<?php
/**
 * Public helpers, meant for themes (check function_exists() before calling).
 *
 * @package WaxStore\Catalog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the plugin instance.
 *
 * @return \WaxStore\Catalog\Plugin
 */
function waxsc() {
	return \WaxStore\Catalog\Plugin::instance();
}

/**
 * Returns the active commerce provider (showcase, WooCommerce, …).
 *
 * @return \WaxStore\Catalog\Commerce\ProviderInterface
 */
function waxsc_commerce() {
	return waxsc()->commerce()->get_active();
}
