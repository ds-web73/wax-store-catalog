<?php
/**
 * Commerce provider contract.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * Any selling engine (showcase, WooCommerce, a dedicated extension…) implements
 * this interface. Themes and blocks only talk to this contract, never to the engine.
 */
interface ProviderInterface {

	const IN_STOCK = 'in_stock';
	const RESERVED = 'reserved';
	const SOLD     = 'sold';

	/**
	 * Unique identifier, stored in the waxsc_commerce_provider option.
	 */
	public function get_id(): string;

	/**
	 * Human readable name for the settings screen.
	 */
	public function get_label(): string;

	/**
	 * Whether the provider can run (e.g. WooCommerce is active).
	 */
	public function is_supported(): bool;

	/**
	 * Post type holding the catalog (waxsc_release, product…).
	 */
	public function get_catalog_post_type(): string;

	/**
	 * Escaped price HTML, empty string when no price.
	 *
	 * @param int $post_id Catalog item ID.
	 */
	public function get_price_html( int $post_id ): string;

	/**
	 * One of the IN_STOCK, RESERVED, SOLD constants.
	 *
	 * @param int $post_id Catalog item ID.
	 */
	public function get_availability( int $post_id ): string;

	/**
	 * Escaped call-to-action HTML (add to cart, reserve…), empty string when none.
	 *
	 * @param int $post_id Catalog item ID.
	 */
	public function get_action_html( int $post_id ): string;

	/**
	 * Schema.org Offer (or AggregateOffer) as an array, empty array when none.
	 *
	 * @param int $post_id Catalog item ID.
	 */
	public function get_offer_schema( int $post_id ): array;
}
