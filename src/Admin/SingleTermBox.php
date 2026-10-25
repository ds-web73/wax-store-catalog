<?php
/**
 * Classic editor: radio buttons for single-choice taxonomies.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Admin;

use WaxStore\Catalog\Content\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the checkbox list of hierarchical taxonomies with radio buttons.
 * Saving is handled by core through tax_input.
 */
final class SingleTermBox {

	/**
	 * Meta box callback.
	 *
	 * @param \WP_Post $post Post.
	 * @param array    $box  Meta box arguments.
	 */
	public static function render( \WP_Post $post, array $box ): void {
		$taxonomy = $box['args']['taxonomy'] ?? '';
		$object   = get_taxonomy( $taxonomy );

		if ( ! $object ) {
			return;
		}

		$current = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
		$current = is_array( $current ) && $current ? (int) $current[0] : 0;
		$name    = 'tax_input[' . $taxonomy . '][]';
		$can     = current_user_can( $object->cap->assign_terms );

		printf( '<input type="hidden" name="%s" value="0">', esc_attr( $name ) );
		printf( '<fieldset class="waxsc-single-term"><legend class="screen-reader-text">%s</legend>', esc_html( $object->labels->singular_name ) );

		printf(
			'<label class="waxsc-single-term__item"><input type="radio" name="%1$s" value="0"%2$s%3$s> %4$s</label>',
			esc_attr( $name ),
			checked( $current, 0, false ),
			disabled( $can, false, false ),
			esc_html__( 'None', 'wax-store-catalog' )
		);

		foreach ( Taxonomies::hierarchical_options( $taxonomy ) as $term_id => $label ) {
			printf(
				'<label class="waxsc-single-term__item"><input type="radio" name="%1$s" value="%2$d"%3$s%4$s> %5$s</label>',
				esc_attr( $name ),
				(int) $term_id,
				checked( $current, $term_id, false ),
				disabled( $can, false, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';
	}
}
