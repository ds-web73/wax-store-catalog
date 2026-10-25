<?php
/**
 * Shop settings: definitions, storage and sanitization.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * All shop settings live in one option, one sub-array per tab.
 * Catalog settings (currency, grading…) keep their own options.
 */
final class Settings {

	const OPTION = 'waxsc_shop';

	/**
	 * Days of the week: key => schema.org day.
	 */
	const DAYS = array(
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	);

	/**
	 * Returns a setting value, or its default.
	 *
	 * @param string $tab Tab key.
	 * @param string $key Field key.
	 * @return mixed
	 */
	public static function get( string $tab, string $key ) {
		$all = get_option( self::OPTION, array() );

		if ( is_array( $all ) && isset( $all[ $tab ][ $key ] ) ) {
			return $all[ $tab ][ $key ];
		}

		return self::schema()[ $tab ]['fields'][ $key ]['default'] ?? '';
	}

	/**
	 * Whether the shop is run by a professional.
	 */
	public static function is_professional(): bool {
		return 'individual' !== self::get( 'identity', 'owner_type' );
	}

	/**
	 * Tabs and fields. "pro" fields only apply to professionals.
	 */
	public static function schema(): array {
		return array(
			'identity' => array(
				'label'  => __( 'Identity', 'wax-store-catalog' ),
				'fields' => array(
					'owner_type' => array(
						'type'    => 'radio',
						'label'   => __( 'Who runs this shop?', 'wax-store-catalog' ),
						'default' => 'professional',
						'options' => array(
							'individual'   => __( 'An individual (private seller)', 'wax-store-catalog' ),
							'professional' => __( 'A professional (sole trader or company)', 'wax-store-catalog' ),
						),
						'desc'    => __( 'Selling regularly may require a professional status, depending on your country.', 'wax-store-catalog' ),
					),
					'shop_name'  => array(
						'type'  => 'text',
						'label' => __( 'Shop name', 'wax-store-catalog' ),
						'desc'  => __( 'Leave empty to use the site title.', 'wax-store-catalog' ),
					),
					'owner_name' => array(
						'type'  => 'text',
						'label' => __( 'Owner full name', 'wax-store-catalog' ),
					),
					'legal_name' => array(
						'type'  => 'text',
						'label' => __( 'Legal name', 'wax-store-catalog' ),
						'desc'  => __( 'Company or trade name as registered.', 'wax-store-catalog' ),
						'pro'   => true,
					),
					'legal_form' => array(
						'type'  => 'text',
						'label' => __( 'Legal form', 'wax-store-catalog' ),
						'list'  => array( 'EI', 'Micro-entreprise', 'EURL', 'SASU', 'SARL', 'SAS', 'Ltd', 'LLC' ),
						'pro'   => true,
					),
				),
			),
			'contact'  => array(
				'label'  => __( 'Contact', 'wax-store-catalog' ),
				'fields' => array(
					'street'      => array(
						'type'  => 'text',
						'label' => __( 'Street address', 'wax-store-catalog' ),
					),
					'postal_code' => array(
						'type'  => 'text',
						'label' => __( 'Postal code', 'wax-store-catalog' ),
					),
					'city'        => array(
						'type'  => 'text',
						'label' => __( 'City', 'wax-store-catalog' ),
					),
					'country'     => array(
						'type'    => 'country',
						'label'   => __( 'Country code', 'wax-store-catalog' ),
						'desc'    => __( 'Two letters, e.g. FR, BE, CH.', 'wax-store-catalog' ),
						'default' => 'FR',
					),
					'phone'       => array(
						'type'  => 'tel',
						'label' => __( 'Phone', 'wax-store-catalog' ),
					),
					'email'       => array(
						'type'  => 'email',
						'label' => __( 'Email', 'wax-store-catalog' ),
					),
					'hours'       => array(
						'type'  => 'hours',
						'label' => __( 'Opening hours', 'wax-store-catalog' ),
						'desc'  => __( 'One or two ranges per day, e.g. 10:00-13:00, 14:00-19:00. Leave empty when closed.', 'wax-store-catalog' ),
					),
					'closed_from'  => array(
						'type'  => 'date',
						'label' => __( 'Exceptional closure from', 'wax-store-catalog' ),
						'desc'  => __( 'Holidays, inventory… Leave empty when there is none.', 'wax-store-catalog' ),
					),
					'closed_until' => array(
						'type'  => 'date',
						'label' => __( 'Until (included)', 'wax-store-catalog' ),
					),
					'closed_note'  => array(
						'type'  => 'text',
						'label' => __( 'Closure reason', 'wax-store-catalog' ),
						'desc'  => __( 'Optional, shown to visitors, e.g. "summer holidays".', 'wax-store-catalog' ),
					),
				),
			),
			'legal'    => array(
				'label'  => __( 'Legal information', 'wax-store-catalog' ),
				'fields' => array(
					'registration_number'  => array(
						'type'  => 'text',
						'label' => __( 'Registration number', 'wax-store-catalog' ),
						'desc'  => __( 'SIRET in France, company number elsewhere.', 'wax-store-catalog' ),
						'pro'   => true,
					),
					'registry'             => array(
						'type'  => 'text',
						'label' => __( 'Trade registry', 'wax-store-catalog' ),
						'desc'  => __( 'e.g. RCS Paris, RNE.', 'wax-store-catalog' ),
						'pro'   => true,
					),
					'vat_number'           => array(
						'type'  => 'text',
						'label' => __( 'VAT number', 'wax-store-catalog' ),
						'pro'   => true,
					),
					'share_capital'        => array(
						'type'  => 'text',
						'label' => __( 'Share capital', 'wax-store-catalog' ),
						'desc'  => __( 'Companies only, e.g. 1 000 €.', 'wax-store-catalog' ),
						'pro'   => true,
					),
					'publication_director' => array(
						'type'  => 'text',
						'label' => __( 'Publication director', 'wax-store-catalog' ),
						'desc'  => __( 'Person legally responsible for the site content.', 'wax-store-catalog' ),
					),
					'mediator_name'        => array(
						'type'  => 'text',
						'label' => __( 'Consumer mediator', 'wax-store-catalog' ),
						'desc'  => __( 'Mandatory for professionals selling to consumers in the EU.', 'wax-store-catalog' ),
						'pro'   => true,
					),
					'mediator_url'         => array(
						'type'  => 'url',
						'label' => __( 'Mediator website', 'wax-store-catalog' ),
						'pro'   => true,
					),
					'legal_page'           => array(
						'type'  => 'page',
						'label' => __( 'Legal notice page', 'wax-store-catalog' ),
						'desc'  => __( 'Add the [waxsc_legal_notice] shortcode to this page: it is filled from these settings.', 'wax-store-catalog' ),
					),
					'terms_page'           => array(
						'type'  => 'page',
						'label' => __( 'Terms of sale page', 'wax-store-catalog' ),
						'pro'   => true,
					),
				),
			),
			'host'     => array(
				'label'  => __( 'Hosting', 'wax-store-catalog' ),
				'fields' => array(
					'host_name'    => array(
						'type'  => 'text',
						'label' => __( 'Hosting provider', 'wax-store-catalog' ),
						'list'  => array( 'OVH SAS', 'o2switch', 'IONOS SARL', 'Hostinger International Ltd', 'Infomaniak Network SA', 'PlanetHoster', 'Scaleway SAS' ),
					),
					'host_address' => array(
						'type'  => 'textarea',
						'label' => __( 'Hosting provider address', 'wax-store-catalog' ),
					),
					'host_phone'   => array(
						'type'  => 'tel',
						'label' => __( 'Hosting provider phone', 'wax-store-catalog' ),
					),
					'host_url'     => array(
						'type'  => 'url',
						'label' => __( 'Hosting provider website', 'wax-store-catalog' ),
					),
				),
			),
			'local'    => array(
				'label'  => __( 'Local SEO', 'wax-store-catalog' ),
				'fields' => array(
					'business_type' => array(
						'type'    => 'select',
						'label'   => __( 'Business type', 'wax-store-catalog' ),
						'default' => 'MusicStore',
						'options' => array(
							'MusicStore' => __( 'Record shop with a physical store', 'wax-store-catalog' ),
							'Store'      => __( 'Other shop with a physical store', 'wax-store-catalog' ),
							'online'     => __( 'Online only, no store open to the public', 'wax-store-catalog' ),
						),
					),
					'description'   => array(
						'type'  => 'textarea',
						'label' => __( 'Short description', 'wax-store-catalog' ),
						'desc'  => __( 'Two or three sentences: what you sell, your specialties. Used by search engines and AI assistants.', 'wax-store-catalog' ),
					),
					'latitude'      => array(
						'type'  => 'latitude',
						'label' => __( 'Latitude', 'wax-store-catalog' ),
						'desc'  => __( 'Right-click your shop on an online map to copy its coordinates.', 'wax-store-catalog' ),
					),
					'longitude'     => array(
						'type'  => 'longitude',
						'label' => __( 'Longitude', 'wax-store-catalog' ),
					),
					'map_url'       => array(
						'type'  => 'url',
						'label' => __( 'Map link', 'wax-store-catalog' ),
					),
					'area_served'   => array(
						'type'  => 'text',
						'label' => __( 'Area served', 'wax-store-catalog' ),
						'desc'  => __( 'e.g. Paris, Île-de-France, France. Separate with commas.', 'wax-store-catalog' ),
					),
					'price_range'   => array(
						'type'    => 'select',
						'label'   => __( 'Price range', 'wax-store-catalog' ),
						'options' => array(
							''    => __( 'Not specified', 'wax-store-catalog' ),
							'€'   => '€',
							'€€'  => '€€',
							'€€€' => '€€€',
						),
					),
				),
			),
			'network'  => array(
				'label'  => __( 'Network', 'wax-store-catalog' ),
				'fields' => self::network_fields(),
			),
		);
	}

	/**
	 * Social and marketplace profiles.
	 */
	private static function network_fields(): array {
		$networks = array(
			'facebook'   => 'Facebook',
			'instagram'  => 'Instagram',
			'x'          => 'X',
			'bluesky'    => 'Bluesky',
			'mastodon'   => 'Mastodon',
			'tiktok'     => 'TikTok',
			'youtube'    => 'YouTube',
			'soundcloud' => 'SoundCloud',
			'mixcloud'   => 'Mixcloud',
			'bandcamp'   => 'Bandcamp',
			'discogs'    => 'Discogs',
			'cdandlp'    => 'CDandLP',
			'spinlog'    => 'Spinlog',
		);

		$fields = array();

		foreach ( $networks as $key => $label ) {
			$fields[ $key ] = array(
				'type'  => 'url',
				'label' => $label,
			);
		}

		return $fields;
	}

	/**
	 * Network profile URLs that are filled in.
	 *
	 * @return string[]
	 */
	public static function profile_urls(): array {
		$urls = array();

		foreach ( array_keys( self::network_fields() ) as $key ) {
			$url = (string) self::get( 'network', $key );

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Option sanitize callback. The form posts one tab at a time (_tab),
	 * merged into the stored option so other tabs are kept.
	 *
	 * @param mixed $input Posted values.
	 */
	public static function sanitize( $input ): array {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();

		if ( ! is_array( $input ) || empty( $input['_tab'] ) ) {
			return is_array( $input ) ? $input : $current;
		}

		$tab    = sanitize_key( $input['_tab'] );
		$schema = self::schema();

		if ( ! isset( $schema[ $tab ] ) ) {
			return $current;
		}

		$clean = array();

		foreach ( $schema[ $tab ]['fields'] as $key => $field ) {
			$clean[ $key ] = self::sanitize_field( $field, $input[ $key ] ?? '' );
		}

		$current[ $tab ] = $clean;

		return $current;
	}

	/**
	 * Sanitizes one value according to its field type.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function sanitize_field( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'email':
				return sanitize_email( (string) $value );

			case 'url':
				return esc_url_raw( trim( (string) $value ) );

			case 'tel':
				return trim( (string) preg_replace( '/[^0-9+().\s-]/', '', (string) $value ) );

			case 'page':
				return absint( $value );

			case 'date':
				$value = trim( (string) $value );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) ) ? $value : '';

			case 'country':
				$value = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
				return 2 === strlen( $value ) ? $value : '';

			case 'latitude':
			case 'longitude':
				$value = str_replace( ',', '.', trim( (string) $value ) );
				$limit = 'latitude' === $field['type'] ? 90 : 180;
				return ( is_numeric( $value ) && abs( (float) $value ) <= $limit ) ? (string) round( (float) $value, 6 ) : '';

			case 'radio':
			case 'select':
				return array_key_exists( (string) $value, $field['options'] ) ? (string) $value : ( $field['default'] ?? '' );

			case 'hours':
				$hours = array();

				foreach ( array_keys( self::DAYS ) as $day ) {
					$hours[ $day ] = self::sanitize_hours( is_array( $value ) ? ( $value[ $day ] ?? '' ) : '' );
				}

				return $hours;

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * "10h-13h, 14:00 - 19:30" => "10:00-13:00,14:00-19:30". Invalid ranges are dropped.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_hours( $value ): string {
		preg_match_all( '/(\d{1,2})\s*[:h]?\s*(\d{2})?\s*[-–à]\s*(\d{1,2})\s*[:h]?\s*(\d{2})?/u', (string) $value, $matches, PREG_SET_ORDER );

		$ranges = array();

		foreach ( $matches as $match ) {
			$open  = sprintf( '%02d:%02d', (int) $match[1], (int) ( $match[2] ?? 0 ) );
			$close = sprintf( '%02d:%02d', (int) $match[3], (int) ( $match[4] ?? 0 ) );

			if ( (int) $match[1] < 24 && (int) $match[3] <= 24 && $open < $close ) {
				$ranges[] = $open . '-' . $close;
			}
		}

		return implode( ',', array_slice( $ranges, 0, 3 ) );
	}

	/**
	 * Catalog options, stored separately because the catalog code reads them directly.
	 */
	public static function register_catalog_options(): void {
		register_setting(
			'waxsc_catalog',
			'waxsc_currency',
			array(
				'type'              => 'string',
				'default'           => 'EUR',
				'sanitize_callback' => static function ( $value ) {
					$value = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
					return 3 === strlen( $value ) ? $value : 'EUR';
				},
			)
		);

		register_setting(
			'waxsc_catalog',
			'waxsc_grading',
			array(
				'type'              => 'string',
				'default'           => 'goldmine',
				'sanitize_callback' => static function ( $value ) {
					return 'free' === $value ? 'free' : 'goldmine';
				},
			)
		);

		register_setting(
			'waxsc_catalog',
			'waxsc_default_sort',
			array(
				'type'              => 'string',
				'default'           => 'az',
				'sanitize_callback' => static function ( $value ) {
					return array_key_exists( (string) $value, \WaxStore\Catalog\Front\CatalogQuery::sort_options() ) ? (string) $value : 'az';
				},
			)
		);

		register_setting(
			'waxsc_catalog',
			'waxsc_sold_items',
			array(
				'type'              => 'string',
				'default'           => 'hide',
				'sanitize_callback' => static function ( $value ) {
					return 'show' === $value ? 'show' : 'hide';
				},
			)
		);

		register_setting(
			'waxsc_catalog',
			'waxsc_reservation_email',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_email',
			)
		);

		register_setting(
			'waxsc_catalog',
			'waxsc_auto_reserve',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => static function ( $value ) {
					return ! empty( $value );
				},
			)
		);

		register_setting(
			'waxsc_catalog',
			'waxsc_google_feed',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => static function ( $value ) {
					delete_transient( 'waxsc_google_feed_xml' );
					return ! empty( $value );
				},
			)
		);

		register_setting(
			'waxsc_catalog',
			'waxsc_reserve_page',
			array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			)
		);

		register_setting(
			'waxsc_catalog',
			'waxsc_commerce_provider',
			array(
				'type'              => 'string',
				'default'           => 'showcase',
				'sanitize_callback' => static function ( $value ) {
					$providers = waxsc()->commerce()->get_providers();
					return isset( $providers[ $value ] ) ? (string) $value : 'showcase';
				},
			)
		);
	}
}
