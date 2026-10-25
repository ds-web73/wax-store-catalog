<?php
/**
 * "Notify me" alerts: storage, triggers, digests, retention and privacy tools.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Alerts;

use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\Taxonomies;
use WaxStore\Catalog\Reservation\Requests;
use WaxStore\Catalog\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * An alert follows one release (back in stock, one-shot) or one artist or
 * label (new arrivals, until unsubscribed). Matching releases are queued
 * and sent as one e-mail per subscriber, at most once an hour.
 */
final class Alerts {

	const POST_TYPE = 'waxsc_alert';
	const CRON      = 'waxsc_send_alerts';

	const EMAIL  = '_waxsc_alert_email';
	const KIND   = '_waxsc_alert_kind';
	const TARGET = '_waxsc_alert_target';
	const TOKEN  = '_waxsc_alert_token';
	const QUEUE  = '_waxsc_alert_queue';

	const KINDS = array( 'release', Taxonomies::ARTIST, Taxonomies::LABEL );

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( self::CRON, array( self::class, 'send' ) );
		add_action( 'waxsc_release_back_in_stock', array( self::class, 'on_available' ) );
		add_action( 'transition_post_status', array( self::class, 'on_publish' ), 10, 3 );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );

		if ( is_admin() ) {
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		}
	}

	/**
	 * Private post type under the Catalog menu. Created by the form only.
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Alerts', 'wax-store-catalog' ),
					'singular_name' => __( 'Alert', 'wax-store-catalog' ),
					'all_items'     => __( 'Alerts', 'wax-store-catalog' ),
					'search_items'  => __( 'Search alerts', 'wax-store-catalog' ),
					'not_found'     => __( 'No alert yet.', 'wax-store-catalog' ),
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
	 * Hourly sending and daily cleanup.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/**
	 * Name of what an alert follows.
	 *
	 * @param string $kind   release, waxsc_artist or waxsc_label.
	 * @param int    $target Release or term ID.
	 */
	public static function target_label( string $kind, int $target ): string {
		if ( 'release' === $kind ) {
			return get_post( $target ) ? Requests::release_label( $target ) : '';
		}

		$term = get_term( $target, $kind );

		return $term instanceof \WP_Term ? $term->name : '';
	}

	/**
	 * Finds an alert of an address on a target.
	 *
	 * @param string $email  Address.
	 * @param string $kind   Kind.
	 * @param int    $target Target.
	 */
	public static function find( string $email, string $kind, int $target ): int {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small table.
					array(
						'key'   => self::EMAIL,
						'value' => $email,
					),
					array(
						'key'   => self::KIND,
						'value' => $kind,
					),
					array(
						'key'   => self::TARGET,
						'value' => $target,
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Creates a pending alert (or returns the existing one).
	 *
	 * @param string $email  Address.
	 * @param string $kind   Kind.
	 * @param int    $target Target.
	 * @return int Alert ID.
	 */
	public static function create( string $email, string $kind, int $target ): int {
		$existing = self::find( $email, $kind, $target );

		if ( $existing ) {
			return $existing;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'pending',
				'post_title'  => wp_slash( $email . ' – ' . self::target_label( $kind, $target ) ),
				'meta_input'  => array(
					self::EMAIL  => $email,
					self::KIND   => $kind,
					self::TARGET => $target,
					self::TOKEN  => strtolower( wp_generate_password( 32, false, false ) ),
				),
			),
			true
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Alert of a token.
	 *
	 * @param string $token Token.
	 */
	public static function by_token( string $token ): int {
		if ( strlen( $token ) < 20 ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::TOKEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single lookup.
				'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- single lookup.
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * A release came back in stock: release alerts and its artists and labels.
	 *
	 * @param int $release Release ID.
	 */
	public static function on_available( int $release ): void {
		if ( 'publish' !== get_post_status( $release ) ) {
			return;
		}

		self::queue_for( $release, true );
	}

	/**
	 * A new release is published: artist and label alerts.
	 *
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public static function on_publish( $new, $old, $post ): void {
		if ( 'publish' !== $new || 'publish' === $old || PostType::NAME !== $post->post_type ) {
			return;
		}

		// Copies are saved right after the post: decide once everything is stored.
		add_action(
			'shutdown',
			static function () use ( $post ) {
				if ( 'in_stock' === Copies::availability( (int) $post->ID ) ) {
					self::queue_for( (int) $post->ID, false );
				}
			}
		);
	}

	/**
	 * Adds a release to the queue of the matching active alerts.
	 *
	 * @param int  $release      Release ID.
	 * @param bool $with_release Include alerts on the release itself.
	 */
	private static function queue_for( int $release, bool $with_release ): void {
		$targets = array();

		if ( $with_release ) {
			$targets[] = array( 'release', array( $release ) );
		}

		foreach ( array( Taxonomies::ARTIST, Taxonomies::LABEL ) as $taxonomy ) {
			$terms = get_the_terms( $release, $taxonomy );

			if ( is_array( $terms ) && $terms ) {
				$targets[] = array( $taxonomy, wp_list_pluck( $terms, 'term_id' ) );
			}
		}

		foreach ( $targets as $target ) {
			$alerts = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small table.
						array(
							'key'   => self::KIND,
							'value' => $target[0],
						),
						array(
							'key'     => self::TARGET,
							'value'   => array_map( 'intval', $target[1] ),
							'compare' => 'IN',
						),
					),
				)
			);

			foreach ( $alerts as $alert ) {
				$queue = get_post_meta( (int) $alert, self::QUEUE, true );
				$queue = is_array( $queue ) ? $queue : array();

				if ( ! in_array( $release, $queue, true ) ) {
					$queue[] = $release;
					update_post_meta( (int) $alert, self::QUEUE, $queue );
				}
			}
		}
	}

	/**
	 * Hourly job: one e-mail per address with every queued release, then
	 * cleanup (release alerts are one-shot; unconfirmed alerts expire).
	 */
	public static function send(): void {
		$alerts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_key'       => self::QUEUE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- hourly job.
			)
		);

		$by_email = array();

		foreach ( $alerts as $alert ) {
			$alert = (int) $alert;
			$queue = get_post_meta( $alert, self::QUEUE, true );

			if ( ! is_array( $queue ) || ! $queue ) {
				delete_post_meta( $alert, self::QUEUE );
				continue;
			}

			$by_email[ (string) get_post_meta( $alert, self::EMAIL, true ) ][] = array(
				'alert'    => $alert,
				'releases' => $queue,
			);
		}

		foreach ( $by_email as $email => $items ) {
			// Records the subscriber waited for come first, then new arrivals.
			usort(
				$items,
				static function ( $a, $b ) {
					return (int) ( 'release' !== get_post_meta( $a['alert'], self::KIND, true ) ) <=> (int) ( 'release' !== get_post_meta( $b['alert'], self::KIND, true ) );
				}
			);

			if ( Mailer::digest( $email, $items ) ) {
				foreach ( $items as $item ) {
					delete_post_meta( $item['alert'], self::QUEUE );

					if ( 'release' === get_post_meta( $item['alert'], self::KIND, true ) ) {
						wp_delete_post( $item['alert'], true );
					}
				}
			}
		}

		$expired = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'pending',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'date_query'     => array( array( 'before' => '7 days ago' ) ),
			)
		);

		foreach ( $expired as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/**
	 * Admin columns.
	 *
	 * @param array $columns Columns.
	 */
	public static function columns( array $columns ): array {
		return array(
			'cb'           => $columns['cb'] ?? '',
			'title'        => __( 'Alert', 'wax-store-catalog' ),
			'waxsc_kind'   => __( 'Follows', 'wax-store-catalog' ),
			'waxsc_status' => __( 'Status', 'wax-store-catalog' ),
			'date'         => $columns['date'] ?? __( 'Date', 'wax-store-catalog' ),
		);
	}

	/**
	 * Column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Alert ID.
	 */
	public static function column( string $column, int $post_id ): void {
		$kind   = (string) get_post_meta( $post_id, self::KIND, true );
		$target = (int) get_post_meta( $post_id, self::TARGET, true );

		if ( 'waxsc_kind' === $column ) {
			$kinds = array(
				'release'          => __( 'Release back in stock', 'wax-store-catalog' ),
				Taxonomies::ARTIST => __( 'New arrivals of an artist', 'wax-store-catalog' ),
				Taxonomies::LABEL  => __( 'New arrivals of a label', 'wax-store-catalog' ),
			);
			echo esc_html( ( $kinds[ $kind ] ?? $kind ) . ' : ' . self::target_label( $kind, $target ) );
		}

		if ( 'waxsc_status' === $column ) {
			echo esc_html( 'publish' === get_post_status( $post_id ) ? __( 'Confirmed', 'wax-store-catalog' ) : __( 'Waiting for confirmation', 'wax-store-catalog' ) );
		}
	}

	/**
	 * Privacy exporter registration.
	 *
	 * @param array $exporters Exporters.
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['wax-store-catalog-alerts'] = array(
			'exporter_friendly_name' => __( 'Record shop alerts', 'wax-store-catalog' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Privacy eraser registration.
	 *
	 * @param array $erasers Erasers.
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['wax-store-catalog-alerts'] = array(
			'eraser_friendly_name' => __( 'Record shop alerts', 'wax-store-catalog' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Alerts of an address.
	 *
	 * @param string $email Address.
	 * @return int[]
	 */
	private static function of( string $email ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => array( 'publish', 'pending' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => self::EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- privacy request.
					'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- privacy request.
				)
			)
		);
	}

	/**
	 * Personal data export.
	 *
	 * @param string $email Address.
	 */
	public static function export( $email ): array {
		$data = array();

		foreach ( self::of( (string) $email ) as $id ) {
			$data[] = array(
				'group_id'    => 'waxsc-alerts',
				'group_label' => __( 'Record shop alerts', 'wax-store-catalog' ),
				'item_id'     => 'waxsc-alert-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Follows', 'wax-store-catalog' ),
						'value' => self::target_label( (string) get_post_meta( $id, self::KIND, true ), (int) get_post_meta( $id, self::TARGET, true ) ),
					),
					array(
						'name'  => __( 'Date', 'wax-store-catalog' ),
						'value' => get_the_date( '', $id ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Personal data erasure.
	 *
	 * @param string $email Address.
	 */
	public static function erase( $email ): array {
		$ids = self::of( (string) $email );

		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}

		return array(
			'items_removed'  => count( $ids ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Shop name for e-mails.
	 */
	public static function shop_name(): string {
		$name = (string) Settings::get( 'identity', 'shop_name' );

		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
