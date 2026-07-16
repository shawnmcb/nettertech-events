/* eslint-disable no-restricted-syntax -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
(function() {
	'use strict';

	var config = window.nettertechEventsTicketScanResult || {};
	var apiBase = config.apiBase || '';
	var nonce = config.nonce || '';
	var attendeeId = config.attendeeId || '';
	var autoCheckin = Boolean(config.autoCheckin);
	var canCheckIn = Boolean(config.canCheckIn);
	var quantity = parseInt(config.quantity || 0, 10);
	var strings = config.strings || {};

	function updateUI(response) {
		var countEl = document.getElementById('checked-count');
		var statusEl = document.querySelector('.nte-scan-result__status');
		var statusTextEl = statusEl ? statusEl.querySelector('.nte-scan-result__status-text') : null;
		var actionsEl = document.querySelector('.nte-scan-result__actions');
		var resultEl = document.querySelector('.nte-scan-result');

		if (!countEl || !statusEl || !statusTextEl || !resultEl) {
			return;
		}

		var newCount = response.checked_in_count || 0;
		countEl.textContent = newCount;

		resultEl.classList.add('nte-scan-result--success-animation');
		setTimeout(function() {
			resultEl.classList.remove('nte-scan-result--success-animation');
		}, 300);

		statusEl.className = 'nte-scan-result__status';
		if (newCount >= quantity) {
			statusEl.classList.add('nte-scan-result__status--warning');
			statusTextEl.textContent = strings.fullyCheckedIn || 'Fully Checked In';
			if (actionsEl) {
				actionsEl.style.display = 'none';
			}
		} else if (newCount > 0) {
			statusEl.classList.add('nte-scan-result__status--partial');
			statusTextEl.textContent = newCount + ' ' + (strings.of || 'of') + ' ' + quantity + ' ' + (strings.checkedIn || 'Checked In');

			var allBtn = document.getElementById('checkin-all-btn');
			if (allBtn) {
				allBtn.textContent = (strings.checkInAll || 'Check In All') + ' (' + (quantity - newCount) + ')';
			}
		} else {
			statusEl.classList.add('nte-scan-result__status--success');
			statusTextEl.textContent = strings.readyToCheckIn || 'Ready to Check In';
		}
	}

	function apiCall(endpoint, method, callback) {
		var btn = document.querySelector('.nte-scan-result__btn--primary');
		if (btn) {
			btn.disabled = true;
		}

		fetch(apiBase + endpoint, {
			method: method,
			headers: {
				'X-WP-Nonce': nonce,
				'Content-Type': 'application/json'
			}
		})
			.then(function(response) {
				return response.json();
			})
			.then(function(data) {
				callback(data);
				if (btn) {
					btn.disabled = false;
				}
			})
			.catch(function(err) {
				console.error('API error:', err);
				if (btn) {
					btn.disabled = false;
				}
			});
	}

	var checkinBtn = document.getElementById('checkin-btn');
	if (checkinBtn) {
		checkinBtn.addEventListener('click', function() {
			apiCall(attendeeId + '/toggle', 'POST', updateUI);
		});
	}

	var checkinOneBtn = document.getElementById('checkin-one-btn');
	if (checkinOneBtn) {
		checkinOneBtn.addEventListener('click', function() {
			apiCall(attendeeId + '/increment', 'POST', updateUI);
		});
	}

	var checkinAllBtn = document.getElementById('checkin-all-btn');
	if (checkinAllBtn) {
		checkinAllBtn.addEventListener('click', function() {
			apiCall(attendeeId + '/check-in', 'POST', updateUI);
		});
	}

	if (autoCheckin && canCheckIn) {
		if (quantity === 1) {
			apiCall(attendeeId + '/toggle', 'POST', updateUI);
		} else {
			apiCall(attendeeId + '/increment', 'POST', updateUI);
		}
	}
})();
