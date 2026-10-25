<?php
/**
 * Reservation e-mails: to the shop, and an acknowledgment to the customer.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Reservation;

use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plain text e-mails, readable everywhere. Failures are not blocking:
 * the request is already stored in the admin.
 */
final class Mailer {

	/**
	 * Sends both e-mails.
	 *
	 * @param array $data    Request data.
	 * @param int   $request Stored request ID.
	 */
	public static function send( array $data, int $request ): void {
		$shop_email = Form::shop_email();
		$shop_name  = (string) Settings::get( 'identity', 'shop_name' );
		$shop_name  = '' !== $shop_name ? $shop_name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$record     = self::record_lines( $data );
		$copy       = $data['release'] ? Requests::find_copy( (int) $data['release'], (string) $data['copy'] ) : array();
		/* translators: %s: location in the shop, e.g. "Jazz bin, row 3". */
		$location   = ! empty( $copy['location'] ) ? sprintf( __( 'Location in the shop: %s', 'wax-store-catalog' ), $copy['location'] ) : '';

		if ( '' !== $shop_email ) {
			$subject = $data['release']
				/* translators: %s: "Artist – Title". */
				? sprintf( __( 'Reservation request: %s', 'wax-store-catalog' ), Requests::release_label( (int) $data['release'] ) )
				/* translators: %s: customer name. */
				: sprintf( __( 'Record request from %s', 'wax-store-catalog' ), $data['name'] );

			$body = implode(
				"\n",
				array_filter(
					array_merge(
						array(
							/* translators: %s: customer name. */
							sprintf( __( 'Name: %s', 'wax-store-catalog' ), $data['name'] ),
							/* translators: %s: e-mail address. */
							sprintf( __( 'E-mail: %s', 'wax-store-catalog' ), $data['email'] ),
							/* translators: %s: phone number. */
							'' !== $data['phone'] ? sprintf( __( 'Phone: %s', 'wax-store-catalog' ), $data['phone'] ) : '',
							'',
						),
						$record,
						array( $location ),
						array(
							'',
							$data['message'],
							'',
							/* translators: %s: admin URL of the request. */
							$request ? sprintf( __( 'See the request: %s', 'wax-store-catalog' ), admin_url( 'post.php?post=' . $request . '&action=edit' ) ) : '',
						)
					),
					static function ( $line ) {
						return null !== $line;
					}
				)
			);

			wp_mail( $shop_email, $subject, $body, array( 'Reply-To: ' . $data['name'] . ' <' . $data['email'] . '>' ) );
		}

		/* translators: %s: shop name. */
		$subject  = sprintf( __( 'Your request to %s', 'wax-store-catalog' ), $shop_name );
		$customer = implode(
			"\n",
			array_merge(
				/* translators: %s: customer name. */
				array( sprintf( __( 'Hello %s,', 'wax-store-catalog' ), $data['name'] ), '', __( 'We have received your request and will get back to you quickly.', 'wax-store-catalog' ), '' ),
				$record,
				'' !== $data['message'] ? array( '', $data['message'] ) : array(),
				array( '', $shop_name, home_url( '/' ) )
			)
		);

		$headers = '' !== $shop_email ? array( 'Reply-To: ' . $shop_name . ' <' . $shop_email . '>' ) : array();

		wp_mail( $data['email'], $subject, $customer, $headers );
	}

	/**
	 * Lines describing the record and copy.
	 *
	 * @param array $data Request data.
	 * @return string[]
	 */
	private static function record_lines( array $data ): array {
		if ( ! $data['release'] ) {
			return array();
		}

		$lines = array( Requests::release_label( (int) $data['release'] ) );
		$copy  = Requests::find_copy( (int) $data['release'], (string) $data['copy'] );

		if ( $copy ) {
			$grades = trim( implode( ' / ', array_filter( array( $copy['media'], $copy['sleeve'] ) ) ) );

			if ( '' !== $grades ) {
				/* translators: %s: grades, e.g. "VG+ / VG". */
				$lines[] = sprintf( __( 'Copy graded %s', 'wax-store-catalog' ), $grades );
			}

			if ( '' !== $copy['price'] ) {
				$lines[] = Copies::format_price( $copy['price'] );
			}
		}

		$lines[] = (string) get_permalink( (int) $data['release'] );

		return $lines;
	}
}
