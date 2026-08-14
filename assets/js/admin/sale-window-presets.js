/**
 * Sale-window preset buttons (NTE-190).
 *
 * "Now" fills a sale-start boundary with the site's current date/time
 * (localized server-side — never the browser clock, which may sit in a
 * different timezone than the site). "At event start" copies the event's
 * start date/time fields. Fills are visible and editable; presets never
 * lock values. Delegated clicks cover ticket rows cloned after load.
 *
 * @package NetterTechEvents
 * @since 1.4.0
 */

( function() {
	'use strict';

	const config = window.nettertechEventsSaleWindowPresets || {};

	/**
	 * Set an input's value and announce it so the time-combobox display
	 * mirrors programmatic fills.
	 *
	 * @param {HTMLInputElement|null} input Target input.
	 * @param {string}                value New value.
	 */
	function fill( input, value ) {
		if ( ! input || '' === value ) {
			return;
		}
		input.value = value;
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	document.addEventListener( 'click', ( e ) => {
		const button = e.target.closest ? e.target.closest( '[data-nte-sale-preset]' ) : null;
		if ( ! button ) {
			return;
		}

		const fieldset = button.closest( '.nte-sale-window' );
		if ( ! fieldset ) {
			return;
		}
		const dateInput = fieldset.querySelector( 'input[type="date"]' );
		const timeInput = fieldset.querySelector( 'input[type="time"]' );

		if ( 'now' === button.dataset.nteSalePreset ) {
			fill( dateInput, config.nowDate || '' );
			fill( timeInput, config.nowTime || '' );
		} else if ( 'event-start' === button.dataset.nteSalePreset ) {
			const eventDate = document.querySelector( '#start_date' );
			const eventTime = document.querySelector( 'input[name="start_time"]' );
			fill( dateInput, eventDate ? eventDate.value : '' );
			fill( timeInput, eventTime ? eventTime.value : '' );
		}
	} );
} )();
