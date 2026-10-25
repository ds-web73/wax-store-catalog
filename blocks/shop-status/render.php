<?php
/**
 * Server rendering of the waxsc/shop-status block.
 *
 * @package WaxStore\Catalog
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo \WaxStore\Catalog\Front\ShopStatus::render( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in ShopStatus.
