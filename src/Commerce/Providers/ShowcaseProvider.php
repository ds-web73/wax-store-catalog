<?php
/**
 * Showcase provider: no online checkout, visitors reserve or contact the shop.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Commerce\Providers;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Default provider, built into the plugin. Prices and availability are
 * aggregated from the release copies.
 */
class ShowcaseProvider implements ProviderInterface {

	const ID = 'showcase';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Showcase (reservation, no online checkout)', 'wax-store-catalog' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_supported(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_catalog_post_type(): string {
		return PostType::NAME;
	}

	/**
	 * Single price, or "from" the lowest price when copies differ.
	 *
	 * @param int $post_id Release ID.
	 */
	public function get_price_html( int $post_id ): string {
		$prices = Copies::available_prices( $post_id );

		if ( ! $prices ) {
			return '';
		}

		$amount = '<span class="waxsc-price__amount">' . esc_html( Copies::format_price( $prices[0] ) ) . '</span>';

		if ( count( array_unique( $prices ) ) > 1 ) {
			return '<span class="waxsc-price waxsc-price--from"><span class="waxsc-price__from">' . esc_html_x( 'From', 'before the lowest price', 'wax-store-catalog' ) . '</span> ' . $amount . '</span>';
		}

		return '<span class="waxsc-price">' . $amount . '</span>';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_availability( int $post_id ): string {
		return Copies::availability( $post_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_action_html( int $post_id ): string {
		$availability = $this->get_availability( $post_id );

		if ( self::SOLD === $availability ) {
			return '<span class="waxsc-action waxsc-action--sold">' . esc_html__( 'Sold', 'wax-store-catalog' ) . '</span>';
		}

		if ( self::RESERVED === $availability ) {
			return '<span class="waxsc-action waxsc-action--reserved">' . esc_html__( 'Reserved', 'wax-store-catalog' ) . '</span>';
		}

		$url = self::reserve_url( $post_id );

		if ( '' === $url ) {
			return '';
		}

		return sprintf(
			'<a class="waxsc-action waxsc-action--reserve" href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Reserve this item', 'wax-store-catalog' )
		);
	}

	/**
	 * Link to the reservation page for a release, and optionally one copy.
	 * Empty when no published reservation page is set.
	 *
	 * @param int    $post_id Release ID.
	 * @param string $copy_id Copy ID.
	 */
	public static function reserve_url( int $post_id, string $copy_id = '' ): string {
		$page_id = (int) get_option( 'waxsc_reserve_page', 0 );

		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		$args = array( 'waxsc_ref' => $post_id );

		if ( '' !== $copy_id ) {
			$args['waxsc_copy'] = $copy_id;
		}

		return add_query_arg( $args, get_permalink( $page_id ) );
	}

	/**
	 * Offer for a single available copy, AggregateOffer for several.
	 *
	 * @param int $post_id Release ID.
	 */
	public function get_offer_schema( int $post_id ): array {
		$prices       = Copies::available_prices( $post_id );
		$availability = 'https://schema.org/InStock';

		// Nothing in stock: keep an offer with the last known price, flagged
		// reserved or sold out, so the product stays valid for search engines.
		if ( ! $prices ) {
			$known = array_filter( wp_list_pluck( Copies::get( $post_id ), 'price' ), 'strlen' );

			if ( ! $known ) {
				return array();
			}

			sort( $known, SORT_NUMERIC );
			$prices       = array( reset( $known ) );
			$availability = self::RESERVED === $this->get_availability( $post_id ) ? 'https://schema.org/Reserved' : 'https://schema.org/SoldOut';
		}

		$offer = array(
			'url'           => get_permalink( $post_id ),
			'priceCurrency' => $this->get_currency(),
			'availability'  => $availability,
		);

		$condition = $this->get_item_condition( $post_id );

		if ( $condition ) {
			$offer['itemCondition'] = $condition;
		}

		if ( 1 === count( $prices ) ) {
			return array( '@type' => 'Offer' ) + $offer + array( 'price' => $prices[0] );
		}

		return array( '@type' => 'AggregateOffer' ) + $offer + array(
			'lowPrice'   => $prices[0],
			'highPrice'  => end( $prices ),
			'offerCount' => count( $prices ),
		);
	}

	/**
	 * Schema.org condition from the condition taxonomy (new / used).
	 *
	 * @param int $post_id Release ID.
	 */
	private function get_item_condition( int $post_id ): string {
		$terms = get_the_terms( $post_id, Taxonomies::CONDITION );

		if ( ! is_array( $terms ) ) {
			return '';
		}

		foreach ( $terms as $term ) {
			$key = Taxonomies::get_key( (int) $term->term_id );

			if ( 'used' === $key ) {
				return 'https://schema.org/UsedCondition';
			}

			if ( 'new' === $key ) {
				return 'https://schema.org/NewCondition';
			}
		}

		return '';
	}

	/**
	 * ISO 4217 currency code, EUR by default.
	 */
	private function get_currency(): string {
		$currency = strtoupper( (string) get_option( 'waxsc_currency', 'EUR' ) );

		return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'EUR';
	}
}
