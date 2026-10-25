/**
 * Wax Store Catalog: release gallery in the editor.
 */
( function ( $, wp ) {
	'use strict';

	const box = document.querySelector( '[data-waxsc-gallery]' );

	if ( ! box || ! wp || ! wp.media ) {
		return;
	}

	const list = box.querySelector( 'ul' );
	const field = box.querySelector( 'input[name="waxsc_gallery"]' );
	const i18n = window.waxscGallery || {};
	let frame = null;

	function sync() {
		field.value = Array.prototype.map.call( list.children, function ( item ) {
			return item.dataset.id;
		} ).join( ',' );
	}

	function addItem( attachment ) {
		if ( list.querySelector( '[data-id="' + attachment.id + '"]' ) ) {
			return;
		}

		const sizes = attachment.sizes || {};
		const source = ( sizes.thumbnail || sizes.full || {} ).url || attachment.url;
		const item = document.createElement( 'li' );
		const image = document.createElement( 'img' );
		const remove = document.createElement( 'button' );

		item.dataset.id = attachment.id;
		image.src = source;
		image.alt = attachment.alt || '';
		remove.type = 'button';
		remove.className = 'button-link button-link-delete';
		remove.setAttribute( 'data-waxsc-gallery-remove', '' );
		remove.setAttribute( 'aria-label', i18n.remove || '' );
		remove.textContent = '\u00d7';

		item.appendChild( image );
		item.appendChild( remove );
		list.appendChild( item );
	}

	box.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( '[data-waxsc-gallery-remove]' ) ) {
			event.target.closest( 'li' ).remove();
			sync();
			return;
		}

		if ( ! event.target.closest( '[data-waxsc-gallery-add]' ) ) {
			return;
		}

		if ( ! frame ) {
			frame = wp.media( {
				title: i18n.title,
				button: { text: i18n.button },
				library: { type: 'image' },
				multiple: 'add',
			} );

			frame.on( 'select', function () {
				frame.state().get( 'selection' ).each( function ( attachment ) {
					addItem( attachment.toJSON() );
				} );
				sync();
			} );
		}

		frame.open();
	} );
}( window.jQuery, window.wp ) );
