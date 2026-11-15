<?php
/**
 * The two-slot (blue/green) layout:
 *
 *   <ROOT>                 symlink nginx serves, -> <ROOT>-builds/a or /b
 *   <ROOT>-builds/a/, b/   the slots
 *   <ROOT>-builds/state/   lock, reports, log
 *
 * The live slot is always read from the link, never from stored state, so a
 * manual switch (README, "Going back") is respected. Slot names are the
 * literals a and b, never paths from input.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Slots {

	const NAMES = array( 'a', 'b' );

	public static function builds(): string {
		return Config::root() . '-builds';
	}

	public static function state(): string {
		return self::builds() . '/state';
	}

	public static function dir( string $slot ): string {
		if ( ! in_array( $slot, self::NAMES, true ) ) {
			throw new \InvalidArgumentException( "not a slot name: $slot" );
		}
		return self::builds() . '/' . $slot;
	}

	public static function other( string $slot ): string {
		return 'a' === $slot ? 'b' : 'a';
	}

	/** The link target written on publish: relative, as Lee's manual switch writes it. */
	private static function target( string $slot ): string {
		return basename( Config::root() ) . '-builds/' . $slot;
	}

	/**
	 * The slot the link points at. Throws when the link is missing or points
	 * anywhere but slot a or b (relative or absolute form).
	 */
	public static function live(): string {
		$root = Config::root();
		clearstatcache( true, $root );
		if ( ! is_link( $root ) ) {
			throw new \RuntimeException( "$root is not a symlink" );
		}
		$t = rtrim( (string) readlink( $root ), '/' );
		foreach ( self::NAMES as $slot ) {
			if ( $t === self::target( $slot ) || $t === self::dir( $slot ) ) {
				return $slot;
			}
		}
		throw new \RuntimeException( "$root points at '$t', not at slot a or b" );
	}

	/** Problems with the layout, or an empty list. */
	public static function problems(): array {
		$out = array();
		try {
			self::live();
		} catch ( \RuntimeException $e ) {
			$out[] = $e->getMessage();
		}
		foreach ( array( self::dir( 'a' ), self::dir( 'b' ), self::state() ) as $d ) {
			if ( is_link( $d ) || ! is_dir( $d ) ) {
				$out[] = "$d is not a directory";
			} elseif ( ! is_writable( $d ) ) {
				$out[] = "$d is not writable by " . ( function_exists( 'posix_geteuid' ) ? ( posix_getpwuid( posix_geteuid() )['name'] ?? '?' ) : 'this user' );
			}
		}
		if ( file_exists( Config::root() . '.tmp' ) || is_link( Config::root() . '.tmp' ) ) {
			$out[] = Config::root() . '.tmp exists (an interrupted publish or manual switch); remove it';
		}
		return $out;
	}

	/**
	 * Point the link at $slot atomically: a temporary link renamed over the
	 * real one. nginx resolves the link per request, so no reload.
	 */
	public static function switch_to( string $slot ): void {
		$root = Config::root();
		$tmp  = $root . '.tmp';
		self::dir( $slot ); // Validates the name.
		if ( is_link( $tmp ) ) {
			unlink( $tmp );
		}
		if ( file_exists( $tmp ) ) {
			throw new \RuntimeException( "$tmp exists and is not a symlink" );
		}
		if ( ! symlink( self::target( $slot ), $tmp ) ) {
			throw new \RuntimeException( "cannot create $tmp" );
		}
		if ( ! rename( $tmp, $root ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new \RuntimeException( "cannot rename $tmp over $root" );
		}
		if ( self::live() !== $slot ) {
			throw new \RuntimeException( "$root does not point at slot $slot after the switch" );
		}
	}

	/**
	 * Delete everything inside $dir without following symlinks (a link is
	 * unlinked, never descended into). $dir itself stays.
	 */
	public static function empty_dir( string $dir ): void {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			throw new \RuntimeException( "$dir is not a directory" );
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$path = $f->getPathname();
			$ok   = ( $f->isDir() && ! $f->isLink() ) ? rmdir( $path ) : unlink( $path );
			if ( ! $ok ) {
				throw new \RuntimeException( "cannot remove $path" );
			}
		}
	}

	/**
	 * A static path ("/tags/code/index.html") as a path inside $dir, or an
	 * exception: percent-decoded as nginx does, no empty, "." or ".."
	 * segments, no NUL or backslash.
	 */
	public static function join( string $dir, string $path ): string {
		$path = rawurldecode( $path );
		if ( ! str_starts_with( $path, '/' ) || preg_match( '#[\x00\\\\]#', $path ) ) {
			throw new \InvalidArgumentException( 'unsafe static path: ' . wp_json_encode( $path ) );
		}
		foreach ( explode( '/', substr( $path, 1 ) ) as $seg ) {
			if ( '' === $seg || '.' === $seg || '..' === $seg ) {
				throw new \InvalidArgumentException( 'unsafe static path: ' . wp_json_encode( $path ) );
			}
		}
		return $dir . $path;
	}
}

/**
 * Writes one build into a slot directory. Every file goes through
 * Slots::join(); parent directories are created without following an
 * existing symlink; writing the same file twice is an error (two routes or
 * assets colliding).
 */
final class SlotWriter {

	/** @var array<string,true> static paths written */
	public array $written = array();

	public function __construct( public readonly string $dir ) {}

	public function put( string $path, string $bytes ): void {
		$file = $this->prepare( $path );
		if ( false === file_put_contents( $file, $bytes ) ) {
			throw new \RuntimeException( "cannot write $file" );
		}
		chmod( $file, 0644 );
	}

	/** Copy (or hardlink, when $link and the filesystem allows) a source file in. */
	public function copy( string $path, string $source, bool $link = false ): void {
		$file = $this->prepare( $path );
		if ( $link && @link( $source, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- EXDEV falls back to copy.
			return;
		}
		if ( ! copy( $source, $file ) ) {
			throw new \RuntimeException( "cannot copy $source to $file" );
		}
		chmod( $file, 0644 );
	}

	private function prepare( string $path ): string {
		$file = Slots::join( $this->dir, $path );
		$key  = rawurldecode( $path );
		if ( isset( $this->written[ $key ] ) ) {
			throw new \RuntimeException( "two outputs write $key" );
		}
		$this->written[ $key ] = true;
		$parent                = $this->dir;
		foreach ( array_slice( explode( '/', substr( $key, 1 ) ), 0, -1 ) as $seg ) {
			$parent .= '/' . $seg;
			if ( is_link( $parent ) ) {
				throw new \RuntimeException( "$parent is a symlink" );
			}
			if ( ! is_dir( $parent ) && ! mkdir( $parent, 0755 ) ) {
				throw new \RuntimeException( "cannot create $parent" );
			}
		}
		if ( file_exists( $file ) || is_link( $file ) ) {
			throw new \RuntimeException( "$file already exists" );
		}
		return $file;
	}
}
