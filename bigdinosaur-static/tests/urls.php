<?php
/**
 * URL mapper cases (Urls::rewrite_html). Run like tests/blocks.php.
 *
 * @package bigdinosaur-static
 */

use BigDinosaur\Static\Assets;
use BigDinosaur\Static\Config;
use BigDinosaur\Static\Render;
use BigDinosaur\Static\Urls;

if ( ! class_exists( Render::class ) ) {
	WP_CLI::error( 'bigdinosaur-static is not active' );
}
Render::enable();

$s     = Config::source();
$d     = Config::origin();
$css   = '/css/site.css?v=' . Assets::hash( '/css/site.css' );
$cases = array(
	array( "<a href=\"$s/about/\">x</a>", '<a href="/about/">x</a>' ),
	array( "<a href=\"$d/about/#team\">x</a>", '<a href="/about/#team">x</a>' ),
	array( "<a href=\"$s/category/personal/page/2/\">x</a>", '<a href="/categories/personal/page/2/">x</a>' ),
	array( "<a href=\"$s/tag/code/\">x</a>", '<a href="/tags/code/">x</a>' ),
	array( "<a href=\"$s/feed/\">x</a>", '<a href="/feed.xml">x</a>' ),
	array( '<a href="/">Home</a>', '<a href="/">Home</a>' ),
	array( '<a href="#main">skip</a>', '<a href="#main">skip</a>' ),
	array( '<a href="https://fangs.ink">x</a>', '<a href="https://fangs.ink">x</a>' ),
	// Site rule: bigdinosaur.org links are always root-relative, present here or not.
	array( '<a href="https://bigdinosaur.org/octopress-and-nginx/">x</a>', '<a href="/octopress-and-nginx/">x</a>' ),
	array( '<a href="https://bigdinosaur.org">x</a>', '<a href="/">x</a>' ),
	array( '<a href="https://www.bigdinosaur.org/about/">x</a>', '<a href="/about/">x</a>' ),
	// bigdinosaur.org media: local file -> /media/, missing -> external.
	array( '<img src="https://bigdinosaur.org/wp-content/uploads/2025/11/Screenshot-2025-11-11-at-11.53.26.png">', '<img src="/media/2025/11/Screenshot-2025-11-11-at-11.53.26.png">' ),
	array( '<img src="https://bigdinosaur.org/wp-content/uploads/2021/02/blog-whymac-modem.jpg">', '<img src="https://bigdinosaur.org/wp-content/uploads/2021/02/blog-whymac-modem.jpg">' ),
	array( '<a href="https://bigdinosaur.org/?attachment_id=642#main">x</a>', '<a href="https://bigdinosaur.org/?attachment_id=642#main">x</a>' ),
	array( "<img src=\"$s/wp-content/uploads/2025/11/a.png\" srcset=\"$s/wp-content/uploads/2025/11/a.png 2474w, $s/wp-content/uploads/2025/11/a-300x177.png 300w\">", '<img src="/media/2025/11/a.png" srcset="/media/2025/11/a.png 2474w, /media/2025/11/a-300x177.png 300w">' ),
	array( "<link rel=\"stylesheet\" href=\"$s/wp-content/themes/bigdinosaur/style.css?ver=123\">", "<link rel=\"stylesheet\" href=\"$css\">" ),
	array( "<link rel=\"preload\" href=\"$s/wp-content/themes/bigdinosaur/assets/fonts/jost-roman-latin.woff2\" as=\"font\">", '<link rel="preload" href="/fonts/jost-roman-latin.woff2" as="font">' ),
	array( "<img src=\"$s/wp-content/themes/bigdinosaur/assets/images/bigdino-left-cleanbg-square.png\">", '<img src="/img/mascot.png">' ),
	// Identity metadata stays absolute on the destination origin.
	array( "<link rel=\"canonical\" href=\"$d/tag/code/\">", "<link rel=\"canonical\" href=\"$d/tags/code/\">" ),
	array( "<link rel=\"alternate\" type=\"application/rss+xml\" href=\"$d/feed/\">", "<link rel=\"alternate\" type=\"application/rss+xml\" href=\"$d/feed.xml\">" ),
	array( "<meta property=\"og:image\" content=\"$s/wp-content/themes/bigdinosaur/assets/images/card.png\">", "<meta property=\"og:image\" content=\"$d/img/card.png\">" ),
	array( "<meta property=\"og:image\" content=\"$s/wp-content/uploads/2025/11/a.png\">", "<meta property=\"og:image\" content=\"$d/media/2025/11/a.png\">" ),
	array( '<meta name="description" content="see https://bigdinosaur.org/ for more">', '<meta name="description" content="see https://bigdinosaur.org/ for more">' ),
	// Tidy: one space between attributes, quoted values untouched.
	array( '<img alt=""  style="width:  2px"   src="/x.png">', '<img alt="" style="width:  2px" src="/x.png">' ),
	// Text, code and pre are never touched; empty class attributes go.
	array( "<p class=\"\">$s/wp-content/uploads/x <code>$s/wp-admin/</code></p><pre>$s/feed/</pre>", "<p>$s/wp-content/uploads/x <code>$s/wp-admin/</code></p><pre>$s/feed/</pre>" ),
);

$bad = 0;
foreach ( $cases as $i => [ $in, $want ] ) {
	$got = BigDinosaur\Static\Tidy::html( Urls::rewrite_html( $in ) ); // The document pass.
	if ( $got !== $want ) {
		++$bad;
		WP_CLI::line( "FAIL  #$i\n      in:   $in\n      want: $want\n      got:  $got" );
	}
}
WP_CLI::line( ( count( $cases ) - $bad ) . " passed, $bad failed" );
if ( $bad ) {
	WP_CLI::halt( 1 );
}
