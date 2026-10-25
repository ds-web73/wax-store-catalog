/**
 * Wax Store Catalog: photo import, one file at a time.
 */
( function () {
	'use strict';

	const config = window.waxscPhotos;
	const form = document.getElementById( 'waxsc-photo-form' );

	if ( ! config || ! form ) {
		return;
	}

	const input = document.getElementById( 'waxsc-photo-files' );
	const replace = document.getElementById( 'waxsc-photo-replace' );
	const box = document.getElementById( 'waxsc-photo-progress' );
	const bar = box.querySelector( 'progress' );
	const status = box.querySelector( '[data-waxsc-status]' );
	const summary = box.querySelector( '[data-waxsc-summary]' );
	const log = box.querySelector( '[data-waxsc-log]' );
	const counts = { cover: 0, gallery: 0, skip: 0, none: 0 };

	function request( action, fields ) {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'ajax' );
				}
				return json.data;
			} );
	}

	function addLog( name, result ) {
		const row = document.createElement( 'tr' );
		const file = document.createElement( 'td' );
		const message = document.createElement( 'td' );
		file.textContent = name;
		message.textContent = result.message;
		row.appendChild( file );
		row.appendChild( message );
		log.insertBefore( row, log.firstChild );
	}

	function format( template, values ) {
		return values.reduce( function ( text, value, index ) {
			return text.replace( '%' + ( index + 1 ) + '$d', value );
		}, template );
	}

	function update( done, total ) {
		bar.max = total;
		bar.value = done;
		status.textContent = format( config.i18n.progress, [ done, total ] );
		summary.textContent = format( config.i18n.summary, [ counts.cover, counts.gallery, counts.skip, counts.none ] );
	}

	function processFile( file ) {
		return request( 'waxsc_photo_match', { name: file.name } ).then( function ( match ) {
			if ( 'found' !== match.status ) {
				counts[ 'skip' === match.status ? 'skip' : 'none' ]++;
				return match;
			}

			return request( 'waxsc_photo_upload', { file: file, replace: replace.checked ? '1' : '' } ).then( function ( result ) {
				if ( counts[ result.status ] !== undefined ) {
					counts[ result.status ]++;
				}
				return result;
			} );
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		const files = Array.prototype.slice.call( input.files ).sort( function ( a, b ) {
			return a.name.localeCompare( b.name, undefined, { numeric: true } );
		} );

		if ( ! files.length ) {
			input.focus();
			return;
		}

		form.querySelector( 'button' ).disabled = true;
		box.hidden = false;
		log.textContent = '';
		Object.keys( counts ).forEach( function ( key ) {
			counts[ key ] = 0;
		} );
		update( 0, files.length );

		let done = 0;

		const next = function () {
			if ( done >= files.length ) {
				status.textContent = config.i18n.done + ' ' + format( config.i18n.progress, [ done, files.length ] );
				form.querySelector( 'button' ).disabled = false;
				return Promise.resolve();
			}

			const file = files[ done ];

			return processFile( file )
				.then( function ( result ) {
					addLog( file.name, result );
				} )
				.catch( function () {
					addLog( file.name, { message: config.i18n.error } );
				} )
				.then( function () {
					done++;
					update( done, files.length );
					return next();
				} );
		};

		request( 'waxsc_photo_index', {} ).then( next ).catch( function () {
			status.textContent = config.i18n.error;
			form.querySelector( 'button' ).disabled = false;
		} );
	} );
}() );
