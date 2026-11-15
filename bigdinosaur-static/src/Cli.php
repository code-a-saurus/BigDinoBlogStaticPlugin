<?php
/**
 * WP-CLI commands: doctor, build, publish,
 * status. The CLI is the operator's path; routine publishing is the
 * Generate button (Admin, Worker). Run as www-data with --path to the WordPress root.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Check that a build can run here. Writes nothing.
	 *
	 * Site guard and constants, slot layout and link, lock, a TLS fetch with
	 * the build-context header, cron settings, set_time_limit, the loopback
	 * Generate relies on, the last cron build's FPM environment, disk space.
	 */
	public function doctor( array $args, array $assoc ): void {
		$bad  = 0;
		$line = function ( bool $ok, string $what, string $detail = '' ) use ( &$bad ) {
			$bad += $ok ? 0 : 1;
			\WP_CLI::line( sprintf( '%-5s %-22s %s', $ok ? 'ok' : 'FAIL', $what, $detail ) );
		};
		$info = fn( string $what, string $detail ) => \WP_CLI::line( sprintf( '%-5s %-22s %s', 'info', $what, $detail ) );

		$refused = Config::guard();
		$line( '' === $refused, 'site guard', '' === $refused ? 'home = ' . Config::source() . ', theme ' . Config::THEME : $refused );
		$info( 'destination origin', Config::origin() );
		$info( 'root', Config::root() . ' (slots in ' . Slots::builds() . ')' );
		if ( '' !== $refused ) {
			\WP_CLI::halt( 1 );
		}
		$problems = Slots::problems();
		$line( ! $problems, 'slot layout', $problems ? implode( '; ', $problems ) : 'a, b and state are writable directories' );
		try {
			$live = Slots::live();
			$line( true, 'link', Config::root() . ' -> ' . readlink( Config::root() ) . " (live: $live, next build: " . Slots::other( $live ) . ')' );
		} catch ( \RuntimeException $e ) {
			$line( false, 'link', $e->getMessage() );
		}
		$held = Build::holder();
		$line( null === $held, 'lock', null === $held ? 'free' : 'held: ' . wp_json_encode( $held ) );

		$res = Fetcher::get( '/' );
		$line( 200 === $res['status'] && '' === $res['error'], 'build fetch', Config::source() . '/ -> ' . $res['status'] . ( '' !== $res['error'] ? ' ' . $res['error'] : ', ' . Config::RESPONSE_HEADER . ': active' ) );

		if ( Purge::configured() ) {
			$err = Purge::check();
			$line( '' === $err, 'Cloudflare purge', '' === $err ? 'token verified; zone ' . Config::cf_zone() . ' is purged after each publish' : $err );
		} else {
			$info( 'Cloudflare purge', 'not configured (BIGDINOSAUR_STATIC_CF_ZONE / BIGDINOSAUR_STATIC_CF_TOKEN unset); nothing is purged after publish' );
		}

		// Generate calls spawn_cron() itself, which core honors regardless of
		// DISABLE_WP_CRON (that constant only turns off visitor-triggered cron),
		// so a true value is fine as long as wp-cron.php is reachable from this host.
		$info( 'DISABLE_WP_CRON', ( defined( 'DISABLE_WP_CRON' ) ? var_export( DISABLE_WP_CRON, true ) : 'not defined' ) . ' (either is fine; Generate spawns cron itself)' );
		$line( ! ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ), 'ALTERNATE_WP_CRON', defined( 'ALTERNATE_WP_CRON' ) ? var_export( ALTERNATE_WP_CRON, true ) : 'not defined' );
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		$line( ! in_array( 'set_time_limit', $disabled, true ), 'set_time_limit', in_array( 'set_time_limit', $disabled, true ) ? 'disabled in php.ini (CLI)' : 'available (CLI)' );

		// The Generate path: core's own loopback check, then what the last cron build saw under FPM.
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		$loop = \WP_Site_Health::get_instance()->can_perform_loopback();
		$line( 'good' === $loop->status, 'loopback', wp_strip_all_tags( $loop->message ) );
		$queued = Worker::queued();
		$info( 'cron event', $queued ? Worker::HOOK . ' queued for ' . wp_date( 'c', $queued ) : 'none queued' );
		$cron = null;
		foreach ( array_merge( array( Build::read_state( 'report.json' ) ), array_map( fn( $s ) => Build::read_state( "slot-$s.json" ), Slots::NAMES ) ) as $r ) {
			if ( 'cron' === ( $r['runner']['via'] ?? '' ) && ( ! $cron || $r['started_ts'] > $cron['started_ts'] ) ) {
				$cron = $r;
			}
		}
		if ( $cron ) {
			$rn = $cron['runner'];
			$line(
				'fpm-fcgi' === $rn['sapi'] && $rn['fastcgi_finish_request'] && $rn['set_time_limit'],
				'last cron build',
				sprintf( '%s %s under %s, fastcgi_finish_request %s, set_time_limit(0) %s', $cron['id'], $cron['result'], $rn['sapi'], $rn['fastcgi_finish_request'] ? 'yes' : 'NO', $rn['set_time_limit'] ? 'ok' : 'FAILED' )
			);
		} else {
			$info( 'last cron build', 'none yet (press Generate once to check the FPM side)' );
		}
		$err = get_option( Worker::ERROR_OPTION );
		if ( $err ) {
			$line( false, 'cron worker', 'last run could not start: ' . $err['message'] );
		}

		$uploads = wp_get_upload_dir()['basedir'];
		$need    = 2 * self::du( $uploads ) + 64 * 1024 * 1024;
		$free    = (float) disk_free_space( Slots::builds() );
		$line( $free > $need, 'disk', sprintf( '%s free, want > %s (2 x uploads + 64 MB)', size_format( $free, 1 ), size_format( $need, 1 ) ) );
		$info( 'media copies', @stat( $uploads )['dev'] === @stat( Slots::builds() )['dev'] ? 'hardlinks (uploads on the same filesystem)' : 'copies (uploads on another filesystem)' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$info( 'versions', 'plugin ' . BIGDINOSAUR_STATIC_VERSION . ', theme ' . wp_get_theme()->get( 'Version' ) . ', WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION );
		\WP_CLI::line( $bad ? "$bad problem(s)" : 'all checks passed' );
		if ( $bad ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Build the site into the inactive slot, validate it, and switch the link.
	 *
	 * ## OPTIONS
	 *
	 * [--[no-]publish]
	 * : Switch the link once the build validates (default). --no-publish
	 * builds and validates only; `publish` switches to it later.
	 *
	 * [--notes]
	 * : Print the coverage notes (external media, links with no route).
	 */
	public function build( array $args, array $assoc ): void {
		$publish = \WP_CLI\Utils\get_flag_value( $assoc, 'publish', true );
		try {
			$r = Build::run( (bool) $publish, fn( $m ) => \WP_CLI::line( $m ) );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		foreach ( $r['failures'] as $f ) {
			\WP_CLI::line( "FAIL  $f" );
		}
		foreach ( $r['warnings'] as $f ) {
			\WP_CLI::warning( $f );
		}
		if ( isset( $assoc['notes'] ) ) {
			foreach ( $r['notes'] as $n ) {
				\WP_CLI::line( "note  $n" );
			}
		}
		$summary = sprintf(
			'%s slot %s: %s (%d pages, %s; %d assets; %d media; %d failures, %d notes; %.1fs)',
			$r['id'],
			$r['slot'],
			$r['result'],
			count( $r['pages'] ),
			size_format( array_sum( $r['pages'] ), 1 ),
			count( $r['assets'] ),
			count( $r['media'] ),
			count( $r['failures'] ),
			count( $r['notes'] ),
			$r['duration']
		);
		if ( 'failed' === $r['result'] ) {
			\WP_CLI::error( $summary . '; the link was not changed' );
		}
		\WP_CLI::success( $summary . ( 'published' === $r['result'] ? "; slot {$r['slot']} is live" : '; not published' ) );
	}

	/**
	 * Switch the link to the inactive slot, if it holds a validated build that
	 * was never published (from build --no-publish).
	 */
	public function publish( array $args, array $assoc ): void {
		try {
			$r = Build::publish();
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		foreach ( $r['warnings'] ?? array() as $f ) {
			\WP_CLI::warning( $f );
		}
		\WP_CLI::success( "slot {$r['slot']} ({$r['id']}) is live" );
	}

	/**
	 * Live slot, each slot's build, the last build, whether one is running or
	 * queued, and whether WordPress has changes the live build lacks.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function status( array $args, array $assoc ): void {
		$s = Build::status();
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$brief = function ( ?array $r ): string {
			if ( ! $r ) {
				return '(no build)';
			}
			$s = "{$r['id']} {$r['result']}";
			if ( 'running' === $r['result'] ) {
				return "$s, {$r['phase']} {$r['progress']}";
			}
			$s .= sprintf( ', %d pages, %d media, %d failures, built %s in %.1fs', count( $r['pages'] ), count( $r['media'] ), count( $r['failures'] ), $r['finished'], $r['duration'] );
			return $r['published_at'] ? "$s, went live {$r['published_at']}" : $s;
		};
		\WP_CLI::line( 'root     ' . $s['root'] . ' -> ' . ( $s['live'] ? "slot {$s['live']}" : implode( '; ', $s['problems'] ) ) );
		\WP_CLI::line( 'running  ' . ( $s['running'] ? wp_json_encode( $s['running'] ) : 'no' ) );
		$q = $s['queued'];
		\WP_CLI::line( 'queued   ' . ( $q ? 'for ' . wp_date( 'c', $q ) : 'no' ) );
		$ch   = $s['change'];
		$pend = $s['pending'];
		\WP_CLI::line( 'changed  ' . ( null === $pend ? 'unknown' : ( $pend ? 'yes' : 'no' ) ) . ( $ch ? ' (latest ' . wp_date( 'c', (int) $ch['at'] ) . ": {$ch['what']})" : '' ) );
		if ( $s['error'] ) {
			\WP_CLI::warning( 'Could not start: ' . $s['error']['message'] );
		}
		foreach ( $s['slots'] as $name => $r ) {
			\WP_CLI::line( "slot $name   " . ( $name === $s['live'] ? '[live] ' : '' ) . $brief( $r ) );
		}
		\WP_CLI::line( 'last     ' . $brief( $s['last'] ) );
		foreach ( array_slice( $s['last']['failures'] ?? array(), 0, 10 ) as $f ) {
			\WP_CLI::line( "         FAIL $f" );
		}
	}

	private static function du( string $dir ): int {
		$n = 0;
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			$n += $f->isFile() ? $f->getSize() : 0;
		}
		return $n;
	}
}
