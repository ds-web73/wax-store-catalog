<?php
/**
 * Schema.org JSON-LD for the shop, on the front page.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Front;

use WaxStore\Catalog\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * MusicStore / Store for a physical shop, Organization for an online-only shop.
 * Disable with: add_filter( 'waxsc_output_local_business', '__return_false' );
 */
final class LocalBusinessSchema {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'output' ), 20 );
	}

	/**
	 * Prints the JSON-LD block.
	 */
	public static function output(): void {
		if ( ! is_front_page() || ! apply_filters( 'waxsc_output_local_business', true ) ) {
			return;
		}

		$data = self::build();

		/**
		 * Filters the shop structured data before output.
		 *
		 * @param array $data JSON-LD data, empty array to skip.
		 */
		$data = apply_filters( 'waxsc_local_business_schema', $data );

		if ( ! $data ) {
			return;
		}

		echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}

	/**
	 * Builds the data from the shop settings.
	 */
	public static function build(): array {
		$type   = (string) Settings::get( 'local', 'business_type' );
		$street = (string) Settings::get( 'contact', 'street' );
		$city   = (string) Settings::get( 'contact', 'city' );
		$name   = (string) Settings::get( 'identity', 'shop_name' );
		$name   = '' !== $name ? $name : get_bloginfo( 'name' );

		$physical = 'online' !== $type && '' !== $street && '' !== $city;

		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => $physical ? $type : 'Organization',
			'@id'      => home_url( '/#shop' ),
			'name'     => $name,
			'url'      => home_url( '/' ),
		);

		$description = (string) Settings::get( 'local', 'description' );
		$data       += self::filled(
			array(
				'description' => '' !== $description ? $description : get_bloginfo( 'description' ),
				'telephone'   => Settings::get( 'contact', 'phone' ),
				'email'       => Settings::get( 'contact', 'email' ),
				'logo'        => get_site_icon_url( 512 ),
				'sameAs'      => Settings::profile_urls(),
			)
		);

		if ( ! empty( $data['logo'] ) && $physical ) {
			$data['image'] = $data['logo'];
		}

		if ( '' !== $street || '' !== $city ) {
			$data['address'] = array( '@type' => 'PostalAddress' ) + self::filled(
				array(
					'streetAddress'   => $street,
					'postalCode'      => Settings::get( 'contact', 'postal_code' ),
					'addressLocality' => $city,
					'addressCountry'  => Settings::get( 'contact', 'country' ),
				)
			);
		}

		if ( ! $physical ) {
			return $data;
		}

		$latitude  = (string) Settings::get( 'local', 'latitude' );
		$longitude = (string) Settings::get( 'local', 'longitude' );

		if ( '' !== $latitude && '' !== $longitude ) {
			$data['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $latitude,
				'longitude' => (float) $longitude,
			);
		}

		$hours = self::opening_hours();

		if ( $hours ) {
			$data['openingHoursSpecification'] = $hours;
		}

		$areas = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'local', 'area_served' ) ) ) );

		return $data + self::filled(
			array(
				'hasMap'     => Settings::get( 'local', 'map_url' ),
				'priceRange' => Settings::get( 'local', 'price_range' ),
				'areaServed' => array_values( $areas ),
			)
		);
	}

	/**
	 * Opening hours, one specification per range, days with identical hours grouped.
	 */
	private static function opening_hours(): array {
		$hours  = Settings::get( 'contact', 'hours' );
		$groups = array();

		if ( ! is_array( $hours ) ) {
			return array();
		}

		foreach ( Settings::DAYS as $day => $schema_day ) {
			foreach ( array_filter( explode( ',', (string) ( $hours[ $day ] ?? '' ) ) ) as $range ) {
				$groups[ $range ][] = $schema_day;
			}
		}

		$specs = array();

		foreach ( $groups as $range => $days ) {
			list( $opens, $closes ) = explode( '-', $range );

			$specs[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $days,
				'opens'     => $opens,
				'closes'    => $closes,
			);
		}

		return $specs;
	}

	/**
	 * Drops empty values.
	 *
	 * @param array $values Values.
	 */
	private static function filled( array $values ): array {
		return array_filter(
			$values,
			static function ( $value ) {
				return '' !== $value && array() !== $value && null !== $value && false !== $value;
			}
		);
	}
}
