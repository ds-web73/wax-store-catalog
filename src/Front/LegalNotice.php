<?php
/**
 * [waxsc_legal_notice] shortcode: legal notice built from the shop settings.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Front;

use WaxStore\Catalog\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Legal notice. It always reflects the current settings, so updating an
 * address or a hosting provider never leaves an outdated page behind.
 */
final class LegalNotice {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_shortcode( 'waxsc_legal_notice', array( self::class, 'render' ) );
	}

	/**
	 * Shortcode output.
	 */
	public static function render(): string {
		$pro  = Settings::is_professional();
		$html = '<div class="waxsc-legal-notice">';

		$html .= self::section(
			__( 'Site publisher', 'wax-store-catalog' ),
			array(
				__( 'Name', 'wax-store-catalog' )                => $pro && '' !== Settings::get( 'identity', 'legal_name' ) ? Settings::get( 'identity', 'legal_name' ) : Settings::get( 'identity', 'owner_name' ),
				__( 'Legal form', 'wax-store-catalog' )          => $pro ? Settings::get( 'identity', 'legal_form' ) : '',
				__( 'Share capital', 'wax-store-catalog' )       => $pro ? Settings::get( 'legal', 'share_capital' ) : '',
				__( 'Address', 'wax-store-catalog' )             => $pro ? self::address() : '',
				__( 'Phone', 'wax-store-catalog' )               => $pro ? Settings::get( 'contact', 'phone' ) : '',
				__( 'Email', 'wax-store-catalog' )               => Settings::get( 'contact', 'email' ),
				__( 'Registration number', 'wax-store-catalog' ) => $pro ? trim( Settings::get( 'legal', 'registration_number' ) . ' ' . Settings::get( 'legal', 'registry' ) ) : '',
				__( 'VAT number', 'wax-store-catalog' )          => $pro ? Settings::get( 'legal', 'vat_number' ) : '',
			)
		);

		$html .= self::section(
			__( 'Publication director', 'wax-store-catalog' ),
			array(
				__( 'Name', 'wax-store-catalog' ) => Settings::get( 'legal', 'publication_director' ),
			)
		);

		$html .= self::section(
			__( 'Hosting', 'wax-store-catalog' ),
			array(
				__( 'Provider', 'wax-store-catalog' ) => Settings::get( 'host', 'host_name' ),
				__( 'Address', 'wax-store-catalog' )  => Settings::get( 'host', 'host_address' ),
				__( 'Phone', 'wax-store-catalog' )    => Settings::get( 'host', 'host_phone' ),
				__( 'Website', 'wax-store-catalog' )  => Settings::get( 'host', 'host_url' ),
			)
		);

		if ( $pro ) {
			$html .= self::section(
				__( 'Consumer mediation', 'wax-store-catalog' ),
				array(
					__( 'Mediator', 'wax-store-catalog' ) => Settings::get( 'legal', 'mediator_name' ),
					__( 'Website', 'wax-store-catalog' )  => Settings::get( 'legal', 'mediator_url' ),
				)
			);
		}

		return $html . '</div>';
	}

	/**
	 * One section, skipped when all its values are empty.
	 *
	 * @param string $title Heading.
	 * @param array  $lines Label => value.
	 */
	private static function section( string $title, array $lines ): string {
		$items = '';

		foreach ( $lines as $label => $value ) {
			$value = trim( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$display = wp_http_validate_url( $value )
				? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $value ), esc_html( $value ) )
				: nl2br( esc_html( $value ) );

			/* translators: %s: field label. French uses a space before the colon: "%s :". */
			$label  = sprintf( _x( '%s:', 'label followed by its value', 'wax-store-catalog' ), $label );
			$items .= sprintf( '<p><strong>%1$s</strong> %2$s</p>', esc_html( $label ), $display );
		}

		return '' === $items ? '' : '<h2>' . esc_html( $title ) . '</h2>' . $items;
	}

	/**
	 * Postal address on one line.
	 */
	private static function address(): string {
		$city = trim( Settings::get( 'contact', 'postal_code' ) . ' ' . Settings::get( 'contact', 'city' ) );

		return implode( ', ', array_filter( array( Settings::get( 'contact', 'street' ), $city, Settings::get( 'contact', 'country' ) ) ) );
	}
}
