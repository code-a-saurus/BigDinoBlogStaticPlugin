<?php
/**
 * The RSS2 feed under build context, saved as /feed.xml. Same shape as
 * The site's feed.xml (content, dc and atom namespaces only, a channel image,
 * no generator, sy: or comment elements); WordPress's own loop, title, link,
 * author, date, category, GUID and excerpt functions; item content through
 * the same block filters as the pages, with URLs absolute on the destination
 * origin. GUIDs are copied verbatim: they are identity.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Feed {

	public static function hook(): void {
		remove_all_actions( 'do_feed_rss2' );
		add_action( 'do_feed_rss2', array( self::class, 'render' ), 10, 0 );
		// Core swaps emoji for s.w.org images (img.wp-smiley) in feeds; leave the characters.
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'the_excerpt_rss', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		// Comments are out of scope; this also keeps comment elements out of any feed.
		add_filter( 'comments_open', '__return_false', PHP_INT_MAX );
		add_filter( 'get_comments_number', fn() => 0, PHP_INT_MAX );
		add_filter( 'feed_link', fn( $url, $feed ) => in_array( $feed, array( '', 'rss2', get_default_feed() ), true ) ? Config::origin() . '/feed.xml' : $url, PHP_INT_MAX, 2 );
	}

	public static function render(): void {
		ob_start();
		self::template();
		$xml = (string) ob_get_clean();
		header( 'Content-Type: application/rss+xml; charset=UTF-8', true );
		if ( Blocks::$failures ) {
			header( 'X-Bigdinosaur-Static-Failures: ' . preg_replace( '/[\r\n]+/', ' ', implode( '; ', array_unique( Blocks::$failures ) ) ), true, 500 );
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per element below.
	}

	/** Item content: block-filtered HTML with every own URL absolute on the destination origin. */
	private static function content(): string {
		$html = Tidy::html( Urls::rewrite_html( (string) get_the_content_feed( 'rss2' ), true ) );
		return str_replace( ']]>', ']]]]><![CDATA[>', $html );
	}

	private static function template(): void {
		$origin = Config::origin();
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
	<title><?php bloginfo_rss( 'name' ); ?></title>
	<atom:link href="<?php echo esc_url( $origin . '/feed.xml' ); ?>" rel="self" type="application/rss+xml" />
	<link><?php echo esc_url( $origin ); ?></link>
	<description><?php bloginfo_rss( 'description' ); ?></description>
	<lastBuildDate><?php echo esc_html( (string) get_feed_build_date( 'r' ) ); ?></lastBuildDate>
	<language><?php bloginfo_rss( 'language' ); ?></language>
	<image>
		<url><?php echo esc_url( $origin . '/icon-32.png' ); ?></url>
		<title><?php bloginfo_rss( 'name' ); ?></title>
		<link><?php echo esc_url( $origin ); ?></link>
		<width>32</width>
		<height>32</height>
	</image>
<?php
		while ( have_posts() ) :
			the_post();
			?>
	<item>
		<title><?php the_title_rss(); ?></title>
		<link><?php the_permalink_rss(); ?></link>
		<dc:creator><![CDATA[<?php the_author(); ?>]]></dc:creator>
		<pubDate><?php echo esc_html( mysql2date( 'D, d M Y H:i:s +0000', (string) get_post_time( 'Y-m-d H:i:s', true ), false ) ); ?></pubDate>
		<?php the_category_rss( 'rss2' ); ?>
		<guid isPermaLink="false"><?php the_guid(); ?></guid>
		<description><![CDATA[<?php the_excerpt_rss(); ?>]]></description>
<?php if ( ! get_option( 'rss_use_excerpt' ) ) : ?>
		<content:encoded><![CDATA[<?php echo self::content(); // phpcs:ignore WordPress.Security.EscapeOutput -- CDATA, terminator escaped. ?>]]></content:encoded>
<?php endif; ?>
	</item>
<?php endwhile; ?>
</channel>
</rss>
		<?php
	}
}
