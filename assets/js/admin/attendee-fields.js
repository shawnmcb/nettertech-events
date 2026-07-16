/* eslint-disable no-unsanitized/property -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * Attendee registration fields builder.
 *
 * Handles add/remove field rows and show/hide options based on field type.
 *
 * @since 3.6.0
 * @package NetterTechEvents
 */
( function() {
	'use strict';

	var fieldIndex = 0;
	var fieldTypes = [ 'select', 'radio', 'checkbox' ];

	/**
	 * Initialize the field builder.
	 */
	function init() {
		var list = document.getElementById( 'nte-attendee-fields-list' );
		var addBtn = document.getElementById( 'nte-add-attendee-field' );

		if ( ! list || ! addBtn ) {
			return;
		}

		// Set initial index based on existing fields.
		fieldIndex = list.querySelectorAll( '.nte-attendee-field-row' ).length;

		// Add field button.
		addBtn.addEventListener( 'click', function() {
			addFieldRow( list );
		} );

		// Delegate events for remove buttons and type selects.
		list.addEventListener( 'click', function( e ) {
			if ( e.target.classList.contains( 'nte-remove-field' ) ) {
				removeFieldRow( e.target );
			}
		} );

		list.addEventListener( 'change', function( e ) {
			if ( e.target.classList.contains( 'nte-field-type-select' ) ) {
				toggleOptionsVisibility( e.target );
			}
		} );
	}

	/**
	 * Add a new field row from the template.
	 *
	 * @param {HTMLElement} list The fields list container.
	 */
	function addFieldRow( list ) {
		var template = document.getElementById( 'tmpl-nte-attendee-field' );
		if ( ! template ) {
			return;
		}

		var html = template.innerHTML.replace( /\{\{data\.index\}\}/g, String( fieldIndex ) );
		var temp = document.createElement( 'div' );
		temp.innerHTML = html;

		var row = temp.firstElementChild;
		if ( row ) {
			list.appendChild( row );
			fieldIndex++;

			// Focus the label input.
			var labelInput = row.querySelector( 'input[type="text"]' );
			if ( labelInput ) {
				labelInput.focus();
			}
		}
	}

	/**
	 * Remove a field row.
	 *
	 * @param {HTMLElement} button The remove button clicked.
	 */
	function removeFieldRow( button ) {
		var row = button.closest( '.nte-attendee-field-row' );
		if ( row ) {
			row.remove();
		}
	}

	/**
	 * Toggle options textarea visibility based on field type.
	 *
	 * @param {HTMLSelectElement} select The field type select.
	 */
	function toggleOptionsVisibility( select ) {
		var row = select.closest( '.nte-attendee-field-row' );
		if ( ! row ) {
			return;
		}

		var optionsWrap = row.querySelector( '.nte-field-options-wrap' );
		if ( ! optionsWrap ) {
			return;
		}

		if ( fieldTypes.indexOf( select.value ) !== -1 ) {
			optionsWrap.style.display = '';
		} else {
			optionsWrap.style.display = 'none';
		}
	}

	// Initialize when DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
