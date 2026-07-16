/**
 * Reveal the "which date survives?" choice when a recurring event is made single.
 *
 * Disclosure only. The select is rendered server-side and its value posts whether or not this
 * script runs — with no script the box is simply visible all the time, which is untidy but never
 * wrong. The one thing that must not happen is the operator converting an event without being
 * told that other dates are about to go, because that is precisely the old behaviour.
 *
 * @package NetterTechEvents
 * @since 1.1.3
 */

( function () {
	'use strict';

	var type = document.getElementById( 'event_type' );
	var box = document.getElementById( 'nettertech-events-conversion-box' );

	if ( ! type || ! box ) {
		return;
	}

	var select = box.querySelector( '#nettertech_events_keep_occurrence' );

	/**
	 * Show the choice only while "single" is selected.
	 *
	 * @param {boolean} announce Whether to speak the change (skip on first paint).
	 */
	function sync( announce ) {
		var converting = 'single' === type.value;

		// style.display, not the hidden attribute: WP admin's own rules out-specify [hidden] on
		// several elements, so a hidden box can end up visible anyway.
		box.style.display = converting ? '' : 'none';

		if ( select ) {
			select.disabled = ! converting;
		}

		if ( announce && converting && window.wp && window.wp.a11y ) {
			window.wp.a11y.speak( box.textContent.trim(), 'assertive' );
		}
	}

	type.addEventListener( 'change', function () {
		sync( true );
	} );

	sync( false );
}() );
