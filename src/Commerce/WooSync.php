<?php
/**
 * Keeps one hidden WooCommerce variable product per release, with one
 * variation per grading, in sync with the copies, in both directions.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Commerce;

use WaxStore\Catalog\Commerce\Providers\WooCommerceProvider;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\Grading;
use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Reservation\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Copies are the source of truth for what is for sale; WooCommerce is the
 * source of truth for what has been sold (it holds stock during checkout,
 * so the last copy can never be sold twice).
 */
final class WooSync {

	const PRODUCT_META   = '_waxsc_wc_product';
	const RELEASE_META   = '_waxsc_release';
	const GROUP_META     = '_waxsc_group';
	const ATTRIBUTE      = 'waxsc-grading';
	const BATCH_HOOK     = 'waxsc_wc_sync_batch';
	const BATCH_SIZE     = 10;
	const COUNT_CACHE    = 'waxsc_wc_pending';

	/**
	 * Re-entrance guard.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Hooks, when WooCommerce is the active selling mode.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'shop_page_notice' ) );
		add_action( 'admin_init', array( self::class, 'check_shop_page' ) );
		add_action( 'add_option_woocommerce_shop_page_id', array( self::class, 'on_shop_page_change' ), 10, 2 );
		add_action( 'update_option_woocommerce_shop_page_id', array( self::class, 'on_shop_page_change_update' ), 10, 2 );

		add_action(
			'wp_loaded',
			static function () {
				if ( ! self::active() ) {
					return;
				}

				foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
					add_action(
						$hook,
						static function ( $meta_id, $object_id, $meta_key ) {
							if ( ReleaseMeta::COPIES === $meta_key ) {
								self::sync( (int) $object_id );
							}
						},
						20,
						3
					);
				}

				add_action( 'save_post_' . PostType::NAME, array( self::class, 'on_save' ), 30 );
				add_action( 'woocommerce_variation_set_stock', array( self::class, 'from_stock' ) );
				add_action( 'before_delete_post', array( self::class, 'on_delete' ) );
				add_action( 'wp_trash_post', array( self::class, 'on_delete' ) );
				add_action( 'template_redirect', array( self::class, 'redirect_product' ) );
				add_filter( 'woocommerce_cart_item_permalink', array( self::class, 'cart_permalink' ), 10, 2 );
				add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'order_item_location' ), 10, 3 );
				add_action( 'woocommerce_after_order_itemmeta', array( self::class, 'show_location' ), 10, 2 );

				add_action( self::BATCH_HOOK, array( self::class, 'run_batch' ) );

				if ( is_admin() && ! wp_doing_ajax() ) {
					add_action( 'admin_init', array( self::class, 'maybe_schedule' ) );
					add_action( 'admin_notices', array( self::class, 'notice' ) );
				}

				add_action( 'wp_ajax_waxsc_wc_sync_now', array( self::class, 'ajax_sync_now' ) );
			}
		);
	}

	/**
	 * Whether the WooCommerce shop page is the static home page.
	 *
	 * @param int $shop Shop page ID.
	 */
	private static function shop_is_home( int $shop ): bool {
		return $shop > 0 && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $shop;
	}

	/**
	 * The shop page was set while saving the WooCommerce settings form:
	 * a deliberate choice, kept as is.
	 */
	private static function chosen_by_owner(): bool {
		return is_admin() && isset( $_POST['woocommerce_shop_page_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only, WooCommerce checks its own nonce.
	}

	/**
	 * Option added (WooCommerce install).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 */
	public static function on_shop_page_change( $option, $value ): void {
		if ( self::shop_is_home( (int) $value ) && ! self::chosen_by_owner() ) {
			self::fix_shop_page();
		}
	}

	/**
	 * Option updated (setup wizard, store setup task…).
	 *
	 * @param mixed $old   Old value.
	 * @param mixed $value New value.
	 */
	public static function on_shop_page_change_update( $old, $value ): void {
		if ( self::shop_is_home( (int) $value ) && ! self::chosen_by_owner() ) {
			self::fix_shop_page();
		}
	}

	/**
	 * Sites already in this state: corrected once, then the owner's later choices are respected.
	 */
	public static function check_shop_page(): void {
		if ( ! function_exists( 'wc_get_page_id' ) || '1' === get_option( 'waxsc_shop_page_checked' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( 'waxsc_shop_page_checked', '1', false );

		if ( self::shop_is_home( (int) get_option( 'woocommerce_shop_page_id' ) ) ) {
			self::fix_shop_page();
		}
	}

	/**
	 * Gives WooCommerce its own shop page: an existing "Shop" page, or a new one.
	 */
	private static function fix_shop_page(): void {
		static $running = false;

		if ( $running ) {
			return;
		}

		$running = true;
		$home    = (int) get_option( 'page_on_front' );
		$page_id = 0;

		foreach ( array_unique( array( sanitize_title( _x( 'shop', 'page slug', 'wax-store-catalog' ) ), 'shop', 'boutique' ) ) as $slug ) {
			$page = get_page_by_path( $slug );

			if ( $page && (int) $page->ID !== $home && 'publish' === $page->post_status ) {
				$page_id = (int) $page->ID;
				break;
			}
		}

		if ( ! $page_id ) {
			$created = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => __( 'Shop', 'wax-store-catalog' ),
					'post_name'   => sanitize_title( _x( 'shop', 'page slug', 'wax-store-catalog' ) ),
				),
				true
			);
			$page_id = is_wp_error( $created ) ? 0 : (int) $created;
		}

		if ( $page_id ) {
			update_option( 'woocommerce_shop_page_id', $page_id );
			set_transient( 'waxsc_shop_page_fixed', $page_id, DAY_IN_SECONDS );
		}

		$running = false;
	}

	/**
	 * Warns when the WooCommerce shop page is also the home page: WooCommerce
	 * then replaces the home page content with its own product list.
	 */
	public static function shop_page_notice(): void {
		$fixed = (int) get_transient( 'waxsc_shop_page_fixed' );

		if ( $fixed && current_user_can( 'manage_options' ) ) {
			delete_transient( 'waxsc_shop_page_fixed' );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: page title. */
						__( 'WooCommerce had chosen your home page as shop page, which hides your catalog sections. The shop now uses the page "%s"; your home page is back.', 'wax-store-catalog' ),
						get_the_title( $fixed )
					)
				)
			);
		}

		if ( ! function_exists( 'wc_get_page_id' ) || ! current_user_can( 'manage_options' ) || 'page' !== get_option( 'show_on_front' ) ) {
			return;
		}

		$front = (int) get_option( 'page_on_front' );

		if ( ! $front || wc_get_page_id( 'shop' ) !== $front ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Your home page is also the WooCommerce shop page: WooCommerce replaces its content with its own product list, and your catalog sections disappear. Choose another page (or none) as shop page.', 'wax-store-catalog' ),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=products' ) ),
			esc_html__( 'Open the WooCommerce product settings', 'wax-store-catalog' )
		);
	}

	/**
	 * Whether WooCommerce is the active provider.
	 */
	public static function active(): bool {
		return WooCommerceProvider::ID === waxsc()->commerce()->get_active()->get_id();
	}

	/**
	 * Product of a release, if any.
	 *
	 * @param int $release Release ID.
	 * @return \WC_Product_Variable|null
	 */
	public static function product( int $release ) {
		$id      = (int) get_post_meta( $release, self::PRODUCT_META, true );
		$product = $id && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		return $product instanceof \WC_Product_Variable ? $product : null;
	}

	/**
	 * Copies grouped by grading and price. Only copies in stock count;
	 * reserved copies are not for sale online.
	 *
	 * @param int $release Release ID.
	 * @return array<string,array{label:string,price:string,stock:int,copies:string[]}>
	 */
	public static function groups( int $release ): array {
		$groups = array();

		foreach ( Copies::get( $release ) as $copy ) {
			if ( '' === $copy['price'] ) {
				continue;
			}

			$key = md5( $copy['media'] . '|' . $copy['sleeve'] . '|' . $copy['variant'] . '|' . $copy['price'] );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'label'  => self::label( $copy ),
					'price'  => $copy['price'],
					'stock'  => 0,
					'copies' => array(),
				);
			}

			$groups[ $key ]['copies'][] = $copy['id'];

			if ( ProviderInterface::IN_STOCK === $copy['status'] ) {
				$groups[ $key ]['stock'] += max( 0, $copy['qty'] );
			}
		}

		return $groups;
	}

	/**
	 * Variation label: "VG+ / VG, 25 €", or the size for merchandise.
	 *
	 * @param array $copy Copy.
	 */
	private static function label( array $copy ): string {
		$parts = array_filter( array( trim( implode( ' / ', array_filter( array( $copy['media'], $copy['sleeve'] ) ) ) ), $copy['variant'] ) );

		if ( ! $parts ) {
			$parts = array( __( 'Copy', 'wax-store-catalog' ) );
		}

		return implode( ', ', $parts ) . ', ' . Copies::format_price( $copy['price'] );
	}

	/**
	 * Save hook (title, cover…).
	 *
	 * @param int $release Release ID.
	 */
	public static function on_save( int $release ): void {
		if ( ! wp_is_post_revision( $release ) ) {
			self::sync( $release );
		}
	}

	/**
	 * Creates or updates the product and its variations.
	 *
	 * @param int $release Release ID.
	 */
	public static function sync( int $release ): void {
		if ( self::$syncing || PostType::NAME !== get_post_type( $release ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		// One process at a time per release (background task and "Convert now" may overlap).
		$lock = 'waxsc_wc_lock_' . $release;

		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < MINUTE_IN_SECONDS ) {
				return;
			}

			update_option( $lock, time(), false );
		}

		self::$syncing = true;

		try {
			self::do_sync( $release );
		} finally {
			self::$syncing = false;
			delete_option( $lock );
		}
	}

	/**
	 * Products already linked to a release, oldest first.
	 *
	 * @param int $release Release ID.
	 * @return int[]
	 */
	private static function linked_products( int $release ): array {
		$ids = wc_get_products(
			array(
				'type'       => 'variable',
				'status'     => array( 'publish', 'draft', 'private', 'pending' ),
				'limit'      => -1,
				'return'     => 'ids',
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'meta_key'   => self::RELEASE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one release.
				'meta_value' => $release, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one release.
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Deletes a product and its variations.
	 *
	 * @param int $product_id Product ID.
	 */
	private static function delete_product( int $product_id ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		foreach ( $product->get_children() as $child ) {
			wp_delete_post( (int) $child, true );
		}

		$product->delete( true );
	}

	/**
	 * Releases linked to more than one product.
	 */
	private static function duplicate_rows(): array {
		global $wpdb;

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cleanup of duplicates.
			$wpdb->prepare(
				"SELECT m.meta_value AS release_id, GROUP_CONCAT(m.post_id ORDER BY m.post_id) AS products
				FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'product'
				WHERE m.meta_key = %s
				GROUP BY m.meta_value
				HAVING COUNT(*) > 1",
				self::RELEASE_META
			)
		);
	}

	/**
	 * Number of releases that still have duplicate products.
	 */
	public static function duplicates_left(): int {
		return count( self::duplicate_rows() );
	}

	/**
	 * Removes duplicate products created by overlapping conversions, keeping
	 * the one the release points to (or the oldest). Stops after a time budget,
	 * so a page never runs into the server time limit.
	 *
	 * @param float $budget Seconds.
	 * @return int Products removed.
	 */
	public static function remove_duplicates( float $budget = 8.0 ): int {
		$start   = microtime( true );
		$removed = 0;

		foreach ( self::duplicate_rows() as $row ) {
			$release  = (int) $row->release_id;
			$products = array_map( 'intval', explode( ',', (string) $row->products ) );
			$keep     = (int) get_post_meta( $release, self::PRODUCT_META, true );
			$keep     = in_array( $keep, $products, true ) ? $keep : $products[0];

			update_post_meta( $release, self::PRODUCT_META, $keep );

			foreach ( $products as $product_id ) {
				if ( microtime( true ) - $start > $budget ) {
					return $removed;
				}

				if ( $product_id !== $keep ) {
					self::delete_product( $product_id );
					++$removed;
				}
			}
		}

		update_option( 'waxsc_wc_deduplicated', '1', false );

		return $removed;
	}

	/**
	 * Sync body.
	 *
	 * @param int $release Release ID.
	 */
	private static function do_sync( int $release ): void {
		$groups  = self::groups( $release );
		$product = self::product( $release );

		if ( ! $product ) {
			$linked  = self::linked_products( $release );
			$product = $linked ? wc_get_product( $linked[0] ) : null;
			$product = $product instanceof \WC_Product_Variable ? $product : null;
		}

		if ( ! $product ) {
			if ( ! $groups ) {
				return;
			}

			$product = new \WC_Product_Variable();
		}

		$product->set_name( Requests::release_label( $release ) );
		$product->set_status( 'publish' === get_post_status( $release ) ? 'publish' : 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_image_id( (int) get_post_thumbnail_id( $release ) );
		$product->set_sku( '' );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( __( 'Copy', 'wax-store-catalog' ) );
		$attribute->set_options( array_values( wp_list_pluck( $groups, 'label' ) ) ? array_values( wp_list_pluck( $groups, 'label' ) ) : array( '—' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( self::RELEASE_META, $release );
		$product_id = $product->save();

		update_post_meta( $release, self::PRODUCT_META, $product_id );

		$existing = array();

		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( $child ) {
				$existing[ (string) $child->get_meta( self::GROUP_META ) ] = $child;
			}
		}

		$attribute_key = sanitize_title( __( 'Copy', 'wax-store-catalog' ) );

		foreach ( $groups as $key => $group ) {
			$variation = $existing[ $key ] ?? new \WC_Product_Variation();
			unset( $existing[ $key ] );

			$variation->set_parent_id( $product_id );
			$variation->set_attributes( array( $attribute_key => $group['label'] ) );
			$variation->set_regular_price( $group['price'] );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $group['stock'] );
			$variation->set_stock_status( $group['stock'] > 0 ? 'instock' : 'outofstock' );
			$variation->set_backorders( 'no' );
			$variation->set_status( 'publish' );
			$variation->update_meta_data( self::GROUP_META, $key );
			$variation->update_meta_data( '_waxsc_label', $group['label'] );
			$variation->save();
		}

		// Groups that no longer exist (copy deleted, grade or price changed).
		foreach ( $existing as $variation ) {
			$variation->delete( true );
		}

		\WC_Product_Variable::sync( $product_id );
		wc_delete_product_transients( $product_id );
	}

	/**
	 * WooCommerce changed a variation stock (order placed, cancelled, refunded…):
	 * copies follow. Selling marks copies sold; restocking puts them back.
	 *
	 * @param \WC_Product $variation Variation.
	 */
	public static function from_stock( $variation ): void {
		if ( self::$syncing || ! $variation instanceof \WC_Product_Variation ) {
			return;
		}

		$parent  = wc_get_product( $variation->get_parent_id() );
		$release = $parent ? (int) $parent->get_meta( self::RELEASE_META ) : 0;
		$key     = (string) $variation->get_meta( self::GROUP_META );
		$groups  = $release ? self::groups( $release ) : array();

		if ( ! isset( $groups[ $key ] ) ) {
			return;
		}

		$target  = max( 0, (int) $variation->get_stock_quantity() );
		$current = $groups[ $key ]['stock'];
		$ids     = $groups[ $key ]['copies'];
		$copies  = Copies::get( $release );

		foreach ( $copies as $index => $copy ) {
			if ( $current === $target ) {
				break;
			}

			if ( ! in_array( $copy['id'], $ids, true ) ) {
				continue;
			}

			if ( $target < $current && ProviderInterface::IN_STOCK === $copy['status'] && $copy['qty'] > 0 ) {
				$remove                  = min( $copy['qty'], $current - $target );
				$copies[ $index ]['qty'] = $copy['qty'] - $remove;
				$current                -= $remove;

				if ( 0 === $copies[ $index ]['qty'] ) {
					$copies[ $index ]['status'] = ProviderInterface::SOLD;
				}
			} elseif ( $target > $current && ( ProviderInterface::SOLD === $copy['status'] || 0 === $copy['qty'] ) && ProviderInterface::RESERVED !== $copy['status'] ) {
				$add                        = $target - $current;
				$copies[ $index ]['status'] = ProviderInterface::IN_STOCK;
				$copies[ $index ]['qty']    = $copy['qty'] + $add;
				$current                   += $add;
			}
		}

		self::$syncing = true;

		try {
			update_post_meta( $release, ReleaseMeta::COPIES, Copies::sanitize( $copies ) );
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * Trashing or deleting a release hides its product.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_delete( int $post_id ): void {
		if ( PostType::NAME !== get_post_type( $post_id ) ) {
			return;
		}

		$product = self::product( $post_id );

		if ( $product ) {
			$product->set_status( 'draft' );
			$product->save();
		}
	}

	/**
	 * The release page is the product page: redirect hidden product URLs.
	 */
	public static function redirect_product(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$release = (int) get_post_meta( (int) get_queried_object_id(), self::RELEASE_META, true );

		if ( $release && 'publish' === get_post_status( $release ) ) {
			wp_safe_redirect( (string) get_permalink( $release ), 301 );
			exit;
		}
	}

	/**
	 * Remembers where the copies of the ordered grading sit in the shop
	 * (hidden meta: shown in the admin order screen only).
	 *
	 * @param \WC_Order_Item_Product $item          Order line.
	 * @param string                 $cart_item_key Cart key.
	 * @param array                  $values        Cart values.
	 */
	public static function order_item_location( $item, $cart_item_key, $values ): void {
		$variation = ! empty( $values['variation_id'] ) ? wc_get_product( (int) $values['variation_id'] ) : null;

		if ( ! $variation ) {
			return;
		}

		$parent  = wc_get_product( $variation->get_parent_id() );
		$release = $parent ? (int) $parent->get_meta( self::RELEASE_META ) : 0;
		$key     = (string) $variation->get_meta( self::GROUP_META );
		$groups  = $release ? self::groups( $release ) : array();

		if ( ! isset( $groups[ $key ] ) ) {
			return;
		}

		$locations = array();

		foreach ( Copies::get( $release ) as $copy ) {
			if ( in_array( $copy['id'], $groups[ $key ]['copies'], true ) && '' !== $copy['location'] && ProviderInterface::IN_STOCK === $copy['status'] ) {
				$locations[] = $copy['location'];
			}
		}

		if ( $locations ) {
			$item->add_meta_data( '_waxsc_location', implode( ' / ', array_unique( $locations ) ), true );
		}
	}

	/**
	 * Location under the order line, in the admin.
	 *
	 * @param int                    $item_id Item ID.
	 * @param \WC_Order_Item_Product $item    Order line.
	 */
	public static function show_location( $item_id, $item ): void {
		$location = is_object( $item ) && method_exists( $item, 'get_meta' ) ? (string) $item->get_meta( '_waxsc_location' ) : '';

		if ( '' !== $location ) {
			/* translators: %s: location in the shop. */
			echo '<p class="waxsc-order-location"><strong>' . esc_html( sprintf( __( 'Location in the shop: %s', 'wax-store-catalog' ), $location ) ) . '</strong></p>';
		}
	}

	/**
	 * Cart links point to the release page.
	 *
	 * @param string $link Link.
	 * @param array  $item Cart item.
	 */
	public static function cart_permalink( $link, $item ): string {
		$release = isset( $item['product_id'] ) ? (int) get_post_meta( (int) $item['product_id'], self::RELEASE_META, true ) : 0;

		return $release ? (string) get_permalink( $release ) : (string) $link;
	}

	/**
	 * Releases still without a product (or a 0 marker when they have nothing to sell).
	 *
	 * @param int $limit Maximum IDs.
	 * @return int[]
	 */
	private static function pending( int $limit ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => PostType::NAME,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- background batches only.
						array(
							'key'     => self::PRODUCT_META,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => ReleaseMeta::COPIES,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);
	}

	/**
	 * Number of releases left to convert, cached one minute.
	 */
	public static function pending_count(): int {
		$count = get_transient( self::COUNT_CACHE );

		if ( false === $count ) {
			$query = new \WP_Query(
				array(
					'post_type'      => PostType::NAME,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached count.
						array(
							'key'     => self::PRODUCT_META,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => ReleaseMeta::COPIES,
							'compare' => 'EXISTS',
						),
					),
				)
			);
			$count = (int) $query->found_posts;
			set_transient( self::COUNT_CACHE, $count, MINUTE_IN_SECONDS );
		}

		return (int) $count;
	}

	/**
	 * Converts one small batch. Returns how many are left.
	 */
	public static function run_batch(): int {
		if ( ! add_option( 'waxsc_wc_batch_lock', time(), '', false ) ) {
			if ( time() - (int) get_option( 'waxsc_wc_batch_lock' ) < 2 * MINUTE_IN_SECONDS ) {
				return self::pending_count() + ( '1' !== get_option( 'waxsc_wc_deduplicated' ) ? self::duplicates_left() : 0 );
			}

			update_option( 'waxsc_wc_batch_lock', time(), false );
		}

		try {
			$left = self::process_batch();
		} finally {
			delete_option( 'waxsc_wc_batch_lock' );
		}

		return $left;
	}

	/**
	 * Batch body.
	 */
	private static function process_batch(): int {
		if ( '1' !== get_option( 'waxsc_wc_deduplicated' ) ) {
			self::remove_duplicates( 15.0 );

			if ( '1' !== get_option( 'waxsc_wc_deduplicated' ) ) {
				self::schedule();
				return self::pending_count() + self::duplicates_left();
			}
		}

		foreach ( self::pending( self::BATCH_SIZE ) as $id ) {
			self::sync( $id );

			if ( ! get_post_meta( $id, self::PRODUCT_META, true ) ) {
				update_post_meta( $id, self::PRODUCT_META, 0 );
			}
		}

		delete_transient( self::COUNT_CACHE );
		$left = self::pending_count();

		if ( $left > 0 ) {
			self::schedule();
		}

		return $left;
	}

	/**
	 * Queues the next background batch (Action Scheduler, bundled with WooCommerce).
	 */
	private static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! as_has_scheduled_action( self::BATCH_HOOK, array(), 'wax-store-catalog' ) ) {
				as_enqueue_async_action( self::BATCH_HOOK, array(), 'wax-store-catalog' );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::BATCH_HOOK );
		}
	}

	/**
	 * On admin pages: a cheap check, at most every five minutes, then background work.
	 */
	public static function maybe_schedule(): void {
		if ( get_transient( 'waxsc_wc_checked' ) ) {
			return;
		}

		set_transient( 'waxsc_wc_checked', 1, '1' === get_option( 'waxsc_wc_deduplicated' ) ? 5 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );

		if ( '1' !== get_option( 'waxsc_wc_deduplicated' ) ) {
			self::remove_duplicates( 5.0 );
		}

		if ( self::pending_count() > 0 ) {
			self::schedule();
		}
	}

	/**
	 * Progress notice, with a button to convert now (useful on local sites
	 * where background tasks may not run).
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$left       = self::pending_count();
		$duplicates = '1' !== get_option( 'waxsc_wc_deduplicated' ) ? self::duplicates_left() : 0;

		if ( $left < 1 && $duplicates < 1 ) {
			return;
		}

		$left += $duplicates;

		printf(
			'<div class="notice notice-info" data-waxsc-wc-sync="%1$s"><p>%2$s <button type="button" class="button">%3$s</button></p></div>',
			'1',
			esc_html(
				sprintf(
					/* translators: %d: number of releases. */
					_n( 'WooCommerce: %d release is waiting for its product. It is created in the background.', 'WooCommerce: %d releases are waiting for their product. They are created in the background.', $left, 'wax-store-catalog' ),
					$left
				)
			),
			esc_html__( 'Convert now', 'wax-store-catalog' )
		);

		wp_enqueue_script( 'waxsc-wc-sync', plugins_url( 'assets/wc-sync.js', \WaxStore\Catalog\PLUGIN_FILE ), array(), \WaxStore\Catalog\VERSION, true );
		wp_localize_script(
			'waxsc-wc-sync',
			'waxscWcSync',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'waxsc_wc_sync_now' ),
				'i18n'    => array(
					/* translators: %d: number of releases. */
					'left' => __( 'Converting… %d left', 'wax-store-catalog' ),
					'done' => __( 'All releases are ready for WooCommerce.', 'wax-store-catalog' ),
				),
			)
		);
	}

	/**
	 * "Convert now": one batch per request.
	 */
	public static function ajax_sync_now(): void {
		check_ajax_referer( 'waxsc_wc_sync_now', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$busy = (bool) get_option( 'waxsc_wc_batch_lock' );
		$left = self::run_batch();

		wp_send_json_success(
			array(
				'left' => $left,
				'busy' => $busy,
			)
		);
	}
}
