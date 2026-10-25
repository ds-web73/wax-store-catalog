<?php
/**
 * Matches photo file names to releases.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Import;

use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\ReleaseMeta;

defined( 'ABSPATH' ) || exit;

/**
 * "SHVL795.jpg" is the cover of the release with catalog number "SHVL 795";
 * "SHVL795-2.jpg" or "SHVL795_verso.jpg" go to its gallery.
 */
final class PhotoMatcher {

	const CACHE = 'waxsc_photo_index';

	/**
	 * Words marking an extra photo, with their sort position.
	 */
	const ROLES = array(
		'recto'   => 1,
		'front'   => 1,
		'cover'   => 1,
		'verso'   => 50,
		'back'    => 50,
		'dos'     => 50,
		'label'   => 60,
		'labels'  => 60,
		'rond'    => 60,
		'macaron' => 60,
		'inner'   => 70,
		'insert'  => 70,
		'sleeve'  => 70,
		'pochette' => 70,
		'detail'  => 80,
	);

	/**
	 * Lowercase letters and digits only: "SHVL 795" => "shvl795".
	 *
	 * @param string $value Raw value.
	 */
	public static function normalize( string $value ): string {
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( $value ) ) );
	}

	/**
	 * Splits a file name into its key and position: "SHVL795-2.jpg" => ["SHVL795", 2].
	 *
	 * @param string $filename File name.
	 * @return array{0:string,1:int}
	 */
	public static function parse( string $filename ): array {
		$stem = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', basename( $filename ) );
		$stem = trim( $stem );

		if ( preg_match( '/^(.+?)[\s_-]*\((\d{1,2})\)$/', $stem, $m ) || preg_match( '/^(.+?)[\s_-]+(\d{1,2})$/', $stem, $m ) ) {
			return array( $m[1], max( 1, (int) $m[2] ) );
		}

		$words = implode( '|', array_map( 'preg_quote', array_keys( self::ROLES ) ) );

		if ( preg_match( '/^(.+?)[\s_-]+(' . $words . ')$/i', $stem, $m ) ) {
			return array( $m[1], self::ROLES[ strtolower( $m[2] ) ] );
		}

		return array( $stem, 1 );
	}

	/**
	 * Builds the lookup index of all releases (cached for the import session).
	 *
	 * @param bool $fresh Rebuild.
	 */
	public static function index( bool $fresh = false ): array {
		$cached = $fresh ? false : get_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$index = array(
			'listing' => array(),
			'discogs' => array(),
			'catno'   => array(),
			'barcode' => array(),
			'slug'    => array(),
		);

		$ids = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;

			foreach ( Copies::get( $id ) as $copy ) {
				if ( '' !== $copy['ext'] ) {
					$index['listing'][ self::normalize( $copy['ext'] ) ][] = $id;
				}
			}

			$discogs = (int) get_post_meta( $id, ReleaseMeta::DISCOGS_ID, true );

			if ( $discogs ) {
				$index['discogs'][ (string) $discogs ][] = $id;
			}

			$catno = self::normalize( (string) get_post_meta( $id, ReleaseMeta::CATALOG_NUMBER, true ) );

			if ( '' !== $catno ) {
				$index['catno'][ $catno ][] = $id;
			}

			$barcode = (string) get_post_meta( $id, ReleaseMeta::BARCODE, true );

			if ( '' !== $barcode ) {
				$index['barcode'][ $barcode ][] = $id;
			}

			$index['slug'][ self::normalize( (string) get_post_field( 'post_name', $id ) ) ][] = $id;
		}

		set_transient( self::CACHE, $index, HOUR_IN_SECONDS );

		return $index;
	}

	/**
	 * Finds the release of a file.
	 *
	 * @param string $filename File name.
	 * @return array{status:string,release:int,position:int,key:string,by:string}
	 */
	public static function match( string $filename ): array {
		list( $key, $position ) = self::parse( $filename );

		$result = self::lookup( $key, $position );

		// "LP 12.jpg" may be a catalog number ending with digits, not photo n°12.
		if ( 'none' === $result['status'] && 1 !== $position ) {
			$whole = trim( (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', basename( $filename ) ) );
			$retry = self::lookup( $whole, 1 );

			if ( 'none' !== $retry['status'] ) {
				return $retry;
			}
		}

		return $result;
	}

	/**
	 * Looks a key up in the index.
	 *
	 * @param string $key      File key.
	 * @param int    $position Photo position.
	 * @return array{status:string,release:int,position:int,key:string,by:string}
	 */
	private static function lookup( string $key, int $position ): array {
		$normal = self::normalize( $key );
		$index  = self::index();
		$result = array(
			'status'   => 'none',
			'release'  => 0,
			'position' => $position,
			'key'      => $key,
			'by'       => '',
		);

		if ( '' === $normal ) {
			return $result;
		}

		$sources = array(
			'listing' => $normal,
			'discogs' => ctype_digit( $normal ) ? ltrim( $normal, '0' ) : '',
			'catno'   => $normal,
			'barcode' => ctype_digit( $normal ) ? $normal : '',
			'slug'    => $normal,
		);

		foreach ( $sources as $source => $value ) {
			if ( '' === $value || empty( $index[ $source ][ $value ] ) ) {
				continue;
			}

			$ids = array_values( array_unique( $index[ $source ][ $value ] ) );

			if ( count( $ids ) > 1 ) {
				$result['status'] = 'ambiguous';
				$result['by']     = $source;
				return $result;
			}

			$result['status']  = 'found';
			$result['release'] = $ids[0];
			$result['by']      = $source;

			return $result;
		}

		return $result;
	}
}
