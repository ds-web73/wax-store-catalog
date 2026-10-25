<?php
/**
 * How artists are filed, as in the bins: leading definite articles are ignored.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Filing rules shared by the A to Z index and the alphabetical sort.
 */
final class Filing {

	/**
	 * Name without its leading definite article and accents: "The Beatles" => "Beatles".
	 *
	 * @param string $name Artist name.
	 */
	public static function name( string $name ): string {
		/**
		 * Leading definite articles ignored when filing artists.
		 *
		 * @param string[] $articles Lowercase articles, with their trailing space or apostrophe.
		 */
		$articles = (array) apply_filters( 'waxsc_filing_articles', array( 'the ', 'les ', 'le ', 'la ', "l'", 'l’', 'los ', 'las ', 'el ', 'il ', 'lo ', 'gli ', 'die ', 'der ', 'das ' ) );
		$name     = trim( remove_accents( $name ) );
		$lower    = strtolower( $name );

		foreach ( $articles as $article ) {
			if ( 0 === strpos( $lower, $article ) && strlen( $name ) > strlen( $article ) ) {
				return ltrim( substr( $name, strlen( $article ) ) );
			}
		}

		return $name;
	}

	/**
	 * Letter an artist is filed under, "#" for digits and symbols.
	 *
	 * @param string $name Artist name.
	 */
	public static function letter( string $name ): string {
		$first = strtoupper( substr( self::name( $name ), 0, 1 ) );

		return ( $first >= 'A' && $first <= 'Z' ) ? $first : '#';
	}
}
