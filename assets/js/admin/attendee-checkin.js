/**
 * Attendee check-in toggle for the Attendees screen.
 *
 * Handles the per-row Check In / Undo buttons. Logged-in staff only — the
 * request is capability- and nonce-checked server side.
 *
 * @since 1.1.2
 * @package NetterTechEvents
 */
( function() {
	'use strict';

	const config = window.nettertechEventsCheckIn || {};
	const i18n = config.i18n || {};

	/**
	 * Announce a message to assistive technology.
	 *
	 * @param {string} message Message to announce.
	 */
	function announce( message ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message );
		}
	}

	/**
	 * Update a row's check-in cell and button to reflect the new state.
	 *
	 * @param {HTMLElement} button  The toggle button that was activated.
	 * @param {Object}      payload Server response data.
	 */
	function applyState( button, payload ) {
		const row = button.closest( 'tr' );
		if ( ! row ) {
			return;
		}

		const cell = row.querySelector( '.column-checkin .nte-checkin-state' );
		if ( cell ) {
			cell.textContent = payload.label;
			cell.className = 'nte-checkin-state ' + ( payload.checkedIn ? 'checkin-yes' : 'checkin-no' );
		}

		button.dataset.state = payload.checkedIn ? 'out' : 'in';
		button.textContent = payload.checkedIn ? i18n.undo : i18n.checkIn;
		button.setAttribute(
			'aria-label',
			( payload.checkedIn ? i18n.undoFor : i18n.checkInFor ).replace( '%s', button.dataset.attendeeName || '' )
		);
	}

	/**
	 * Hand the server's progress figures to the sticky progress row.
	 *
	 * A failed request passes null, which marks the row out of date rather than
	 * leaving the last figure looking confirmed.
	 *
	 * @param {Object|null} progress CheckInProgress payload, or null on failure.
	 */
	function publishProgress( progress ) {
		document.dispatchEvent(
			new CustomEvent( 'nettertech-events:check-in-progress', {
				detail: { progress: progress || null },
			} )
		);
	}

	/**
	 * Send the check-in toggle request.
	 *
	 * @param {HTMLElement} button The toggle button that was activated.
	 */
	async function toggleCheckIn( button ) {
		const attendeeId = button.dataset.attendeeId;
		const state = button.dataset.state;

		if ( ! attendeeId || ! state || 'true' === button.getAttribute( 'aria-disabled' ) ) {
			return;
		}

		// aria-disabled, not disabled: a disabled button drops keyboard focus to
		// <body>, which throws the operator out of their place in the list.
		button.setAttribute( 'aria-disabled', 'true' );

		const body = new URLSearchParams();
		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce || '' );
		body.append( 'attendee_id', attendeeId );
		body.append( 'state', state );

		try {
			const response = await fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );
			const payload = await response.json();

			if ( ! payload || ! payload.success ) {
				const message = ( payload && payload.data && payload.data.message ) || i18n.error;
				publishProgress( null );
				announce( message );
				return;
			}

			applyState( button, payload.data );
			publishProgress( payload.data.progress );
			announce( payload.data.checkedIn ? i18n.checkedInAnnounce : i18n.undoneAnnounce );
		} catch ( error ) {
			publishProgress( null );
			announce( i18n.error );
		} finally {
			button.removeAttribute( 'aria-disabled' );
		}
	}

	/**
	 * Initialize the delegated click handler.
	 */
	function init() {
		const table = document.querySelector( '.nte-attendees-table' );
		if ( ! table ) {
			return;
		}

		table.addEventListener( 'click', function( event ) {
			const button = event.target.closest( '.nte-checkin-toggle' );
			if ( ! button ) {
				return;
			}

			event.preventDefault();
			toggleCheckIn( button );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
