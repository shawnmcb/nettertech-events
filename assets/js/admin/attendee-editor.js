/**
 * Attendees screen — Edit / Add Attendee dialogs (NTE-143 C5).
 *
 * Native <dialog> + fetch against the admin REST controller. No jQuery.
 *
 * @package NetterTechEvents
 */

/* global wp */

( function () {
	'use strict';

	var config = window.nettertechEventsAttendeeEditor || {};

	function speak( message ) {
		if ( window.wp && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message );
		}
	}

	function showError( dialog, message ) {
		var error = dialog.querySelector( '.nte-attendee-dialog-error' );
		if ( error ) {
			error.textContent = message;
			error.hidden = false;
		}
		speak( message );
	}

	function clearError( dialog ) {
		var error = dialog.querySelector( '.nte-attendee-dialog-error' );
		if ( error ) {
			error.textContent = '';
			error.hidden = true;
		}
	}

	async function request( method, url, body, onDone, dialog ) {
		try {
			const response = await window.fetch( url, {
				method: method,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce || ''
				},
				credentials: 'same-origin',
				body: JSON.stringify( body )
			} );
			const data = await response.json();

			if ( response.ok ) {
				onDone();
				return;
			}
			showError( dialog, ( data && data.message ) || config.i18n.genericError );
		} catch ( e ) {
			showError( dialog, config.i18n.genericError );
		}
	}

	// ------------------------------------------------------------------ Edit.
	var editDialog = document.getElementById( 'nte-edit-attendee-dialog' );

	function openEdit( button ) {
		if ( ! editDialog ) {
			return;
		}
		clearError( editDialog );
		var form = editDialog.querySelector( '#nte-edit-attendee-form' );
		form.elements.attendee_id.value = button.getAttribute( 'data-attendee-id' ) || '';
		form.elements.name.value = button.getAttribute( 'data-attendee-name' ) || '';
		form.elements.email.value = button.getAttribute( 'data-attendee-email' ) || '';
		form.elements.status.value = button.getAttribute( 'data-attendee-status' ) || 'confirmed';
		form.elements.quantity.value = button.getAttribute( 'data-attendee-quantity' ) || '1';
		editDialog.showModal();
	}

	// ------------------------------------------------------------------- Add.
	var addDialog = document.getElementById( 'nte-add-attendee-dialog' );

	function repopulateTiers( occurrenceId ) {
		var select = document.getElementById( 'nte-add-attendee-ticket-type' );
		if ( ! select ) {
			return;
		}
		var tiers;
		try {
			tiers = JSON.parse( select.getAttribute( 'data-tiers' ) || '{}' );
		} catch ( e ) {
			tiers = {};
		}
		while ( select.options.length > 1 ) {
			select.remove( 1 );
		}
		( tiers[ occurrenceId ] || [] ).forEach( function ( tier ) {
			var option = document.createElement( 'option' );
			option.value = String( tier.id );
			option.textContent = tier.name;
			select.appendChild( option );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var edit = event.target.closest( '.nte-edit-attendee' );
		if ( edit ) {
			event.preventDefault();
			openEdit( edit );
			return;
		}

		if ( event.target.closest( '#nte-add-attendee-open' ) ) {
			event.preventDefault();
			if ( addDialog ) {
				clearError( addDialog );
				addDialog.showModal();
			}
			return;
		}

		var cancel = event.target.closest( '.nte-dialog-cancel' );
		if ( cancel ) {
			event.preventDefault();
			var open = cancel.closest( 'dialog' );
			if ( open ) {
				open.close();
			}
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && 'nte-add-attendee-occurrence' === event.target.id ) {
			repopulateTiers( event.target.value );
		}
	} );

	if ( editDialog ) {
		editDialog.querySelector( '#nte-edit-attendee-form' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var form = event.target;
			var id = parseInt( form.elements.attendee_id.value, 10 );
			if ( ! id ) {
				return;
			}
			request(
				'PUT',
				config.restUrl + '/' + id,
				{
					name: form.elements.name.value,
					email: form.elements.email.value,
					status: form.elements.status.value,
					quantity: parseInt( form.elements.quantity.value, 10 ) || 1
				},
				function () {
					speak( config.i18n.saved );
					window.location.reload();
				},
				editDialog
			);
		} );
	}

	if ( addDialog ) {
		addDialog.querySelector( '#nte-add-attendee-form' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var form = event.target;
			var body = {
				occurrence_id: parseInt( form.elements.occurrence_id.value, 10 ) || 0,
				name: form.elements.name.value,
				email: form.elements.email.value,
				quantity: parseInt( form.elements.quantity.value, 10 ) || 1,
				status: 'confirmed'
			};
			var tier = parseInt( form.elements.ticket_type_id.value, 10 );
			if ( tier ) {
				body.ticket_type_id = tier;
			}
			request(
				'POST',
				config.restUrl,
				body,
				function () {
					speak( config.i18n.added );
					window.location.reload();
				},
				addDialog
			);
		} );
	}
}() );
