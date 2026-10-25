<?php
/**
 * Server rendering of the waxsc/catalog-filters block.
 *
 * @package WaxStore\Catalog
 *
 * @var array     $attributes Block attributes.
 * @var \WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

echo \WaxStore\Catalog\Front\Blocks::filters( $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Blocks.
