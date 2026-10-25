<?php
/**
 * Server rendering of the waxsc/reservation-form block.
 *
 * @package WaxStore\Catalog
 */

defined( 'ABSPATH' ) || exit;

echo \WaxStore\Catalog\Reservation\Form::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Form.
