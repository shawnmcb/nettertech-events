/* eslint-disable no-restricted-syntax, no-unused-vars, no-unsanitized/property -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * Event Revisions JavaScript
 *
 * Handles viewing diffs and restoring event revisions from the admin editor.
 *
 * @package NetterTechEvents
 * @since 1.5.0
 */

/* global nettertechEventsAdmin */

( function() {
	'use strict';

	/**
	 * Event Revisions Controller
	 *
	 * @namespace
	 */
	var NetterTechEventsRevisions = {

		/**
		 * Initialize revision handlers.
		 *
		 * @return {void}
		 */
		init: function() {
			var list = document.getElementById( 'nte-revision-list' );
			if ( ! list ) {
				return;
			}

			list.addEventListener( 'click', function( e ) {
				var target = e.target;

				if ( target.classList.contains( 'nte-revision-view-diff' ) ) {
					e.preventDefault();
					var revisionId = target.getAttribute( 'data-revision-id' );
					var container = target.closest( '.nte-revision-item' ).querySelector( '.nte-revision-diff-panel' );
					NetterTechEventsRevisions.loadDiff( revisionId, container );
				}

				if ( target.classList.contains( 'nte-revision-close-diff' ) ) {
					e.preventDefault();
					NetterTechEventsRevisions.closeDiff( target );
				}

				if ( target.classList.contains( 'nte-revision-restore' ) ) {
					e.preventDefault();
					var restoreId = target.getAttribute( 'data-revision-id' );
					NetterTechEventsRevisions.confirmRestore( restoreId );
				}
			} );
		},

		/**
		 * Load and display a revision diff.
		 *
		 * @param {string}      revisionId Revision ID.
		 * @param {HTMLElement} container  Container element for diff display.
		 * @return {void}
		 */
		loadDiff: function( revisionId, container ) {
			if ( ! container ) {
				return;
			}

			container.style.display = 'block';
			container.textContent = 'Loading...';

			var formData = new FormData();
			formData.append( 'action', 'nettertech_events_revision_diff' );
			formData.append( 'revision_id', revisionId );
			formData.append( 'nonce', nettertechEventsAdmin.revisionNonce );

			fetch( nettertechEventsAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			} )
				.then( function( response ) {
					return response.json();
				} )
				.then( function( result ) {
					if ( ! result.success ) {
						container.textContent = result.data && result.data.message ? result.data.message : 'Failed to load diff.';
						return;
					}

					var diff = result.data.diff;
					var html = '';

					if ( result.data.change_summary ) {
						html += '<p class="nte-revision-change-summary">' + '</p>';
					}

					if ( diff.length === 0 ) {
						html += '<p>No differences found.</p>';
					} else {
						html += '<table class="nte-revision-diff-table widefat">';
						html += '<thead><tr><th>Field</th><th>Revision Value</th><th>Current Value</th></tr></thead>';
						html += '<tbody>';

						diff.forEach( function( item ) {
							html += '<tr>';
							html += '<td></td>';
							html += '<td></td>';
							html += '<td></td>';
							html += '</tr>';
						} );

						html += '</tbody></table>';
					}

					html += '<button type="button" class="button button-small nte-revision-close-diff">Close</button>';

					container.innerHTML = html;

					// Populate with textContent for XSS safety.
					if ( result.data.change_summary ) {
						var summaryEl = container.querySelector( '.nte-revision-change-summary' );
						if ( summaryEl ) {
							summaryEl.textContent = result.data.change_summary;
						}
					}

					var rows = container.querySelectorAll( '.nte-revision-diff-table tbody tr' );
					diff.forEach( function( item, index ) {
						if ( rows[ index ] ) {
							var cells = rows[ index ].querySelectorAll( 'td' );
							cells[0].textContent = item.label;
							cells[1].textContent = item.old;
							cells[2].textContent = item.new;
						}
					} );
				} )
				.catch( function() {
					container.textContent = 'Request failed.';
				} );
		},

		/**
		 * Close a diff panel.
		 *
		 * @param {HTMLElement} button The close button clicked.
		 * @return {void}
		 */
		closeDiff: function( button ) {
			var panel = button.closest( '.nte-revision-diff-panel' );
			if ( panel ) {
				panel.style.display = 'none';
				panel.innerHTML = '';
			}
		},

		/**
		 * Confirm and execute a revision restore.
		 *
		 * @param {string} revisionId Revision ID.
		 * @return {void}
		 */
		confirmRestore: function( revisionId ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( 'Are you sure you want to restore this revision? The current state will be saved as a revision before restoring.' ) ) {
				return;
			}

			var formData = new FormData();
			formData.append( 'action', 'nettertech_events_revision_restore' );
			formData.append( 'revision_id', revisionId );
			formData.append( 'nonce', nettertechEventsAdmin.revisionNonce );

			fetch( nettertechEventsAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			} )
				.then( function( response ) {
					return response.json();
				} )
				.then( function( result ) {
					if ( result.success && result.data && result.data.redirect ) {
						window.location = result.data.redirect;
					} else {
						// eslint-disable-next-line no-alert
						window.alert( result.data && result.data.message ? result.data.message : 'Failed to restore revision.' );
					}
				} )
				.catch( function() {
					// eslint-disable-next-line no-alert
					window.alert( 'Request failed.' );
				} );
		}
	};

	// Initialize when DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function() {
			NetterTechEventsRevisions.init();
		} );
	} else {
		NetterTechEventsRevisions.init();
	}
}() );
