/**
 * Event Ticket Types JavaScript
 *
 * Handles ticket type management in the event editor including:
 * - Adding/removing ticket types
 * - Capacity type toggling
 * - Occurrence capacity constraints enforcement
 *
 * @package NetterTechEvents
 * @since 1.0.0
 */

/* global jQuery */

jQuery(document).ready(function($) {
	'use strict';

	// Data passed from PHP via wp_localize_script().
	var data = window.nettertechEventsEventTickets || {};
	var ticketIndex = data.ticketIndex || 0;

	/**
	 * Toggle ticketing container visibility.
	 */
	$('#ticketing_enabled').on('change', function() {
		if ($(this).is(':checked')) {
			$('#ticket-types-container').slideDown();
			// Add first ticket type if none exist.
			if ($('#ticket-types-list .ticket-type-row').length === 0) {
				addTicketType();
			}
		} else {
			$('#ticket-types-container').slideUp();
		}
	});

	/**
	 * Add ticket type button click handler.
	 */
	$('#add-ticket-type').on('click', function() {
		addTicketType();
	});

	/**
	 * Add a new ticket type row from template.
	 */
	function addTicketType() {
		var template = $('#ticket-type-template').html();
		var html = template.replace(/\{\{INDEX\}\}/g, ticketIndex);
		$('#ticket-types-list').append(html);
		ticketIndex++;
	}

	/**
	 * Remove ticket type row.
	 */
	$(document).on('click', '.remove-ticket-type', function(e) {
		e.preventDefault();
		$(this).closest('.ticket-type-row').remove();
	});

	/**
	 * Capacity type selector change handler.
	 * Shows/hides capacity field and updates description.
	 */
	$(document).on('change', '.ticket-type-capacity-type', function() {
		var $row = $(this).closest('.ticket-type-row');
		var selectedType = $(this).val();
		var $capacityWrapper = $row.find('.ticket-type-capacity-wrapper');
		var $description = $(this).siblings('.description');

		// Update description text.
		var description = $(this).find('option:selected').data('description');
		$description.text(description);

		// Show/hide capacity field based on type.
		if (selectedType === 'fixed') {
			$capacityWrapper.show();
		} else {
			$capacityWrapper.hide();
		}
	});

	/**
	 * Get the occurrence capacity value.
	 *
	 * @return {number|null} The occurrence capacity or null if not set.
	 */
	function getOccurrenceCapacity() {
		var val = $('#occurrence_capacity').val();
		return val === '' ? null : parseInt(val, 10);
	}

	/**
	 * Update ticket capacity max attributes based on occurrence capacity.
	 * Clamps existing values if they exceed the new max.
	 */
	function updateCapacityMax() {
		var occurrenceCapacity = getOccurrenceCapacity();
		$('.ticket-type-capacity-wrapper input[type="number"]').each(function() {
			var $input = $(this);
			if (occurrenceCapacity !== null) {
				$input.attr('max', occurrenceCapacity);
				// Clamp current value if it exceeds max.
				var currentVal = $input.val() === '' ? null : parseInt($input.val(), 10);
				if (currentVal !== null && currentVal > occurrenceCapacity) {
					$input.val(occurrenceCapacity);
				}
			} else {
				$input.removeAttr('max');
			}
		});
	}

	// Set max on page load.
	updateCapacityMax();

	/**
	 * Update max when occurrence capacity changes.
	 */
	$('#occurrence_capacity').on('input', function() {
		updateCapacityMax();
	});

	/**
	 * Set max when new ticket type is added.
	 */
	$(document).on('DOMNodeInserted', '#ticket-types-list', function() {
		updateCapacityMax();
	});

	/**
	 * Clamp ticket capacity on input (enforces max for manual entry).
	 */
	$(document).on('input', '.ticket-type-capacity-wrapper input[type="number"]', function() {
		var $input = $(this);
		var max = parseInt($input.attr('max'), 10);
		var val = $input.val() === '' ? null : parseInt($input.val(), 10);
		if (!isNaN(max) && val !== null && val > max) {
			$input.val(max);
		}
	});
});
