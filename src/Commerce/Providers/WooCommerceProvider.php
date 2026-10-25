<?php
/**
 * WooCommerce provider: cart, checkout, payment and orders by WooCommerce,
 * while releases stay the catalog pages.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Commerce\Providers;

use WaxStore\Catalog\Commerce\WooSync;

defined( 'ABSPATH' ) || exit;

/**
 * Prices, availability and structured data come from the copies, as in
 * showcase mode; the call to action becomes an add-to-cart form.
 */
class WooCommerceProvider extends ShowcaseProvider {

	const ID = 'woocommerce';

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
		return __( 'WooCommerce (online checkout)', 'wax-store-catalog' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_supported(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Add-to-cart form: one choice per grading, with the quantity in stock.
	 *
	 * @param int $post_id Release ID.
	 */
	public function get_action_html( int $post_id ): string {
		$product = WooSync::product( $post_id );

		if ( ! $product ) {
			return parent::get_action_html( $post_id );
		}

		$options = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation || ! $variation->is_in_stock() || ! $variation->is_purchasable() ) {
				continue;
			}

			$stock     = (int) $variation->get_stock_quantity();
			$options[] = array(
				'id'    => $variation_id,
				'label' => (string) $variation->get_meta( '_waxsc_label' ),
				'stock' => max( 1, $stock ),
			);
		}

		if ( ! $options ) {
			return '<span class="waxsc-action waxsc-action--sold">' . esc_html__( 'Sold', 'wax-store-catalog' ) . '</span>';
		}

		$html  = '<form class="waxsc-cart" method="post" action="' . esc_url( get_permalink( $post_id ) ) . '">';
		$html .= '<input type="hidden" name="product_id" value="' . esc_attr( (string) $product->get_id() ) . '">';

		if ( 1 === count( $options ) ) {
			$html .= '<input type="hidden" name="add-to-cart" value="' . esc_attr( (string) $options[0]['id'] ) . '">';
			$max   = $options[0]['stock'];
		} else {
			$html .= '<p class="waxsc-cart__field"><label for="waxsc-cart-variation-' . esc_attr( (string) $post_id ) . '">' . esc_html__( 'Copy', 'wax-store-catalog' ) . '</label>';
			$html .= '<select id="waxsc-cart-variation-' . esc_attr( (string) $post_id ) . '" name="add-to-cart">';

			foreach ( $options as $option ) {
				/* translators: 1: grading and price, 2: number in stock. */
				$html .= '<option value="' . esc_attr( (string) $option['id'] ) . '">' . esc_html( sprintf( _n( '%1$s (%2$d in stock)', '%1$s (%2$d in stock)', $option['stock'], 'wax-store-catalog' ), $option['label'], $option['stock'] ) ) . '</option>';
			}

			$html .= '</select></p>';
			$max   = max( wp_list_pluck( $options, 'stock' ) );
		}

		if ( $max > 1 ) {
			$html .= '<p class="waxsc-cart__field"><label for="waxsc-cart-qty-' . esc_attr( (string) $post_id ) . '">' . esc_html__( 'Quantity', 'wax-store-catalog' ) . '</label>';
			$html .= '<input type="number" id="waxsc-cart-qty-' . esc_attr( (string) $post_id ) . '" name="quantity" value="1" min="1" max="' . esc_attr( (string) $max ) . '" inputmode="numeric"></p>';
		}

		$html .= '<p><button type="submit" class="waxsc-action waxsc-action--cart wp-element-button">' . esc_html__( 'Add to cart', 'wax-store-catalog' ) . '</button></p>';

		return $html . '</form>';
	}
}
