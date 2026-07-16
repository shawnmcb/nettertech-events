/* eslint-disable no-restricted-syntax, no-unused-vars -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * Ticket quantity selector with progressive enhancement.
 *
 * Enhances the ticket form with:
 * - +/- increment/decrement buttons
 * - AJAX batch form submission
 * - Per-ticket error display
 * - Accessible announcements via wp.a11y.speak() or aria-live fallback
 *
 * Expects window.nettertechEventsTicketForm (set via wp_localize_script) for i18n strings.
 *
 * @package NetterTechEvents
 */

( function() {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';

	/**
	 * Create an SVG icon element.
	 *
	 * @param {string} pathData SVG path d attribute.
	 * @param {number} size    Display width/height in px.
	 * @return {SVGElement} The SVG element.
	 */
	function createIcon( pathData, size ) {
		var svg = document.createElementNS( SVG_NS, 'svg' );
		svg.setAttribute( 'class', 'nte-icon' );
		svg.setAttribute( 'width', String( size ) );
		svg.setAttribute( 'height', String( size ) );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2.5' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );

		var path = document.createElementNS( SVG_NS, 'path' );
		path.setAttribute( 'd', pathData );
		svg.appendChild( path );

		return svg;
	}

	/**
	 * Initialize all ticket forms on the page.
	 */
	function init() {
		const forms = document.querySelectorAll( '.nte-ticket-form' );

		forms.forEach( function( form ) {
			enhanceForm( form );
		} );
	}

	/**
	 * Enhance a single ticket form with quantity controls and AJAX submission.
	 *
	 * @param {HTMLFormElement} form The ticket form element.
	 */
	function enhanceForm( form ) {
		const inputs = form.querySelectorAll( '.nte-ticket-quantity__input' );
		const submitBtn = form.querySelector( '.nte-ticket-form__submit' );
		const statusEl = form.querySelector( '.nte-ticket-form__status' );

		// Track submission in progress to prevent double-submit.
		form._nettertechEventsSubmitting = false;

		// Add +/- buttons around each quantity input.
		inputs.forEach( function( input ) {
			wrapWithButtons( input );
		} );

		// Update submit state whenever any quantity changes.
		form.addEventListener( 'change', function() {
			updateSubmitState( form, submitBtn );
		} );

		form.addEventListener( 'input', function() {
			updateSubmitState( form, submitBtn );
		} );

		// Intercept submit for AJAX handling.
		form.addEventListener( 'submit', function( e ) {
			e.preventDefault();
			handleSubmit( form, submitBtn, statusEl );
		} );

		// Set the correct initial state.
		updateSubmitState( form, submitBtn );
	}

	/**
	 * Wrap a quantity input with decrement and increment buttons.
	 *
	 * Buttons are inserted inside the existing .nte-ticket-quantity wrapper:
	 *   [minus button] [input] [plus button]
	 *
	 * @param {HTMLInputElement} input The number input to wrap.
	 */
	function wrapWithButtons( input ) {
		const wrapper = input.closest( '.nte-ticket-quantity' );

		if ( ! wrapper || wrapper.querySelector( '.nte-ticket-quantity__btn' ) ) {
			// Already wrapped or no wrapper found.
			return;
		}

		const i18n = ( typeof nettertechEventsTicketForm !== 'undefined' && nettertechEventsTicketForm.i18n ) ? nettertechEventsTicketForm.i18n : {};
		const min = parseInt( input.getAttribute( 'min' ), 10 ) || 0;
		const max = parseInt( input.getAttribute( 'max' ), 10 ) || 999;
		const currentVal = parseInt( input.value, 10 ) || 0;

		// Decrement button.
		const decrementBtn = document.createElement( 'button' );
		decrementBtn.type = 'button';
		decrementBtn.className = 'nte-ticket-quantity__btn nte-ticket-quantity__btn--minus';
		decrementBtn.appendChild( createIcon( 'M5 12h14', 16 ) );
		decrementBtn.setAttribute( 'aria-label', i18n.decrease || 'Decrease quantity' );
		decrementBtn.disabled = currentVal <= min;

		// Increment button.
		const incrementBtn = document.createElement( 'button' );
		incrementBtn.type = 'button';
		incrementBtn.className = 'nte-ticket-quantity__btn nte-ticket-quantity__btn--plus';
		incrementBtn.appendChild( createIcon( 'M12 5v14M5 12h14', 16 ) );
		incrementBtn.setAttribute( 'aria-label', i18n.increase || 'Increase quantity' );
		incrementBtn.disabled = currentVal >= max;

		// Insert: minus before input, plus after input.
		wrapper.insertBefore( decrementBtn, input );
		wrapper.insertBefore( incrementBtn, input.nextSibling );

		// Button click handlers.
		decrementBtn.addEventListener( 'click', function() {
			changeQuantity( input, -1, min, max, decrementBtn, incrementBtn );
		} );

		incrementBtn.addEventListener( 'click', function() {
			changeQuantity( input, 1, min, max, decrementBtn, incrementBtn );
		} );

		// Keep button states in sync when the input is edited directly.
		function syncButtonStates() {
			const val = parseInt( input.value, 10 ) || 0;
			decrementBtn.disabled = val <= min;
			incrementBtn.disabled = val >= max;
		}

		input.addEventListener( 'change', syncButtonStates );
		input.addEventListener( 'input', syncButtonStates );
	}

	/**
	 * Adjust an input value by delta and clamp to min/max.
	 *
	 * Dispatches a bubbling change event so the form's change listener
	 * picks it up and calls updateSubmitState.
	 *
	 * @param {HTMLInputElement}  input        The quantity input.
	 * @param {number}            delta        Amount to change (+1 or -1).
	 * @param {number}            min          Minimum allowed value.
	 * @param {number}            max          Maximum allowed value.
	 * @param {HTMLButtonElement} decrementBtn The minus button.
	 * @param {HTMLButtonElement} incrementBtn The plus button.
	 */
	function changeQuantity( input, delta, min, max, decrementBtn, incrementBtn ) {
		let val = parseInt( input.value, 10 ) || 0;
		val = Math.max( min, Math.min( max, val + delta ) );
		input.value = val;

		decrementBtn.disabled = val <= min;
		incrementBtn.disabled = val >= max;

		// Trigger change so the form-level listener updates the submit button.
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	/**
	 * Recalculate and update the submit button label, price, and disabled state.
	 *
	 * Reads data-text-none, data-text-single, data-text-plural, and data-currency
	 * from the submit button element.
	 *
	 * @param {HTMLFormElement}   form      The ticket form.
	 * @param {HTMLButtonElement} submitBtn The form's submit button.
	 */
	function updateSubmitState( form, submitBtn ) {
		if ( ! submitBtn ) {
			return;
		}

		const textNone = submitBtn.dataset.textNone || 'No tickets selected';
		const textSingle = submitBtn.dataset.textSingle || 'Get Ticket';
		const textPlural = submitBtn.dataset.textPlural || 'Get Tickets';
		const currency = submitBtn.dataset.currency || '$';

		const inputs = form.querySelectorAll( '.nte-ticket-quantity__input' );
		let totalQty = 0;
		let totalPrice = 0;

		inputs.forEach( function( input ) {
			const qty = parseInt( input.value, 10 ) || 0;
			const price = parseFloat( input.dataset.price ) || 0;
			totalQty += qty;
			totalPrice += qty * price;
		} );

		const hasQuantity = totalQty > 0;
		submitBtn.disabled = ! hasQuantity;

		// Determine label text.
		let labelText;
		if ( ! hasQuantity ) {
			labelText = textNone;
		} else if ( totalQty === 1 ) {
			labelText = textSingle;
		} else {
			labelText = textPlural;
		}

		const labelEl = submitBtn.querySelector( '.nte-ticket-form__submit-label' );
		if ( labelEl ) {
			labelEl.textContent = labelText;
		}

		// Manage price display element.
		let priceEl = submitBtn.querySelector( '.nte-ticket-form__submit-price' );

		if ( hasQuantity ) {
			if ( ! priceEl ) {
				priceEl = document.createElement( 'span' );
				priceEl.className = 'nte-ticket-form__submit-price';
				submitBtn.appendChild( priceEl );
			}

			priceEl.textContent = currency + totalPrice.toFixed( 2 );
			priceEl.style.display = '';
		} else if ( priceEl ) {
			priceEl.style.display = 'none';
		}
	}

	/**
	 * Handle form submission: collect ticket data, POST via AJAX, redirect on success.
	 *
	 * Prevents double-submission via a flag on the form element.
	 *
	 * @param {HTMLFormElement}   form      The ticket form.
	 * @param {HTMLButtonElement} submitBtn The submit button.
	 * @param {HTMLElement}       statusEl  The aria-live status region.
	 */
	function handleSubmit( form, submitBtn, statusEl ) {
		if ( form._nettertechEventsSubmitting ) {
			return;
		}

		const i18n = ( typeof nettertechEventsTicketForm !== 'undefined' && nettertechEventsTicketForm.i18n ) ? nettertechEventsTicketForm.i18n : {};
		const inputs = form.querySelectorAll( '.nte-ticket-quantity__input' );
		const tickets = [];

		inputs.forEach( function( input ) {
			const qty = parseInt( input.value, 10 ) || 0;
			const ticketId = input.dataset.ticketId;

			if ( qty > 0 && ticketId ) {
				tickets.push( {
					ticket_type_id: parseInt( ticketId, 10 ),
					quantity: qty,
				} );
			}
		} );

		if ( tickets.length === 0 ) {
			announce( statusEl, i18n.selectTicket || 'Please select at least one ticket.', 'assertive' );
			return;
		}

		// Set submission flag and show loading state.
		form._nettertechEventsSubmitting = true;

		if ( submitBtn ) {
			submitBtn.disabled = true;

			const labelEl = submitBtn.querySelector( '.nte-ticket-form__submit-label' );
			if ( labelEl ) {
				labelEl.textContent = i18n.adding || 'Adding\u2026';
			}
		}

		announce( statusEl, i18n.adding || 'Adding to cart\u2026' );

		// Nonce is passed as a hidden input field value.
		const nonceField = form.querySelector( '[name="nettertech_events_ticket_nonce"]' );
		const nonce = nonceField ? nonceField.value : '';

		const formData = new FormData();
		formData.append( 'action', 'nettertech_events_add_tickets_batch' );
		formData.append( 'nettertech_events_ticket_nonce', nonce );
		formData.append( 'tickets', JSON.stringify( tickets ) );

		// Use getAttribute to read the action URL — avoids conflict with
		// the hidden input[name="action"] which shadows form.action in some browsers.
		fetch( form.getAttribute( 'action' ), {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		} )
			.then( function( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function( data ) {
				if ( data.success === true ) {
					handleSuccess( form, submitBtn, statusEl, data.data, i18n );
				} else {
					handleError( form, submitBtn, statusEl, data.data, i18n );
				}
			} )
			.catch( function( error ) {
				handleError( form, submitBtn, statusEl, {
					message: i18n.networkError || 'An error occurred. Please try again.',
				}, i18n );
			} );
	}

	/**
	 * Handle a successful AJAX response by redirecting to the cart.
	 *
	 * @param {HTMLFormElement}   form      The ticket form.
	 * @param {HTMLButtonElement} submitBtn The submit button.
	 * @param {HTMLElement}       statusEl  The aria-live status region.
	 * @param {Object}            data      Server response data object.
	 * @param {Object}            i18n      Localisation strings.
	 */
	function handleSuccess( form, submitBtn, statusEl, data, i18n ) {
		announce( statusEl, i18n.addedToCart || 'Tickets added to cart.' );

		if ( data && data.cart_url ) {
			announce( statusEl, i18n.redirecting || 'Redirecting to cart\u2026' );
			window.location.href = data.cart_url;
		} else {
			// No redirect URL — reset form for another attempt.
			form._nettertechEventsSubmitting = false;
			resetForm( form, submitBtn );
		}
	}

	/**
	 * Handle an error AJAX response: display errors, restore button state.
	 *
	 * Checks data.ticket_errors for per-ticket error messages keyed by ticket ID.
	 *
	 * @param {HTMLFormElement}   form      The ticket form.
	 * @param {HTMLButtonElement} submitBtn The submit button.
	 * @param {HTMLElement}       statusEl  The aria-live status region.
	 * @param {Object}            data      Server error data object.
	 * @param {Object}            i18n      Localisation strings.
	 */
	function handleError( form, submitBtn, statusEl, data, i18n ) {
		form._nettertechEventsSubmitting = false;

		// Restore button to a usable state.
		if ( submitBtn ) {
			updateSubmitState( form, submitBtn );
		}

		const message = ( data && data.message )
			? data.message
			: ( i18n.addError || 'Failed to add tickets to cart.' );

		announce( statusEl, message, 'assertive' );

		// Clear any previously shown per-ticket errors.
		clearTicketErrors( form );

		// Show new per-ticket errors if provided.
		if ( data && data.ticket_errors ) {
			Object.keys( data.ticket_errors ).forEach( function( ticketId ) {
				const card = form.querySelector( '[data-ticket-id="' + ticketId + '"]' );
				if ( card ) {
					showTicketError( card, data.ticket_errors[ ticketId ] );
				}
			} );
		}
	}

	/**
	 * Remove all per-ticket error elements from the form.
	 *
	 * @param {HTMLFormElement} form The ticket form.
	 */
	function clearTicketErrors( form ) {
		const existing = form.querySelectorAll( '.nte-ticket-error' );
		existing.forEach( function( el ) {
			el.remove();
		} );
	}

	/**
	 * Insert an error message after the ticket card's footer element.
	 *
	 * @param {HTMLElement} card    The .nte-ticket-card element.
	 * @param {string}      message The error text to display.
	 */
	function showTicketError( card, message ) {
		const footer = card.querySelector( '.nte-ticket-card__footer' );
		const errorEl = document.createElement( 'span' );
		errorEl.className = 'nte-ticket-error';
		errorEl.setAttribute( 'role', 'alert' );
		errorEl.textContent = message;

		if ( footer ) {
			footer.insertAdjacentElement( 'afterend', errorEl );
		} else {
			card.appendChild( errorEl );
		}
	}

	/**
	 * Reset all quantity inputs to zero and refresh the submit button state.
	 *
	 * @param {HTMLFormElement}   form      The ticket form.
	 * @param {HTMLButtonElement} submitBtn The submit button.
	 */
	function resetForm( form, submitBtn ) {
		const inputs = form.querySelectorAll( '.nte-ticket-quantity__input' );

		inputs.forEach( function( input ) {
			input.value = 0;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		if ( submitBtn ) {
			updateSubmitState( form, submitBtn );
		}
	}

	/**
	 * Announce a message to screen readers.
	 *
	 * Uses wp.a11y.speak() when available (requires the wp-a11y script
	 * dependency). Falls back to updating the form's aria-live status region.
	 *
	 * @param {HTMLElement} statusEl  The .nte-ticket-form__status element.
	 * @param {string}      message   The message to announce.
	 * @param {string}      priority  'polite' (default) or 'assertive' for errors.
	 */
	function announce( statusEl, message, priority ) {
		if ( typeof wp !== 'undefined' && wp.a11y && typeof wp.a11y.speak === 'function' ) {
			wp.a11y.speak( message, priority || 'polite' );
		}

		// Always update the local aria-live region as a fallback and for
		// cases where wp-a11y is not enqueued.
		if ( statusEl ) {
			statusEl.textContent = message;
		}
	}

	// Initialise: handle both DOMContentLoaded (page still parsing) and
	// already-loaded states (script injected after parse).
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
