<?php
/**
 * Record shop > Tools > Labels: printable A4 sheets of price stickers with a QR code.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\Taxonomies;

use const WaxStore\Catalog\PLUGIN_FILE;
use const WaxStore\Catalog\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * One label per copy in stock (several for a quantity). QR codes are drawn
 * in the browser by a bundled library: no external service.
 */
final class LabelsPage {

	const SLUG = 'waxsc-labels';

	/**
	 * Common A4 sticker sheets, sizes in millimetres.
	 */
	public static function formats(): array {
		return array(
			'small'  => array(
				'label' => __( 'Small: 24 per sheet, 70 × 37 mm (3 × 8)', 'wax-store-catalog' ),
				'cols'  => 3,
				'rows'  => 8,
				'w'     => 70,
				'h'     => 37.125,
				'top'   => 0,
				'left'  => 0,
				'gap'   => 0,
			),
			'medium' => array(
				'label' => __( 'Medium: 14 per sheet, 99.1 × 38.1 mm (2 × 7)', 'wax-store-catalog' ),
				'cols'  => 2,
				'rows'  => 7,
				'w'     => 99.1,
				'h'     => 38.1,
				'top'   => 15.15,
				'left'  => 4.65,
				'gap'   => 2.5,
			),
			'large'  => array(
				'label' => __( 'Large: 8 per sheet, 99.1 × 67.7 mm (2 × 4)', 'wax-store-catalog' ),
				'cols'  => 2,
				'rows'  => 4,
				'w'     => 99.1,
				'h'     => 67.7,
				'top'   => 13.1,
				'left'  => 4.65,
				'gap'   => 2.5,
			),
		);
	}

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_waxsc_labels', array( self::class, 'print_sheet' ) );
		add_filter( 'bulk_actions-edit-' . PostType::NAME, array( self::class, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-' . PostType::NAME, array( self::class, 'handle_bulk' ), 10, 3 );
	}

	/**
	 * Submenu.
	 */
	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostType::NAME,
			__( 'Labels', 'wax-store-catalog' ),
			__( 'Labels', 'wax-store-catalog' ),
			'edit_posts',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * "Print labels" in the bulk actions of the catalog list.
	 *
	 * @param array $actions Actions.
	 */
	public static function bulk_action( array $actions ): array {
		$actions['waxsc_labels'] = __( 'Print labels', 'wax-store-catalog' );
		return $actions;
	}

	/**
	 * Sends the selection to the labels screen.
	 *
	 * @param string $redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Release IDs.
	 */
	public static function handle_bulk( $redirect, $action, $ids ): string {
		if ( 'waxsc_labels' !== $action ) {
			return (string) $redirect;
		}

		return Menu::tool_url( 'labels', array( 'ids' => implode( ',', array_map( 'absint', (array) $ids ) ) ) );
	}

	/**
	 * Screen: selection and format.
	 */
	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- selection form, read only.
		$ids = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Labels', 'wax-store-catalog' ); ?></h1>
			<p><?php esc_html_e( 'Price stickers for the sleeves: artist, title, format, grading, price and a QR code to the release page. One label per copy in stock.', 'wax-store-catalog' ); ?></p>
			<p class="description"><?php esc_html_e( 'Tip: in Catalog > All releases, tick releases and choose "Print labels" in the bulk actions.', 'wax-store-catalog' ); ?></p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank">
				<input type="hidden" name="action" value="waxsc_labels">
				<?php wp_nonce_field( 'waxsc_labels', '_wpnonce', false ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php if ( '' !== $ids ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Selection', 'wax-store-catalog' ); ?></th>
								<td>
									<?php
									/* translators: %d: number of releases. */
									echo esc_html( sprintf( _n( '%d release selected in the catalog', '%d releases selected in the catalog', count( array_filter( explode( ',', $ids ) ) ), 'wax-store-catalog' ), count( array_filter( explode( ',', $ids ) ) ) ) );
									?>
									<input type="hidden" name="ids" value="<?php echo esc_attr( $ids ); ?>">
								</td>
							</tr>
						<?php else : ?>
							<tr>
								<th scope="row"><label for="waxsc-labels-since"><?php esc_html_e( 'Releases added since', 'wax-store-catalog' ); ?></label></th>
								<td><input type="date" id="waxsc-labels-since" name="since" value="<?php echo esc_attr( wp_date( 'Y-m-d', time() - WEEK_IN_SECONDS ) ); ?>"></td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><label for="waxsc-labels-location"><?php esc_html_e( 'Location contains', 'wax-store-catalog' ); ?></label></th>
							<td>
								<input type="text" id="waxsc-labels-location" name="location" class="regular-text" aria-describedby="waxsc-labels-location-desc">
								<p class="description" id="waxsc-labels-location-desc"><?php esc_html_e( 'Optional, e.g. "Jazz" to print one bin only.', 'wax-store-catalog' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="waxsc-labels-format"><?php esc_html_e( 'Sheet format', 'wax-store-catalog' ); ?></label></th>
							<td>
								<select id="waxsc-labels-format" name="format">
									<?php foreach ( self::formats() as $key => $format ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $format['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="waxsc-labels-skip"><?php esc_html_e( 'Labels already used on the sheet', 'wax-store-catalog' ); ?></label></th>
							<td>
								<input type="number" id="waxsc-labels-skip" name="skip" value="0" min="0" max="23" class="small-text" aria-describedby="waxsc-labels-skip-desc">
								<p class="description" id="waxsc-labels-skip-desc"><?php esc_html_e( 'To reuse a partly used sheet: printing starts after this number of labels.', 'wax-store-catalog' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Show the sheet to print', 'wax-store-catalog' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Copies to label.
	 *
	 * @param int[]  $ids      Release IDs (empty: use $since).
	 * @param string $since    Y-m-d.
	 * @param string $location Location filter.
	 */
	public static function labels( array $ids, string $since, string $location ): array {
		$args = array(
			'post_type'      => PostType::NAME,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $ids ) {
			$args['post__in'] = $ids;
		} elseif ( '' !== $since ) {
			$args['date_query'] = array(
				array(
					'after'     => $since,
					'inclusive' => true,
				),
			);
		}

		$labels = array();

		foreach ( get_posts( $args ) as $release ) {
			$release = (int) $release;
			$artists = get_the_terms( $release, Taxonomies::ARTIST );
			$formats = get_the_terms( $release, Taxonomies::FORMAT );
			$year    = (int) get_post_meta( $release, '_waxsc_year', true );

			foreach ( Copies::get( $release ) as $copy ) {
				if ( ProviderInterface::IN_STOCK !== $copy['status'] || $copy['qty'] < 1 ) {
					continue;
				}

				if ( '' !== $location && false === stripos( $copy['location'], $location ) ) {
					continue;
				}

				$label = array(
					'artist' => is_array( $artists ) && $artists ? $artists[0]->name : '',
					'title'  => html_entity_decode( get_the_title( $release ), ENT_QUOTES, 'UTF-8' ),
					'format' => trim( ( is_array( $formats ) && $formats ? $formats[0]->name : '' ) . ( $year ? ', ' . $year : '' ), ', ' ),
					'grades' => trim( implode( ' / ', array_filter( array( $copy['media'], $copy['sleeve'] ) ) ) . ( '' !== $copy['variant'] ? ' ' . $copy['variant'] : '' ) ),
					'price'  => '' !== $copy['price'] ? Copies::format_price( $copy['price'] ) : '',
					'url'    => (string) get_permalink( $release ),
				);

				for ( $i = 0; $i < min( 50, $copy['qty'] ); $i++ ) {
					$labels[] = $label;
				}
			}
		}

		return $labels;
	}

	/**
	 * Standalone print page.
	 */
	public static function print_sheet(): void {
		check_admin_referer( 'waxsc_labels' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to print labels.', 'wax-store-catalog' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above.
		$formats  = self::formats();
		$key      = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'small';
		$format   = $formats[ $key ] ?? $formats['small'];
		$ids      = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array();
		$since    = isset( $_GET['since'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['since'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['since'] ) ) : '';
		$location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		$skip     = isset( $_GET['skip'] ) ? min( absint( $_GET['skip'] ), $format['cols'] * $format['rows'] - 1 ) : 0;
		// phpcs:enable

		$labels   = array_merge( array_fill( 0, $skip, null ), self::labels( $ids, $since, $location ) );
		$per_page = $format['cols'] * $format['rows'];
		$pages    = array_chunk( $labels, $per_page );
		$url      = plugin_dir_url( PLUGIN_FILE ) . 'assets/';
		$count    = count( $labels ) - $skip;

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<title><?php esc_html_e( 'Labels', 'wax-store-catalog' ); ?></title>
<style>
@page { size: A4; margin: 0; }
* { box-sizing: border-box; }
body { margin: 0; font-family: "Arial Narrow", Arial, sans-serif; color: #000; background: #ddd; }
.toolbar { padding: 12px 16px; background: #1e2433; color: #fff; font: 14px/1.4 system-ui, sans-serif; display: flex; gap: 16px; align-items: center; }
.toolbar button { font: inherit; padding: 6px 14px; cursor: pointer; }
.sheet { width: 210mm; height: 297mm; margin: 10mm auto; background: #fff; padding: <?php echo esc_attr( $format['top'] . 'mm 0 0 ' . $format['left'] . 'mm' ); ?>; display: grid; grid-template-columns: repeat(<?php echo (int) $format['cols']; ?>, <?php echo esc_attr( $format['w'] . 'mm' ); ?>); grid-auto-rows: <?php echo esc_attr( $format['h'] . 'mm' ); ?>; column-gap: <?php echo esc_attr( $format['gap'] . 'mm' ); ?>; page-break-after: always; overflow: hidden; }
.label { display: flex; gap: 2.5mm; align-items: center; padding: 2.5mm 3mm; overflow: hidden; }
.qr { flex: none; width: <?php echo esc_attr( 'large' === $key ? '30mm' : '22mm' ); ?>; }
.qr svg { display: block; width: 100%; height: auto; }
.text { min-width: 0; line-height: 1.15; }
.artist { font-weight: 700; font-size: <?php echo 'large' === $key ? '13pt' : '10pt'; ?>; }
.title { font-size: <?php echo 'large' === $key ? '11pt' : '8.5pt'; ?>; }
.meta { font-size: 7.5pt; margin-top: 1mm; }
.price { font-weight: 700; font-size: <?php echo 'large' === $key ? '18pt' : '13pt'; ?>; margin-top: 1mm; }
.artist, .title { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
@media print { body { background: none; } .toolbar { display: none; } .sheet { margin: 0; } }
</style>
</head>
<body>
<div class="toolbar">
	<span>
		<?php
		/* translators: 1: number of labels, 2: number of sheets. */
		echo esc_html( sprintf( __( '%1$d labels on %2$d sheet(s).', 'wax-store-catalog' ), $count, count( $pages ) ) );
		?>
		<?php esc_html_e( 'Print at 100% (no "fit to page"), without margins.', 'wax-store-catalog' ); ?>
	</span>
	<button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'wax-store-catalog' ); ?></button>
</div>
<?php if ( 0 === $count ) : ?>
	<p style="padding:16px"><?php esc_html_e( 'No copy in stock matches this selection.', 'wax-store-catalog' ); ?></p>
<?php endif; ?>
<?php foreach ( $pages as $page ) : ?>
<div class="sheet">
	<?php foreach ( $page as $label ) : ?>
		<?php if ( null === $label ) : ?>
			<div class="label"></div>
		<?php else : ?>
			<div class="label">
				<div class="qr" data-qr="<?php echo esc_attr( $label['url'] ); ?>"></div>
				<div class="text">
					<div class="artist"><?php echo esc_html( $label['artist'] ); ?></div>
					<div class="title"><?php echo esc_html( $label['title'] ); ?></div>
					<div class="meta"><?php echo esc_html( trim( $label['format'] . ( '' !== $label['grades'] ? '  ' . $label['grades'] : '' ) ) ); ?></div>
					<?php if ( '' !== $label['price'] ) : ?>
						<div class="price"><?php echo esc_html( $label['price'] ); ?></div>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	<?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php
wp_register_script( 'waxsc-qrcode', $url . 'vendor/qrcode.js', array(), VERSION, true );
wp_add_inline_script(
	'waxsc-qrcode',
	"document.querySelectorAll( '[data-qr]' ).forEach( function ( box ) { var qr = qrcode( 0, 'M' ); qr.addData( box.getAttribute( 'data-qr' ) ); qr.make(); box.innerHTML = qr.createSvgTag( { cellSize: 2, margin: 0, scalable: true } ); } );"
);
wp_print_scripts( array( 'waxsc-qrcode' ) );
?>
</body>
</html>
		<?php
		exit;
	}
}
