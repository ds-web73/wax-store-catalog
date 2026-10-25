<?php
/**
 * Fetches illustration covers from the Cover Art Archive, one release at a time.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Covers;

use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Content\Taxonomies;
use WaxStore\Catalog\Reservation\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Only releases without a cover are processed; each one is tried once,
 * unless a retry is asked for.
 */
final class Fetcher {

	const CHECKED = '_waxsc_caa_checked';
	const SOURCE  = '_waxsc_cover_source';

	/**
	 * Base query for releases still without cover.
	 *
	 * @param bool $retry Include releases already tried without success.
	 */
	private static function query_args( bool $retry ): array {
		$meta = array(
			array(
				'key'     => '_thumbnail_id',
				'compare' => 'NOT EXISTS',
			),
		);

		if ( ! $retry ) {
			$meta[] = array(
				'key'     => self::CHECKED,
				'compare' => 'NOT EXISTS',
			);
		}

		return array(
			'post_type'      => PostType::NAME,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin tool.
		);
	}

	/**
	 * Releases left to process.
	 *
	 * @param bool $retry Include releases already tried.
	 */
	public static function remaining( bool $retry ): int {
		$query = new \WP_Query( array_merge( self::query_args( $retry ), array( 'posts_per_page' => 1 ) ) );

		return (int) $query->found_posts;
	}

	/**
	 * Processes the next release.
	 *
	 * @param bool $retry       Include releases already tried.
	 * @param bool $other_issue Accept the cover of another edition of the album.
	 * @param int  $after       Last processed ID, so a retry run moves forward.
	 * @return array{status:string,release:int,message:string}
	 */
	public static function next( bool $retry, bool $other_issue, int $after = 0 ): array {
		$args = array_merge( self::query_args( $retry ), array( 'posts_per_page' => 1 ) );

		if ( $after ) {
			$filter = static function ( $where ) use ( $after ) {
				global $wpdb;
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
			};
			add_filter( 'posts_where', $filter );
		}

		$ids = get_posts( array_merge( $args, array( 'suppress_filters' => false ) ) );

		if ( $after ) {
			remove_filter( 'posts_where', $filter );
		}

		if ( ! $ids ) {
			return array(
				'status'  => 'done',
				'release' => 0,
				'message' => '',
			);
		}

		return self::process( (int) $ids[0], $other_issue );
	}

	/**
	 * Looks a release up and attaches its cover.
	 *
	 * @param int  $release     Release ID.
	 * @param bool $other_issue Accept another edition's cover.
	 */
	public static function process( int $release, bool $other_issue ): array {
		$artists = get_the_terms( $release, Taxonomies::ARTIST );
		$label   = Requests::release_label( $release );

		$found = MusicBrainz::find(
			array(
				'discogs' => (int) get_post_meta( $release, ReleaseMeta::DISCOGS_ID, true ),
				'barcode' => (string) get_post_meta( $release, ReleaseMeta::BARCODE, true ),
				'catno'   => (string) get_post_meta( $release, ReleaseMeta::CATALOG_NUMBER, true ),
				'artist'  => is_array( $artists ) && $artists ? $artists[0]->name : '',
			)
		);

		if ( is_wp_error( $found ) ) {
			return self::result( $release, $found->get_error_code(), $label );
		}

		$file   = MusicBrainz::download_front( 'release', $found['mbid'] );
		$source = 'caa';

		if ( is_wp_error( $file ) && 'not_found' === $file->get_error_code() && $other_issue ) {
			$group = '' !== $found['group'] ? $found['group'] : MusicBrainz::group( $found['mbid'] );

			if ( '' !== $group ) {
				$file   = MusicBrainz::download_front( 'release-group', $group );
				$source = 'caa-group';
			}
		}

		if ( is_wp_error( $file ) ) {
			return self::result( $release, 'not_found' === $file->get_error_code() ? 'no_cover' : 'http', $label );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
		$mime       = (string) wp_get_image_mime( $file );
		/* translators: %s: "Artist – Title". */
		$alt        = sprintf( __( '%s, cover', 'wax-store-catalog' ), $label );
		$attachment = media_handle_sideload(
			array(
				'name'     => 'cover-' . sanitize_title( $label ) . '.' . ( $extensions[ $mime ] ?? 'jpg' ),
				'tmp_name' => $file,
			),
			$release,
			$alt
		);

		if ( is_wp_error( $attachment ) ) {
			wp_delete_file( $file );
			return self::result( $release, 'http', $label );
		}

		update_post_meta( $attachment, '_wp_attachment_image_alt', $alt );
		update_post_meta( $attachment, self::SOURCE, $source );
		set_post_thumbnail( $release, $attachment );

		return self::result( $release, 'caa-group' === $source ? 'found_group' : 'found', $label );
	}

	/**
	 * Records the attempt and builds the message.
	 *
	 * @param int    $release Release ID.
	 * @param string $status  Status code.
	 * @param string $label   "Artist – Title".
	 */
	private static function result( int $release, string $status, string $label ): array {
		// Network errors and rate limits are not recorded: the release is tried again.
		if ( ! in_array( $status, array( 'http', 'rate' ), true ) ) {
			update_post_meta( $release, self::CHECKED, gmdate( 'Y-m-d' ) );
		}

		$messages = array(
			'found'       => __( 'Cover found', 'wax-store-catalog' ),
			'found_group' => __( 'Cover of another edition of the album', 'wax-store-catalog' ),
			'not_found'   => __( 'Not found on MusicBrainz', 'wax-store-catalog' ),
			'no_cover'    => __( 'Found on MusicBrainz, but without cover', 'wax-store-catalog' ),
			'http'        => __( 'Connection problem, will be retried', 'wax-store-catalog' ),
			'rate'        => __( 'MusicBrainz asks to slow down, will be retried', 'wax-store-catalog' ),
		);

		return array(
			'status'  => $status,
			'release' => $release,
			/* translators: 1: "Artist – Title", 2: result. */
			'message' => sprintf( __( '%1$s: %2$s', 'wax-store-catalog' ), $label, $messages[ $status ] ?? $status ),
		);
	}

	/**
	 * Whether the cover of a release is an illustration from the archive.
	 *
	 * @param int $release Release ID.
	 */
	public static function is_illustration( int $release ): bool {
		$thumbnail = (int) get_post_thumbnail_id( $release );

		return $thumbnail && 0 === strpos( (string) get_post_meta( $thumbnail, self::SOURCE, true ), 'caa' );
	}
}
