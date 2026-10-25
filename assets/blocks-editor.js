/**
 * Wax Store Catalog: editor side of the dynamic blocks. They render on the
 * site; in the editor a labelled placeholder shows where they sit, and
 * on/off options appear in the block settings.
 */
( function ( wp ) {
	'use strict';

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const ToggleControl = wp.components.ToggleControl;

	const names = [ 'release-price', 'release-summary', 'release-details', 'release-copies', 'release-tracklist', 'catalog-filters', 'artist-index', 'reservation-form', 'release-gallery', 'shop-status', 'alert-form', 'sell-form' ];

	const toggles = {
		'release-price': [ [ 'showAction', __( 'Show the reserve or buy button', 'wax-store-catalog' ) ] ],
		'shop-status': [
			[ 'showAddress', __( 'Show the address', 'wax-store-catalog' ) ],
			[ 'showWeek', __( 'Show the hours of the week', 'wax-store-catalog' ) ],
		],
	};

	names.forEach( function ( name ) {
		wp.blocks.registerBlockType( 'waxsc/' + name, {
			edit: function ( props ) {
				const settings = wp.blocks.getBlockType( 'waxsc/' + name );
				const controls = toggles[ name ];
				const children = [
					el(
						'div',
						useBlockProps( { className: 'waxsc-editor-placeholder', key: 'placeholder' } ),
						el( 'strong', null, settings ? settings.title : name ),
						el( 'span', null, settings ? settings.description : '' )
					),
				];

				if ( controls ) {
					children.unshift(
						el(
							InspectorControls,
							{ key: 'inspector' },
							el(
								PanelBody,
								{ title: __( 'Settings', 'wax-store-catalog' ) },
								controls.map( function ( control ) {
									return el( ToggleControl, {
										key: control[ 0 ],
										label: control[ 1 ],
										checked: !! props.attributes[ control[ 0 ] ],
										onChange: function ( value ) {
											const update = {};
											update[ control[ 0 ] ] = value;
											props.setAttributes( update );
										},
										__nextHasNoMarginBottom: true,
									} );
								} )
							)
						)
					);
				}

				return el( wp.element.Fragment, null, children );
			},
			save: function () {
				return null;
			},
		} );
	} );
}( window.wp ) );
