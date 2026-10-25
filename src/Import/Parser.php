<?php
/**
 * Value parsers for imported rows (Discogs conventions and free spreadsheets).
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Import;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Grading;

defined( 'ABSPATH' ) || exit;

/**
 * Parsers.
 */
final class Parser {

	/**
	 * Removes the Discogs disambiguation suffix: "Nirvana (2)" => "Nirvana".
	 *
	 * @param string $name Artist or label name.
	 */
	public static function clean_name( string $name ): string {
		return trim( (string) preg_replace( '/\s*\(\d+\)$/', '', trim( $name ) ) );
	}

	/**
	 * Splits "Jazz, Soul" or "Jazz; Soul" or "Jazz | Soul".
	 *
	 * @param string $value Raw list.
	 * @return string[]
	 */
	public static function split_list( string $value ): array {
		return array_values( array_filter( array_map( 'trim', (array) preg_split( '/[,;|]/', $value ) ), 'strlen' ) );
	}

	/**
	 * Maps a format string ("2xLP, Album, RE", "Vinyle 45 tours", "K7") to a format term key.
	 *
	 * @param string $format Raw format.
	 */
	public static function format_key( string $format ): string {
		$s = ' ' . strtolower( remove_accents( $format ) ) . ' ';

		// Inch marks as typed by people and tools: ", '', ″, ”, “, inch, pouces.
		$inch  = '\s*(?:"|\'\'|\xE2\x80\xB3|\xE2\x80\x9D|\xE2\x80\x9C|inch|in\b|pouces?)';
		$multi = '(?:\d+\s*(?:x|\xC3\x97)\s*)?';

		$vinyl = (bool) preg_match( '/\b' . $multi . 'lp\b|vinyl|(?<!\d)(?:7|10|12)' . $inch . '|\b(?:33|45|78)\s*(?:t|tours|rpm)\b/', $s );

		$rules = array(
			'vinyl-box'    => $vinyl && preg_match( '/box\s*set|coffret/', $s ),
			'vinyl-lp'     => preg_match( '/\b' . $multi . 'lp\b|\b33\s*(?:t|tours|rpm)\b/', $s ),
			'vinyl-single' => preg_match( '/(?<!\d)7' . $inch . '|\b45\s*(?:t|tours|rpm)\b.*\bsingle\b|\bsingle\b.*\b45/', $s ),
			'vinyl-maxi'   => preg_match( '/(?<!\d)12' . $inch . '|\bmaxi\b/', $s ),
			'vinyl-10'     => preg_match( '/(?<!\d)10' . $inch . '/', $s ),
			'vinyl'        => $vinyl,
			'cd'           => preg_match( '/\b' . $multi . '(?:cd|cdr|sacd)\b/', $s ),
			'dvd'          => preg_match( '/\bdvd\b/', $s ),
			'blu-ray'      => preg_match( '/blu\s*-?\s*ray/', $s ),
			'cassette'     => preg_match( '/\bcass(?:ette)?\b|\bk7\b/', $s ),
			'merch-tshirt' => preg_match( '/t\s*-?\s*shirt|\btee\b/', $s ),
			'merch-poster' => preg_match( '/poster|affiche/', $s ),
			'merchandise'  => preg_match( '/merch|goodies/', $s ),
		);

		foreach ( $rules as $key => $match ) {
			if ( $match ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Detects edition features in a Discogs-style format string
	 * ("LP, Album, Ltd, Num, Red", "Picture Disc", "Record Store Day"…).
	 *
	 * @param string $format Raw format.
	 * @return string[] Feature keys.
	 */
	public static function feature_keys( string $format ): array {
		$s      = strtolower( remove_accents( $format ) );
		$tokens = array_map( 'trim', explode( ',', $s ) );
		$keys   = array();

		$patterns = array(
			'limited'       => '/^(ltd|limited|limited edition|edition limitee)$/',
			'numbered'      => '/^(num|numbered|numerote)$/',
			'promo'         => '/^promo$/',
			'picture-disc'  => '/^(pic|picture disc|picture)$/',
			'test-pressing' => '/^(tp|test pressing)$/',
			'white-label'   => '/^(w\/lbl|white label)$/',
			'gatefold'      => '/^(gat|gatefold|pochette ouvrante)$/',
			'poster'        => '/^(poster|affiche)( inclus.*| included)?$/',
			'180g'          => '/^180\s*(g|gr|gram|gramme)s?$/',
			'rsd'           => '/^(rsd|record store day|disquaire day)(\s.*)?$/',
			'splatter'      => '/(splatter|marbled|marble|swirl)/',
			'colored'       => '/(colou?red|couleur|clear|transparent|translucent|^red$|^blue$|^green$|^yellow$|^orange$|^purple$|^pink$|^gold$|^silver$)/',
		);

		foreach ( $tokens as $token ) {
			foreach ( $patterns as $key => $pattern ) {
				if ( preg_match( $pattern, $token ) ) {
					$keys[ $key ] = true;
				}
			}
		}

		if ( isset( $keys['splatter'] ) ) {
			$keys['colored'] = true;
		}

		return array_keys( $keys );
	}

	/**
	 * Detects a reissue or repress in the format descriptions (RE, RP, Reissue…).
	 *
	 * @param string $format Raw format.
	 */
	public static function is_reissue( string $format ): bool {
		$s = strtolower( remove_accents( $format ) );

		return (bool) preg_match( '/(^|[,(\s])(re|rp|reissue|repress|reedition)([,)\s]|$)/', $s );
	}

	/**
	 * Maps a grade ("Very Good Plus (VG+)", "VG+", "Generic", "No Cover") to a stored grade.
	 *
	 * @param string $value  Raw grade.
	 * @param bool   $sleeve Sleeve scale.
	 */
	public static function grade( string $value, bool $sleeve = false ): string {
		$value = trim( $value );

		if ( Grading::MODE_FREE === Grading::mode() ) {
			return Grading::sanitize( $value, $sleeve );
		}

		$lower = strtolower( $value );

		if ( $sleeve && 0 === strpos( $lower, 'generic' ) ) {
			return 'generic';
		}

		if ( $sleeve && ( 'no cover' === $lower || 'none' === $lower || 'sans pochette' === $lower ) ) {
			return 'none';
		}

		if ( preg_match( '/\(([^)]+)\)\s*$/', $value, $matches ) ) {
			$value = $matches[1];
		}

		$code = strtoupper( trim( (string) preg_split( '/\s+or\s+/i', $value )[0] ) );

		if ( 'M-' === $code ) {
			$code = 'NM';
		}

		return Grading::sanitize( $code, $sleeve );
	}

	/**
	 * Maps "Neuf", "New", "Sealed", "Occasion", "Used"… to a condition key, empty when unknown.
	 *
	 * @param string $value Raw condition.
	 */
	public static function condition_key( string $value ): string {
		$value = strtolower( remove_accents( trim( $value ) ) );

		if ( preg_match( '/^(new|neuf|neuve|sealed|scelle|sous blister)\b/', $value ) ) {
			return 'new';
		}

		if ( preg_match( '/^(used|occasion|second\s*hand|seconde\s*main|d\'occasion)\b/', $value ) ) {
			return 'used';
		}

		return '';
	}

	/**
	 * Maps a listing status. Anything unknown ("For Sale", "Draft"…) is in stock.
	 *
	 * @param string $value Raw status.
	 */
	public static function status( string $value ): string {
		$value = strtolower( remove_accents( trim( $value ) ) );

		if ( in_array( $value, array( 'sold', 'vendu' ), true ) ) {
			return ProviderInterface::SOLD;
		}

		if ( in_array( $value, array( 'reserved', 'reserve' ), true ) ) {
			return ProviderInterface::RESERVED;
		}

		return ProviderInterface::IN_STOCK;
	}

	/**
	 * Tracklist cell: one track per line, "position<TAB>title<TAB>duration".
	 * Lines without tabs become titles.
	 *
	 * @param string $value Cell.
	 */
	public static function tracklist( string $value ): array {
		$tracks = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$parts    = explode( "\t", $line );
			$tracks[] = 1 === count( $parts )
				? array( 'title' => $parts[0] )
				: array(
					'position' => $parts[0],
					'title'    => $parts[1] ?? '',
					'duration' => $parts[2] ?? '',
				);
		}

		return \WaxStore\Catalog\Content\Tracklist::sanitize( $tracks );
	}

	/**
	 * Converts a cell to UTF-8 (spreadsheets often save CSV as Windows-1252).
	 *
	 * @param mixed $value Raw cell.
	 */
	public static function to_utf8( $value ): string {
		$value = (string) $value;

		if ( '' === $value || preg_match( '//u', $value ) ) {
			return $value;
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $value, 'UTF-8', 'Windows-1252' );
		}

		if ( function_exists( 'iconv' ) ) {
			return (string) iconv( 'Windows-1252', 'UTF-8//IGNORE', $value );
		}

		return $value;
	}
}
