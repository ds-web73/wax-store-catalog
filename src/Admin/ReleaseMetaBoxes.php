<?php
/**
 * Release edit screen: details, tracklist and copies meta boxes.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Barcode;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\Gallery;
use WaxStore\Catalog\Content\Grading;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Content\Taxonomies;
use WaxStore\Catalog\Content\Tracklist;

use const WaxStore\Catalog\PLUGIN_FILE;
use const WaxStore\Catalog\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Meta boxes.
 */
final class ReleaseMetaBoxes {

	const NONCE = 'waxsc_release_nonce';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_' . PostType::NAME, array( self::class, 'add' ) );
		add_action( 'save_post_' . PostType::NAME, array( self::class, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_block_editor' ) );
	}

	/**
	 * Registers the meta boxes.
	 */
	public static function add(): void {
		add_meta_box( 'waxsc_details', __( 'Release details', 'wax-store-catalog' ), array( self::class, 'render_details' ), PostType::NAME, 'normal', 'high' );
		add_meta_box( 'waxsc_tracklist', __( 'Tracklist', 'wax-store-catalog' ), array( self::class, 'render_tracklist' ), PostType::NAME, 'normal', 'default' );
		add_meta_box( 'waxsc_copies', __( 'Copies for sale', 'wax-store-catalog' ), array( self::class, 'render_copies' ), PostType::NAME, 'normal', 'high' );
		add_meta_box( 'waxsc_gallery', __( 'Other photos', 'wax-store-catalog' ), array( self::class, 'render_gallery' ), PostType::NAME, 'side', 'low' );
	}

	/**
	 * Assets, only on the release edit screen.
	 */
	public static function enqueue(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->base || PostType::NAME !== $screen->post_type ) {
			return;
		}

		$url = plugin_dir_url( PLUGIN_FILE ) . 'assets/';

		wp_enqueue_style( 'waxsc-admin', $url . 'admin.css', array(), VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'waxsc-gallery-admin', $url . 'gallery-admin.js', array( 'media-editor' ), VERSION, true );
		wp_add_inline_script(
			'waxsc-gallery-admin',
			'window.waxscGallery = ' . wp_json_encode(
				array(
					'title'  => __( 'Add photos to the gallery', 'wax-store-catalog' ),
					'button' => __( 'Add to the gallery', 'wax-store-catalog' ),
					'remove' => __( 'Remove this photo', 'wax-store-catalog' ),
				)
			) . ';',
			'before'
		);
		wp_enqueue_script( 'waxsc-admin', $url . 'admin.js', array(), VERSION, true );

		$taxonomy = get_taxonomy( Taxonomies::FORMAT );

		$config = array(
			'formatTaxonomy' => Taxonomies::FORMAT,
			'formatRestBase' => ( $taxonomy && $taxonomy->rest_base ) ? $taxonomy->rest_base : Taxonomies::FORMAT,
			'formatKinds'    => self::format_kinds(),
			'i18n'           => array(
				'barcodeValid'   => __( 'Valid barcode', 'wax-store-catalog' ),
				'barcodeInvalid' => __( 'Check digit or length looks wrong', 'wax-store-catalog' ),
			),
		);

		wp_add_inline_script( 'waxsc-admin', 'window.waxscAdmin = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Block editor: single-choice taxonomy panels become a select.
	 */
	public static function enqueue_block_editor(): void {
		$screen = get_current_screen();

		if ( ! $screen || PostType::NAME !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'waxsc-editor',
			plugin_dir_url( PLUGIN_FILE ) . 'assets/editor.js',
			array( 'wp-components', 'wp-data', 'wp-element', 'wp-hooks', 'wp-i18n' ),
			VERSION,
			true
		);

		$taxonomies = array();

		foreach ( Taxonomies::SINGLE_CHOICE as $slug ) {
			$taxonomy = get_taxonomy( $slug );

			if ( ! $taxonomy ) {
				continue;
			}

			$options = array();

			foreach ( Taxonomies::hierarchical_options( $slug ) as $term_id => $label ) {
				$options[] = array(
					'value' => (string) $term_id,
					'label' => $label,
				);
			}

			$taxonomies[ $slug ] = array(
				'restBase' => $taxonomy->rest_base ? $taxonomy->rest_base : $slug,
				'label'    => $taxonomy->labels->singular_name,
				'options'  => $options,
			);
		}

		wp_add_inline_script( 'waxsc-editor', 'window.waxscEditor = ' . wp_json_encode( array( 'taxonomies' => $taxonomies ) ) . ';', 'before' );
		wp_set_script_translations( 'waxsc-editor', 'wax-store-catalog' );
	}

	/**
	 * Maps each format term ID to the key of its top-level ancestor
	 * (vinyl, cd, merchandise…), so the screen can adapt to the format.
	 *
	 * @return array<int,string>
	 */
	private static function format_kinds(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Taxonomies::FORMAT,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$kinds = array();

		foreach ( $terms as $term ) {
			$ancestors = get_ancestors( $term->term_id, Taxonomies::FORMAT, 'taxonomy' );
			$root      = $ancestors ? (int) end( $ancestors ) : (int) $term->term_id;
			$key       = Taxonomies::get_key( $root );

			if ( '' !== $key ) {
				$kinds[ $term->term_id ] = $key;
			}
		}

		return $kinds;
	}

	/**
	 * Details meta box.
	 *
	 * @param \WP_Post $post Release.
	 */
	public static function render_details( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$year    = (int) get_post_meta( $post->ID, ReleaseMeta::YEAR, true );
		$catalog = (string) get_post_meta( $post->ID, ReleaseMeta::CATALOG_NUMBER, true );
		$barcode = (string) get_post_meta( $post->ID, ReleaseMeta::BARCODE, true );
		$details = (string) get_post_meta( $post->ID, ReleaseMeta::PRESSING, true );
		?>
		<div class="waxsc-details">
			<p class="waxsc-field">
				<label for="waxsc_year"><?php esc_html_e( 'Release year', 'wax-store-catalog' ); ?></label>
				<input type="number" id="waxsc_year" name="waxsc_year" min="1877" max="<?php echo esc_attr( (string) ( (int) gmdate( 'Y' ) + 1 ) ); ?>" value="<?php echo $year ? esc_attr( (string) $year ) : ''; ?>">
			</p>
			<p class="waxsc-field">
				<label for="waxsc_catalog_number"><?php esc_html_e( 'Catalog number', 'wax-store-catalog' ); ?></label>
				<input type="text" id="waxsc_catalog_number" name="waxsc_catalog_number" value="<?php echo esc_attr( $catalog ); ?>" placeholder="BST 84003">
			</p>
			<p class="waxsc-field">
				<label for="waxsc_barcode"><?php esc_html_e( 'Barcode (EAN / UPC)', 'wax-store-catalog' ); ?></label>
				<input type="text" id="waxsc_barcode" name="waxsc_barcode" inputmode="numeric" value="<?php echo esc_attr( $barcode ); ?>" aria-describedby="waxsc_barcode_status" data-waxsc-barcode>
				<span id="waxsc_barcode_status" class="waxsc-barcode-status" aria-live="polite">
					<?php
					if ( '' !== $barcode && ! Barcode::is_valid( $barcode ) ) {
						esc_html_e( 'Check digit or length looks wrong', 'wax-store-catalog' );
					}
					?>
				</span>
			</p>
			<p class="waxsc-field waxsc-field--wide">
				<label for="waxsc_pressing"><?php esc_html_e( 'Pressing details', 'wax-store-catalog' ); ?></label>
				<input type="text" id="waxsc_pressing" name="waxsc_pressing" value="<?php echo esc_attr( $details ); ?>" placeholder="<?php esc_attr_e( 'Transparent red, marbled black, 500 copies', 'wax-store-catalog' ); ?>">
			</p>
		</div>
		<?php
	}

	/**
	 * Gallery meta box: back, center label, insert… after the cover.
	 *
	 * @param \WP_Post $post Release.
	 */
	public static function render_gallery( \WP_Post $post ): void {
		$ids = Gallery::get( $post->ID );

		echo '<div data-waxsc-gallery>';
		echo '<ul class="waxsc-gallery-admin">';

		foreach ( $ids as $id ) {
			printf(
				'<li data-id="%1$d">%2$s<button type="button" class="button-link button-link-delete" data-waxsc-gallery-remove aria-label="%3$s">&times;</button></li>',
				(int) $id,
				wp_get_attachment_image( $id, 'thumbnail' ),
				esc_attr__( 'Remove this photo', 'wax-store-catalog' )
			);
		}

		echo '</ul>';
		printf( '<input type="hidden" name="waxsc_gallery" value="%s">', esc_attr( implode( ',', $ids ) ) );
		printf( '<button type="button" class="button" data-waxsc-gallery-add>%s</button>', esc_html__( 'Add photos', 'wax-store-catalog' ) );
		echo '<p class="description">' . esc_html__( 'Back cover, center label, insert… The front cover is the featured image.', 'wax-store-catalog' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Tracklist meta box.
	 *
	 * @param \WP_Post $post Release.
	 */
	public static function render_tracklist( \WP_Post $post ): void {
		echo '<div class="waxsc-repeater" data-waxsc-repeater>';
		echo '<ol class="waxsc-rows waxsc-rows--tracks" data-waxsc-rows>';

		foreach ( Tracklist::get( $post->ID ) as $index => $track ) {
			self::track_row( (string) $index, $track );
		}

		echo '</ol><template>';
		self::track_row( '__INDEX__', Tracklist::empty_row() );
		echo '</template>';

		printf(
			'<button type="button" class="button" data-waxsc-action="add">%s</button>',
			esc_html__( 'Add a track', 'wax-store-catalog' )
		);
		echo '</div>';
	}

	/**
	 * One track row.
	 *
	 * @param string $index Row index.
	 * @param array  $track Track data.
	 */
	private static function track_row( string $index, array $track ): void {
		$name = 'waxsc_tracklist[' . $index . ']';
		$id   = 'waxsc-track-' . $index;
		?>
		<li class="waxsc-row waxsc-row--track" data-waxsc-row>
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-position"><?php esc_html_e( 'Position', 'wax-store-catalog' ); ?></label>
			<input type="text" class="waxsc-track-position" id="<?php echo esc_attr( $id ); ?>-position" name="<?php echo esc_attr( $name ); ?>[position]" value="<?php echo esc_attr( $track['position'] ); ?>" placeholder="A1">
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-title"><?php esc_html_e( 'Title', 'wax-store-catalog' ); ?></label>
			<input type="text" class="waxsc-track-title" id="<?php echo esc_attr( $id ); ?>-title" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $track['title'] ); ?>" placeholder="<?php esc_attr_e( 'Title', 'wax-store-catalog' ); ?>">
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-duration"><?php esc_html_e( 'Duration', 'wax-store-catalog' ); ?></label>
			<input type="text" class="waxsc-track-duration" id="<?php echo esc_attr( $id ); ?>-duration" name="<?php echo esc_attr( $name ); ?>[duration]" value="<?php echo esc_attr( $track['duration'] ); ?>" placeholder="3:45" pattern="(\d{1,2}:)?\d{1,3}:[0-5]\d">
			<?php self::row_buttons(); ?>
		</li>
		<?php
	}

	/**
	 * Copies meta box.
	 *
	 * @param \WP_Post $post Release.
	 */
	public static function render_copies( \WP_Post $post ): void {
		echo '<p class="description">' . esc_html__( 'One row per physical copy. For new stock, a single row with a quantity is enough.', 'wax-store-catalog' ) . '</p>';
		echo '<div class="waxsc-repeater" data-waxsc-repeater>';
		echo '<ol class="waxsc-rows waxsc-rows--copies" data-waxsc-rows>';

		foreach ( Copies::get( $post->ID ) as $index => $copy ) {
			self::copy_row( (string) $index, $copy );
		}

		echo '</ol><template>';
		self::copy_row( '__INDEX__', Copies::empty_row() );
		echo '</template>';

		printf(
			'<button type="button" class="button" data-waxsc-action="add">%s</button>',
			esc_html__( 'Add a copy', 'wax-store-catalog' )
		);
		echo '</div>';
	}

	/**
	 * One copy row.
	 *
	 * @param string $index Row index.
	 * @param array  $copy  Copy data.
	 */
	private static function copy_row( string $index, array $copy ): void {
		$name     = 'waxsc_copies[' . $index . ']';
		$id       = 'waxsc-copy-' . $index;
		$statuses = array(
			ProviderInterface::IN_STOCK => __( 'In stock', 'wax-store-catalog' ),
			ProviderInterface::RESERVED => __( 'Reserved', 'wax-store-catalog' ),
			ProviderInterface::SOLD     => __( 'Sold', 'wax-store-catalog' ),
		);
		?>
		<li class="waxsc-row waxsc-row--copy" data-waxsc-row>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $copy['id'] ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[ext]" value="<?php echo esc_attr( $copy['ext'] ); ?>">
			<div class="waxsc-copy-fields">
				<?php
				self::grade_field( $name . '[media]', $id . '-media', __( 'Media grade', 'wax-store-catalog' ), $copy['media'], false );
				self::grade_field( $name . '[sleeve]', $id . '-sleeve', __( 'Sleeve grade', 'wax-store-catalog' ), $copy['sleeve'], true );
				?>
				<p class="waxsc-field waxsc-field--variant">
					<label for="<?php echo esc_attr( $id ); ?>-variant"><?php esc_html_e( 'Size / variant', 'wax-store-catalog' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-variant" name="<?php echo esc_attr( $name ); ?>[variant]" value="<?php echo esc_attr( $copy['variant'] ); ?>" placeholder="M">
				</p>
				<p class="waxsc-field waxsc-field--price">
					<label for="<?php echo esc_attr( $id ); ?>-price"><?php esc_html_e( 'Price', 'wax-store-catalog' ); ?></label>
					<input type="text" inputmode="decimal" id="<?php echo esc_attr( $id ); ?>-price" name="<?php echo esc_attr( $name ); ?>[price]" value="<?php echo esc_attr( $copy['price'] ); ?>">
				</p>
				<p class="waxsc-field waxsc-field--qty">
					<label for="<?php echo esc_attr( $id ); ?>-qty"><?php esc_html_e( 'Quantity', 'wax-store-catalog' ); ?></label>
					<input type="number" min="0" id="<?php echo esc_attr( $id ); ?>-qty" name="<?php echo esc_attr( $name ); ?>[qty]" value="<?php echo esc_attr( (string) $copy['qty'] ); ?>">
				</p>
				<p class="waxsc-field waxsc-field--status">
					<label for="<?php echo esc_attr( $id ); ?>-status"><?php esc_html_e( 'Status', 'wax-store-catalog' ); ?></label>
					<select id="<?php echo esc_attr( $id ); ?>-status" name="<?php echo esc_attr( $name ); ?>[status]">
						<?php foreach ( $statuses as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $copy['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="waxsc-field waxsc-field--location">
					<label for="<?php echo esc_attr( $id ); ?>-location"><?php esc_html_e( 'Location in the shop (internal)', 'wax-store-catalog' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-location" name="<?php echo esc_attr( $name ); ?>[location]" value="<?php echo esc_attr( $copy['location'] ); ?>" placeholder="<?php esc_attr_e( 'Jazz bin, row 3', 'wax-store-catalog' ); ?>">
				</p>
				<p class="waxsc-field waxsc-field--note">
					<label for="<?php echo esc_attr( $id ); ?>-note"><?php esc_html_e( 'Notes (pressing details, defects…)', 'wax-store-catalog' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-note" name="<?php echo esc_attr( $name ); ?>[note]" value="<?php echo esc_attr( $copy['note'] ); ?>">
				</p>
			</div>
			<?php self::row_buttons(); ?>
		</li>
		<?php
	}

	/**
	 * Grade field: select in Goldmine mode, free text otherwise.
	 *
	 * @param string $name   Field name.
	 * @param string $id     Field ID.
	 * @param string $label  Label.
	 * @param string $value  Current value.
	 * @param bool   $sleeve Sleeve scale.
	 */
	private static function grade_field( string $name, string $id, string $label, string $value, bool $sleeve ): void {
		echo '<p class="waxsc-field waxsc-field--grade">';
		printf( '<label for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $label ) );

		if ( Grading::MODE_FREE === Grading::mode() ) {
			printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
		} else {
			$grades = $sleeve ? Grading::sleeve_grades() : Grading::media_grades();

			printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
			echo '<option value="">—</option>';

			foreach ( $grades as $code => $grade_label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $code ), selected( $value, $code, false ), esc_html( $grade_label ) );
			}

			echo '</select>';
		}

		echo '</p>';
	}

	/**
	 * Move up, move down and remove buttons.
	 */
	private static function row_buttons(): void {
		?>
		<span class="waxsc-row-actions">
			<button type="button" class="button-link" data-waxsc-action="up" aria-label="<?php esc_attr_e( 'Move up', 'wax-store-catalog' ); ?>">&uarr;</button>
			<button type="button" class="button-link" data-waxsc-action="down" aria-label="<?php esc_attr_e( 'Move down', 'wax-store-catalog' ); ?>">&darr;</button>
			<button type="button" class="button-link button-link-delete" data-waxsc-action="remove" aria-label="<?php esc_attr_e( 'Remove', 'wax-store-catalog' ); ?>">&times;</button>
		</span>
		<?php
	}

	/**
	 * Saves the meta boxes. REST saves from the block editor carry no nonce and are skipped:
	 * the meta box form is submitted separately right after.
	 *
	 * @param int $post_id Release ID.
	 */
	public static function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$year = ReleaseMeta::sanitize_year( isset( $_POST['waxsc_year'] ) ? sanitize_text_field( wp_unslash( $_POST['waxsc_year'] ) ) : '' );
		self::update_or_delete( $post_id, ReleaseMeta::YEAR, $year ? $year : '' );

		$catalog = ReleaseMeta::sanitize_catalog_number( isset( $_POST['waxsc_catalog_number'] ) ? sanitize_text_field( wp_unslash( $_POST['waxsc_catalog_number'] ) ) : '' );
		self::update_or_delete( $post_id, ReleaseMeta::CATALOG_NUMBER, $catalog );

		$details = ReleaseMeta::sanitize_pressing( isset( $_POST['waxsc_pressing'] ) ? sanitize_text_field( wp_unslash( $_POST['waxsc_pressing'] ) ) : '' );
		self::update_or_delete( $post_id, ReleaseMeta::PRESSING, $details );

		$barcode = Barcode::normalize( isset( $_POST['waxsc_barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['waxsc_barcode'] ) ) : '' );
		self::update_or_delete( $post_id, ReleaseMeta::BARCODE, $barcode );

		// Arrays are sanitized field by field in Tracklist::sanitize() and Copies::sanitize().
		if ( isset( $_POST['waxsc_gallery'] ) ) {
			$gallery = Gallery::sanitize( explode( ',', sanitize_text_field( wp_unslash( $_POST['waxsc_gallery'] ) ) ) );
			self::update_or_delete( $post_id, Gallery::META, $gallery );
		}

		$tracks = isset( $_POST['waxsc_tracklist'] ) && is_array( $_POST['waxsc_tracklist'] ) ? Tracklist::sanitize( wp_unslash( $_POST['waxsc_tracklist'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::update_or_delete( $post_id, ReleaseMeta::TRACKLIST, $tracks );

		$copies = isset( $_POST['waxsc_copies'] ) && is_array( $_POST['waxsc_copies'] ) ? Copies::sanitize( wp_unslash( $_POST['waxsc_copies'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::update_or_delete( $post_id, ReleaseMeta::COPIES, $copies );
	}

	/**
	 * Updates a meta, or deletes it when empty.
	 *
	 * @param int    $post_id Release ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 */
	private static function update_or_delete( int $post_id, string $key, $value ): void {
		if ( '' === $value || array() === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}

		update_post_meta( $post_id, $key, $value );
	}
}
