<?php
/**
 * Plugin Name: BigDinosaur Static
 * Description: Renders this site as clean static HTML and publishes it to a separate static vhost. Configuration is wp-config.php constants; see README.md.
 * Version: 0.6.0
 * Requires at least: 7.1
 * Requires PHP: 8.4
 * Author: Lee Hutchinson
 * License: GPL-2.0-or-later
 *
 * @package bigdinosaur-static
 */

defined( 'ABSPATH' ) || exit;

define( 'BIGDINOSAUR_STATIC_VERSION', '0.6.0' );
define( 'BIGDINOSAUR_STATIC_DIR', __DIR__ );

// Render side (the clean document WordPress emits under the build header).
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Urls.php';      // Urls, Tidy
require_once __DIR__ . '/src/Assets.php';
require_once __DIR__ . '/src/Blocks.php';
require_once __DIR__ . '/src/Render.php';
require_once __DIR__ . '/src/Feed.php';
// Build side (fetch, write, validate, switch).
require_once __DIR__ . '/src/Manifest.php';
require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/src/Slots.php';     // Slots, SlotWriter
require_once __DIR__ . '/src/Build.php';     // Build, Fetcher, Worker
require_once __DIR__ . '/src/Purge.php';
require_once __DIR__ . '/src/Changes.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/src/Cli.php';
	WP_CLI::add_command( 'bigdinosaur-static', 'BigDinosaur\\Static\\Cli' );
}

add_action( 'plugins_loaded', array( 'BigDinosaur\\Static\\Render', 'boot' ), 0 );

// Generate runs the build in WP-Cron; nothing else schedules it.
BigDinosaur\Static\Worker::hook();
register_deactivation_hook( __FILE__, array( 'BigDinosaur\\Static\\Worker', 'deactivate' ) );

// The "changes since last publish" indicator; never builds anything.
BigDinosaur\Static\Changes::hook();

if ( is_admin() ) {
	require_once __DIR__ . '/src/Admin.php';
	BigDinosaur\Static\Admin::hook();
}
