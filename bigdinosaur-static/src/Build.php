<?php
/**
 * The build engine, the fetcher it uses, and the WP-Cron runner behind the
 * Generate button. The CLI and Generate run the same thing:
 *
 *   manifest -> fetch each route with the build-context header -> write into
 *   the slot the link does not point at (emptied first) -> assets, robots,
 *   sitemap -> referenced media (hardlinked from uploads) -> validate every
 *   file against the slot -> switch the link (unless --no-publish) ->
 *   Cloudflare purge when configured.
 *
 * Any failure leaves the link alone; the failed output stays in the inactive
 * slot for inspection. One lock (flock, released by the kernel if the process
 * dies) serializes build and publish. State lives in <ROOT>-builds/state/:
 * lock, report.json (the current or last build, rewritten as it progresses),
 * slot-a.json / slot-b.json (the build each slot holds), build.log.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

/** Another build or publish holds the lock. The cron worker retries later. */
final class LockedException extends \RuntimeException {}

final class Build {

	/**
	 * robots.txt follows WordPress's own "discourage search engines" setting
	 * (Settings > Reading): on, as on the LAN test site, disallows everything;
	 * off, as on prod, allows everything and names the sitemap.
	 */
	public static function robots(): string {
		if ( 1 !== (int) get_option( 'blog_public' ) ) {
			return "User-agent: *\nDisallow: /\n";
		}
		return "User-agent: *\nAllow: /\n\nSitemap: " . Config::origin() . "/sitemap.xml\n";
	}

	/** @var resource|null */
	private static $lock = null;

	private array $r;

	/** @var callable(string):void */
	private $say;

	private function __construct( callable $say ) {
		$this->say = $say;
	}

	/**
	 * Build into the inactive slot and, when $publish, switch to it. Returns
	 * the report. Throws (before any write) when the guard, the layout or the
	 * lock forbid running; every later problem is a failure in the report.
	 */
	public static function run( bool $publish = true, ?callable $say = null, string $via = 'cli' ): array {
		self::preflight();
		// Finish even if the browser or the cron caller disconnects.
		ignore_user_abort( true );
		$unlimited = function_exists( 'set_time_limit' ) && set_time_limit( 0 );
		self::lock( ( $publish ? 'build' : 'build --no-publish' ) . " ($via)" );
		try {
			$b = new self( $say ?? static function () {} );
			return $b->build(
				$publish,
				array(
					'via'                    => $via,
					'sapi'                   => PHP_SAPI,
					'pid'                    => getmypid(),
					'fastcgi_finish_request' => function_exists( 'fastcgi_finish_request' ),
					'set_time_limit'         => $unlimited,
				)
			);
		} finally {
			self::unlock();
		}
	}

	/**
	 * Switch the link to the inactive slot, only if that slot holds a build
	 * that validated and was never published (a build --no-publish).
	 */
	public static function publish(): array {
		self::preflight();
		self::lock( 'publish' );
		try {
			$live = Slots::live();
			$slot = Slots::other( $live );
			$rep  = self::read_state( "slot-$slot.json" );
			if ( ! $rep || 'validated' !== ( $rep['result'] ?? '' ) ) {
				throw new \RuntimeException( "slot $slot holds no validated, unpublished build (its report says: " . ( $rep['result'] ?? 'nothing' ) . ')' );
			}
			if ( ! is_file( Slots::dir( $slot ) . '/index.html' ) ) {
				throw new \RuntimeException( "slot $slot has no index.html" );
			}
			$rep['live_before'] = $live;
			self::go_live( $rep );
			self::write_state( "slot-$slot.json", $rep );
			$last = self::read_state( 'report.json' );
			if ( ( $last['id'] ?? '' ) === $rep['id'] ) {
				self::write_state( 'report.json', $rep );
			}
			self::log( "{$rep['id']} publish slot $slot (was $live): {$rep['result']}" );
			return $rep;
		} finally {
			self::unlock();
		}
	}

	/** Shared status for the admin page and both CLI formats. Reads only. */
	public static function status(): array {
		$out = array( 'root' => Config::root(), 'live' => null, 'problems' => array(), 'running' => self::holder() );
		try {
			$out['live'] = Slots::live();
		} catch ( \RuntimeException $e ) {
			$out['problems'][] = $e->getMessage();
		}
		$out['last'] = self::read_state( 'report.json' );
		foreach ( Slots::NAMES as $s ) {
			$out['slots'][ $s ] = self::read_state( "slot-$s.json" );
		}
		$out['live_r']  = $out['live'] ? ( $out['slots'][ $out['live'] ] ?? null ) : null;
		$out['change']  = Changes::last();
		$out['pending'] = Changes::pending( $out['live_r'], $out['change'] );
		$out['queued']  = Worker::queued();
		$out['error']   = get_option( Worker::ERROR_OPTION ) ?: null;
		return $out;
	}

	/** Who holds the lock (array from the lock file), or null when free. */
	public static function holder(): ?array {
		$file = Slots::state() . '/lock';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$fh = fopen( $file, 'r' );
		if ( ! $fh ) {
			return null;
		}
		// A shared lock succeeds only while no builder holds the exclusive one.
		if ( flock( $fh, LOCK_SH | LOCK_NB ) ) {
			flock( $fh, LOCK_UN );
			fclose( $fh );
			return null;
		}
		$held = json_decode( (string) stream_get_contents( $fh ), true );
		fclose( $fh );
		return is_array( $held ) ? $held : array( 'what' => 'unknown' );
	}

	private static function preflight(): void {
		$refused = Config::guard();
		if ( '' !== $refused ) {
			throw new \RuntimeException( "refusing to run: $refused" );
		}
		$problems = Slots::problems();
		if ( $problems ) {
			throw new \RuntimeException( 'layout: ' . implode( '; ', $problems ) );
		}
	}

	private static function lock( string $what ): void {
		// c+: create if missing, never truncate, so a losing contender can read
		// who holds the lock (below) before giving up.
		$fh = fopen( Slots::state() . '/lock', 'c+' );
		if ( ! $fh ) {
			throw new \RuntimeException( 'cannot open the lock file' );
		}
		if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
			$held = (string) stream_get_contents( $fh );
			fclose( $fh );
			throw new LockedException( "another build or publish is running: $held" );
		}
		ftruncate( $fh, 0 );
		fwrite( $fh, (string) wp_json_encode( array( 'what' => $what, 'pid' => getmypid(), 'since' => wp_date( 'c' ) ) ) );
		fflush( $fh );
		self::$lock = $fh;
	}

	private static function unlock(): void {
		if ( self::$lock ) {
			ftruncate( self::$lock, 0 );
			flock( self::$lock, LOCK_UN );
			fclose( self::$lock );
			self::$lock = null;
		}
	}

	private function build( bool $publish, array $runner ): array {
		$t0   = microtime( true );
		$live = Slots::live();
		$slot = Slots::other( $live );
		$this->r = array(
			'id'           => wp_date( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) ),
			'slot'         => $slot,
			'live_before'  => $live,
			'publish'      => $publish,
			'plugin'       => BIGDINOSAUR_STATIC_VERSION,
			'theme'        => (string) wp_get_theme()->get( 'Version' ),
			'started'      => wp_date( 'c' ),
			'started_ts'   => $t0,
			'runner'       => $runner,
			'finished'     => null,
			'duration'     => null,
			'result'       => 'running',
			'phase'        => 'starting',
			'progress'     => '',
			'published_at' => null,
			'pages'        => array(),
			'assets'       => array(),
			'media'        => array(),
			'failures'     => array(),
			'notes'        => array(),
			'warnings'     => array(),
		);
		$this->save();
		( $this->say )( "build {$this->r['id']} into slot $slot (live: $live)" );

		try {
			// Invalidate the previous candidate before touching its files. An abrupt
			// exit must never leave a stale 'validated' record authorizing publish.
			self::write_state( "slot-$slot.json", $this->r );
			$w = new SlotWriter( Slots::dir( $slot ) );
			Slots::empty_dir( $w->dir );
			$routes = Manifest::routes();
			$refs   = $this->fetch_all( $w, $routes );
			$this->assets( $w, $routes );
			$this->media( $w, $refs );
			$this->validate( $w, $routes );
		} catch ( \Throwable $e ) {
			$this->r['failures'][] = 'build aborted: ' . $e->getMessage();
		}

		$this->r['result'] = $this->r['failures'] ? 'failed' : 'validated';
		if ( $publish && 'validated' === $this->r['result'] ) {
			$this->phase( 'publishing' );
			try {
				self::go_live( $this->r );
			} catch ( \Throwable $e ) {
				$this->r['failures'][] = 'publish: ' . $e->getMessage();
				$this->r['result']     = 'failed';
			}
		}
		$this->r['phase']    = 'done';
		$this->r['progress'] = '';
		$this->r['finished'] = wp_date( 'c' );
		$this->r['duration'] = round( microtime( true ) - $t0, 1 );
		$this->r['notes']    = array_values( array_unique( $this->r['notes'] ) );
		sort( $this->r['notes'] );
		$this->save();
		self::write_state( "slot-$slot.json", $this->r );
		self::log(
			sprintf(
				'%s build slot %s: %s, %d pages, %d media, %d failures, %.1fs',
				$this->r['id'],
				$slot,
				$this->r['result'],
				count( $this->r['pages'] ),
				count( $this->r['media'] ),
				count( $this->r['failures'] ),
				$this->r['duration']
			)
		);
		return $this->r;
	}

	/** Switch the link to $rep['slot'], then purge Cloudflare if configured. */
	private static function go_live( array &$rep ): void {
		Slots::switch_to( $rep['slot'] );
		$rep['result']       = 'published';
		$rep['published_at'] = wp_date( 'c' );
		if ( Purge::configured() ) {
			try {
				Purge::everything();
			} catch ( \Throwable $e ) {
				// The site is live; a failed purge is a warning, not a rollback.
				$rep['warnings'][] = $e->getMessage();
			}
		}
	}

	/**
	 * Fetch every route and write it. Returns referenced media: /media/ path
	 * => list of pages referencing it.
	 */
	private function fetch_all( SlotWriter $w, array $routes ): array {
		$this->phase( 'fetching' );
		$refs  = array();
		$total = count( $routes );
		$n     = 0;
		foreach ( $routes as $path => $route ) {
			++$n;
			$res = Fetcher::get( $route['source'] );
			if ( 200 !== $res['status'] || '' !== $res['error'] ) {
				$this->r['failures'][] = "$path: fetch {$route['source']}: status {$res['status']} {$res['error']}";
				continue;
			}
			$file = 'feed' === $route['kind'] ? $path : $path . 'index.html';
			$w->put( $file, $res['body'] );
			$this->r['pages'][ $path ] = strlen( $res['body'] );
			$found = 'feed' === $route['kind'] ? self::feed_media_refs( $res['body'] ) : self::media_refs( $res['body'] );
			foreach ( $found as $m ) {
				$refs[ $m ][] = $path;
			}
			if ( 0 === $n % 10 || $n === $total ) {
				$this->r['progress'] = "$n/$total";
				$this->save();
			}
		}
		( $this->say )( sprintf( 'fetched %d of %d routes', count( $this->r['pages'] ), $total ) );
		return $refs;
	}

	private function assets( SlotWriter $w, array $routes ): void {
		$this->phase( 'assets' );
		foreach ( Assets::all() as $path => $a ) {
			if ( isset( $a['bytes'] ) ) {
				$w->put( $path, $a['bytes'] );
				$this->r['assets'][ $path ] = hash( 'sha256', $a['bytes'] );
			} else {
				if ( ! is_file( $a['file'] ) ) {
					throw new \RuntimeException( "asset source missing: {$a['file']}" );
				}
				$w->copy( $path, $a['file'] );
				$this->r['assets'][ $path ] = (string) hash_file( 'sha256', $a['file'] );
			}
		}
		$w->put( '/robots.txt', self::robots() );
		$w->put( '/sitemap.xml', Manifest::sitemap( $routes ) );
		( $this->say )( sprintf( 'wrote %d assets, robots.txt, sitemap.xml', count( $this->r['assets'] ) ) );
	}

	/** Hardlink (or copy) each referenced upload into /media/. A missing file fails the build. */
	private function media( SlotWriter $w, array $refs ): void {
		$this->phase( 'media' );
		$base = realpath( wp_get_upload_dir()['basedir'] );
		if ( ! $base ) {
			throw new \RuntimeException( 'uploads directory missing' );
		}
		ksort( $refs );
		foreach ( $refs as $path => $pages ) {
			$where = implode( ', ', array_slice( array_unique( $pages ), 0, 3 ) ) . ( count( array_unique( $pages ) ) > 3 ? ', …' : '' );
			try {
				$expect = Slots::join( $base, '/' . substr( $path, strlen( '/media/' ) ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->r['failures'][] = "$where: unsafe media URL $path";
				continue;
			}
			$real = realpath( $expect );
			if ( ! $real || ! is_file( $real ) || ! str_starts_with( $real, $base . '/' ) ) {
				$this->r['failures'][] = "$where: $path is not in the uploads (expected $expect)";
				continue;
			}
			$w->copy( $path, $real, true );
			$this->r['media'][ $path ] = (string) hash_file( 'sha256', $real );
		}
		( $this->say )( sprintf( 'linked %d media files', count( $this->r['media'] ) ) );
	}

	/** Validate everything in the slot against the slot itself. */
	private function validate( SlotWriter $w, array $routes ): void {
		$this->phase( 'validating' );
		$resolve = Validator::dir_resolver( $w->dir );
		$plain   = Fetcher::get( '/feed/', false );
		$guids   = 200 === $plain['status'] ? Validator::feed_guids( $plain['body'] ) : null;
		if ( null === $guids ) {
			$this->r['failures'][] = "/feed.xml: source feed status {$plain['status']}";
		}
		$checks = array();
		foreach ( $routes as $path => $route ) {
			$checks[ $path ] = array( 'feed' === $route['kind'] ? $path : $path . 'index.html', $route['kind'], $route['code'] );
		}
		$checks['/404.html'] = array( '/404.html', '404', false );
		foreach ( $checks as $path => [ $file, $kind, $code ] ) {
			$abs = Slots::join( $w->dir, $file );
			if ( ! is_file( $abs ) ) {
				if ( ! isset( $this->r['pages'][ $path ] ) && '/404.html' !== $path ) {
					continue; // Fetch failure, already reported.
				}
				$this->r['failures'][] = "$path: $file missing from the slot";
				continue;
			}
			$body = (string) file_get_contents( $abs );
			$v    = 'feed' === $kind ? Validator::feed( $body, $guids, $resolve ) : Validator::html( $body, $kind, $code, $resolve );
			foreach ( $v['failures'] as $f ) {
				$this->r['failures'][] = "$path: $f";
			}
			$this->r['notes'] = array_merge( $this->r['notes'], $v['notes'] );
		}

		$sitemap = new \DOMDocument();
		if ( ! @$sitemap->loadXML( (string) file_get_contents( $w->dir . '/sitemap.xml' ), LIBXML_NONET ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->r['failures'][] = '/sitemap.xml: does not parse as XML';
		} else {
			foreach ( $sitemap->getElementsByTagName( 'loc' ) as $loc ) {
				$url = trim( $loc->textContent );
				if ( ! str_starts_with( $url, Config::origin() . '/' ) || ! $resolve( substr( $url, strlen( Config::origin() ) ), '' ) ) {
					$this->r['failures'][] = "/sitemap.xml: $url does not resolve";
				}
			}
		}

		// The stylesheets' own url()s (fonts, images).
		foreach ( array( '/css/site.css', '/css/prism.css' ) as $css ) {
			preg_match_all( '/url\(\s*[\'"]?([^\'")]+)/', (string) file_get_contents( $w->dir . $css ), $m );
			foreach ( $m[1] as $url ) {
				if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
					[ $p, $q ] = array_pad( explode( '?', (string) preg_replace( '/#.*$/', '', $url ), 2 ), 2, '' );
					if ( ! $resolve( $p, $q ) ) {
						$this->r['failures'][] = "$css: url($url) does not resolve";
					}
				} elseif ( ! str_starts_with( $url, 'data:' ) ) {
					$this->r['failures'][] = "$css: url($url) is not root-relative";
				}
			}
		}
		( $this->say )( sprintf( 'validated %d documents: %d failures, %d notes', count( $checks ), count( $this->r['failures'] ), count( array_unique( $this->r['notes'] ) ) ) );
	}

	/** /media/ paths (no query or fragment) in the URL attributes and <meta content> of a page. */
	public static function media_refs( string $html ): array {
		$out = array();
		$p   = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			$vals = array();
			if ( 'META' === $p->get_tag() ) {
				$content = (string) $p->get_attribute( 'content' );
				if ( Urls::is_own( $content ) ) {
					$vals[] = $content; // og:image, twitter:image; never prose.
				}
			} else {
				foreach ( Urls::URL_ATTRIBUTES as $attr ) {
					$v = $p->get_attribute( $attr );
					if ( ! is_string( $v ) || '' === $v ) {
						continue;
					}
					if ( 'srcset' === $attr ) {
						foreach ( explode( ',', $v ) as $c ) {
							$vals[] = (string) preg_split( '/\s+/', trim( $c ) )[0];
						}
					} else {
						$vals[] = $v;
					}
				}
			}
			foreach ( $vals as $v ) {
				if ( Urls::is_own( $v ) ) {
					$v = (string) preg_replace( '#^https://[^/?\#]+#', '', $v );
				}
				if ( str_starts_with( $v, '/media/' ) ) {
					$out[] = (string) preg_replace( '/[?#].*$/s', '', $v );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The same over every item's content:encoded in the feed. */
	public static function feed_media_refs( string $xml ): array {
		$doc = new \DOMDocument();
		if ( ! @$doc->loadXML( $xml, LIBXML_NONET ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- validation reports it.
			return array();
		}
		$out = array();
		foreach ( $doc->getElementsByTagNameNS( Validator::FEED_NS['content'], 'encoded' ) as $c ) {
			$out = array_merge( $out, self::media_refs( $c->textContent ) );
		}
		return array_values( array_unique( $out ) );
	}

	private function phase( string $phase ): void {
		$this->r['phase']    = $phase;
		$this->r['progress'] = '';
		$this->save();
	}

	private function save(): void {
		self::write_state( 'report.json', $this->r );
	}

	public static function read_state( string $name ): ?array {
		$file = Slots::state() . '/' . $name;
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	/** Atomic: temp file then rename, so a reader never sees half a report. */
	private static function write_state( string $name, array $data ): void {
		$file = Slots::state() . '/' . $name;
		$tmp  = $file . '.tmp';
		if ( false === file_put_contents( $tmp, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ) || ! rename( $tmp, $file ) ) {
			throw new \RuntimeException( "cannot write $file" );
		}
	}

	private static function log( string $line ): void {
		file_put_contents( Slots::state() . '/build.log', wp_date( 'c' ) . " $line\n", FILE_APPEND | LOCK_EX );
	}
}

/**
 * Build fetches from the source origin: build-context header, no cookies, no
 * redirects, a timeout and a size cap, TLS verified against the system trust
 * store (which holds the LAN CA; WordPress's bundled one does not).
 */
final class Fetcher {

	const TIMEOUT  = 30;
	const MAX_SIZE = 16 * 1024 * 1024;

	/**
	 * GET a source path. $build false fetches the normal (non-build) page,
	 * used only for comparisons such as the source feed's GUIDs.
	 *
	 * @return array{status:int,body:string,error:string}
	 */
	public static function get( string $path, bool $build = true ): array {
		if ( ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) ) {
			throw new \InvalidArgumentException( "not a root-relative path: $path" );
		}
		$args = array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_SIZE,
			'sslverify'           => true,
			'user-agent'          => 'bigdinosaur-static/' . BIGDINOSAUR_STATIC_VERSION,
			'headers'             => $build ? array( Config::HEADER => '1' ) : array(),
			'cookies'             => array(),
		);
		$ca = openssl_get_cert_locations()['default_cert_file'] ?? '';
		if ( is_readable( $ca ) ) {
			$args['sslcertificates'] = $ca;
		}
		$r = wp_remote_get( Config::source() . $path, $args );
		if ( is_wp_error( $r ) ) {
			return array( 'status' => 0, 'body' => '', 'error' => $r->get_error_message() );
		}
		$headers = array_change_key_case( wp_remote_retrieve_headers( $r )->getAll(), CASE_LOWER );
		$error   = '';
		$state   = $headers[ strtolower( Config::RESPONSE_HEADER ) ] ?? null;
		if ( $build && 'active' !== $state ) {
			$error = Config::RESPONSE_HEADER . ' is ' . wp_json_encode( $state );
		}
		if ( isset( $headers['x-bigdinosaur-static-failures'] ) ) {
			$error = 'render failures: ' . implode( '; ', (array) $headers['x-bigdinosaur-static-failures'] );
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $r ),
			'body'   => (string) wp_remote_retrieve_body( $r ),
			'error'  => $error,
		);
	}
}

/**
 * The Generate runner: WordPress's own cron, no systemd, no system cron, no
 * shell. Generate schedules the one-off event bigdinosaur_static_build for
 * now and calls spawn_cron(), which POSTs to wp-cron.php without waiting.
 * Core's wp-cron.php calls fastcgi_finish_request() before running events,
 * so the build runs in a PHP-FPM worker detached from any browser. Nothing
 * else schedules the event.
 */
final class Worker {

	const HOOK = 'bigdinosaur_static_build';

	/** Why the last cron run could not start a build (preflight refusal), if it could not. */
	const ERROR_OPTION = 'bigdinosaur_static_worker_error';

	public static function hook(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/** When the event is due (unix time), or null when nothing is queued. */
	public static function queued(): ?int {
		$t = wp_next_scheduled( self::HOOK );
		return $t ? (int) $t : null;
	}

	/**
	 * The Generate button. Never starts a second build: a running build
	 * refuses, an already queued one is left as it is.
	 *
	 * @return string 'queued', 'already queued' or 'running'.
	 */
	public static function generate(): string {
		if ( Build::holder() ) {
			return 'running';
		}
		$already = null !== self::queued();
		if ( ! $already ) {
			$ok = wp_schedule_single_event( time(), self::HOOK, array(), true );
			if ( is_wp_error( $ok ) ) {
				throw new \RuntimeException( 'cannot schedule the build: ' . $ok->get_error_message() );
			}
		}
		delete_option( self::ERROR_OPTION );
		spawn_cron();
		return $already ? 'already queued' : 'queued';
	}

	/** The event callback, in the detached wp-cron.php worker. */
	public static function run(): void {
		try {
			Build::run( true, null, 'cron' );
			delete_option( self::ERROR_OPTION );
		} catch ( LockedException $e ) {
			// A CLI build or publish holds the lock: try again in a minute.
			if ( null === self::queued() ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
			}
		} catch ( \Throwable $e ) {
			// Refused before any write (guard, layout). Build failures are in the report instead.
			update_option( self::ERROR_OPTION, array( 'at' => time(), 'message' => $e->getMessage() ), false );
		}
	}

	/** Plugin deactivation: drop any queued build. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
