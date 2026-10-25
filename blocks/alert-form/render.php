<?php
/**
 * Server rendering of the waxsc/alert-form block.
 *
 * @package WaxStore\Catalog
 */

defined( 'ABSPATH' ) || exit;

$waxsc_alert = \WaxStore\Catalog\Alerts\Form::render();

echo '' !== $waxsc_alert ? '<div ' . get_block_wrapper_attributes() . '>' . $waxsc_alert . '</div>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Form.
