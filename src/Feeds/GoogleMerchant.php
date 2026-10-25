<?php
/**
 * Google Merchant Center product feed (RSS 2.0 with the g: namespace).
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Feeds;

use WaxStore\Catalog\Content\Barcode;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\Gallery;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Content\Taxonomies;
use WaxStore\Catalog\Front\ReleaseSchema;
use WaxStore\Catalog\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One item per release in stock, at the lowest price of its available copies,
 * which is the "From" price shown on the release page.
 */
final class GoogleMerchant {

	const OPTION = 'waxsc_google_feed';
	const CACHE  = 'waxsc_google_feed_xml';
	const STATS  = 'waxsc_google_feed_stats';

	/**
	 * Google product categories by top-level format key.
	 */
	const CATEGORIES = array(
		'vinyl'           => 543523,
		'cd'              => 543522,
		'cassette'        => 543524,
		'dvd'             => 839,
		'blu-ray'         => 839,
		'merch-tshirt'    => 212,
		'merch-poster'    => 500044,
	);

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter(
			'query_vars',
			static function ( $vars ) {
				$vars[] = 'waxsc_feed';
				return $vars;
			}
		);
		add_action( 'template_redirect', array( self::class, 'serve' ) );
		add_action( 'added_post_meta', array( self::class, 'flush_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'flush_meta' ), 10, 3 );
		add_action( 'save_post_' . PostType::NAME, array( self::class, 'flush' ) );
	}

	/**
	 * Feed address to give to Merchant Center.
	 */
	public static function url(): string {
		return add_query_arg( 'waxsc_feed', 'google', home_url( '/' ) );
	}

	/**
	 * Clears the cache when copies or the cover change.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function flush_meta( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, array( ReleaseMeta::COPIES, '_thumbnail_id', Gallery::META ), true ) ) {
			self::flush();
		}
	}

	/**
	 * Clears the cached feed when catalog data changes.
	 */
	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * Outputs the feed.
	 */
	public static function serve(): void {
		if ( 'google' !== get_query_var( 'waxsc_feed' ) ) {
			return;
		}

		if ( ! get_option( self::OPTION ) ) {
			status_header( 404 );
			exit;
		}

		$xml = get_transient( self::CACHE );

		if ( ! is_string( $xml ) ) {
			$xml = self::build();
			set_transient( self::CACHE, $xml, HOUR_IN_SECONDS );
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is XML-escaped in build().
		exit;
	}

	/**
	 * XML-escaped text.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum length.
	 */
	private static function x( string $text, int $limit = 0 ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) );

		if ( $limit && mb_strlen( $text ) > $limit ) {
			$text = rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…';
		}

		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Builds the feed and records the statistics shown in the settings.
	 */
	public static function build(): string {
		$currency = strtoupper( (string) get_option( 'waxsc_currency', 'EUR' ) );
		$shop     = (string) Settings::get( 'identity', 'shop_name' );
		$shop     = '' !== $shop ? $shop : get_bloginfo( 'name' );
		$stats    = array(
			'items'    => 0,
			'no_image' => 0,
			'no_price' => 0,
			'time'     => time(),
		);

		$ids = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached feed.
					'relation' => 'OR',
					array(
						'key'     => '_waxsc_sold',
						'value'   => '1',
						'compare' => '!=',
					),
					array(
						'key'     => '_waxsc_sold',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$items = '';

		foreach ( $ids as $release ) {
			$item = self::item( (int) $release, $currency, $stats );

			if ( '' !== $item ) {
				$items .= $item;
				++$stats['items'];
			}
		}

		update_option( self::STATS, $stats, false );

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
			. '<title>' . self::x( $shop ) . '</title>'
			. '<link>' . esc_url( home_url( '/' ) ) . '</link>'
			. '<description>' . self::x( get_bloginfo( 'description' ) ) . '</description>'
			. $items
			. '</channel></rss>';
	}

	/**
	 * One item, empty when the release cannot be listed.
	 *
	 * @param int    $release  Release ID.
	 * @param string $currency ISO currency.
	 * @param array  $stats    Statistics (updated).
	 */
	private static function item( int $release, string $currency, array &$stats ): string {
		$available = Copies::get_available( $release );
		$priced    = array_values(
			array_filter(
				$available,
				static function ( $copy ) {
					return '' !== $copy['price'];
				}
			)
		);

		if ( ! $priced ) {
			++$stats['no_price'];
			return '';
		}

		$image = get_the_post_thumbnail_url( $release, 'full' );

		if ( ! $image ) {
			++$stats['no_image'];
			return '';
		}

		usort(
			$priced,
			static function ( $a, $b ) {
				return (float) $a['price'] <=> (float) $b['price'];
			}
		);

		$copy    = $priced[0];
		$title   = html_entity_decode( get_the_title( $release ), ENT_QUOTES, 'UTF-8' );
		$artists = self::terms( $release, Taxonomies::ARTIST );
		$labels  = self::terms( $release, Taxonomies::LABEL );
		$formats = self::terms( $release, Taxonomies::FORMAT );
		$genres  = self::terms( $release, Taxonomies::GENRE );
		$year    = (int) get_post_meta( $release, ReleaseMeta::YEAR, true );
		$catno   = (string) get_post_meta( $release, ReleaseMeta::CATALOG_NUMBER, true );
		$barcode = (string) get_post_meta( $release, ReleaseMeta::BARCODE, true );
		$key     = self::format_key( $formats );

		$details = array_filter( array( $formats ? $formats[0]->name : '', $year ? (string) $year : '' ) );
		$name    = ( $artists ? implode( ', ', wp_list_pluck( $artists, 'name' ) ) . ' – ' : '' ) . $title . ( $details ? ' (' . implode( ', ', $details ) . ')' : '' );

		$description = ReleaseSchema::description( $release, $title, $artists );
		$grades      = trim( implode( ' / ', array_filter( array( $copy['media'], $copy['sleeve'] ) ) ) );

		if ( '' !== $grades ) {
			/* translators: %s: grades, e.g. "VG+ / VG". */
			$description .= ' ' . sprintf( __( 'Copy graded %s.', 'wax-store-catalog' ), $grades );
		}

		$pressing = (string) get_post_meta( $release, ReleaseMeta::PRESSING, true );

		if ( '' !== $pressing ) {
			$description .= ' ' . $pressing . '.';
		}

		if ( '' !== $copy['note'] ) {
			$description .= ' ' . $copy['note'];
		}

		$xml  = '<item>';
		$xml .= '<g:id>waxsc-' . $release . '</g:id>';
		$xml .= '<g:title>' . self::x( $name, 150 ) . '</g:title>';
		$xml .= '<g:description>' . self::x( $description, 5000 ) . '</g:description>';
		$xml .= '<g:link>' . esc_url( (string) get_permalink( $release ) ) . '</g:link>';
		$xml .= '<g:image_link>' . esc_url( (string) $image ) . '</g:image_link>';

		foreach ( array_slice( Gallery::get( $release ), 0, 10 ) as $photo ) {
			$xml .= '<g:additional_image_link>' . esc_url( (string) wp_get_attachment_image_url( $photo, 'full' ) ) . '</g:additional_image_link>';
		}

		$xml .= '<g:availability>in_stock</g:availability>';
		$xml .= '<g:price>' . self::x( number_format( (float) $copy['price'], 2, '.', '' ) . ' ' . $currency ) . '</g:price>';
		$xml .= '<g:condition>' . self::condition( $release, $copy ) . '</g:condition>';

		if ( '' !== $barcode && Barcode::is_valid( $barcode ) ) {
			$xml .= '<g:gtin>' . self::x( $barcode ) . '</g:gtin>';
		} elseif ( $labels && '' !== $catno ) {
			$xml .= '<g:brand>' . self::x( $labels[0]->name, 70 ) . '</g:brand>';
			$xml .= '<g:mpn>' . self::x( $catno, 70 ) . '</g:mpn>';
		} else {
			$xml .= '<g:identifier_exists>no</g:identifier_exists>';
		}

		if ( 0 === strpos( $key, 'merch' ) && $artists ) {
			$xml .= '<g:brand>' . self::x( $artists[0]->name, 70 ) . '</g:brand>';
		}

		$category = self::CATEGORIES[ $key ] ?? ( 0 === strpos( $key, 'merch' ) ? 0 : 855 );

		if ( $category ) {
			$xml .= '<g:google_product_category>' . $category . '</g:google_product_category>';
		}

		$type = array_filter( array( $formats ? $formats[0]->name : '', $genres ? $genres[0]->name : '' ) );

		if ( $type ) {
			$xml .= '<g:product_type>' . self::x( implode( ' > ', $type ), 750 ) . '</g:product_type>';
		}

		return $xml . '</item>';
	}

	/**
	 * Most precise format key: "merch-tshirt", else the top-level one ("vinyl").
	 *
	 * @param \WP_Term[] $formats Format terms.
	 */
	private static function format_key( array $formats ): string {
		if ( ! $formats ) {
			return '';
		}

		$own = Taxonomies::get_key( (int) $formats[0]->term_id );

		if ( isset( self::CATEGORIES[ $own ] ) ) {
			return $own;
		}

		$ancestors = get_ancestors( $formats[0]->term_id, Taxonomies::FORMAT, 'taxonomy' );

		return Taxonomies::get_key( $ancestors ? (int) end( $ancestors ) : (int) $formats[0]->term_id );
	}

	/**
	 * "new" or "used": the condition taxonomy first, then the grades.
	 *
	 * @param int   $release Release ID.
	 * @param array $copy    Copy.
	 */
	private static function condition( int $release, array $copy ): string {
		foreach ( self::terms( $release, Taxonomies::CONDITION ) as $term ) {
			$key = Taxonomies::get_key( (int) $term->term_id );

			if ( 'new' === $key || 'used' === $key ) {
				return $key;
			}
		}

		return ( '' !== $copy['media'] && 'M' !== $copy['media'] ) ? 'used' : 'new';
	}

	/**
	 * Terms as an array.
	 *
	 * @param int    $release  Release ID.
	 * @param string $taxonomy Taxonomy.
	 * @return \WP_Term[]
	 */
	private static function terms( int $release, string $taxonomy ): array {
		$terms = get_the_terms( $release, $taxonomy );

		return is_array( $terms ) ? array_values( $terms ) : array();
	}
}
