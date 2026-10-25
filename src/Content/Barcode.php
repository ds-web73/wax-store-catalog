<?php
/**
 * Barcode helpers (EAN-8, UPC-A, EAN-13).
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

defined( 'ABSPATH' ) || exit;

/**
 * GTIN normalization and checksum validation.
 */
final class Barcode {

	/**
	 * Keeps digits only ("5 099902 987026" => "5099902987026").
	 *
	 * @param mixed $value Raw value.
	 */
	public static function normalize( $value ): string {
		return substr( (string) preg_replace( '/\D+/', '', (string) $value ), 0, 14 );
	}

	/**
	 * Checks length and GTIN check digit.
	 *
	 * @param string $digits Normalized barcode.
	 */
	public static function is_valid( string $digits ): bool {
		$length = strlen( $digits );

		if ( ! in_array( $length, array( 8, 12, 13 ), true ) ) {
			return false;
		}

		$sum    = 0;
		$weight = 3;

		for ( $i = $length - 2; $i >= 0; $i-- ) {
			$sum   += (int) $digits[ $i ] * $weight;
			$weight = 3 === $weight ? 1 : 3;
		}

		return ( 10 - $sum % 10 ) % 10 === (int) $digits[ $length - 1 ];
	}
}
