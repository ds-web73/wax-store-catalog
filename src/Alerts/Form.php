<?php
/**
 * "Notify me" form, its handler and the confirm / stop links.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Alerts;

use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Same spam protection as the reservation form: honeypot, minimum filling
 * time, hourly limit, plus a double opt-in by e-mail.
 */
final class Form {

	const ACTION   = 'waxsc_alert';
	const PER_HOUR = 5;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'template_redirect', array( self::class, 'links' ) );
		add_filter(
			'query_vars',
			static function ( $vars ) {
				$vars[] = 'waxsc_alert';
				return $vars;
			}
		);
	}

	/**
	 * Form for the current page: a release, an artist or a label archive.
	 *
	 * @param int $release Release ID, 0 to use the queried object.
	 */
	public static function render( int $release = 0 ): string {
		$options = array();

		if ( ! $release && is_singular( waxsc()->commerce()->get_catalog_post_type() ) ) {
			$release = (int) get_queried_object_id();
		}

		if ( $release ) {
			if ( 'in_stock' !== Copies::availability( $release ) ) {
				/* translators: %s: "Artist – Title". */
				$options[] = array( 'release', $release, sprintf( __( 'when %s is back in stock', 'wax-store-catalog' ), get_the_title( $release ) ) );
			}

			foreach ( array( Taxonomies::ARTIST, Taxonomies::LABEL ) as $taxonomy ) {
				$terms = get_the_terms( $release, $taxonomy );

				if ( is_array( $terms ) && $terms ) {
					/* translators: %s: artist or label name. */
					$options[] = array( $taxonomy, (int) $terms[0]->term_id, sprintf( __( 'of the new arrivals of %s', 'wax-store-catalog' ), $terms[0]->name ) );
				}
			}
		} elseif ( is_tax( array( Taxonomies::ARTIST, Taxonomies::LABEL ) ) ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				/* translators: %s: artist or label name. */
				$options[] = array( $term->taxonomy, (int) $term->term_id, sprintf( __( 'of the new arrivals of %s', 'wax-store-catalog' ), $term->name ) );
			}
		}

		if ( ! $options ) {
			return '';
		}

		$status  = sanitize_key( (string) get_query_var( 'waxsc_alert' ) );
		$notices = array(
			'sent'  => __( 'Almost done: check your e-mails and click the confirmation link.', 'wax-store-catalog' ),
			'email' => __( 'Please enter a valid e-mail address.', 'wax-store-catalog' ),
			'none'  => __( 'Please choose at least one alert.', 'wax-store-catalog' ),
			'limit' => __( 'Too many requests were sent from your connection. Please try again later.', 'wax-store-catalog' ),
		);
		$time    = time();
		$open    = $release && 'in_stock' !== Copies::availability( $release );
		$html    = '';

		if ( isset( $notices[ $status ] ) ) {
			$html .= '<p class="waxsc-alert__notice" role="' . ( 'sent' === $status ? 'status' : 'alert' ) . '">' . esc_html( $notices[ $status ] ) . '</p>';
			$open  = true;
		}

		$html .= '<details id="waxsc-alert" class="waxsc-alert"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Notify me', 'wax-store-catalog' ) . '</summary>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		$html .= '<input type="hidden" name="waxsc_ts" value="' . esc_attr( (string) $time ) . '">';
		$html .= '<input type="hidden" name="waxsc_tsh" value="' . esc_attr( wp_hash( 'waxsc_alert_' . $time ) ) . '">';
		$html .= '<input type="hidden" name="waxsc_return" value="' . esc_url( self::current_url() ) . '">';
		$html .= '<fieldset><legend>' . esc_html__( 'Send me an e-mail', 'wax-store-catalog' ) . '</legend>';

		foreach ( $options as $index => $option ) {
			$html .= '<label class="waxsc-alert__choice"><input type="checkbox" name="waxsc_follow[]" value="' . esc_attr( $option[0] . ':' . $option[1] ) . '"' . checked( 0 === $index, true, false ) . '> ' . esc_html( $option[2] ) . '</label>';
		}

		$html .= '</fieldset>';
		$html .= '<p class="waxsc-alert__field"><label for="waxsc-alert-email">' . esc_html__( 'Your e-mail address', 'wax-store-catalog' ) . '</label><input type="email" id="waxsc-alert-email" name="waxsc_email" autocomplete="email" required></p>';
		$html .= '<p class="waxsc-reservation__hp" aria-hidden="true"><label for="waxsc-alert-website">' . esc_html__( 'Leave this field empty', 'wax-store-catalog' ) . '</label><input type="text" id="waxsc-alert-website" name="waxsc_website" tabindex="-1" autocomplete="off"></p>';
		$html .= '<p class="waxsc-alert__help">' . esc_html__( 'You will receive a confirmation e-mail first. Every alert e-mail has a link to stop it.', 'wax-store-catalog' ) . '</p>';
		$html .= '<p><button type="submit" class="wp-element-button">' . esc_html__( 'Notify me', 'wax-store-catalog' ) . '</button></p>';
		$html .= '</form></details>';

		return $html;
	}

	/**
	 * Current URL without the alert status.
	 */
	private static function current_url(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return remove_query_arg( 'waxsc_alert', home_url( $path ) );
	}

	/**
	 * Form handler.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form, often cached: spam checks instead of a nonce.
		$return = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['waxsc_return'] ?? '' ) ), home_url( '/' ) );
		$email  = sanitize_email( wp_unslash( $_POST['waxsc_email'] ?? '' ) );
		$follow = isset( $_POST['waxsc_follow'] ) && is_array( $_POST['waxsc_follow'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['waxsc_follow'] ) ) : array();
		$time   = absint( $_POST['waxsc_ts'] ?? 0 );
		$hash   = sanitize_text_field( wp_unslash( $_POST['waxsc_tsh'] ?? '' ) );
		$bot    = '' !== trim( sanitize_text_field( wp_unslash( $_POST['waxsc_website'] ?? '' ) ) );
		// phpcs:enable

		$back = static function ( $status ) use ( $return ) {
			wp_safe_redirect( add_query_arg( 'waxsc_alert', $status, $return ) . '#waxsc-alert' );
			exit;
		};

		if ( $bot || ! $time || time() - $time < 3 || ! hash_equals( wp_hash( 'waxsc_alert_' . $time ), $hash ) ) {
			$back( 'sent' );
		}

		if ( ! is_email( $email ) ) {
			$back( 'email' );
		}

		$targets = array();

		foreach ( $follow as $value ) {
			list( $kind, $target ) = array_pad( explode( ':', $value, 2 ), 2, '' );
			$target                = absint( $target );

			if ( ! in_array( $kind, Alerts::KINDS, true ) || ! $target ) {
				continue;
			}

			$valid = 'release' === $kind ? PostType::NAME === get_post_type( $target ) : get_term( $target, $kind ) instanceof \WP_Term;

			if ( $valid ) {
				$targets[] = array( $kind, $target );
			}
		}

		if ( ! $targets ) {
			$back( 'none' );
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'waxsc_alert_rate_' . md5( wp_hash( $ip ) );

		if ( (int) get_transient( $key ) >= self::PER_HOUR ) {
			$back( 'limit' );
		}

		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );

		$pending = array();

		foreach ( $targets as $target ) {
			$alert = Alerts::create( $email, $target[0], $target[1] );

			if ( $alert && 'publish' !== get_post_status( $alert ) ) {
				$pending[] = $alert;
			}
		}

		if ( $pending ) {
			Mailer::confirmation( $email, $pending );
		}

		$back( 'sent' );
	}

	/**
	 * Confirm and stop links from the e-mails.
	 */
	public static function links(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- token links from e-mails.
		$confirm = isset( $_GET['waxsc_alert_confirm'] ) ? sanitize_key( wp_unslash( $_GET['waxsc_alert_confirm'] ) ) : '';
		$stop    = isset( $_GET['waxsc_alert_stop'] ) ? sanitize_key( wp_unslash( $_GET['waxsc_alert_stop'] ) ) : '';
		// phpcs:enable

		if ( '' === $confirm && '' === $stop ) {
			return;
		}

		$alert = Alerts::by_token( '' !== $confirm ? $confirm : $stop );
		$home  = '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( Alerts::shop_name() ) . '</a></p>';

		if ( ! $alert ) {
			wp_die( '<p>' . esc_html__( 'This link is no longer valid: the alert was already stopped or has expired.', 'wax-store-catalog' ) . '</p>' . $home, esc_html__( 'Alert', 'wax-store-catalog' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}

		$label = Mailer::describe( $alert );

		if ( '' !== $confirm ) {
			wp_update_post(
				array(
					'ID'          => $alert,
					'post_status' => 'publish',
				)
			);
			/* translators: %s: alert description. */
			$message = sprintf( __( 'Your alert is active: %s. We will send you an e-mail as soon as it happens.', 'wax-store-catalog' ), $label );
		} else {
			wp_delete_post( $alert, true );
			/* translators: %s: alert description. */
			$message = sprintf( __( 'Alert stopped: %s. You will not receive these e-mails any more.', 'wax-store-catalog' ), $label );
		}

		wp_die( '<p>' . esc_html( $message ) . '</p>' . $home, esc_html__( 'Alert', 'wax-store-catalog' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}
}
