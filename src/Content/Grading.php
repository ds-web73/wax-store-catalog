<?php
/**
 * Condition grading (Goldmine standard or free text).
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Grading scales.
 */
final class Grading {

	const OPTION        = 'waxsc_grading';
	const MODE_GOLDMINE = 'goldmine';
	const MODE_FREE     = 'free';

	/**
	 * Active grading mode.
	 */
	public static function mode(): string {
		$mode = (string) get_option( self::OPTION, self::MODE_GOLDMINE );

		return self::MODE_FREE === $mode ? self::MODE_FREE : self::MODE_GOLDMINE;
	}

	/**
	 * Goldmine grades for the media (record, CD, tape). The code comes first
	 * so it stays readable in narrow fields.
	 *
	 * @return array<string,string> Grade code => label.
	 */
	public static function media_grades(): array {
		return array(
			'M'   => __( 'M · Mint', 'wax-store-catalog' ),
			'NM'  => __( 'NM · Near Mint (M-)', 'wax-store-catalog' ),
			'VG+' => __( 'VG+ · Very Good Plus', 'wax-store-catalog' ),
			'VG'  => __( 'VG · Very Good', 'wax-store-catalog' ),
			'G+'  => __( 'G+ · Good Plus', 'wax-store-catalog' ),
			'G'   => __( 'G · Good', 'wax-store-catalog' ),
			'F'   => __( 'F · Fair', 'wax-store-catalog' ),
			'P'   => __( 'P · Poor', 'wax-store-catalog' ),
		);
	}

	/**
	 * Goldmine grades for the sleeve, plus generic sleeve and no sleeve.
	 *
	 * @return array<string,string> Grade code => label.
	 */
	public static function sleeve_grades(): array {
		return self::media_grades() + array(
			'generic' => __( 'Generic sleeve', 'wax-store-catalog' ),
			'none'    => __( 'No sleeve', 'wax-store-catalog' ),
		);
	}

	/**
	 * Sanitizes a grade according to the active mode.
	 *
	 * @param mixed $value  Raw value.
	 * @param bool  $sleeve Whether the grade applies to the sleeve.
	 */
	public static function sanitize( $value, bool $sleeve = false ): string {
		$value = trim( (string) $value );

		if ( self::MODE_FREE === self::mode() ) {
			return mb_substr( sanitize_text_field( $value ), 0, 60 );
		}

		$grades = $sleeve ? self::sleeve_grades() : self::media_grades();

		return isset( $grades[ $value ] ) ? $value : '';
	}
}
