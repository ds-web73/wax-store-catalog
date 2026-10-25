<?php
/**
 * Copies: the physical items for sale under a release.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

use WaxStore\Catalog\Commerce\ProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * A release holds one or more copies, each with its own grades, price and status.
 * New stock is usually a single copy with a quantity.
 */
final class Copies {

	/**
	 * Empty copy.
	 */
	public static function empty_row(): array {
		return array(
			'id'      => '',
			'ext'     => '',
			'media'   => '',
			'sleeve'  => '',
			'variant' => '',
			'note'    => '',
			'location' => '',
			'price'   => '',
			'qty'     => 1,
			'status'  => ProviderInterface::IN_STOCK,
		);
	}

	/**
	 * Returns all copies of a release.
	 *
	 * @param int $post_id Release ID.
	 */
	public static function get( int $post_id ): array {
		$copies = get_post_meta( $post_id, ReleaseMeta::COPIES, true );

		return is_array( $copies ) ? self::sanitize( $copies ) : array();
	}

	/**
	 * Copies that can be bought or reserved right now.
	 *
	 * @param int $post_id Release ID.
	 */
	public static function get_available( int $post_id ): array {
		return array_values(
			array_filter(
				self::get( $post_id ),
				static function ( array $copy ) {
					return ProviderInterface::IN_STOCK === $copy['status'] && $copy['qty'] > 0;
				}
			)
		);
	}

	/**
	 * Aggregated availability of a release.
	 *
	 * @param int $post_id Release ID.
	 */
	public static function availability( int $post_id ): string {
		$reserved = false;

		foreach ( self::get( $post_id ) as $copy ) {
			if ( $copy['qty'] < 1 ) {
				continue;
			}

			if ( ProviderInterface::IN_STOCK === $copy['status'] ) {
				return ProviderInterface::IN_STOCK;
			}

			if ( ProviderInterface::RESERVED === $copy['status'] ) {
				$reserved = true;
			}
		}

		return $reserved ? ProviderInterface::RESERVED : ProviderInterface::SOLD;
	}

	/**
	 * Prices of available copies, ascending, copies without price ignored.
	 *
	 * @param int $post_id Release ID.
	 * @return string[]
	 */
	public static function available_prices( int $post_id ): array {
		$prices = array_filter( wp_list_pluck( self::get_available( $post_id ), 'price' ), 'strlen' );
		usort(
			$prices,
			static function ( $a, $b ) {
				return (float) $a <=> (float) $b;
			}
		);

		return array_values( $prices );
	}

	/**
	 * Sanitizes copies. Fully empty rows are dropped, each copy gets a stable ID.
	 *
	 * @param mixed $copies Raw rows.
	 */
	public static function sanitize( $copies ): array {
		$clean    = array();
		$statuses = array( ProviderInterface::IN_STOCK, ProviderInterface::RESERVED, ProviderInterface::SOLD );

		foreach ( (array) $copies as $copy ) {
			if ( ! is_array( $copy ) ) {
				continue;
			}

			$row = array(
				'id'      => sanitize_key( $copy['id'] ?? '' ),
				'ext'     => mb_substr( sanitize_text_field( $copy['ext'] ?? '' ), 0, 64 ),
				'media'   => Grading::sanitize( $copy['media'] ?? '' ),
				'sleeve'  => Grading::sanitize( $copy['sleeve'] ?? '', true ),
				'variant' => mb_substr( self::clean_text( $copy['variant'] ?? '' ), 0, 60 ),
				'note'    => mb_substr( self::clean_text( $copy['note'] ?? '' ), 0, 500 ),
				'location' => mb_substr( self::clean_text( $copy['location'] ?? '' ), 0, 80 ),
				'price'   => self::sanitize_price( $copy['price'] ?? '' ),
				'qty'     => max( 0, (int) ( $copy['qty'] ?? 1 ) ),
				'status'  => in_array( $copy['status'] ?? '', $statuses, true ) ? $copy['status'] : ProviderInterface::IN_STOCK,
			);

			if ( '' === $row['media'] . $row['sleeve'] . $row['variant'] . $row['note'] . $row['price'] ) {
				continue;
			}

			if ( '' === $row['id'] ) {
				$row['id'] = strtolower( wp_generate_password( 10, false, false ) );
			}

			$clean[] = $row;
		}

		return $clean;
	}

	/**
	 * Localized price with currency symbol. Whole amounts drop their decimals,
	 * like hand-written price stickers: "25 €", "12,50 €".
	 *
	 * @param string $price Machine price ("25.00").
	 */
	public static function format_price( string $price ): string {
		$symbols  = array(
			'EUR' => '€',
			'USD' => '$',
			'GBP' => '£',
			'CHF' => 'CHF',
			'JPY' => '¥',
		);
		$currency = strtoupper( (string) get_option( 'waxsc_currency', 'EUR' ) );
		$currency = preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'EUR';
		$symbol   = $symbols[ $currency ] ?? $currency;
		$amount   = (float) $price;
		$decimals = ( abs( $amount - round( $amount ) ) < 0.005 ) ? 0 : 2;
		$format   = self::price_format();

		if ( 'CHF' === $symbol || strlen( $symbol ) === 3 ) {
			$format = str_replace( '%2$s%1$s', "%2\$s\u{00A0}%1\$s", $format );
		}

		return sprintf( $format, number_format_i18n( $amount, $decimals ), $symbol );
	}

	/**
	 * Plain text from imported or typed values: decodes entities such as &quot;
	 * (Discogs exports encode quotes, sometimes without the final semicolon), then strips tags.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function clean_text( $value ): string {
		$value = (string) $value;

		// Some exports drop the final semicolon ("&quotPop&quot") or encode twice ("&amp;quot;").
		for ( $pass = 0; $pass < 2 && false !== strpos( $value, '&' ); $pass++ ) {
			$value = (string) preg_replace( '/&(quot|amp|apos|lt|gt|nbsp|#0*39|#0*34|#x0*27|#x0*22)(?!;)/i', '&$1;', $value );
			$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Price format: currency symbol before the amount ($12.50) in English and a
	 * few other languages, after it (12,50 €) elsewhere. Adjust with the
	 * "waxsc_price_format" filter: %1$s is the amount, %2$s the symbol.
	 */
	public static function price_format(): string {
		$before = (bool) preg_match( '/^(en|ja|zh|ko|he|th)(_|$)/', determine_locale() );
		$format = $before ? '%2$s%1$s' : "%1\$s\u{00A0}%2\$s";

		return (string) apply_filters( 'waxsc_price_format', $format, determine_locale() );
	}

	/**
	 * Accepts "12,5" or "12.50", returns "12.50". Empty means price on request.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_price( $value ): string {
		$value = str_replace( ',', '.', trim( (string) $value ) );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		return number_format( max( 0, (float) $value ), 2, '.', '' );
	}
}
