<?php
/**
 * Record shop > Tools > Discogs export: delete file for the listings sold on the site.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Discogs\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Screen and downloads.
 */
final class DiscogsExportPage {

	const SLUG = 'waxsc-discogs-export';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_waxsc_discogs_export', array( self::class, 'download' ) );
		add_action( 'admin_post_waxsc_discogs_redownload', array( self::class, 'redownload' ) );
	}

	/**
	 * Submenu, with the number of listings waiting.
	 */
	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostType::NAME,
			__( 'Discogs export', 'wax-store-catalog' ),
			__( 'Discogs export', 'wax-store-catalog' ),
			'edit_others_posts',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Screen.
	 */
	public static function render(): void {
		$reserved = ! empty( $_GET['reserved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display option.
		$pending  = Export::pending( $reserved );
		$history  = Export::history();
		$base     = Menu::tool_url( 'discogs' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Discogs export', 'wax-store-catalog' ); ?></h1>
			<p><?php esc_html_e( 'Copies sold on your site that still have a listing on Discogs. Download the file, then on Discogs open the inventory upload page and choose the delete option: these listings are removed in one go, so the same copy can never be sold twice.', 'wax-store-catalog' ); ?></p>
			<p><?php esc_html_e( 'The other way round, copies sold on Discogs are marked sold on your site when you import a full Discogs inventory export with the option "Mark as sold the Discogs copies that are not in this file".', 'wax-store-catalog' ); ?></p>

			<p>
				<?php if ( $reserved ) : ?>
					<a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Sold copies only', 'wax-store-catalog' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'reserved', '1', $base ) ); ?>"><?php esc_html_e( 'Also include reserved copies', 'wax-store-catalog' ); ?></a>
				<?php endif; ?>
			</p>

			<?php if ( ! $pending ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Nothing to remove from Discogs: every copy sold on the site is already handled.', 'wax-store-catalog' ); ?></p></div>
			<?php else : ?>
				<h2>
					<?php
					/* translators: %d: number of listings. */
					echo esc_html( sprintf( _n( '%d listing to remove', '%d listings to remove', count( $pending ), 'wax-store-catalog' ), count( $pending ) ) );
					?>
				</h2>
				<table class="widefat striped" style="max-width:60rem">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Release', 'wax-store-catalog' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Copy', 'wax-store-catalog' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'wax-store-catalog' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Location in the shop', 'wax-store-catalog' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Discogs listing', 'wax-store-catalog' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pending as $row ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['release'] ) ); ?>"><?php echo esc_html( $row['label'] ); ?></a></td>
								<td><?php echo esc_html( $row['copy'] ); ?></td>
								<td><?php echo esc_html( ProviderInterface::RESERVED === $row['status'] ? __( 'Reserved', 'wax-store-catalog' ) : __( 'Sold', 'wax-store-catalog' ) ); ?></td>
								<td><?php echo esc_html( $row['location'] ?? '' ); ?></td>
								<td><code><?php echo esc_html( $row['listing'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="waxsc_discogs_export">
					<input type="hidden" name="reserved" value="<?php echo $reserved ? '1' : ''; ?>">
					<?php wp_nonce_field( 'waxsc_discogs_export' ); ?>
					<?php submit_button( __( 'Download the delete file for Discogs', 'wax-store-catalog' ) ); ?>
					<p class="description"><?php esc_html_e( 'These listings are then considered handled and will not be exported again. Past files stay available below.', 'wax-store-catalog' ); ?></p>
				</form>
			<?php endif; ?>

			<?php if ( $history ) : ?>
				<h2><?php esc_html_e( 'Previous exports', 'wax-store-catalog' ); ?></h2>
				<ul class="ul-disc">
					<?php foreach ( $history as $index => $export ) : ?>
						<li>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: date, 2: number of listings. */
									_n( '%1$s: %2$d listing', '%1$s: %2$d listings', count( $export['listings'] ), 'wax-store-catalog' ),
									wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $export['date'] ),
									count( $export['listings'] )
								)
							);
							?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=waxsc_discogs_redownload&export=' . $index ), 'waxsc_discogs_redownload' ) ); ?>"><?php esc_html_e( 'Download again', 'wax-store-catalog' ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Sends a CSV file.
	 *
	 * @param string $csv  Content.
	 * @param int    $time Export time.
	 */
	private static function send( string $csv, int $time ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="discogs-delete-' . gmdate( 'Y-m-d-His', $time ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV of numeric listing IDs.
		exit;
	}

	/**
	 * New export.
	 */
	public static function download(): void {
		check_admin_referer( 'waxsc_discogs_export' );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to export releases.', 'wax-store-catalog' ), 403 );
		}

		$pending = Export::pending( ! empty( $_POST['reserved'] ) );

		if ( ! $pending ) {
			wp_safe_redirect( Menu::tool_url( 'discogs' ) );
			exit;
		}

		self::send( Export::create( $pending ), time() );
	}

	/**
	 * Past export.
	 */
	public static function redownload(): void {
		check_admin_referer( 'waxsc_discogs_redownload' );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to export releases.', 'wax-store-catalog' ), 403 );
		}

		$history = Export::history();
		$index   = isset( $_GET['export'] ) ? absint( $_GET['export'] ) : 0;

		if ( ! isset( $history[ $index ] ) ) {
			wp_die( esc_html__( 'This export no longer exists.', 'wax-store-catalog' ), 404 );
		}

		self::send( Export::csv( $history[ $index ]['listings'] ), (int) $history[ $index ]['date'] );
	}
}
