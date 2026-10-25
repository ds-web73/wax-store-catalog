<?php
/**
 * Catalog taxonomies.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

use WaxStore\Catalog\Admin\SingleTermBox;
use WaxStore\Catalog\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomies attached to the catalog post type (release or WooCommerce product).
 */
final class Taxonomies {

	const FORMAT    = 'waxsc_format';
	const GENRE     = 'waxsc_genre';
	const ARTIST    = 'waxsc_artist';
	const LABEL     = 'waxsc_label';
	const COUNTRY   = 'waxsc_country';
	const CONDITION = 'waxsc_condition';
	const EDITION   = 'waxsc_edition';
	const FEATURE   = 'waxsc_feature';

	/**
	 * Term meta holding a stable, language-independent key (vinyl, cd, used…).
	 */
	const KEY_META = 'waxsc_key';

	/**
	 * Taxonomies accepting a single term per item.
	 */
	const SINGLE_CHOICE = array( self::FORMAT, self::CONDITION, self::EDITION );

	/**
	 * Registers all taxonomies on the given post type.
	 *
	 * @param string $post_type Catalog post type.
	 */
	public static function register( string $post_type ): void {
		foreach ( self::definitions() as $taxonomy => $definition ) {
			$args = array(
				'labels'            => $definition['labels'],
				'hierarchical'      => $definition['hierarchical'],
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => $definition['admin_column'],
				'show_tagcloud'     => false,
				'rewrite'           => array(
					'slug'         => $definition['slug'],
					'with_front'   => false,
					'hierarchical' => $definition['hierarchical'],
				),
			);

			if ( in_array( $taxonomy, self::SINGLE_CHOICE, true ) ) {
				$args['meta_box_cb'] = array( SingleTermBox::class, 'render' );
			}

			register_taxonomy( $taxonomy, array( $post_type ), $args );

			register_term_meta(
				$taxonomy,
				self::KEY_META,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_key',
				)
			);
		}
	}

	/**
	 * Keeps a single term in single-choice taxonomies, whatever the source
	 * (editor, REST, import). The most specific term wins: "LP" over "Vinyl".
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms     Terms passed to wp_set_object_terms().
	 * @param array  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy.
	 */
	public static function enforce_single_term( $object_id, $terms, $tt_ids, $taxonomy ): void {
		static $running = false;

		if ( $running || ! in_array( $taxonomy, self::SINGLE_CHOICE, true ) ) {
			return;
		}

		$assigned = wp_get_object_terms( (int) $object_id, $taxonomy, array( 'fields' => 'ids' ) );

		if ( ! is_array( $assigned ) || count( $assigned ) < 2 ) {
			return;
		}

		$best       = 0;
		$best_depth = -1;

		foreach ( $assigned as $term_id ) {
			$depth = count( get_ancestors( (int) $term_id, $taxonomy, 'taxonomy' ) );

			if ( $depth > $best_depth ) {
				$best       = (int) $term_id;
				$best_depth = $depth;
			}
		}

		$running = true;
		wp_set_object_terms( (int) $object_id, array( $best ), $taxonomy );
		$running = false;
	}

	/**
	 * Options for a single-choice control: term ID => indented name.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int,string>
	 */
	public static function hierarchical_options( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$children = array();

		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}

		$options = array();
		$walk    = static function ( int $parent, int $depth ) use ( &$walk, &$options, $children ) {
			foreach ( $children[ $parent ] ?? array() as $term ) {
				$options[ (int) $term->term_id ] = str_repeat( '— ', $depth ) . $term->name;
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};

		$walk( 0, 0 );

		return $options;
	}

	/**
	 * Finds a term by its stable key, 0 when missing (deleted by the shop owner).
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $key      Stable key.
	 */
	public static function find_by_key( string $taxonomy, string $key ): int {
		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
				'meta_key'   => self::KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small taxonomies.
				'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- small taxonomies.
			)
		);

		return ( is_array( $ids ) && $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Finds a reference term by key; when it was deleted or recreated by hand,
	 * reattaches the key to a term with the default name, or creates it.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $key      Stable key.
	 */
	public static function ensure_by_key( string $taxonomy, string $key ): int {
		$term_id = self::find_by_key( $taxonomy, $key );

		if ( $term_id ) {
			return $term_id;
		}

		$name = Installer::default_term_name( $taxonomy, $key );

		if ( '' === $name ) {
			return 0;
		}

		$existing = term_exists( $name, $taxonomy );

		if ( is_array( $existing ) ) {
			$term_id = (int) $existing['term_id'];
		} else {
			$result  = wp_insert_term( $name, $taxonomy );
			$term_id = is_wp_error( $result ) ? 0 : (int) $result['term_id'];
		}

		if ( $term_id ) {
			update_term_meta( $term_id, self::KEY_META, $key );
		}

		return $term_id;
	}

	/**
	 * Returns the stable key of a term, or an empty string.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function get_key( int $term_id ): string {
		return (string) get_term_meta( $term_id, self::KEY_META, true );
	}

	/**
	 * Taxonomy definitions. URL slugs are translatable.
	 */
	private static function definitions(): array {
		return array(
			self::FORMAT    => array(
				'hierarchical' => true,
				'admin_column' => true,
				'slug'         => _x( 'format', 'taxonomy URL slug', 'wax-store-catalog' ),
				'labels'       => array(
					'name'          => _x( 'Formats', 'taxonomy general name', 'wax-store-catalog' ),
					'singular_name' => _x( 'Format', 'taxonomy singular name', 'wax-store-catalog' ),
					'all_items'     => __( 'All formats', 'wax-store-catalog' ),
					'edit_item'     => __( 'Edit format', 'wax-store-catalog' ),
					'view_item'     => __( 'View format', 'wax-store-catalog' ),
					'add_new_item'  => __( 'Add new format', 'wax-store-catalog' ),
					'new_item_name' => __( 'New format name', 'wax-store-catalog' ),
					'parent_item'   => __( 'Parent format', 'wax-store-catalog' ),
					'search_items'  => __( 'Search formats', 'wax-store-catalog' ),
					'not_found'     => __( 'No formats found.', 'wax-store-catalog' ),
					'back_to_items' => __( '&larr; Go to formats', 'wax-store-catalog' ),
				),
			),
			self::GENRE     => array(
				'hierarchical' => true,
				'admin_column' => true,
				'slug'         => _x( 'genre', 'taxonomy URL slug', 'wax-store-catalog' ),
				'labels'       => array(
					'name'          => _x( 'Genres', 'taxonomy general name', 'wax-store-catalog' ),
					'singular_name' => _x( 'Genre', 'taxonomy singular name', 'wax-store-catalog' ),
					'all_items'     => __( 'All genres', 'wax-store-catalog' ),
					'edit_item'     => __( 'Edit genre', 'wax-store-catalog' ),
					'view_item'     => __( 'View genre', 'wax-store-catalog' ),
					'add_new_item'  => __( 'Add new genre', 'wax-store-catalog' ),
					'new_item_name' => __( 'New genre name', 'wax-store-catalog' ),
					'parent_item'   => __( 'Parent genre', 'wax-store-catalog' ),
					'search_items'  => __( 'Search genres', 'wax-store-catalog' ),
					'not_found'     => __( 'No genres found.', 'wax-store-catalog' ),
					'back_to_items' => __( '&larr; Go to genres', 'wax-store-catalog' ),
				),
			),
			self::ARTIST    => array(
				'hierarchical' => false,
				'admin_column' => true,
				'slug'         => _x( 'artist', 'taxonomy URL slug', 'wax-store-catalog' ),
				'labels'       => array(
					'name'                       => _x( 'Artists', 'taxonomy general name', 'wax-store-catalog' ),
					'singular_name'              => _x( 'Artist', 'taxonomy singular name', 'wax-store-catalog' ),
					'all_items'                  => __( 'All artists', 'wax-store-catalog' ),
					'edit_item'                  => __( 'Edit artist', 'wax-store-catalog' ),
					'view_item'                  => __( 'View artist', 'wax-store-catalog' ),
					'add_new_item'               => __( 'Add new artist', 'wax-store-catalog' ),
					'new_item_name'              => __( 'New artist name', 'wax-store-catalog' ),
					'search_items'               => __( 'Search artists', 'wax-store-catalog' ),
					'not_found'                  => __( 'No artists found.', 'wax-store-catalog' ),
					'separate_items_with_commas' => __( 'Separate artists with commas', 'wax-store-catalog' ),
					'choose_from_most_used'      => __( 'Choose from the most used artists', 'wax-store-catalog' ),
					'back_to_items'              => __( '&larr; Go to artists', 'wax-store-catalog' ),
				),
			),
			self::LABEL     => array(
				'hierarchical' => false,
				'admin_column' => false,
				'slug'         => _x( 'label', 'taxonomy URL slug', 'wax-store-catalog' ),
				'labels'       => array(
					'name'                       => _x( 'Record labels', 'taxonomy general name', 'wax-store-catalog' ),
					'singular_name'              => _x( 'Record label', 'taxonomy singular name', 'wax-store-catalog' ),
					'all_items'                  => __( 'All record labels', 'wax-store-catalog' ),
					'edit_item'                  => __( 'Edit record label', 'wax-store-catalog' ),
					'view_item'                  => __( 'View record label', 'wax-store-catalog' ),
					'add_new_item'               => __( 'Add new record label', 'wax-store-catalog' ),
					'new_item_name'              => __( 'New record label name', 'wax-store-catalog' ),
					'search_items'               => __( 'Search record labels', 'wax-store-catalog' ),
					'not_found'                  => __( 'No record labels found.', 'wax-store-catalog' ),
					'separate_items_with_commas' => __( 'Separate record labels with commas', 'wax-store-catalog' ),
					'choose_from_most_used'      => __( 'Choose from the most used record labels', 'wax-store-catalog' ),
					'back_to_items'              => __( '&larr; Go to record labels', 'wax-store-catalog' ),
				),
			),
			self::COUNTRY   => array(
				'hierarchical' => false,
				'admin_column' => false,
				'slug'         => _x( 'country', 'taxonomy URL slug', 'wax-store-catalog' ),
				'labels'       => array(
					'name'                       => _x( 'Countries', 'taxonomy general name', 'wax-store-catalog' ),
					'singular_name'              => _x( 'Country', 'taxonomy singular name', 'wax-store-catalog' ),
					'all_items'                  => __( 'All countries', 'wax-store-catalog' ),
					'edit_item'                  => __( 'Edit country', 'wax-store-catalog' ),
					'view_item'                  => __( 'View country', 'wax-store-catalog' ),
					'add_new_item'               => __( 'Add new country', 'wax-store-catalog' ),
					'new_item_name'              => __( 'New country name', 'wax-store-catalog' ),
					'search_items'               => __( 'Search countries', 'wax-store-catalog' ),
					'not_found'                  => __( 'No countries found.', 'wax-store-catalog' ),
					'separate_items_with_commas' => __( 'Separate countries with commas', 'wax-store-catalog' ),
					'choose_from_most_used'      => __( 'Choose from the most used countries', 'wax-store-catalog' ),
					'back_to_items'              => __( '&larr; Go to countries', 'wax-store-catalog' ),
				),
			),
			self::CONDITION => array(
				'hierarchical' => true,
				'admin_column' => true,
				'slug'         => _x( 'condition', 'taxonomy URL slug', 'wax-store-catalog' ),
				'labels'       => array(
					'name'          => _x( 'Conditions', 'taxonomy general name', 'wax-store-catalog' ),
					'singular_name' => _x( 'Condition', 'taxonomy singular name', 'wax-store-catalog' ),
					'all_items'     => __( 'All conditions', 'wax-store-catalog' ),
					'edit_item'     => __( 'Edit condition', 'wax-store-catalog' ),
					'view_item'     => __( 'View condition', 'wax-store-catalog' ),
					'add_new_item'  => __( 'Add new condition', 'wax-store-catalog' ),
					'new_item_name' => __( 'New condition name', 'wax-store-catalog' ),
					'parent_item'   => __( 'Parent condition', 'wax-store-catalog' ),
					'search_items'  => __( 'Search conditions', 'wax-store-catalog' ),
					'not_found'     => __( 'No conditions found.', 'wax-store-catalog' ),
					'back_to_items' => __( '&larr; Go to conditions', 'wax-store-catalog' ),
				),
			),
			self::EDITION   => array(
				'hierarchical' => true,
				'admin_column' => false,
				'slug'         => _x( 'edition', 'taxonomy URL slug', 'wax-store-catalog' ),
				'labels'       => array(
					'name'          => _x( 'Editions', 'taxonomy general name', 'wax-store-catalog' ),
					'singular_name' => _x( 'Edition', 'taxonomy singular name', 'wax-store-catalog' ),
					'all_items'     => __( 'All editions', 'wax-store-catalog' ),
					'edit_item'     => __( 'Edit edition', 'wax-store-catalog' ),
					'view_item'     => __( 'View edition', 'wax-store-catalog' ),
					'add_new_item'  => __( 'Add new edition', 'wax-store-catalog' ),
					'new_item_name' => __( 'New edition name', 'wax-store-catalog' ),
					'parent_item'   => __( 'Parent edition', 'wax-store-catalog' ),
					'search_items'  => __( 'Search editions', 'wax-store-catalog' ),
					'not_found'     => __( 'No editions found.', 'wax-store-catalog' ),
					'back_to_items' => __( '&larr; Go to editions', 'wax-store-catalog' ),
				),
			),
			self::FEATURE   => array(
				'hierarchical' => true,
				'admin_column' => false,
				'slug'         => _x( 'feature', 'taxonomy URL slug', 'wax-store-catalog' ),
				'labels'       => array(
					'name'          => _x( 'Features', 'taxonomy general name', 'wax-store-catalog' ),
					'singular_name' => _x( 'Feature', 'taxonomy singular name', 'wax-store-catalog' ),
					'all_items'     => __( 'All features', 'wax-store-catalog' ),
					'edit_item'     => __( 'Edit feature', 'wax-store-catalog' ),
					'view_item'     => __( 'View feature', 'wax-store-catalog' ),
					'add_new_item'  => __( 'Add new feature', 'wax-store-catalog' ),
					'new_item_name' => __( 'New feature name', 'wax-store-catalog' ),
					'parent_item'   => __( 'Parent feature', 'wax-store-catalog' ),
					'search_items'  => __( 'Search features', 'wax-store-catalog' ),
					'not_found'     => __( 'No features found.', 'wax-store-catalog' ),
					'back_to_items' => __( '&larr; Go to features', 'wax-store-catalog' ),
				),
			),
		);
	}
}
