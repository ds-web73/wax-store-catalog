<?php
/**
 * Server rendering of the waxsc/sell-form block.
 *
 * @package WaxStore\Catalog
 */

defined( 'ABSPATH' ) || exit;

echo \WaxStore\Catalog\Buying\SellForm::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in SellForm.
