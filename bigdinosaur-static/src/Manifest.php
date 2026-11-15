<?php
/**
 * Every public route the static site has, from WordPress APIs; and the
 * sitemap built from them.
 *
 * @package bigdinosaur-static
 */

namespace BigDinosaur\Static;

defined( 'ABSPATH' ) || exit;

final class Manifest {

	/**
	 * Static path => array( 'kind' => home|list|post|page|feed, 'source' =>
	 * source path to fetch, 'code' => bool (post tagged code), 'modified' =>
	 * ISO 8601 for posts and pages else '', 'archive' => bool (date archive) ).
	 * Throws when two routes would write the same file.
	 *
	 * @return array<string,array{kind:string,source:string,code:bool,modified:string,archive:bool}>
	 */
	public static function routes(): array {
		global $wpdb;
		$per_page = max( 1, (int) get_option( 'posts_per_page' ) );
		$routes   = array();
		$add      = function ( string $source, string $kind, bool $code = false, string $modified = '', bool $archive = false ) use ( &$routes ) {
			$static = Urls::map_path( $source );
			if ( null === $static || isset( $routes[ $static ] ) ) {
				throw new \RuntimeException( "route collision or unmappable route: $source" );
			}
			$routes[ $static ] = array( 'kind' => $kind, 'source' => $source, 'code' => $code, 'modified' => $modified, 'archive' => $archive );
		};
		// Page 1 at the base path, then /page/N/ for the rest, per posts_per_page.
		$paged = function ( string $base, int $count, string $kind, bool $archive = false ) use ( $add, $per_page ) {
			$add( $base, $kind, false, '', $archive );
			for ( $n = 2, $pages = (int) ceil( $count / $per_page ); $n <= $pages; $n++ ) {
				$add( $base . "page/$n/", $kind );
			}
		};
		$rel = fn( $url ) => (string) wp_make_link_relative( (string) $url );

		$posts = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => -1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
		$count = count( array_filter( $posts, fn( $p ) => 'post' === $p->post_type ) );
		$paged( '/', $count, 'home' );
		foreach ( $posts as $p ) {
			$add( $rel( get_permalink( $p ) ), $p->post_type, 'post' === $p->post_type && has_tag( 'code', $p ), (string) get_post_modified_time( 'c', false, $p ) );
		}
		foreach ( get_terms( array( 'taxonomy' => array( 'category', 'post_tag' ), 'hide_empty' => true ) ) as $term ) {
			$paged( $rel( get_term_link( $term ) ), (int) $term->count, 'list' );
		}
		$dates = $wpdb->get_results( "SELECT YEAR(post_date) y, MONTH(post_date) m, COUNT(*) n FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_password = '' GROUP BY y, m ORDER BY y, m" ); // phpcs:ignore WordPress.DB
		$years = array();
		foreach ( $dates as $d ) {
			$years[ $d->y ] = ( $years[ $d->y ] ?? 0 ) + (int) $d->n;
			$paged( $rel( get_month_link( (int) $d->y, (int) $d->m ) ), (int) $d->n, 'list', true );
		}
		foreach ( $years as $y => $n ) {
			$paged( $rel( get_year_link( (int) $y ) ), $n, 'list', true );
		}
		$add( '/feed/', 'feed' );
		return $routes;
	}

	/**
	 * sitemap.xml: the home page and its pagination, posts and pages with
	 * lastmod, then year and month archives (first pages only). Term archives
	 * are noindex, so they are left out.
	 */
	public static function sitemap( array $routes ): string {
		$origin = Config::origin();
		$groups = array( 'home' => array(), 'single' => array(), 'year' => array(), 'month' => array() );
		foreach ( $routes as $path => $r ) {
			$loc = '<loc>' . esc_xml( $origin . $path ) . '</loc>';
			if ( 'home' === $r['kind'] ) {
				$groups['home'][] = "<url>$loc</url>";
			} elseif ( in_array( $r['kind'], array( 'post', 'page' ), true ) ) {
				$groups['single'][] = "<url>$loc<lastmod>" . esc_xml( $r['modified'] ) . '</lastmod></url>';
			} elseif ( $r['archive'] ) {
				$groups[ preg_match( '#^/\d{4}/$#', $path ) ? 'year' : 'month' ][] = "<url>$loc</url>";
			}
		}
		sort( $groups['year'] );
		sort( $groups['month'] );
		$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $groups as $urls ) {
			foreach ( $urls as $u ) {
				$out .= "\t$u\n";
			}
		}
		return $out . "</urlset>\n";
	}
}
