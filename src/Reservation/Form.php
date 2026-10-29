<?php
/**
 * Reservation form block and its submission handler.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Reservation;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Public form, no account needed. Spam is kept out without a captcha
 * (honeypot, minimum filling time, hourly limit), so the form stays accessible.
 */
final class Form {

	const ACTION    = 'waxsc_reserve';
	const MIN_DELAY = 3;
	const MAX_AGE   = WEEK_IN_SECONDS;
	const PER_HOUR  = 5;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
	}

	/**
	 * Public query vars read by the form.
	 *
	 * @param array $vars Query vars.
	 */
	public static function query_vars( array $vars ): array {
		return array_merge( $vars, array( 'waxsc_ref', 'waxsc_copy', 'waxsc_sent', 'waxsc_error', 'waxsc_form' ) );
	}

	/**
	 * Block rendering.
	 */
	public static function render(): string {
		$release = absint( get_query_var( 'waxsc_ref' ) );
		$copy_id = sanitize_key( (string) get_query_var( 'waxsc_copy' ) );

		if ( $release && ( waxsc()->commerce()->get_catalog_post_type() !== get_post_type( $release ) || 'publish' !== get_post_status( $release ) ) ) {
			$release = 0;
		}

		if ( get_query_var( 'waxsc_sent' ) ) {
			$catalog = get_post_type_archive_link( waxsc()->commerce()->get_catalog_post_type() );

			return '<div class="waxsc-reservation__sent" role="status"><p><strong>' . esc_html__( 'Thank you, your request has been sent.', 'wax-store-catalog' ) . '</strong></p><p>' . esc_html__( 'We will get back to you quickly. A copy of your request has been sent to your e-mail address.', 'wax-store-catalog' ) . '</p>'
				. ( $catalog ? '<p><a href="' . esc_url( $catalog ) . '">' . esc_html__( 'Back to the catalog', 'wax-store-catalog' ) . '</a></p>' : '' ) . '</div>';
		}

		$old   = self::old_values();
		$error = sanitize_key( (string) get_query_var( 'waxsc_error' ) );
		$html  = self::error_notice( $error );

		if ( $release ) {
			$html .= self::recap( $release, $copy_id );
		}

		$time = time();
		$html .= '<form class="waxsc-reservation__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		$html .= '<input type="hidden" name="waxsc_ref" value="' . esc_attr( (string) $release ) . '">';
		$html .= '<input type="hidden" name="waxsc_copy" value="' . esc_attr( $copy_id ) . '">';
		$html .= '<input type="hidden" name="waxsc_ts" value="' . esc_attr( (string) $time ) . '">';
		$html .= '<input type="hidden" name="waxsc_tsh" value="' . esc_attr( wp_hash( 'waxsc_ts_' . $time ) ) . '">';
		$html .= \WaxStore\Catalog\Front\FormNonce::field( 'waxsc_reserve' );
		$html .= '<input type="hidden" name="waxsc_return" value="' . esc_url( get_permalink() ) . '">';

		$html .= self::field( 'name', __( 'Your name', 'wax-store-catalog' ), 'text', $old['name'], true, 'name', 'name' === $error );
		$html .= self::field( 'email', __( 'Your e-mail address', 'wax-store-catalog' ), 'email', $old['email'], true, 'email', 'email' === $error );
		$html .= self::field( 'phone', __( 'Phone (optional)', 'wax-store-catalog' ), 'tel', $old['phone'], false, 'tel', false );

		$placeholder = $release ? __( 'When will you come by the shop? Any question about the record?', 'wax-store-catalog' ) : __( 'Which record are you looking for? Artist, title, format…', 'wax-store-catalog' );
		$html       .= '<p class="waxsc-reservation__field"><label for="waxsc-message">' . esc_html( $release ? __( 'Message (optional)', 'wax-store-catalog' ) : __( 'Your request', 'wax-store-catalog' ) ) . '</label>'
			. '<textarea id="waxsc-message" name="waxsc_message" rows="5" placeholder="' . esc_attr( $placeholder ) . '"' . ( $release ? '' : ' required' ) . '>' . esc_textarea( $old['message'] ) . '</textarea></p>';

		// Honeypot: invisible to people, filled by robots.
		$html .= '<p class="waxsc-reservation__hp" aria-hidden="true"><label for="waxsc-website">' . esc_html__( 'Leave this field empty', 'wax-store-catalog' ) . '</label><input type="text" id="waxsc-website" name="waxsc_website" value="" tabindex="-1" autocomplete="off"></p>';

		$privacy = get_privacy_policy_url();
		$consent = $privacy
			/* translators: %s: privacy policy link. */
			? sprintf( esc_html__( 'I agree that my details are used to answer this request (%s).', 'wax-store-catalog' ), '<a href="' . esc_url( $privacy ) . '">' . esc_html__( 'privacy policy', 'wax-store-catalog' ) . '</a>' )
			: esc_html__( 'I agree that my details are used to answer this request.', 'wax-store-catalog' );

		$html .= '<p class="waxsc-reservation__consent"><label><input type="checkbox" name="waxsc_consent" value="1" required' . ( 'consent' === $error ? ' aria-invalid="true"' : '' ) . '> ' . $consent . '</label></p>';
		$html .= '<p><button type="submit" class="wp-element-button">' . esc_html( $release ? __( 'Send my reservation request', 'wax-store-catalog' ) : __( 'Send my request', 'wax-store-catalog' ) ) . '</button></p>';
		$html .= '</form>';

		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'waxsc-reservation' ) ) . '>' . $html . '</div>';
	}

	/**
	 * Recap of the record and copy being reserved.
	 *
	 * @param int    $release Release ID.
	 * @param string $copy_id Copy ID.
	 */
	private static function recap( int $release, string $copy_id ): string {
		$copy  = Requests::find_copy( $release, $copy_id );
		$image = get_the_post_thumbnail( $release, 'thumbnail', array( 'class' => 'waxsc-reservation__cover', 'alt' => '' ) );
		$lines = array( '<strong>' . esc_html( Requests::release_label( $release ) ) . '</strong>' );

		if ( $copy ) {
			$grades = trim( implode( ' / ', array_filter( array( $copy['media'], $copy['sleeve'] ) ) ) );

			if ( '' !== $grades ) {
				/* translators: %s: grades, e.g. "VG+ / VG". */
				$lines[] = esc_html( sprintf( __( 'Copy graded %s', 'wax-store-catalog' ), $grades ) );
			}

			if ( '' !== $copy['price'] ) {
				$lines[] = esc_html( Copies::format_price( $copy['price'] ) );
			}

			if ( ProviderInterface::IN_STOCK !== $copy['status'] ) {
				$lines[] = '<em>' . esc_html__( 'This copy is already reserved or sold, but you can still send a request: we will tell you if another one comes in.', 'wax-store-catalog' ) . '</em>';
			}
		}

		return '<div class="waxsc-reservation__item">' . $image . '<p>' . implode( '<br>', $lines ) . '</p></div>';
	}

	/**
	 * One text field.
	 *
	 * @param string $name     Field key.
	 * @param string $label    Label.
	 * @param string $type     Input type.
	 * @param string $value    Value.
	 * @param bool   $required Required.
	 * @param string $auto     Autocomplete token.
	 * @param bool   $invalid  Field in error.
	 */
	private static function field( string $name, string $label, string $type, string $value, bool $required, string $auto, bool $invalid ): string {
		return sprintf(
			'<p class="waxsc-reservation__field"><label for="waxsc-%1$s">%2$s</label><input type="%3$s" id="waxsc-%1$s" name="waxsc_%1$s" value="%4$s" autocomplete="%5$s"%6$s%7$s></p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			esc_attr( $auto ),
			$required ? ' required' : '',
			$invalid ? ' aria-invalid="true" aria-describedby="waxsc-reservation-error"' : ''
		);
	}

	/**
	 * Error message after a failed submission.
	 *
	 * @param string $error Error code.
	 */
	private static function error_notice( string $error ): string {
		$messages = array(
			'name'    => __( 'Please enter your name.', 'wax-store-catalog' ),
			'email'   => __( 'Please enter a valid e-mail address, so we can answer you.', 'wax-store-catalog' ),
			'consent' => __( 'Please tick the box to agree that your details are used to answer you.', 'wax-store-catalog' ),
			'message' => __( 'Please tell us which record you are looking for.', 'wax-store-catalog' ),
			'expired' => __( 'This form was open for too long. Please send it again.', 'wax-store-catalog' ),
			'limit'   => __( 'Too many requests were sent from your connection. Please try again in an hour, or call us.', 'wax-store-catalog' ),
			'failed'  => __( 'Your request could not be saved. Please try again, or call us.', 'wax-store-catalog' ),
		);

		return isset( $messages[ $error ] ) ? '<div class="waxsc-reservation__error" id="waxsc-reservation-error" role="alert"><p>' . esc_html( $messages[ $error ] ) . '</p></div>' : '';
	}

	/**
	 * Values typed before an error, kept for 10 minutes.
	 */
	private static function old_values(): array {
		$values = array(
			'name'    => '',
			'email'   => '',
			'phone'   => '',
			'message' => '',
		);
		$token  = sanitize_key( (string) get_query_var( 'waxsc_form' ) );

		if ( '' !== $token ) {
			$stored = get_transient( 'waxsc_form_' . $token );

			if ( is_array( $stored ) ) {
				$values = array_merge( $values, array_intersect_key( $stored, $values ) );
			}
		}

		return $values;
	}

	/**
	 * Submission handler. No nonce: the form is public and often served from a
	 * page cache, where nonces expire; spam checks replace it.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked below with FormNonce::verify(), after the anti-spam checks.
		$return = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['waxsc_return'] ?? '' ) ), home_url( '/' ) );
		$data   = array(
			'name'    => mb_substr( sanitize_text_field( wp_unslash( $_POST['waxsc_name'] ?? '' ) ), 0, 120 ),
			'email'   => sanitize_email( wp_unslash( $_POST['waxsc_email'] ?? '' ) ),
			'phone'   => mb_substr( (string) preg_replace( '/[^0-9+().\s-]/', '', sanitize_text_field( wp_unslash( $_POST['waxsc_phone'] ?? '' ) ) ), 0, 30 ),
			'message' => mb_substr( sanitize_textarea_field( wp_unslash( $_POST['waxsc_message'] ?? '' ) ), 0, 3000 ),
			'release' => absint( $_POST['waxsc_ref'] ?? 0 ),
			'copy'    => sanitize_key( wp_unslash( $_POST['waxsc_copy'] ?? '' ) ),
		);
		$time   = absint( $_POST['waxsc_ts'] ?? 0 );
		$hash   = sanitize_text_field( wp_unslash( $_POST['waxsc_tsh'] ?? '' ) );
		$bot    = '' !== trim( sanitize_text_field( wp_unslash( $_POST['waxsc_website'] ?? '' ) ) );
		$agreed = ! empty( $_POST['waxsc_consent'] );
		// phpcs:enable

		$base = remove_query_arg( array( 'waxsc_error', 'waxsc_form', 'waxsc_sent' ), $return );

		if ( $data['release'] && waxsc()->commerce()->get_catalog_post_type() !== get_post_type( $data['release'] ) ) {
			$data['release'] = 0;
			$data['copy']    = '';
		}

		$args = array_filter(
			array(
				'waxsc_ref'     => $data['release'] ? $data['release'] : null,
				'waxsc_copy'    => '' !== $data['copy'] ? $data['copy'] : null,
			)
		);

		// Robots get the success page and nothing is stored.
		if ( $bot || ( $time && time() - $time < self::MIN_DELAY ) ) {
			self::redirect( add_query_arg( array_merge( $args, array( 'waxsc_sent' => 1 ) ), $base ) );
		}

		$error = '';

		if ( ! \WaxStore\Catalog\Front\FormNonce::verify( 'waxsc_reserve' ) || ! $time || ! hash_equals( wp_hash( 'waxsc_ts_' . $time ), $hash ) || time() - $time > self::MAX_AGE ) {
			$error = 'expired';
		} elseif ( '' === $data['name'] ) {
			$error = 'name';
		} elseif ( ! is_email( $data['email'] ) ) {
			$error = 'email';
		} elseif ( ! $data['release'] && '' === trim( $data['message'] ) ) {
			$error = 'message';
		} elseif ( ! $agreed ) {
			$error = 'consent';
		} elseif ( ! self::within_limit() ) {
			$error = 'limit';
		}

		if ( '' === $error ) {
			$request = Requests::create( $data );

			if ( ! $request ) {
				$error = 'failed';
			}
		}

		if ( '' !== $error ) {
			$token = strtolower( wp_generate_password( 12, false, false ) );
			set_transient( 'waxsc_form_' . $token, $data, 10 * MINUTE_IN_SECONDS );
			self::redirect(
				add_query_arg(
					array_merge(
						$args,
						array(
							'waxsc_error' => $error,
							'waxsc_form'  => $token,
						)
					),
					$base
				)
			);
		}

		if ( $data['release'] && '' !== $data['copy'] && get_option( 'waxsc_auto_reserve' ) ) {
			Requests::reserve_copy( $data['release'], $data['copy'] );
		}

		Mailer::send( $data, $request );

		self::redirect( add_query_arg( array_merge( $args, array( 'waxsc_sent' => 1 ) ), $base ) );
	}

	/**
	 * Hourly limit per connection. The address is only hashed, never stored as is.
	 */
	private static function within_limit(): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'waxsc_rate_' . md5( wp_hash( $ip ) );
		$count = (int) get_transient( $key );

		if ( $count >= self::PER_HOUR ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Redirects and stops.
	 *
	 * @param string $url URL.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Address receiving the requests.
	 */
	public static function shop_email(): string {
		foreach ( array( (string) get_option( 'waxsc_reservation_email', '' ), (string) Settings::get( 'contact', 'email' ), (string) get_option( 'admin_email' ) ) as $email ) {
			if ( is_email( $email ) ) {
				return $email;
			}
		}

		return '';
	}
}
