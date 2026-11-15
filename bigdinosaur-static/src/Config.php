<?php
/**
 * Configuration (wp-config.php constants only) and the site guard.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Config {

	/** Request header that switches on build-context rendering. */
	const HEADER = 'X-Bigdinosaur-Static-Render';

	/** Response header the fetcher requires: "active", or "refused: <reason>". */
	const RESPONSE_HEADER = 'X-Bigdinosaur-Static';

	/** Hosts whose links are always made root-relative. */
	const PUBLIC_HOSTS = array( 'bigdinosaur.org', 'www.bigdinosaur.org' );

	/** The only theme this plugin renders for. */
	const THEME = 'bigdinosaur';

	/** Source origin (must equal the stored home option). */
	public static function source(): string {
		return defined( 'BIGDINOSAUR_STATIC_SOURCE' ) ? untrailingslashit( (string) BIGDINOSAUR_STATIC_SOURCE ) : '';
	}

	/** Destination (public identity) origin. */
	public static function origin(): string {
		return defined( 'BIGDINOSAUR_STATIC_ORIGIN' ) ? untrailingslashit( (string) BIGDINOSAUR_STATIC_ORIGIN ) : '';
	}

	/** The symlink nginx serves; slots a and b and state/ are in <ROOT>-builds. */
	public static function root(): string {
		return defined( 'BIGDINOSAUR_STATIC_ROOT' ) ? untrailingslashit( (string) BIGDINOSAUR_STATIC_ROOT ) : '';
	}

	/** Cloudflare zone id for the after-publish purge; '' when purging is off (the LAN). */
	public static function cf_zone(): string {
		return defined( 'BIGDINOSAUR_STATIC_CF_ZONE' ) ? trim( (string) BIGDINOSAUR_STATIC_CF_ZONE ) : '';
	}

	/** Cloudflare API token with Zone > Cache Purge on that zone. Never printed or logged. */
	public static function cf_token(): string {
		return defined( 'BIGDINOSAUR_STATIC_CF_TOKEN' ) ? trim( (string) BIGDINOSAUR_STATIC_CF_TOKEN ) : '';
	}

	/**
	 * Why the plugin must not run here, or '' when it may. Compares the stored
	 * home option (not home_url(), which build context filters) and the active
	 * theme. Callers fail before any write when this is non-empty.
	 */
	public static function guard(): string {
		foreach ( array( 'BIGDINOSAUR_STATIC_SOURCE', 'BIGDINOSAUR_STATIC_ORIGIN', 'BIGDINOSAUR_STATIC_ROOT' ) as $c ) {
			if ( ! defined( $c ) || '' === (string) constant( $c ) ) {
				return "$c is not defined in wp-config.php";
			}
		}
		foreach ( array( self::source(), self::origin() ) as $o ) {
			if ( ! preg_match( '#^https://[a-z0-9.-]+(:\d+)?$#i', $o ) ) {
				return "origin '$o' must be https://host with no path";
			}
		}
		if ( self::source() === self::origin() ) {
			return 'source and destination origins are the same';
		}
		$home = untrailingslashit( (string) get_option( 'home' ) );
		if ( $home !== self::source() ) {
			return "home option '$home' does not equal BIGDINOSAUR_STATIC_SOURCE";
		}
		if ( self::THEME !== get_stylesheet() ) {
			return 'active theme is ' . get_stylesheet() . ', not ' . self::THEME;
		}
		if ( version_compare( (string) wp_get_theme()->get( 'Version' ), '0.7.17', '<' ) ) {
			return 'bigdinosaur-static 0.6.0 requires bigdinosaur theme 0.7.17 or later';
		}
		if ( ! str_starts_with( self::root(), '/' ) || str_contains( self::root(), '..' ) ) {
			return 'BIGDINOSAUR_STATIC_ROOT must be an absolute path';
		}
		return '';
	}
}
