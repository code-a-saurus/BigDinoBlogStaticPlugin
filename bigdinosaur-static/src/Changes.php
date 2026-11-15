<?php
/**
 * The "changes since last publish" indicator.
 *
 * One option holds the time and a short description of the last change that
 * could alter the public output. It is never cleared: the site is behind when
 * that time is later than the start of the build the link points at. That
 * stays true after a failed build, a --no-publish build or a manual switch,
 * where a flag cleared at build start would lie. It drives the admin page and
 * the Posts-screen notice and nothing else; it never schedules a build.
 *
 * Every hook does at most one cheap update_option per request.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Changes {

	const OPTION = 'bigdinosaur_static_changed';

	/** Post types whose published entries reach the static output. */
	const TYPES = array( 'post', 'page', 'wp_block', 'nav_menu_item' );

	/** Options that change rendering, routes, the feed or the sitemap. */
	const OPTIONS = array(
		'blogname',
		'blogdescription',
		'posts_per_page',
		'posts_per_rss',
		'rss_use_excerpt',
		'permalink_structure',
		'category_base',
		'tag_base',
		'date_format',
		'time_format',
		'timezone_string',
		'gmt_offset',
		'start_of_week',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'sticky_posts',
		'site_icon',
		'blog_public',
		'WPLANG',
		'stylesheet',
		'template',
	);

	private static bool $marked = false;

	public static function hook(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'post_saved' ), 10, 4 );
		add_action( 'deleted_post', array( self::class, 'post_deleted' ), 10, 2 );
		add_action( 'attachment_updated', fn( $id ) => self::mark( "attachment $id updated" ) );
		add_action( 'delete_attachment', fn( $id ) => self::mark( "attachment $id deleted" ) );
		add_action( 'updated_post_meta', array( self::class, 'meta_updated' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'meta_updated' ), 10, 3 );
		add_action( 'edited_term', array( self::class, 'term_changed' ), 10, 3 );
		add_action( 'delete_term', array( self::class, 'term_changed' ), 10, 3 );
		add_action( 'wp_update_nav_menu', fn( $id ) => self::mark( "menu $id updated" ) );
		add_action( 'wp_delete_nav_menu', fn( $id ) => self::mark( "menu $id deleted" ) );
		add_action( 'updated_option', array( self::class, 'option_changed' ) );
		add_action( 'added_option', array( self::class, 'option_changed' ) );
		add_action( 'profile_update', fn( $id ) => self::mark( "user $id profile updated" ) );
	}

	/** Public or formerly public posts, pages, synced patterns and menu items. */
	public static function post_saved( $post_id, $post, $update, $before ): void {
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::TYPES, true ) ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$was = $before instanceof \WP_Post && 'publish' === $before->post_status;
		$is  = 'publish' === $post->post_status;
		if ( ! $was && ! $is ) {
			return; // Drafts, scheduled, private, trash: staging only.
		}
		$verb = $is ? ( $was ? 'updated' : 'published' ) : "unpublished ({$post->post_status})";
		self::mark( str_replace( '_', ' ', $post->post_type ) . " {$post->ID} $verb" );
	}

	public static function post_deleted( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && in_array( $post->post_type, self::TYPES, true ) ) {
			self::mark( str_replace( '_', ' ', $post->post_type ) . " {$post->ID} deleted" );
		}
	}

	/** Attachment metadata, alt text or file changes (an existing upload edited). */
	public static function meta_updated( $meta_ids, $object_id, $key ): void {
		if ( in_array( $key, array( '_wp_attachment_metadata', '_wp_attachment_image_alt', '_wp_attached_file' ), true )
			&& 'attachment' === get_post_type( $object_id ) ) {
			self::mark( "attachment $object_id $key" );
		}
	}

	public static function term_changed( $term_id, $tt_id, $taxonomy ): void {
		if ( is_taxonomy_viewable( $taxonomy ) ) {
			self::mark( "$taxonomy $term_id changed" );
		}
	}

	public static function option_changed( $option ): void {
		if ( in_array( $option, self::OPTIONS, true ) || 'theme_mods_' . get_stylesheet() === $option ) {
			self::mark( "option $option" );
		}
	}

	/** Record a change. The first call in a request wins; one write per request. */
	public static function mark( string $what ): void {
		if ( self::$marked ) {
			return;
		}
		self::$marked = true;
		update_option( self::OPTION, array( 'at' => microtime( true ), 'what' => $what ), false );
	}

	/** Forget this request's mark. Tests only: lets one process exercise mark() repeatedly. */
	public static function reset(): void {
		self::$marked = false;
	}

	/** The last recorded change, or null. */
	public static function last(): ?array {
		$v = get_option( self::OPTION );
		return is_array( $v ) && isset( $v['at'] ) ? $v : null;
	}

	/**
	 * Whether WordPress has changes the live static site lacks.
	 *
	 * @param array|null $live The report of the build the link points at.
	 * @return bool|null true/false, or null when there is nothing to compare
	 *                   (no live build record and no recorded change).
	 */
	public static function pending( ?array $live, ?array $last = null ): ?bool {
		$last ??= self::last();
		if ( ! isset( $live['started_ts'] ) ) {
			return $last ? true : null;
		}
		return $last && (float) $last['at'] >= (float) $live['started_ts'];
	}
}
