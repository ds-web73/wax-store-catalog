<?php
/**
 * Suggests the Wax Store theme on the Record shop screens, when another
 * theme is active. Dismissible for good.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin works with any theme: this is only a suggestion.
 */
final class ThemeNotice {

	const META = 'waxsc_dismiss_theme_notice';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_init', array( self::class, 'dismiss' ) );
	}

	/**
	 * Notice on the Record shop screens only.
	 */
	public static function notice(): void {
		$screen = get_current_screen();
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		if ( ! $screen || ! in_array( $page, array( Menu::TOOLS, SettingsPage::SLUG ), true ) || ! current_user_can( 'switch_themes' ) ) {
			return;
		}

		if ( 'wax-store' === get_template() || get_user_meta( get_current_user_id(), self::META, true ) ) {
			return;
		}

		$installed = wp_get_theme( 'wax-store' )->exists();
		$url       = $installed
			? admin_url( 'themes.php?theme=wax-store' )
			: admin_url( 'theme-install.php?theme=wax-store' );
		$dismiss   = wp_nonce_url( add_query_arg( 'waxsc-dismiss-theme', '1' ), 'waxsc_dismiss_theme' );
		?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'Wax Store Catalog works with any theme. For a design made for record shops (price stickers, bin dividers, vinyl-style release pages), try its companion theme, Wax Store.', 'wax-store-catalog' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $installed ? __( 'Preview Wax Store', 'wax-store-catalog' ) : __( 'Get the Wax Store theme', 'wax-store-catalog' ) ); ?></a>
				<a class="button" href="https://github.com/ds-web73/wax-store"><?php esc_html_e( 'More about the theme', 'wax-store-catalog' ); ?></a>
				<a href="<?php echo esc_url( $dismiss ); ?>" style="margin-left:8px"><?php esc_html_e( 'Dismiss', 'wax-store-catalog' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismisses the notice for the current user, for good.
	 */
	public static function dismiss(): void {
		if ( empty( $_GET['waxsc-dismiss-theme'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
			return;
		}

		check_admin_referer( 'waxsc_dismiss_theme' );
		update_user_meta( get_current_user_id(), self::META, 1 );
		wp_safe_redirect( remove_query_arg( array( 'waxsc-dismiss-theme', '_wpnonce' ) ) );
		exit;
	}
}
