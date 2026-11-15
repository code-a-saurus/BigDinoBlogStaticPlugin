<?php
/** Isolated process for build-lifecycle.php; real publisher, scratch files, no WordPress or HTTP. */
namespace BigDinosaur\Static {
	final class Manifest {
		public static function routes(): array {
			if ( 'interrupt-empty' === $GLOBALS['audit_mode'] ) {
				exit( 88 );
			}
			return array(
				'/' => array( 'source' => '/', 'kind' => 'home', 'code' => false ),
				'/next/' => array( 'source' => '/next/', 'kind' => 'page', 'code' => false ),
			);
		}
	}
	final class Urls {
		const URL_ATTRIBUTES = array();
	}
}

namespace {
	[ , $audit_mode, $audit_root, $audit_src ] = $argv;
	define( 'ABSPATH', '/unused/' );
	define( 'BIGDINOSAUR_STATIC_SOURCE', 'https://source.example' );
	define( 'BIGDINOSAUR_STATIC_ORIGIN', 'https://public.example' );
	define( 'BIGDINOSAUR_STATIC_ROOT', $audit_root . '/static' );
	define( 'BIGDINOSAUR_STATIC_VERSION', 'test' );
	function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
	function get_option( $name ) {
		return match ( $name ) {
			'home' => BIGDINOSAUR_STATIC_SOURCE,
			'bigdinosaur_static_changed' => array( 'at' => 500, 'what' => 'fixture edit' ),
			'bigdinosaur_static_worker_error' => array( 'at' => 501, 'message' => 'fixture error' ),
			default => false,
		};
	}
	function get_stylesheet() { return 'bigdinosaur'; }
	function wp_date( $format ) { return date( $format ); }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function wp_get_theme() { return new class { public function get( $key ) { return '0.7.17'; } }; }
	function wp_next_scheduled( $hook ) { return 600; }
	class WP_HTML_Tag_Processor {
		public function __construct( $html ) {}
		public function next_tag() { return false; }
	}
	function wp_remote_get( $url, $args ) {
		if ( str_ends_with( $url, '/next/' ) ) {
			exit( 88 ); // Abrupt exit bypasses the engine's catch/finally; the OS releases flock.
		}
		return array();
	}
	function is_wp_error( $r ) { return false; }
	function wp_remote_retrieve_headers( $r ) {
		return new class { public function getAll() { return array( 'X-Bigdinosaur-Static' => 'active' ); } };
	}
	function wp_remote_retrieve_response_code( $r ) { return 200; }
	function wp_remote_retrieve_body( $r ) { return '<!doctype html><p>Partial, unvalidated replacement</p>'; }
	class WP_CLI {
		public static function line( $s ) { echo $s . "\n"; }
	}
	foreach ( array( 'Config', 'Slots', 'Purge', 'Changes', 'Build', 'Cli' ) as $class ) {
		require $audit_src . '/' . $class . '.php';
	}
	try {
		if ( str_starts_with( $audit_mode, 'interrupt-' ) ) {
			\BigDinosaur\Static\Build::run( false );
		} elseif ( 'publish' === $audit_mode ) {
			echo json_encode( \BigDinosaur\Static\Build::publish() );
		} elseif ( 'status-json' === $audit_mode ) {
			( new \BigDinosaur\Static\Cli() )->status( array(), array( 'format' => 'json' ) );
		}
	} catch ( \RuntimeException $e ) {
		fwrite( STDERR, $e->getMessage() . "\n" );
		exit( 1 );
	}
}
