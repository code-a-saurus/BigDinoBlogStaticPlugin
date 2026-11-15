<?php
/**
 * Block fixtures through the build-context render filters.
 * Run: sudo -u www-data wp --path=<wordpress root> eval-file tests/blocks.php
 * Read-only: writes nothing.
 *
 * @package bigdinosaur-static
 */

use BigDinosaur\Static\Blocks;
use BigDinosaur\Static\Config;
use BigDinosaur\Static\Render;

if ( ! class_exists( Render::class ) ) {
	WP_CLI::error( 'bigdinosaur-static is not active' );
}
Render::enable();

$fixtures = array(
	'paragraph with drop cap' => array(
		'<!-- wp:paragraph {"dropCap":true} --><p class="has-drop-cap">Once upon a <a href="__SRC__/about/">time</a>.</p><!-- /wp:paragraph -->',
		// URLs are left for the document pass (tests/urls.php). __SRC__ is the source origin.
		'has'  => array( '<p class="drop-cap">Once upon a <a href="__SRC__/about/">time</a>.</p>' ),
		'lacks' => array( 'wp-block-paragraph', 'has-drop-cap' ),
	),
	'plain paragraph keeps text, entities and code' => array(
		'<!-- wp:paragraph --><p>Edit <code>/wp-content/uploads/</code> &amp; <code>&lt;?php</code> in __SRC__/x; say "hi" >  out</p><!-- /wp:paragraph -->',
		'has'  => array( '<p>Edit <code>/wp-content/uploads/</code> &amp; <code>&lt;?php</code> in __SRC__/x; say "hi" >  out</p>' ),
		'lacks' => array( 'class=' ),
	),
	'heading with anchor' => array(
		'<!-- wp:heading {"level":3} --><h3 class="wp-block-heading" id="why">Why</h3><!-- /wp:heading -->',
		'has'  => array( '<h3 id="why">Why</h3>' ),
		'lacks' => array( 'wp-block-heading' ),
	),
	'image: aligned, linked, captioned, with lightbox attribute' => array(
		'<!-- wp:image {"lightbox":{"enabled":true},"id":894,"sizeSlug":"large","linkDestination":"media","align":"left","className":"is-style-default"} --><figure class="wp-block-image alignleft size-large is-style-default"><a href="__SRC__/wp-content/uploads/2025/11/Screenshot-2025-11-11-at-11.53.26.png"><img src="__SRC__/wp-content/uploads/2025/11/Screenshot-2025-11-11-at-11.53.26-1024x605.png" alt="An image" class="wp-image-894"/></a><figcaption class="wp-element-caption">Caption <em>here</em></figcaption></figure><!-- /wp:image -->',
		// Core 7.1 restores the classic-theme outer container for aligned images:
		// div.image > figure.align-x, the older-post shape (blocks.css styles both).
		'has'  => array( '<div class="image"><figure class="align-left">', 'alt="An image"', ' srcset="', ' width="', ' height="', '<figcaption class="caption">Caption <em>here</em></figcaption>' ),
		'lacks' => array( 'wp-image', 'size-large', 'is-style', 'data-wp-', 'lightbox', '<button', 'wp-element' ),
	),
	'image: resized with width' => array(
		'<!-- wp:image {"id":894,"width":"298px","sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full is-resized"><img src="__SRC__/wp-content/uploads/2025/11/a.png" alt="" class="wp-image-894" style="width:298px"/></figure><!-- /wp:image -->',
		'has'  => array( '<figure class="image resized">', 'style="width:298px"' ),
		'lacks' => array( 'size-full', 'is-resized', 'wp-image' ),
	),
	'gallery: nested images, 2 columns, cropped' => array(
		'<!-- wp:gallery {"columns":2,"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-2 is-cropped"><!-- wp:image {"id":894,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="__SRC__/wp-content/uploads/2025/11/a.png" alt="" class="wp-image-894"/></figure><!-- /wp:image --><!-- wp:image {"id":895,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="__SRC__/wp-content/uploads/2025/11/b.png" alt="" class="wp-image-895"/></figure><!-- /wp:image --><figcaption class="blocks-gallery-caption wp-element-caption">Two</figcaption></figure><!-- /wp:gallery -->',
		'has'  => array( 'class="gallery cropped"', 'data-columns="2"', '<figure class="image">', 'class="blocks-gallery-caption caption"' ),
		'lacks' => array( 'has-nested-images', 'columns-2', 'is-layout', 'wp-block-gallery', 'data-wp-', 'wp-image' ),
	),
	'gallery: default columns' => array(
		'<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":894} --><figure class="wp-block-image"><img src="__SRC__/wp-content/uploads/2025/11/a.png" alt="" class="wp-image-894"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->',
		'has'  => array( 'class="gallery cropped"', 'data-columns="3"' ),
		'lacks' => array( 'columns-default' ),
	),
	'quote' => array(
		'<!-- wp:quote {"className":"is-style-default"} --><blockquote class="wp-block-quote is-style-default"><!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph --><cite>Someone</cite></blockquote><!-- /wp:quote -->',
		'has'  => array( '<blockquote class="quote"><p>Words.</p><cite>Someone</cite></blockquote>' ),
		'lacks' => array( 'is-layout', 'wp-block' ),
	),
	'pullquote aligned right' => array(
		'<!-- wp:pullquote {"align":"right","className":"is-style-default"} --><figure class="wp-block-pullquote alignright is-style-default"><blockquote><p>Pulled.</p><cite>Me</cite></blockquote></figure><!-- /wp:pullquote -->',
		'has'  => array( '<figure class="pullquote align-right"><blockquote><p>Pulled.</p><cite>Me</cite></blockquote></figure>' ),
		'lacks' => array( 'is-style' ),
	),
	'code keeps lang class and whitespace' => array(
		"<!-- wp:code {\"className\":\"lang-bash\"} --><pre class=\"wp-block-code lang-bash\"><code>cd /var/www\n\tls -la &lt;dir&gt; # __SRC__/wp-content/</code></pre><!-- /wp:code -->",
		'has'  => array( "<pre class=\"code lang-bash\"><code>cd /var/www\n\tls -la &lt;dir&gt; # __SRC__/wp-content/</code></pre>" ),
		'lacks' => array( 'wp-block-code' ),
	),
	'list (no post uses it yet)' => array(
		'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One</li><!-- /wp:list-item --><!-- wp:list-item --><li>Two</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
		'has'  => array( '<ul><li>One</li><li>Two</li></ul>' ),
		'lacks' => array( 'wp-block-list' ),
	),
	'columns and column' => array(
		'<!-- wp:columns --><div class="wp-block-columns is-layout-flex wp-container-core-columns-is-layout-28f84493 wp-block-columns-is-layout-flex"><!-- wp:column {"width":"66.66%"} --><div class="wp-block-column is-layout-flow wp-block-column-is-layout-flow" style="flex-basis:66.66%"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column is-layout-flow wp-block-column-is-layout-flow"><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->',
		'has'  => array( '<div class="columns">', '<div class="column" style="flex-basis:66.66%"><p>A</p></div>', '<div class="column"><p>B</p></div>' ),
		'lacks' => array( 'is-layout', 'wp-container', 'wp-block' ),
	),
	'embed (youtube wrapper classes)' => array(
		"<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v=dQw4w9WgXcQ\",\"type\":\"video\",\"providerNameSlug\":\"youtube\",\"responsive\":true,\"className\":\"wp-embed-aspect-16-9 wp-has-aspect-ratio\"} --><figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n</div></figure><!-- /wp:embed -->",
		'has'  => array( '<figure class="embed embed-youtube aspect-16-9"><div class="embed-frame">' ),
		'lacks' => array( 'is-type', 'is-provider', 'wp-' ),
	),
	'synced single-paragraph block becomes p.notice (wp_block 116)' => array(
		'<!-- wp:block {"ref":116} /-->',
		'has'  => array( '<p class="notice">' ),
		'lacks' => array( 'wp-block' ),
	),
	'registered shortcode passes' => array(
		'<!-- wp:shortcode -->[bds_test_shortcode]<!-- /wp:shortcode -->',
		'has'  => array( 'shortcode-output' ),
		'lacks' => array( '[bds_test_shortcode]' ),
	),
	'theme font-size preset maps to text-<slug>' => array(
		'<!-- wp:paragraph {"fontSize":"body"} --><p class="has-body-font-size">Sized.</p><!-- /wp:paragraph -->',
		'has'   => array( '<p class="text-body">Sized.</p>' ),
		'lacks' => array( 'has-body-font-size' ),
	),
	'font-size preset the theme does not register is left for the validator' => array(
		'<!-- wp:paragraph {"fontSize":"x-large"} --><p class="has-x-large-font-size">Sized.</p><!-- /wp:paragraph -->',
		'has'   => array( 'has-x-large-font-size' ),
	),
	'unregistered shortcode fails' => array(
		'<!-- wp:shortcode -->[bds_no_such_shortcode]<!-- /wp:shortcode -->',
		'fail' => 'unregistered shortcode [bds_no_such_shortcode]',
	),
	'unsupported block fails' => array(
		'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
		'fail' => 'unsupported block core/buttons',
	),
	'unsupported block style fails' => array(
		'<!-- wp:image {"className":"is-style-rounded"} --><figure class="wp-block-image is-style-rounded"><img src="__SRC__/wp-content/uploads/2025/11/a.png" alt=""/></figure><!-- /wp:image -->',
		'fail' => 'unsupported block style is-style-rounded on core/image',
	),
	'core\'s More split (anchor and read-more link) is not classic content' => array(
		"<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n\n<span id=\"more-418\"></span>\n\n<p class=\"read-more\"><a class=\"more-link\" href=\"__SRC__/x/\">Continue reading</a></p>",
		'has'  => array( '<p>A</p>', 'class="read-more"' ),
	),
	'classic content outside blocks fails' => array(
		'<p>Hand-written HTML with no block comments.</p>',
		'fail' => 'classic (freeform) content outside blocks',
	),
);

add_shortcode( 'bds_test_shortcode', fn() => '<span>shortcode-output</span>' );

$pass = 0;
$bad  = 0;
$src  = Config::source();
foreach ( $fixtures as $label => $f ) {
	$f = array_map( fn( $v ) => is_array( $v ) ? str_replace( '__SRC__', $src, $v ) : str_replace( '__SRC__', $src, (string) $v ), $f );
	Blocks::$failures = array();
	// The parts of the the_content chain that matter here, in core's order:
	// blocks, shortcodes, core's content tags (width/height, srcset, loading;
	// they need wp-image-N), then the plugin's late wp-image-N removal.
	$html   = BigDinosaur\Static\Tidy::html( Blocks::drop_image_ids( wp_filter_content_tags( do_shortcode( do_blocks( $f[0] ) ) ) ) );
	$errors = array();
	if ( isset( $f['fail'] ) ) {
		$hit = array_filter( Blocks::$failures, fn( $x ) => str_starts_with( $x, $f['fail'] ) );
		if ( ! $hit ) {
			$errors[] = "expected failure '{$f['fail']}', got: " . ( Blocks::$failures ? implode( '; ', Blocks::$failures ) : 'none' );
		}
	} else {
		if ( Blocks::$failures ) {
			$errors[] = 'unexpected failures: ' . implode( '; ', Blocks::$failures );
		}
		foreach ( $f['has'] ?? array() as $s ) {
			if ( ! str_contains( $html, $s ) ) {
				$errors[] = "missing: $s";
			}
		}
		foreach ( $f['lacks'] ?? array() as $s ) {
			if ( str_contains( $html, $s ) ) {
				$errors[] = "should not contain: $s";
			}
		}
	}
	if ( $errors ) {
		++$bad;
		WP_CLI::line( "FAIL  $label" );
		foreach ( $errors as $e ) {
			WP_CLI::line( "      $e" );
		}
		WP_CLI::line( '      output: ' . trim( $html ) );
	} else {
		++$pass;
		WP_CLI::line( "ok    $label" );
	}
}
WP_CLI::line( "$pass passed, $bad failed" );
if ( $bad ) {
	WP_CLI::halt( 1 );
}
