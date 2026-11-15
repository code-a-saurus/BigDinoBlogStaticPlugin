<?php
/**
 * URL mapping between the source WordPress and the static site, and the
 * whitespace tidy that follows attribute removal. Only tag attributes are
 * ever rewritten; text, <code> and <pre> contents are untouched because
 * nothing here reads them.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Urls {

	/** Attributes holding navigation or asset URLs (root-relative in output). */
	const URL_ATTRIBUTES = array( 'href', 'src', 'srcset', 'poster', 'action' );

	/**
	 * Rewrite every URL attribute in an HTML document or fragment.
	 *
	 * Identity metadata (<meta content> and <link rel=canonical|alternate>)
	 * stays absolute on the destination origin; everything else becomes
	 * root-relative. With $absolute true (feeds) every URL stays absolute.
	 * Empty class attributes are removed.
	 */
	public static function rewrite_html( string $html, bool $absolute = false ): string {
		$p = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			$tag = $p->get_tag();
			if ( 'META' === $tag ) {
				$content = $p->get_attribute( 'content' );
				if ( is_string( $content ) && self::is_own( $content ) ) {
					$p->set_attribute( 'content', self::map( $content, true, false ) );
				}
				continue;
			}
			$identity = 'LINK' === $tag && preg_match( '/\b(canonical|alternate)\b/i', (string) $p->get_attribute( 'rel' ) );
			foreach ( self::URL_ATTRIBUTES as $attr ) {
				$value = $p->get_attribute( $attr );
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$link = ( 'A' === $tag || 'AREA' === $tag ) && 'href' === $attr;
				if ( 'srcset' === $attr ) {
					$parts = array();
					foreach ( explode( ',', $value ) as $candidate ) {
						$bits    = preg_split( '/\s+/', trim( $candidate ), 2 );
						$parts[] = trim( self::map( $bits[0], $absolute || $identity, false ) . ' ' . ( $bits[1] ?? '' ) );
					}
					$new = implode( ', ', $parts );
				} else {
					$new = self::map( $value, $absolute || $identity, $link );
				}
				if ( $new !== $value ) {
					$p->set_attribute( $attr, $new );
				}
			}
			if ( '' === trim( (string) $p->get_attribute( 'class' ) ) && null !== $p->get_attribute( 'class' ) ) {
				$p->remove_attribute( 'class' );
			}
		}
		return $p->get_updated_html();
	}

	/** True for an absolute URL on the source or destination origin. */
	public static function is_own( string $url ): bool {
		foreach ( array( Config::source(), Config::origin() ) as $o ) {
			if ( $url === $o || str_starts_with( $url, $o . '/' ) || str_starts_with( $url, $o . '?' ) || str_starts_with( $url, $o . '#' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map one URL. Own-origin and root-relative URLs get their path mapped and
	 * become root-relative (or absolute on the destination origin when
	 * $absolute). Public-site (bigdinosaur.org) URLs: links always become
	 * root-relative; media only when the file is in the local uploads tree,
	 * else they stay external (the validator notes them). Anything else is
	 * returned as is.
	 */
	public static function map( string $url, bool $absolute, bool $link ): string {
		$public = self::is_public( $url );
		if ( self::is_own( $url ) || $public ) {
			$rest = preg_replace( '#^https?://[^/?\#]+#i', '', $url );
		} elseif ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			$rest = $url;
		} else {
			return $url;
		}
		// "https://host", "https://host?x" and "https://host#x" have no path: give them one.
		if ( '' === $rest || '?' === $rest[0] || '#' === $rest[0] ) {
			$rest = '/' . $rest;
		}
		$mapped = self::map_path( $rest );
		if ( $public ) {
			$is_media = ( null !== $mapped && str_starts_with( $mapped, '/media/' ) ) || str_contains( $rest, 'attachment_id=' );
			if ( $is_media && ( null === $mapped || ! self::local_media_exists( $mapped ) ) ) {
				return $url;
			}
			if ( ! $link && ! $is_media && ! $absolute ) {
				return $url; // A non-link, non-media public URL (an iframe, say): leave it.
			}
		}
		if ( null === $mapped ) {
			return $url; // Unresolvable (e.g. attachment_id of a missing attachment); the validator reports it.
		}
		return $absolute ? Config::origin() . $mapped : $mapped;
	}

	/** True for an absolute URL on the public production host. */
	public static function is_public( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $host && in_array( $host, Config::PUBLIC_HOSTS, true ) && preg_match( '#^https?://#i', $url );
	}

	/**
	 * Map a root-relative path (with optional query and fragment) to its
	 * static equivalent, or null when it cannot be resolved.
	 */
	public static function map_path( string $rest ): ?string {
		$fragment = '';
		$hash     = strpos( $rest, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $rest, $hash );
			$rest     = substr( $rest, 0, $hash );
		}
		$query = '';
		$q     = strpos( $rest, '?' );
		if ( false !== $q ) {
			$query = substr( $rest, $q + 1 );
			$rest  = substr( $rest, 0, $q );
		}
		$path = '' === $rest ? '/' : $rest;
		parse_str( $query, $args );

		// Query permalinks are not an interface: resolve them.
		if ( '/' === $path && isset( $args['attachment_id'] ) ) {
			$file = wp_get_attachment_url( (int) $args['attachment_id'] );
			return $file ? self::map_path( (string) wp_make_link_relative( $file ) ) : null;
		}
		if ( '/' === $path && isset( $args['p'] ) ) {
			$permalink = get_permalink( (int) $args['p'] );
			return $permalink ? self::map_path( (string) wp_make_link_relative( $permalink ) ) . $fragment : null;
		}

		if ( str_starts_with( $path, '/wp-content/uploads/' ) ) {
			return '/media/' . substr( $path, strlen( '/wp-content/uploads/' ) ) . $fragment;
		}
		$theme_prefix = (string) wp_make_link_relative( get_stylesheet_directory_uri() ) . '/';
		if ( str_starts_with( $path, $theme_prefix ) ) {
			$static = Assets::static_path_for_theme_file( substr( $path, strlen( $theme_prefix ) ) );
			return null === $static ? $path . ( '' !== $query ? '?' . $query : '' ) . $fragment : $static . $fragment;
		}
		if ( preg_match( '#^/category/(.*)$#', $path, $m ) ) {
			$path = '/categories/' . $m[1];
		} elseif ( preg_match( '#^/tag/(.*)$#', $path, $m ) ) {
			$path = '/tags/' . $m[1];
		} elseif ( preg_match( '#^/feed/?$#', $path ) ) {
			$path = '/feed.xml';
		}
		return $path . ( '' !== $query ? '?' . $query : '' ) . $fragment;
	}

	/** Whether a /media/... path exists in the local uploads directory. */
	public static function local_media_exists( string $media_path ): bool {
		$relative = rawurldecode( (string) preg_replace( '#[?\#].*$#', '', substr( $media_path, strlen( '/media/' ) ) ) );
		if ( str_contains( $relative, '..' ) ) {
			return false;
		}
		return is_file( wp_get_upload_dir()['basedir'] . '/' . $relative );
	}
}

/**
 * Whitespace left inside tags by attribute removal ("<p >", "<h3  id=…>",
 * "<p class=\"notice\" >", "alt=\"\"  style=…"). Each tag token is located by
 * the HTML API, so only the bytes of the tag itself are rewritten; text,
 * <code>, <pre> and script contents cannot be reached.
 */
final class Tidy extends \WP_HTML_Tag_Processor {

	public static function html( string $html ): string {
		$p     = new self( $html );
		$spans = array();
		while ( $p->next_tag() ) {
			if ( $p->set_bookmark( 'tag' ) ) {
				$spans[] = array( $p->bookmarks['tag']->start, $p->bookmarks['tag']->length );
				$p->release_bookmark( 'tag' );
			}
		}
		// Rewrite from the end so earlier offsets stay valid as tag lengths change.
		foreach ( array_reverse( $spans ) as [ $start, $length ] ) {
			$tag  = substr( $html, $start, $length );
			// One space between attributes; quoted values are matched first and kept as is.
			$tidy = (string) preg_replace_callback( '/("[^"]*"|\'[^\']*\')|\s{2,}/', fn( $m ) => isset( $m[1] ) && '' !== $m[1] ? $m[1] : ' ', $tag );
			$tidy = (string) preg_replace( '/^(<[a-zA-Z][^\s\/>]*)\s+/', '$1 ', $tidy ); // One space after the name.
			$tidy = (string) preg_replace( '/^(<[a-zA-Z][^\s\/>]*) >$/', '$1>', $tidy ); // "<p >" -> "<p>".
			$tidy = (string) preg_replace( '/(?<=["\'])\s+>$/', '>', $tidy );            // 'x" >' -> 'x">'.
			if ( $tidy !== $tag ) {
				$html = substr_replace( $html, $tidy, $start, $length );
			}
		}
		return $html;
	}
}
