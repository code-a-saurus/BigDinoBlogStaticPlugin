<?php
/**
 * Cloudflare cache purge after a publish. Build::go_live() calls it when
 * BIGDINOSAUR_STATIC_CF_ZONE and BIGDINOSAUR_STATIC_CF_TOKEN are both defined
 * (prod; unset on the LAN). Purges the whole zone; the site is small and the
 * plugin publishes whole builds. A failure throws, which go_live() records as
 * a warning in the report: the new build is live either way, the edge is just
 * stale until its TTL runs out. The token never appears in output, reports or
 * logs.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Purge {

	const API     = 'https://api.cloudflare.com/client/v4/';
	const TIMEOUT = 15;

	public static function configured(): bool {
		return '' !== Config::cf_zone() && '' !== Config::cf_token();
	}

	/** Purge everything in the zone. Throws on failure. */
	public static function everything(): void {
		self::request( 'POST', 'zones/' . Config::cf_zone() . '/purge_cache', array( 'purge_everything' => true ) );
	}

	/**
	 * doctor: verify the token itself (works for a purge-only token, which
	 * cannot read the zone). '' when fine, else the problem.
	 */
	public static function check(): string {
		try {
			$r = self::request( 'GET', 'user/tokens/verify' );
			$status = (string) ( $r['result']['status'] ?? '' );
			return 'active' === $status ? '' : "Cloudflare token status is '$status'";
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
	}

	/** One API call. Returns the decoded body on success, throws otherwise. */
	private static function request( string $method, string $path, ?array $body = null ): array {
		$args = array(
			'method'     => $method,
			'timeout'    => self::TIMEOUT,
			'user-agent' => 'bigdinosaur-static/' . BIGDINOSAUR_STATIC_VERSION,
			'headers'    => array(
				'Authorization' => 'Bearer ' . Config::cf_token(),
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'Cloudflare purge: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $data ) || true !== ( $data['success'] ?? false ) ) {
			$errors = array_map(
				fn( $e ) => trim( ( $e['code'] ?? '' ) . ' ' . ( $e['message'] ?? '' ) ),
				is_array( $data['errors'] ?? null ) ? $data['errors'] : array()
			);
			throw new \RuntimeException( "Cloudflare purge: HTTP $code" . ( $errors ? ' (' . implode( '; ', $errors ) . ')' : '' ) );
		}
		return $data;
	}
}
