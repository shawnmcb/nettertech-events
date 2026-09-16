/* eslint-disable no-restricted-syntax, no-unused-vars -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * Event Quick Edit & Bulk Action Enhancements
 *
 * Handles inline quick editing of events and extended bulk action UI:
 * - Quick edit show/hide/populate/save/cancel
 * - Category modal for bulk category add/remove
 * - Bulk delete confirmation dialog
 * - "Select All Matching" banner for filtered results
 *
 * @package NetterTechEvents
 * @since 1.5.0
 */

/* global nettertechEventsAdmin */

( function() {
	'use strict';

	/**
	 * Quick Edit Controller
	 *
	 * @namespace
	 */
	var QuickEdit = {
		/** @type {HTMLTableRowElement|null} Currently active edit row. */
		activeRow: null,

		/** @type {HTMLTableRowElement|null} Original data row being edited. */
		activeDataRow: null,

		/**
		 * Initialize quick edit functionality.
		 */
		init: function() {
			this.bindQuickEditTriggers();
			this.bindDeleteConfirmation();
			this.bindBulkActionEnhancements();
			this.initSelectAllMatching();
		},

		/**
		 * Bind click handlers for quick edit triggers.
		 */
		bindQuickEditTriggers: function() {
			var self = this;

			document.addEventListener( 'click', function( e ) {
				var trigger = e.target.closest( '.nte-quick-edit-trigger' );
				if ( trigger ) {
					e.preventDefault();
					self.open( trigger );
					return;
				}

				if ( e.target.closest( '.nte-inline-edit-cancel' ) ) {
					e.preventDefault();
					self.close();
					return;
				}

				if ( e.target.closest( '.nte-inline-edit-save' ) ) {
					e.preventDefault();
					self.save();
				}
			} );

			document.addEventListener( 'keydown', function( e ) {
				if ( 'Escape' === e.key && self.activeRow ) {
					e.preventDefault();
					self.close();
				}
			} );
		},

		/**
		 * Open quick edit for an event row.
		 *
		 * @param {HTMLElement} trigger The clicked trigger button.
		 */
		open: function( trigger ) {
			// Close any existing quick edit.
			this.close();

			var dataRow = trigger.closest( 'tr' );
			if ( ! dataRow || dataRow.hasAttribute( 'data-occurrence-id' ) ) {
				return;
			}

			var template = document.getElementById( 'nte-inline-edit-template' );
			if ( ! template ) {
				return;
			}

			// Clone the template row.
			var editRow = template.cloneNode( true );
			editRow.removeAttribute( 'id' );
			editRow.style.display = '';

			// Populate fields from data attributes.
			var titleInput = editRow.querySelector( 'input[name="title"]' );
			var statusSelect = editRow.querySelector( 'select[name="status"]' );
			var venueInput = editRow.querySelector( 'input[name="venue_name"]' );

			if ( titleInput ) {
				titleInput.value = dataRow.getAttribute( 'data-title' ) || '';
			}
			if ( statusSelect ) {
				statusSelect.value = dataRow.getAttribute( 'data-status' ) || 'draft';
			}
			if ( venueInput ) {
				venueInput.value = dataRow.getAttribute( 'data-venue-name' ) || '';
			}

			// Insert after the data row and hide the data row.
			dataRow.style.display = 'none';
			dataRow.parentNode.insertBefore( editRow, dataRow.nextSibling );

			this.activeRow = editRow;
			this.activeDataRow = dataRow;

			// Focus title input.
			if ( titleInput ) {
				titleInput.focus();
				titleInput.select();
			}
		},

		/**
		 * Close the active quick edit row.
		 */
		close: function() {
			if ( this.activeRow ) {
				this.activeRow.remove();
			}
			if ( this.activeDataRow ) {
				this.activeDataRow.style.display = '';
			}
			this.activeRow = null;
			this.activeDataRow = null;
		},

		/**
		 * Drop an event's expanded date rows and reset its toggle.
		 *
		 * A quick edit re-renders the event row from the response; the date rows it
		 * was showing were rendered against the pre-save event, so they are removed
		 * rather than left to disagree with the row above them.
		 *
		 * @param {number|string} eventId The saved event's ID.
		 */
		collapseOccurrenceRows: function( eventId ) {
			var selector = '[data-event-id="' + String( eventId ) + '"]';
			var toggle = document.querySelector( '.nte-dates-toggle' + selector );

			// The toggle script owns the closed state (rows, URL, announcement), so
			// route through it; otherwise the address bar would still say the
			// dates are open after the rows are gone.
			if ( toggle && toggle.getAttribute( 'aria-expanded' ) === 'true' ) {
				toggle.click();
				return;
			}

			document.querySelectorAll( 'tr[data-occurrence-id]' + selector ).forEach( function( row ) {
				row.remove();
			} );
		},

		/**
		 * Save quick edit changes via AJAX.
		 */
		save: function() {
			if ( ! this.activeRow || ! this.activeDataRow ) {
				return;
			}

			var self = this;
			var eventId = this.activeDataRow.getAttribute( 'data-event-id' );
			var saveBtn = this.activeRow.querySelector( '.nte-inline-edit-save' );
			var spinner = this.activeRow.querySelector( '.spinner' );

			if ( saveBtn ) {
				saveBtn.disabled = true;
			}
			if ( spinner ) {
				spinner.classList.add( 'is-active' );
			}

			var formData = new FormData();
			formData.append( 'action', 'nettertech_events_quick_edit_event' );
			formData.append( 'nonce', nettertechEventsAdmin.quickEditNonce || '' );
			formData.append( 'event_id', eventId );
			formData.append( 'title', self.activeRow.querySelector( 'input[name="title"]' ).value );
			formData.append( 'status', self.activeRow.querySelector( 'select[name="status"]' ).value );
			formData.append( 'venue_name', self.activeRow.querySelector( 'input[name="venue_name"]' ).value );

			fetch( nettertechEventsAdmin.ajaxUrl || window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function( response ) {
					return response.json();
				} )
				.then( function( result ) {
					if ( result.success && result.data && result.data.event ) {
						var event = result.data.event;

						// Update data attributes on the original row.
						self.activeDataRow.setAttribute( 'data-title', event.title );
						self.activeDataRow.setAttribute( 'data-status', event.status );
						self.activeDataRow.setAttribute( 'data-venue-name', event.venue_name );

						// Update visible title text.
						var titleLink = self.activeDataRow.querySelector( '.row-title' );
						if ( titleLink ) {
							titleLink.textContent = event.title;
						}

						// Update visible status text and color.
						var statusCell = self.activeDataRow.querySelector( '.column-status a' );
						if ( statusCell ) {
							var statusLabels = nettertechEventsAdmin.statuses || {};
							var statusColors = {
								'draft': '#646970',
								'published': '#00a32a',
								'cancelled': '#d63638',
								'postponed': '#dba617',
							};
							statusCell.textContent = statusLabels[ event.status ] || event.status;
							statusCell.style.color = statusColors[ event.status ] || '#646970';
						}

						self.collapseOccurrenceRows( event.id );
						self.close();
					} else {
						var msg = ( result.data && result.data.message ) ? result.data.message : 'Update failed.';
						window.alert( msg ); // eslint-disable-line no-alert
						if ( saveBtn ) {
							saveBtn.disabled = false;
						}
						if ( spinner ) {
							spinner.classList.remove( 'is-active' );
						}
					}
				} )
				.catch( function() {
					window.alert( 'Network error. Please try again.' ); // eslint-disable-line no-alert
					if ( saveBtn ) {
						saveBtn.disabled = false;
					}
					if ( spinner ) {
						spinner.classList.remove( 'is-active' );
					}
				} );
		},

		/**
		 * Bind click confirmation for single event delete links.
		 */
		bindDeleteConfirmation: function() {
			document.addEventListener( 'click', function( e ) {
				var link = e.target.closest( '.nte-delete-event' );
				if ( ! link ) {
					return;
				}
				var name = link.getAttribute( 'data-event-name' ) || '';
				var msg = name
					? 'Delete "' + name + '"? This will permanently remove the event and all its occurrences. This cannot be undone.'
					: 'Delete this event? This will permanently remove the event and all its occurrences. This cannot be undone.';
				if ( ! window.confirm( msg ) ) { // eslint-disable-line no-alert
					e.preventDefault();
				}
			} );
		},

		/**
		 * Bind enhancements for bulk action selectors.
		 *
		 * The events list form submits via method="get" so that filtering,
		 * search, pagination, and column sorting stay bookmarkable and carry
		 * the active query. A GET bulk submit on a large selection (100+
		 * events) would overflow URL-length limits and silently drop trailing
		 * IDs, so every bulk action is re-routed through a generated POST form
		 * built by submitBulkAction(). The PHP bulk handler reads $_REQUEST, so
		 * it resolves the POST identically to the prior GET path.
		 */
		bindBulkActionEnhancements: function() {
			var self = this;

			// Intercept form submission for category actions and delete confirmation.
			var forms = document.querySelectorAll( '#posts-filter, #nte-events-list-form, .wrap > form[method="get"]' );
			forms.forEach( function( form ) {
				form.addEventListener( 'submit', function( e ) {
					var selects = form.querySelectorAll( 'select[name="action"], select[name="action2"]' );
					var action = '';

					selects.forEach( function( select ) {
						if ( select.value !== '-1' ) {
							action = select.value;
						}
					} );

					// No bulk action selected — let the GET form submit normally
					// (filter, search, sort, and pagination controls).
					if ( '' === action ) {
						return;
					}

					// Bulk delete confirmation.
					if ( 'delete' === action ) {
						var checked = form.querySelectorAll( 'input[name="event[]"]:checked' );
						if ( checked.length > 0 ) {
							var msg = 'Permanently delete ' + checked.length + ' event' + ( checked.length === 1 ? '' : 's' ) + '? This cannot be undone.';
							if ( ! window.confirm( msg ) ) { // eslint-disable-line no-alert
								e.preventDefault();
								return;
							}
						}
					}

					// Category actions — show modal (which POSTs on apply).
					if ( 'bulk_category_add' === action || 'bulk_category_remove' === action ) {
						e.preventDefault();
						self.showCategoryModal( form, action );
						return;
					}

					// All other bulk actions — POST to avoid URL-length truncation.
					e.preventDefault();
					self.submitBulkAction( form, action );
				} );
			} );
		},

		/**
		 * Submit a bulk action via a generated POST form.
		 *
		 * Copies the page slug, bulk-action nonce/referer, selected event IDs,
		 * and any selected category IDs into a hidden POST form, then submits.
		 * POST avoids the URL-length truncation that a GET bulk submit hits on
		 * large selections.
		 *
		 * @param {HTMLFormElement} form   The events list form (method="get").
		 * @param {string}          action The selected bulk action key.
		 */
		submitBulkAction: function( form, action ) {
			var postForm = document.createElement( 'form' );
			postForm.method = 'post';
			postForm.action = window.location.href;
			postForm.style.display = 'none';

			var appendField = function( name, value ) {
				var input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = name;
				input.value = value;
				postForm.appendChild( input );
			};

			// Page slug and bulk action.
			var pageInput = form.querySelector( 'input[name="page"]' );
			appendField( 'page', pageInput ? pageInput.value : 'nettertech-events' );
			appendField( 'action', action );

			// Bulk-action nonce and referer (rendered by WP_List_Table).
			var nonce = form.querySelector( 'input[name="_wpnonce"]' );
			if ( nonce ) {
				appendField( '_wpnonce', nonce.value );
			}
			var referer = form.querySelector( 'input[name="_wp_http_referer"]' );
			if ( referer ) {
				appendField( '_wp_http_referer', referer.value );
			}

			// Selected event IDs.
			form.querySelectorAll( 'input[name="event[]"]:checked' ).forEach( function( cb ) {
				appendField( 'event[]', cb.value );
			} );

			// Selected category IDs, when injected by the category modal.
			form.querySelectorAll( 'input[name="bulk_category_ids[]"]' ).forEach( function( cb ) {
				appendField( 'bulk_category_ids[]', cb.value );
			} );

			// "Select all matching" signal, when present.
			var selectAll = form.querySelector( 'input[name="select_all_matching"]' );
			if ( selectAll ) {
				appendField( 'select_all_matching', selectAll.value );
			}

			document.body.appendChild( postForm );
			postForm.submit();
		},

		/**
		 * Show the category selection modal for bulk actions.
		 *
		 * @param {HTMLFormElement} form   The parent form.
		 * @param {string}         action The bulk action name.
		 */
		showCategoryModal: function( form, action ) {
			var self = this;
			var modal = document.getElementById( 'nte-category-modal' );
			if ( ! modal ) {
				return;
			}

			modal.style.display = '';

			var applyBtn = modal.querySelector( '.nte-category-modal__apply' );
			var cancelBtn = modal.querySelector( '.nte-category-modal__cancel' );

			var cleanup = function() {
				modal.style.display = 'none';
				// Uncheck all checkboxes.
				modal.querySelectorAll( 'input[type="checkbox"]' ).forEach( function( cb ) {
					cb.checked = false;
				} );
			};

			// Cancel handler.
			var handleCancel = function() {
				cleanup();
				cancelBtn.removeEventListener( 'click', handleCancel );
				applyBtn.removeEventListener( 'click', handleApply );
			};

			// Apply handler — inject hidden fields and submit.
			var handleApply = function() {
				var checked = modal.querySelectorAll( 'input[type="checkbox"]:checked' );
				if ( checked.length === 0 ) {
					cleanup();
					cancelBtn.removeEventListener( 'click', handleCancel );
					applyBtn.removeEventListener( 'click', handleApply );
					return;
				}

				// Remove any previous hidden category fields.
				form.querySelectorAll( 'input[name="bulk_category_ids[]"]' ).forEach( function( el ) {
					el.remove();
				} );

				// Add hidden fields for selected categories.
				checked.forEach( function( cb ) {
					var hidden = document.createElement( 'input' );
					hidden.type = 'hidden';
					hidden.name = 'bulk_category_ids[]';
					hidden.value = cb.value;
					form.appendChild( hidden );
				} );

				cleanup();
				cancelBtn.removeEventListener( 'click', handleCancel );
				applyBtn.removeEventListener( 'click', handleApply );

				// POST (not form.submit()) so large selections never truncate.
				self.submitBulkAction( form, action );
			};

			cancelBtn.addEventListener( 'click', handleCancel );
			applyBtn.addEventListener( 'click', handleApply );

			// Focus first checkbox.
			var firstCb = modal.querySelector( 'input[type="checkbox"]' );
			if ( firstCb ) {
				firstCb.focus();
			}
		},

		/**
		 * Initialize "Select All Matching" banner.
		 *
		 * Shows a banner when all items on the current page are selected
		 * and filters are active, offering to select all matching events.
		 */
		initSelectAllMatching: function() {
			var headerCb = document.querySelector( '#cb-select-all-1' );
			if ( ! headerCb ) {
				return;
			}

			// Check if filters are active.
			var urlParams = new URLSearchParams( window.location.search );
			var hasFilters = urlParams.has( 'status' ) || urlParams.has( 'event_type' ) || urlParams.has( 's' );
			if ( ! hasFilters ) {
				return;
			}

			// Read total items from WordPress pagination display (e.g., "42 items").
			var displayingNum = document.querySelector( '.displaying-num' );
			var totalItems = 0;
			if ( displayingNum ) {
				var match = displayingNum.textContent.match( /(\d+)/ );
				if ( match ) {
					totalItems = parseInt( match[1], 10 );
				}
			}

			// Read per-page from visible row count.
			var visibleRows = document.querySelectorAll( '#the-list tr[data-event-id]:not([data-occurrence-id])' );
			var perPage = visibleRows.length || 20;

			if ( totalItems <= perPage ) {
				return;
			}

			headerCb.addEventListener( 'change', function() {
				var existing = document.querySelector( '.nte-select-all-matching-banner' );
				if ( existing ) {
					existing.remove();
				}

				if ( ! headerCb.checked ) {
					return;
				}

				// Create banner.
				var banner = document.createElement( 'div' );
				banner.className = 'nte-select-all-matching-banner';

				var msgText = nettertechEventsAdmin.strings && nettertechEventsAdmin.strings.selectAllMatching
					? nettertechEventsAdmin.strings.selectAllMatching.replace( '%d', totalItems )
					: 'Select all ' + totalItems + ' matching events?';

				var msg = document.createElement( 'span' );
				msg.textContent = msgText;

				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'button button-link';
				btn.textContent = nettertechEventsAdmin.strings && nettertechEventsAdmin.strings.selectAll
					? nettertechEventsAdmin.strings.selectAll
					: 'Select All';

				btn.addEventListener( 'click', function() {
					// Add hidden input to signal "select all matching".
					var form = headerCb.closest( 'form' );
					if ( form ) {
						var existingHidden = form.querySelector( 'input[name="select_all_matching"]' );
						if ( ! existingHidden ) {
							var hidden = document.createElement( 'input' );
							hidden.type = 'hidden';
							hidden.name = 'select_all_matching';
							hidden.value = '1';
							form.appendChild( hidden );
						}
					}

					banner.textContent = nettertechEventsAdmin.strings && nettertechEventsAdmin.strings.allSelected
						? nettertechEventsAdmin.strings.allSelected.replace( '%d', totalItems )
						: 'All ' + totalItems + ' matching events selected.';
					banner.classList.add( 'nte-select-all-matching-banner--active' );
				} );

				banner.appendChild( msg );
				banner.appendChild( document.createTextNode( ' ' ) );
				banner.appendChild( btn );

				// Insert before the table.
				var table = document.querySelector( '.wp-list-table' );
				if ( table ) {
					table.parentNode.insertBefore( banner, table );
				}
			} );
		},
	};

	// Initialize on DOM ready.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function() {
			QuickEdit.init();
		} );
	} else {
		QuickEdit.init();
	}
} )();
