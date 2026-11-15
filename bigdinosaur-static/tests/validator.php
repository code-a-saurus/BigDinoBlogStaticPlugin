<?php
/**
 * Validator cases: live build-context output must pass, and each seeded
 * defect must fail with the expected message (or be a note, not a failure).
 * Fetches from the source origin over HTTP; writes nothing. Run like
 * tests/blocks.php.
 *
 * @package bigdinosaur-static
 */

use BigDinosaur\Static\Assets;
use BigDinosaur\Static\Config;
use BigDinosaur\Static\Fetcher;
use BigDinosaur\Static\Manifest;
use BigDinosaur\Static\Validator;

if ( ! class_exists( Validator::class ) ) {
	WP_CLI::error( 'bigdinosaur-static is not active' );
}

$routes = Manifest::routes();

// Resolver against the live site (no candidate directory exists here):
// manifest routes, shipped assets (hashed ones only with their current ?v=),
// local media, robots.txt and sitemap.xml.
$assets  = Assets::all();
$resolve = function ( string $path, string $query ) use ( $routes, $assets ): bool {
	$path = rawurldecode( $path );
	if ( isset( $routes[ $path ] ) || in_array( $path, array( '/robots.txt', '/sitemap.xml' ), true ) ) {
		return '' === $query;
	}
	if ( isset( $assets[ $path ] ) ) {
		return isset( Assets::HASHED[ $path ] ) ? 'v=' . Assets::hash( $path ) === $query : '' === $query;
	}
	return str_starts_with( $path, '/media/' ) && '' === $query && BigDinosaur\Static\Urls::local_media_exists( $path );
};
$get     = function ( string $static ) use ( $routes ) {
	$r = Fetcher::get( $routes[ $static ]['source'] );
	if ( 200 !== $r['status'] || '' !== $r['error'] ) {
		WP_CLI::error( "fetch $static: {$r['status']} {$r['error']}" );
	}
	return $r['body'];
};
$code_post = '/vibe-coding-my-way-to-wp-dark-mode/';
if ( ! $routes[ $code_post ]['code'] ) {
	WP_CLI::error( "$code_post is no longer tagged code; pick another fixture" );
}
$post = $get( $code_post );
$list = $get( '/tags/code/' );
$feed = $get( '/feed.xml' );
$src  = Config::source();
$dst  = Config::origin();

$in_head = fn( $html, $add ) => str_replace( '</head>', "$add\n</head>", $html );
$in_main = fn( $html, $add ) => preg_replace( '/(<main\b[^>]*>)/', "\$1$add", $html, 1 );

// name => [ kind, code, html, expected failure substring or null (must pass), expected note substring ]
$cases = array(
	'live code post passes'                 => array( 'post', true, $post, null ),
	'live list passes'                      => array( 'list', false, $list, null ),
	'text, <code> and <pre> are not checked' => array( 'post', true, $in_main( $post, "<p>$src/wp-content/x <code>/wp-admin/ ?p=3</code></p><pre>$src/feed/</pre>" ), null ),
	'<style> element'                       => array( 'post', true, $in_head( $post, '<style>p{}</style>' ), '<style> element' ),
	'extra stylesheet'                      => array( 'post', true, $in_head( $post, '<link rel="stylesheet" href="/css/other.css">' ), 'stylesheets are' ),
	'WordPress stylesheet'                  => array( 'post', true, $in_head( $post, '<link rel="stylesheet" href="/wp-includes/css/dist/block-library/style.min.css">' ), 'points at a WordPress path' ),
	'second generator meta'                 => array( 'post', true, $in_head( $post, '<meta name="generator" content="WordPress 7.1.4">' ), '2 × meta name=generator' ),
	'missing generator meta'                => array( 'post', true, preg_replace( '/<meta name="generator"[^>]*>\n?/', '', $post ), '0 × meta name=generator' ),
	'wrong generator'                       => array( 'post', true, str_replace( '<meta name="generator" content="Wordpress Static">', '<meta name="generator" content="WordPress 7.1.4">', $post ), 'generator is' ),
	'api.w.org link'                        => array( 'post', true, $in_head( $post, "<link rel=\"https://api.w.org/\" href=\"$dst/wp-json/\">" ), 'unexpected head element' ),
	'comments feed link'                    => array( 'post', true, $in_head( $post, "<link rel=\"alternate\" type=\"application/rss+xml\" href=\"$dst/comments/feed/\">" ), '2 × link rel=alternate' ),
	'speculation rules'                     => array( 'post', true, $in_head( $post, '<script type="speculationrules">{}</script>' ), 'unexpected head element: script type=speculationrules' ),
	'importmap'                             => array( 'list', false, str_replace( '</body>', '<script type="importmap">{}</script></body>', $list ), 'unexpected inline script' ),
	'missing canonical'                     => array( 'post', true, preg_replace( '/<link rel="canonical"[^>]*>/', '', $post ), '0 × link rel=canonical' ),
	'canonical on the source origin'        => array( 'post', true, str_replace( "rel=\"canonical\" href=\"$dst", "rel=\"canonical\" href=\"$src", $post ), 'source origin' ),
	'code post without Prism'               => array( 'post', true, preg_replace( '#<script[^>]*/js/prism\.js[^>]*></script>#', '', $post ), 'scripts are' ),
	'Prism on a post not tagged code'       => array( 'post', false, $post, 'scripts are' ),
	'list without post-list.js'             => array( 'list', false, preg_replace( '#<script[^>]*/js/post-list\.js[^>]*></script>#', '', $list ), 'scripts are' ),
	'body class'                            => array( 'post', true, str_replace( '<body>', '<body class="home blog">', $post ), '<body> has attributes' ),
	'wp- class'                             => array( 'post', true, $in_main( $post, '<figure class="wp-block-image">x</figure>' ), 'class wp-block-image' ),
	'has- class'                            => array( 'post', true, $in_main( $post, '<p class="has-drop-cap">x</p>' ), 'class has-drop-cap' ),
	'unknown class'                         => array( 'post', true, $in_main( $post, '<p class="sparkly">x</p>' ), 'class sparkly, not in the allowed set' ),
	'data-wp attribute'                     => array( 'post', true, $in_main( $post, '<div data-wp-interactive="core/image">x</div>' ), 'data-wp-interactive' ),
	'link to the source origin'             => array( 'post', true, $in_main( $post, "<a href=\"$src/about/\">x</a>" ), 'source origin' ),
	'absolute own link'                     => array( 'post', true, $in_main( $post, "<a href=\"$dst/about/\">x</a>" ), 'expected root-relative' ),
	'uploads path'                          => array( 'post', true, $in_main( $post, '<img src="/wp-content/uploads/2025/11/a.png" alt="">' ), 'points at a WordPress path' ),
	'query permalink'                       => array( 'post', true, $in_main( $post, '<a href="/?p=885">x</a>' ), 'points at a WordPress path' ),
	'public-site link left absolute'        => array( 'post', true, $in_main( $post, '<a href="https://bigdinosaur.org/about/">x</a>' ), 'should be root-relative' ),
	'missing media'                         => array( 'post', true, $in_main( $post, '<img src="/media/2025/11/nope.png" alt="" width="1" height="1">' ), 'does not resolve' ),
	'local image without dimensions'        => array( 'post', true, $in_main( $post, '<img src="/img/card.png" alt=""><img src="/media/2025/11/Screenshot-2025-11-11-at-11.53.26.png" alt="">' ), '<img> without width/height' ),
	'missing srcset candidate'              => array( 'post', true, $in_main( $post, '<img src="/img/card.png" srcset="/img/card.png 1200w, /media/nope-300.png 300w" alt="" width="1" height="1">' ), 'does not resolve' ),
	'stale asset hash'                      => array( 'post', true, preg_replace( '#/css/site\.css\?v=[0-9a-f]{12}#', '/css/site.css?v=000000000000', $post ), 'does not resolve' ),
	'Redis Object Cache comment'            => array( 'post', true, str_replace( '</html>', "</html>\n<!--\nPerformance optimized by Redis Object Cache. Learn more: https://wprediscache.com\n-->\n", $post ), 'HTML comment present' ),
	'comment inside the body'               => array( 'post', true, $in_main( $post, '<!-- x -->' ), 'HTML comment present' ),
	'link with no route is a note'          => array( 'post', true, $in_main( $post, '<a href="/no-such-post/">x</a>' ), null, 'no route in this build: /no-such-post/' ),
	'external public media is a note'       => array( 'post', true, $in_main( $post, '<img src="https://bigdinosaur.org/wp-content/uploads/2021/02/x.jpg" alt="">' ), null, 'external media' ),
	'static 404 page passes'                => array( '404', false, (string) file_get_contents( BIGDINOSAUR_STATIC_DIR . '/' . Assets::OWN['/404.html'] ), null ),
);

$bad = 0;
$run = function ( string $name, array $r, ?string $want_fail, ?string $want_note ) use ( &$bad ) {
	$fails = implode( "\n", $r['failures'] );
	$notes = implode( "\n", $r['notes'] );
	$ok    = null === $want_fail ? '' === $fails : str_contains( $fails, $want_fail );
	$ok    = $ok && ( null === $want_note || str_contains( $notes, $want_note ) );
	if ( ! $ok ) {
		++$bad;
		WP_CLI::line( "FAIL  $name\n      want failure: " . var_export( $want_fail, true ) . ( $want_note ? "  note: $want_note" : '' ) . "\n      got:\n        " . str_replace( "\n", "\n        ", $fails ?: '(no failures)' ) );
	} else {
		WP_CLI::line( "ok    $name" );
	}
};
foreach ( $cases as $name => $c ) {
	$run( $name, Validator::html( $c[2], $c[0], $c[1], $resolve ), $c[3], $c[4] ?? null );
}

$guids = Validator::feed_guids( Fetcher::get( '/feed/', false )['body'] );
foreach ( array(
	'live feed passes, GUIDs equal the source feed' => array( $feed, $guids, null ),
	'feed that is not XML'                          => array( str_replace( '</channel>', '', $feed ), $guids, 'does not parse' ),
	'generator element'                             => array( str_replace( '<language>', '<generator>x</generator><language>', $feed ), $guids, 'generator' ),
	'extra namespace'                               => array( str_replace( '<rss version="2.0"', '<rss version="2.0" xmlns:wfw="http://wellformedweb.org/CommentAPI/"', $feed ), $guids, 'namespace wfw' ),
	'changed GUID'                                  => array( preg_replace( '#(<guid[^>]*>)[^<]+#', '$1https://example.com/x', $feed, 1 ), $guids, 'GUIDs differ' ),
	'root-relative URL in item content'             => array( preg_replace( '#<content:encoded><!\[CDATA\[#', '$0<a href="/about/">x</a>', $feed, 1 ), $guids, 'expected absolute' ),
	'wp- class in item content'                     => array( preg_replace( '#<content:encoded><!\[CDATA\[#', '$0<p class="wp-block-paragraph">x</p>', $feed, 1 ), $guids, 'class wp-block-paragraph' ),
	'source-origin link in item content'            => array( preg_replace( '#<content:encoded><!\[CDATA\[#', "\$0<a href=\"$src/about/\">x</a>", $feed, 1 ), $guids, 'source origin' ),
	'channel link on the source origin'             => array( preg_replace( '#<link>[^<]+</link>#', "<link>$src</link>", $feed, 1 ), $guids, 'channel <link>' ),
) as $name => [ $xml, $g, $want ] ) {
	$run( "feed: $name", Validator::feed( $xml, $g ), $want, null );
}

WP_CLI::line( ( count( $cases ) + 9 - $bad ) . " passed, $bad failed" );
if ( $bad ) {
	WP_CLI::halt( 1 );
}
