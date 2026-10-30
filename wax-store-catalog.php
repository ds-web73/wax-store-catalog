<?php
/**
 * Plugin Name:       Wax Store Catalog
 * Description:       Record shop catalog for vinyl, CD, cassettes and merchandise: releases, formats, labels, conditions and structured data. Companion plugin of the Wax Store theme, works with any theme.
 * Version:           0.31.2
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Plugin URI:        https://github.com/ds-web73/wax-store-catalog
 * Author:            DS-Web
 * Author URI:        https://github.com/ds-web73
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wax-store-catalog
 * Domain Path:       /languages
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.31.2';
const PLUGIN_FILE = __FILE__;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

require __DIR__ . '/src/functions.php';

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
