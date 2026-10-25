<?php
/**
 * Import target fields and automatic column matching.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Target fields. Header aliases cover the Discogs inventory export and
 * common English and French spreadsheet headers.
 */
final class Fields {

	/**
	 * Target fields: key => label.
	 *
	 * @return array<string,string>
	 */
	public static function targets(): array {
		return array(
			'title'            => __( 'Title (required)', 'wax-store-catalog' ),
			'artist'           => __( 'Artist', 'wax-store-catalog' ),
			'label'            => __( 'Record label', 'wax-store-catalog' ),
			'catalog_number'   => __( 'Catalog number', 'wax-store-catalog' ),
			'format'           => __( 'Format', 'wax-store-catalog' ),
			'condition'        => __( 'Condition (new / used)', 'wax-store-catalog' ),
			'year'             => __( 'Release year', 'wax-store-catalog' ),
			'country'          => __( 'Country', 'wax-store-catalog' ),
			'genre'            => __( 'Genres', 'wax-store-catalog' ),
			'style'            => __( 'Sub-genres (styles)', 'wax-store-catalog' ),
			'barcode'          => __( 'Barcode', 'wax-store-catalog' ),
			'price'            => __( 'Price', 'wax-store-catalog' ),
			'media_condition'  => __( 'Media grade', 'wax-store-catalog' ),
			'sleeve_condition' => __( 'Sleeve grade', 'wax-store-catalog' ),
			'variant'          => __( 'Size / variant', 'wax-store-catalog' ),
			'tracklist'        => __( 'Tracklist (one track per line: position, title, duration separated by tabs)', 'wax-store-catalog' ),
			'comments'         => __( 'Copy notes', 'wax-store-catalog' ),
			'location'         => __( 'Location in the shop', 'wax-store-catalog' ),
			'quantity'         => __( 'Quantity', 'wax-store-catalog' ),
			'status'           => __( 'Status', 'wax-store-catalog' ),
			'listing_id'       => __( 'Unique copy ID (e.g. Discogs listing ID)', 'wax-store-catalog' ),
			'release_id'       => __( 'Discogs release ID', 'wax-store-catalog' ),
		);
	}

	/**
	 * Known header names per target, already normalized.
	 *
	 * @return array<string,string[]>
	 */
	private static function aliases(): array {
		return array(
			'title'            => array( 'title', 'titre', 'album' ),
			'artist'           => array( 'artist', 'artiste', 'artists', 'artistes' ),
			'label'            => array( 'label', 'labels', 'maison de disque', 'editeur' ),
			'catalog_number'   => array( 'catno', 'catalog number', 'cat no', 'numero de catalogue', 'reference', 'ref' ),
			'format'           => array( 'format', 'formats', 'support' ),
			'condition'        => array( 'condition', 'etat', 'neuf occasion', 'new used', 'neuf ou occasion' ),
			'year'             => array( 'year', 'released', 'annee', 'date' ),
			'country'          => array( 'country', 'pays' ),
			'genre'            => array( 'genre', 'genres' ),
			'style'            => array( 'style', 'styles', 'sous genre', 'sous genres', 'subgenre', 'sub genre' ),
			'barcode'          => array( 'barcode', 'ean', 'upc', 'code barre', 'gtin' ),
			'price'            => array( 'price', 'prix', 'prix de vente' ),
			'media_condition'  => array( 'media condition', 'media', 'etat disque', 'etat media', 'grading disque' ),
			'sleeve_condition' => array( 'sleeve condition', 'sleeve', 'etat pochette', 'grading pochette' ),
			'variant'          => array( 'variant', 'variante', 'size', 'taille' ),
			'tracklist'        => array( 'tracklist', 'tracks', 'titres', 'liste des titres' ),
			'comments'         => array( 'comments', 'comment', 'commentaire', 'commentaires', 'notes', 'note' ),
			'location'         => array( 'location', 'emplacement', 'bac', 'rangement' ),
			'quantity'         => array( 'quantity', 'quantite', 'qty', 'stock' ),
			'status'           => array( 'status', 'statut' ),
			'listing_id'       => array( 'listing id', 'sku', 'id exemplaire' ),
			'release_id'       => array( 'release id', 'discogs id' ),
		);
	}

	/**
	 * Lowercase, no accents, single spaces ("Release_ID" => "release id").
	 *
	 * @param string $header Raw header.
	 */
	public static function normalize( string $header ): string {
		$header = strtolower( remove_accents( trim( $header, " \t\n\r\0\x0B\"'" ) ) );

		return trim( (string) preg_replace( '/[\s_\-.]+/', ' ', $header ) );
	}

	/**
	 * Guesses the column index of each target, -1 when not found.
	 *
	 * @param string[] $header CSV header row.
	 * @return array<string,int>
	 */
	public static function guess_mapping( array $header ): array {
		$normalized = array_map( array( self::class, 'normalize' ), $header );
		$mapping    = array();

		foreach ( self::aliases() as $target => $names ) {
			$mapping[ $target ] = -1;

			foreach ( $names as $name ) {
				$index = array_search( $name, $normalized, true );

				if ( false !== $index ) {
					$mapping[ $target ] = (int) $index;
					break;
				}
			}
		}

		return $mapping;
	}
}
