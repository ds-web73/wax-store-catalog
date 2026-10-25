<?php
/**
 * Release gallery: extra photos (back, center label, insert…) after the front cover.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Ordered list of attachment IDs.
 */
final class Gallery {

	const META = '_waxsc_gallery';

	/**
	 * Registers the meta on the release post type.
	 */
	public static function register(): void {
		register_post_meta(
			PostType::NAME,
			self::META,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * Keeps existing image attachments, without duplicates.
	 *
	 * @param mixed $ids Raw IDs.
	 * @return int[]
	 */
	public static function sanitize( $ids ): array {
		$clean = array();

		foreach ( (array) $ids as $id ) {
			$id = absint( $id );

			if ( $id && ! in_array( $id, $clean, true ) && wp_attachment_is_image( $id ) ) {
				$clean[] = $id;
			}
		}

		return $clean;
	}

	/**
	 * Gallery of a release.
	 *
	 * @param int $post_id Release ID.
	 * @return int[]
	 */
	public static function get( int $post_id ): array {
		$ids = get_post_meta( $post_id, self::META, true );

		return is_array( $ids ) ? self::sanitize( $ids ) : array();
	}

	/**
	 * Adds a photo, ordered by its position (2, 3… or back, label…).
	 *
	 * @param int $post_id       Release ID.
	 * @param int $attachment_id Attachment ID.
	 * @param int $position      Sort position.
	 */
	public static function add( int $post_id, int $attachment_id, int $position ): void {
		update_post_meta( $attachment_id, '_waxsc_position', $position );

		$ids   = self::get( $post_id );
		$ids[] = $attachment_id;

		usort(
			$ids,
			static function ( $a, $b ) {
				return (int) get_post_meta( $a, '_waxsc_position', true ) <=> (int) get_post_meta( $b, '_waxsc_position', true );
			}
		);

		update_post_meta( $post_id, self::META, $ids );
	}
}
