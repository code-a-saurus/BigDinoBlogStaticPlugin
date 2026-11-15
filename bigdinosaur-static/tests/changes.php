<?php
/**
 * The "changes since last publish" flag: which saves mark it, and pending()
 * against the live build's start time. Uses in-memory WP_Post objects; the
 * only write is the flag option, restored at the end. Run like
 * tests/blocks.php.
 *
 * @package bigdinosaur-static
 */

use BigDinosaur\Static\Changes;

if ( ! class_exists( Changes::class ) ) {
	WP_CLI::error( 'bigdinosaur-static is not active' );
}

$bad   = 0;
$count = 0;
$check = function ( string $name, bool $ok, string $detail = '' ) use ( &$bad, &$count ) {
	++$count;
	$bad += $ok ? 0 : 1;
	WP_CLI::line( ( $ok ? 'ok    ' : 'FAIL  ' ) . $name . ( $ok || '' === $detail ? '' : "\n      $detail" ) );
};

$saved = get_option( Changes::OPTION, null );
$post  = fn( string $type, string $status, int $id = 999001 ) => new WP_Post( (object) array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status ) );
// Run a hook with a clean slate; return what it recorded (or null).
$after = function ( callable $fn ) {
	delete_option( Changes::OPTION );
	Changes::reset();
	$fn();
	Changes::reset();
	return Changes::last();
};

try {
	$cases = array(
		'new published post'          => array( 'post', 'publish', null, 'post 999001 published' ),
		'published post updated'      => array( 'post', 'publish', 'publish', 'post 999001 updated' ),
		'published post to draft'     => array( 'post', 'draft', 'publish', 'post 999001 unpublished (draft)' ),
		'published post trashed'      => array( 'post', 'trash', 'publish', 'post 999001 unpublished (trash)' ),
		'draft saved'                 => array( 'post', 'draft', 'draft', null ),
		'new draft'                   => array( 'post', 'draft', null, null ),
		'scheduled'                   => array( 'post', 'future', 'draft', null ),
		'scheduled goes live'         => array( 'post', 'publish', 'future', 'post 999001 published' ),
		'private'                     => array( 'post', 'private', 'draft', null ),
		'page updated'                => array( 'page', 'publish', 'publish', 'page 999001 updated' ),
		'synced pattern updated'      => array( 'wp_block', 'publish', 'publish', 'wp block 999001 updated' ),
		'menu item saved'             => array( 'nav_menu_item', 'publish', null, 'nav menu item 999001 published' ),
		'attachment upload'           => array( 'attachment', 'inherit', null, null ),
		'revision'                    => array( 'revision', 'inherit', null, null ),
		'customize changeset'         => array( 'customize_changeset', 'publish', null, null ),
	);
	foreach ( $cases as $name => [ $type, $status, $before, $want ] ) {
		$got = $after( fn() => Changes::post_saved( 999001, $post( $type, $status ), true, $before ? $post( $type, $before ) : null ) );
		$check( "save: $name", ( $got['what'] ?? null ) === $want, 'got ' . wp_json_encode( $got ) );
	}

	$got = $after( fn() => Changes::post_deleted( 999001, $post( 'post', 'publish' ) ) );
	$check( 'delete published post', 'post 999001 deleted' === ( $got['what'] ?? null ) );
	$got = $after( fn() => Changes::post_deleted( 999001, $post( 'post', 'trash' ) ) );
	$check( 'delete trashed post (already marked when trashed)', null === $got );

	$got = $after( fn() => Changes::option_changed( 'posts_per_page' ) );
	$check( 'option posts_per_page', 'option posts_per_page' === ( $got['what'] ?? null ) );
	$got = $after( fn() => Changes::option_changed( 'theme_mods_' . get_stylesheet() ) );
	$check( 'theme mods (menu locations)', null !== $got );
	$got = $after( fn() => Changes::option_changed( 'cron' ) );
	$check( 'option cron ignored', null === $got );
	$got = $after( fn() => Changes::option_changed( Changes::OPTION ) );
	$check( 'own option ignored', null === $got );

	$got = $after( fn() => Changes::term_changed( 1, 1, 'category' ) );
	$check( 'category edited', 'category 1 changed' === ( $got['what'] ?? null ) );
	$got = $after( fn() => Changes::term_changed( 1, 1, 'nav_menu' ) );
	$check( 'nav_menu term ignored (menus have their own hooks)', null === $got );

	$got = $after(
		function () {
			Changes::mark( 'first' );
			Changes::mark( 'second' );
		}
	);
	$check( 'one write per request: first wins', 'first' === ( $got['what'] ?? null ) );

	// pending() against the live build's start.
	$live = array( 'started_ts' => 1000.5, 'started' => '1970-01-01T00:16:40+00:00' );
	$check( 'pending: change after live build started', true === Changes::pending( $live, array( 'at' => 1000.6, 'what' => 'x' ) ) );
	$check( 'pending: change before live build started', false === Changes::pending( $live, array( 'at' => 1000.4, 'what' => 'x' ) ) );
	$check( 'pending: no live record, a change', true === Changes::pending( null, array( 'at' => 1.0, 'what' => 'x' ) ) );
	delete_option( Changes::OPTION );
	$check( 'pending: no change recorded', false === Changes::pending( $live, null ) );
	$check( 'pending: no live record, no change', null === Changes::pending( null, null ) );
} finally {
	if ( null === $saved ) {
		delete_option( Changes::OPTION );
	} else {
		update_option( Changes::OPTION, $saved, false );
	}
	Changes::reset();
}

WP_CLI::line( sprintf( '%d checks, %d failed', $count, $bad ) );
if ( $bad ) {
	WP_CLI::halt( 1 );
}
