<?php
/**
 * Theme and plugin assets: the static names they get, their compiled bytes and
 * content hashes. The theme's stylesheet is ported to the site vocabulary at
 * build time (the theme itself must keep its WordPress class names, because it
 * still serves the editor site).
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/** Generated files with a ?v= content hash: static path => theme-relative source. */
	const HASHED = array(
		'/css/site.css'     => 'style.css',
		'/css/prism.css'    => 'assets/css/prism.css',
		'/js/post-list.js'  => 'assets/js/post-list.js',
		'/js/prism.js'      => 'assets/js/prism.js',
	);

	/** Copied as-is, no query (preload URLs must equal the CSS url()s). */
	const PLAIN = array(
		'/img/mascot.png' => 'assets/images/bigdino-left-cleanbg-square.png',
		'/img/card.png'   => 'assets/images/card.png',
	);

	/** Plugin-owned files: static path => plugin-relative source. */
	const OWN = array(
		'/favicon.ico'          => 'assets/icons/favicon.ico',
		'/icon-32.png'          => 'assets/icons/icon-32.png',
		'/icon-192.png'         => 'assets/icons/icon-192.png',
		'/apple-touch-icon.png' => 'assets/icons/apple-touch-icon.png',
		'/404.html'             => 'assets/404.html',
	);

	/** @var array<string,string> compiled bytes per static path, per request */
	private static array $cache = array();

	/**
	 * Static path (with ?v= for hashed files) for a theme-relative path, or
	 * null when the theme file is not one the static site ships.
	 */
	public static function static_path_for_theme_file( string $relative ): ?string {
		$relative = ltrim( $relative, '/' );
		$hashed   = array_search( $relative, self::HASHED, true );
		if ( false !== $hashed ) {
			return $hashed . '?v=' . self::hash( $hashed );
		}
		$plain = array_search( $relative, self::PLAIN, true );
		if ( false !== $plain ) {
			return $plain;
		}
		if ( preg_match( '#^assets/fonts/([a-z0-9._-]+\.woff2)$#i', $relative, $m ) ) {
			return '/fonts/' . $m[1];
		}
		return null;
	}

	/** Short content hash of a generated file. */
	public static function hash( string $static_path ): string {
		return substr( hash( 'sha256', self::bytes( $static_path ) ), 0, 12 );
	}

	/**
	 * Every file the static site ships besides pages and media: static path =>
	 * array( 'bytes' => string ) for generated files or array( 'file' => path ).
	 */
	public static function all(): array {
		$out   = array();
		$theme = get_stylesheet_directory();
		foreach ( array_keys( self::HASHED ) as $path ) {
			$out[ $path ] = array( 'bytes' => self::bytes( $path ) );
		}
		foreach ( self::PLAIN as $path => $rel ) {
			$out[ $path ] = array( 'file' => $theme . '/' . $rel );
		}
		foreach ( glob( $theme . '/assets/fonts/*.woff2' ) as $font ) {
			$out[ '/fonts/' . basename( $font ) ] = array( 'file' => $font );
		}
		foreach ( self::OWN as $path => $rel ) {
			$out[ $path ] = array( 'file' => BIGDINOSAUR_STATIC_DIR . '/' . $rel );
		}
		return $out;
	}

	/** Compiled bytes of a generated (hashed) file. */
	public static function bytes( string $static_path ): string {
		if ( isset( self::$cache[ $static_path ] ) ) {
			return self::$cache[ $static_path ];
		}
		$src = self::theme_file( self::HASHED[ $static_path ] ?? throw new \RuntimeException( "not a generated asset: $static_path" ) );
		$out = '/css/site.css' === $static_path ? self::site_css( $src ) : $src;
		// A compiled asset that still says wp-/wp_/WordPress (in a selector, a path
		// or a comment) would leak a WordPress reference onto the public site.
		// prism.js is exempt as an unchanged third-party bundle.
		if ( '/js/prism.js' !== $static_path && preg_match( '/wp-|wp_|wordpress/i', $out, $m, PREG_OFFSET_CAPTURE ) ) {
			throw new \RuntimeException( "$static_path: WordPress reference survived the port near: " . substr( $out, max( 0, $m[0][1] - 60 ), 120 ) );
		}
		return self::$cache[ $static_path ] = $out;
	}

	private static function theme_file( string $relative ): string {
		$path = get_stylesheet_directory() . '/' . $relative;
		$src  = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( false === $src ) {
			throw new \RuntimeException( "cannot read theme file $relative" );
		}
		return $src;
	}

	private static function site_css( string $src ): string {
		$src = preg_replace( '#\A/\*.*?\*/\n#s', '', $src, 1 ); // Theme header comment.
		if ( ! str_contains( $src, 'url(assets/fonts/' ) ) {
			throw new \RuntimeException( 'style.css: no url(assets/fonts/ references; the font paths moved, update Assets::site_css()' );
		}
		$src = str_replace( 'url(assets/fonts/', 'url(/fonts/', $src );
		$selectors = array();
		foreach ( Blocks::CLASS_MAP as $from => $to ) {
			if ( null !== $to ) {
				$selectors[ '.' . $from ] = '.' . $to;
			}
		}
		$src = strtr( $src, $selectors );
		$blocks = file_get_contents( BIGDINOSAUR_STATIC_DIR . '/assets/blocks.css' );
		// Font-size presets: the same rule core emits for has-<slug>-font-size
		// (!important included, so a preset beats the theme's font: shorthand
		// exactly as it does on WordPress), under the name Blocks maps to.
		$presets = '';
		foreach ( Blocks::font_sizes() as $slug => $px ) {
			$presets .= ".text-$slug { font-size: {$px}px !important; }\n";
		}
		return trim( $blocks ) . "\n\n" . trim( $src ) . "\n\n" . $presets;
	}
}
