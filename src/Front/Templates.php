<?php
/**
 * Default block templates for the catalog (block themes, WordPress 6.7+).
 * A theme overrides any of them with a file of the same name in /templates.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Front;

use WaxStore\Catalog\Content\PostType;
use WaxStore\Catalog\Content\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Templates.
 */
final class Templates {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 30 );
		add_filter( 'template_include', array( self::class, 'classic' ), 99 );
	}

	/**
	 * Classic themes do not use block templates: the release page, the catalog
	 * and its archives get the same content, between the theme header and footer.
	 *
	 * @param string $template Template chosen by WordPress.
	 */
	public static function classic( string $template ): string {
		if ( wp_is_block_theme() || PostType::NAME !== waxsc()->commerce()->get_catalog_post_type() ) {
			return $template;
		}

		$taxonomies = array( Taxonomies::FORMAT, Taxonomies::GENRE, Taxonomies::ARTIST, Taxonomies::LABEL, Taxonomies::COUNTRY, Taxonomies::CONDITION, Taxonomies::EDITION, Taxonomies::FEATURE );

		if ( is_singular( PostType::NAME ) ) {
			self::$classic_content = self::body( self::single() );
		} elseif ( is_post_type_archive( PostType::NAME ) ) {
			self::$classic_content = self::body( self::archive() );
		} elseif ( is_tax( $taxonomies ) ) {
			self::$classic_content = self::body( self::archive( true ) );
		} else {
			return $template;
		}

		/**
		 * Lets a theme use its own file for the catalog pages.
		 *
		 * @param string $file Template file of the plugin.
		 */
		return (string) apply_filters( 'waxsc_classic_template', dirname( __DIR__, 2 ) . '/templates/classic.php' );
	}

	/**
	 * Block markup rendered by the classic template.
	 *
	 * @var string
	 */
	private static string $classic_content = '';

	/**
	 * Renders the catalog content for classic themes.
	 */
	public static function render_classic(): string {
		if ( is_singular() ) {
			the_post();
		}

		$html = do_blocks( self::$classic_content );

		if ( is_singular() ) {
			rewind_posts();
		}

		return $html;
	}

	/**
	 * Template markup without the header and footer template parts.
	 *
	 * @param string $markup Template markup.
	 */
	private static function body( string $markup ): string {
		$markup = (string) preg_replace( '/<!-- wp:template-part [^>]*\/-->\s*/', '', $markup );

		// Classic themes already open their own <main>: one landmark only.
		$markup = str_replace( array( '"tagName":"main",', '<main class="wp-block-group', '</main>' ), array( '', '<div class="wp-block-group', '</div>' ), $markup );

		return trim( $markup );
	}

	/**
	 * Registers the single release, catalog and taxonomy templates.
	 */
	public static function register(): void {
		if ( ! function_exists( 'register_block_template' ) || PostType::NAME !== waxsc()->commerce()->get_catalog_post_type() ) {
			return;
		}

		register_block_template(
			'wax-store-catalog//single-' . PostType::NAME,
			array(
				'title'       => __( 'Single release', 'wax-store-catalog' ),
				'description' => __( 'A release page: cover, details, copies for sale and tracklist.', 'wax-store-catalog' ),
				'content'     => self::single(),
			)
		);

		register_block_template(
			'wax-store-catalog//archive-' . PostType::NAME,
			array(
				'title'       => __( 'Catalog', 'wax-store-catalog' ),
				'description' => __( 'All releases, with the artist index and the format and genre filters.', 'wax-store-catalog' ),
				'content'     => self::archive(),
			)
		);

		$taxonomies = array( Taxonomies::FORMAT, Taxonomies::GENRE, Taxonomies::ARTIST, Taxonomies::LABEL, Taxonomies::COUNTRY, Taxonomies::CONDITION, Taxonomies::EDITION, Taxonomies::FEATURE );

		foreach ( $taxonomies as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( ! $object ) {
				continue;
			}

			register_block_template(
				'wax-store-catalog//taxonomy-' . $taxonomy,
				array(
					/* translators: %s: taxonomy name, e.g. "Genres". */
					'title'       => sprintf( __( 'Catalog: %s', 'wax-store-catalog' ), $object->labels->name ),
					'description' => __( 'Releases of one artist, label, genre, format…', 'wax-store-catalog' ),
					'content'     => self::archive( true ),
				)
			);
		}
	}

	/**
	 * Single release template.
	 */
	private static function single(): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","className":"waxsc-release","layout":{"type":"constrained","contentSize":"1200px"}} -->
<main class="wp-block-group waxsc-release">
<!-- wp:columns {"className":"waxsc-release__columns"} -->
<div class="wp-block-columns waxsc-release__columns">
<!-- wp:column {"width":"45%","className":"waxsc-release__cover"} -->
<div class="wp-block-column waxsc-release__cover" style="flex-basis:45%">
<!-- wp:post-featured-image {"aspectRatio":"1","scale":"contain"} /-->
<!-- wp:waxsc/release-gallery /-->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"waxsc-release__body"} -->
<div class="wp-block-column waxsc-release__body">
<!-- wp:post-terms {"term":"' . Taxonomies::ARTIST . '","className":"waxsc-artist"} /-->
<!-- wp:post-title {"level":1} /-->
<!-- wp:waxsc/release-copies /-->
<!-- wp:waxsc/release-details /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
<!-- wp:waxsc/release-tracklist /-->
<!-- wp:post-content {"layout":{"type":"constrained"}} /-->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}

	/**
	 * Catalog and taxonomy archive template.
	 *
	 * @param bool $taxonomy Taxonomy archive (shows the term title and description).
	 */
	private static function archive( bool $taxonomy = false ): string {
		$title = $taxonomy
			? '<!-- wp:group {"className":"waxsc-divider","layout":{"type":"default"}} -->
<div class="wp-block-group waxsc-divider">
<!-- wp:query-title {"type":"archive","showPrefix":false} /-->
</div>
<!-- /wp:group -->
<!-- wp:term-description /-->
<!-- wp:waxsc/alert-form /-->'
			: '<!-- wp:group {"className":"waxsc-divider","layout":{"type":"default"}} -->
<div class="wp-block-group waxsc-divider">
<!-- wp:heading {"level":1,"className":"waxsc-catalog-title"} -->
<h1 class="wp-block-heading waxsc-catalog-title">' . esc_html__( 'Catalog', 'wax-store-catalog' ) . '</h1>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->';

		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","className":"waxsc-catalog","layout":{"type":"constrained","contentSize":"1200px"}} -->
<main class="wp-block-group waxsc-catalog">
' . $title . '
<!-- wp:waxsc/artist-index /-->
<!-- wp:waxsc/catalog-filters /-->
<!-- wp:query {"queryId":1,"query":{"inherit":true},"className":"waxsc-results"} -->
<div class="wp-block-query waxsc-results">
<!-- wp:post-template {"className":"waxsc-grid","layout":{"type":"grid","minimumColumnWidth":"11rem"}} -->
<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"1","scale":"cover"} /-->
<!-- wp:waxsc/release-price /-->
<!-- wp:post-terms {"term":"' . Taxonomies::ARTIST . '","className":"waxsc-artist"} /-->
<!-- wp:post-title {"isLink":true,"level":2,"className":"waxsc-card-title"} /-->
<!-- wp:waxsc/release-summary /-->
<!-- /wp:post-template -->
<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->
<!-- wp:query-pagination-previous /-->
<!-- wp:query-pagination-numbers /-->
<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination -->
<!-- wp:query-no-results -->
<!-- wp:paragraph -->
<p>' . esc_html__( 'No release matches these filters. Try another format or genre, or clear the filters.', 'wax-store-catalog' ) . '</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}
}
