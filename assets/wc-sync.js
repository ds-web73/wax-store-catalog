/**
 * Wax Store Catalog: "Convert now" button of the WooCommerce notice.
 */
( function () {
	'use strict';

	const config = window.waxscWcSync;
	const notice = document.querySelector( '[data-waxsc-wc-sync]' );

	if ( ! config || ! notice ) {
		return;
	}

	const button = notice.querySelector( 'button' );
	const text = notice.querySelector( 'p' );

	function step() {
		const body = new FormData();
		body.append( 'action', 'waxsc_wc_sync_now' );
		body.append( 'nonce', config.nonce );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json.success ) {
					return;
				}

				if ( json.data.left > 0 ) {
					text.firstChild.textContent = config.i18n.left.replace( '%d', json.data.left ) + ' ';
					window.setTimeout( step, json.data.busy ? 3000 : 0 );
				} else {
					text.textContent = config.i18n.done;
					notice.className = 'notice notice-success';
				}
			} );
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		step();
	} );
}() );
