<?php
/**
 * Reservation requests: storage, admin screens, retention.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Reservation;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\ReleaseMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Every request is stored, so none is lost when e-mail delivery fails.
 */
final class Requests {

	const POST_TYPE = 'waxsc_request';
	const CRON      = 'waxsc_purge_requests';

	const META_EMAIL   = '_waxsc_req_email';
	const META_PHONE   = '_waxsc_req_phone';
	const META_MESSAGE = '_waxsc_req_message';
	const META_RELEASE = '_waxsc_req_release';
	const META_COPY    = '_waxsc_req_copy';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( self::CRON, array( self::class, 'purge' ) );
		add_action( 'init', array( self::class, 'schedule' ) );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . self::POST_TYPE, array( self::class, 'meta_box' ) );
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
			add_action( 'admin_post_waxsc_request_reserve', array( self::class, 'handle_reserve' ) );
			add_action( 'admin_notices', array( self::class, 'notice' ) );
			add_action( 'admin_init', array( self::class, 'privacy_text' ) );
		}
	}

	/**
	 * Private post type, listed under the Catalog menu. Requests are created by the form only.
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Reservation requests', 'wax-store-catalog' ),
					'singular_name' => __( 'Reservation request', 'wax-store-catalog' ),
					'menu_name'     => __( 'Reservations', 'wax-store-catalog' ),
					'all_items'     => __( 'Reservations', 'wax-store-catalog' ),
					'edit_item'     => __( 'Reservation request', 'wax-store-catalog' ),
					'search_items'  => __( 'Search requests', 'wax-store-catalog' ),
					'not_found'     => __( 'No reservation request yet.', 'wax-store-catalog' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Stores a request.
	 *
	 * @param array $data name, email, phone, message, release, copy.
	 * @return int Request ID, 0 on failure.
	 */
	public static function create( array $data ): int {
		$title = $data['name'];

		if ( $data['release'] ) {
			$title .= ' – ' . self::release_label( (int) $data['release'] );
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => wp_slash( $title ),
				'meta_input'  => array(
					self::META_EMAIL   => $data['email'],
					self::META_PHONE   => $data['phone'],
					self::META_MESSAGE => wp_slash( $data['message'] ),
					self::META_RELEASE => (int) $data['release'],
					self::META_COPY    => $data['copy'],
				),
			),
			true
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * "Artist – Title" of a release.
	 *
	 * @param int $release_id Release ID.
	 */
	public static function release_label( int $release_id ): string {
		$terms  = get_the_terms( $release_id, 'waxsc_artist' );
		$artist = is_array( $terms ) && $terms ? $terms[0]->name . ' – ' : '';

		return $artist . html_entity_decode( get_the_title( $release_id ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Finds one copy of a release.
	 *
	 * @param int    $release_id Release ID.
	 * @param string $copy_id    Copy ID.
	 */
	public static function find_copy( int $release_id, string $copy_id ): array {
		foreach ( Copies::get( $release_id ) as $copy ) {
			if ( '' !== $copy_id && $copy['id'] === $copy_id ) {
				return $copy;
			}
		}

		return array();
	}

	/**
	 * Marks a copy as reserved.
	 *
	 * @param int    $release_id Release ID.
	 * @param string $copy_id    Copy ID.
	 */
	public static function reserve_copy( int $release_id, string $copy_id ): bool {
		$copies = Copies::get( $release_id );

		foreach ( $copies as $index => $copy ) {
			if ( $copy['id'] === $copy_id && ProviderInterface::IN_STOCK === $copy['status'] ) {
				$copies[ $index ]['status'] = ProviderInterface::RESERVED;
				update_post_meta( $release_id, ReleaseMeta::COPIES, $copies );
				return true;
			}
		}

		return false;
	}

	/**
	 * Details meta box (read only).
	 */
	public static function meta_box(): void {
		add_meta_box( 'waxsc_request', __( 'Request details', 'wax-store-catalog' ), array( self::class, 'render_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Renders the details.
	 *
	 * @param \WP_Post $post Request.
	 */
	public static function render_box( \WP_Post $post ): void {
		$release = (int) get_post_meta( $post->ID, self::META_RELEASE, true );
		$copy_id = (string) get_post_meta( $post->ID, self::META_COPY, true );
		$email   = (string) get_post_meta( $post->ID, self::META_EMAIL, true );
		$phone   = (string) get_post_meta( $post->ID, self::META_PHONE, true );
		$message = (string) get_post_meta( $post->ID, self::META_MESSAGE, true );
		$copy    = $release ? self::find_copy( $release, $copy_id ) : array();

		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( __( 'Received', 'wax-store-catalog' ), esc_html( get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) ) );
		self::row( __( 'Email', 'wax-store-catalog' ), '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' );

		if ( '' !== $phone ) {
			self::row( __( 'Phone', 'wax-store-catalog' ), '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>' );
		}

		if ( $release && get_post( $release ) ) {
			self::row( __( 'Release', 'wax-store-catalog' ), '<a href="' . esc_url( (string) get_edit_post_link( $release ) ) . '">' . esc_html( self::release_label( $release ) ) . '</a>' );
		}

		if ( $copy ) {
			$statuses = array(
				ProviderInterface::IN_STOCK => __( 'In stock', 'wax-store-catalog' ),
				ProviderInterface::RESERVED => __( 'Reserved', 'wax-store-catalog' ),
				ProviderInterface::SOLD     => __( 'Sold', 'wax-store-catalog' ),
			);
			$details  = trim( implode( ' / ', array_filter( array( $copy['media'], $copy['sleeve'] ) ) ) . ' ' . ( '' !== $copy['price'] ? Copies::format_price( $copy['price'] ) : '' ) );
			$status   = $statuses[ $copy['status'] ] ?? '';
			$button   = '';

			if ( ProviderInterface::IN_STOCK === $copy['status'] && current_user_can( 'edit_post', $release ) ) {
				$url    = wp_nonce_url( admin_url( 'admin-post.php?action=waxsc_request_reserve&request=' . $post->ID ), 'waxsc_request_reserve_' . $post->ID );
				$button = ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Mark this copy as reserved', 'wax-store-catalog' ) . '</a>';
			}

			self::row( __( 'Copy', 'wax-store-catalog' ), esc_html( $details . ' (' . $status . ')' ) . $button );

			if ( '' !== $copy['location'] ) {
				self::row( __( 'Location in the shop', 'wax-store-catalog' ), esc_html( $copy['location'] ) );
			}
		}

		if ( '' !== $message ) {
			self::row( __( 'Message', 'wax-store-catalog' ), nl2br( esc_html( $message ) ) );
		}

		echo '</tbody></table>';
	}

	/**
	 * One details row (value already escaped).
	 *
	 * @param string $label Label.
	 * @param string $value Escaped HTML.
	 */
	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by callers.
	}

	/**
	 * List columns.
	 *
	 * @param array $columns Columns.
	 */
	public static function columns( array $columns ): array {
		return array(
			'cb'            => $columns['cb'] ?? '',
			'title'         => __( 'Request', 'wax-store-catalog' ),
			'waxsc_email'   => __( 'Email', 'wax-store-catalog' ),
			'waxsc_release' => __( 'Release', 'wax-store-catalog' ),
			'date'          => $columns['date'] ?? __( 'Date', 'wax-store-catalog' ),
		);
	}

	/**
	 * Column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Request ID.
	 */
	public static function column( string $column, int $post_id ): void {
		if ( 'waxsc_email' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, self::META_EMAIL, true ) );
		}

		if ( 'waxsc_release' === $column ) {
			$release = (int) get_post_meta( $post_id, self::META_RELEASE, true );
			echo $release && get_post( $release ) ? esc_html( self::release_label( $release ) ) : '—';
		}
	}

	/**
	 * "Mark this copy as reserved" button.
	 */
	public static function handle_reserve(): void {
		$request = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;

		check_admin_referer( 'waxsc_request_reserve_' . $request );

		$release = (int) get_post_meta( $request, self::META_RELEASE, true );
		$copy_id = (string) get_post_meta( $request, self::META_COPY, true );

		if ( ! $release || ! current_user_can( 'edit_post', $release ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this release.', 'wax-store-catalog' ), 403 );
		}

		$done = self::reserve_copy( $release, $copy_id );

		wp_safe_redirect( add_query_arg( 'waxsc_reserved', $done ? '1' : '0', (string) get_edit_post_link( $request, 'url' ) ) );
		exit;
	}

	/**
	 * Result notice of the reserve button.
	 */
	public static function notice(): void {
		if ( ! isset( $_GET['waxsc_reserved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}

		$done = '1' === $_GET['waxsc_reserved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			$done ? 'success' : 'warning',
			esc_html( $done ? __( 'The copy is now reserved.', 'wax-store-catalog' ) : __( 'This copy is no longer in stock.', 'wax-store-catalog' ) )
		);
	}

	/**
	 * Daily retention job.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Deletes requests older than the retention period (12 months by default).
	 */
	public static function purge(): void {
		/**
		 * Days a reservation request is kept.
		 *
		 * @param int $days Retention in days.
		 */
		$days = max( 1, (int) apply_filters( 'waxsc_request_retention_days', 365 ) );
		$ids  = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'date_query'     => array( array( 'before' => $days . ' days ago' ) ),
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/**
	 * Suggested text for the privacy policy page.
	 */
	public static function privacy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'Wax Store Catalog',
			'<p>' . esc_html__( 'When you send a reservation request, we store your name, e-mail address, phone number if given, message and the record you asked about. We use them only to answer your request. They are deleted automatically after 12 months.', 'wax-store-catalog' ) . '</p>'
		);
	}
}
