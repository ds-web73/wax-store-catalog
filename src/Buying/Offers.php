<?php
/**
 * Collection offers ("Sell us your records"): storage, protected files,
 * admin screens, retention and privacy tools.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Buying;

use WaxStore\Catalog\Content\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Files are stored under random names in a closed folder and only served
 * to users who can edit others' posts.
 */
final class Offers {

	const POST_TYPE = 'waxsc_offer';
	const CRON      = 'waxsc_purge_offers';
	const DIR       = 'wax-store-catalog/offers';
	const DATA      = '_waxsc_offer';
	const FILES     = '_waxsc_offer_files';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( self::CRON, array( self::class, 'purge' ) );
		add_action( 'before_delete_post', array( self::class, 'delete_files' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . self::POST_TYPE, array( self::class, 'meta_box' ) );
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
			add_action( 'admin_post_waxsc_offer_file', array( self::class, 'serve_file' ) );
		}
	}

	/**
	 * Private post type under the Catalog menu.
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Collection offers', 'wax-store-catalog' ),
					'singular_name' => __( 'Collection offer', 'wax-store-catalog' ),
					'all_items'     => __( 'Collection offers', 'wax-store-catalog' ),
					'edit_item'     => __( 'Collection offer', 'wax-store-catalog' ),
					'search_items'  => __( 'Search offers', 'wax-store-catalog' ),
					'not_found'     => __( 'No collection offer yet.', 'wax-store-catalog' ),
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
	 * Protected folder for the files, created on demand.
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;

		if ( ! is_dir( $dir ) && wp_mkdir_p( $dir ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			if ( WP_Filesystem() ) {
				global $wp_filesystem;
				$wp_filesystem->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
				$wp_filesystem->put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", FS_CHMOD_FILE );
			}
		}

		return $dir;
	}

	/**
	 * Stores an offer.
	 *
	 * @param array $data  Offer fields.
	 * @param array $files Stored files: [ name, path, type ].
	 */
	public static function create( array $data, array $files ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				/* translators: 1: seller name, 2: collection size. */
				'post_title'  => wp_slash( sprintf( __( '%1$s – %2$s', 'wax-store-catalog' ), $data['name'], self::size_label( $data['size'] ) ) ),
				'meta_input'  => array(
					self::DATA  => wp_slash( $data ),
					self::FILES => $files,
				),
			),
			true
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Collection sizes.
	 */
	public static function sizes(): array {
		return array(
			'under-50'   => __( 'Fewer than 50 records', 'wax-store-catalog' ),
			'50-200'     => __( '50 to 200 records', 'wax-store-catalog' ),
			'200-1000'   => __( '200 to 1,000 records', 'wax-store-catalog' ),
			'over-1000'  => __( 'More than 1,000 records', 'wax-store-catalog' ),
		);
	}

	/**
	 * Formats offered.
	 */
	public static function formats(): array {
		return array(
			'vinyl'    => __( 'Vinyl', 'wax-store-catalog' ),
			'cd'       => __( 'CD', 'wax-store-catalog' ),
			'cassette' => __( 'Cassette', 'wax-store-catalog' ),
			'other'    => __( 'Other (DVD, merchandise…)', 'wax-store-catalog' ),
		);
	}

	/**
	 * Size label.
	 *
	 * @param string $size Key.
	 */
	public static function size_label( string $size ): string {
		return self::sizes()[ $size ] ?? '';
	}

	/**
	 * Details meta box.
	 */
	public static function meta_box(): void {
		add_meta_box( 'waxsc_offer', __( 'Offer details', 'wax-store-catalog' ), array( self::class, 'render_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Renders the offer.
	 *
	 * @param \WP_Post $post Offer.
	 */
	public static function render_box( \WP_Post $post ): void {
		$data    = (array) get_post_meta( $post->ID, self::DATA, true );
		$files   = (array) get_post_meta( $post->ID, self::FILES, true );
		$formats = array_intersect_key( self::formats(), array_flip( (array) ( $data['formats'] ?? array() ) ) );
		$rows    = array(
			__( 'Received', 'wax-store-catalog' )        => esc_html( get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) ),
			__( 'Name', 'wax-store-catalog' )            => esc_html( $data['name'] ?? '' ),
			__( 'Email', 'wax-store-catalog' )           => '<a href="mailto:' . esc_attr( $data['email'] ?? '' ) . '">' . esc_html( $data['email'] ?? '' ) . '</a>',
			__( 'Phone', 'wax-store-catalog' )           => esc_html( $data['phone'] ?? '' ),
			__( 'City', 'wax-store-catalog' )            => esc_html( $data['city'] ?? '' ),
			__( 'Collection size', 'wax-store-catalog' ) => esc_html( self::size_label( (string) ( $data['size'] ?? '' ) ) ),
			__( 'Formats', 'wax-store-catalog' )         => esc_html( implode( ', ', $formats ) ),
			__( 'Main genres', 'wax-store-catalog' )     => esc_html( $data['genres'] ?? '' ),
			__( 'Description', 'wax-store-catalog' )     => nl2br( esc_html( $data['message'] ?? '' ) ),
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $rows as $label => $value ) {
			if ( '' !== trim( wp_strip_all_tags( $value ) ) ) {
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}
		}

		if ( $files ) {
			echo '<tr><th scope="row">' . esc_html__( 'Files', 'wax-store-catalog' ) . '</th><td><ul style="display:flex;flex-wrap:wrap;gap:1rem;list-style:none;margin:0;padding:0">';

			foreach ( $files as $index => $file ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=waxsc_offer_file&offer=' . $post->ID . '&file=' . $index ), 'waxsc_offer_file_' . $post->ID );

				if ( 0 === strpos( (string) $file['type'], 'image/' ) ) {
					echo '<li><a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $file['name'] ) . '" style="width:160px;height:160px;object-fit:cover"></a></li>';
				} else {
					echo '<li><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $file['name'] ) . '</a></li>';
				}
			}

			echo '</ul></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Streams a protected file to an authorized user.
	 */
	public static function serve_file(): void {
		$offer = isset( $_GET['offer'] ) ? absint( $_GET['offer'] ) : 0;
		$index = isset( $_GET['file'] ) ? absint( $_GET['file'] ) : 0;

		check_admin_referer( 'waxsc_offer_file_' . $offer );

		if ( ! current_user_can( 'edit_others_posts' ) || self::POST_TYPE !== get_post_type( $offer ) ) {
			wp_die( esc_html__( 'You are not allowed to see this file.', 'wax-store-catalog' ), 403 );
		}

		$files = (array) get_post_meta( $offer, self::FILES, true );
		$file  = $files[ $index ] ?? null;
		$path  = $file ? (string) $file['path'] : '';

		if ( ! $file || 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( self::dir() ) ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'This file no longer exists.', 'wax-store-catalog' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: ' . $file['type'] );
		header( 'Content-Disposition: ' . ( 0 === strpos( (string) $file['type'], 'image/' ) ? 'inline' : 'attachment' ) . '; filename="' . sanitize_file_name( $file['name'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a protected file.
		exit;
	}

	/**
	 * Deletes the files of an offer with the offer.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_files( int $post_id ): void {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		foreach ( (array) get_post_meta( $post_id, self::FILES, true ) as $file ) {
			if ( ! empty( $file['path'] ) && 0 === strpos( wp_normalize_path( $file['path'] ), wp_normalize_path( self::dir() ) ) ) {
				wp_delete_file( $file['path'] );
			}
		}
	}

	/**
	 * List columns.
	 *
	 * @param array $columns Columns.
	 */
	public static function columns( array $columns ): array {
		return array(
			'cb'          => $columns['cb'] ?? '',
			'title'       => __( 'Offer', 'wax-store-catalog' ),
			'waxsc_email' => __( 'Email', 'wax-store-catalog' ),
			'waxsc_city'  => __( 'City', 'wax-store-catalog' ),
			'date'        => $columns['date'] ?? __( 'Date', 'wax-store-catalog' ),
		);
	}

	/**
	 * Column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Offer ID.
	 */
	public static function column( string $column, int $post_id ): void {
		$data = (array) get_post_meta( $post_id, self::DATA, true );

		if ( 'waxsc_email' === $column ) {
			echo esc_html( $data['email'] ?? '' );
		}

		if ( 'waxsc_city' === $column ) {
			echo esc_html( $data['city'] ?? '' );
		}
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
	 * Deletes offers (and their files) older than 12 months.
	 */
	public static function purge(): void {
		$days = max( 1, (int) apply_filters( 'waxsc_offer_retention_days', 365 ) );
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
	 * Offers of an address.
	 *
	 * @param string $email Address.
	 * @return int[]
	 */
	private static function of( string $email ): array {
		$found = array();
		$ids   = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			$data = (array) get_post_meta( (int) $id, self::DATA, true );

			if ( isset( $data['email'] ) && 0 === strcasecmp( (string) $data['email'], $email ) ) {
				$found[] = (int) $id;
			}
		}

		return $found;
	}

	/**
	 * Privacy exporter registration.
	 *
	 * @param array $exporters Exporters.
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['wax-store-catalog-offers'] = array(
			'exporter_friendly_name' => __( 'Collection offers', 'wax-store-catalog' ),
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
		$erasers['wax-store-catalog-offers'] = array(
			'eraser_friendly_name' => __( 'Collection offers', 'wax-store-catalog' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Personal data export.
	 *
	 * @param string $email Address.
	 */
	public static function export( $email ): array {
		$items = array();

		foreach ( self::of( (string) $email ) as $id ) {
			$data    = (array) get_post_meta( $id, self::DATA, true );
			$items[] = array(
				'group_id'    => 'waxsc-offers',
				'group_label' => __( 'Collection offers', 'wax-store-catalog' ),
				'item_id'     => 'waxsc-offer-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Name', 'wax-store-catalog' ),
						'value' => $data['name'] ?? '',
					),
					array(
						'name'  => __( 'Phone', 'wax-store-catalog' ),
						'value' => $data['phone'] ?? '',
					),
					array(
						'name'  => __( 'City', 'wax-store-catalog' ),
						'value' => $data['city'] ?? '',
					),
					array(
						'name'  => __( 'Description', 'wax-store-catalog' ),
						'value' => $data['message'] ?? '',
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Personal data erasure (offers and files).
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
}
