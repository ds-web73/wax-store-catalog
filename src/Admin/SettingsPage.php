<?php
/**
 * Record shop > Settings screen, one tab per settings group.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

use WaxStore\Catalog\Content\Grading;
use WaxStore\Catalog\Settings\Settings;

use const WaxStore\Catalog\PLUGIN_FILE;
use const WaxStore\Catalog\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Tabbed settings page. Lives under Settings so it stays reachable
 * whatever the commerce provider (the catalog menu may change with it).
 */
final class SettingsPage {

	const SLUG = 'waxsc-settings';

	/**
	 * Hook suffix of the settings screen.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 11 );
		add_action( 'admin_menu', array( self::class, 'old_address' ), 1 );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( self::class, 'action_link' ) );
		add_action( 'admin_post_waxsc_create_reservation_page', array( self::class, 'create_reservation_page' ) );
		add_action( 'admin_post_waxsc_create_sell_page', array( self::class, 'create_sell_page' ) );
	}

	/**
	 * Registers options with the Settings API.
	 */
	public static function register(): void {
		register_setting(
			'waxsc_shop',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
			)
		);

		Settings::register_catalog_options();
	}

	/**
	 * Menu entry.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			Menu::TOOLS,
			__( 'Record shop settings', 'wax-store-catalog' ),
			__( 'Settings', 'wax-store-catalog' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( $hook ) {
			self::$hook = $hook;
			add_action( 'admin_print_styles-' . $hook, array( self::class, 'enqueue' ) );
			add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		}
	}

	/**
	 * The settings used to live under Settings > Record shop: old links still work.
	 */
	public static function old_address(): void {
		global $pagenow;

		if ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect only.
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect only.
			wp_safe_redirect( add_query_arg( array_filter( array( 'page' => self::SLUG, 'tab' => $tab ) ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Creates a "Reservation" page holding the form block, and selects it.
	 */
	public static function create_reservation_page(): void {
		check_admin_referer( 'waxsc_create_reservation_page' );

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to create pages.', 'wax-store-catalog' ), 403 );
		}

		$page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Reservation', 'wax-store-catalog' ),
				'post_name'    => sanitize_title( _x( 'reservation', 'page slug', 'wax-store-catalog' ) ),
				'post_content' => '<!-- wp:paragraph --><p>' . esc_html__( 'Fill in this form: we keep the record for you and get back to you quickly.', 'wax-store-catalog' ) . '</p><!-- /wp:paragraph -->' . "\n" . '<!-- wp:waxsc/reservation-form /-->',
			),
			true
		);

		if ( ! is_wp_error( $page ) ) {
			update_option( 'waxsc_reserve_page', (int) $page );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=catalog' ) );
		exit;
	}

	/**
	 * Creates a "Sell us your records" page holding the form block.
	 */
	public static function create_sell_page(): void {
		check_admin_referer( 'waxsc_create_sell_page' );

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to create pages.', 'wax-store-catalog' ), 403 );
		}

		$page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Sell us your records', 'wax-store-catalog' ),
				'post_name'    => sanitize_title( _x( 'sell-your-records', 'page slug', 'wax-store-catalog' ) ),
				'post_content' => '<!-- wp:paragraph --><p>' . esc_html__( 'We buy records and collections of any size. Tell us about yours: we answer every offer.', 'wax-store-catalog' ) . '</p><!-- /wp:paragraph -->' . "\n" . '<!-- wp:waxsc/sell-form /-->',
			),
			true
		);

		if ( ! is_wp_error( $page ) ) {
			update_option( 'waxsc_sell_page', (int) $page );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=catalog' ) );
		exit;
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 */
	public static function action_link( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Settings', 'wax-store-catalog' ) )
		);

		return $links;
	}

	/**
	 * Assets.
	 */
	public static function enqueue(): void {
		$url = plugin_dir_url( PLUGIN_FILE ) . 'assets/';
		wp_enqueue_style( 'waxsc-settings', $url . 'settings.css', array(), VERSION );
		wp_enqueue_script( 'waxsc-settings', $url . 'settings.js', array(), VERSION, true );
	}

	/**
	 * Hides professional-only fields for individuals, on every tab.
	 *
	 * @param string $classes Body classes.
	 */
	public static function body_class( string $classes ): string {
		$screen = get_current_screen();

		if ( $screen && self::$hook === $screen->id && ! Settings::is_professional() ) {
			$classes .= ' waxsc-individual';
		}

		return $classes;
	}

	/**
	 * Tabs: shop settings plus the catalog tab.
	 */
	private static function tabs(): array {
		$tabs = array();

		foreach ( Settings::schema() as $key => $tab ) {
			$tabs[ $key ] = $tab['label'];
		}

		$tabs['catalog'] = __( 'Catalog', 'wax-store-catalog' );

		return $tabs;
	}

	/**
	 * Screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs    = self::tabs();
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'identity'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$current = isset( $tabs[ $current ] ) ? $current : 'identity';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Record shop settings', 'wax-store-catalog' ); ?></h1>
			<?php settings_errors(); ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'wax-store-catalog' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $key ) ); ?>" class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php
				if ( 'catalog' === $current ) {
					settings_fields( 'waxsc_catalog' );
					self::render_catalog_tab();
				} else {
					settings_fields( 'waxsc_shop' );
					printf( '<input type="hidden" name="%s[_tab]" value="%s">', esc_attr( Settings::OPTION ), esc_attr( $current ) );
					self::render_tab( $current );
				}

				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Generic tab from the settings schema.
	 *
	 * @param string $tab Tab key.
	 */
	private static function render_tab( string $tab ): void {
		$schema = Settings::schema()[ $tab ];

		if ( 'network' === $tab ) {
			echo '<p>' . esc_html__( 'These profiles are linked from the site and declared to search engines as belonging to your shop.', 'wax-store-catalog' ) . '</p>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $schema['fields'] as $key => $field ) {
			$id    = 'waxsc-' . $tab . '-' . $key;
			$class = ! empty( $field['pro'] ) ? ' class="waxsc-pro-only"' : '';

			echo '<tr' . $class . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute.

			if ( in_array( $field['type'], array( 'radio', 'hours' ), true ) ) {
				echo '<th scope="row">' . esc_html( $field['label'] ) . '</th>';
			} else {
				printf( '<th scope="row"><label for="%1$s">%2$s</label></th>', esc_attr( $id ), esc_html( $field['label'] ) );
			}

			echo '<td>';
			self::render_field( $tab, $key, $field, $id );

			if ( ! empty( $field['desc'] ) ) {
				printf( '<p class="description" id="%1$s-desc">%2$s</p>', esc_attr( $id ), esc_html( $field['desc'] ) );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One field.
	 *
	 * @param string $tab   Tab key.
	 * @param string $key   Field key.
	 * @param array  $field Field definition.
	 * @param string $id    HTML ID.
	 */
	private static function render_field( string $tab, string $key, array $field, string $id ): void {
		$name  = Settings::OPTION . '[' . $key . ']';
		$value = Settings::get( $tab, $key );
		$desc  = ! empty( $field['desc'] ) ? ' aria-describedby="' . esc_attr( $id . '-desc' ) . '"' : '';

		switch ( $field['type'] ) {
			case 'radio':
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';

				foreach ( $field['options'] as $option => $label ) {
					printf(
						'<label class="waxsc-radio"><input type="radio" name="%1$s" value="%2$s" data-waxsc-owner-type%3$s> %4$s</label>',
						esc_attr( $name ),
						esc_attr( $option ),
						checked( $value, $option, false ),
						esc_html( $label )
					);
				}

				echo '</fieldset>';
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s"%3$s>', esc_attr( $id ), esc_attr( $name ), $desc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

				foreach ( $field['options'] as $option => $label ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $label ) );
				}

				echo '</select>';
				break;

			case 'textarea':
				printf( '<textarea id="%1$s" name="%2$s" rows="3" class="large-text"%3$s>%4$s</textarea>', esc_attr( $id ), esc_attr( $name ), $desc, esc_textarea( (string) $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				break;

			case 'page':
				wp_dropdown_pages(
					array(
						'name'              => esc_attr( $name ),
						'id'                => esc_attr( $id ),
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( '— Select a page —', 'wax-store-catalog' ),
						'option_none_value' => '0',
					)
				);
				break;

			case 'hours':
				echo '<fieldset class="waxsc-hours"><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
				$labels = self::day_labels();

				foreach ( array_keys( Settings::DAYS ) as $day ) {
					$day_value = is_array( $value ) ? (string) ( $value[ $day ] ?? '' ) : '';
					printf(
						'<label for="%1$s-%2$s">%3$s</label><input type="text" id="%1$s-%2$s" name="%4$s[%2$s]" value="%5$s" placeholder="%6$s">',
						esc_attr( $id ),
						esc_attr( $day ),
						esc_html( $labels[ $day ] ),
						esc_attr( $name ),
						esc_attr( str_replace( ',', ', ', $day_value ) ),
						esc_attr__( 'Closed', 'wax-store-catalog' )
					);
				}

				echo '</fieldset>';
				break;

			default:
				$types = array(
					'date'      => 'date',
					'email'     => 'email',
					'url'       => 'url',
					'tel'       => 'tel',
					'latitude'  => 'text',
					'longitude' => 'text',
				);
				$type  = $types[ $field['type'] ] ?? 'text';
				$list  = ! empty( $field['list'] ) ? ' list="' . esc_attr( $id . '-list' ) . '"' : '';
				$class = 'country' === $field['type'] ? 'small-text' : 'regular-text';

				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s"%6$s%7$s>',
					esc_attr( $type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $class ),
					$list, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					$desc // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				);

				if ( '' !== $list ) {
					printf( '<datalist id="%s">', esc_attr( $id . '-list' ) );

					foreach ( $field['list'] as $suggestion ) {
						printf( '<option value="%s">', esc_attr( $suggestion ) );
					}

					echo '</datalist>';
				}
		}
	}

	/**
	 * Translated day names.
	 */
	private static function day_labels(): array {
		return array(
			'mon' => __( 'Monday', 'wax-store-catalog' ),
			'tue' => __( 'Tuesday', 'wax-store-catalog' ),
			'wed' => __( 'Wednesday', 'wax-store-catalog' ),
			'thu' => __( 'Thursday', 'wax-store-catalog' ),
			'fri' => __( 'Friday', 'wax-store-catalog' ),
			'sat' => __( 'Saturday', 'wax-store-catalog' ),
			'sun' => __( 'Sunday', 'wax-store-catalog' ),
		);
	}

	/**
	 * Catalog tab: currency, grading, reservation page and selling engine.
	 */
	private static function render_catalog_tab(): void {
		$providers = waxsc()->commerce()->get_providers();
		$active    = waxsc()->commerce()->get_active()->get_id();
		$grading   = Grading::mode();
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="waxsc-commerce-provider"><?php esc_html_e( 'Selling mode', 'wax-store-catalog' ); ?></label></th>
					<td>
						<select id="waxsc-commerce-provider" name="waxsc_commerce_provider">
							<?php foreach ( $providers as $id => $provider ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $active, $id ); ?>><?php echo esc_html( $provider->get_label() ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Other selling modes appear here when a compatible plugin is active.', 'wax-store-catalog' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="waxsc-default-sort"><?php esc_html_e( 'Default catalog order', 'wax-store-catalog' ); ?></label></th>
					<td>
						<select id="waxsc-default-sort" name="waxsc_default_sort">
							<?php foreach ( \WaxStore\Catalog\Front\CatalogQuery::sort_options() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( \WaxStore\Catalog\Front\CatalogQuery::default_sort(), $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Visitors can change it with the sort menu above the catalog. Artists are filed without their leading article: The Beatles under B.', 'wax-store-catalog' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sold releases', 'wax-store-catalog' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Sold releases', 'wax-store-catalog' ); ?></legend>
							<?php $waxsc_sold = \WaxStore\Catalog\Front\CatalogQuery::sold_setting(); ?>
							<label class="waxsc-radio"><input type="radio" name="waxsc_sold_items" value="hide" <?php checked( $waxsc_sold, 'hide' ); ?>> <?php esc_html_e( 'Hidden from the catalog and home page grids', 'wax-store-catalog' ); ?></label>
							<label class="waxsc-radio"><input type="radio" name="waxsc_sold_items" value="show" <?php checked( $waxsc_sold, 'show' ); ?>> <?php esc_html_e( 'Shown with a "Sold" sticker; visitors can hide them with a filter', 'wax-store-catalog' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Either way, the page of a sold release stays online: it keeps its search ranking and lets collectors ask you to look out for it.', 'wax-store-catalog' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Google Merchant Center', 'wax-store-catalog' ); ?></th>
					<td>
						<?php $waxsc_feed_on = (bool) get_option( 'waxsc_google_feed' ); ?>
						<label><input type="checkbox" name="waxsc_google_feed" value="1" <?php checked( $waxsc_feed_on ); ?>> <?php esc_html_e( 'Publish a product feed for Google Shopping (free listings)', 'wax-store-catalog' ); ?></label>
						<?php if ( $waxsc_feed_on ) : ?>
							<p><?php esc_html_e( 'Feed address to add in Merchant Center (Products > Feeds, scheduled fetch):', 'wax-store-catalog' ); ?><br><code><?php echo esc_html( \WaxStore\Catalog\Feeds\GoogleMerchant::url() ); ?></code></p>
							<?php
							$waxsc_stats = get_option( \WaxStore\Catalog\Feeds\GoogleMerchant::STATS );

							if ( is_array( $waxsc_stats ) ) {
								echo '<p class="description">' . esc_html(
									sprintf(
										/* translators: 1: products in the feed, 2: releases without photo, 3: releases without price. */
										__( 'Last generation: %1$d products in the feed. Left out: %2$d without photo (Google refuses products without a real image), %3$d without price.', 'wax-store-catalog' ),
										(int) $waxsc_stats['items'],
										(int) $waxsc_stats['no_image'],
										(int) $waxsc_stats['no_price']
									)
								) . '</p>';
							}
							?>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'One product per release in stock, at the lowest price of its copies. Shipping and returns are set in your Merchant Center account.', 'wax-store-catalog' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="waxsc-currency"><?php esc_html_e( 'Currency', 'wax-store-catalog' ); ?></label></th>
					<td>
						<input type="text" id="waxsc-currency" name="waxsc_currency" value="<?php echo esc_attr( (string) get_option( 'waxsc_currency', 'EUR' ) ); ?>" class="small-text" maxlength="3" aria-describedby="waxsc-currency-desc">
						<p class="description" id="waxsc-currency-desc"><?php esc_html_e( 'ISO code: EUR, USD, GBP, CHF…', 'wax-store-catalog' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Grading', 'wax-store-catalog' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Grading', 'wax-store-catalog' ); ?></legend>
							<label class="waxsc-radio"><input type="radio" name="waxsc_grading" value="goldmine" <?php checked( $grading, Grading::MODE_GOLDMINE ); ?>> <?php esc_html_e( 'Goldmine standard (M, NM, VG+, VG, G+, G, F, P)', 'wax-store-catalog' ); ?></label>
							<label class="waxsc-radio"><input type="radio" name="waxsc_grading" value="free" <?php checked( $grading, Grading::MODE_FREE ); ?>> <?php esc_html_e( 'Free text, your own wording', 'wax-store-catalog' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Switching to Goldmine clears grades that do not match the scale the next time a release is saved.', 'wax-store-catalog' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="waxsc-reserve-page"><?php esc_html_e( 'Reservation page', 'wax-store-catalog' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'waxsc_reserve_page',
								'id'                => 'waxsc-reserve-page',
								'selected'          => (int) get_option( 'waxsc_reserve_page', 0 ),
								'show_option_none'  => esc_html__( '— No reservation button —', 'wax-store-catalog' ),
								'option_none_value' => '0',
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Page holding the Reservation form block. The "Reserve" buttons link to it with the chosen record and copy.', 'wax-store-catalog' ); ?></p>
						<?php if ( ! get_option( 'waxsc_reserve_page' ) ) : ?>
							<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=waxsc_create_reservation_page' ), 'waxsc_create_reservation_page' ) ); ?>"><?php esc_html_e( 'Create the reservation page', 'wax-store-catalog' ); ?></a></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Buying records', 'wax-store-catalog' ); ?></th>
					<td>
						<?php $waxsc_sell = (int) get_option( 'waxsc_sell_page', 0 ); ?>
						<?php if ( $waxsc_sell && 'publish' === get_post_status( $waxsc_sell ) ) : ?>
							<p><a href="<?php echo esc_url( (string) get_permalink( $waxsc_sell ) ); ?>"><?php echo esc_html( get_the_title( $waxsc_sell ) ); ?></a></p>
						<?php else : ?>
							<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=waxsc_create_sell_page' ), 'waxsc_create_sell_page' ) ); ?>"><?php esc_html_e( 'Create the "Sell us your records" page', 'wax-store-catalog' ); ?></a></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Offers arrive by e-mail and in Record shop > Customer requests, with their photos and lists.', 'wax-store-catalog' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="waxsc-reservation-email"><?php esc_html_e( 'Requests sent to', 'wax-store-catalog' ); ?></label></th>
					<td>
						<input type="email" id="waxsc-reservation-email" name="waxsc_reservation_email" value="<?php echo esc_attr( (string) get_option( 'waxsc_reservation_email', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( \WaxStore\Catalog\Reservation\Form::shop_email() ); ?>" aria-describedby="waxsc-reservation-email-desc">
						<p class="description" id="waxsc-reservation-email-desc"><?php esc_html_e( 'Leave empty to use the shop e-mail from the Contact tab. Every request is also kept in Record shop > Customer requests.', 'wax-store-catalog' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic reservation', 'wax-store-catalog' ); ?></th>
					<td>
						<label><input type="checkbox" name="waxsc_auto_reserve" value="1" <?php checked( (bool) get_option( 'waxsc_auto_reserve' ) ); ?>> <?php esc_html_e( 'Mark the copy as reserved as soon as a request arrives', 'wax-store-catalog' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off by default, so that an unwanted request never blocks your stock: you reserve from the request screen.', 'wax-store-catalog' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}
