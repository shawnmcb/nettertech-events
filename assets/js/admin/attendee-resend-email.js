/**
 * Re-send order confirmation email from the Attendees screen.
 *
 * Drives the pre-existing `nettertech_events_resend_confirmation_email` AJAX
 * action, which is capability- and nonce-checked server side.
 *
 * @since 1.1.2
 * @package NetterTechEvents
 */
( function() {
	'use strict';

	const config = window.nettertechEventsResendEmail || {};
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
	 * How long an armed button waits for its confirming click, in milliseconds.
	 */
	const ARM_TIMEOUT = 4000;

	/**
	 * Arm a button for confirmation, disarming it after a short delay.
	 *
	 * Sending mail is not idempotent, so a single misclick must not replay the
	 * email. A two-step button is used rather than window.confirm() — a modal
	 * dialog blocks the page and is barred by the project's no-alert lint rule.
	 *
	 * @param {HTMLElement} button The row-action button to arm.
	 */
	function armButton( button ) {
		button.dataset.armed = 'true';
		button.textContent = i18n.confirm;
		announce( i18n.confirm );

		window.setTimeout( function() {
			if ( 'true' === button.dataset.armed ) {
				delete button.dataset.armed;
				button.textContent = i18n.resend;
			}
		}, ARM_TIMEOUT );
	}

	/**
	 * Re-send the confirmation email for one order.
	 *
	 * @param {HTMLElement} button The activated row-action button.
	 */
	async function resendEmail( button ) {
		const orderId = button.dataset.orderId;
		if ( ! orderId ) {
			return;
		}

		if ( 'true' !== button.dataset.armed ) {
			armButton( button );
			return;
		}

		delete button.dataset.armed;
		button.disabled = true;
		const originalText = i18n.resend;
		button.textContent = i18n.sending;

		const body = new URLSearchParams();
		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce || '' );
		body.append( 'order_id', orderId );

		try {
			const response = await fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );
			const payload = await response.json();

			const succeeded = payload && payload.success;
			const message = ( payload && payload.data && payload.data.message ) ||
				( succeeded ? i18n.sent : i18n.error );

			announce( message );
			button.textContent = succeeded ? i18n.sent : originalText;

			if ( succeeded ) {
				// Leave the confirmation visible briefly, then restore the action.
				window.setTimeout( function() {
					button.textContent = originalText;
				}, 3000 );
			}
		} catch ( error ) {
			announce( i18n.error );
			button.textContent = originalText;
		} finally {
			button.disabled = false;
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
			const button = event.target.closest( '.nte-resend-email' );
			if ( ! button ) {
				return;
			}

			event.preventDefault();
			resendEmail( button );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
