<?php
/**
 * Nonces for the public forms (reservation, alerts, collection offers).
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Front;

use const WaxStore\Catalog\PLUGIN_FILE;
use const WaxStore\Catalog\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Pages holding a form are often cached, and a nonce printed in a cached
 * page expires after a day. The browser therefore asks for a fresh nonce
 * as soon as the visitor starts filling the form in.
 */
final class FormNonce {

	const FIELD   = 'waxsc_nonce';
	const ACTIONS = array( 'waxsc_reserve', 'waxsc_alert', 'waxsc_sell' );

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_waxsc_fresh_nonce', array( self::class, 'fresh' ) );
		add_action( 'wp_ajax_nopriv_waxsc_fresh_nonce', array( self::class, 'fresh' ) );
	}

	/**
	 * Hidden nonce field, refreshed by the browser.
	 *
	 * @param string $action Nonce action.
	 */
	public static function field( string $action ): string {
		wp_enqueue_script( 'waxsc-form-nonce', plugins_url( 'assets/form-nonce.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_localize_script( 'waxsc-form-nonce', 'waxscFormNonce', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );

		return '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" data-waxsc-nonce-action="' . esc_attr( $action ) . '">';
	}

	/**
	 * Whether the submitted nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	public static function verify( string $action ): bool {
		$nonce = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';

		return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Fresh nonce for one of the public form actions.
	 */
	public static function fresh(): void {
		$action = isset( $_POST['form'] ) ? sanitize_key( wp_unslash( $_POST['form'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this endpoint hands out nonces.

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			wp_send_json_error( null, 400 );
		}

		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( $action ) ) );
	}
}
