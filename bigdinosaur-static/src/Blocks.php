<?php
/**
 * Block output in the site vocabulary: WordPress block classes mapped to the
 * short names assets/blocks.css and the theme style, WordPress-only
 * attributes dropped.
 *
 * Each supported block's own render_block_{name} filter runs last and edits
 * only tag attributes of that block's output. Inner blocks were already
 * cleaned by their own filters; the map is idempotent, so re-reading them
 * from the parent changes nothing. An unsupported block type, block style or
 * unregistered shortcode is recorded as a failure, which fails the page.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	/** Supported block types. */
	const SUPPORTED = array(
		'core/paragraph', 'core/heading', 'core/image', 'core/gallery', 'core/quote',
		'core/pullquote', 'core/code', 'core/list', 'core/list-item', 'core/columns',
		'core/column', 'core/embed', 'core/more', 'core/block', 'core/shortcode',
	);

	/** WordPress class => site class, or null to drop. */
	const CLASS_MAP = array(
		'wp-block-paragraph'              => null,
		'has-drop-cap'                    => 'drop-cap',
		'wp-block-heading'                => null,
		'wp-block-image'                  => 'image',
		'size-large'                      => null,
		'size-full'                       => null,
		'size-medium'                     => null,
		'size-thumbnail'                  => null,
		'is-resized'                      => 'resized',
		'is-style-default'                => null,
		'wp-element-caption'              => 'caption',
		'alignleft'                       => 'align-left',
		'alignright'                      => 'align-right',
		'aligncenter'                     => 'align-center',
		'alignwide'                       => 'align-wide',
		'wp-block-gallery'                => 'gallery',
		'has-nested-images'               => null,
		'is-cropped'                      => 'cropped',
		'is-layout-flex'                  => null,
		'is-layout-flow'                  => null,
		'is-layout-constrained'           => null,
		'wp-block-gallery-is-layout-flex' => null,
		'columns-default'                 => null,
		'wp-block-code'                   => 'code',
		'wp-block-pullquote'              => 'pullquote',
		'wp-block-quote'                  => 'quote',
		'wp-block-quote-is-layout-flow'   => null,
		'wp-block-list'                   => null,
		'wp-block-columns'                => 'columns',
		'wp-block-columns-is-layout-flex' => null,
		'wp-block-column'                 => 'column',
		'wp-block-column-is-layout-flow'  => null,
		'wp-block-embed'                  => 'embed',
		'is-type-video'                   => null,
		'is-type-rich'                    => null,
		'is-provider-youtube'             => null,
		'wp-block-embed-youtube'          => 'embed-youtube',
		'wp-embed-aspect-16-9'            => 'aspect-16-9',
		'wp-embed-aspect-4-3'             => 'aspect-4-3',
		'wp-has-aspect-ratio'             => null,
		'wp-block-embed__wrapper'         => 'embed-frame',
		'wp-lightbox-container'           => null,
	);

	/** WordPress-only attributes dropped outright (plus every data-wp-*). */
	const DROP_ATTRS = array( 'data-id', 'data-link', 'data-type' );

	/** Failures for this request: "feature (context)" strings. */
	public static array $failures = array();

	public static function hook(): void {
		foreach ( self::SUPPORTED as $name ) {
			add_filter( "render_block_$name", array( self::class, 'clean' ), PHP_INT_MAX, 2 );
		}
		add_filter( 'render_block', array( self::class, 'check' ), PHP_INT_MAX, 2 );
		// Lightboxes are dropped by design: never let core add them.
		add_filter(
			'render_block_data',
			function ( $block ) {
				if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
					$block['attrs']['lightbox'] = array( 'enabled' => false );
				}
				return $block;
			},
			PHP_INT_MAX
		);
		// The <span id="more-N"></span> anchor core leaves where a More block sits.
		add_filter( 'the_content', fn( $html ) => (string) preg_replace( '#<span id="more-\d+"></span>#', '', (string) $html ), PHP_INT_MAX );
		// wp-image-N stays through block rendering because core's
		// wp_filter_content_tags (the_content, priority 12) needs it to add
		// width/height, srcset/sizes and loading; it goes after that.
		add_filter( 'the_content', array( self::class, 'drop_image_ids' ), PHP_INT_MAX );
	}

	/** Record unsupported block types and block styles. */
	public static function check( $content, $block ) {
		$name = $block['blockName'] ?? null;
		if ( null === $name ) {
			// Core splits content at <!--more-->, leaving its own anchor (single
			// view) or the theme's read-more link (lists) between blocks.
			$rest = preg_replace(
				array( '#<span id="more-\d+"></span>#', '#<p class="read-more"><a class="more-link" href="[^"<>]*">[^<]*</a></p>#' ),
				'',
				(string) $content
			);
			if ( '' !== trim( (string) $rest ) ) {
				self::fail( 'classic (freeform) content outside blocks' );
			}
			return $content;
		}
		if ( ! in_array( $name, self::SUPPORTED, true ) ) {
			self::fail( "unsupported block $name" );
			return $content;
		}
		$classes = preg_split( '/\s+/', (string) ( $block['attrs']['className'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $classes as $c ) {
			if ( str_starts_with( $c, 'is-style-' ) && 'is-style-default' !== $c ) {
				self::fail( "unsupported block style $c on $name" );
			}
		}
		return $content;
	}

	/** Clean one supported block's output. */
	public static function clean( $content, $block ) {
		$content = (string) $content;
		$name    = $block['blockName'];

		if ( 'core/shortcode' === $name ) {
			// Registered shortcodes have rendered by now; any [tag] left whose
			// name is not registered failed to render.
			if ( preg_match_all( '/\[([a-zA-Z0-9_-]+)[^\]]*\]/', (string) ( $block['innerHTML'] ?? '' ), $m ) ) {
				foreach ( $m[1] as $tag ) {
					if ( ! shortcode_exists( $tag ) ) {
						self::fail( "unregistered shortcode [$tag]" );
					}
				}
			}
			return $content;
		}
		if ( 'core/more' === $name ) {
			return $content; // The theme's read-more/more-link output.
		}

		$p = new \WP_HTML_Tag_Processor( $content );
		while ( $p->next_tag() ) {
			self::clean_tag( $p, $name );
		}
		$content = $p->get_updated_html();

		if ( 'core/block' === $name ) {
			$content = self::mark_notice( $content, $block );
		}
		return $content;
	}

	/** Map classes and drop WordPress-only attributes on the current tag. */
	private static function clean_tag( \WP_HTML_Tag_Processor $p, string $block_name ): void {
		$names = $p->get_attribute_names_with_prefix( '' ) ?? array();
		foreach ( $names as $attr ) {
			if ( str_starts_with( $attr, 'data-wp-' ) || in_array( $attr, self::DROP_ATTRS, true ) ) {
				$p->remove_attribute( $attr );
			}
		}
		$class = $p->get_attribute( 'class' );
		if ( ! is_string( $class ) ) {
			return;
		}
		$out     = array();
		$columns = null;
		foreach ( preg_split( '/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY ) as $c ) {
			if ( array_key_exists( $c, self::CLASS_MAP ) ) {
				if ( 'columns-default' === $c ) {
					$columns = '3'; // A gallery with no explicit column count lays out as three.
				}
				if ( null !== self::CLASS_MAP[ $c ] ) {
					$out[] = self::CLASS_MAP[ $c ];
				}
			} elseif ( preg_match( '/^(wp-block-gallery-\d+|wp-container-[a-z0-9-]+|wp-elements-[0-9a-f]+)$/', $c ) ) {
				continue;
			} elseif ( preg_match( '/^columns-(\d+)$/', $c, $m ) ) {
				$columns = $m[1];
			} elseif ( preg_match( '/^has-([a-z0-9-]+)-font-size$/', $c, $m ) && isset( self::font_sizes()[ $m[1] ] ) ) {
				$out[] = 'text-' . $m[1]; // Theme font-size preset; Assets emits .text-<slug>.
			} else {
				$out[] = $c; // Vocabulary, lang-*, theme classes; the validator judges the rest.
			}
		}
		$out = array_values( array_unique( $out ) );
		if ( $out ) {
			$p->set_attribute( 'class', implode( ' ', $out ) );
		} else {
			$p->remove_attribute( 'class' );
		}
		if ( null !== $columns ) {
			if ( 'FIGURE' !== $p->get_tag() ) {
				self::fail( "columns-N class on <{$p->get_tag()}> in $block_name" );
			}
			$p->set_attribute( 'data-columns', $columns );
		}
	}

	/**
	 * The theme's editor font-size presets, slug => px (functions.php,
	 * editor-font-sizes). Core renders a chosen preset as has-<slug>-font-size
	 * plus a .has-<slug>-font-size { font-size: Npx !important } rule in its
	 * global styles; the static site carries the same intent as text-<slug>
	 * with a matching rule appended to site.css by Assets::site_css(). A
	 * font-size class for a slug the theme does not register is left alone,
	 * so the validator fails the build rather than dropping it silently.
	 */
	public static function font_sizes(): array {
		static $sizes = null;
		if ( null === $sizes ) {
			$sizes = array();
			foreach ( (array) ( get_theme_support( 'editor-font-sizes' )[0] ?? array() ) as $preset ) {
				if ( ! empty( $preset['slug'] ) && isset( $preset['size'] ) && preg_match( '/^[a-z0-9-]+$/', (string) $preset['slug'] ) ) {
					$sizes[ (string) $preset['slug'] ] = (int) $preset['size'];
				}
			}
		}
		return $sizes;
	}

	/**
	 * A synced block whose content is a single paragraph (the "Old post
	 * warning") renders as p.notice.
	 */
	private static function mark_notice( string $content, array $block ): string {
		$ref = (int) ( $block['attrs']['ref'] ?? 0 );
		$src = $ref ? get_post_field( 'post_content', $ref ) : '';
		$top = array_values( array_filter( parse_blocks( (string) $src ), fn( $b ) => null !== $b['blockName'] ) );
		if ( 1 !== count( $top ) || 'core/paragraph' !== $top[0]['blockName'] ) {
			return $content;
		}
		$p = new \WP_HTML_Tag_Processor( $content );
		if ( $p->next_tag( 'p' ) ) {
			$p->add_class( 'notice' );
		}
		return $p->get_updated_html();
	}

	/** Remove wp-image-N classes (attributes only) once core has used them. */
	public static function drop_image_ids( $html ) {
		$html = (string) $html;
		if ( ! str_contains( $html, 'wp-image-' ) ) {
			return $html;
		}
		$p = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag( 'img' ) ) {
			foreach ( preg_split( '/\s+/', (string) $p->get_attribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY ) as $c ) {
				if ( preg_match( '/^wp-image-\d+$/', $c ) ) {
					$p->remove_class( $c );
				}
			}
			if ( '' === trim( (string) $p->get_attribute( 'class' ) ) ) {
				$p->remove_attribute( 'class' );
			}
		}
		return $p->get_updated_html();
	}

	private static function fail( string $what ): void {
		$post = get_post();
		$ctx  = $post ? sprintf( 'post %d %s', $post->ID, $post->post_name ) : 'no post';
		self::$failures[] = "$what ($ctx)";
	}
}
