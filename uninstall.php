<?php
/**
 * Uninstall: removes settings and personal data (requests, alerts, offers).
 * Releases and terms are the shop's data and are deliberately kept, so that
 * reinstalling restores the catalog.
 *
 * @package WaxStore\Catalog
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$waxsc_state = get_option( 'waxsc_import_state' );

if ( is_array( $waxsc_state ) && ! empty( $waxsc_state['file'] ) && is_file( $waxsc_state['file'] ) ) {
	wp_delete_file( $waxsc_state['file'] );
}

$waxsc_options = array(
	'waxsc_shop',
	'waxsc_default_sort',
	'waxsc_sold_items',
	'waxsc_shop_page_checked',
	'waxsc_discogs_exports',
	'waxsc_google_feed',
	'waxsc_sell_page',
	'waxsc_google_feed_stats',
	'waxsc_wc_deduplicated',
	'waxsc_reservation_email',
	'waxsc_auto_reserve',
	'waxsc_import_state',
	'waxsc_grading',
	'waxsc_version',
	'waxsc_default_terms_installed',
	'waxsc_commerce_provider',
	'waxsc_currency',
	'waxsc_reserve_page',
);

foreach ( $waxsc_options as $waxsc_option ) {
	delete_option( $waxsc_option );
}

// Reservation requests hold personal data: they are removed with the plugin.
$waxsc_requests = get_posts(
	array(
		'post_type'      => 'waxsc_request',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $waxsc_requests as $waxsc_request ) {
	wp_delete_post( $waxsc_request, true );
}

wp_clear_scheduled_hook( 'waxsc_purge_requests' );

// Alerts hold e-mail addresses: removed with the plugin.
$waxsc_alerts = get_posts(
	array(
		'post_type'      => 'waxsc_alert',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $waxsc_alerts as $waxsc_alert ) {
	wp_delete_post( $waxsc_alert, true );
}

wp_clear_scheduled_hook( 'waxsc_send_alerts' );

// Collection offers hold personal data and files: removed with the plugin.
$waxsc_offers = get_posts(
	array(
		'post_type'      => 'waxsc_offer',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $waxsc_offers as $waxsc_offer ) {
	foreach ( (array) get_post_meta( $waxsc_offer, '_waxsc_offer_files', true ) as $waxsc_file ) {
		if ( ! empty( $waxsc_file['path'] ) && false !== strpos( wp_normalize_path( $waxsc_file['path'] ), '/wax-store-catalog/offers/' ) ) {
			wp_delete_file( $waxsc_file['path'] );
		}
	}

	wp_delete_post( $waxsc_offer, true );
}

wp_clear_scheduled_hook( 'waxsc_purge_offers' );
