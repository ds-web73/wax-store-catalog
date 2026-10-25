/**
 * Wax Store Catalog: runs the import batch after batch.
 */
( function () {
	'use strict';

	const config = window.waxscImport;
	const bar = document.getElementById( 'waxsc-import-progress' );
	const status = document.getElementById( 'waxsc-import-status' );

	if ( ! config || ! bar || ! status ) {
		return;
	}

	let failures = 0;

	function format( line, total ) {
		return config.i18n.progress.replace( '%1$d', line ).replace( '%2$d', total );
	}

	function next() {
		const body = new FormData();
		body.append( 'action', 'waxsc_import_batch' );
		body.append( 'nonce', config.nonce );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'batch' );
				}

				failures = 0;
				bar.max = json.data.total;
				bar.value = json.data.line;
				status.textContent = format( json.data.line, json.data.total );

				if ( 'running' === json.data.stage ) {
					next();
				} else {
					window.location.reload();
				}
			} )
			.catch( function () {
				failures++;

				if ( failures < 4 ) {
					window.setTimeout( next, 3000 * failures );
				} else {
					status.textContent = config.i18n.error;
				}
			} );
	}

	next();
}() );
