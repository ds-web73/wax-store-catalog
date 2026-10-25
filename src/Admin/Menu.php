<?php
/**
 * "Record shop" admin menu: tools and customer requests in tabbed screens,
 * so that the Catalog menu only holds releases and their taxonomies.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

use WaxStore\Catalog\Alerts\Alerts;
use WaxStore\Catalog\Buying\Offers;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Reservation\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Menu: Record shop > Tools, Customer requests, Settings.
 */
final class Menu {

	const TOOLS = 'waxsc-tools';

	/**
	 * Tools hook suffix.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register' ), 9 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'request_tabs' ), 1 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ) );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ) );
	}

	/**
	 * Tools tabs: key => [ label, capability, class ].
	 */
	public static function tools(): array {
		return array(
			'import'  => array( __( 'CSV import', 'wax-store-catalog' ), 'edit_others_posts', ImportPage::class ),
			'photos'  => array( __( 'Photos', 'wax-store-catalog' ), 'upload_files', PhotoImportPage::class ),
			'covers'  => array( __( 'Automatic covers', 'wax-store-catalog' ), 'upload_files', CoversPage::class ),
			'discogs' => array( __( 'Discogs export', 'wax-store-catalog' ), 'edit_others_posts', DiscogsExportPage::class ),
			'labels'  => array( __( 'Labels', 'wax-store-catalog' ), 'edit_posts', LabelsPage::class ),
		);
	}

	/**
	 * Address of a tools tab.
	 *
	 * @param string $tab  Tab key.
	 * @param array  $args Extra arguments.
	 */
	public static function tool_url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::TOOLS, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Current tools tab the user may open.
	 */
	private static function current_tool(): string {
		$tools = array_filter(
			self::tools(),
			static function ( $tool ) {
				return current_user_can( $tool[1] );
			}
		);
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.

		return isset( $tools[ $tab ] ) ? $tab : (string) array_key_first( $tools );
	}

	/**
	 * Menu entries.
	 */
	public static function register(): void {
		self::$hook = (string) add_menu_page(
			__( 'Record shop', 'wax-store-catalog' ),
			__( 'Record shop', 'wax-store-catalog' ),
			'edit_posts',
			self::TOOLS,
			array( self::class, 'render_tools' ),
			'dashicons-store',
			27
		);

		add_submenu_page( self::TOOLS, __( 'Tools', 'wax-store-catalog' ), __( 'Tools', 'wax-store-catalog' ), 'edit_posts', self::TOOLS, array( self::class, 'render_tools' ) );
		add_submenu_page( self::TOOLS, __( 'Customer requests', 'wax-store-catalog' ), __( 'Customer requests', 'wax-store-catalog' ), 'edit_others_posts', 'edit.php?post_type=' . Requests::POST_TYPE );
	}

	/**
	 * Scripts of the open tools tab.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}

		$class = self::tools()[ self::current_tool() ][2] ?? '';

		if ( $class && method_exists( $class, 'enqueue' ) ) {
			$class::enqueue();
		}
	}

	/**
	 * Tools screen: tabs, then the screen of the open tool.
	 */
	public static function render_tools(): void {
		$current = self::current_tool();
		$class   = self::tools()[ $current ][2] ?? '';

		echo '<div class="wrap"><h1>' . esc_html__( 'Tools', 'wax-store-catalog' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Tools', 'wax-store-catalog' ) . '">';

		foreach ( self::tools() as $key => $tool ) {
			if ( current_user_can( $tool[1] ) ) {
				printf(
					'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
					esc_url( self::tool_url( $key ) ),
					$key === $current ? ' nav-tab-active' : '',
					$key === $current ? ' aria-current="page"' : '',
					esc_html( $tool[0] )
				);
			}
		}

		echo '</nav>';

		if ( $class ) {
			ob_start();
			$class::render();
			$html = (string) ob_get_clean();

			// Each tool prints its own wrap and title: keep only its content, under the tabs.
			$html = (string) preg_replace( '#^\s*<div class="wrap">\s*<h1>.*?</h1>#s', '<div class="waxsc-tool">', $html, 1 );
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by each tool.
		}

		echo '</div>';
	}

	/**
	 * Customer requests screens: post type => tab label.
	 */
	private static function requests(): array {
		return array(
			Requests::POST_TYPE => __( 'Reservations', 'wax-store-catalog' ),
			Alerts::POST_TYPE   => __( 'Alerts', 'wax-store-catalog' ),
			Offers::POST_TYPE   => __( 'Collection offers', 'wax-store-catalog' ),
		);
	}

	/**
	 * Post type of the current customer requests screen, if any.
	 */
	private static function request_screen(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return ( $screen && isset( self::requests()[ $screen->post_type ] ) ) ? (string) $screen->post_type : '';
	}

	/**
	 * Tabs above the three customer requests lists.
	 */
	public static function request_tabs(): void {
		$current = self::request_screen();
		$screen  = get_current_screen();

		if ( '' === $current || 'edit' !== $screen->base ) {
			return;
		}

		echo '<nav class="nav-tab-wrapper waxsc-request-tabs" aria-label="' . esc_attr__( 'Customer requests', 'wax-store-catalog' ) . '" style="margin:1rem 20px 0 2px">';

		foreach ( self::requests() as $type => $label ) {
			$count = wp_count_posts( $type );
			$total = (int) ( $count->publish ?? 0 ) + (int) ( $count->pending ?? 0 );

			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s <span class="count">(%5$s)</span></a>',
				esc_url( admin_url( 'edit.php?post_type=' . $type ) ),
				$type === $current ? ' nav-tab-active' : '',
				$type === $current ? ' aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $total ) )
			);
		}

		echo '</nav>';
	}

	/**
	 * Keeps the Record shop menu open on customer requests screens.
	 *
	 * @param string $parent Parent file.
	 */
	public static function parent_file( $parent ) {
		return '' !== self::request_screen() ? self::TOOLS : $parent;
	}

	/**
	 * Highlights "Customer requests".
	 *
	 * @param string $submenu Submenu file.
	 */
	public static function submenu_file( $submenu ) {
		return '' !== self::request_screen() ? 'edit.php?post_type=' . Requests::POST_TYPE : $submenu;
	}
}
