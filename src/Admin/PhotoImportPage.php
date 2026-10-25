<?php
/**
 * Record shop > Tools > Photos: covers and gallery photos matched by file name.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

use WaxStore\Catalog\Content\Gallery;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Import\PhotoMatcher;
use WaxStore\Catalog\Reservation\Requests;

use const WaxStore\Catalog\PLUGIN_FILE;
use const WaxStore\Catalog\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The browser sends files one by one: no upload size or time limit,
 * unmatched files are never uploaded, already imported files are skipped.
 */
final class PhotoImportPage {

	const SLUG  = 'waxsc-photos';
	const NONCE = 'waxsc_photos';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_waxsc_photo_index', array( self::class, 'ajax_index' ) );
		add_action( 'wp_ajax_waxsc_photo_match', array( self::class, 'ajax_match' ) );
		add_action( 'wp_ajax_waxsc_photo_upload', array( self::class, 'ajax_upload' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostType::NAME,
			__( 'Import photos', 'wax-store-catalog' ),
			__( 'Import photos', 'wax-store-catalog' ),
			'upload_files',
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( $hook ) {
			add_action( 'admin_print_scripts-' . $hook, array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * Script.
	 */
	public static function enqueue(): void {
		wp_enqueue_script( 'waxsc-photo-import', plugin_dir_url( PLUGIN_FILE ) . 'assets/photo-import.js', array(), VERSION, true );
		wp_add_inline_script(
			'waxsc-photo-import',
			'window.waxscPhotos = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE ),
					'i18n'    => array(
						/* translators: 1: files done, 2: total files. */
						'progress' => __( '%1$d of %2$d files processed', 'wax-store-catalog' ),
						'done'     => __( 'Finished.', 'wax-store-catalog' ),
						'error'    => __( 'Server error, file skipped.', 'wax-store-catalog' ),
						/* translators: 1: covers, 2: gallery photos, 3: skipped files, 4: unmatched files. */
						'summary'  => __( 'Covers: %1$d, gallery photos: %2$d, already imported: %3$d, not matched: %4$d', 'wax-store-catalog' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Screen.
	 */
	public static function render(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import photos', 'wax-store-catalog' ); ?></h1>
			<p><?php esc_html_e( 'Select your photos: each one is attached to its release by its file name. Files with no matching release are not uploaded.', 'wax-store-catalog' ); ?></p>
			<p><?php esc_html_e( 'The file name can be the catalog number (SHVL795.jpg, spaces and dashes do not matter), the Discogs listing or release ID, the barcode, or the release URL slug.', 'wax-store-catalog' ); ?></p>
			<p><?php esc_html_e( 'The first photo becomes the cover. Add a suffix for the other ones: SHVL795-2.jpg, SHVL795_verso.jpg, SHVL795-label.jpg… They go to the release gallery.', 'wax-store-catalog' ); ?></p>

			<form id="waxsc-photo-form">
				<p>
					<label for="waxsc-photo-files"><strong><?php esc_html_e( 'Photos', 'wax-store-catalog' ); ?></strong></label><br>
					<input type="file" id="waxsc-photo-files" accept="image/jpeg,image/png,image/webp" multiple>
				</p>
				<p>
					<label><input type="checkbox" id="waxsc-photo-replace" value="1"> <?php esc_html_e( 'Replace covers that are already set (otherwise the photo goes to the gallery)', 'wax-store-catalog' ); ?></label>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Start import', 'wax-store-catalog' ); ?></button></p>
			</form>

			<div id="waxsc-photo-progress" hidden>
				<progress max="1" value="0" style="width:100%;max-width:40rem;height:1.5rem"></progress>
				<p aria-live="polite" data-waxsc-status></p>
				<p data-waxsc-summary></p>
				<table class="widefat striped" style="max-width:60rem">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'File', 'wax-store-catalog' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'wax-store-catalog' ); ?></th>
						</tr>
					</thead>
					<tbody data-waxsc-log></tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Common checks.
	 */
	private static function check(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to upload files.', 'wax-store-catalog' ) ), 403 );
		}
	}

	/**
	 * Rebuilds the release index at the start of a batch.
	 */
	public static function ajax_index(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::check();
		$index = PhotoMatcher::index( true );
		wp_send_json_success( array( 'releases' => count( $index['slug'] ) ) );
	}

	/**
	 * Matches a file name before uploading it.
	 */
	public static function ajax_match(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::check();

		$name   = isset( $_POST['name'] ) ? sanitize_file_name( wp_unslash( $_POST['name'] ) ) : '';
		$match  = PhotoMatcher::match( $name );
		$result = self::describe( $match );

		if ( 'found' === $match['status'] && self::already_imported( $match['release'], $name ) ) {
			$result['status']  = 'skip';
			$result['message'] = sprintf(
				/* translators: %s: "Artist – Title". */
				__( 'Already imported for %s', 'wax-store-catalog' ),
				Requests::release_label( $match['release'] )
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Uploads one file and attaches it.
	 */
	public static function ajax_upload(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::check();

		if ( empty( $_FILES['file']['name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received.', 'wax-store-catalog' ) ) );
		}

		$name  = sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) );
		$match = PhotoMatcher::match( $name );

		if ( 'found' !== $match['status'] || ! current_user_can( 'edit_post', $match['release'] ) ) {
			wp_send_json_success( self::describe( $match ) );
		}

		$release = $match['release'];

		if ( self::already_imported( $release, $name ) ) {
			wp_send_json_success(
				array(
					'status'  => 'skip',
					'message' => __( 'Already imported', 'wax-store-catalog' ),
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$label = Requests::release_label( $release );
		$cover = 1 === $match['position'];
		$alt   = $cover
			/* translators: %s: "Artist – Title". */
			? sprintf( __( '%s, cover', 'wax-store-catalog' ), $label )
			/* translators: %s: "Artist – Title". */
			: sprintf( __( '%s, photo', 'wax-store-catalog' ), $label );

		$attachment = media_handle_upload(
			'file',
			$release,
			array( 'post_title' => $alt ),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				),
			)
		);

		if ( is_wp_error( $attachment ) ) {
			wp_send_json_success(
				array(
					'status'  => 'error',
					'message' => $attachment->get_error_message(),
				)
			);
		}

		update_post_meta( $attachment, '_wp_attachment_image_alt', $alt );
		update_post_meta( $attachment, '_waxsc_source_file', strtolower( $name ) );

		$replace = ! empty( $_POST['replace'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in check().

		// A real photo always replaces an illustration from the Cover Art Archive.
		if ( $cover && ( $replace || ! has_post_thumbnail( $release ) || \WaxStore\Catalog\Covers\Fetcher::is_illustration( $release ) ) ) {
			set_post_thumbnail( $release, $attachment );
			$role = 'cover';
		} else {
			Gallery::add( $release, $attachment, $cover ? 99 : $match['position'] );
			$role = 'gallery';
		}

		wp_send_json_success(
			array(
				'status'  => $role,
				'message' => sprintf(
					/* translators: %s: "Artist – Title". */
					'cover' === $role ? __( 'Cover of %s', 'wax-store-catalog' ) : __( 'Gallery of %s', 'wax-store-catalog' ),
					$label
				),
			)
		);
	}

	/**
	 * Whether a file with this name was already imported for the release.
	 *
	 * @param int    $release Release ID.
	 * @param string $name    File name.
	 */
	private static function already_imported( int $release, string $name ): bool {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $release,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_waxsc_source_file', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small attachment set per release.
				'meta_value'     => strtolower( $name ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- small attachment set per release.
			)
		);

		return (bool) $found;
	}

	/**
	 * Result message of a match.
	 *
	 * @param array $match Match result.
	 */
	private static function describe( array $match ): array {
		if ( 'found' === $match['status'] ) {
			return array(
				'status'  => 'found',
				'message' => Requests::release_label( $match['release'] ),
			);
		}

		return array(
			'status'  => $match['status'],
			'message' => 'ambiguous' === $match['status']
				/* translators: %s: key read in the file name. */
				? sprintf( __( 'Several releases match "%s": rename the file with the Discogs ID or the release slug.', 'wax-store-catalog' ), $match['key'] )
				/* translators: %s: key read in the file name. */
				: sprintf( __( 'No release matches "%s".', 'wax-store-catalog' ), $match['key'] ),
		);
	}
}
