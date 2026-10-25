/**
 * Wax Store Catalog: settings screen, live switch between individual and professional.
 */
( function () {
	'use strict';

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( '[data-waxsc-owner-type]' ) ) {
			document.body.classList.toggle( 'waxsc-individual', 'individual' === event.target.value );
		}
	} );
}() );
