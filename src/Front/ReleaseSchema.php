<?php
/**
 * Schema.org JSON-LD for release pages: Product + MusicRelease, and breadcrumbs.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Front;

use WaxStore\Catalog\Content\Barcode;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Content\Taxonomies;
use WaxStore\Catalog\Content\Tracklist;
use WaxStore\Catalog\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One node typed both Product (price, availability, condition, barcode: rich
 * results) and MusicRelease (artist, label, catalog number, format, tracks:
 * what the record is). Merchandise is a plain Product.
 * Disable with: add_filter( 'waxsc_output_release_schema', '__return_false' );
 */
final class ReleaseSchema {

	/**
	 * Format keys mapped to schema.org MusicReleaseFormatType.
	 */
	const FORMATS = array(
		'vinyl'    => 'https://schema.org/VinylFormat',
		'cd'       => 'https://schema.org/CDFormat',
		'cassette' => 'https://schema.org/CassetteFormat',
		'dvd'      => 'https://schema.org/DVDFormat',
	);

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'output' ), 20 );
	}

	/**
	 * Prints the JSON-LD on release pages.
	 */
	public static function output(): void {
		$post_type = waxsc()->commerce()->get_catalog_post_type();

		if ( ! is_singular( $post_type ) || ! apply_filters( 'waxsc_output_release_schema', true ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		$graph   = array_values( array_filter( array( self::release( $post_id ), self::breadcrumbs( $post_id ) ) ) );

		/**
		 * Filters the release structured data before output.
		 *
		 * @param array $graph   JSON-LD nodes, empty array to skip.
		 * @param int   $post_id Release ID.
		 */
		$graph = (array) apply_filters( 'waxsc_release_schema', $graph, $post_id );

		if ( ! $graph ) {
			return;
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}

	/**
	 * Product + MusicRelease node.
	 *
	 * @param int $post_id Release ID.
	 */
	public static function release( int $post_id ): array {
		$url     = get_permalink( $post_id );
		$title   = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		$artists = self::terms( $post_id, Taxonomies::ARTIST );
		$kind    = self::format_kind( $post_id );
		$is_music = 'merchandise' !== $kind;

		$node = array(
			'@type' => $is_music ? array( 'Product', 'MusicRelease' ) : 'Product',
			'@id'   => $url . '#release',
			'url'   => $url,
			'name'  => $artists ? implode( ', ', wp_list_pluck( $artists, 'name' ) ) . ' – ' . $title : $title,
		);

		$images = array_values(
			array_filter(
				array_merge(
					array( get_the_post_thumbnail_url( $post_id, 'full' ) ),
					array_map(
						static function ( $id ) {
							return wp_get_attachment_image_url( $id, 'full' );
						},
						\WaxStore\Catalog\Content\Gallery::get( $post_id )
					)
				)
			)
		);

		if ( $images ) {
			$node['image'] = 1 === count( $images ) ? $images[0] : $images;
		}

		$node['description'] = self::description( $post_id, $title, $artists );

		$catalog = (string) get_post_meta( $post_id, ReleaseMeta::CATALOG_NUMBER, true );
		$barcode = (string) get_post_meta( $post_id, ReleaseMeta::BARCODE, true );

		if ( '' !== $catalog ) {
			$node['sku'] = $catalog;
		}

		if ( '' !== $barcode && Barcode::is_valid( $barcode ) ) {
			$node[ 'gtin' . strlen( $barcode ) ] = $barcode;
		}

		$offer = waxsc()->commerce()->get_active()->get_offer_schema( $post_id );

		if ( $offer ) {
			$offer['seller'] = array(
				'@type' => 'Organization',
				'name'  => self::shop_name(),
				'url'   => home_url( '/' ),
			);

			$node['offers'] = $offer;

			if ( ! empty( $offer['itemCondition'] ) ) {
				$node['itemCondition'] = $offer['itemCondition'];
			}
		}

		if ( ! $is_music ) {
			return $node;
		}

		$music = array();

		if ( $artists ) {
			$music['byArtist'] = array_map(
				static function ( $term ) {
					$link = get_term_link( $term );
					return array_filter(
						array(
							'@type' => 'MusicGroup',
							'name'  => $term->name,
							'url'   => is_wp_error( $link ) ? '' : $link,
						)
					);
				},
				$artists
			);

			if ( 1 === count( $music['byArtist'] ) ) {
				$music['byArtist'] = $music['byArtist'][0];
			}

			$music['creditedTo'] = implode( ', ', wp_list_pluck( $artists, 'name' ) );
		}

		$labels = self::terms( $post_id, Taxonomies::LABEL );

		if ( $labels ) {
			$music['recordLabel'] = array(
				'@type' => 'Organization',
				'name'  => $labels[0]->name,
			);
		}

		if ( '' !== $catalog ) {
			$music['catalogNumber'] = $catalog;
		}

		if ( isset( self::FORMATS[ $kind ] ) ) {
			$music['musicReleaseFormat'] = self::FORMATS[ $kind ];
		}

		$year = (int) get_post_meta( $post_id, ReleaseMeta::YEAR, true );

		if ( $year ) {
			$music['datePublished'] = (string) $year;
		}

		$genres = self::terms( $post_id, Taxonomies::GENRE );

		if ( $genres ) {
			$names = array();

			foreach ( $genres as $genre ) {
				foreach ( array_reverse( get_ancestors( $genre->term_id, Taxonomies::GENRE, 'taxonomy' ) ) as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, Taxonomies::GENRE );

					if ( $ancestor instanceof \WP_Term ) {
						$names[] = $ancestor->name;
					}
				}

				$names[] = $genre->name;
			}

			$music['genre'] = array_values( array_unique( $names ) );
		}

		$countries = self::terms( $post_id, Taxonomies::COUNTRY );

		if ( $countries ) {
			$music['countryOfOrigin'] = array(
				'@type' => 'Country',
				'name'  => $countries[0]->name,
			);
		}

		$music['releaseOf'] = array_filter(
			array(
				'@type'    => 'MusicAlbum',
				'name'     => $title,
				'byArtist' => $music['byArtist'] ?? null,
			)
		);

		$tracks = Tracklist::get( $post_id );

		if ( $tracks ) {
			$music['numTracks'] = count( $tracks );
			$music['track']     = array();

			foreach ( $tracks as $index => $track ) {
				$music['track'][] = array_filter(
					array(
						'@type'    => 'MusicRecording',
						'position' => '' !== $track['position'] ? $track['position'] : (string) ( $index + 1 ),
						'name'     => $track['title'],
						'duration' => self::iso_duration( $track['duration'] ),
					)
				);
			}
		}

		return $node + $music;
	}

	/**
	 * Breadcrumbs: home > catalog > artist > release.
	 *
	 * @param int $post_id Release ID.
	 */
	public static function breadcrumbs( int $post_id ): array {
		$items = array(
			array(
				'name' => self::shop_name(),
				'item' => home_url( '/' ),
			),
		);

		$archive = get_post_type_archive_link( get_post_type( $post_id ) );

		if ( $archive ) {
			$items[] = array(
				'name' => __( 'Catalog', 'wax-store-catalog' ),
				'item' => $archive,
			);
		}

		$artists = self::terms( $post_id, Taxonomies::ARTIST );
		$link    = $artists ? get_term_link( $artists[0] ) : '';

		if ( $artists && ! is_wp_error( $link ) ) {
			$items[] = array(
				'name' => $artists[0]->name,
				'item' => $link,
			);
		}

		$items[] = array(
			'name' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'item' => get_permalink( $post_id ),
		);

		$list = array();

		foreach ( $items as $position => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $position + 1,
				'name'     => $item['name'],
				'item'     => $item['item'],
			);
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list,
		);
	}

	/**
	 * Excerpt, or the start of the content, or a sentence built from the details.
	 *
	 * @param int       $post_id Release ID.
	 * @param string    $title   Release title.
	 * @param \WP_Term[] $artists Artists.
	 */
	public static function description( int $post_id, string $title, array $artists ): string {
		$post = get_post( $post_id );

		if ( $post && '' !== trim( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		if ( $post && '' !== trim( wp_strip_all_tags( $post->post_content ) ) ) {
			return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 40, '…' );
		}

		$formats = self::terms( $post_id, Taxonomies::FORMAT );
		$labels  = self::terms( $post_id, Taxonomies::LABEL );
		$year    = (int) get_post_meta( $post_id, ReleaseMeta::YEAR, true );
		$details = array_filter(
			array(
				$labels ? $labels[0]->name : '',
				$year ? (string) $year : '',
			)
		);

		$sentence = sprintf(
			/* translators: 1: format, e.g. "LP (33 rpm)", 2: title, 3: artist. */
			__( '%1$s "%2$s" by %3$s', 'wax-store-catalog' ),
			$formats ? $formats[0]->name : __( 'Record', 'wax-store-catalog' ),
			$title,
			$artists ? implode( ', ', wp_list_pluck( $artists, 'name' ) ) : __( 'various artists', 'wax-store-catalog' )
		);

		return $details ? $sentence . ' (' . implode( ', ', $details ) . ').' : $sentence . '.';
	}

	/**
	 * Top-level format key (vinyl, cd, cassette, merchandise…).
	 *
	 * @param int $post_id Release ID.
	 */
	private static function format_kind( int $post_id ): string {
		$formats = self::terms( $post_id, Taxonomies::FORMAT );

		if ( ! $formats ) {
			return '';
		}

		$ancestors = get_ancestors( $formats[0]->term_id, Taxonomies::FORMAT, 'taxonomy' );
		$root      = $ancestors ? (int) end( $ancestors ) : (int) $formats[0]->term_id;

		return Taxonomies::get_key( $root );
	}

	/**
	 * "9:22" => "PT9M22S", "1:02:03" => "PT1H2M3S".
	 *
	 * @param string $duration Duration as typed.
	 */
	public static function iso_duration( string $duration ): string {
		if ( '' === $duration ) {
			return '';
		}

		$parts   = array_map( 'intval', explode( ':', $duration ) );
		$seconds = array_pop( $parts );
		$minutes = (int) array_pop( $parts );
		$hours   = (int) array_pop( $parts );

		return 'PT' . ( $hours ? $hours . 'H' : '' ) . ( $minutes ? $minutes . 'M' : '' ) . $seconds . 'S';
	}

	/**
	 * Terms of a release, as an array.
	 *
	 * @param int    $post_id  Release ID.
	 * @param string $taxonomy Taxonomy.
	 * @return \WP_Term[]
	 */
	private static function terms( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );

		return is_array( $terms ) ? array_values( $terms ) : array();
	}

	/**
	 * Shop name from the settings, or the site title.
	 */
	private static function shop_name(): string {
		$name = (string) Settings::get( 'identity', 'shop_name' );

		return '' !== $name ? $name : html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
	}
}
