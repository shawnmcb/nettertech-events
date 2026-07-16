/**
 * Ticket-type SKU surfacing on the All Events list and event editor (NTE-114).
 *
 * Handles two delegated interactions:
 *   - `.nte-sku-copy`     — copy a single locked SKU (event editor).
 *   - `.nte-skus-action`  — fetch an event's SKUs and open the shared dialog
 *                           (All Events list row action); "Copy all" copies the
 *                           comma-delimited list.
 *
 * Data is loaded via admin-ajax only on click (the action is carried in the URL
 * query so a Cloudflare WAF rule can scope to it without body inspection).
 */
( function () {
	'use strict';

	const config = window.nettertechEventsSkus || {};
	const i18n = config.i18n || {};
	const dialog = document.getElementById( 'nte-skus-dialog' );
	let currentCsv = '';

	function speak( message, politeness ) {
		if ( window.wp && window.wp.a11y && message ) {
			window.wp.a11y.speak( message, politeness || 'polite' );
		}
	}

	async function copyText( text, successMessage ) {
		if ( ! text ) {
			return;
		}
		if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) {
			speak( i18n.copyError, 'assertive' );
			return;
		}
		try {
			await navigator.clipboard.writeText( text );
			speak( successMessage );
		} catch {
			speak( i18n.copyError, 'assertive' );
		}
	}

	function renderDialog( data ) {
		if ( ! dialog ) {
			return;
		}

		const titleEl = dialog.querySelector( '.nte-skus-dialog__title' );
		const bodyEl = dialog.querySelector( '.nte-skus-dialog__body' );
		const copyAllBtn = dialog.querySelector( '.nte-skus-copy-all' );

		currentCsv = data.skus_csv || '';

		if ( titleEl ) {
			titleEl.textContent = data.event_title || '';
		}

		if ( bodyEl ) {
			bodyEl.textContent = '';
			const tickets = data.tickets || [];

			if ( ! tickets.length ) {
				const empty = document.createElement( 'p' );
				empty.textContent = i18n.noSkus || '';
				bodyEl.appendChild( empty );
			} else {
				const list = document.createElement( 'ul' );
				list.className = 'nte-skus-dialog__items';

				tickets.forEach( function ( ticket ) {
					const li = document.createElement( 'li' );
					const name = document.createElement( 'span' );
					name.className = 'nte-skus-dialog__name';
					name.textContent = ticket.name || '';
					const code = document.createElement( 'code' );
					code.textContent = ticket.sku || i18n.none || '';
					li.appendChild( name );
					li.appendChild( document.createTextNode( ' ' ) );
					li.appendChild( code );
					list.appendChild( li );
				} );

				bodyEl.appendChild( list );
			}
		}

		if ( copyAllBtn ) {
			copyAllBtn.disabled = ! currentCsv;
		}

		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		}
	}

	async function fetchSkus( eventId ) {
		if ( ! config.ajaxUrl || ! config.action ) {
			return;
		}

		const url = config.ajaxUrl +
			'?action=' + encodeURIComponent( config.action ) +
			'&event_id=' + encodeURIComponent( eventId ) +
			'&nonce=' + encodeURIComponent( config.nonce || '' );

		try {
			const response = await fetch( url, { credentials: 'same-origin' } );
			const payload = await response.json();
			if ( payload && payload.success ) {
				renderDialog( payload.data || {} );
			} else {
				speak( i18n.error, 'assertive' );
			}
		} catch {
			speak( i18n.error, 'assertive' );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		const copyBtn = event.target.closest( '.nte-sku-copy' );
		if ( copyBtn ) {
			event.preventDefault();
			copyText( copyBtn.getAttribute( 'data-sku' ), i18n.copied );
			return;
		}

		const skusAction = event.target.closest( '.nte-skus-action' );
		if ( skusAction ) {
			event.preventDefault();
			fetchSkus( skusAction.getAttribute( 'data-event-id' ) );
			return;
		}

		const copyAll = event.target.closest( '.nte-skus-copy-all' );
		if ( copyAll ) {
			event.preventDefault();
			copyText( currentCsv, i18n.copiedAll );
		}
	} );
}() );
