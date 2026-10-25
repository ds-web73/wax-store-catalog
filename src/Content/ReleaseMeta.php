<?php
/**
 * Release meta registration.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Meta keys start with an underscore: they are edited through the plugin's
 * meta boxes, not the generic Custom Fields panel, but remain readable in REST.
 */
final class ReleaseMeta {

	const YEAR           = '_waxsc_year';
	const CATALOG_NUMBER = '_waxsc_catalog_number';
	const BARCODE        = '_waxsc_barcode';
	const TRACKLIST      = '_waxsc_tracklist';
	const COPIES         = '_waxsc_copies';
	const DISCOGS_ID     = '_waxsc_discogs_id';
	const IMPORT_KEY     = '_waxsc_import_key';
	const PRESSING       = '_waxsc_pressing_details';

	/**
	 * Registers all release meta.
	 */
	public static function register(): void {
		$auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		self::register_identifiers();
		Gallery::register();

		register_post_meta(
			PostType::NAME,
			self::YEAR,
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_year' ),
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			PostType::NAME,
			self::CATALOG_NUMBER,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_catalog_number' ),
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			PostType::NAME,
			self::PRESSING,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_pressing' ),
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			PostType::NAME,
			self::BARCODE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( Barcode::class, 'normalize' ),
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			PostType::NAME,
			self::TRACKLIST,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( Tracklist::class, 'sanitize' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'position' => array( 'type' => 'string' ),
								'title'    => array( 'type' => 'string' ),
								'duration' => array( 'type' => 'string' ),
							),
						),
					),
				),
			)
		);

		register_post_meta(
			PostType::NAME,
			self::COPIES,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( Copies::class, 'sanitize' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'      => array( 'type' => 'string' ),
								'ext'     => array( 'type' => 'string' ),
								'media'   => array( 'type' => 'string' ),
								'sleeve'  => array( 'type' => 'string' ),
								'variant' => array( 'type' => 'string' ),
								'note'    => array( 'type' => 'string' ),
								'location' => array( 'type' => 'string' ),
								'price'   => array( 'type' => 'string' ),
								'qty'     => array( 'type' => 'integer' ),
								'status'  => array(
									'type' => 'string',
									'enum' => array( 'in_stock', 'reserved', 'sold' ),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Discogs release ID (an identifier only, no Discogs content is stored).
	 */
	public static function register_identifiers(): void {
		register_post_meta(
			PostType::NAME,
			self::DISCOGS_ID,
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * Year between the first commercial records (1877) and next year, 0 otherwise.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_year( $value ): int {
		$year = (int) $value;
		$max  = (int) gmdate( 'Y' ) + 1;

		return ( $year >= 1877 && $year <= $max ) ? $year : 0;
	}

	/**
	 * Free description of the pressing ("Transparent red, marbled black, 500 copies").
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_pressing( $value ): string {
		return mb_substr( sanitize_text_field( (string) $value ), 0, 200 );
	}

	/**
	 * Catalog number as printed on the release ("BST 84003", "CBS 32110").
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_catalog_number( $value ): string {
		return mb_substr( sanitize_text_field( (string) $value ), 0, 60 );
	}
}
