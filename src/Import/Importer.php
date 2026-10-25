<?php
/**
 * CSV importer, processed in small batches so large catalogs never time out.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Import;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Barcode;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Content\Taxonomies;
use WaxStore\Catalog\Content\Tracklist;

defined( 'ABSPATH' ) || exit;

/**
 * Importer. Re-importing the same file updates copies instead of duplicating them;
 * release data only fills empty fields, so manual edits are never overwritten.
 */
final class Importer {

	const STATE_OPTION = 'waxsc_import_state';
	const MAX_ERRORS   = 50;

	/**
	 * Current import state, empty array when idle.
	 */
	public static function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Saves the import state.
	 *
	 * @param array $state State.
	 */
	public static function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Cancels or clears an import and deletes the uploaded file.
	 */
	public static function reset(): void {
		$state = self::get_state();

		if ( ! empty( $state['file'] ) && is_file( $state['file'] ) ) {
			wp_delete_file( $state['file'] );
		}

		delete_option( self::STATE_OPTION );
	}

	/**
	 * Reads the header, detects the delimiter, counts rows and prepares the mapping.
	 *
	 * @param string $file Uploaded CSV path.
	 * @return array|\WP_Error New state.
	 */
	public static function analyze( string $file ) {
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a large CSV.

		if ( ! $handle ) {
			return new \WP_Error( 'waxsc_unreadable', __( 'The file could not be read.', 'wax-store-catalog' ) );
		}

		$first = (string) fgets( $handle );
		$bom   = 0 === strpos( $first, "\xEF\xBB\xBF" ) ? 3 : 0;
		$line  = substr( $first, $bom );

		$counts = array(
			','  => substr_count( $line, ',' ),
			';'  => substr_count( $line, ';' ),
			"\t" => substr_count( $line, "\t" ),
		);
		arsort( $counts );
		$delimiter = (string) key( $counts );

		fseek( $handle, $bom );
		$header = fgetcsv( $handle, 0, $delimiter, '"', '' );

		if ( ! is_array( $header ) || count( $header ) < 2 ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'waxsc_no_header', __( 'No column headers were found on the first line.', 'wax-store-catalog' ) );
		}

		$header = array_map( array( Parser::class, 'to_utf8' ), $header );
		$offset = (int) ftell( $handle );
		$sample = array();
		$total  = 0;

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( self::is_empty_row( $row ) ) {
				continue;
			}

			if ( ! $sample ) {
				$sample = array_map( array( Parser::class, 'to_utf8' ), $row );
			}

			++$total;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( 0 === $total ) {
			return new \WP_Error( 'waxsc_empty', __( 'The file contains no rows to import.', 'wax-store-catalog' ) );
		}

		return array(
			'stage'     => 'mapping',
			'file'      => $file,
			'delimiter' => $delimiter,
			'header'    => $header,
			'sample'    => $sample,
			'mapping'   => Fields::guess_mapping( $header ),
			'offset'    => $offset,
			'line'      => 0,
			'total'     => $total,
			'publish'   => false,
			'condition' => 'used',
			'missing'   => false,
			'seen'      => array(),
			'report'    => array(
				'releases_created' => 0,
				'copies_created'   => 0,
				'copies_updated'   => 0,
				'skipped'          => 0,
				'errors'           => array(),
				'unknown_formats'  => array(),
				'marked_sold'      => 0,
				'enriched'         => 0,
			),
		);
	}

	/**
	 * Imports the next rows, stopping after a row count or a time budget.
	 *
	 * @param int   $max_rows    Maximum rows per batch.
	 * @param float $max_seconds Time budget.
	 */
	public static function run_batch( int $max_rows = 50, float $max_seconds = 15.0 ): array {
		$state = self::get_state();

		if ( 'running' !== ( $state['stage'] ?? '' ) ) {
			return $state;
		}

		$handle = is_file( $state['file'] ) ? fopen( $state['file'], 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a large CSV.

		if ( ! $handle ) {
			$state['stage']                 = 'done';
			$state['report']['errors'][]    = __( 'The uploaded file is no longer available.', 'wax-store-catalog' );
			self::save_state( $state );
			return $state;
		}

		fseek( $handle, (int) $state['offset'] );
		wp_defer_term_counting( true );

		$start     = microtime( true );
		$processed = 0;
		$finished  = false;

		while ( $processed < $max_rows && ( microtime( true ) - $start ) < $max_seconds ) {
			$row = fgetcsv( $handle, 0, $state['delimiter'], '"', '' );

			if ( false === $row ) {
				$finished = true;
				break;
			}

			if ( self::is_empty_row( $row ) ) {
				continue;
			}

			++$state['line'];
			++$processed;

			$error = self::import_row( array_map( array( Parser::class, 'to_utf8' ), $row ), $state );

			if ( '' !== $error ) {
				++$state['report']['skipped'];

				if ( count( $state['report']['errors'] ) < self::MAX_ERRORS ) {
					/* translators: 1: row number, 2: error message. */
					$state['report']['errors'][] = sprintf( __( 'Row %1$d: %2$s', 'wax-store-catalog' ), $state['line'], $error );
				}
			}
		}

		$state['offset'] = (int) ftell( $handle );

		if ( ! $finished && false === fgetcsv( $handle, 0, $state['delimiter'], '"', '' ) ) {
			$finished = true;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		wp_defer_term_counting( false );

		if ( $finished ) {
			if ( ! empty( $state['missing'] ) && ! empty( $state['seen'] ) ) {
				$state['report']['marked_sold'] = self::mark_missing_sold( $state['seen'] );
			}

			$state['seen']  = array();
			$state['stage'] = 'done';
			wp_delete_file( $state['file'] );
		}

		self::save_state( $state );

		return $state;
	}

	/**
	 * Imports one row. Returns an error message, or an empty string on success.
	 *
	 * @param array $row   CSV cells.
	 * @param array $state Import state (report updated by reference).
	 */
	private static function import_row( array $row, array &$state ): string {
		$data = array();

		foreach ( $state['mapping'] as $target => $index ) {
			$data[ $target ] = ( $index >= 0 && isset( $row[ $index ] ) ) ? trim( (string) $row[ $index ] ) : '';
		}

		$title = Copies::clean_text( $data['title'] );

		if ( '' === $title ) {
			return __( 'missing title.', 'wax-store-catalog' );
		}

		$artist     = Parser::clean_name( Copies::clean_text( $data['artist'] ) );
		$catalog    = ReleaseMeta::sanitize_catalog_number( $data['catalog_number'] );
		$release_id = absint( $data['release_id'] );

		$key = $release_id
			? 'discogs:' . $release_id
			: 'row:' . md5( strtolower( $artist . '|' . $title . '|' . $catalog ) );

		$post_id  = self::find_release( $key );
		$has_copy = self::has_copy_data( $data );

		// Enrichment rows (no price, no copy): they only complete existing releases.
		if ( ! $post_id && ! $has_copy ) {
			return __( 'release not in the catalog, nothing to complete.', 'wax-store-catalog' );
		}

		if ( ! $post_id ) {
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'   => PostType::NAME,
						'post_status' => $state['publish'] ? 'publish' : 'draft',
						'post_title'  => $title,
						'post_name'   => sanitize_title( trim( $artist . ' ' . $title ) ),
					)
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id->get_error_message();
			}

			update_post_meta( $post_id, ReleaseMeta::IMPORT_KEY, $key );
			++$state['report']['releases_created'];
		}

		self::fill_meta( $post_id, ReleaseMeta::DISCOGS_ID, $release_id );
		self::fill_meta( $post_id, ReleaseMeta::CATALOG_NUMBER, $catalog );
		self::fill_meta( $post_id, ReleaseMeta::YEAR, ReleaseMeta::sanitize_year( $data['year'] ) );
		self::fill_meta( $post_id, ReleaseMeta::BARCODE, Barcode::normalize( $data['barcode'] ) );

		self::fill_terms( $post_id, Taxonomies::ARTIST, '' !== $artist ? array( $artist ) : array() );
		self::fill_terms( $post_id, Taxonomies::LABEL, array_map( array( Parser::class, 'clean_name' ), Parser::split_list( Copies::clean_text( $data['label'] ) ) ) );
		self::fill_terms( $post_id, Taxonomies::COUNTRY, Parser::split_list( Copies::clean_text( $data['country'] ) ) );
		self::fill_terms( $post_id, Taxonomies::GENRE, self::genre_ids( Parser::split_list( Copies::clean_text( $data['genre'] ) ), Parser::split_list( Copies::clean_text( $data['style'] ?? '' ) ) ) );

		$format_key = Parser::format_key( $data['format'] );

		if ( '' === $format_key && '' !== $data['format'] ) {
			self::note_unknown_format( sanitize_text_field( $data['format'] ), $state );
		}

		$format_id  = '' !== $format_key ? Taxonomies::ensure_by_key( Taxonomies::FORMAT, $format_key ) : 0;

		if ( ! $format_id && 0 === strpos( $format_key, 'vinyl-' ) ) {
			$format_id = Taxonomies::ensure_by_key( Taxonomies::FORMAT, 'vinyl' );
		}

		self::fill_terms( $post_id, Taxonomies::FORMAT, $format_id ? array( $format_id ) : array() );

		if ( Parser::is_reissue( $data['format'] ) ) {
			$edition_id = Taxonomies::ensure_by_key( Taxonomies::EDITION, 'reissue' );
			self::fill_terms( $post_id, Taxonomies::EDITION, $edition_id ? array( $edition_id ) : array() );
		}

		$features = array_filter(
			array_map(
				static function ( $key ) {
					return Taxonomies::ensure_by_key( Taxonomies::FEATURE, $key );
				},
				Parser::feature_keys( $data['format'] )
			)
		);
		self::fill_terms( $post_id, Taxonomies::FEATURE, $features );

		$tracks = Parser::tracklist( (string) ( $data['tracklist'] ?? '' ) );

		if ( $tracks && ! Tracklist::get( $post_id ) ) {
			update_post_meta( $post_id, ReleaseMeta::TRACKLIST, $tracks );
		}

		if ( ! $has_copy ) {
			++$state['report']['enriched'];
			return '';
		}

		$condition = Parser::condition_key( $data['condition'] );

		if ( '' === $condition ) {
			$condition = $state['condition'];
		}

		if ( '' !== $condition ) {
			$condition_id = Taxonomies::ensure_by_key( Taxonomies::CONDITION, $condition );
			self::fill_terms( $post_id, Taxonomies::CONDITION, $condition_id ? array( $condition_id ) : array() );
		}

		self::upsert_copy( $post_id, $data, $state );

		return '';
	}

	/**
	 * Adds or updates the copy described by the row. Copies are matched by their
	 * external ID, or by grades and variant when the file has no ID column.
	 *
	 * @param int   $post_id Release ID.
	 * @param array $data    Mapped row.
	 * @param array $state   Import state (report updated by reference).
	 */
	private static function upsert_copy( int $post_id, array $data, array &$state ): void {
		$listing = Copies::clean_text( $data['listing_id'] );

		if ( '' !== $listing ) {
			$state['seen'][] = $listing;
		}

		$status = Parser::status( $data['status'] );
		$qty    = '' !== $data['quantity'] ? max( 0, (int) $data['quantity'] ) : 1;

		$incoming = array(
			'ext'     => Copies::clean_text( $data['listing_id'] ),
			'media'   => Parser::grade( $data['media_condition'] ),
			'sleeve'  => Parser::grade( $data['sleeve_condition'], true ),
			'variant' => Copies::clean_text( $data['variant'] ),
			'note'    => Copies::clean_text( $data['comments'] ),
			'location' => Copies::clean_text( $data['location'] ?? '' ),
			'price'   => Copies::sanitize_price( $data['price'] ),
			'qty'     => ProviderInterface::SOLD === $status ? 0 : $qty,
			'status'  => $status,
		);

		$copies = Copies::get( $post_id );
		$match  = null;

		foreach ( $copies as $index => $copy ) {
			$same = '' !== $incoming['ext']
				? $copy['ext'] === $incoming['ext']
				: ( '' === $copy['ext'] && $copy['media'] === $incoming['media'] && $copy['sleeve'] === $incoming['sleeve'] && $copy['variant'] === $incoming['variant'] );

			if ( $same ) {
				$match = $index;
				break;
			}
		}

		if ( null === $match ) {
			$copies[] = array_merge( Copies::empty_row(), $incoming );
			++$state['report']['copies_created'];
		} else {
			if ( '' === $incoming['variant'] ) {
				unset( $incoming['variant'] );
			}

			if ( '' === $incoming['location'] ) {
				unset( $incoming['location'] );
			}

			$copies[ $match ] = array_merge( $copies[ $match ], $incoming );
			++$state['report']['copies_updated'];
		}

		update_post_meta( $post_id, ReleaseMeta::COPIES, Copies::sanitize( $copies ) );
	}

	/**
	 * Genre term IDs: genres at the top level, styles as sub-genres of the first genre.
	 * Releases get the most precise terms: the styles when there are any.
	 *
	 * @param string[] $genres Genre names.
	 * @param string[] $styles Style names.
	 * @return int[]
	 */
	private static function genre_ids( array $genres, array $styles ): array {
		$genre_ids = array();

		foreach ( $genres as $name ) {
			$id = self::term_id( $name, Taxonomies::GENRE, 0 );

			if ( $id ) {
				$genre_ids[] = $id;
			}
		}

		$style_ids = array();

		foreach ( $styles as $name ) {
			$id = self::term_id( $name, Taxonomies::GENRE, $genre_ids ? $genre_ids[0] : 0 );

			if ( $id ) {
				$style_ids[] = $id;
			}
		}

		return $style_ids ? $style_ids : $genre_ids;
	}

	/**
	 * Finds or creates a term under a parent.
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $parent   Parent term ID.
	 */
	private static function term_id( string $name, string $taxonomy, int $parent ): int {
		$existing = term_exists( $name, $taxonomy, $parent );

		if ( is_array( $existing ) ) {
			return (int) $existing['term_id'];
		}

		$created = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );

		if ( is_wp_error( $created ) ) {
			$id = $created->get_error_data( 'term_exists' );
			return $id ? (int) $id : 0;
		}

		return (int) $created['term_id'];
	}

	/**
	 * Copies with a Discogs listing ID that is no longer in a full inventory
	 * export were sold on Discogs: they are marked sold here too.
	 *
	 * @param string[] $seen Listing IDs present in the file.
	 * @return int Copies marked sold.
	 */
	private static function mark_missing_sold( array $seen ): int {
		$seen   = array_flip( $seen );
		$marked = 0;
		$ids    = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $release ) {
			$copies  = Copies::get( (int) $release );
			$changed = false;

			foreach ( $copies as $index => $copy ) {
				if ( '' !== $copy['ext'] && ! isset( $seen[ $copy['ext'] ] ) && ProviderInterface::IN_STOCK === $copy['status'] ) {
					$copies[ $index ]['status'] = ProviderInterface::SOLD;
					$copies[ $index ]['qty']    = 0;
					$changed                    = true;
					++$marked;
				}
			}

			if ( $changed ) {
				update_post_meta( (int) $release, ReleaseMeta::COPIES, $copies );
				\WaxStore\Catalog\Discogs\Export::mark_exported( (int) $release, array_column( array_filter( $copies, static function ( $copy ) use ( $seen ) {
					return '' !== $copy['ext'] && ! isset( $seen[ $copy['ext'] ] );
				} ), 'ext' ) );
			}
		}

		return $marked;
	}

	/**
	 * Whether a row describes a copy (price, grades, listing…), or only completes release data.
	 *
	 * @param array $data Mapped row.
	 */
	private static function has_copy_data( array $data ): bool {
		foreach ( array( 'price', 'media_condition', 'sleeve_condition', 'listing_id', 'comments', 'variant', 'quantity', 'status' ) as $field ) {
			if ( '' !== trim( (string) ( $data[ $field ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keeps up to 20 distinct format values that could not be recognized.
	 *
	 * @param string $format Raw format.
	 * @param array  $state  Import state (report updated by reference).
	 */
	private static function note_unknown_format( string $format, array &$state ): void {
		$unknown = $state['report']['unknown_formats'] ?? array();

		if ( count( $unknown ) < 20 && ! in_array( $format, $unknown, true ) ) {
			$unknown[] = $format;
		}

		$state['report']['unknown_formats'] = $unknown;
	}

	/**
	 * Finds a previously imported release by its import key.
	 *
	 * @param string $key Import key.
	 */
	private static function find_release( string $key ): int {
		$ids = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => ReleaseMeta::IMPORT_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- import only.
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- import only.
			)
		);

		if ( ! $ids && 0 === strpos( $key, 'discogs:' ) ) {
			// Releases created by hand carry the Discogs ID without import key.
			$ids = get_posts(
				array(
					'post_type'      => PostType::NAME,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => ReleaseMeta::DISCOGS_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- import only.
					'meta_value'     => (int) substr( $key, 8 ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- import only.
				)
			);
		}

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Sets a meta only when the release has no value yet.
	 *
	 * @param int    $post_id Release ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 */
	private static function fill_meta( int $post_id, string $key, $value ): void {
		if ( '' === $value || 0 === $value || null === $value ) {
			return;
		}

		$current = get_post_meta( $post_id, $key, true );

		if ( '' !== $current && 0 !== $current && '0' !== $current && false !== $current ) {
			return;
		}

		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Assigns terms only when the release has none in that taxonomy.
	 *
	 * @param int    $post_id  Release ID.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $terms    Term names or IDs.
	 */
	private static function fill_terms( int $post_id, string $taxonomy, array $terms ): void {
		$terms = array_values( array_filter( $terms ) );

		if ( ! $terms ) {
			return;
		}

		$existing = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_array( $existing ) && $existing ) {
			return;
		}

		wp_set_object_terms( $post_id, $terms, $taxonomy );
	}

	/**
	 * Whether a CSV row is blank.
	 *
	 * @param array $row CSV cells.
	 */
	private static function is_empty_row( array $row ): bool {
		return '' === trim( implode( '', array_map( 'strval', $row ) ) );
	}
}
