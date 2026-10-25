<?php
/**
 * Copies sold on the site that still have a Discogs listing.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Discogs;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Reservation\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the CSV expected by the Discogs inventory upload, delete mode:
 * a single "listing_id" column.
 */
final class Export {

	const META    = '_waxsc_discogs_exported';
	const HISTORY = 'waxsc_discogs_exports';

	/**
	 * Listings to remove from Discogs.
	 *
	 * @param bool $with_reserved Include reserved copies.
	 * @return array<int,array{listing:string,release:int,label:string,copy:string,status:string}>
	 */
	public static function pending( bool $with_reserved = false ): array {
		$statuses = $with_reserved ? array( ProviderInterface::SOLD, ProviderInterface::RESERVED ) : array( ProviderInterface::SOLD );
		$pending  = array();
		$ids      = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $release ) {
			$release  = (int) $release;
			$exported = self::exported( $release );

			foreach ( Copies::get( $release ) as $copy ) {
				$sold = ProviderInterface::SOLD === $copy['status'] || ( ProviderInterface::IN_STOCK === $copy['status'] && $copy['qty'] < 1 );
				$keep = $sold || in_array( $copy['status'], $statuses, true );

				if ( '' === $copy['ext'] || ! $keep || in_array( $copy['ext'], $exported, true ) ) {
					continue;
				}

				$pending[] = array(
					'listing' => $copy['ext'],
					'release' => $release,
					'label'   => Requests::release_label( $release ),
					'copy'    => trim( implode( ' / ', array_filter( array( $copy['media'], $copy['sleeve'] ) ) ) . ( '' !== $copy['price'] ? ', ' . Copies::format_price( $copy['price'] ) : '' ), ', ' ),
					'status'  => $sold ? ProviderInterface::SOLD : $copy['status'],
					'location' => $copy['location'],
				);
			}
		}

		return $pending;
	}

	/**
	 * Listing IDs already exported for a release.
	 *
	 * @param int $release Release ID.
	 * @return string[]
	 */
	public static function exported( int $release ): array {
		$ids = get_post_meta( $release, self::META, true );

		return is_array( $ids ) ? array_map( 'strval', $ids ) : array();
	}

	/**
	 * Remembers listings as handled (exported, or already gone from Discogs).
	 *
	 * @param int      $release  Release ID.
	 * @param string[] $listings Listing IDs.
	 */
	public static function mark_exported( int $release, array $listings ): void {
		$ids = array_values( array_unique( array_merge( self::exported( $release ), array_map( 'strval', $listings ) ) ) );
		update_post_meta( $release, self::META, $ids );
	}

	/**
	 * Records an export and marks its listings, returns the CSV.
	 *
	 * @param array $pending Pending rows.
	 */
	public static function create( array $pending ): string {
		$by_release = array();

		foreach ( $pending as $row ) {
			$by_release[ $row['release'] ][] = $row['listing'];
		}

		foreach ( $by_release as $release => $listings ) {
			self::mark_exported( (int) $release, $listings );
		}

		$listings = array_values( array_unique( wp_list_pluck( $pending, 'listing' ) ) );
		$history  = get_option( self::HISTORY, array() );
		$history  = is_array( $history ) ? $history : array();

		array_unshift(
			$history,
			array(
				'date'     => time(),
				'listings' => $listings,
			)
		);

		update_option( self::HISTORY, array_slice( $history, 0, 10 ), false );

		return self::csv( $listings );
	}

	/**
	 * CSV content: header and one listing ID per line.
	 *
	 * @param string[] $listings Listing IDs.
	 */
	public static function csv( array $listings ): string {
		return "listing_id\n" . implode( "\n", array_map( 'strval', $listings ) ) . "\n";
	}

	/**
	 * Past exports, newest first.
	 */
	public static function history(): array {
		$history = get_option( self::HISTORY, array() );

		return is_array( $history ) ? $history : array();
	}
}
