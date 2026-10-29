/**
 * Wax Store Catalog: fresh nonce for the public forms, fetched when the
 * visitor starts filling a form in (cached pages may hold an old one).
 */
( function () {
	'use strict';

	const config = window.waxscFormNonce;

	if ( ! config ) {
		return;
	}

	document.querySelectorAll( '[data-waxsc-nonce-action]' ).forEach( function ( field ) {
		const form = field.form;

		if ( ! form ) {
			return;
		}

		form.addEventListener(
			'focusin',
			function () {
				const body = new FormData();
				body.append( 'action', 'waxsc_fresh_nonce' );
				body.append( 'form', field.getAttribute( 'data-waxsc-nonce-action' ) );

				fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( json ) {
						if ( json && json.success ) {
							field.value = json.data.nonce;
						}
					} )
					.catch( function () {} );
			},
			{ once: true }
		);
	} );
}() );
