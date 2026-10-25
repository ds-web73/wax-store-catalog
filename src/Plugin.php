<?php
/**
 * Plugin bootstrap.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog;

use WaxStore\Catalog\Admin\CoversPage;
use WaxStore\Catalog\Admin\DiscogsExportPage;
use WaxStore\Catalog\Admin\ImportPage;
use WaxStore\Catalog\Admin\LabelsPage;
use WaxStore\Catalog\Admin\Menu;
use WaxStore\Catalog\Admin\PhotoImportPage;
use WaxStore\Catalog\Admin\ReleaseMetaBoxes;
use WaxStore\Catalog\Admin\SettingsPage;
use WaxStore\Catalog\Commerce\Registry;
use WaxStore\Catalog\Commerce\WooSync;
use WaxStore\Catalog\Commerce\Providers\WooCommerceProvider;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\SortKeys;
use WaxStore\Catalog\Content\Taxonomies;
use WaxStore\Catalog\Feeds\GoogleMerchant;
use WaxStore\Catalog\Front\Blocks;
use WaxStore\Catalog\Front\CatalogQuery;
use WaxStore\Catalog\Front\LegalNotice;
use WaxStore\Catalog\Front\Templates;
use WaxStore\Catalog\Reservation\Form;
use WaxStore\Catalog\Alerts\Alerts;
use WaxStore\Catalog\Buying\Offers;
use WaxStore\Catalog\Buying\SellForm;
use WaxStore\Catalog\Alerts\Form as AlertForm;
use WaxStore\Catalog\Reservation\Requests;
use WaxStore\Catalog\Front\LocalBusinessSchema;
use WaxStore\Catalog\Front\ReleaseSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Commerce providers registry.
	 *
	 * @var Registry
	 */
	private Registry $commerce;

	/**
	 * Hooked on plugins_loaded, so that commerce plugins are available.
	 */
	public static function boot(): void {
		self::instance();
	}

	/**
	 * Returns the single instance, creating it on first call.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->commerce = new Registry();
	}

	/**
	 * Registers WordPress hooks.
	 */
	private function hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'init', array( $this, 'register_content' ) );
		add_action( 'set_object_terms', array( Taxonomies::class, 'enforce_single_term' ), 10, 4 );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 20 );

		add_filter(
			'waxsc_commerce_providers',
			static function ( $providers ) {
				$providers[] = new WooCommerceProvider();
				return $providers;
			}
		);
		WooSync::init();
		SortKeys::init();
		Requests::init();
		Form::init();
		Alerts::init();
		Offers::init();
		SellForm::init();
		GoogleMerchant::init();
		AlertForm::init();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'waxsc enrich', CLI\EnrichCommand::class );
		}

		LocalBusinessSchema::init();
		ReleaseSchema::init();
		LegalNotice::init();
		Blocks::init();
		CatalogQuery::init();
		Templates::init();

		if ( is_admin() ) {
			add_action( 'admin_init', array( Installer::class, 'heal_sort_keys' ) );
			ReleaseMetaBoxes::init();
			ImportPage::init();
			PhotoImportPage::init();
			CoversPage::init();
			DiscogsExportPage::init();
			LabelsPage::init();
			Menu::init();
			SettingsPage::init();
		}
	}

	/**
	 * Bundled translations, used until WordPress.org language packs are installed
	 * (those take precedence, as they live in wp-content/languages).
	 */
	public function load_textdomain(): void {
		// Kept on purpose: loads the bundled French translation until a language
		// pack exists on translate.wordpress.org (language packs take precedence).
		load_plugin_textdomain( 'wax-store-catalog', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}

	/**
	 * Registers the catalog post type (when the active provider uses it),
	 * then attaches taxonomies to whatever post type holds the catalog.
	 */
	public function register_content(): void {
		$post_type = $this->commerce->get_catalog_post_type();

		if ( PostType::NAME === $post_type ) {
			PostType::register();
		}

		Taxonomies::register( $post_type );
	}

	/**
	 * Commerce registry accessor.
	 */
	public function commerce(): Registry {
		return $this->commerce;
	}
}
