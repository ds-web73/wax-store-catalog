/**
 * Wax Store Catalog: automatic covers, one release per request.
 */
( function () {
	'use strict';

	const config = window.waxscCovers;
	const form = document.getElementById( 'waxsc-covers-form' );

	if ( ! config || ! form ) {
		return;
	}

	const start = form.querySelector( '[data-waxsc-start]' );
	const stop = form.querySelector( '[data-waxsc-stop]' );
	const box = document.getElementById( 'waxsc-covers-progress' );
	const status = box.querySelector( '[data-waxsc-status]' );
	const summary = box.querySelector( '[data-waxsc-summary]' );
	const log = box.querySelector( '[data-waxsc-log]' );
	let running = false;
	let left = 0;
	let after = 0;
	const counts = { found: 0, group: 0, none: 0 };

	function post( action, fields ) {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( 'ajax' );
				}
				return j.data;
			} );
	}

	function options() {
		return {
			retry: document.getElementById( 'waxsc-covers-retry' ).checked ? '1' : '',
			group: document.getElementById( 'waxsc-covers-group' ).checked ? '1' : '',
		};
	}

	function show() {
		status.textContent = config.i18n.left.replace( '%d', Math.max( 0, left ) );
		summary.textContent = config.i18n.summary.replace( '%1$d', counts.found ).replace( '%2$d', counts.group ).replace( '%3$d', counts.none );
	}

	function finish( message ) {
		running = false;
		start.disabled = false;
		stop.hidden = true;
		status.textContent = message;
	}

	function step() {
		if ( ! running ) {
			finish( config.i18n.stopped );
			return;
		}

		const fields = options();
		fields.after = String( after );

		post( 'waxsc_covers_next', fields )
			.then( function ( result ) {
				if ( 'done' === result.status ) {
					finish( config.i18n.done );
					return;
				}

				const wait = ( 'rate' === result.status || 'http' === result.status ) ? 5000 : 0;

				if ( ! wait ) {
					after = result.release;
					left--;
					if ( 'found' === result.status ) {
						counts.found++;
					} else if ( 'found_group' === result.status ) {
						counts.group++;
					} else {
						counts.none++;
					}
				}

				const item = document.createElement( 'li' );
				item.textContent = result.message;
				log.insertBefore( item, log.firstChild );
				show();
				window.setTimeout( step, wait );
			} )
			.catch( function () {
				window.setTimeout( step, 5000 );
			} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		running = true;
		after = 0;
		start.disabled = true;
		stop.hidden = false;
		box.hidden = false;

		post( 'waxsc_covers_count', options() ).then( function ( data ) {
			left = data.left;
			show();
			step();
		} );
	} );

	stop.addEventListener( 'click', function () {
		running = false;
		stop.hidden = true;
	} );
}() );
