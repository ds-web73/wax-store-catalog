<?php
/**
 * Record shop > Tools > Automatic covers: Cover Art Archive lookup, started by hand.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Covers\Fetcher;

use const WaxStore\Catalog\PLUGIN_FILE;
use const WaxStore\Catalog\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The browser drives the run, one release per request: works on local sites
 * and stops cleanly when the tab is closed.
 */
final class CoversPage {

	const SLUG  = 'waxsc-covers';
	const NONCE = 'waxsc_covers';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_waxsc_covers_count', array( self::class, 'ajax_count' ) );
		add_action( 'wp_ajax_waxsc_covers_next', array( self::class, 'ajax_next' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostType::NAME,
			__( 'Automatic covers', 'wax-store-catalog' ),
			__( 'Automatic covers', 'wax-store-catalog' ),
			'upload_files',
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( $hook ) {
			add_action( 'admin_print_scripts-' . $hook, array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * Script.
	 */
	public static function enqueue(): void {
		wp_enqueue_script( 'waxsc-covers', plugin_dir_url( PLUGIN_FILE ) . 'assets/covers.js', array(), VERSION, true );
		wp_add_inline_script(
			'waxsc-covers',
			'window.waxscCovers = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE ),
					'i18n'    => array(
						/* translators: %d: number of releases. */
						'left'    => __( '%d releases left', 'wax-store-catalog' ),
						'done'    => __( 'Finished.', 'wax-store-catalog' ),
						'stopped' => __( 'Stopped. Start again to resume.', 'wax-store-catalog' ),
						/* translators: 1: covers found, 2: covers of another edition, 3: not found. */
						'summary' => __( 'Covers: %1$d, from another edition: %2$d, not found: %3$d', 'wax-store-catalog' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Screen.
	 */
	public static function render(): void {
		$remaining = Fetcher::remaining( false );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Automatic covers', 'wax-store-catalog' ); ?></h1>
			<p><?php esc_html_e( 'Looks for the cover of releases without photo in the Cover Art Archive, the open cover database of MusicBrainz and the Internet Archive. The pressing is identified by its Discogs ID, then its barcode, then its artist and catalog number.', 'wax-store-catalog' ); ?></p>
			<p><?php esc_html_e( 'These covers are shown with the mention "Illustration", since your copy may differ. Your own photos always take priority: importing a photo replaces the illustration.', 'wax-store-catalog' ); ?></p>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'External service: for each release, its Discogs ID, barcode, catalog number and artist are sent to musicbrainz.org, and the cover is downloaded from coverartarchive.org (Internet Archive). Covers remain the property of their rights holders; the archive provides them without any licence. Displaying them is your decision.', 'wax-store-catalog' ); ?></p>
			</div>

			<form id="waxsc-covers-form">
				<p>
					<label><input type="checkbox" id="waxsc-covers-group" checked> <?php esc_html_e( 'When the exact pressing has no cover, use the cover of another edition of the same album', 'wax-store-catalog' ); ?></label><br>
					<label><input type="checkbox" id="waxsc-covers-retry"> <?php esc_html_e( 'Try again the releases already searched without success', 'wax-store-catalog' ); ?></label>
				</p>
				<p>
					<?php
					/* translators: %d: number of releases. */
					echo esc_html( sprintf( _n( '%d release without cover has not been searched yet.', '%d releases without cover have not been searched yet.', $remaining, 'wax-store-catalog' ), $remaining ) );
					?>
					<?php esc_html_e( 'MusicBrainz allows one request per second: count about one hour for 1,500 releases. Keep this page open; you can stop and resume.', 'wax-store-catalog' ); ?>
				</p>
				<p>
					<button type="submit" class="button button-primary" data-waxsc-start><?php esc_html_e( 'Start', 'wax-store-catalog' ); ?></button>
					<button type="button" class="button" data-waxsc-stop hidden><?php esc_html_e( 'Stop', 'wax-store-catalog' ); ?></button>
				</p>
			</form>

			<div id="waxsc-covers-progress" hidden>
				<p aria-live="polite" data-waxsc-status></p>
				<p data-waxsc-summary></p>
				<ul data-waxsc-log style="max-height:24rem;overflow:auto;"></ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Common checks.
	 */
	private static function check(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( null, 403 );
		}
	}

	/**
	 * Releases left.
	 */
	public static function ajax_count(): void {
		self::check();
		wp_send_json_success( array( 'left' => Fetcher::remaining( ! empty( $_POST['retry'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in check().
	}

	/**
	 * One release.
	 */
	public static function ajax_next(): void {
		self::check();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check().
		$retry  = ! empty( $_POST['retry'] );
		$group  = ! empty( $_POST['group'] );
		$after  = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;
		// phpcs:enable

		$result = Fetcher::next( $retry, $group, $retry ? $after : 0 );

		wp_send_json_success( $result );
	}
}
