/**
 * Wax Store Catalog: release edit screen.
 */
( function () {
	'use strict';

	const config = window.waxscAdmin || { formatKinds: {}, i18n: {} };

	/* Repeaters: add, move and remove rows. */
	function initRepeater( root ) {
		const list = root.querySelector( '[data-waxsc-rows]' );
		const template = root.querySelector( 'template' );
		let counter = Date.now();

		root.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-waxsc-action]' );

			if ( ! button || ! root.contains( button ) ) {
				return;
			}

			event.preventDefault();
			const action = button.dataset.waxscAction;

			if ( 'add' === action ) {
				list.insertAdjacentHTML( 'beforeend', template.innerHTML.replace( /__INDEX__/g, String( counter++ ) ) );
				const field = list.lastElementChild.querySelector( 'input:not([type="hidden"]), select' );

				if ( field ) {
					field.focus();
				}

				return;
			}

			const row = button.closest( '[data-waxsc-row]' );

			if ( ! row ) {
				return;
			}

			if ( 'remove' === action ) {
				row.remove();
			} else if ( 'up' === action && row.previousElementSibling ) {
				row.parentNode.insertBefore( row, row.previousElementSibling );
				button.focus();
			} else if ( 'down' === action && row.nextElementSibling ) {
				row.parentNode.insertBefore( row.nextElementSibling, row );
				button.focus();
			}
		} );
	}

	document.querySelectorAll( '[data-waxsc-repeater]' ).forEach( initRepeater );

	/* Barcode check digit (EAN-8, UPC-A, EAN-13). */
	function isValidGtin( digits ) {
		if ( [ 8, 12, 13 ].indexOf( digits.length ) === -1 ) {
			return false;
		}

		let sum = 0;
		let weight = 3;

		for ( let i = digits.length - 2; i >= 0; i-- ) {
			sum += Number( digits[ i ] ) * weight;
			weight = 3 === weight ? 1 : 3;
		}

		return ( 10 - ( sum % 10 ) ) % 10 === Number( digits[ digits.length - 1 ] );
	}

	const barcode = document.querySelector( '[data-waxsc-barcode]' );

	if ( barcode ) {
		const status = document.getElementById( barcode.getAttribute( 'aria-describedby' ) );

		barcode.addEventListener( 'input', function () {
			const digits = barcode.value.replace( /\D+/g, '' );

			if ( ! status ) {
				return;
			}

			if ( '' === digits ) {
				status.textContent = '';
				status.className = 'waxsc-barcode-status';
				return;
			}

			const valid = isValidGtin( digits );
			status.textContent = valid ? config.i18n.barcodeValid : config.i18n.barcodeInvalid;
			status.className = 'waxsc-barcode-status ' + ( valid ? 'is-valid' : 'is-invalid' );
		} );
	}

	/* Adapt the screen to the selected format: merchandise has no tracklist nor grades. */
	function selectedFormatIds() {
		const isBlockEditor = document.body.classList.contains( 'block-editor-page' );

		if ( isBlockEditor && window.wp && window.wp.data ) {
			const editor = window.wp.data.select( 'core/editor' );
			return ( editor && editor.getEditedPostAttribute( config.formatRestBase ) ) || [];
		}

		return Array.prototype.map.call(
			document.querySelectorAll( 'input[name="tax_input[' + config.formatTaxonomy + '][]"]:checked' ),
			function ( input ) {
				return Number( input.value );
			}
		);
	}

	let lastIds = null;

	function applyFormat() {
		const ids = selectedFormatIds();
		const signature = ids.join( ',' );

		if ( signature === lastIds ) {
			return;
		}

		lastIds = signature;

		const kinds = ids.map( function ( id ) {
			return config.formatKinds[ id ];
		} ).filter( Boolean );

		const merchandiseOnly = kinds.length > 0 && kinds.every( function ( kind ) {
			return 'merchandise' === kind;
		} );

		document.body.classList.toggle( 'waxsc-is-merchandise', merchandiseOnly );
	}

	applyFormat();

	if ( window.wp && window.wp.data && document.body.classList.contains( 'block-editor-page' ) ) {
		window.wp.data.subscribe( applyFormat );
	} else {
		document.addEventListener( 'change', function ( event ) {
			if ( event.target.name === 'tax_input[' + config.formatTaxonomy + '][]' ) {
				applyFormat();
			}
		} );
	}
}() );
