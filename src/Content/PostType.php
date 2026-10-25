<?php
/**
 * Release post type (catalog in showcase mode).
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Release post type.
 */
final class PostType {

	const NAME = 'waxsc_release';

	/**
	 * Registers the post type and its meta.
	 */
	public static function register(): void {
		register_post_type(
			self::NAME,
			array(
				'labels'        => self::labels(),
				'public'        => true,
				'show_in_rest'  => true,
				'menu_position' => 26,
				'menu_icon'     => 'dashicons-album',
				'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
				'has_archive'   => true,
				'rewrite'       => array(
					'slug'       => _x( 'catalog', 'catalog URL slug', 'wax-store-catalog' ),
					'with_front' => false,
				),
			)
		);

		ReleaseMeta::register();
	}

	/**
	 * Post type labels.
	 */
	private static function labels(): array {
		return array(
			'name'                  => _x( 'Releases', 'post type general name', 'wax-store-catalog' ),
			'singular_name'         => _x( 'Release', 'post type singular name', 'wax-store-catalog' ),
			'menu_name'             => _x( 'Catalog', 'admin menu', 'wax-store-catalog' ),
			'add_new'               => __( 'Add new', 'wax-store-catalog' ),
			'add_new_item'          => __( 'Add new release', 'wax-store-catalog' ),
			'edit_item'             => __( 'Edit release', 'wax-store-catalog' ),
			'new_item'              => __( 'New release', 'wax-store-catalog' ),
			'view_item'             => __( 'View release', 'wax-store-catalog' ),
			'view_items'            => __( 'View catalog', 'wax-store-catalog' ),
			'search_items'          => __( 'Search releases', 'wax-store-catalog' ),
			'not_found'             => __( 'No releases found.', 'wax-store-catalog' ),
			'not_found_in_trash'    => __( 'No releases found in Trash.', 'wax-store-catalog' ),
			'all_items'             => __( 'All releases', 'wax-store-catalog' ),
			'archives'              => __( 'Catalog', 'wax-store-catalog' ),
			'featured_image'        => __( 'Front cover', 'wax-store-catalog' ),
			'set_featured_image'    => __( 'Set front cover', 'wax-store-catalog' ),
			'remove_featured_image' => __( 'Remove front cover', 'wax-store-catalog' ),
			'use_featured_image'    => __( 'Use as front cover', 'wax-store-catalog' ),
			'item_published'        => __( 'Release published.', 'wax-store-catalog' ),
			'item_updated'          => __( 'Release updated.', 'wax-store-catalog' ),
		);
	}
}
