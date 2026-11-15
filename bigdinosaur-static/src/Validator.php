<?php
/**
 * The output contract. Every check works on the finished document with
 * Dom\HTMLDocument; nothing here edits output. One failure blocks
 * publication. Notes are not failures: they are the build report's coverage
 * list (external media, internal links with no route in this build).
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Validator {

	/** Classes the output may carry besides those the compiled site.css styles. */
	const EXTRA_CLASSES = array(
		'post', 'page', 'menu', 'menu-item', 'more-link', 'footer-copy', 'post-nav-older',
		'posts-nav-newer', 'resized', 'notice', 'embed-youtube', 'blocks-gallery-caption',
	);

	/** Patterns for open-ended vocabulary (Prism languages, embed aspect ratios). */
	const CLASS_PATTERNS = array( '/^lang-[a-z0-9]+(-[a-z0-9]+)*$/', '/^aspect-\d+-\d+$/' );

	/** Class prefixes that betray WordPress markup. */
	const BANNED_CLASS = '/^(wp-|has-|is-|size-|columns-|postid-)/';

	/** Attribute URLs that must never appear on own-site URLs. */
	const BANNED_PATH = '#^/(wp-content|wp-includes|wp-json|wp-admin)/|^/wp-[a-z-]+\.php|[?&]p=\d#';

	/** Head elements: signature => [min, max]. Anything else in the head fails. */
	const HEAD = array(
		'meta charset'                       => array( 1, 1 ),
		'meta name=viewport'                 => array( 1, 1 ),
		'meta name=generator'                => array( 1, 1 ),
		'script inline'                      => array( 1, 1 ),
		'title'                              => array( 1, 1 ),
		'meta name=description'              => array( 0, 1 ),
		'meta name=robots'                   => array( 1, 1 ),
		'meta name=color-scheme'             => array( 1, 1 ),
		'meta name=theme-color'              => array( 2, 2 ),
		'link rel=canonical'                 => array( 1, 1 ),
		'link rel=alternate'                 => array( 1, 1 ),
		'link rel=icon'                      => array( 2, 2 ),
		'link rel=apple-touch-icon'          => array( 1, 1 ),
		'meta property=og:type'              => array( 1, 1 ),
		'meta property=og:site_name'         => array( 1, 1 ),
		'meta property=og:locale'            => array( 1, 1 ),
		'meta property=og:title'             => array( 1, 1 ),
		'meta property=og:description'       => array( 0, 1 ),
		'meta property=og:url'               => array( 1, 1 ),
		'meta property=og:image'             => array( 1, 1 ),
		'meta property=og:image:width'       => array( 0, 1 ),
		'meta property=og:image:height'      => array( 0, 1 ),
		'meta property=og:image:alt'         => array( 0, 1 ),
		'meta name=twitter:card'             => array( 1, 1 ),
		'meta name=twitter:title'            => array( 1, 1 ),
		'meta name=twitter:description'      => array( 0, 1 ),
		'meta name=twitter:image'            => array( 1, 1 ),
		'meta name=twitter:image:alt'        => array( 0, 1 ),
		'link rel=preload'                   => array( 4, 4 ),
		'link rel=stylesheet'                => array( 1, 2 ),
	);

	/** Head elements on posts only. */
	const POST_HEAD = array(
		'meta property=article:published_time' => array( 1, 1 ),
		'meta property=article:modified_time'  => array( 0, 1 ),
		'meta property=article:author'         => array( 0, 1 ),
		'meta property=article:section'        => array( 0, 1 ),
		'meta property=article:tag'            => array( 0, PHP_INT_MAX ),
	);

	/** The only namespaces the feed may declare. */
	const FEED_NS = array(
		'content' => 'http://purl.org/rss/1.0/modules/content/',
		'dc'      => 'http://purl.org/dc/elements/1.1/',
		'atom'    => 'http://www.w3.org/2005/Atom',
	);

	private array $failures = array();
	private array $notes    = array();

	/** @var callable(string $path, string $query): bool|null */
	private $resolve;

	private static ?array $allowed = null;

	/**
	 * @param callable|null $resolve fn( path, query ): bool for root-relative
	 *        URLs (no fragment). Null skips resolvability.
	 */
	private function __construct( ?callable $resolve ) {
		$this->resolve = $resolve;
	}

	/**
	 * Validate one HTML page. $kind: home|list|post|page (a WordPress-rendered
	 * route) or 404 (the plugin's static error page, prod's own document with
	 * its own inline style, checked for URLs, classes prefixes and resolvability only).
	 *
	 * @return array{failures:string[],notes:string[]}
	 */
	public static function html( string $html, string $kind, bool $code = false, ?callable $resolve = null ): array {
		$v = new self( $resolve );
		$v->check_html( $html, $kind, $code );
		return $v->result();
	}

	/**
	 * Validate the feed. $source_guids, when given, must equal the feed's GUIDs
	 * in order (identity is copied verbatim). $resolve checks item URLs (made
	 * root-relative) as for pages.
	 *
	 * @return array{failures:string[],notes:string[]}
	 */
	public static function feed( string $xml, ?array $source_guids = null, ?callable $resolve = null ): array {
		$v = new self( $resolve );
		$v->check_feed( $xml, $source_guids );
		return $v->result();
	}

	/** GUIDs of an RSS document, in order (for the source-feed comparison). */
	public static function feed_guids( string $xml ): array {
		$doc = self::load_xml( $xml );
		$out = array();
		if ( $doc ) {
			foreach ( $doc->getElementsByTagName( 'guid' ) as $g ) {
				$out[] = trim( $g->textContent );
			}
		}
		return $out;
	}

	private function result(): array {
		return array( 'failures' => array_values( array_unique( $this->failures ) ), 'notes' => array_values( array_unique( $this->notes ) ) );
	}

	private function fail( string $what ): void {
		$this->failures[] = $what;
	}

	private function check_html( string $html, string $kind, bool $code ): void {
		if ( ! preg_match( '/^\s*<!doctype html>/i', $html ) ) {
			$this->fail( 'no <!doctype html>' );
		}
		$doc = \Dom\HTMLDocument::createFromString( $html, LIBXML_NOERROR );
		$own = '404' !== $kind;

		if ( $own ) {
			$this->check_head( $doc, $kind, $code );
			$this->check_scripts_and_styles( $doc, $kind, $code );
			$this->check_comments( $doc );
			if ( $doc->body && $doc->body->attributes->length ) {
				$this->fail( '<body> has attributes' );
			}
		}
		foreach ( $doc->querySelectorAll( '*' ) as $el ) {
			$this->check_element( $el, $own, false );
		}
	}

	/** Classes, data-wp-*, attribute URLs: shared by pages and feed content. */
	private function check_element( \Dom\Element $el, bool $allowlist, bool $absolute ): void {
		$tag = $el->localName;
		foreach ( $el->getAttributeNames() as $attr ) {
			if ( str_starts_with( $attr, 'data-wp-' ) ) {
				$this->fail( "<$tag> has $attr" );
			}
		}
		foreach ( $el->classList as $c ) {
			if ( preg_match( self::BANNED_CLASS, $c ) ) {
				$this->fail( "<$tag> has class $c" );
			} elseif ( $allowlist && ! self::class_allowed( $c ) ) {
				$this->fail( "<$tag> has class $c, not in the allowed set" );
			}
		}
		// Core adds width/height (and srcset, loading) to every image it knows;
		// a local image without them means that pass was lost.
		if ( 'img' === $tag && str_contains( (string) $el->getAttribute( 'src' ), '/media/' ) && ( ! $el->hasAttribute( 'width' ) || ! $el->hasAttribute( 'height' ) ) ) {
			$this->fail( '<img> without width/height: ' . $el->getAttribute( 'src' ) );
		}
		if ( 'meta' === $tag ) {
			$content = (string) $el->getAttribute( 'content' );
			if ( Config::source() && str_contains( $content, Config::source() ) ) {
				$this->fail( "<meta> content points at the source origin: $content" );
			}
			if ( preg_match( '#^https?://#', $content ) && ( Urls::is_own( $content ) ) ) {
				$this->check_url( $tag, 'content', $content, false );
			}
			return;
		}
		// Identity links (canonical, feed) stay absolute; so does everything in the feed.
		$absolute = $absolute || ( 'link' === $tag && preg_match( '/\b(canonical|alternate)\b/i', (string) $el->getAttribute( 'rel' ) ) );
		foreach ( Urls::URL_ATTRIBUTES as $attr ) {
			$value = $el->getAttribute( $attr );
			if ( null === $value || '' === $value ) {
				continue;
			}
			$urls = 'srcset' === $attr
				? array_map( fn( $c ) => preg_split( '/\s+/', trim( $c ) )[0], explode( ',', $value ) )
				: array( $value );
			foreach ( $urls as $url ) {
				$this->check_url( $tag, $attr, $url, $absolute );
			}
		}
	}

	/** One attribute URL. */
	private function check_url( string $tag, string $attr, string $url, bool $absolute ): void {
		$source = Config::source();
		if ( '' !== $source && ( $url === $source || preg_match( '#^' . preg_quote( $source, '#' ) . '[/?\#]#', $url ) ) ) {
			$this->fail( "<$tag $attr> points at the source origin: $url" );
			return;
		}
		// On prod the destination origin is itself a public host; own URLs are judged below.
		if ( ! Urls::is_own( $url ) && Urls::is_public( $url ) ) {
			if ( preg_match( '#^https?://[^/]+/(wp-content/uploads/|\?attachment_id=)#', $url ) ) {
				$this->notes[] = "external media (not in the local uploads): $url";
			} elseif ( 'a' === $tag && 'href' === $attr ) {
				$this->fail( "<a href> to the public site should be root-relative: $url" );
			}
			return;
		}
		$path = null;
		if ( Urls::is_own( $url ) ) {
			$path = (string) preg_replace( '#^https://[^/?\#]+#', '', $url );
			$path = '' === $path ? '/' : $path;
			if ( ! $absolute && 'content' !== $attr ) {
				$this->fail( "<$tag $attr> is absolute, expected root-relative: $url" );
			}
		} elseif ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			$path = $url;
			if ( $absolute ) {
				$this->fail( "<$tag $attr> is root-relative, expected absolute: $url" );
			}
		}
		if ( null === $path ) {
			return; // External, fragment-only, mailto: and the like.
		}
		if ( preg_match( self::BANNED_PATH, $path ) ) {
			$this->fail( "<$tag $attr> points at a WordPress path: $url" );
			return;
		}
		if ( $this->resolve ) {
			$bare  = (string) preg_replace( '/#.*$/', '', $path );
			$query = str_contains( $bare, '?' ) ? substr( $bare, strpos( $bare, '?' ) + 1 ) : '';
			$bare  = (string) preg_replace( '/\?.*$/', '', $bare );
			if ( ! ( $this->resolve )( $bare, $query ) ) {
				if ( 'a' === $tag && 'href' === $attr ) {
					$this->notes[] = "link with no route in this build: $url";
				} else {
					$this->fail( "<$tag $attr> does not resolve: $url" );
				}
			}
		}
	}

	private function check_head( \Dom\HTMLDocument $doc, string $kind, bool $code ): void {
		if ( ! $doc->head ) {
			$this->fail( 'no <head>' );
			return;
		}
		$spec   = 'post' === $kind ? self::HEAD + self::POST_HEAD : self::HEAD;
		$counts = array();
		foreach ( $doc->head->children as $el ) {
			$sig = self::signature( $el );
			if ( ! isset( $spec[ $sig ] ) ) {
				$this->fail( "unexpected head element: $sig " . self::outer( $el ) );
				continue;
			}
			$counts[ $sig ] = ( $counts[ $sig ] ?? 0 ) + 1;
		}
		foreach ( $spec as $sig => [ $min, $max ] ) {
			$n = $counts[ $sig ] ?? 0;
			if ( $n < $min || $n > $max ) {
				$this->fail( "head has $n × $sig, expected " . ( $min === $max ? $min : "{$min}–" . ( PHP_INT_MAX === $max ? 'n' : $max ) ) );
			}
		}
		$generator = $doc->head->querySelector( 'meta[name="generator"]' );
		if ( $generator && Render::GENERATOR !== $generator->getAttribute( 'content' ) ) {
			$this->fail( 'generator is "' . $generator->getAttribute( 'content' ) . '", expected "' . Render::GENERATOR . '"' );
		}
		$origin = Config::origin();
		foreach ( array(
			'link[rel="canonical"]'        => 'href',
			'meta[property="og:url"]'      => 'content',
			'meta[property="og:image"]'    => 'content',
			'meta[name="twitter:image"]'   => 'content',
		) as $selector => $attr ) {
			$el = $doc->head->querySelector( $selector );
			$v  = $el ? (string) $el->getAttribute( $attr ) : '';
			if ( $el && ! str_starts_with( $v, $origin . '/' ) && ! Urls::is_public( $v ) ) {
				$this->fail( "$selector is not on the destination origin: $v" );
			}
		}
		$feed = $doc->head->querySelector( 'link[rel="alternate"]' );
		if ( $feed && $origin . '/feed.xml' !== $feed->getAttribute( 'href' ) ) {
			$this->fail( 'feed link is ' . $feed->getAttribute( 'href' ) . ", expected $origin/feed.xml" );
		}
	}

	/**
	 * No HTML comment anywhere on a WordPress-rendered page, after </html>
	 * included (Redis Object Cache's "Performance optimized" comment landed
	 * there on prod, 2026-11-13).
	 */
	private function check_comments( \Dom\Node $node ): void {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \Dom\Comment ) {
				$this->fail( 'HTML comment present: ' . substr( (string) preg_replace( '/\s+/', ' ', trim( $child->data ) ), 0, 100 ) );
			} elseif ( $child->hasChildNodes() ) {
				$this->check_comments( $child );
			}
		}
	}

	/** Exactly the theme's scheme scripts, post-list.js on lists, Prism on code posts. */
	private function check_scripts_and_styles( \Dom\HTMLDocument $doc, string $kind, bool $code ): void {
		if ( $doc->querySelectorAll( 'style' )->length ) {
			$this->fail( $doc->querySelectorAll( 'style' )->length . ' <style> element(s)' );
		}
		$want_js = array();
		if ( in_array( $kind, array( 'home', 'list' ), true ) ) {
			$want_js[] = '/js/post-list.js';
		}
		if ( $code ) {
			$want_js[] = '/js/prism.js';
		}
		$inline = 0;
		$src    = array();
		foreach ( $doc->querySelectorAll( 'script' ) as $s ) {
			$names = $s->getAttributeNames();
			if ( ! $s->hasAttribute( 'src' ) ) {
				++$inline;
				if ( $names || ! str_contains( $s->textContent, "'color-scheme'" ) ) {
					$this->fail( 'unexpected inline script: ' . substr( self::outer( $s ), 0, 100 ) );
				}
				continue;
			}
			$url = (string) $s->getAttribute( 'src' );
			if ( array_diff( $names, array( 'src', 'defer' ) ) || ! preg_match( '#^(/js/[a-z-]+\.js)\?v=[0-9a-f]{12}$#', $url, $m ) ) {
				$this->fail( 'unexpected script: ' . self::outer( $s ) );
				continue;
			}
			$src[] = $m[1];
		}
		if ( 2 !== $inline ) {
			$this->fail( "$inline inline scripts, expected the theme's 2 scheme scripts" );
		}
		if ( $src !== $want_js ) {
			$this->fail( 'scripts are [' . implode( ', ', $src ) . '], expected [' . implode( ', ', $want_js ) . ']' );
		}
		$css = array();
		foreach ( $doc->querySelectorAll( 'link[rel~="stylesheet"]' ) as $l ) {
			$href = (string) $l->getAttribute( 'href' );
			$css[] = preg_match( '#^(/css/(site|prism)\.css)\?v=[0-9a-f]{12}$#', $href, $m ) ? $m[1] : $href;
		}
		$want_css = $code ? array( '/css/site.css', '/css/prism.css' ) : array( '/css/site.css' );
		if ( $css !== $want_css ) {
			$this->fail( 'stylesheets are [' . implode( ', ', $css ) . '], expected [' . implode( ', ', $want_css ) . ']' );
		}
	}

	private function check_feed( string $xml, ?array $source_guids ): void {
		$doc = self::load_xml( $xml );
		if ( ! $doc ) {
			$this->fail( 'feed does not parse as XML' );
			return;
		}
		$rss = $doc->documentElement;
		if ( 'rss' !== $rss->nodeName || '2.0' !== $rss->getAttribute( 'version' ) ) {
			$this->fail( 'feed root is not <rss version="2.0">' );
		}
		$xp = new \DOMXPath( $doc );
		foreach ( $xp->query( 'namespace::*', $rss ) as $ns ) {
			$prefix = $ns->localName;
			if ( 'xml' !== $prefix && ( self::FEED_NS[ $prefix ] ?? null ) !== $ns->nodeValue ) {
				$this->fail( "feed declares namespace $prefix={$ns->nodeValue}" );
			}
		}
		foreach ( $xp->query( '//*' ) as $el ) {
			if ( null !== $el->namespaceURI && ! in_array( $el->namespaceURI, self::FEED_NS, true ) ) {
				$this->fail( "feed element {$el->nodeName} outside the allowed namespaces" );
			}
		}
		if ( $xp->query( '//generator|//comments|//*[local-name()="commentRss"]' )->length ) {
			$this->fail( 'feed has generator or comment elements' );
		}
		$origin = Config::origin();
		$link   = $xp->query( '/rss/channel/link' )->item( 0 );
		if ( ! $link || $origin !== trim( $link->textContent ) ) {
			$this->fail( 'channel <link> is not the destination origin' );
		}
		$xp->registerNamespace( 'atom', self::FEED_NS['atom'] );
		$xp->registerNamespace( 'content', self::FEED_NS['content'] );
		$self = $xp->query( '/rss/channel/atom:link[@rel="self"]' )->item( 0 );
		if ( ! $self || "$origin/feed.xml" !== $self->getAttribute( 'href' ) ) {
			$this->fail( "channel atom:link self is not $origin/feed.xml" );
		}
		$items = $xp->query( '/rss/channel/item' );
		if ( ! $items->length ) {
			$this->fail( 'feed has no items' );
		}
		foreach ( $items as $i => $item ) {
			$l = trim( (string) $xp->query( 'link', $item )->item( 0 )?->textContent );
			if ( ! str_starts_with( $l, $origin . '/' ) ) {
				$this->fail( "item $i <link> is not on the destination origin: $l" );
			}
			$content = $xp->query( 'content:encoded', $item )->item( 0 );
			if ( $content ) {
				$html = \Dom\HTMLDocument::createFromString( '<!doctype html><body>' . $content->textContent, LIBXML_NOERROR );
				foreach ( $html->body->querySelectorAll( '*' ) as $el ) {
					$this->check_element( $el, true, true );
				}
			}
		}
		if ( null !== $source_guids && self::feed_guids( $xml ) !== $source_guids ) {
			$this->fail( 'feed GUIDs differ from the source feed' );
		}
	}

	private static function load_xml( string $xml ): ?\DOMDocument {
		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		$ok   = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $doc : null;
	}

	/** "meta name=robots", "link rel=icon", "script inline", "title", ... */
	private static function signature( \Dom\Element $el ): string {
		$tag = $el->localName;
		return match ( true ) {
			'meta' === $tag && $el->hasAttribute( 'charset' )  => 'meta charset',
			'meta' === $tag && $el->hasAttribute( 'property' ) => 'meta property=' . $el->getAttribute( 'property' ),
			'meta' === $tag                                    => 'meta name=' . $el->getAttribute( 'name' ),
			'link' === $tag                                    => 'link rel=' . strtolower( trim( (string) $el->getAttribute( 'rel' ) ) ),
			'script' === $tag                                  => $el->hasAttribute( 'src' ) ? 'script src' : ( $el->hasAttribute( 'type' ) ? 'script type=' . $el->getAttribute( 'type' ) : 'script inline' ),
			default                                            => $tag,
		};
	}

	private static function outer( \Dom\Element $el ): string {
		return substr( (string) preg_replace( '/\s+/', ' ', $el->outerHTML ), 0, 160 );
	}

	private static function class_allowed( string $c ): bool {
		if ( null === self::$allowed ) {
			preg_match_all( '/\.(-?[_a-zA-Z][\w-]*)/', Assets::bytes( '/css/site.css' ), $m );
			self::$allowed = array_fill_keys( array_merge( $m[1], self::EXTRA_CLASSES ), true );
		}
		if ( isset( self::$allowed[ $c ] ) ) {
			return true;
		}
		foreach ( self::CLASS_PATTERNS as $re ) {
			if ( preg_match( $re, $c ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolver against a built slot directory: a path ending in / needs its
	 * index.html, anything else the file itself; hashed assets only with the
	 * ?v= of the bytes actually written, everything else with no query.
	 */
	public static function dir_resolver( string $dir ): callable {
		$hashes = array();
		$real   = realpath( $dir );
		return function ( string $path, string $query ) use ( $dir, $real, &$hashes ): bool {
			try {
				$file = Slots::join( $dir, str_ends_with( $path, '/' ) ? $path . 'index.html' : $path );
			} catch ( \InvalidArgumentException $e ) {
				return false;
			}
			if ( ! is_file( $file ) || is_link( $file ) || ! str_starts_with( (string) realpath( $file ), $real . '/' ) ) {
				return false; // Missing, or reached through a symlink.
			}
			$path = rawurldecode( $path );
			if ( isset( Assets::HASHED[ $path ] ) ) {
				$hashes[ $path ] ??= substr( (string) hash_file( 'sha256', $file ), 0, 12 );
				return 'v=' . $hashes[ $path ] === $query;
			}
			return '' === $query;
		};
	}
}
