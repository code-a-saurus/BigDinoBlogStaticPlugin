<?php
/**
 * Build-context rendering: when a front-end request carries
 * X-Bigdinosaur-Static-Render: 1, WordPress itself emits the clean static
 * document. Without the header nothing here is hooked and the site renders
 * exactly as before.
 *
 * The header grants nothing: it only removes and renames output for an
 * anonymous request. It never enables previews, drafts or private content.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Render {

	/** Style handles allowed to print (everything else is dequeued). */
	const STYLES = array( 'bigdinosaur', 'bigdinosaur-prism' );

	/** Script handles allowed to print. */
	const SCRIPTS = array( 'bigdinosaur-post-list', 'bigdinosaur-prism' );

	/** What every page's <meta name="generator"> says. */
	const GENERATOR = 'Wordpress Static';

	/** Whether the request asks for build context at all. */
	public static function requested(): bool {
		$header = 'HTTP_' . strtoupper( str_replace( '-', '_', Config::HEADER ) );
		if ( '1' !== ( $_SERVER[ $header ] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return false;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return in_array( $method, array( 'GET', 'HEAD' ), true )
			&& ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
	}

	public static function boot(): void {
		if ( ! self::requested() ) {
			return;
		}
		$refused = Config::guard();
		if ( '' !== $refused ) {
			add_action(
				'send_headers',
				function () use ( $refused ) {
					header( Config::RESPONSE_HEADER . ': refused: ' . preg_replace( '/[\r\n]+/', ' ', $refused ) );
				}
			);
			return;
		}
		add_action( 'send_headers', fn() => header( Config::RESPONSE_HEADER . ': active' ) );
		self::enable();
		add_action( 'template_redirect', array( self::class, 'buffer' ), PHP_INT_MAX );
	}

	/** Hook every build-context filter. boot() calls it; tests call it directly. */
	public static function enable(): void {
		self::urls();
		self::head();
		self::assets();
		self::markup();
		Blocks::hook();
		Feed::hook();
	}

	/**
	 * Permalinks, canonical, OG, pagination and feed links come out on the
	 * destination origin by construction; the final pass makes navigation
	 * root-relative. redirect_canonical would otherwise 301 every build fetch
	 * to the destination origin.
	 */
	private static function urls(): void {
		add_filter(
			'home_url',
			function ( $url ) {
				$source = Config::source();
				return ( $url === $source || str_starts_with( (string) $url, $source . '/' ) || str_starts_with( (string) $url, $source . '?' ) )
					? Config::origin() . substr( $url, strlen( $source ) )
					: $url;
			},
			PHP_INT_MAX
		);
		remove_action( 'template_redirect', 'redirect_canonical' );
		add_filter( 'redirect_canonical', '__return_false', PHP_INT_MAX );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
	}

	/** Head and footer cruft (hook names checked against WP 7.1.4 default-filters.php). */
	private static function head(): void {
		$wp_head = array(
			'rsd_link'                                => 10,
			'wp_generator'                            => 10,
			'rest_output_link_wp_head'                => 10,
			'wp_shortlink_wp_head'                    => 10,
			'feed_links_extra'                        => 3,
			'locale_stylesheet'                       => 10,
			'print_emoji_detection_script'            => 7,
			'wp_enqueue_img_auto_sizes_contain_css_fix' => 0,
			'wp_print_auto_sizes_contain_css_fix'     => 1,
			'wp_oembed_add_host_js'                   => 10,
			'wp_site_icon'                            => 99,
			'wp_custom_css_cb'                        => 101,
			'wp_post_preview_js'                      => 1,
			'_custom_logo_header_styles'              => 10,
		);
		foreach ( $wp_head as $fn => $priority ) {
			remove_action( 'wp_head', $fn, $priority );
		}
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_footer', 'wp_print_speculation_rules' );
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );

		// Redis Object Cache (prod; the LAN runs without it) prints
		// "<!-- Performance optimized by Redis Object Cache ... -->" after
		// </html> on shutdown, inside our buffer. Its own switch, read when its
		// wp_head callback registers that shutdown hook; we run at plugins_loaded.
		if ( ! defined( 'WP_REDIS_DISABLE_COMMENT' ) ) {
			define( 'WP_REDIS_DISABLE_COMMENT', true );
		}

		// The static site names its generator; core's wp_generator is removed above.
		add_action(
			'wp_head',
			function () {
				echo '<meta name="generator" content="' . esc_attr( self::GENERATOR ) . '">' . "\n";
			},
			1
		);

		// Script modules (importmap, module preloads, module data): none ship.
		add_action(
			'wp_loaded',
			function () {
				$modules = wp_script_modules();
				foreach ( array( 'wp_head', 'wp_footer' ) as $hook ) {
					foreach ( array( 'print_import_map', 'print_enqueued_script_modules', 'print_head_enqueued_script_modules', 'print_script_module_preloads', 'print_script_module_data', 'print_script_module_translations', 'print_a11y_script_module_html' ) as $method ) {
						// Core hooks these at priority 10, 20 or 21 depending on the
						// method; removing at all three covers every combination.
						foreach ( array( 10, 20, 21 ) as $priority ) {
							remove_action( $hook, array( $modules, $method ), $priority );
						}
					}
				}
			}
		);

		// The site's three icon links (core's wp_site_icon is removed above).
		add_action(
			'wp_head',
			function () {
				echo '<link rel="icon" href="/icon-32.png" sizes="32x32">' . "\n";
				echo '<link rel="icon" href="/icon-192.png" sizes="192x192">' . "\n";
				echo '<link rel="apple-touch-icon" href="/apple-touch-icon.png">' . "\n";
			},
			3
		);
	}

	/** Only the theme's stylesheet, Prism and the post-list loader print, as plain tags. */
	private static function assets(): void {
		foreach ( array(
			'wp_enqueue_emoji_styles'             => 10,
			'wp_common_block_scripts_and_styles'  => 10,
			'wp_enqueue_classic_theme_styles'     => 10,
			'wp_enqueue_global_styles'            => 10,
			'wp_enqueue_stored_styles'            => 10,
			'wp_enqueue_block_template_skip_link' => 10,
			'wp_enqueue_admin_bar_bump_styles'    => 10,
			'wp_enqueue_admin_bar_header_styles'  => 10,
		) as $fn => $priority ) {
			remove_action( 'wp_enqueue_scripts', $fn, $priority );
		}
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		remove_action( 'wp_footer', 'wp_enqueue_stored_styles', 1 );
		// Off, so wp_load_classic_theme_block_styles_on_demand() bails and never
		// hoists late block <style>s into the head; the one wp-block-library
		// handle it enqueues instead is dropped by the allowlist below.
		add_filter( 'should_load_separate_core_block_assets', '__return_false', PHP_INT_MAX );
		add_filter( 'should_load_block_assets_on_demand', '__return_false', PHP_INT_MAX );
		add_filter( 'print_late_styles', '__return_false' );

		// Dequeue everything but the allowed handles, then strip the survivors'
		// dependencies and inline before/after/data snippets, which would
		// otherwise print extra tags or pull dropped handles back in.
		$filter_queue = function ( \WP_Dependencies $deps, array $allowed ) {
			foreach ( $deps->queue as $handle ) {
				if ( ! in_array( $handle, $allowed, true ) ) {
					$deps->dequeue( $handle );
				}
			}
			foreach ( $allowed as $handle ) {
				if ( isset( $deps->registered[ $handle ] ) ) {
					$deps->registered[ $handle ]->deps = array();
					unset( $deps->registered[ $handle ]->extra['after'], $deps->registered[ $handle ]->extra['before'], $deps->registered[ $handle ]->extra['data'] );
				}
			}
		};
		add_action( 'wp_print_styles', fn() => $filter_queue( wp_styles(), self::STYLES ), 0 );
		add_action( 'wp_print_scripts', fn() => $filter_queue( wp_scripts(), self::SCRIPTS ), 0 );
		add_action( 'wp_print_footer_scripts', fn() => $filter_queue( wp_scripts(), self::SCRIPTS ), 0 );

		add_filter(
			'style_loader_tag',
			fn( $tag, $handle, $href ) => in_array( $handle, self::STYLES, true ) ? '<link rel="stylesheet" href="' . esc_url( $href ) . '">' . "\n" : '',
			PHP_INT_MAX,
			3
		);
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle, $src ) {
				if ( ! in_array( $handle, self::SCRIPTS, true ) ) {
					return '';
				}
				$defer = 'defer' === wp_scripts()->get_data( $handle, 'strategy' ) ? ' defer' : '';
				return '<script' . $defer . ' src="' . esc_url( $src ) . '"></script>' . "\n";
			},
			PHP_INT_MAX,
			3
		);
	}

	/** Body, article and menu markup reduced to the site vocabulary. */
	private static function markup(): void {
		add_filter( 'body_class', fn() => array(), PHP_INT_MAX );
		add_filter( 'post_class', fn() => array( 'page' === get_post_type() ? 'page' : 'post' ), PHP_INT_MAX );
		add_filter( 'nav_menu_css_class', fn() => array( 'menu-item' ), PHP_INT_MAX );
		add_filter( 'nav_menu_item_id', fn() => '', PHP_INT_MAX );
		add_filter(
			'wp_nav_menu_args',
			function ( $args ) {
				$args['items_wrap'] = '<ul class="%2$s">%3$s</ul>';
				return $args;
			},
			PHP_INT_MAX
		);
		// Layout, elements, duotone and similar block supports add wp-container-N,
		// is-layout-*, wp-elements-* classes and the core-block-supports <style>.
		foreach ( array(
			'wp_render_layout_support_flag',
			'wp_render_elements_class_name',
			'wp_render_block_style_variation_class_name',
			'wp_render_custom_css_class_name',
			'_wp_add_block_level_presets_class',
			'wp_render_position_support',
		) as $fn ) {
			remove_filter( 'render_block', $fn, 10 );
		}
		remove_filter( 'render_block', array( 'WP_Duotone', 'render_duotone_support' ), 10 );
		remove_filter( 'render_block_data', 'wp_render_elements_support_styles', 10 );
		remove_filter( 'render_block_data', 'wp_render_custom_css_support_styles', 10 );
		remove_filter( 'render_block_data', 'wp_render_block_style_variation_support_styles', 10 );
		remove_filter( 'pre_render_block', '_wp_add_block_level_preset_styles', 10 );
	}

	/** Final pass over the HTML document: URL attributes only. Feeds are handled by Feed. */
	public static function buffer(): void {
		if ( is_feed() ) {
			return;
		}
		ob_start(
			function ( $html ) {
				// Tidy also turns body_class()'s empty "<body >" into "<body>".
				$html = Tidy::html( Urls::rewrite_html( (string) $html ) );
				if ( Blocks::$failures ) {
					// A 500 and the failures header; the fetcher refuses the page and reports the list.
					$list = implode( '; ', array_unique( Blocks::$failures ) );
					header( 'X-Bigdinosaur-Static-Failures: ' . preg_replace( '/[\r\n]+/', ' ', $list ), true, 500 );
				}
				return $html;
			}
		);
	}
}
