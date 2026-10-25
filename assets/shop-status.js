/**
 * Wax Store Catalog: "Open today" status, recomputed every minute in the
 * shop time zone, so cached pages stay right.
 */
( function () {
	'use strict';

	const KEYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
	const SHORT = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 };

	function nowIn( timezone, date ) {
		const offset = /^([+-])(\d{2}):(\d{2})$/.exec( timezone );

		if ( offset ) {
			const shifted = new Date( date.getTime() + ( offset[ 1 ] === '-' ? -1 : 1 ) * ( +offset[ 2 ] * 60 + +offset[ 3 ] ) * 60000 );
			return { day: ( shifted.getUTCDay() + 6 ) % 7, minutes: shifted.getUTCHours() * 60 + shifted.getUTCMinutes(), iso: shifted.toISOString().slice( 0, 10 ) };
		}

		const parts = {};
		new Intl.DateTimeFormat( 'en-GB', { timeZone: timezone, weekday: 'short', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' } )
			.formatToParts( date )
			.forEach( function ( part ) {
				parts[ part.type ] = part.value;
			} );

		return { day: SHORT[ parts.weekday ], minutes: +parts.hour * 60 + +parts.minute, iso: parts.year + '-' + parts.month + '-' + parts.day };
	}

	function toMinutes( time ) {
		const p = time.split( ':' );
		return +p[ 0 ] * 60 + +p[ 1 ];
	}

	function addDays( iso, days ) {
		const d = new Date( iso + 'T12:00:00Z' );
		d.setUTCDate( d.getUTCDate() + days );
		return d.toISOString().slice( 0, 10 );
	}

	function compute( data, date ) {
		const now = nowIn( data.timezone || 'UTC', date );
		const t = data.i18n;
		const closure = data.closure || {};
		const closedOn = function ( iso ) {
			return closure.from && iso >= closure.from && iso <= closure.until;
		};

		if ( closedOn( now.iso ) ) {
			return null; // Keep the server message, which holds a formatted date.
		}

		const today = data.days[ KEYS[ now.day ] ] || [];

		for ( let i = 0; i < today.length; i++ ) {
			if ( now.minutes < toMinutes( today[ i ][ 0 ] ) ) {
				return { state: 'closed', text: ( i === 0 ? t.opensToday : t.reopensAt ).replace( '%s', data.times[ today[ i ][ 0 ] ] ) };
			}

			if ( now.minutes < toMinutes( today[ i ][ 1 ] ) ) {
				return { state: 'open', text: t.openUntil.replace( '%s', data.times[ today[ i ][ 1 ] ] ) };
			}
		}

		for ( let offset = 1; offset <= 7; offset++ ) {
			const key = KEYS[ ( now.day + offset ) % 7 ];
			const ranges = data.days[ key ] || [];

			if ( ! ranges.length || closedOn( addDays( now.iso, offset ) ) ) {
				continue;
			}

			const time = data.times[ ranges[ 0 ][ 0 ] ];

			return {
				state: 'closed',
				text: offset === 1 ? t.opensTomorrow.replace( '%s', time ) : t.opensOn.replace( '%1$s', data.names[ key ] ).replace( '%2$s', time ),
			};
		}

		if ( closure.from && closure.from > now.iso ) {
			return null; // A closure starts within the week: keep the server message.
		}

		return { state: 'closed', text: t.closed };
	}

	if ( typeof module !== 'undefined' ) {
		module.exports = compute;
		return;
	}

	function refresh() {
		document.querySelectorAll( '[data-waxsc-status]' ).forEach( function ( element ) {
			let data;

			try {
				data = JSON.parse( element.getAttribute( 'data-waxsc-status' ) );
			} catch ( e ) {
				return;
			}

			const status = compute( data, new Date() );

			if ( ! status ) {
				return;
			}

			element.classList.toggle( 'is-open', status.state === 'open' );
			element.classList.toggle( 'is-closed', status.state !== 'open' );
			element.querySelector( '.waxsc-status__text' ).textContent = status.text;
		} );
	}

	refresh();
	window.setInterval( refresh, 60000 );
}() );
