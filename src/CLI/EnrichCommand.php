<?php
/**
 * WP-CLI: wp waxsc enrich <dump> — completes releases with the CC0 data of
 * the monthly Discogs releases dump, in one command, on the server.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\CLI;

use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Import\Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Same result as the discogs_enrich tool followed by a CSV import.
 */
final class EnrichCommand {

	/**
	 * Completes releases with genres, styles, year, country, barcode and
	 * tracklist from the Discogs releases dump (https://data.discogs.com/).
	 *
	 * ## OPTIONS
	 *
	 * <dump>
	 * : Path to discogs_YYYYMMDD_releases.xml.gz (or .xml).
	 *
	 * ## EXAMPLES
	 *
	 *     wp waxsc enrich ~/discogs_20261001_releases.xml.gz
	 *
	 * @param array $args Positional arguments.
	 */
	public function __invoke( $args ): void {
		$dump = (string) ( $args[0] ?? '' );

		if ( ! is_readable( $dump ) ) {
			\WP_CLI::error( 'Dump file not readable: ' . $dump );
		}

		global $wpdb;

		$wanted = array_flip(
			array_map(
				'strval',
				$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- CLI command.
					$wpdb->prepare(
						"SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
						WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status <> 'trash' AND m.meta_value > 0",
						ReleaseMeta::DISCOGS_ID,
						PostType::NAME
					)
				)
			)
		);

		if ( ! $wanted ) {
			\WP_CLI::error( 'No release with a Discogs ID in the catalog.' );
		}

		\WP_CLI::log( sprintf( '%d releases to find in %s', count( $wanted ), $dump ) );

		$csv    = wp_tempnam( 'waxsc-enrich.csv' );
		$out    = fopen( $csv, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed CSV.
		$reader = new \XMLReader();
		$source = substr( $dump, -3 ) === '.gz' ? 'compress.zlib://' . $dump : $dump;

		if ( ! $out || ! $reader->open( $source ) ) {
			\WP_CLI::error( 'Cannot open the dump or the temporary file.' );
		}

		fputcsv( $out, array( 'release_id', 'artist', 'title', 'year', 'country', 'genres', 'styles', 'barcode', 'label', 'catno', 'tracklist' ), ',', '"', '' );

		$found = 0;
		$read  = 0;

		// Move to the first <release>, then go from sibling to sibling.
		while ( $reader->read() && ! ( \XMLReader::ELEMENT === $reader->nodeType && 'release' === $reader->name ) ) {
			continue;
		}

		while ( \XMLReader::ELEMENT === $reader->nodeType && 'release' === $reader->name ) {
			++$read;
			$id = (string) $reader->getAttribute( 'id' );

			if ( 0 === $read % 250000 ) {
				\WP_CLI::log( sprintf( '  %d releases read, %d found', $read, $found ) );
			}

			if ( isset( $wanted[ $id ] ) ) {
				$release = simplexml_load_string( $reader->readOuterXml() );

				if ( $release ) {
					fputcsv( $out, self::row( $release ), ',', '"', '' );
					++$found;
				}

				unset( $wanted[ $id ] );

				if ( ! $wanted ) {
					break;
				}
			}

			if ( ! $reader->next( 'release' ) ) {
				break;
			}
		}

		$reader->close();
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		\WP_CLI::log( sprintf( '%d releases found among %d read. Importing…', $found, $read ) );

		Importer::reset();
		$state = Importer::analyze( $csv );

		if ( is_wp_error( $state ) ) {
			wp_delete_file( $csv );
			\WP_CLI::error( $state->get_error_message() );
		}

		$state['stage'] = 'running';
		Importer::save_state( $state );

		do {
			$state = Importer::run_batch( 200, 60.0 );
		} while ( 'running' === $state['stage'] );

		Importer::reset();

		\WP_CLI::success( sprintf( '%d releases completed. Not in this dump: %d.', (int) $state['report']['enriched'], count( $wanted ) ) );
	}

	/**
	 * CSV row of a release, as written by the discogs_enrich tool.
	 *
	 * @param \SimpleXMLElement $release Release node.
	 */
	private static function row( \SimpleXMLElement $release ): array {
		$clean   = static function ( $name ) {
			return trim( (string) preg_replace( '/\s*\(\d+\)$/', '', trim( (string) $name ) ) );
		};
		$artists = array();

		foreach ( $release->artists->artist ?? array() as $artist ) {
			$artists[] = $clean( $artist->name );
		}

		$barcode = '';

		foreach ( $release->identifiers->identifier ?? array() as $identifier ) {
			$digits = (string) preg_replace( '/\D/', '', (string) $identifier['value'] );

			if ( 'barcode' === strtolower( (string) $identifier['type'] ) && \WaxStore\Catalog\Content\Barcode::is_valid( $digits ) ) {
				$barcode = $digits;
				break;
			}
		}

		$tracks = array();

		foreach ( $release->tracklist->track ?? array() as $track ) {
			$title = trim( (string) $track->title );

			if ( '' === $title || ( '' === trim( (string) $track->position ) && '' === trim( (string) $track->duration ) ) ) {
				continue;
			}

			$tracks[] = trim( (string) $track->position ) . "\t" . str_replace( "\t", ' ', $title ) . "\t" . trim( (string) $track->duration );
		}

		$label    = $release->labels->label[0] ?? null;
		$released = (string) $release->released;
		$genres   = array();
		$styles   = array();

		foreach ( $release->genres->genre ?? array() as $genre ) {
			$genres[] = trim( (string) $genre );
		}

		foreach ( $release->styles->style ?? array() as $style ) {
			$styles[] = trim( (string) $style );
		}

		return array(
			(string) $release['id'],
			implode( ', ', array_filter( $artists ) ),
			trim( (string) $release->title ),
			ctype_digit( substr( $released, 0, 4 ) ) ? substr( $released, 0, 4 ) : '',
			trim( (string) $release->country ),
			implode( ' | ', array_filter( $genres ) ),
			implode( ' | ', array_filter( $styles ) ),
			$barcode,
			$label ? $clean( $label['name'] ) : '',
			$label ? trim( (string) $label['catno'] ) : '',
			implode( "\n", $tracks ),
		);
	}
}
