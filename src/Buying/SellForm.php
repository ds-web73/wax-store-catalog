<?php
/**
 * "Sell us your records" form block and its handler.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Buying;

use WaxStore\Catalog\Reservation\Form as ReservationForm;
use WaxStore\Catalog\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Public form with optional photos and list file. Spam protection without
 * captcha (honeypot, minimum filling time, hourly limit), as for reservations.
 */
final class SellForm {

	const ACTION     = 'waxsc_sell';
	const MAX_PHOTOS = 5;
	const MAX_SIZE   = 8 * MB_IN_BYTES;
	const PER_HOUR   = 3;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_filter(
			'query_vars',
			static function ( $vars ) {
				$vars[] = 'waxsc_sell';
				return $vars;
			}
		);
	}

	/**
	 * Accepted files: photos, and lists as spreadsheet, PDF or text.
	 *
	 * @param bool $photos Photos only.
	 */
	private static function mimes( bool $photos ): array {
		$images = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		);

		return $photos ? $images : array(
			'csv'  => 'text/csv',
			'txt'  => 'text/plain',
			'pdf'  => 'application/pdf',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'xls'  => 'application/vnd.ms-excel',
			'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		);
	}

	/**
	 * Block rendering.
	 */
	public static function render(): string {
		$status = sanitize_key( (string) get_query_var( 'waxsc_sell' ) );

		if ( 'sent' === $status ) {
			return '<div ' . get_block_wrapper_attributes( array( 'class' => 'waxsc-sell' ) ) . '><div class="waxsc-reservation__sent" role="status"><p><strong>' . esc_html__( 'Thank you, your offer has been sent.', 'wax-store-catalog' ) . '</strong></p><p>' . esc_html__( 'We will look at it and get back to you. A copy has been sent to your e-mail address.', 'wax-store-catalog' ) . '</p></div></div>';
		}

		$errors = array(
			'name'    => __( 'Please enter your name.', 'wax-store-catalog' ),
			'email'   => __( 'Please enter a valid e-mail address, so we can answer you.', 'wax-store-catalog' ),
			'message' => __( 'Please describe your records in a few words.', 'wax-store-catalog' ),
			'consent' => __( 'Please tick the box to agree that your details are used to answer you.', 'wax-store-catalog' ),
			'file'    => __( 'A file could not be accepted: photos in JPEG, PNG or WebP, list in spreadsheet, PDF or text, 8 MB at most each, 5 photos at most.', 'wax-store-catalog' ),
			'expired' => __( 'This form was open for too long. Please send it again.', 'wax-store-catalog' ),
			'limit'   => __( 'Too many requests were sent from your connection. Please try again in an hour, or call us.', 'wax-store-catalog' ),
			'failed'  => __( 'Your offer could not be saved. Please try again, or call us.', 'wax-store-catalog' ),
		);

		$time = time();
		$html = isset( $errors[ $status ] ) ? '<div class="waxsc-reservation__error" role="alert"><p>' . esc_html( $errors[ $status ] ) . '</p></div>' : '';

		$html .= '<form class="waxsc-sell__form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		$html .= '<input type="hidden" name="waxsc_ts" value="' . esc_attr( (string) $time ) . '">';
		$html .= '<input type="hidden" name="waxsc_tsh" value="' . esc_attr( wp_hash( 'waxsc_sell_' . $time ) ) . '">';
		$html .= '<input type="hidden" name="waxsc_return" value="' . esc_url( (string) get_permalink() ) . '">';

		$html .= self::input( 'name', __( 'Your name', 'wax-store-catalog' ), 'text', true, 'name' );
		$html .= self::input( 'email', __( 'Your e-mail address', 'wax-store-catalog' ), 'email', true, 'email' );
		$html .= self::input( 'phone', __( 'Phone (optional)', 'wax-store-catalog' ), 'tel', false, 'tel' );
		$html .= self::input( 'city', __( 'City where the records are', 'wax-store-catalog' ), 'text', false, 'address-level2' );

		$html .= '<p class="waxsc-reservation__field"><label for="waxsc-sell-size">' . esc_html__( 'Number of records', 'wax-store-catalog' ) . '</label><select id="waxsc-sell-size" name="waxsc_size">';

		foreach ( Offers::sizes() as $key => $label ) {
			$html .= '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
		}

		$html .= '</select></p>';
		$html .= '<fieldset class="waxsc-sell__formats"><legend>' . esc_html__( 'Formats', 'wax-store-catalog' ) . '</legend>';

		foreach ( Offers::formats() as $key => $label ) {
			$html .= '<label><input type="checkbox" name="waxsc_formats[]" value="' . esc_attr( $key ) . '"' . checked( 'vinyl', $key, false ) . '> ' . esc_html( $label ) . '</label>';
		}

		$html .= '</fieldset>';
		$html .= self::input( 'genres', __( 'Main genres', 'wax-store-catalog' ), 'text', false, 'off', __( 'Rock, jazz, French pop…', 'wax-store-catalog' ) );
		$html .= '<p class="waxsc-reservation__field"><label for="waxsc-sell-message">' . esc_html__( 'Your records', 'wax-store-catalog' ) . '</label><textarea id="waxsc-sell-message" name="waxsc_message" rows="6" required placeholder="' . esc_attr__( 'A few artists or titles, the general condition, original pressings or reissues…', 'wax-store-catalog' ) . '"></textarea></p>';
		$html .= '<p class="waxsc-reservation__field"><label for="waxsc-sell-photos">' . esc_html__( 'Photos (optional, 5 at most)', 'wax-store-catalog' ) . '</label><input type="file" id="waxsc-sell-photos" name="waxsc_photos[]" accept="image/jpeg,image/png,image/webp" multiple></p>';
		$html .= '<p class="waxsc-reservation__field"><label for="waxsc-sell-list">' . esc_html__( 'Your list (optional: spreadsheet, PDF or text)', 'wax-store-catalog' ) . '</label><input type="file" id="waxsc-sell-list" name="waxsc_list" accept=".csv,.txt,.pdf,.xlsx,.xls,.ods"></p>';
		$html .= '<p class="waxsc-reservation__hp" aria-hidden="true"><label for="waxsc-sell-website">' . esc_html__( 'Leave this field empty', 'wax-store-catalog' ) . '</label><input type="text" id="waxsc-sell-website" name="waxsc_website" tabindex="-1" autocomplete="off"></p>';

		$privacy = get_privacy_policy_url();
		$consent = $privacy
			/* translators: %s: privacy policy link. */
			? sprintf( esc_html__( 'I agree that my details are used to answer this request (%s).', 'wax-store-catalog' ), '<a href="' . esc_url( $privacy ) . '">' . esc_html__( 'privacy policy', 'wax-store-catalog' ) . '</a>' )
			: esc_html__( 'I agree that my details are used to answer this request.', 'wax-store-catalog' );

		$html .= '<p class="waxsc-reservation__consent"><label><input type="checkbox" name="waxsc_consent" value="1" required> ' . $consent . '</label></p>';
		$html .= '<p><button type="submit" class="wp-element-button">' . esc_html__( 'Send my offer', 'wax-store-catalog' ) . '</button></p></form>';

		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'waxsc-sell waxsc-reservation' ) ) . '>' . $html . '</div>';
	}

	/**
	 * One text field.
	 *
	 * @param string $name        Key.
	 * @param string $label       Label.
	 * @param string $type        Type.
	 * @param bool   $required    Required.
	 * @param string $auto        Autocomplete.
	 * @param string $placeholder Placeholder.
	 */
	private static function input( string $name, string $label, string $type, bool $required, string $auto, string $placeholder = '' ): string {
		return sprintf(
			'<p class="waxsc-reservation__field"><label for="waxsc-sell-%1$s">%2$s</label><input type="%3$s" id="waxsc-sell-%1$s" name="waxsc_%1$s" autocomplete="%4$s"%5$s%6$s></p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $auto ),
			$required ? ' required' : '',
			'' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : ''
		);
	}

	/**
	 * Submission handler.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form, often cached: spam checks instead of a nonce.
		$return = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['waxsc_return'] ?? '' ) ), home_url( '/' ) );
		$data   = array(
			'name'    => mb_substr( sanitize_text_field( wp_unslash( $_POST['waxsc_name'] ?? '' ) ), 0, 120 ),
			'email'   => sanitize_email( wp_unslash( $_POST['waxsc_email'] ?? '' ) ),
			'phone'   => mb_substr( (string) preg_replace( '/[^0-9+().\s-]/', '', sanitize_text_field( wp_unslash( $_POST['waxsc_phone'] ?? '' ) ) ), 0, 30 ),
			'city'    => mb_substr( sanitize_text_field( wp_unslash( $_POST['waxsc_city'] ?? '' ) ), 0, 100 ),
			'size'    => sanitize_key( wp_unslash( $_POST['waxsc_size'] ?? '' ) ),
			'formats' => array_values( array_intersect( array_keys( Offers::formats() ), array_map( 'sanitize_key', (array) wp_unslash( $_POST['waxsc_formats'] ?? array() ) ) ) ),
			'genres'  => mb_substr( sanitize_text_field( wp_unslash( $_POST['waxsc_genres'] ?? '' ) ), 0, 200 ),
			'message' => mb_substr( sanitize_textarea_field( wp_unslash( $_POST['waxsc_message'] ?? '' ) ), 0, 5000 ),
		);
		$time   = absint( $_POST['waxsc_ts'] ?? 0 );
		$hash   = sanitize_text_field( wp_unslash( $_POST['waxsc_tsh'] ?? '' ) );
		$bot    = '' !== trim( sanitize_text_field( wp_unslash( $_POST['waxsc_website'] ?? '' ) ) );
		$agreed = ! empty( $_POST['waxsc_consent'] );
		// phpcs:enable

		$back = static function ( string $status ) use ( $return ) {
			wp_safe_redirect( add_query_arg( 'waxsc_sell', $status, $return ) );
			exit;
		};

		if ( ! isset( Offers::sizes()[ $data['size'] ] ) ) {
			$data['size'] = 'under-50';
		}

		if ( $bot || ( $time && time() - $time < 5 ) ) {
			$back( 'sent' );
		}

		if ( ! $time || ! hash_equals( wp_hash( 'waxsc_sell_' . $time ), $hash ) || time() - $time > WEEK_IN_SECONDS ) {
			$back( 'expired' );
		}

		if ( '' === $data['name'] ) {
			$back( 'name' );
		}

		if ( ! is_email( $data['email'] ) ) {
			$back( 'email' );
		}

		if ( '' === trim( $data['message'] ) ) {
			$back( 'message' );
		}

		if ( ! $agreed ) {
			$back( 'consent' );
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'waxsc_sell_rate_' . md5( wp_hash( $ip ) );

		if ( (int) get_transient( $key ) >= self::PER_HOUR ) {
			$back( 'limit' );
		}

		$files = self::store_files();

		if ( null === $files ) {
			$back( 'file' );
		}

		$offer = Offers::create( $data, $files );

		if ( ! $offer ) {
			$back( 'failed' );
		}

		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
		self::mail( $data, $offer, count( $files ) );

		$back( 'sent' );
	}

	/**
	 * Validates and stores the uploaded files. Null when one is refused.
	 *
	 * @return array|null [ name, path, type ] per file.
	 */
	private static function store_files(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- validated below and by wp_handle_upload().
		$uploads = array();

		if ( ! empty( $_FILES['waxsc_photos']['name'] ) && is_array( $_FILES['waxsc_photos']['name'] ) ) {
			foreach ( $_FILES['waxsc_photos']['name'] as $index => $name ) {
				if ( '' === (string) $name || UPLOAD_ERR_NO_FILE === (int) $_FILES['waxsc_photos']['error'][ $index ] ) {
					continue;
				}

				$uploads[] = array(
					array(
						'name'     => $name,
						'type'     => $_FILES['waxsc_photos']['type'][ $index ],
						'tmp_name' => $_FILES['waxsc_photos']['tmp_name'][ $index ],
						'error'    => $_FILES['waxsc_photos']['error'][ $index ],
						'size'     => $_FILES['waxsc_photos']['size'][ $index ],
					),
					true,
				);
			}
		}

		if ( count( $uploads ) > self::MAX_PHOTOS ) {
			return null;
		}

		if ( ! empty( $_FILES['waxsc_list']['name'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['waxsc_list']['error'] ) {
			$uploads[] = array( $_FILES['waxsc_list'], false );
		}
		// phpcs:enable

		if ( ! $uploads ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$dir    = Offers::dir();
		$filter = static function ( array $dirs ) use ( $dir ): array {
			$dirs['path']   = $dir;
			$dirs['subdir'] = '/' . Offers::DIR;
			$dirs['url']    = $dirs['baseurl'] . '/' . Offers::DIR;
			return $dirs;
		};

		$stored = array();
		add_filter( 'upload_dir', $filter );

		foreach ( $uploads as $upload ) {
			list( $file, $photo ) = $upload;

			if ( (int) $file['size'] > self::MAX_SIZE ) {
				$result = array( 'error' => 'size' );
			} else {
				$result = wp_handle_upload(
					$file,
					array(
						'test_form'                => false,
						'mimes'                    => self::mimes( $photo ),
						'unique_filename_callback' => static function ( $directory, $name, $ext ) {
							return 'offer-' . strtolower( wp_generate_password( 20, false, false ) ) . $ext;
						},
					)
				);
			}

			if ( isset( $result['error'] ) || ( $photo && ! wp_get_image_mime( $result['file'] ) ) ) {
				if ( ! empty( $result['file'] ) ) {
					wp_delete_file( $result['file'] );
				}

				foreach ( $stored as $done ) {
					wp_delete_file( $done['path'] );
				}

				remove_filter( 'upload_dir', $filter );
				return null;
			}

			$stored[] = array(
				'name' => sanitize_file_name( (string) $file['name'] ),
				'path' => $result['file'],
				'type' => $result['type'],
			);
		}

		remove_filter( 'upload_dir', $filter );

		return $stored;
	}

	/**
	 * E-mails to the shop and to the seller.
	 *
	 * @param array $data  Offer.
	 * @param int   $offer Offer ID.
	 * @param int   $files Number of files.
	 */
	private static function mail( array $data, int $offer, int $files ): void {
		$shop_email = ReservationForm::shop_email();
		$shop       = (string) Settings::get( 'identity', 'shop_name' );
		$shop       = '' !== $shop ? $shop : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$formats    = implode( ', ', array_intersect_key( Offers::formats(), array_flip( $data['formats'] ) ) );
		$summary    = array_filter(
			array(
				/* translators: %s: name. */
				sprintf( __( 'Name: %s', 'wax-store-catalog' ), $data['name'] ),
				/* translators: %s: e-mail. */
				sprintf( __( 'E-mail: %s', 'wax-store-catalog' ), $data['email'] ),
				/* translators: %s: phone. */
				'' !== $data['phone'] ? sprintf( __( 'Phone: %s', 'wax-store-catalog' ), $data['phone'] ) : '',
				/* translators: %s: city. */
				'' !== $data['city'] ? sprintf( __( 'City: %s', 'wax-store-catalog' ), $data['city'] ) : '',
				Offers::size_label( $data['size'] ) . ( '' !== $formats ? ' (' . $formats . ')' : '' ),
				/* translators: %s: genres. */
				'' !== $data['genres'] ? sprintf( __( 'Genres: %s', 'wax-store-catalog' ), $data['genres'] ) : '',
			)
		);

		if ( '' !== $shop_email ) {
			$body = implode( "\n", $summary ) . "\n\n" . $data['message'] . "\n\n";

			if ( $files ) {
				/* translators: %d: number of files. */
				$body .= sprintf( _n( '%d file attached to the offer, visible in the admin.', '%d files attached to the offer, visible in the admin.', $files, 'wax-store-catalog' ), $files ) . "\n";
			}

			/* translators: %s: admin URL. */
			$body .= sprintf( __( 'See the offer: %s', 'wax-store-catalog' ), admin_url( 'post.php?post=' . $offer . '&action=edit' ) );

			wp_mail(
				$shop_email,
				/* translators: 1: name, 2: collection size. */
				sprintf( __( 'Records to sell: %1$s, %2$s', 'wax-store-catalog' ), $data['name'], Offers::size_label( $data['size'] ) ),
				$body,
				array( 'Reply-To: ' . $data['name'] . ' <' . $data['email'] . '>' )
			);
		}

		wp_mail(
			$data['email'],
			/* translators: %s: shop name. */
			sprintf( __( 'Your offer to %s', 'wax-store-catalog' ), $shop ),
			/* translators: %s: name. */
			sprintf( __( 'Hello %s,', 'wax-store-catalog' ), $data['name'] ) . "\n\n" . __( 'We have received your offer and will get back to you after looking at it.', 'wax-store-catalog' ) . "\n\n" . implode( "\n", $summary ) . "\n\n" . $data['message'] . "\n\n" . $shop . "\n" . home_url( '/' ),
			'' !== $shop_email ? array( 'Reply-To: ' . $shop . ' <' . $shop_email . '>' ) : array()
		);
	}
}
