<?php
/**
 * Slot layer, slot resolver, media collection and sitemap. Works only in a
 * scratch directory under the system temp dir (removed at the end); never
 * touches the real slots or the link. Run like tests/blocks.php.
 *
 * @package bigdinosaur-static
 */

use BigDinosaur\Static\Assets;
use BigDinosaur\Static\Build;
use BigDinosaur\Static\Config;
use BigDinosaur\Static\Manifest;
use BigDinosaur\Static\Purge;
use BigDinosaur\Static\Slots;
use BigDinosaur\Static\SlotWriter;
use BigDinosaur\Static\Validator;

if ( ! class_exists( Build::class ) ) {
	WP_CLI::error( 'bigdinosaur-static is not active' );
}

$bad   = 0;
$count = 0;
$check = function ( string $name, bool $ok, string $detail = '' ) use ( &$bad, &$count ) {
	++$count;
	$bad += $ok ? 0 : 1;
	WP_CLI::line( ( $ok ? 'ok    ' : 'FAIL  ' ) . $name . ( $ok || '' === $detail ? '' : "\n      $detail" ) );
};
$throws = function ( callable $fn ): string {
	try {
		$fn();
	} catch ( \Throwable $e ) {
		return $e->getMessage();
	}
	return '';
};

$tmp = sys_get_temp_dir() . '/bds-slots-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp . '/slot', 0755, true );
mkdir( $tmp . '/outside', 0755 );
file_put_contents( $tmp . '/outside/keep.txt', 'keep' );

try {
	// join(): decoding and unsafe segments.
	$check( 'join decodes like nginx', $tmp . '/slot/a b/index.html' === Slots::join( $tmp . '/slot', '/a%20b/index.html' ) );
	foreach ( array( '/../x', '/a/../../x', '/a/%2e%2e/x', '/a//b', 'x', "/a\0b", '/a\\b', '/./x' ) as $p ) {
		$check( 'join rejects ' . wp_json_encode( $p ), '' !== $throws( fn() => Slots::join( $tmp . '/slot', $p ) ) );
	}
	$check( 'slot names are literals', '' !== $throws( fn() => Slots::dir( '../a' ) ) && '' === $throws( fn() => Slots::dir( 'b' ) ) );

	// SlotWriter: nested dirs, collisions, symlinked parents, hardlinks.
	$w = new SlotWriter( $tmp . '/slot' );
	$w->put( '/index.html', '<!doctype html>home' );
	$w->put( '/tags/code/index.html', 'list' );
	$w->put( '/css/site.css', 'body{}' );
	$check( 'writer creates nested files', is_file( $tmp . '/slot/tags/code/index.html' ) && '0644' === substr( sprintf( '%o', fileperms( $tmp . '/slot/tags/code/index.html' ) ), -4 ) );
	$check( 'writer refuses a second write of the same file', str_contains( $throws( fn() => $w->put( '/tags/code/index.html', 'x' ) ), 'two outputs' ) );
	$check( 'writer refuses the same file spelled encoded', str_contains( $throws( fn() => $w->put( '/tags/%63ode/index.html', 'x' ) ), 'two outputs' ) );
	symlink( $tmp . '/outside', $tmp . '/slot/escape' );
	$check( 'writer refuses to write through a symlinked directory', str_contains( $throws( fn() => $w->put( '/escape/x.html', 'x' ) ), 'symlink' ) && ! file_exists( $tmp . '/outside/x.html' ) );
	$w->copy( '/media/keep.txt', $tmp . '/outside/keep.txt', true );
	$check( 'copy with link hardlinks on one filesystem', fileinode( $tmp . '/slot/media/keep.txt' ) === fileinode( $tmp . '/outside/keep.txt' ) );

	// dir_resolver.
	$resolve = Validator::dir_resolver( $tmp . '/slot' );
	$hash    = substr( hash( 'sha256', 'body{}' ), 0, 12 );
	$check( 'resolver: / and directory routes need index.html', $resolve( '/', '' ) && $resolve( '/tags/code/', '' ) && ! $resolve( '/tags/', '' ) && ! $resolve( '/tags/code', '' ) );
	$check( 'resolver: hashed asset needs the written bytes\' hash', $resolve( '/css/site.css', "v=$hash" ) && ! $resolve( '/css/site.css', 'v=000000000000' ) && ! $resolve( '/css/site.css', '' ) );
	$check( 'resolver: other files take no query', $resolve( '/media/keep.txt', '' ) && ! $resolve( '/media/keep.txt', 'x=1' ) );
	$check( 'resolver: missing and traversal paths fail', ! $resolve( '/nope.html', '' ) && ! $resolve( '/../outside/keep.txt', '' ) && ! $resolve( '/escape/keep.txt', '' ) );

	// empty_dir: removes everything inside, never follows the symlink.
	Slots::empty_dir( $tmp . '/slot' );
	$check( 'empty_dir empties the slot', array() === array_diff( scandir( $tmp . '/slot' ), array( '.', '..' ) ) );
	$check( 'empty_dir leaves symlink targets and hardlink sources alone', is_file( $tmp . '/outside/keep.txt' ) && 'keep' === file_get_contents( $tmp . '/outside/keep.txt' ) );
	symlink( $tmp . '/outside', $tmp . '/link' );
	$check( 'empty_dir refuses a symlink', '' !== $throws( fn() => Slots::empty_dir( $tmp . '/link' ) ) );
	$check( 'empty_dir did not follow it', is_file( $tmp . '/outside/keep.txt' ) );

	// media_refs.
	$o    = Config::origin();
	$html = '<!doctype html><head><meta property="og:image" content="' . $o . '/media/2025/11/a.png"><meta name="description" content="/media/not-a-url"></head>'
		. '<body><img src="/media/2022/07/b-1024x500.jpg?x=1" srcset="/media/2022/07/b-1024x500.jpg 1024w, /media/2022/07/b-300x150.jpg 300w">'
		. '<a href="/media/2022/07/b.jpg#top">full</a><a href="https://bigdinosaur.org/wp-content/uploads/2021/02/ext.jpg">ext</a>'
		. '<p>/media/2020/01/text.png</p><code>/media/2020/01/code.png</code><img src="/img/mascot.png"></body>';
	$refs = Build::media_refs( $html );
	sort( $refs );
	$want = array( '/media/2022/07/b-1024x500.jpg', '/media/2022/07/b-300x150.jpg', '/media/2022/07/b.jpg', '/media/2025/11/a.png' );
	$check( 'media_refs: attributes, srcset, own-origin meta; not prose meta, text, code or external', $want === $refs, wp_json_encode( $refs ) );
	$feed = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><item><content:encoded><![CDATA[<img src="' . $o . '/media/2025/11/c.png">]]></content:encoded></item></channel></rss>';
	$check( 'feed_media_refs reads item content', array( '/media/2025/11/c.png' ) === Build::feed_media_refs( $feed ) );

	// Sitemap: the site's shape, from the live manifest.
	$routes = Manifest::routes();
	$xml    = Manifest::sitemap( $routes );
	$doc    = new DOMDocument();
	$check( 'sitemap parses', (bool) $doc->loadXML( $xml ) );
	$locs = array_map( fn( $l ) => substr( trim( $l->textContent ), strlen( $o ) ), iterator_to_array( $doc->getElementsByTagName( 'loc' ) ) );
	$check( 'sitemap starts with / then /page/2/', array( '/', '/page/2/' ) === array_slice( $locs, 0, 2 ) );
	$check( 'sitemap has every post and page with lastmod', count( array_filter( $routes, fn( $r ) => in_array( $r['kind'], array( 'post', 'page' ), true ) ) ) === $doc->getElementsByTagName( 'lastmod' )->length );
	$check( 'sitemap has year and month archives, no terms, no archive pagination', in_array( '/2022/', $locs, true ) && in_array( '/2022/06/', $locs, true ) && ! array_filter( $locs, fn( $l ) => preg_match( '#^/(tags|categories)/|^/\d{4}/.*page/#', $l ) ) );
	$check( 'manifest flags archives only on first pages', 18 === count( array_filter( $routes, fn( $r ) => $r['archive'] ) ), (string) count( array_filter( $routes, fn( $r ) => $r['archive'] ) ) );

	// robots.txt follows "discourage search engines" (read through a filter, no option write).
	$as = function ( string $value, callable $fn ) {
		$f = fn() => $value;
		add_filter( 'pre_option_blog_public', $f );
		try {
			return $fn();
		} finally {
			remove_filter( 'pre_option_blog_public', $f );
		}
	};
	$check( 'robots: public site allows all and names the sitemap', "User-agent: *\nAllow: /\n\nSitemap: $o/sitemap.xml\n" === $as( '1', fn() => Build::robots() ) );
	$check( 'robots: discouraged site disallows everything', "User-agent: *\nDisallow: /\n" === $as( '0', fn() => Build::robots() ) );

	// Cloudflare purge is off unless both constants are defined (they are not on the LAN).
	$check( 'purge: not configured here', ! Purge::configured() );
} finally {
	Slots::empty_dir( $tmp );
	rmdir( $tmp );
}

WP_CLI::line( ( $count - $bad ) . " passed, $bad failed" );
if ( $bad ) {
	WP_CLI::halt( 1 );
}
