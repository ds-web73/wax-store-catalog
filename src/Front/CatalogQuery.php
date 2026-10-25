<?php
/**
 * Catalog archives: page size and A to Z artist browsing.
 *
 * Format and genre filters need no code: taxonomy query vars
 * (?waxsc_format=vinyl&waxsc_genre=jazz) are handled natively by WordPress.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Front;

use WaxStore\Catalog\Content\Filing;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Content\SortKeys;
use WaxStore\Catalog\Content\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog query adjustments.
 */
final class CatalogQuery {

	const LETTERS_CACHE = 'waxsc_artist_letters';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'pre_get_posts', array( self::class, 'adjust' ) );
		add_filter( 'query_loop_block_query_vars', array( self::class, 'query_loop' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );

		foreach ( array( 'created_' . Taxonomies::ARTIST, 'edited_' . Taxonomies::ARTIST, 'delete_' . Taxonomies::ARTIST ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}

		add_action(
			'set_object_terms',
			static function ( $object_id, $terms, $tt_ids, $taxonomy ) {
				if ( Taxonomies::ARTIST === $taxonomy ) {
					self::flush();
				}
			},
			10,
			4
		);
	}

	/**
	 * Public query var for the artist letter.
	 *
	 * @param array $vars Query vars.
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = 'waxsc_letter';
		$vars[] = 'waxsc_sort';
		$vars[] = 'waxsc_sold';
		$vars[] = 'waxsc_style';
		$vars[] = 'waxsc_q';
		return $vars;
	}

	/**
	 * Adjusts the main catalog query.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function adjust( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type  = waxsc()->commerce()->get_catalog_post_type();
		$taxonomies = array( Taxonomies::FORMAT, Taxonomies::GENRE, Taxonomies::ARTIST, Taxonomies::LABEL, Taxonomies::COUNTRY, Taxonomies::CONDITION, Taxonomies::EDITION, Taxonomies::FEATURE );

		if ( ! $query->is_post_type_archive( $post_type ) && ! $query->is_tax( $taxonomies ) ) {
			return;
		}

		/**
		 * Releases per catalog page.
		 *
		 * @param int $per_page Releases per page.
		 */
		$query->set( 'posts_per_page', (int) apply_filters( 'waxsc_catalog_per_page', 24 ) );

		self::apply_style( $query );
		self::apply_search( $query );
		self::apply_sort( $query, self::current_sort() );

		$letter = (string) $query->get( 'waxsc_letter' );

		if ( '' === $letter ) {
			return;
		}

		$letter  = '0' === $letter ? '#' : strtoupper( substr( $letter, 0, 1 ) );
		$letters = self::letters();
		$ids     = $letters[ $letter ] ?? array();

		if ( ! $ids ) {
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = array(
			'taxonomy' => Taxonomies::ARTIST,
			'field'    => 'term_id',
			'terms'    => $ids,
		);

		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Current keyword search, trimmed and limited.
	 */
	public static function current_search(): string {
		return mb_substr( trim( sanitize_text_field( (string) get_query_var( 'waxsc_q' ) ) ), 0, 100 );
	}

	/**
	 * Keyword search on artists, titles and catalog numbers. Every word must
	 * match one of them: "floyd meddle" finds Meddle by Pink Floyd.
	 *
	 * @param \WP_Query $query Query.
	 */
	private static function apply_search( \WP_Query $query ): void {
		$search = sanitize_text_field( (string) $query->get( 'waxsc_q' ) );
		$words  = array_slice( array_filter( preg_split( '/\s+/u', mb_substr( trim( $search ), 0, 100 ) ) ), 0, 6 );

		if ( ! $words ) {
			return;
		}

		$ids = null;

		foreach ( $words as $word ) {
			$matches = self::matching_ids( $word );
			$ids     = null === $ids ? $matches : array_values( array_intersect( $ids, $matches ) );

			if ( ! $ids ) {
				break;
			}
		}

		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
	}

	/**
	 * Releases whose title, artist or catalog number contains a word.
	 *
	 * @param string $word Word.
	 * @return int[]
	 */
	private static function matching_ids( string $word ): array {
		global $wpdb;

		$post_type = waxsc()->commerce()->get_catalog_post_type();
		$like      = '%' . $wpdb->esc_like( $word ) . '%';
		$compact   = '%' . $wpdb->esc_like( (string) preg_replace( '/[\s.\-\/]/', '', $word ) ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- search on indexed columns, results vary per request.
		$by_text = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_title LIKE %s
				UNION
				SELECT m.post_id FROM {$wpdb->postmeta} m
				WHERE m.meta_key = %s AND ( m.meta_value LIKE %s OR REPLACE( REPLACE( REPLACE( m.meta_value, ' ', '' ), '-', '' ), '.', '' ) LIKE %s )",
				$post_type,
				$like,
				ReleaseMeta::CATALOG_NUMBER,
				$like,
				$compact
			)
		);
		// phpcs:enable

		$artists = get_terms(
			array(
				'taxonomy'   => Taxonomies::ARTIST,
				'name__like' => $word,
				'fields'     => 'ids',
				'hide_empty' => true,
			)
		);

		$by_artist = ( is_array( $artists ) && $artists ) ? get_objects_in_term( $artists, Taxonomies::ARTIST ) : array();

		return array_values( array_unique( array_map( 'intval', array_merge( (array) $by_text, is_array( $by_artist ) ? $by_artist : array() ) ) ) );
	}

	/**
	 * Search result pages are not indexed: endless combinations, no unique content.
	 *
	 * @param array $robots Robots directives.
	 */
	public static function robots( array $robots ): array {
		if ( '' !== self::current_search() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}

	/**
	 * A sub-genre replaces its genre in the query, when it really belongs to it.
	 *
	 * @param \WP_Query $query Query.
	 */
	private static function apply_style( \WP_Query $query ): void {
		$style = sanitize_title( (string) $query->get( 'waxsc_style' ) );

		if ( '' === $style ) {
			return;
		}

		$term = get_term_by( 'slug', $style, Taxonomies::GENRE );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$genre = sanitize_title( (string) $query->get( Taxonomies::GENRE ) );

		if ( '' !== $genre ) {
			$parent    = get_term_by( 'slug', $genre, Taxonomies::GENRE );
			$ancestors = get_ancestors( $term->term_id, Taxonomies::GENRE, 'taxonomy' );

			if ( ! $parent instanceof \WP_Term || ! in_array( $parent->term_id, $ancestors, true ) ) {
				return;
			}
		}

		$query->set( Taxonomies::GENRE, $term->slug );
	}

	/**
	 * Sort options: key => label.
	 *
	 * @return array<string,string>
	 */
	public static function sort_options(): array {
		return array(
			'az'         => __( 'Artist, A to Z', 'wax-store-catalog' ),
			'new'        => __( 'Latest arrivals', 'wax-store-catalog' ),
			'price_asc'  => __( 'Price, low to high', 'wax-store-catalog' ),
			'price_desc' => __( 'Price, high to low', 'wax-store-catalog' ),
		);
	}

	/**
	 * Default sort, from the Catalog settings.
	 */
	public static function default_sort(): string {
		$sort = (string) get_option( 'waxsc_default_sort', 'az' );

		return isset( self::sort_options()[ $sort ] ) ? $sort : 'az';
	}

	/**
	 * Requested sort, or the default one.
	 */
	public static function current_sort(): string {
		$sort = sanitize_key( (string) get_query_var( 'waxsc_sort' ) );

		return isset( self::sort_options()[ $sort ] ) ? $sort : self::default_sort();
	}

	/**
	 * Applies a sort, and hides sold releases when needed. Price sorts only
	 * list releases with a price.
	 *
	 * @param \WP_Query $query Query.
	 * @param string    $sort  Sort key.
	 */
	private static function apply_sort( \WP_Query $query, string $sort ): void {
		$meta = array( 'relation' => 'AND' );

		if ( self::hide_sold() ) {
			$meta[] = self::available_clause();
		}

		if ( 'new' === $sort ) {
			$query->set( 'orderby', array( 'date' => 'DESC' ) );
		} elseif ( 'price_asc' === $sort || 'price_desc' === $sort ) {
			$meta['waxsc_price'] = array(
				'key'     => SortKeys::PRICE,
				'value'   => 0,
				'compare' => '>',
				'type'    => 'DECIMAL(10,2)',
			);
			$query->set(
				'orderby',
				array(
					'waxsc_price' => 'price_asc' === $sort ? 'ASC' : 'DESC',
					'title'       => 'ASC',
				)
			);
		} else {
			$meta[] = array(
				'relation'   => 'OR',
				'waxsc_name' => array(
					'key'     => SortKeys::NAME,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => SortKeys::NAME,
					'compare' => 'NOT EXISTS',
				),
			);
			$query->set(
				'orderby',
				array(
					'waxsc_name' => 'ASC',
					'title'      => 'ASC',
				)
			);
		}

		if ( count( $meta ) > 1 ) {
			$query->set( 'meta_query', $meta );
		}
	}

	/**
	 * Meta clause keeping releases that still have a copy for sale or reserved.
	 */
	public static function available_clause(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => SortKeys::SOLD,
				'value'   => '1',
				'compare' => '!=',
			),
			array(
				'key'     => SortKeys::SOLD,
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Shop setting: hide sold releases from catalog grids, or show them.
	 */
	public static function sold_setting(): string {
		return 'show' === get_option( 'waxsc_sold_items', 'hide' ) ? 'show' : 'hide';
	}

	/**
	 * Whether sold releases are hidden for the current request: always when the
	 * shop hides them, or when the visitor ticks "Hide sold items".
	 */
	public static function hide_sold(): bool {
		return 'hide' === self::sold_setting() || 'hide' === get_query_var( 'waxsc_sold' );
	}

	/**
	 * Same rule for query loops placed on other pages (home page grid…).
	 *
	 * @param array $vars Query vars of a Query Loop block.
	 */
	public static function query_loop( array $vars ): array {
		$post_type = waxsc()->commerce()->get_catalog_post_type();

		if ( ( $vars['post_type'] ?? '' ) !== $post_type || 'hide' !== self::sold_setting() ) {
			return $vars;
		}

		$meta               = isset( $vars['meta_query'] ) && is_array( $vars['meta_query'] ) ? $vars['meta_query'] : array();
		$vars['meta_query'] = $meta ? array( 'relation' => 'AND', $meta, self::available_clause() ) : self::available_clause(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small catalogs.

		return $vars;
	}

	/**
	 * Letters that have at least one artist.
	 *
	 * @return array<string,bool>
	 */
	public static function available_letters(): array {
		return array_fill_keys( array_keys( self::letters() ), true );
	}

	/**
	 * Artist term IDs grouped by filing letter, cached.
	 *
	 * @return array<string,int[]>
	 */
	private static function letters(): array {
		$cached = get_transient( self::LETTERS_CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$terms   = get_terms(
			array(
				'taxonomy'   => Taxonomies::ARTIST,
				'hide_empty' => true,
			)
		);
		$letters = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$letters[ self::filing_letter( $term->name ) ][] = (int) $term->term_id;
		}

		ksort( $letters );
		set_transient( self::LETTERS_CACHE, $letters, DAY_IN_SECONDS );

		return $letters;
	}

	/**
	 * Letter an artist is filed under.
	 *
	 * @param string $name Artist name.
	 */
	public static function filing_letter( string $name ): string {
		return Filing::letter( $name );
	}

	/**
	 * Clears the letter cache.
	 */
	public static function flush(): void {
		delete_transient( self::LETTERS_CACHE );
	}
}
