/**
 * Wax Store Catalog: block editor, single-choice taxonomies as a select.
 */
( function ( wp ) {
	'use strict';

	const config = window.waxscEditor || { taxonomies: {} };
	const el = wp.element.createElement;
	const __ = wp.i18n.__;

	function SingleTermSelect( props ) {
		const taxonomy = config.taxonomies[ props.slug ];
		const restBase = taxonomy.restBase;

		const selected = wp.data.useSelect(
			function ( select ) {
				return select( 'core/editor' ).getEditedPostAttribute( restBase ) || [];
			},
			[ restBase ]
		);

		const editPost = wp.data.useDispatch( 'core/editor' ).editPost;

		const options = [ { value: '', label: __( 'None', 'wax-store-catalog' ) } ].concat( taxonomy.options );

		return el( wp.components.SelectControl, {
			label: taxonomy.label,
			hideLabelFromVision: true,
			value: selected.length ? String( selected[ selected.length - 1 ] ) : '',
			options: options,
			onChange: function ( value ) {
				const edit = {};
				edit[ restBase ] = value ? [ Number( value ) ] : [];
				editPost( edit );
			},
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
		} );
	}

	wp.hooks.addFilter( 'editor.PostTaxonomyType', 'wax-store-catalog/single-term', function ( OriginalComponent ) {
		return function ( props ) {
			if ( ! config.taxonomies[ props.slug ] ) {
				return el( OriginalComponent, props );
			}

			return el( SingleTermSelect, props );
		};
	} );
}( window.wp ) );
