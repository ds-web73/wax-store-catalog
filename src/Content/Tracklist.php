<?php
/**
 * Tracklist storage.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Tracklist: ordered list of tracks (position, title, duration).
 */
final class Tracklist {

	/**
	 * Empty track.
	 */
	public static function empty_row(): array {
		return array(
			'position' => '',
			'title'    => '',
			'duration' => '',
		);
	}

	/**
	 * Returns the tracklist of a release.
	 *
	 * @param int $post_id Release ID.
	 */
	public static function get( int $post_id ): array {
		$tracks = get_post_meta( $post_id, ReleaseMeta::TRACKLIST, true );

		return is_array( $tracks ) ? self::sanitize( $tracks ) : array();
	}

	/**
	 * Sanitizes a tracklist. Rows without title are dropped.
	 *
	 * @param mixed $tracks Raw rows.
	 */
	public static function sanitize( $tracks ): array {
		$clean = array();

		foreach ( (array) $tracks as $track ) {
			if ( ! is_array( $track ) ) {
				continue;
			}

			$title = mb_substr( Copies::clean_text( $track['title'] ?? '' ), 0, 200 );

			if ( '' === $title ) {
				continue;
			}

			$clean[] = array(
				'position' => mb_substr( sanitize_text_field( $track['position'] ?? '' ), 0, 10 ),
				'title'    => $title,
				'duration' => self::sanitize_duration( $track['duration'] ?? '' ),
			);
		}

		return $clean;
	}

	/**
	 * Accepts m:ss or h:mm:ss, empty otherwise.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_duration( $value ): string {
		$value = trim( (string) $value );

		return preg_match( '/^(\d{1,2}:)?\d{1,3}:[0-5]\d$/', $value ) ? $value : '';
	}
}
