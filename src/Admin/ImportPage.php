<?php
/**
 * Record shop > Tools > CSV import.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Import\Fields;
use WaxStore\Catalog\Import\Importer;

use const WaxStore\Catalog\PLUGIN_FILE;
use const WaxStore\Catalog\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Three steps: upload, column mapping, batched import with progress.
 */
final class ImportPage {

	const SLUG       = 'waxsc-import';
	const CAPABILITY = 'edit_others_posts';
	const NONCE      = 'waxsc_import';
	const UPLOAD_DIR = 'wax-store-catalog';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_waxsc_import_upload', array( self::class, 'handle_upload' ) );
		add_action( 'admin_post_waxsc_import_start', array( self::class, 'handle_start' ) );
		add_action( 'admin_post_waxsc_import_reset', array( self::class, 'handle_reset' ) );
		add_action( 'admin_post_waxsc_discogs_ids', array( self::class, 'download_ids' ) );
		add_action( 'wp_ajax_waxsc_import_batch', array( self::class, 'ajax_batch' ) );
	}

	/**
	 * Submenu under Catalog.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostType::NAME,
			__( 'Import a catalog', 'wax-store-catalog' ),
			__( 'Import', 'wax-store-catalog' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( $hook ) {
			add_action( 'admin_print_scripts-' . $hook, array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * Progress script, only while an import runs.
	 */
	public static function enqueue(): void {
		$state = Importer::get_state();

		if ( 'running' !== ( $state['stage'] ?? '' ) ) {
			return;
		}

		wp_enqueue_script( 'waxsc-import', plugin_dir_url( PLUGIN_FILE ) . 'assets/import.js', array(), VERSION, true );

		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => array(
				/* translators: 1: rows done, 2: total rows. */
				'progress' => __( '%1$d of %2$d rows imported', 'wax-store-catalog' ),
				'error'    => __( 'The import was interrupted. Reload the page to resume where it stopped.', 'wax-store-catalog' ),
			),
		);

		wp_add_inline_script( 'waxsc-import', 'window.waxscImport = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Page URL, with an optional error code.
	 *
	 * @param string $error Error code.
	 */
	private static function url( string $error = '' ): string {
		$url = Menu::tool_url( 'import' );

		return '' !== $error ? add_query_arg( 'waxsc_error', rawurlencode( $error ), $url ) : $url;
	}

	/**
	 * Common checks for form handlers.
	 *
	 * @param string $action Nonce action suffix.
	 */
	private static function verify( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import releases.', 'wax-store-catalog' ), 403 );
		}

		check_admin_referer( self::NONCE . '_' . $action );
	}

	/**
	 * Step 1: stores the CSV in a protected uploads folder and analyzes it.
	 */
	public static function handle_upload(): void {
		check_admin_referer( self::NONCE . '_upload' );
		self::verify( 'upload' );

		if ( empty( $_FILES['waxsc_file']['name'] ) ) {
			wp_safe_redirect( self::url( 'no_file' ) );
			exit;
		}

		self::protect_upload_dir();

		$filter = static function ( array $dirs ): array {
			$dirs['subdir'] = '/' . self::UPLOAD_DIR;
			$dirs['path']   = $dirs['basedir'] . '/' . self::UPLOAD_DIR;
			$dirs['url']    = $dirs['baseurl'] . '/' . self::UPLOAD_DIR;
			return $dirs;
		};

		add_filter( 'upload_dir', $filter );

		$result = wp_handle_upload(
			$_FILES['waxsc_file'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by wp_handle_upload().
			array(
				'test_form'                => false,
				'mimes'                    => array(
					'csv' => 'text/csv',
					'txt' => 'text/plain',
				),
				'unique_filename_callback' => static function ( $dir, $name, $ext ) {
					return 'import-' . strtolower( wp_generate_password( 16, false, false ) ) . $ext;
				},
			)
		);

		remove_filter( 'upload_dir', $filter );

		if ( isset( $result['error'] ) ) {
			wp_safe_redirect( self::url( 'upload' ) );
			exit;
		}

		Importer::reset();
		$state = Importer::analyze( $result['file'] );

		if ( is_wp_error( $state ) ) {
			wp_delete_file( $result['file'] );
			wp_safe_redirect( self::url( $state->get_error_code() ) );
			exit;
		}

		Importer::save_state( $state );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Step 2: saves the mapping and options, then starts the import.
	 */
	public static function handle_start(): void {
		check_admin_referer( self::NONCE . '_start' );
		self::verify( 'start' );

		$state = Importer::get_state();

		if ( 'mapping' !== ( $state['stage'] ?? '' ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		$columns = count( $state['header'] );
		$posted  = isset( $_POST['waxsc_mapping'] ) && is_array( $_POST['waxsc_mapping'] ) ? map_deep( wp_unslash( $_POST['waxsc_mapping'] ), 'intval' ) : array();
		$mapping = array();

		foreach ( array_keys( Fields::targets() ) as $target ) {
			$index              = isset( $posted[ $target ] ) ? (int) $posted[ $target ] : -1;
			$mapping[ $target ] = ( $index >= 0 && $index < $columns ) ? $index : -1;
		}

		if ( $mapping['title'] < 0 ) {
			wp_safe_redirect( self::url( 'no_title' ) );
			exit;
		}

		$condition = isset( $_POST['waxsc_condition'] ) ? sanitize_key( wp_unslash( $_POST['waxsc_condition'] ) ) : '';

		$state['mapping']   = $mapping;
		$state['publish']   = ! empty( $_POST['waxsc_publish'] );
		$state['missing']   = ! empty( $_POST['waxsc_missing'] ) && $mapping['listing_id'] >= 0;
		$state['condition'] = in_array( $condition, array( 'used', 'new' ), true ) ? $condition : '';
		$state['stage']     = 'running';

		Importer::save_state( $state );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * CSV of the Discogs release IDs of the catalog, for the discogs_enrich tool.
	 */
	public static function download_ids(): void {
		check_admin_referer( 'waxsc_discogs_ids' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import releases.', 'wax-store-catalog' ), 403 );
		}

		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off export.
			$wpdb->prepare(
				"SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status <> 'trash' AND m.meta_value > 0",
				\WaxStore\Catalog\Content\ReleaseMeta::DISCOGS_ID,
				PostType::NAME
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="discogs-ids-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo "release_id\n" . implode( "\n", array_map( 'absint', (array) $ids ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integers.
		exit;
	}

	/**
	 * Cancels or clears the import.
	 */
	public static function handle_reset(): void {
		self::verify( 'reset' );
		Importer::reset();
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Step 3: one batch per AJAX call.
	 */
	public static function ajax_batch(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}

		$state = Importer::run_batch();

		wp_send_json_success(
			array(
				'stage' => $state['stage'] ?? '',
				'line'  => (int) ( $state['line'] ?? 0 ),
				'total' => (int) ( $state['total'] ?? 0 ),
			)
		);
	}

	/**
	 * Blocks direct web access to uploaded files (Apache); files also get
	 * random names and are deleted as soon as the import ends.
	 */
	private static function protect_upload_dir(): void {
		global $wp_filesystem;

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! WP_Filesystem() ) {
			return;
		}

		if ( ! $wp_filesystem->exists( $dir . '/index.php' ) ) {
			$wp_filesystem->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}

		if ( ! $wp_filesystem->exists( $dir . '/.htaccess' ) ) {
			$wp_filesystem->put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", FS_CHMOD_FILE );
		}
	}

	/**
	 * Screen.
	 */
	public static function render(): void {
		$state = Importer::get_state();
		$stage = $state['stage'] ?? '';

		echo '<div class="wrap"><h1>' . esc_html__( 'Import a catalog', 'wax-store-catalog' ) . '</h1>';

		self::render_error();

		if ( 'mapping' === $stage ) {
			self::render_mapping( $state );
		} elseif ( 'running' === $stage ) {
			self::render_running( $state );
		} elseif ( 'done' === $stage ) {
			self::render_report( $state );
		} else {
			self::render_upload();
		}

		echo '</div>';
	}

	/**
	 * Error notice from the redirect.
	 */
	private static function render_error(): void {
		$code = isset( $_GET['waxsc_error'] ) ? sanitize_key( wp_unslash( $_GET['waxsc_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		$messages = array(
			'no_file'         => __( 'Please choose a CSV file.', 'wax-store-catalog' ),
			'upload'          => __( 'The file could not be uploaded. Check that it is a .csv file.', 'wax-store-catalog' ),
			'waxsc_no_header' => __( 'No column headers were found on the first line.', 'wax-store-catalog' ),
			'waxsc_empty'     => __( 'The file contains no rows to import.', 'wax-store-catalog' ),
			'no_title'        => __( 'Choose the column holding the title: it is required.', 'wax-store-catalog' ),
		);

		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $messages[ $code ] ) . '</p></div>';
		}
	}

	/**
	 * Step 1 form.
	 */
	private static function render_upload(): void {
		?>
		<p><?php esc_html_e( 'Import releases and copies from a CSV file: the inventory export of your Discogs seller account, or your own spreadsheet saved as CSV. Delimiter and encoding are detected automatically.', 'wax-store-catalog' ); ?></p>
		<p><?php esc_html_e( 'Importing the same file again later updates prices, grades and statuses without creating duplicates. Release details you edited by hand are never overwritten.', 'wax-store-catalog' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="waxsc_import_upload">
			<?php wp_nonce_field( self::NONCE . '_upload' ); ?>
			<p>
				<label for="waxsc_file"><?php esc_html_e( 'CSV file', 'wax-store-catalog' ); ?></label><br>
				<input type="file" id="waxsc_file" name="waxsc_file" accept=".csv,text/csv" required>
			</p>
			<?php submit_button( __( 'Upload and continue', 'wax-store-catalog' ) ); ?>
		</form>
		<h2><?php esc_html_e( 'Complete releases with Discogs data', 'wax-store-catalog' ); ?></h2>
		<p><?php esc_html_e( 'Discogs publishes its whole database every month under the CC0 licence (public domain). The discogs_enrich tool, provided with this plugin, reads this file on your computer and keeps your releases only: genres, styles, year, country, barcode and tracklist. Images are not part of this open data.', 'wax-store-catalog' ); ?></p>
		<ol>
			<li>
				<?php esc_html_e( 'Download the list of your releases and the tool:', 'wax-store-catalog' ); ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=waxsc_discogs_ids' ), 'waxsc_discogs_ids' ) ); ?>"><?php esc_html_e( 'Discogs IDs of the catalog', 'wax-store-catalog' ); ?></a>,
				<a href="<?php echo esc_url( plugin_dir_url( PLUGIN_FILE ) . 'tools/discogs_enrich.py' ); ?>" download><?php esc_html_e( 'discogs_enrich tool', 'wax-store-catalog' ); ?></a>
				(<a href="<?php echo esc_url( plugin_dir_url( PLUGIN_FILE ) . ( 0 === strpos( determine_locale(), 'fr' ) ? 'tools/LISEZMOI.md' : 'tools/README.md' ) ); ?>"><?php esc_html_e( 'instructions', 'wax-store-catalog' ); ?></a>).
			</li>
			<li><?php esc_html_e( 'On data.discogs.com, download the latest releases file (discogs_YYYYMMDD_releases.xml.gz, several GB, no need to unzip it).', 'wax-store-catalog' ); ?></li>
			<li><?php esc_html_e( 'In a terminal: python3 discogs_enrich.py discogs-ids-….csv discogs_…_releases.xml.gz (Python 3 is already installed on Linux and macOS; on Windows, install it from python.org and type py instead of python3).', 'wax-store-catalog' ); ?></li>
			<li><?php esc_html_e( 'Upload the discogs-enrichment.csv file it produces with the form above: only empty fields of existing releases are filled.', 'wax-store-catalog' ); ?></li>
		</ol>
		<p class="description"><?php esc_html_e( 'With WP-CLI on your server, one command does it all: wp waxsc enrich /path/to/discogs_YYYYMMDD_releases.xml.gz', 'wax-store-catalog' ); ?></p>
		<?php
	}

	/**
	 * Step 2 form.
	 *
	 * @param array $state Import state.
	 */
	private static function render_mapping( array $state ): void {
		?>
		<p>
			<?php
			/* translators: %d: number of rows. */
			echo esc_html( sprintf( _n( '%d row found. Check which column feeds each field.', '%d rows found. Check which column feeds each field.', (int) $state['total'], 'wax-store-catalog' ), (int) $state['total'] ) );
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="waxsc_import_start">
			<?php wp_nonce_field( self::NONCE . '_start' ); ?>
			<table class="widefat striped" style="max-width:60rem">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Field', 'wax-store-catalog' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Column in your file', 'wax-store-catalog' ); ?></th>
						<th scope="col"><?php esc_html_e( 'First row value', 'wax-store-catalog' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( Fields::targets() as $target => $label ) : ?>
					<?php $selected = (int) ( $state['mapping'][ $target ] ?? -1 ); ?>
					<tr>
						<th scope="row"><label for="waxsc-map-<?php echo esc_attr( $target ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<select id="waxsc-map-<?php echo esc_attr( $target ); ?>" name="waxsc_mapping[<?php echo esc_attr( $target ); ?>]">
								<option value="-1"><?php esc_html_e( '— Ignore —', 'wax-store-catalog' ); ?></option>
								<?php foreach ( $state['header'] as $index => $column ) : ?>
									<option value="<?php echo esc_attr( (string) $index ); ?>" <?php selected( $selected, $index ); ?>><?php echo esc_html( $column ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<?php echo $selected >= 0 ? esc_html( wp_trim_words( (string) ( $state['sample'][ $selected ] ?? '' ), 8 ) ) : ''; ?>
							<?php if ( 'condition' === $target ) : ?>
								<p>
									<label for="waxsc_condition"><?php esc_html_e( 'When empty or unrecognized:', 'wax-store-catalog' ); ?></label>
									<select id="waxsc_condition" name="waxsc_condition">
										<option value="used" <?php selected( $state['condition'], 'used' ); ?>><?php esc_html_e( 'Used', 'wax-store-catalog' ); ?></option>
										<option value="new" <?php selected( $state['condition'], 'new' ); ?>><?php esc_html_e( 'New', 'wax-store-catalog' ); ?></option>
										<option value="" <?php selected( $state['condition'], '' ); ?>><?php esc_html_e( 'Do not set', 'wax-store-catalog' ); ?></option>
									</select>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Options', 'wax-store-catalog' ); ?></h2>
			<p>
				<label>
					<input type="checkbox" name="waxsc_publish" value="1" <?php checked( ! empty( $state['publish'] ) ); ?>>
					<?php esc_html_e( 'Publish new releases immediately (otherwise they are saved as drafts for review)', 'wax-store-catalog' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="waxsc_missing" value="1">
					<?php esc_html_e( 'Mark as sold the Discogs copies that are not in this file (they were sold on Discogs)', 'wax-store-catalog' ); ?>
				</label>
				<br><span class="description"><?php esc_html_e( 'Only with a full inventory export from Discogs, never with a partial file: every missing listing would be marked sold. Needs the listing ID column.', 'wax-store-catalog' ); ?></span>
			</p>
			<p class="description"><?php esc_html_e( 'Tip: a column with a unique ID per copy (the Discogs listing ID, or your own SKU) makes later re-imports fully reliable.', 'wax-store-catalog' ); ?></p>

			<p class="submit">
				<?php submit_button( __( 'Start import', 'wax-store-catalog' ), 'primary', 'submit', false ); ?>
			</p>
		</form>
		<?php
		self::render_reset_button( __( 'Cancel', 'wax-store-catalog' ) );
	}

	/**
	 * Step 3: progress.
	 *
	 * @param array $state Import state.
	 */
	private static function render_running( array $state ): void {
		$line  = (int) $state['line'];
		$total = (int) $state['total'];
		?>
		<p><?php esc_html_e( 'Import in progress. Keep this page open; if it closes, the import resumes where it stopped when you come back.', 'wax-store-catalog' ); ?></p>
		<progress id="waxsc-import-progress" max="<?php echo esc_attr( (string) $total ); ?>" value="<?php echo esc_attr( (string) $line ); ?>" style="width:100%;max-width:40rem;height:1.5rem"></progress>
		<p id="waxsc-import-status" aria-live="polite">
			<?php
			/* translators: 1: rows done, 2: total rows. */
			echo esc_html( sprintf( __( '%1$d of %2$d rows imported', 'wax-store-catalog' ), $line, $total ) );
			?>
		</p>
		<noscript><p><?php esc_html_e( 'JavaScript is required to run the import.', 'wax-store-catalog' ); ?></p></noscript>
		<?php
		self::render_reset_button( __( 'Stop the import', 'wax-store-catalog' ) );
	}

	/**
	 * Final report.
	 *
	 * @param array $state Import state.
	 */
	private static function render_report( array $state ): void {
		$report = $state['report'];
		?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Import finished.', 'wax-store-catalog' ); ?></p></div>
		<ul class="ul-disc">
			<?php /* translators: %d: count. */ ?>
			<li><?php echo esc_html( sprintf( __( 'Releases created: %d', 'wax-store-catalog' ), (int) $report['releases_created'] ) ); ?></li>
			<?php /* translators: %d: count. */ ?>
			<li><?php echo esc_html( sprintf( __( 'Copies created: %d', 'wax-store-catalog' ), (int) $report['copies_created'] ) ); ?></li>
			<?php /* translators: %d: count. */ ?>
			<li><?php echo esc_html( sprintf( __( 'Copies updated: %d', 'wax-store-catalog' ), (int) $report['copies_updated'] ) ); ?></li>
			<?php /* translators: %d: count. */ ?>
			<li><?php echo esc_html( sprintf( __( 'Rows skipped: %d', 'wax-store-catalog' ), (int) $report['skipped'] ) ); ?></li>
			<?php if ( ! empty( $report['enriched'] ) ) : ?>
				<?php /* translators: %d: count. */ ?>
				<li><?php echo esc_html( sprintf( __( 'Releases completed with new data: %d', 'wax-store-catalog' ), (int) $report['enriched'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( ! empty( $report['marked_sold'] ) ) : ?>
				<?php /* translators: %d: count. */ ?>
				<li><?php echo esc_html( sprintf( __( 'Copies sold on Discogs, marked sold: %d', 'wax-store-catalog' ), (int) $report['marked_sold'] ) ); ?></li>
			<?php endif; ?>
		</ul>
		<?php if ( ! empty( $report['unknown_formats'] ) ) : ?>
			<h2><?php esc_html_e( 'Unrecognized formats', 'wax-store-catalog' ); ?></h2>
			<p><?php esc_html_e( 'These releases were imported without a format. Set it with bulk edit, or tell us the wording so it can be recognized.', 'wax-store-catalog' ); ?></p>
			<ul class="ul-disc">
				<?php foreach ( $report['unknown_formats'] as $format ) : ?>
					<li><code><?php echo esc_html( $format ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $report['errors'] ) : ?>
			<h2><?php esc_html_e( 'Skipped rows', 'wax-store-catalog' ); ?></h2>
			<ul class="ul-disc">
				<?php foreach ( $report['errors'] as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PostType::NAME ) ); ?>"><?php esc_html_e( 'View the catalog', 'wax-store-catalog' ); ?></a>
		</p>
		<?php
		self::render_reset_button( __( 'New import', 'wax-store-catalog' ) );
	}

	/**
	 * Reset form.
	 *
	 * @param string $label Button label.
	 */
	private static function render_reset_button( string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="waxsc_import_reset">
			<?php wp_nonce_field( self::NONCE . '_reset' ); ?>
			<?php submit_button( $label, 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
