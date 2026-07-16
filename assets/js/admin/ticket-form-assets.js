/* eslint-disable no-unused-vars, no-alert -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * Ticket form metabox behavior.
 *
 * @package NetterTechEvents
 */

/* global jQuery, nettertechEventsTicketFormAssets */

jQuery(function($) {
	'use strict';

	var strings = (window.nettertechEventsTicketFormAssets && window.nettertechEventsTicketFormAssets.strings) || {};
	var ticketIndexes = {
		occurrence: 0,
		event: 0,
		template: 0
	};

	$('.nte-ticket-row').each(function() {
		var scope = $(this).data('scope');
		ticketIndexes[scope]++;
	});

	$('.nte-tab-button').on('click', function() {
		var tabId = $(this).data('tab');
		$('.nte-tab-button').removeClass('active').attr('aria-selected', 'false');
		$(this).addClass('active').attr('aria-selected', 'true');
		$('.nte-tab-content').removeClass('active');
		$('#nte-tab-' + tabId).addClass('active');
	});

	$('#nte-ticketing-enabled').on('change', function() {
		$('#nte-tickets-container').toggleClass('nte-hidden', !this.checked);
		if (this.checked && $('#nte-ticket-types-list .nte-ticket-row').length === 0) {
			addTicket('occurrence');
		}
	});

	$('.nte-add-ticket').on('click', function() {
		var scope = $(this).data('scope');
		addTicket(scope);
	});

	function addTicket(scope) {
		var template = $('#nte-ticket-template-' + scope).html();
		var html = template.replace(/\{\{INDEX\}\}/g, ticketIndexes[scope]);
		var container = scope === 'occurrence' ? '#nte-ticket-types-list, #nte-occurrence-ticket-types-list' :
			scope === 'event' ? '#nte-event-ticket-types-list' :
				'#nte-template-ticket-types-list';

		ticketIndexes[scope]++;
		$(container).find('.nte-empty-state').remove();
		$(container).append(html);
		$(container).find('.nte-ticket-row:last .nte-ticket-name-input').focus();
	}

	$(document).on('click', '.nte-ticket-remove', function(e) {
		e.preventDefault();
		if (window.confirm(strings.removeTicket || 'Remove this ticket type?')) {
			$(this).closest('.nte-ticket-row').slideUp(200, function() {
				$(this).remove();
			});
		}
	});

	$(document).on('click', '.nte-ticket-toggle', function(e) {
		e.preventDefault();
		var $row = $(this).closest('.nte-ticket-row');
		var $body = $row.find('.nte-ticket-body');
		var $icon = $(this).find('.dashicons');

		$body.slideToggle(200);
		$icon.toggleClass('dashicons-arrow-up-alt2 dashicons-arrow-down-alt2');
		$(this).attr('aria-expanded', $body.is(':visible'));
	});

	$(document).on('input', '.nte-ticket-name-input', function() {
		var name = $(this).val() || strings.newTicket || 'New Ticket';
		$(this).closest('.nte-ticket-row').find('.nte-ticket-name').text(name);
	});

	/**
	 * Show the capacity input only for a fixed tier, and disable it otherwise.
	 *
	 * Hiding is not enough on its own. A hidden input is still submitted, so a
	 * capacity typed while the tier was fixed would be posted after the operator
	 * switched the tier to shared — and stored, it later reads back as a real
	 * limit nobody granted. Disabling keeps it out of the submission entirely.
	 *
	 * @param {jQuery} $row         The ticket row.
	 * @param {string} selectedType The selected capacity type.
	 */
	function toggleCapacityField($row, selectedType) {
		var isFixed = selectedType === 'fixed';
		var $capacityField = $row.find('.nte-capacity-field');

		$capacityField.toggle(isFixed);
		$capacityField.find('input[name$="[capacity]"]').prop('disabled', !isFixed);
	}

	$(document).on('change', '.nte-capacity-type-select', function() {
		var $row = $(this).closest('.nte-ticket-row');
		var description = $(this).find('option:selected').data('description');

		$row.find('.nte-capacity-type-hint').text(description);

		toggleCapacityField($row, $(this).val());
	});

	$('.nte-capacity-type-select').each(function() {
		toggleCapacityField($(this).closest('.nte-ticket-row'), $(this).val());
	});

	$(document).on('click', '.nte-ticket-move-up', function(e) {
		e.preventDefault();
		var $row = $(this).closest('.nte-ticket-row');
		var $prev = $row.prev('.nte-ticket-row');
		if ($prev.length) {
			$row.insertBefore($prev);
			$(this).focus();
		}
	});

	$(document).on('click', '.nte-ticket-move-down', function(e) {
		e.preventDefault();
		var $row = $(this).closest('.nte-ticket-row');
		var $next = $row.next('.nte-ticket-row');
		if ($next.length) {
			$row.insertAfter($next);
			$(this).focus();
		}
	});

	$(document).on('mousedown', '.nte-ticket-drag-handle', function() {
		$(this).closest('.nte-ticket-row').attr('draggable', 'true');
	});

	$(document).on('dragstart', '.nte-ticket-row', function(e) {
		e.originalEvent.dataTransfer.effectAllowed = 'move';
		e.originalEvent.dataTransfer.setData('text/plain', '');
		$(this).addClass('nte-ticket-row--dragging');
	});

	$(document).on('dragend', '.nte-ticket-row', function() {
		$(this).removeClass('nte-ticket-row--dragging').removeAttr('draggable');
		$('.nte-ticket-row').removeClass('nte-ticket-row--drag-over');
	});

	$(document).on('dragover', '.nte-ticket-row', function(e) {
		e.preventDefault();
		var $dragging = $('.nte-ticket-row--dragging');
		if ($dragging.length && !$dragging.is(this)) {
			$('.nte-ticket-row').removeClass('nte-ticket-row--drag-over');
			$(this).addClass('nte-ticket-row--drag-over');
		}
	});

	$(document).on('drop', '.nte-ticket-row', function(e) {
		e.preventDefault();
		var $dragging = $('.nte-ticket-row--dragging');
		if ($dragging.length && !$dragging.is(this)) {
			var rect = this.getBoundingClientRect();
			var mid = rect.top + rect.height / 2;
			if (e.originalEvent.clientY < mid) {
				$dragging.insertBefore($(this));
			} else {
				$dragging.insertAfter($(this));
			}
		}
		$('.nte-ticket-row').removeClass('nte-ticket-row--drag-over');
	});
});
