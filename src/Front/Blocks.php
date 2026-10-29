<?php
/**
 * Block registration and server rendering.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Front;

use WaxStore\Catalog\Commerce\ProviderInterface;
use WaxStore\Catalog\Content\Copies;
use WaxStore\Catalog\Content\Gallery;
use WaxStore\Catalog\Content\Grading;
use WaxStore\Catalog\Content\ReleaseMeta;
use WaxStore\Catalog\Content\Taxonomies;
use WaxStore\Catalog\Content\Tracklist;

use const WaxStore\Catalog\PLUGIN_FILE;
use const WaxStore\Catalog\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic blocks. Every method returns escaped HTML.
 */
final class Blocks {

	const BLOCKS = array( 'release-price', 'release-summary', 'release-details', 'release-copies', 'release-tracklist', 'catalog-filters', 'artist-index', 'reservation-form', 'release-gallery', 'shop-status', 'alert-form', 'sell-form' );

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 20 );
		add_action( 'init', array( self::class, 'register_patterns' ), 30 );
		add_filter( 'render_block_core/post-featured-image', array( self::class, 'cover_placeholder' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_front' ) );
		add_shortcode( 'waxsc_catalog', array( self::class, 'shortcode' ) );
	}

	/**
	 * The catalog stylesheet on every page: query loops of releases may sit in
	 * any template, and cover placeholders need it even without a plugin block.
	 */
	public static function enqueue_front(): void {
		wp_enqueue_style( 'waxsc-blocks' );
	}

	/**
	 * [waxsc_catalog] for page builders and classic themes: artist index, filters
	 * and the latest releases. Attribute "count" sets the number of releases.
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts    = shortcode_atts( array( 'count' => 12 ), (array) $atts, 'waxsc_catalog' );
		$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( 'wax-store-catalog/latest-arrivals' );

		if ( ! $pattern ) {
			return '';
		}

		$content = str_replace( '"perPage":12', '"perPage":' . max( 1, min( 48, (int) $atts['count'] ) ), $pattern['content'] );

		return '<div class="waxsc-shortcode">' . do_blocks( $content ) . '</div>';
	}

	/**
	 * Releases without a photo get a stylized record instead of an empty space,
	 * in any query loop or template.
	 *
	 * @param string         $content  Rendered block.
	 * @param array          $parsed   Parsed block.
	 * @param \WP_Block|null $instance Block instance.
	 */
	public static function cover_placeholder( $content, $parsed, $instance = null ): string {
		$content = (string) $content;

		if ( '' !== trim( $content ) ) {
			return self::illustration_note( $content, $instance );
		}

		$post_id = self::post_id( $instance );

		if ( ! $post_id ) {
			return $content;
		}

		$attrs = isset( $parsed['attrs'] ) && is_array( $parsed['attrs'] ) ? $parsed['attrs'] : array();
		$ratio = isset( $attrs['aspectRatio'] ) && preg_match( '#^[0-9.]+(\s*/\s*[0-9.]+)?$#', (string) $attrs['aspectRatio'] ) ? (string) $attrs['aspectRatio'] : '1';
		$inner = '<span class="waxsc-cover-placeholder__disc"></span>';

		if ( ! empty( $attrs['isLink'] ) ) {
			$inner = '<a href="' . esc_url( get_permalink( $post_id ) ) . '" tabindex="-1">' . $inner . '</a>';
		}

		return '<figure class="wp-block-post-featured-image waxsc-cover-placeholder" style="aspect-ratio:' . esc_attr( $ratio ) . '" aria-hidden="true">' . $inner . '</figure>';
	}

	/**
	 * "Illustration" under a cover fetched from the Cover Art Archive, on the release page.
	 *
	 * @param string         $content  Rendered image.
	 * @param \WP_Block|null $instance Block instance.
	 */
	private static function illustration_note( string $content, $instance ): string {
		$post_id = self::post_id( $instance );

		if ( ! $post_id || ! is_singular() || (int) get_queried_object_id() !== $post_id || ! \WaxStore\Catalog\Covers\Fetcher::is_illustration( $post_id ) ) {
			return $content;
		}

		$note = '<figcaption class="waxsc-cover-note">' . esc_html__( 'Illustration: your copy may differ', 'wax-store-catalog' ) . '</figcaption>';
		$pos  = strrpos( $content, '</figure>' );

		return false === $pos ? $content . $note : substr_replace( $content, $note . '</figure>', $pos, strlen( '</figure>' ) );
	}

	/**
	 * Ready-made home page section.
	 */
	public static function register_patterns(): void {
		$post_type = waxsc()->commerce()->get_catalog_post_type();
		$archive   = get_post_type_archive_link( $post_type );

		register_block_pattern_category(
			'waxsc',
			array( 'label' => __( 'Record shop catalog', 'wax-store-catalog' ) )
		);

		register_block_pattern(
			'wax-store-catalog/latest-arrivals',
			array(
				'title'       => __( 'Latest arrivals', 'wax-store-catalog' ),
				'description' => __( 'Artist index, filters and a grid of the latest releases, with a link to the full catalog.', 'wax-store-catalog' ),
				'categories'  => array( 'waxsc', 'query' ),
				'blockTypes'  => array( 'core/query' ),
				'content'     => '<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide"><!-- wp:group {"className":"waxsc-divider","layout":{"type":"default"}} -->
<div class="wp-block-group waxsc-divider"><!-- wp:heading -->
<h2 class="wp-block-heading">' . esc_html__( 'Latest arrivals', 'wax-store-catalog' ) . '</h2>
<!-- /wp:heading --></div>
<!-- /wp:group -->
<!-- wp:waxsc/artist-index /-->
<!-- wp:waxsc/catalog-filters /-->
<!-- wp:query {"queryId":20,"query":{"perPage":12,"pages":0,"offset":0,"postType":"' . esc_attr( $post_type ) . '","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false},"className":"waxsc-latest"} -->
<div class="wp-block-query waxsc-latest"><!-- wp:post-template {"className":"waxsc-grid","layout":{"type":"grid","minimumColumnWidth":"11rem"}} -->
<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"1","scale":"cover"} /-->
<!-- wp:waxsc/release-price /-->
<!-- wp:post-terms {"term":"waxsc_artist","className":"waxsc-artist"} /-->
<!-- wp:post-title {"isLink":true,"level":3,"className":"waxsc-card-title"} /-->
<!-- wp:waxsc/release-summary /-->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $archive ? $archive : home_url( '/' ) ) . '">' . esc_html__( 'Browse the whole catalog', 'wax-store-catalog' ) . '</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->',
			)
		);
	}

	/**
	 * Registers assets, then blocks from their block.json.
	 */
	public static function register(): void {
		$url  = plugin_dir_url( PLUGIN_FILE ) . 'assets/';
		$path = plugin_dir_path( PLUGIN_FILE );

		wp_register_script( 'waxsc-blocks-editor', $url . 'blocks-editor.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), VERSION, true );
		wp_register_script( 'waxsc-shop-status', $url . 'shop-status.js', array(), VERSION, true );
		wp_set_script_translations( 'waxsc-blocks-editor', 'wax-store-catalog' );
		wp_register_script( 'waxsc-catalog-filters', $url . 'catalog-filters.js', array(), VERSION, true );
		wp_register_style( 'waxsc-blocks', $url . 'blocks.css', array(), VERSION );

		foreach ( self::BLOCKS as $name ) {
			register_block_type( $path . 'blocks/' . $name );
		}
	}

	/**
	 * Release ID from block context (query loops) or the current post.
	 *
	 * @param \WP_Block|null $block Block instance.
	 */
	private static function post_id( $block ): int {
		$post_id = ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();

		return ( $post_id && waxsc()->commerce()->get_catalog_post_type() === get_post_type( $post_id ) ) ? $post_id : 0;
	}

	/**
	 * Wraps block HTML with the block wrapper attributes.
	 *
	 * @param string $html  Inner HTML (escaped).
	 * @param string $class Extra class.
	 * @param string $tag   Wrapper tag.
	 */
	private static function wrap( string $html, string $class = '', string $tag = 'div' ): string {
		if ( '' === $html ) {
			return '';
		}

		$attributes = get_block_wrapper_attributes( '' !== $class ? array( 'class' => $class ) : array() );

		return sprintf( '<%1$s %2$s>%3$s</%1$s>', tag_escape( $tag ), $attributes, $html );
	}

	/**
	 * Price, as a sticker, with the optional call to action.
	 *
	 * @param array          $attributes Attributes.
	 * @param \WP_Block|null $block      Block.
	 */
	public static function price( array $attributes, $block = null ): string {
		$post_id = self::post_id( $block );

		if ( ! $post_id ) {
			return '';
		}

		$provider = waxsc()->commerce()->get_active();
		$html     = $provider->get_price_html( $post_id );

		if ( '' === $html && ProviderInterface::SOLD === $provider->get_availability( $post_id ) ) {
			$html = '<span class="waxsc-price waxsc-price--sold">' . esc_html__( 'Sold', 'wax-store-catalog' ) . '</span>';
		}

		if ( ! empty( $attributes['showAction'] ) ) {
			$html .= $provider->get_action_html( $post_id );
		}

		return self::wrap( $html );
	}

	/**
	 * Card summary: format and year, then grades of the best available copy.
	 *
	 * @param array          $attributes Attributes.
	 * @param \WP_Block|null $block      Block.
	 */
	public static function summary( array $attributes, $block = null ): string {
		$post_id = self::post_id( $block );

		if ( ! $post_id ) {
			return '';
		}

		$first = array_filter(
			array(
				self::term_names( $post_id, Taxonomies::FORMAT ),
				(string) ( get_post_meta( $post_id, ReleaseMeta::YEAR, true ) ? get_post_meta( $post_id, ReleaseMeta::YEAR, true ) : '' ),
			)
		);

		$lines = array();

		if ( $first ) {
			$lines[] = esc_html( implode( ', ', $first ) );
		}

		$copies = Copies::get_available( $post_id );

		if ( $copies ) {
			$grades = self::grades_text( $copies[0] );

			if ( '' !== $grades ) {
				$lines[] = esc_html( $grades );
			}

			if ( count( $copies ) > 1 ) {
				/* translators: %d: number of copies for sale. */
				$lines[] = esc_html( sprintf( _n( '%d copy', '%d copies', count( $copies ), 'wax-store-catalog' ), count( $copies ) ) );
			}
		}

		return self::wrap( $lines ? '<p>' . implode( '<br>', $lines ) . '</p>' : '' );
	}

	/**
	 * Full release details as a description list.
	 *
	 * @param array          $attributes Attributes.
	 * @param \WP_Block|null $block      Block.
	 */
	public static function details( array $attributes, $block = null ): string {
		$post_id = self::post_id( $block );

		if ( ! $post_id ) {
			return '';
		}

		$rows = array(
			__( 'Format', 'wax-store-catalog' )         => self::term_links( $post_id, Taxonomies::FORMAT ),
			__( 'Record label', 'wax-store-catalog' )   => self::term_links( $post_id, Taxonomies::LABEL ),
			__( 'Catalog number', 'wax-store-catalog' ) => esc_html( (string) get_post_meta( $post_id, ReleaseMeta::CATALOG_NUMBER, true ) ),
			__( 'Year', 'wax-store-catalog' )           => esc_html( (string) ( get_post_meta( $post_id, ReleaseMeta::YEAR, true ) ? get_post_meta( $post_id, ReleaseMeta::YEAR, true ) : '' ) ),
			__( 'Country', 'wax-store-catalog' )        => self::term_links( $post_id, Taxonomies::COUNTRY ),
			__( 'Genre', 'wax-store-catalog' )          => self::term_links( $post_id, Taxonomies::GENRE ),
			__( 'Edition', 'wax-store-catalog' )        => self::term_links( $post_id, Taxonomies::EDITION ),
			__( 'Features', 'wax-store-catalog' )       => self::term_links( $post_id, Taxonomies::FEATURE ),
			__( 'Pressing', 'wax-store-catalog' )       => esc_html( (string) get_post_meta( $post_id, ReleaseMeta::PRESSING, true ) ),
			__( 'Barcode', 'wax-store-catalog' )        => esc_html( (string) get_post_meta( $post_id, ReleaseMeta::BARCODE, true ) ),
		);

		$html = '';

		foreach ( $rows as $label => $value ) {
			if ( '' !== $value ) {
				$html .= '<div class="waxsc-details__row"><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
			}
		}

		return self::wrap( '' !== $html ? $html : '', 'waxsc-details', 'dl' );
	}

	/**
	 * Copies for sale, then a single call to action.
	 *
	 * @param array          $attributes Attributes.
	 * @param \WP_Block|null $block      Block.
	 */
	public static function copies( array $attributes, $block = null ): string {
		$post_id = self::post_id( $block );

		if ( ! $post_id ) {
			return '';
		}

		$provider  = waxsc()->commerce()->get_active();
		$copies    = Copies::get( $post_id );
		$items     = '';
		$statuses  = array(
			ProviderInterface::IN_STOCK => '',
			ProviderInterface::RESERVED => __( 'Reserved', 'wax-store-catalog' ),
			ProviderInterface::SOLD     => __( 'Sold', 'wax-store-catalog' ),
		);
		$available = 0;
		$per_copy  = \WaxStore\Catalog\Commerce\Providers\ShowcaseProvider::ID === $provider->get_id() && '' !== \WaxStore\Catalog\Commerce\Providers\ShowcaseProvider::reserve_url( $post_id );

		foreach ( $copies as $copy ) {
			$sold = ProviderInterface::SOLD === $copy['status'] || $copy['qty'] < 1;

			if ( $sold ) {
				continue;
			}

			++$available;
			$status = $statuses[ $copy['status'] ];
			$lines  = array_filter( array( self::grades_text( $copy, true ), $copy['variant'], $copy['note'] ) );
			$price  = '' !== $copy['price'] ? Copies::format_price( $copy['price'] ) : __( 'Price on request', 'wax-store-catalog' );

			$reserve = '';

			if ( $per_copy && ProviderInterface::IN_STOCK === $copy['status'] ) {
				$reserve = sprintf(
					'<a class="waxsc-copy__reserve" href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
					esc_url( \WaxStore\Catalog\Commerce\Providers\ShowcaseProvider::reserve_url( $post_id, $copy['id'] ) ),
					esc_html__( 'Reserve', 'wax-store-catalog' ),
					esc_html( self::grades_text( $copy ) . ', ' . $price )
				);
			}

			$items .= sprintf(
				'<li class="waxsc-copy waxsc-copy--%1$s"><div class="waxsc-copy__info">%2$s</div><div class="waxsc-copy__price">%3$s%4$s%5$s</div></li>',
				esc_attr( $copy['status'] ),
				implode( '', array_map( static function ( $line ) { return '<p>' . esc_html( $line ) . '</p>'; }, $lines ) ),
				esc_html( $price ),
				'' !== $status ? ' <span class="waxsc-copy__status">' . esc_html( $status ) . '</span>' : '',
				$reserve
			);
		}

		$alert = \WaxStore\Catalog\Alerts\Form::render( $post_id );

		if ( 0 === $available ) {
			return self::wrap( '<p class="waxsc-copies__empty">' . esc_html__( 'No copy available right now. Ask us, it may come back in the bins.', 'wax-store-catalog' ) . '</p>' . $alert, 'waxsc-copies' );
		}

		$title = '<h2 class="waxsc-copies__title">' . esc_html( _n( 'Copy for sale', 'Copies for sale', $available, 'wax-store-catalog' ) ) . '</h2>';

		$action = $per_copy ? '' : $provider->get_action_html( $post_id );

		return self::wrap( $title . '<ul class="waxsc-copies__list">' . $items . '</ul>' . $action . $alert, 'waxsc-copies' );
	}

	/**
	 * Gallery: thumbnails linking to the full-size photos.
	 *
	 * @param array          $attributes Attributes.
	 * @param \WP_Block|null $block      Block.
	 */
	public static function gallery( array $attributes, $block = null ): string {
		$post_id = self::post_id( $block );
		$ids     = $post_id ? Gallery::get( $post_id ) : array();

		if ( ! $ids ) {
			return '';
		}

		$items = '';

		foreach ( $ids as $id ) {
			$full   = wp_get_attachment_image_url( $id, 'full' );
			$items .= '<li><a href="' . esc_url( (string) $full ) . '">' . wp_get_attachment_image( $id, 'medium', false, array( 'loading' => 'lazy' ) ) . '</a></li>';
		}

		return self::wrap( '<ul class="waxsc-gallery__list">' . $items . '</ul>', 'waxsc-gallery' );
	}

	/**
	 * Tracklist.
	 *
	 * @param array          $attributes Attributes.
	 * @param \WP_Block|null $block      Block.
	 */
	public static function tracklist( array $attributes, $block = null ): string {
		$post_id = self::post_id( $block );
		$tracks  = $post_id ? Tracklist::get( $post_id ) : array();

		if ( ! $tracks ) {
			return '';
		}

		$items = '';

		foreach ( $tracks as $track ) {
			$items .= sprintf(
				'<li><span class="waxsc-track__position">%1$s</span><span class="waxsc-track__title">%2$s</span><span class="waxsc-track__duration">%3$s</span></li>',
				esc_html( $track['position'] ),
				esc_html( $track['title'] ),
				esc_html( $track['duration'] )
			);
		}

		return self::wrap( '<h2 class="waxsc-tracklist__title">' . esc_html__( 'Tracklist', 'wax-store-catalog' ) . '</h2><ol class="waxsc-tracklist__list">' . $items . '</ol>', 'waxsc-tracklist' );
	}

	/**
	 * Format and genre filters. A plain GET form, enhanced by catalog-filters.js.
	 *
	 * @param array          $attributes Attributes.
	 * @param \WP_Block|null $block      Block.
	 */
	public static function filters( array $attributes, $block = null ): string {
		$post_type = waxsc()->commerce()->get_catalog_post_type();
		$action    = get_post_type_archive_link( $post_type );

		if ( ! $action ) {
			return '';
		}

		/**
		 * Format keys offered in the filter (top-level format terms).
		 *
		 * @param string[] $keys Format keys.
		 */
		$keys    = (array) apply_filters( 'waxsc_filter_format_keys', array( 'vinyl', 'cd', 'cassette' ) );
		$formats = array();

		foreach ( $keys as $key ) {
			$term_id = Taxonomies::find_by_key( Taxonomies::FORMAT, (string) $key );
			$term    = $term_id ? get_term( $term_id, Taxonomies::FORMAT ) : null;

			if ( $term instanceof \WP_Term ) {
				$formats[ $term->slug ] = $term->name;
			}
		}

		$genres = array();
		$terms  = get_terms(
			array(
				'taxonomy'   => Taxonomies::GENRE,
				'parent'     => 0,
				'hide_empty' => true,
			)
		);

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$genres[ $term->slug ] = $term->name;
		}

		$current_format = self::current_term( Taxonomies::FORMAT );
		$current_genre  = self::current_term( Taxonomies::GENRE );
		$current_style  = sanitize_title( (string) get_query_var( 'waxsc_style' ) );

		// On a sub-genre archive (/genre/hard-rock/), select its parent genre and the sub-genre.
		$genre_term = '' !== $current_genre ? get_term_by( 'slug', $current_genre, Taxonomies::GENRE ) : false;

		if ( $genre_term instanceof \WP_Term && $genre_term->parent ) {
			$ancestors     = get_ancestors( $genre_term->term_id, Taxonomies::GENRE, 'taxonomy' );
			$root          = get_term( (int) end( $ancestors ), Taxonomies::GENRE );
			$current_style = '' !== $current_style ? $current_style : $genre_term->slug;
			$genre_term    = $root instanceof \WP_Term ? $root : $genre_term;
			$current_genre = $genre_term->slug;
		}
		$letter         = self::current_letter();

		$html  = '<form class="waxsc-filters__form" method="get" action="' . esc_url( $action ) . '" role="search" aria-label="' . esc_attr__( 'Filter the catalog', 'wax-store-catalog' ) . '" data-results-label="' . esc_attr__( 'releases shown', 'wax-store-catalog' ) . '" data-waxsc-filters>';
		$search = CatalogQuery::current_search();
		$html  .= '<p class="waxsc-filters__field waxsc-filters__field--search"><label for="waxsc-filter-search">' . esc_html__( 'Search', 'wax-store-catalog' ) . '</label>'
			. '<input type="search" id="waxsc-filter-search" name="waxsc_q" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Artist, album, catalog number', 'wax-store-catalog' ) . '" autocomplete="off" enterkeyhint="search" data-waxsc-search></p>';
		$html  .= self::filter_select( Taxonomies::FORMAT, __( 'Format', 'wax-store-catalog' ), __( 'All formats', 'wax-store-catalog' ), $formats, $current_format );
		$html .= self::filter_select( Taxonomies::GENRE, __( 'Genre', 'wax-store-catalog' ), __( 'All genres', 'wax-store-catalog' ), $genres, $current_genre );
		$html .= self::style_select( $genre_term instanceof \WP_Term ? $genre_term : null, $current_style );

		$sort  = CatalogQuery::current_sort();
		$html .= self::filter_select( 'waxsc_sort', __( 'Sort by', 'wax-store-catalog' ), '', CatalogQuery::sort_options(), $sort === CatalogQuery::default_sort() ? '' : $sort, CatalogQuery::default_sort() );

		$hide_sold = 'hide' === get_query_var( 'waxsc_sold' );

		if ( 'show' === CatalogQuery::sold_setting() ) {
			$html .= '<p class="waxsc-filters__field waxsc-filters__field--check"><label><input type="checkbox" name="waxsc_sold" value="hide"' . checked( $hide_sold, true, false ) . '> ' . esc_html__( 'Hide sold items', 'wax-store-catalog' ) . '</label></p>';
		}

		if ( '' !== $letter ) {
			$html .= '<input type="hidden" name="waxsc_letter" value="' . esc_attr( $letter ) . '">';
		}

		$html .= '<button type="submit" class="waxsc-filters__submit wp-element-button">' . esc_html__( 'Filter', 'wax-store-catalog' ) . '</button>';

		if ( '' !== $search || '' !== $current_format || '' !== $current_genre || '' !== $current_style || '' !== $letter || $sort !== CatalogQuery::default_sort() || $hide_sold ) {
			$html .= '<a class="waxsc-filters__reset" href="' . esc_url( $action ) . '">' . esc_html__( 'Clear filters', 'wax-store-catalog' ) . '</a>';
		}

		$html .= '<p class="screen-reader-text" aria-live="polite" data-waxsc-status></p></form>';

		return self::wrap( $html, 'waxsc-filters' );
	}

	/**
	 * Sub-genre select, only when the chosen genre has sub-genres with releases.
	 * Always printed as a wrapper so the script can swap it after a change.
	 *
	 * @param \WP_Term|null $genre   Chosen top-level genre.
	 * @param string        $current Chosen sub-genre slug.
	 */
	private static function style_select( $genre, string $current ): string {
		$options = array();

		if ( $genre instanceof \WP_Term ) {
			$children = get_terms(
				array(
					'taxonomy'   => Taxonomies::GENRE,
					'parent'     => $genre->term_id,
					'hide_empty' => true,
				)
			);

			foreach ( is_array( $children ) ? $children : array() as $child ) {
				$options[ $child->slug ] = $child->name;
			}
		}

		$select = self::filter_select( 'waxsc_style', _x( 'Sub-genre', 'catalog filter', 'wax-store-catalog' ), __( 'All sub-genres', 'wax-store-catalog' ), $options, isset( $options[ $current ] ) ? $current : '' );

		return '<div class="waxsc-filters__style" data-waxsc-style>' . $select . '</div>';
	}

	/**
	 * One filter select.
	 *
	 * @param string $name    Query var.
	 * @param string $label   Label.
	 * @param string $all     "All" option.
	 * @param array  $options Slug => name.
	 * @param string $current Selected slug.
	 * @param string $default Option sent as an empty value (keeps default URLs clean).
	 */
	private static function filter_select( string $name, string $label, string $all, array $options, string $current, string $default = '' ): string {
		if ( ! $options ) {
			return '';
		}

		$id   = 'waxsc-filter-' . $name;
		$html = '<p class="waxsc-filters__field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';

		if ( '' !== $all ) {
			$html .= '<option value="">' . esc_html( $all ) . '</option>';
		}

		foreach ( $options as $slug => $option ) {
			$value = $slug === $default ? '' : $slug;
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $option ) . '</option>';
		}

		return $html . '</select></p>';
	}

	/**
	 * A to Z artist index. Letters without artists are shown but not linked.
	 *
	 * @param array          $attributes Attributes.
	 * @param \WP_Block|null $block      Block.
	 */
	public static function artist_index( array $attributes, $block = null ): string {
		$post_type = waxsc()->commerce()->get_catalog_post_type();
		$base      = get_post_type_archive_link( $post_type );

		if ( ! $base ) {
			return '';
		}

		$available = CatalogQuery::available_letters();
		$current   = self::current_letter();
		$letters   = array_merge( array( '#' ), range( 'A', 'Z' ) );
		$items     = '';

		foreach ( $letters as $letter ) {
			$label = '#' === $letter ? __( 'Artists starting with a digit or symbol', 'wax-store-catalog' ) : '';

			if ( ! isset( $available[ $letter ] ) ) {
				$items .= '<li><span class="waxsc-index__letter is-empty" aria-hidden="true">' . esc_html( $letter ) . '</span></li>';
				continue;
			}

			$url     = add_query_arg( 'waxsc_letter', '#' === $letter ? '0' : strtolower( $letter ), $base );
			$current_attr = ( $current === $letter ) ? ' aria-current="page"' : '';
			$items  .= sprintf(
				'<li><a class="waxsc-index__letter%1$s" href="%2$s"%3$s%4$s>%5$s</a></li>',
				$current === $letter ? ' is-current' : '',
				esc_url( $url ),
				$current_attr,
				'' !== $label ? ' aria-label="' . esc_attr( $label ) . '"' : '',
				esc_html( $letter )
			);
		}

		return self::wrap( '<ul class="waxsc-index__list">' . $items . '</ul>', 'waxsc-index', 'nav' );
	}

	/**
	 * Current term slug of a taxonomy, from the query var or the archive being viewed.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	private static function current_term( string $taxonomy ): string {
		$value = get_query_var( $taxonomy );

		if ( is_string( $value ) && '' !== $value ) {
			return sanitize_title( $value );
		}

		return '';
	}

	/**
	 * Current artist letter, "#" for digits and symbols.
	 */
	public static function current_letter(): string {
		$letter = (string) get_query_var( 'waxsc_letter' );

		if ( '' === $letter ) {
			return '';
		}

		return '0' === $letter ? '#' : strtoupper( substr( $letter, 0, 1 ) );
	}

	/**
	 * Comma-separated term names.
	 *
	 * @param int    $post_id  Release ID.
	 * @param string $taxonomy Taxonomy.
	 */
	private static function term_names( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );

		return is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
	}

	/**
	 * Escaped term links.
	 *
	 * @param int    $post_id  Release ID.
	 * @param string $taxonomy Taxonomy.
	 */
	private static function term_links( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( ! is_array( $terms ) ) {
			return '';
		}

		$links = array();

		foreach ( $terms as $term ) {
			$link    = get_term_link( $term );
			$links[] = is_wp_error( $link ) ? esc_html( $term->name ) : '<a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>';
		}

		return implode( ', ', $links );
	}

	/**
	 * "VG+ / VG+", or with full words for the release page.
	 *
	 * @param array $copy Copy.
	 * @param bool  $long Use full grade names.
	 */
	private static function grades_text( array $copy, bool $long = false ): string {
		if ( '' === $copy['media'] && '' === $copy['sleeve'] ) {
			return '';
		}

		if ( ! $long || Grading::MODE_FREE === Grading::mode() ) {
			return trim( $copy['media'] . ' / ' . $copy['sleeve'], ' /' );
		}

		$media  = Grading::media_grades()[ $copy['media'] ] ?? $copy['media'];
		$sleeve = Grading::sleeve_grades()[ $copy['sleeve'] ] ?? $copy['sleeve'];
		$parts  = array();

		if ( '' !== $copy['media'] ) {
			/* translators: %s: media grade, e.g. "VG+ · Very Good Plus". */
			$parts[] = sprintf( __( 'Record: %s', 'wax-store-catalog' ), $media );
		}

		if ( '' !== $copy['sleeve'] ) {
			/* translators: %s: sleeve grade. */
			$parts[] = sprintf( __( 'Sleeve: %s', 'wax-store-catalog' ), $sleeve );
		}

		return implode( ', ', $parts );
	}
}
