<?php
/**
 * Tools → Static site: what is live, whether
 * WordPress has changes the static site lacks, the queue, the last build, and
 * one action, Generate. Administrators only. No settings: paths and origins
 * are wp-config.php constants. Plus a one-line notice on the Posts and Pages
 * screens while the static site is behind.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG   = 'bigdinosaur-static';
	const ACTION = 'bigdinosaur_static_generate';
	const CAP    = 'manage_options';

	public static function hook(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'generate' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BIGDINOSAUR_STATIC_DIR . '/bigdinosaur-static.php' ), array( self::class, 'action_link' ) );
	}

	/** A "Static site" link on the plugin's row in Plugins, pointing at the Tools page. */
	public static function action_link( array $links ): array {
		if ( current_user_can( self::CAP ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Static site</a>' );
		}
		return $links;
	}

	public static function menu(): void {
		add_management_page( 'Static site', 'Static site', self::CAP, self::SLUG, array( self::class, 'page' ) );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/** POST handler for the Generate button. */
	public static function generate(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sorry, you are not allowed to do that.', 403 );
		}
		check_admin_referer( self::ACTION );
		$refused = Config::guard();
		if ( '' !== $refused ) {
			wp_safe_redirect( self::url( array( 'bds' => 'refused' ) ) );
			exit;
		}
		try {
			$r = Worker::generate();
		} catch ( \RuntimeException $e ) {
			update_option( Worker::ERROR_OPTION, array( 'at' => time(), 'message' => $e->getMessage() ), false );
			$r = 'error';
		}
		wp_safe_redirect( self::url( array( 'bds' => str_replace( ' ', '-', $r ) ) ) );
		exit;
	}

	public static function notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-post', 'edit-page' ), true ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( '' !== Config::guard() ) {
			return;
		}
		$s = Build::status();
		if ( ! $s['pending'] ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>The static site is behind WordPress: changes since the last publish (latest %s). Nothing goes public until you press Generate on <a href="%s">Tools → Static site</a>.</p></div>',
			esc_html( self::when( (float) $s['change']['at'] ) ),
			esc_url( self::url() )
		);
	}

	private static function when( float|string|null $t ): string {
		if ( null === $t || '' === $t ) {
			return '—';
		}
		$ts = is_string( $t ) ? strtotime( $t ) : (int) $t;
		if ( false === $ts ) {
			return (string) $t;
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $fmt, $ts ) . ' (' . ( abs( time() - $ts ) < 5 ? 'just now' : human_time_diff( $ts ) . ( $ts <= time() ? ' ago' : ' from now' ) ) . ')';
	}

	private static function summary( array $r ): string {
		return sprintf(
			'%d pages (%s), %d assets, %d media, %d failures, %d notes; %.1f s',
			count( $r['pages'] ?? array() ),
			size_format( array_sum( $r['pages'] ?? array() ), 1 ),
			count( $r['assets'] ?? array() ),
			count( $r['media'] ?? array() ),
			count( $r['failures'] ?? array() ),
			count( $r['notes'] ?? array() ),
			(float) ( $r['duration'] ?? 0 )
		);
	}

	public static function page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sorry, you are not allowed to access this page.', 403 );
		}
		$refused = Config::guard();
		$s       = '' === $refused ? Build::status() : null;
		$msg     = sanitize_key( $_GET['bds'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$running = $s && $s['running'];
		$busy    = $s && ( $running || $s['queued'] || in_array( $msg, array( 'queued', 'already-queued' ), true ) );
		$messages = array(
			'queued'         => array( 'success', 'Build queued; it starts within a second or two.' ),
			'already-queued' => array( 'info', 'A build was already queued; no second one was added.' ),
			'running'        => array( 'warning', 'A build is already running; no second one was started. Press Generate again after it finishes if you have changed something since it started.' ),
			'refused'        => array( 'error', 'The plugin refuses to run on this site (see below).' ),
			'error'          => array( 'error', 'The build could not be queued (see below).' ),
		);

		echo '<div class="wrap"><h1>Static site</h1>';
		if ( isset( $messages[ $msg ] ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
		}
		echo '<p>WordPress is the staging site. Publishing, editing, deleting or scheduling here changes only WordPress. The public static site changes only when you press <strong>Generate</strong>, which builds the whole site, checks it and swaps it in.</p>';

		if ( '' !== $refused ) {
			printf( '<div class="notice notice-error inline"><p>Refusing to run: %s</p></div></div>', esc_html( $refused ) );
			return;
		}

		$rows = array();

		// What is live.
		if ( ! $s['live'] ) {
			$rows['Live'] = '<strong>No valid live slot:</strong> ' . esc_html( implode( '; ', $s['problems'] ) );
		} elseif ( ! $s['live_r'] ) {
			$rows['Live'] = esc_html( "Slot {$s['live']}, which holds no build from this plugin." );
		} else {
			$r    = $s['live_r'];
			$live = esc_html( "Slot {$s['live']}: build {$r['id']}" );
			if ( 'published' === ( $r['result'] ?? '' ) && $r['published_at'] ) {
				$live .= ', live since ' . esc_html( self::when( $r['published_at'] ) );
			} else {
				$live .= esc_html( ' (' . ( $r['result'] ?? '?' ) . ', switched to by hand)' );
			}
			$live .= '<br><span class="description">' . esc_html( 'Built from WordPress as of ' . self::when( $r['started'] ?? null ) . '; ' . self::summary( $r ) ) . '</span>';
			$rows['Live'] = $live;
		}

		// Changes WordPress has that the static site lacks.
		if ( true === $s['pending'] ) {
			$rows['Changes since last publish'] = '<strong>Yes.</strong> ' . esc_html( 'Latest ' . self::when( (float) $s['change']['at'] ) . ': ' . $s['change']['what'] . '.' );
		} elseif ( false === $s['pending'] ) {
			$rows['Changes since last publish'] = 'No' . ( $s['change'] ? esc_html( ' (latest change ' . self::when( (float) $s['change']['at'] ) . ' is in the live build)' ) : '' );
		} else {
			$rows['Changes since last publish'] = 'Unknown (no live build and no recorded change)';
		}
		$cur = array(
			'theme'  => (string) wp_get_theme()->get( 'Version' ),
			'plugin' => BIGDINOSAUR_STATIC_VERSION,
		);
		foreach ( $cur as $k => $v ) {
			if ( $s['live_r'] && isset( $s['live_r'][ $k ] ) && $s['live_r'][ $k ] !== $v ) {
				$rows['Changes since last publish'] .= '<br>' . esc_html( "The live build used $k {$s['live_r'][$k]}; $k $v is installed now." );
			}
		}

		// Queue.
		$last = $s['last'];
		if ( $running ) {
			$q = 'Running';
			if ( $last && 'running' === $last['result'] ) {
				$q .= esc_html( ": {$last['phase']} {$last['progress']}, started " . self::when( $last['started'] ) . " (build {$last['id']}, slot {$last['slot']})" );
			} else {
				$q .= esc_html( ': ' . ( $s['running']['what'] ?? '' ) . ' since ' . self::when( $s['running']['since'] ?? null ) );
			}
		} elseif ( $s['queued'] ) {
			$q = esc_html( 'Queued for ' . self::when( $s['queued'] ) );
			if ( $s['queued'] < time() - 30 ) {
				$q .= '<br><span class="description">WP-Cron has not picked it up yet. The system cron is a fallback when visitor-triggered cron is disabled; if it stays here, run <code>wp bigdinosaur-static doctor</code>.</span>';
			}
		} else {
			$q = 'Idle';
		}
		$rows['Build queue'] = $q;
		if ( $s['error'] ) {
			$rows['Build queue'] .= '<br><strong>Could not start:</strong> ' . esc_html( $s['error']['message'] . ' (' . self::when( (int) $s['error']['at'] ) . ')' );
		}

		// Last build.
		if ( $last && 'running' !== $last['result'] ) {
			$lb  = '<strong>' . esc_html( $last['result'] ) . '</strong>' . esc_html( " — build {$last['id']} into slot {$last['slot']}, finished " . self::when( $last['finished'] ) . ' (' . ( $last['runner']['via'] ?? 'cli' ) . ')' );
			$lb .= '<br>' . esc_html( self::summary( $last ) );
			if ( 'failed' === $last['result'] ) {
				$lb .= '<br>' . esc_html( "The live site was not changed (still slot {$s['live']})." );
			}
			if ( $last['failures'] ) {
				$lb .= '<ul style="list-style:disc;margin-left:2em">';
				foreach ( array_slice( $last['failures'], 0, 30 ) as $f ) {
					$lb .= '<li><code>' . esc_html( $f ) . '</code></li>';
				}
				$lb .= count( $last['failures'] ) > 30 ? '<li>' . esc_html( ( count( $last['failures'] ) - 30 ) . ' more in the report') . '</li>' : '';
				$lb .= '</ul>';
			}
			foreach ( $last['warnings'] ?? array() as $w ) {
				$lb .= '<br>Warning: ' . esc_html( $w );
			}
			if ( $last['notes'] ) {
				$lb .= '<details><summary>' . count( $last['notes'] ) . ' coverage notes</summary><ul style="list-style:disc;margin-left:2em">';
				foreach ( $last['notes'] as $n ) {
					$lb .= '<li><code>' . esc_html( $n ) . '</code></li>';
				}
				$lb .= '</ul></details>';
			}
			$rows['Last build'] = $lb;
		}

		echo '<table class="widefat striped" style="max-width:64em"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th scope="row" style="width:14em">' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1.5em">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		submit_button( $running ? 'Build running…' : 'Generate', 'primary large', 'submit', false, $running ? array( 'disabled' => 'disabled' ) : array() );
		printf( ' &nbsp; <a class="button button-large" href="%s" target="_blank" rel="noopener">View static site</a>', esc_url( Config::origin() . '/' ) );
		echo '</form>';

		if ( $busy ) {
			// Refresh while a build is queued or running (also drops the ?bds= message).
			printf( '<script>setTimeout(function(){location.replace(%s)},2000)</script>', wp_json_encode( self::url() ) );
		}
		echo '</div>';
	}
}
