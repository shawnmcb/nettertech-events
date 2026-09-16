/**
 * Per-date row expansion on the All Events list.
 *
 * Progressive enhancement over a plain link: the toggle's href already asks the
 * server for the same page with this event in the `expanded` argument, so every
 * failure path here falls back to following it. On success the rows arrive
 * pre-rendered from admin-ajax and the URL is rewritten to match, keeping the
 * page bookmarkable and shareable in its open state.
 *
 * @package NetterTechEvents
 */

( function () {
	'use strict';

	const config = window.nettertechEventsDatesToggle || {};
	const i18n = config.i18n || {};
	const EXPANDED_ARG = 'expanded';

	/**
	 * Announce a state change to assistive technology.
	 *
	 * @param {string} message    Message to announce.
	 * @param {string} politeness Live-region politeness.
	 */
	function speak( message, politeness ) {
		if ( window.wp && window.wp.a11y && message ) {
			window.wp.a11y.speak( message, politeness || 'polite' );
		}
	}

	/**
	 * Every child row currently rendered for one event.
	 *
	 * @param {string} eventId Event ID.
	 * @return {NodeList} Matching rows.
	 */
	function childRows( eventId ) {
		return document.querySelectorAll(
			'tr[data-occurrence-id][data-event-id="' + CSS.escape( eventId ) + '"]'
		);
	}

	/**
	 * Read the event IDs currently named by the URL's expanded argument.
	 *
	 * @return {Array} Array of ID strings.
	 */
	function expandedFromUrl() {
		const raw = new URL( window.location.href ).searchParams.get( EXPANDED_ARG );
		if ( ! raw ) {
			return [];
		}

		return raw.split( ',' ).filter( ( id ) => id.trim() !== '' );
	}

	/**
	 * Rewrite the expanded argument in the address bar, leaving every other
	 * argument as it was. Replaces rather than pushes: expanding a row is a change
	 * of view, not a navigation the Back button should have to unwind.
	 *
	 * @param {string}  eventId  Event ID.
	 * @param {boolean} isOpen   Whether the event is now open.
	 */
	function syncUrl( eventId, isOpen ) {
		const url = new URL( window.location.href );
		let ids = expandedFromUrl().filter( ( id ) => id !== eventId );

		if ( isOpen ) {
			ids = ids.concat( [ eventId ] );
		}

		if ( ids.length === 0 ) {
			url.searchParams.delete( EXPANDED_ARG );
		} else {
			url.searchParams.set( EXPANDED_ARG, ids.join( ',' ) );
		}

		window.history.replaceState( {}, '', url.toString() );
	}

	/**
	 * Put a toggle into its closed state and drop the rows it controlled.
	 *
	 * @param {HTMLElement} link Toggle link.
	 */
	function collapse( link ) {
		const eventId = link.getAttribute( 'data-event-id' ) || '';

		childRows( eventId ).forEach( ( row ) => row.remove() );

		link.setAttribute( 'aria-expanded', 'false' );
		link.removeAttribute( 'aria-controls' );

		const chevron = link.querySelector( '.nte-dates-toggle__chevron' );
		if ( chevron ) {
			chevron.textContent = '▸';
		}

		syncUrl( eventId, false );
		speak( i18n.hidden );
	}

	/**
	 * Insert the server-rendered rows for one event after its parent row.
	 *
	 * The markup comes from our own capability- and nonce-checked admin-ajax
	 * endpoint, which escapes every cell as it renders it; it is server-authored
	 * table markup, not user data. It is parsed in an inert document rather than
	 * assigned to innerHTML, and a `<tr>` only survives parsing inside a table.
	 *
	 * @param {HTMLElement} link Toggle link.
	 * @param {Object}      data Endpoint payload.
	 */
	function insertRows( link, data ) {
		const parentRow = link.closest( 'tr' );
		if ( ! parentRow || ! data.html ) {
			return;
		}

		const parsed = new DOMParser().parseFromString(
			'<table><tbody>' + data.html + '</tbody></table>',
			'text/html'
		);

		const rows = Array.prototype.slice
			.call( parsed.querySelectorAll( 'tbody > tr' ) )
			.map( ( row ) => document.importNode( row, true ) );
		let anchor = parentRow;
		const ids = [];

		rows.forEach( ( row ) => {
			anchor.insertAdjacentElement( 'afterend', row );
			anchor = row;
			if ( row.id ) {
				ids.push( row.id );
			}
		} );

		link.setAttribute( 'aria-expanded', 'true' );
		if ( ids.length > 0 ) {
			link.setAttribute( 'aria-controls', ids.join( ' ' ) );
		}

		const chevron = link.querySelector( '.nte-dates-toggle__chevron' );
		if ( chevron ) {
			chevron.textContent = '▾';
		}

		const eventId = link.getAttribute( 'data-event-id' ) || '';
		syncUrl( eventId, true );

		const count = parseInt( data.count, 10 ) || rows.length;
		if ( i18n.shown ) {
			speak( i18n.shown.replace( '%d', String( count ) ) );
		}
	}

	/**
	 * Fetch and insert one event's date rows.
	 *
	 * @param {HTMLElement} link Toggle link.
	 */
	async function expand( link ) {
		const eventId = link.getAttribute( 'data-event-id' ) || '';

		if ( ! config.ajaxUrl || ! config.action ) {
			window.location.assign( link.href );
			return;
		}

		const url = config.ajaxUrl +
			'?action=' + encodeURIComponent( config.action ) +
			'&event_id=' + encodeURIComponent( eventId ) +
			'&nonce=' + encodeURIComponent( config.nonce || '' );

		try {
			const response = await fetch( url, { credentials: 'same-origin' } );
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}

			const payload = await response.json();
			if ( ! payload || ! payload.success ) {
				throw new Error( 'unsuccessful' );
			}

			insertRows( link, payload.data || {} );
		} catch {
			// The link renders the same rows server-side, so a failure here is a
			// slower path rather than a dead end.
			window.location.assign( link.href );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		const link = event.target.closest( '.nte-dates-toggle' );
		if ( ! link ) {
			return;
		}

		event.preventDefault();

		// Focus stays on the control across both directions so a keyboard user
		// keeps their place in the table.
		link.focus();

		if ( link.getAttribute( 'aria-expanded' ) === 'true' ) {
			collapse( link );
			return;
		}

		expand( link );
	} );
}() );
