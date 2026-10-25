/**
 * Wax Store Catalog: filters update the results without reloading the page.
 * Without JavaScript, the form submits normally.
 */
( function () {
	'use strict';

	const form = document.querySelector( '[data-waxsc-filters]' );
	const results = document.querySelector( '.waxsc-results' );

	if ( ! form ) {
		return;
	}

	// Outside the catalog (home page…), a choice opens the catalog with that filter.
	if ( ! results ) {
		form.classList.add( 'is-enhanced' );
		form.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-waxsc-search]' ) ) {
				return;
			}

			const style = form.querySelector( '[name="waxsc_style"]' );

			if ( event.target.name === 'waxsc_genre' && style ) {
				style.value = '';
			}

			form.requestSubmit ? form.requestSubmit() : form.submit();
		} );
		return;
	}

	if ( ! window.fetch || ! window.DOMParser ) {
		return;
	}

	const status = form.querySelector( '[data-waxsc-status]' );
	form.classList.add( 'is-enhanced' );

	function urlFor( formElement ) {
		const url = new URL( formElement.action );
		new FormData( formElement ).forEach( function ( value, key ) {
			if ( value ) {
				url.searchParams.set( key, value );
			}
		} );
		return url.toString();
	}

	function load( url, push ) {
		results.setAttribute( 'aria-busy', 'true' );

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.text();
			} )
			.then( function ( html ) {
				const doc = new DOMParser().parseFromString( html, 'text/html' );
				const fresh = doc.querySelector( '.waxsc-results' );
				const freshForm = doc.querySelector( '[data-waxsc-filters]' );

				if ( ! fresh ) {
					window.location.href = url;
					return;
				}

				results.innerHTML = fresh.innerHTML;

				const freshStyle = freshForm && freshForm.querySelector( '[data-waxsc-style]' );
				const currentStyle = form.querySelector( '[data-waxsc-style]' );

				if ( freshStyle && currentStyle ) {
					currentStyle.replaceWith( freshStyle );
				}

				const reset = freshForm && freshForm.querySelector( '.waxsc-filters__reset' );
				const currentReset = form.querySelector( '.waxsc-filters__reset' );

				if ( currentReset ) {
					currentReset.remove();
				}

				if ( reset ) {
					form.insertBefore( reset, status );
				}

				if ( push ) {
					window.history.pushState( {}, '', url );
				}

				if ( status ) {
					status.textContent = results.querySelectorAll( '.wp-block-post' ).length + ' ' + ( form.dataset.resultsLabel || '' );
				}
			} )
			.catch( function () {
				window.location.href = url;
			} )
			.finally( function () {
				results.removeAttribute( 'aria-busy' );
			} );
	}

	const search = form.querySelector( '[data-waxsc-search]' );
	let typing = null;
	let lastSearch = search ? search.value.trim() : '';

	if ( search ) {
		search.addEventListener( 'input', function () {
			window.clearTimeout( typing );
			typing = window.setTimeout( function () {
				const value = search.value.trim();

				// Search from two characters, or when the field is emptied.
				if ( value === lastSearch || ( value.length > 0 && value.length < 2 ) ) {
					return;
				}

				lastSearch = value;
				load( urlFor( form ), true );
			}, 400 );
		} );
	}

	form.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( '[data-waxsc-search]' ) ) {
			return;
		}

		if ( event.target.name === 'waxsc_genre' ) {
			const style = form.querySelector( '[name="waxsc_style"]' );

			if ( style ) {
				style.value = '';
			}
		}

		load( urlFor( form ), true );
	} );

	form.addEventListener( 'submit', function ( event ) {
		if ( ! results ) {
			return;
		}
		event.preventDefault();
		load( urlFor( form ), true );
	} );

	window.addEventListener( 'popstate', function () {
		window.location.reload();
	} );
}() );
