<?php
/**
 * Sort keys stored on each release, so the catalog can be ordered
 * by artist (filed name) or by lowest available price.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps _waxsc_sort_name, _waxsc_price_min and _waxsc_sold up to date.
 */
final class SortKeys {

	const NAME  = '_waxsc_sort_name';
	const PRICE = '_waxsc_price_min';
	const SOLD  = '_waxsc_sold';

	/**
	 * Hooks: release saved, artist changed, copies changed (editor or import).
	 */
	public static function init(): void {
		add_action( 'save_post_' . PostType::NAME, array( self::class, 'on_save' ), 20 );

		add_action(
			'set_object_terms',
			static function ( $object_id, $terms, $tt_ids, $taxonomy ) {
				if ( Taxonomies::ARTIST === $taxonomy && PostType::NAME === get_post_type( $object_id ) ) {
					self::update( (int) $object_id );
				}
			},
			10,
			4
		);

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action(
				$hook,
				static function ( $meta_id, $object_id, $meta_key ) {
					if ( ReleaseMeta::COPIES === $meta_key ) {
						self::update( (int) $object_id );
					}
				},
				10,
				3
			);
		}
	}

	/**
	 * Save hook.
	 *
	 * @param int $post_id Release ID.
	 */
	public static function on_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		self::update( $post_id );
	}

	/**
	 * Recomputes both keys of a release.
	 *
	 * @param int $post_id Release ID.
	 */
	public static function update( int $post_id ): void {
		$artists = get_the_terms( $post_id, Taxonomies::ARTIST );
		$artist  = is_array( $artists ) && $artists ? $artists[0]->name : '';
		$name    = strtolower( trim( Filing::name( $artist ) . ' ' . remove_accents( (string) get_the_title( $post_id ) ) ) );

		update_post_meta( $post_id, self::NAME, mb_substr( $name, 0, 190 ) );

		$prices = Copies::available_prices( $post_id );
		update_post_meta( $post_id, self::PRICE, $prices ? (float) $prices[0] : 0 );

		$was_sold = '1' === (string) get_post_meta( $post_id, self::SOLD, true );
		$in_stock = \WaxStore\Catalog\Commerce\ProviderInterface::IN_STOCK === Copies::availability( $post_id );
		update_post_meta( $post_id, self::SOLD, \WaxStore\Catalog\Commerce\ProviderInterface::SOLD === Copies::availability( $post_id ) ? 1 : 0 );

		if ( $was_sold && $in_stock ) {
			/**
			 * A sold release has a copy in stock again.
			 *
			 * @param int $post_id Release ID.
			 */
			do_action( 'waxsc_release_back_in_stock', $post_id );
		}
	}
}
