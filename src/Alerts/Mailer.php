<?php
/**
 * Alert e-mails: confirmation, and the digest of matching releases.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Alerts;

use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Reservation\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Plain text e-mails with a one-click unsubscribe link.
 */
final class Mailer {

	/**
	 * Link to confirm or stop an alert.
	 *
	 * @param string $action confirm or stop.
	 * @param int    $alert  Alert ID.
	 */
	public static function link( string $action, int $alert ): string {
		return add_query_arg(
			array( 'waxsc_alert_' . $action => (string) get_post_meta( $alert, Alerts::TOKEN, true ) ),
			home_url( '/' )
		);
	}

	/**
	 * Confirmation request, one e-mail for the alerts just created.
	 *
	 * @param string $email  Address.
	 * @param int[]  $alerts Alert IDs.
	 */
	public static function confirmation( string $email, array $alerts ): bool {
		$shop  = Alerts::shop_name();
		$lines = array(
			__( 'Hello,', 'wax-store-catalog' ),
			'',
			__( 'Please confirm the alerts you asked for, by clicking on each link:', 'wax-store-catalog' ),
			'',
		);

		foreach ( $alerts as $alert ) {
			$lines[] = self::describe( $alert );
			$lines[] = self::link( 'confirm', $alert );
			$lines[] = '';
		}

		$lines[] = __( 'If you did not ask for this, ignore this e-mail: nothing will be sent to you.', 'wax-store-catalog' );
		$lines[] = '';
		$lines[] = $shop;
		$lines[] = home_url( '/' );

		/* translators: %s: shop name. */
		return wp_mail( $email, sprintf( __( 'Confirm your alert at %s', 'wax-store-catalog' ), $shop ), implode( "\n", $lines ) );
	}

	/**
	 * Digest of the releases matching the alerts of one address.
	 *
	 * @param string $email Address.
	 * @param array  $items List of [ alert, releases ].
	 */
	public static function digest( string $email, array $items ): bool {
		$shop  = Alerts::shop_name();
		$lines = array( __( 'Hello,', 'wax-store-catalog' ), '' );
		$total = 0;
		$seen  = array();

		foreach ( $items as $item ) {
			$releases = array_filter(
				array_map( 'intval', $item['releases'] ),
				static function ( $release ) use ( $seen ) {
					return 'publish' === get_post_status( $release ) && ! in_array( $release, $seen, true );
				}
			);

			$seen = array_merge( $seen, $releases );

			if ( ! $releases ) {
				continue;
			}

			$lines[] = self::describe( (int) $item['alert'] );

			foreach ( $releases as $release ) {
				$prices  = Copies::available_prices( $release );
				$price   = $prices ? ' – ' . Copies::format_price( $prices[0] ) : '';
				$lines[] = '  • ' . Requests::release_label( $release ) . $price;
				$lines[] = '    ' . get_permalink( $release );
				++$total;
			}

			$lines[] = '';
		}

		if ( 0 === $total ) {
			return true;
		}

		$lines[] = __( 'Second-hand copies are unique: first come, first served.', 'wax-store-catalog' );
		$lines[] = '';
		$lines[] = $shop;
		$lines[] = home_url( '/' );
		// Back-in-stock alerts are one-shot: they end with this e-mail.
		$ongoing = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return 'release' !== get_post_meta( (int) $item['alert'], Alerts::KIND, true );
				}
			)
		);

		if ( $ongoing ) {
			$lines[] = '';
			$lines[] = __( 'Stop these alerts:', 'wax-store-catalog' );

			foreach ( $ongoing as $item ) {
				/* translators: 1: alert description, 2: link. */
				$lines[] = sprintf( __( '%1$s: %2$s', 'wax-store-catalog' ), self::describe( (int) $item['alert'] ), self::link( 'stop', (int) $item['alert'] ) );
			}
		}

		if ( count( $ongoing ) < count( $items ) ) {
			$lines[] = '';
			$lines[] = __( 'Back-in-stock alerts are sent once: this one is now closed.', 'wax-store-catalog' );
		}

		$subject = sprintf(
			/* translators: 1: shop name, 2: number of releases. */
			_n( '%1$s: %2$d record you are waiting for is in the bins', '%1$s: %2$d records you are waiting for are in the bins', $total, 'wax-store-catalog' ),
			$shop,
			$total
		);

		$headers = $ongoing ? array( 'List-Unsubscribe: <' . self::link( 'stop', (int) $ongoing[0]['alert'] ) . '>' ) : array();

		return wp_mail( $email, $subject, implode( "\n", $lines ), $headers );
	}

	/**
	 * "Pink Floyd – Meddle back in stock" / "New arrivals of Pink Floyd".
	 *
	 * @param int $alert Alert ID.
	 */
	public static function describe( int $alert ): string {
		$kind   = (string) get_post_meta( $alert, Alerts::KIND, true );
		$target = Alerts::target_label( $kind, (int) get_post_meta( $alert, Alerts::TARGET, true ) );

		/* translators: %s: "Artist – Title". */
		return 'release' === $kind ? sprintf( __( '%s back in stock', 'wax-store-catalog' ), $target )
			/* translators: %s: artist or label name. */
			: sprintf( __( 'New arrivals of %s', 'wax-store-catalog' ), $target );
	}
}
