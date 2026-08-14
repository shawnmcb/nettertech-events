/**
 * Inline, screen-reader-announced validation for date/time field groups.
 *
 * Replaces alert()-based validation: errors render adjacent to the offending
 * field, are associated via aria-describedby, and are announced through a
 * per-container aria-live region (plus wp.a11y.speak when available).
 *
 * @package NetterTechEvents
 * @since 1.4.0
 */

( function() {
	'use strict';

	const config = window.nettertechEventsDatetimeValidation || {};
	const i18n = config.i18n || {};

	let errorCount = 0;

	/**
	 * Ensure the container has an assertive live region and return it.
	 *
	 * @param {Element} container Metabox or fieldset container.
	 * @return {Element} The live region element.
	 */
	function liveRegion( container ) {
		let region = container.querySelector( '.nte-datetime-live-region' );
		if ( ! region ) {
			region = document.createElement( 'div' );
			region.className = 'nte-datetime-live-region screen-reader-text';
			region.setAttribute( 'aria-live', 'assertive' );
			region.setAttribute( 'aria-atomic', 'true' );
			container.appendChild( region );
		}
		return region;
	}

	/**
	 * Show an inline error next to a field and announce it.
	 *
	 * @param {Element} field     The field (native input or combobox display).
	 * @param {string}  message   Error text.
	 * @param {Element} container Live-region container (defaults to the field's form section).
	 */
	function showError( field, message, container ) {
		clearError( field );

		errorCount++;
		const errorId = 'nte-field-error-' + errorCount;
		const error = document.createElement( 'span' );
		error.className = 'nte-field-error';
		error.id = errorId;
		error.textContent = message;

		const anchor = field.closest( '.nte-time-combobox' ) || field;
		anchor.insertAdjacentElement( 'afterend', error );

		field.setAttribute( 'aria-invalid', 'true' );
		const described = ( field.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( Boolean );
		described.push( errorId );
		field.setAttribute( 'aria-describedby', described.join( ' ' ) );
		field.dataset.nteErrorId = errorId;

		const region = liveRegion( container || field.closest( 'fieldset, .inside, form' ) || document.body );
		region.textContent = message;

		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, 'assertive' );
		}
	}

	/**
	 * Remove a field's inline error, if present.
	 *
	 * @param {Element} field The field to clear.
	 */
	function clearError( field ) {
		const errorId = field.dataset.nteErrorId;
		if ( ! errorId ) {
			return;
		}
		const error = document.getElementById( errorId );
		if ( error ) {
			error.remove();
		}
		const described = ( field.getAttribute( 'aria-describedby' ) || '' )
			.split( /\s+/ )
			.filter( ( id ) => id && id !== errorId );
		if ( described.length > 0 ) {
			field.setAttribute( 'aria-describedby', described.join( ' ' ) );
		} else {
			field.removeAttribute( 'aria-describedby' );
		}
		field.removeAttribute( 'aria-invalid' );
		delete field.dataset.nteErrorId;
	}

	/**
	 * Focus a field's operator-visible control (combobox display when present).
	 *
	 * @param {Element} field The field with an error.
	 */
	function focusField( field ) {
		const wrapper = field.closest( '.nte-time-combobox' );
		const target = wrapper ? wrapper.querySelector( '.nte-time-combobox__input' ) : field;
		if ( target && target.focus ) {
			target.focus();
		}
	}

	window.nettertechEventsDatetimeValidation = window.nettertechEventsDatetimeValidation || {};
	window.nettertechEventsDatetimeValidation.showError = showError;
	window.nettertechEventsDatetimeValidation.clearError = clearError;
	window.nettertechEventsDatetimeValidation.focusField = focusField;
	window.nettertechEventsDatetimeValidation.messages = i18n;
} )();
