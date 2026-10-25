<?php
/**
 * Server rendering of the waxsc/release-details block.
 *
 * @package WaxStore\Catalog
 *
 * @var array     $attributes Block attributes.
 * @var \WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

echo \WaxStore\Catalog\Front\Blocks::details( $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Blocks.
