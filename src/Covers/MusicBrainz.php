<?php
/**
 * Minimal MusicBrainz and Cover Art Archive client.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Covers;

use const WaxStore\Catalog\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * MusicBrainz asks for one request per second and a meaningful User-Agent:
 * https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting
 */
final class MusicBrainz {

	const API    = 'https://musicbrainz.org/ws/2/';
	const CAA    = 'https://coverartarchive.org/';
	const LAST   = 'waxsc_mb_last_request';
	const DELAY  = 1.1;

	/**
	 * User-Agent identifying the plugin and the site, as MusicBrainz requires.
	 */
	public static function user_agent(): string {
		return 'WaxStoreCatalog/' . VERSION . ' ( ' . home_url( '/' ) . ' )';
	}

	/**
	 * GET a JSON resource of the MusicBrainz API, respecting the rate limit.
	 *
	 * @param string $path  Path after /ws/2/.
	 * @param array  $query Query arguments.
	 * @return array|\WP_Error Decoded JSON, or an error ("not_found", "rate", "http").
	 */
	public static function get( string $path, array $query ) {
		$last = (float) get_option( self::LAST, 0 );
		$wait = self::DELAY - ( microtime( true ) - $last );

		if ( $wait > 0 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}

		update_option( self::LAST, (string) microtime( true ), false );

		$response = wp_remote_get(
			add_query_arg( array_merge( $query, array( 'fmt' => 'json' ) ), self::API . $path ),
			array(
				'timeout'    => 15,
				'user-agent' => self::user_agent(),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			return new \WP_Error( 'not_found', 'Not found' );
		}

		if ( 503 === $code || 429 === $code ) {
			return new \WP_Error( 'rate', 'Rate limited' );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return ( 200 === $code && is_array( $data ) ) ? $data : new \WP_Error( 'http', 'HTTP ' . $code );
	}

	/**
	 * Escapes a value for a Lucene phrase query.
	 *
	 * @param string $value Raw value.
	 */
	public static function phrase( string $value ): string {
		return '"' . addcslashes( trim( $value ), '"\\' ) . '"';
	}

	/**
	 * Finds the MusicBrainz release of a pressing.
	 *
	 * @param array $release discogs, barcode, catno, artist.
	 * @return array{mbid:string,group:string,by:string}|\WP_Error
	 */
	public static function find( array $release ) {
		if ( ! empty( $release['discogs'] ) ) {
			$data = self::get(
				'url',
				array(
					'resource' => 'https://www.discogs.com/release/' . (int) $release['discogs'],
					'inc'      => 'release-rels',
				)
			);

			if ( is_wp_error( $data ) && 'not_found' !== $data->get_error_code() ) {
				return $data;
			}

			foreach ( is_array( $data ) ? (array) ( $data['relations'] ?? array() ) : array() as $relation ) {
				if ( ! empty( $relation['release']['id'] ) ) {
					return array(
						'mbid'  => (string) $relation['release']['id'],
						'group' => '',
						'by'    => 'discogs',
					);
				}
			}
		}

		$queries = array();

		if ( ! empty( $release['barcode'] ) ) {
			$queries['barcode'] = 'barcode:' . preg_replace( '/\D/', '', (string) $release['barcode'] );
		}

		if ( ! empty( $release['catno'] ) && ! empty( $release['artist'] ) ) {
			$queries['catno'] = 'catno:' . self::phrase( $release['catno'] ) . ' AND artist:' . self::phrase( $release['artist'] );
		}

		foreach ( $queries as $by => $query ) {
			$data = self::get(
				'release/',
				array(
					'query' => $query,
					'limit' => 5,
				)
			);

			if ( is_wp_error( $data ) ) {
				if ( 'not_found' === $data->get_error_code() ) {
					continue;
				}

				return $data;
			}

			foreach ( (array) ( $data['releases'] ?? array() ) as $candidate ) {
				$score = (int) ( $candidate['score'] ?? 0 );

				if ( $score < ( 'barcode' === $by ? 100 : 90 ) || empty( $candidate['id'] ) ) {
					continue;
				}

				if ( 'catno' === $by && ! self::same_artist( $candidate, (string) $release['artist'] ) ) {
					continue;
				}

				return array(
					'mbid'  => (string) $candidate['id'],
					'group' => (string) ( $candidate['release-group']['id'] ?? '' ),
					'by'    => $by,
				);
			}
		}

		return new \WP_Error( 'not_found', 'No match' );
	}

	/**
	 * Release group of a release (needed when found through the Discogs link).
	 *
	 * @param string $mbid Release MBID.
	 */
	public static function group( string $mbid ): string {
		$data = self::get( 'release/' . rawurlencode( $mbid ), array( 'inc' => 'release-groups' ) );

		return is_array( $data ) ? (string) ( $data['release-group']['id'] ?? '' ) : '';
	}

	/**
	 * Loose artist comparison: "The Beatles" matches "Beatles, The".
	 *
	 * @param array  $candidate Search result.
	 * @param string $artist    Our artist name.
	 */
	private static function same_artist( array $candidate, string $artist ): bool {
		$normalize = static function ( $name ) {
			$name = strtolower( remove_accents( (string) $name ) );
			$name = (string) preg_replace( '/\b(the|les|le|la)\b/', '', $name );
			return (string) preg_replace( '/[^a-z0-9]/', '', $name );
		};

		$ours  = $normalize( $artist );
		$names = array();

		foreach ( (array) ( $candidate['artist-credit'] ?? array() ) as $credit ) {
			$names[] = is_array( $credit ) ? ( $credit['name'] ?? ( $credit['artist']['name'] ?? '' ) ) : '';
		}

		$theirs = $normalize( implode( ' ', $names ) );

		return '' !== $ours && '' !== $theirs && ( false !== strpos( $theirs, $ours ) || false !== strpos( $ours, $theirs ) );
	}

	/**
	 * Downloads a front cover from the Cover Art Archive to a temporary file.
	 *
	 * @param string $type "release" or "release-group".
	 * @param string $mbid MBID.
	 * @return string|\WP_Error Temporary file path, or "not_found".
	 */
	public static function download_front( string $type, string $mbid ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		foreach ( array( 'front-1200', 'front' ) as $size ) {
			$url  = self::CAA . $type . '/' . rawurlencode( $mbid ) . '/' . $size;
			$head = wp_remote_head(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'user-agent'  => self::user_agent(),
				)
			);

			if ( is_wp_error( $head ) ) {
				return new \WP_Error( 'http', $head->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $head );

			if ( 404 === $code ) {
				continue;
			}

			$tmp = download_url( $url, 30 );

			if ( is_wp_error( $tmp ) ) {
				continue;
			}

			if ( ! wp_get_image_mime( $tmp ) ) {
				wp_delete_file( $tmp );
				continue;
			}

			return $tmp;
		}

		return new \WP_Error( 'not_found', 'No front cover' );
	}
}
