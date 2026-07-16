/**
 * Event Recurrence Pattern Builder JavaScript
 *
 * Handles recurrence pattern selection, custom rule building,
 * and dynamic field visibility in the event editor.
 *
 * @package NetterTechEvents
 * @since 1.0.0
 */

/* global jQuery */

jQuery(document).ready(function($) {
	'use strict';

	// Data passed from PHP via wp_localize_script().
	var data = window.nettertechEventsEventRecurrence || {};
	var i18n = data.i18n || {};

	// i18n strings with fallbacks.
	var units = {
		'DAILY': i18n.days || 'day(s)',
		'WEEKLY': i18n.weeks || 'week(s)',
		'MONTHLY': i18n.months || 'month(s)',
		'YEARLY': i18n.years || 'year(s)'
	};

	/**
	 * Toggle recurrence box based on event type.
	 */
	$('#event_type').on('change', function() {
		if ($(this).val() === 'recurring') {
			$('#recurrence-box').slideDown();
		} else {
			$('#recurrence-box').slideUp();
		}
	});

	/**
	 * Toggle custom fields based on preset selection.
	 */
	$('#recurrence_preset').on('change', function() {
		if ($(this).val() === 'custom') {
			$('#custom-recurrence-fields').slideDown();
			updateCustomRule();
		} else {
			$('#custom-recurrence-fields').slideUp();
			$('#recurrence_rule').val($(this).val());
		}
	});

	/**
	 * Update interval unit label based on frequency.
	 */
	$('#recurrence_freq').on('change', function() {
		$('#interval-unit').text(units[$(this).val()] || 'day(s)');

		// Show/hide weekly days selector.
		$('#weekly-days').toggle($(this).val() === 'WEEKLY');

		// Show/hide monthly pattern selector.
		$('#monthly-pattern').toggle($(this).val() === 'MONTHLY');

		updateCustomRule();
	});

	/**
	 * Toggle nth-weekday sub-panel based on monthly type radio.
	 */
	$('input[name="recurrence_monthly_type"]').on('change', function() {
		$('#monthly-nth-weekday').toggle($(this).val() === 'nth_weekday');
		updateCustomRule();
	});

	/**
	 * Update custom rule on field changes.
	 */
	$('#recurrence_interval, #recurrence_count, #recurrence_until').on('change', updateCustomRule);
	$('input[name="recurrence_byday[]"]').on('change', updateCustomRule);
	$('input[name="recurrence_monthly_ordinals[]"]').on('change', updateCustomRule);
	$('input[name="recurrence_monthly_byday[]"]').on('change', updateCustomRule);
	$('input[name="recurrence_end_type"]').on('change', function() {
		updateCustomRule();
		// Enable/disable related fields.
		$('#recurrence_count').prop('disabled', $('input[name="recurrence_end_type"]:checked').val() !== 'count');
		$('#recurrence_until').prop('disabled', $('input[name="recurrence_end_type"]:checked').val() !== 'until');
	});

	/**
	 * Build RRULE string from form fields.
	 */
	function updateCustomRule() {
		if ($('#recurrence_preset').val() !== 'custom') {
			return;
		}

		var parts = ['FREQ=' + $('#recurrence_freq').val()];

		var interval = parseInt($('#recurrence_interval').val(), 10) || 1;
		if (interval > 1) {
			parts.push('INTERVAL=' + interval);
		}

		// Days of week (WEEKLY).
		if ($('#recurrence_freq').val() === 'WEEKLY') {
			var days = [];
			$('input[name="recurrence_byday[]"]:checked').each(function() {
				days.push($(this).val());
			});
			if (days.length > 0) {
				parts.push('BYDAY=' + days.join(','));
			}
		}

		// nth-weekday-of-month (MONTHLY + nth_weekday). Cartesian product of
		// ordinals x weekdays: e.g., [1, 3] x [MO] -> "1MO,3MO".
		if ($('#recurrence_freq').val() === 'MONTHLY' &&
			$('input[name="recurrence_monthly_type"]:checked').val() === 'nth_weekday') {
			var ordinals = [];
			$('input[name="recurrence_monthly_ordinals[]"]:checked').each(function() {
				ordinals.push($(this).val());
			});
			var monthlyDays = [];
			$('input[name="recurrence_monthly_byday[]"]:checked').each(function() {
				monthlyDays.push($(this).val());
			});
			if (ordinals.length > 0 && monthlyDays.length > 0) {
				var bydayParts = [];
				ordinals.forEach(function(ord) {
					monthlyDays.forEach(function(day) {
						bydayParts.push(ord + day);
					});
				});
				parts.push('BYDAY=' + bydayParts.join(','));
			}
		}

		// End condition.
		var endType = $('input[name="recurrence_end_type"]:checked').val();
		if (endType === 'count') {
			var count = parseInt($('#recurrence_count').val(), 10) || 10;
			parts.push('COUNT=' + count);
		} else if (endType === 'until') {
			var until = $('#recurrence_until').val();
			if (until) {
				parts.push('UNTIL=' + until.replace(/-/g, '') + 'T235959Z');
			}
		}

		$('#recurrence_rule').val(parts.join(';'));
	}
});
