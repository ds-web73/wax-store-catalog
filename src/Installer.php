<?php
/**
 * Activation, deactivation and upgrade routines.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Content\SortKeys;
use WaxStore\Catalog\Content\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	const VERSION_OPTION = 'waxsc_version';
	const TERMS_OPTION   = 'waxsc_default_terms_installed';

	const TERMS_TRANSLATED_OPTION = 'waxsc_default_terms_translated';

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		Plugin::instance()->register_content();
		self::install();
		self::heal_sort_keys();
		flush_rewrite_rules();
	}

	/**
	 * Computes sort keys and the sold flag for releases that miss them
	 * (releases created before 0.8.0, by other tools…). Runs in batches
	 * on admin pages until every release is covered.
	 */
	public static function heal_sort_keys(): void {
		if ( wp_doing_ajax() || get_transient( 'waxsc_sort_checked' ) ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- batched, admin only.
					array(
						'key'     => SortKeys::SOLD,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $ids as $post_id ) {
			SortKeys::update( (int) $post_id );
		}

		// Nothing left: check again in ten minutes only.
		if ( count( $ids ) < 300 ) {
			set_transient( 'waxsc_sort_checked', 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Default terms created before translations existed keep their English name
	 * ("Vinyl", "Used"…). Renames those still untouched to the site language;
	 * terms the shop owner renamed are left alone.
	 */
	private static function translate_default_terms(): void {
		$translated = self::default_terms();

		if ( ! switch_to_locale( 'en_US' ) ) {
			return;
		}

		$english = self::default_terms();
		restore_previous_locale();

		foreach ( $translated as $taxonomy => $terms ) {
			self::rename_terms( $taxonomy, $terms, $english[ $taxonomy ] ?? array() );
		}
	}

	/**
	 * Recursive rename helper.
	 *
	 * @param string $taxonomy   Taxonomy.
	 * @param array  $translated Translated definitions.
	 * @param array  $english    English definitions, same order.
	 */
	private static function rename_terms( string $taxonomy, array $translated, array $english ): void {
		foreach ( $translated as $index => $term ) {
			$source = $english[ $index ] ?? null;

			if ( ! $source || $source['key'] !== $term['key'] ) {
				continue;
			}

			$term_id = Taxonomies::find_by_key( $taxonomy, $term['key'] );
			$current = $term_id ? get_term( $term_id, $taxonomy ) : null;

			if ( $current instanceof \WP_Term && $current->name === $source['name'] && $source['name'] !== $term['name'] ) {
				$args = array( 'name' => $term['name'] );

				if ( sanitize_title( $source['name'] ) === $current->slug ) {
					$args['slug'] = sanitize_title( $term['name'] );
				}

				wp_update_term( $term_id, $taxonomy, $args );
			}

			if ( ! empty( $term['children'] ) ) {
				self::rename_terms( $taxonomy, $term['children'], $source['children'] ?? array() );
			}
		}
	}

	/**
	 * Deactivation hook. Content is kept; only rewrite rules are cleaned.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( \WaxStore\Catalog\Reservation\Requests::CRON );
		wp_clear_scheduled_hook( \WaxStore\Catalog\Alerts\Alerts::CRON );
		wp_clear_scheduled_hook( \WaxStore\Catalog\Buying\Offers::CRON );
		unregister_post_type( PostType::NAME );
		flush_rewrite_rules();
	}

	/**
	 * Runs on init (late) when the stored version differs from the code.
	 */
	public static function maybe_upgrade(): void {
		$previous = (string) get_option( self::VERSION_OPTION );

		if ( VERSION === $previous ) {
			return;
		}

		if ( $previous && version_compare( $previous, '0.2.0', '<' ) ) {
			self::migrate_price_to_copies();
		}

		if ( ! get_option( self::TERMS_TRANSLATED_OPTION ) ) {
			self::translate_default_terms();
			update_option( self::TERMS_TRANSLATED_OPTION, 1, false );
		}

		if ( $previous && version_compare( $previous, '0.17.0', '<' ) && version_compare( $previous, '0.5.0', '>=' ) ) {
			self::insert_terms( Taxonomies::FEATURE, self::default_terms()[ Taxonomies::FEATURE ], 0 );
		}

		if ( $previous && version_compare( $previous, '0.5.0', '<' ) ) {
			self::insert_terms( Taxonomies::FEATURE, self::default_terms()[ Taxonomies::FEATURE ], 0 );
		}

		self::install();
		flush_rewrite_rules();
	}

	/**
	 * Idempotent install. Default terms are only created once, so that terms
	 * deleted on purpose by the shop owner are never recreated.
	 */
	private static function install(): void {
		if ( ! get_option( self::TERMS_OPTION ) ) {
			self::install_default_terms();
			update_option( self::TERMS_OPTION, 1, false );
		}

		update_option( self::VERSION_OPTION, VERSION, false );
	}

	/**
	 * 0.1.0 stored price and availability on the release itself:
	 * turn them into a single copy.
	 */
	private static function migrate_price_to_copies(): void {
		$ids = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => 'waxsc_price', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration.
			)
		);

		foreach ( $ids as $post_id ) {
			if ( ! Copies::get( $post_id ) ) {
				$copy           = Copies::empty_row();
				$copy['price']  = (string) get_post_meta( $post_id, 'waxsc_price', true );
				$copy['status'] = (string) get_post_meta( $post_id, 'waxsc_availability', true );

				if ( ProviderInterface::SOLD === $copy['status'] ) {
					$copy['qty'] = 0;
				}

				update_post_meta( $post_id, ReleaseMeta::COPIES, Copies::sanitize( array( $copy ) ) );
			}

			delete_post_meta( $post_id, 'waxsc_price' );
			delete_post_meta( $post_id, 'waxsc_availability' );
		}
	}

	/**
	 * Creates the default terms. Names are translated in the site language,
	 * the stable key is stored in term meta so code never relies on names or slugs.
	 */
	private static function install_default_terms(): void {
		foreach ( self::default_terms() as $taxonomy => $terms ) {
			self::insert_terms( $taxonomy, $terms, 0 );
		}
	}

	/**
	 * Recursive term insertion.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $terms    Terms definitions.
	 * @param int    $parent   Parent term ID.
	 */
	private static function insert_terms( string $taxonomy, array $terms, int $parent ): void {
		foreach ( $terms as $term ) {
			$term_id = Taxonomies::find_by_key( $taxonomy, $term['key'] );

			if ( ! $term_id ) {
				$result = wp_insert_term( $term['name'], $taxonomy, array( 'parent' => $parent ) );

				if ( is_wp_error( $result ) ) {
					$term_id = (int) $result->get_error_data( 'term_exists' );
				} else {
					$term_id = (int) $result['term_id'];
				}

				if ( $term_id ) {
					update_term_meta( $term_id, Taxonomies::KEY_META, $term['key'] );
				}
			}

			if ( $term_id && ! empty( $term['children'] ) ) {
				self::insert_terms( $taxonomy, $term['children'], $term_id );
			}
		}
	}

	/**
	 * Translated default name of a reference term, empty when the key is unknown.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $key      Stable key.
	 */
	public static function default_term_name( string $taxonomy, string $key ): string {
		$terms = self::default_terms()[ $taxonomy ] ?? array();
		$stack = $terms;

		while ( $stack ) {
			$term = array_shift( $stack );

			if ( $term['key'] === $key ) {
				return $term['name'];
			}

			foreach ( $term['children'] ?? array() as $child ) {
				$stack[] = $child;
			}
		}

		return '';
	}

	/**
	 * Default terms definitions.
	 */
	private static function default_terms(): array {
		return array(
			Taxonomies::FORMAT    => array(
				array(
					'key'      => 'vinyl',
					'name'     => __( 'Vinyl', 'wax-store-catalog' ),
					'children' => array(
						array(
							'key'  => 'vinyl-lp',
							'name' => __( 'LP (33 rpm)', 'wax-store-catalog' ),
						),
						array(
							'key'  => 'vinyl-single',
							'name' => __( 'Single (7" 45 rpm)', 'wax-store-catalog' ),
						),
						array(
							'key'  => 'vinyl-maxi',
							'name' => __( 'Maxi single (12" 45 rpm)', 'wax-store-catalog' ),
						),
						array(
							'key'  => 'vinyl-10',
							'name' => __( '10" record', 'wax-store-catalog' ),
						),
						array(
							'key'  => 'vinyl-box',
							'name' => __( 'Box set', 'wax-store-catalog' ),
						),
					),
				),
				array(
					'key'  => 'cd',
					'name' => __( 'CD', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'dvd',
					'name' => __( 'DVD', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'blu-ray',
					'name' => __( 'Blu-ray', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'cassette',
					'name' => __( 'Cassette', 'wax-store-catalog' ),
				),
				array(
					'key'      => 'merchandise',
					'name'     => __( 'Merchandise', 'wax-store-catalog' ),
					'children' => array(
						array(
							'key'  => 'merch-tshirt',
							'name' => __( 'T-shirts', 'wax-store-catalog' ),
						),
						array(
							'key'  => 'merch-poster',
							'name' => __( 'Posters', 'wax-store-catalog' ),
						),
						array(
							'key'  => 'merch-accessory',
							'name' => __( 'Accessories', 'wax-store-catalog' ),
						),
					),
				),
			),
			Taxonomies::CONDITION => array(
				array(
					'key'  => 'new',
					'name' => __( 'New', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'used',
					'name' => __( 'Used', 'wax-store-catalog' ),
				),
			),
			Taxonomies::EDITION   => array(
				array(
					'key'  => 'original',
					'name' => __( 'Original pressing', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'reissue',
					'name' => __( 'Reissue', 'wax-store-catalog' ),
				),
			),
			Taxonomies::FEATURE   => array(
				array(
					'key'  => 'colored',
					'name' => __( 'Colored vinyl', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'splatter',
					'name' => __( 'Splatter / marbled', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'picture-disc',
					'name' => __( 'Picture disc', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'limited',
					'name' => __( 'Limited edition', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'numbered',
					'name' => __( 'Numbered', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'promo',
					'name' => __( 'Promo', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'rsd',
					'name' => __( 'Record Store Day', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'test-pressing',
					'name' => __( 'Test pressing', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'white-label',
					'name' => __( 'White label', 'wax-store-catalog' ),
				),
				array(
					'key'  => '180g',
					'name' => __( '180 g', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'gatefold',
					'name' => __( 'Gatefold sleeve', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'poster',
					'name' => __( 'Poster included', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'goodies',
					'name' => __( 'Goodies included', 'wax-store-catalog' ),
				),
				array(
					'key'  => 'packaging',
					'name' => __( 'Special packaging', 'wax-store-catalog' ),
				),
			),
		);
	}
}
