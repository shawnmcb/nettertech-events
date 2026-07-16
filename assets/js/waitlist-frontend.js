/* eslint-disable no-restricted-syntax -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * Waitlist frontend - join/leave flow for sold-out events.
 *
 * Handles toggle, form submission, localStorage persistence,
 * and leave action. Relies on `nettertechEventsWaitlist` localized data.
 *
 * @package NetterTechEvents
 */

(function() {
	'use strict';

	var STORAGE_PREFIX = 'nettertech_events_waitlist_';

	/**
	 * Initialize all waitlist panels on the page.
	 */
	function init() {
		var panels = document.querySelectorAll('.nte-waitlist');

		for (var i = 0; i < panels.length; i++) {
			initPanel(panels[i]);
		}
	}

	/**
	 * Initialize a single waitlist panel.
	 *
	 * @param {HTMLElement} container The .nte-waitlist container.
	 */
	function initPanel(container) {
		var occurrenceId = container.getAttribute('data-occurrence-id');
		var toggle       = container.querySelector('.nte-waitlist__toggle');
		var panel        = container.querySelector('.nte-waitlist__panel');
		var submitBtn    = container.querySelector('.nte-waitlist__submit');
		var successEl    = container.querySelector('.nte-waitlist__success');
		var successText  = container.querySelector('.nte-waitlist__success-text');
		var errorEl      = container.querySelector('.nte-waitlist__error');
		var leaveBtn     = container.querySelector('.nte-waitlist__leave');

		if (!toggle || !panel || !submitBtn) {
			return;
		}

		// Always wire up leave handler (needed for localStorage restore too).
		if (leaveBtn) {
			leaveBtn.addEventListener('click', function() {
				var storedData = getStored(occurrenceId);
				if (!storedData || !storedData.email) {
					return;
				}

				// eslint-disable-next-line no-alert
				if (!window.confirm(nettertechEventsWaitlist.i18n.leaveConfirm)) {
					return;
				}

				leaveBtn.disabled = true;
				leaveBtn.textContent = nettertechEventsWaitlist.i18n.leaving;

				fetch(nettertechEventsWaitlist.restUrl + 'leave', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nettertechEventsWaitlist.nonce
					},
					body: JSON.stringify({
						occurrence_id: parseInt(occurrenceId, 10),
						email: storedData.email,
						token: storedData.leaveToken || ''
					})
				})
				.then(function(response) {
					return response.json().then(function(data) {
						return { ok: response.ok, data: data };
					});
				})
				.then(function(result) {
					if (result.ok) {
						clearStored(occurrenceId);
						hideSuccess(container, toggle, successEl, submitBtn);
						leaveBtn.disabled = false;
						leaveBtn.textContent = nettertechEventsWaitlist.i18n.leaveWaitlist;
					} else {
						leaveBtn.disabled = false;
					}
				})
				.catch(function() {
					leaveBtn.disabled = false;
				});
			});
		}

		// Toggle panel.
		toggle.addEventListener('click', function() {
			var expanded = toggle.getAttribute('aria-expanded') === 'true';

			if (expanded) {
				closePanel(toggle, panel);
			} else {
				openPanel(toggle, panel);
			}
		});

		// Submit form.
		submitBtn.addEventListener('click', function() {
			var nameInput  = container.querySelector('.nte-waitlist__input--name');
			var emailInput = container.querySelector('.nte-waitlist__input--email');
			var phoneInput = container.querySelector('.nte-waitlist__input--phone');

			var name  = nameInput ? nameInput.value.trim() : '';
			var email = emailInput ? emailInput.value.trim() : '';
			var phone = phoneInput ? phoneInput.value.trim() : '';

			// Client-side validation.
			if (!name || !email) {
				showError(errorEl, nettertechEventsWaitlist.i18n.requiredFields || 'Please fill in all required fields.');
				if (nameInput) {
					nameInput.setAttribute('aria-invalid', name ? 'false' : 'true');
				}
				if (emailInput) {
					emailInput.setAttribute('aria-invalid', email ? 'false' : 'true');
				}
				// Focus first invalid field.
				if (!name && nameInput) {
					nameInput.focus();
				} else if (!email && emailInput) {
					emailInput.focus();
				}
				return;
			}

			if (!isValidEmail(email)) {
				showError(errorEl, nettertechEventsWaitlist.i18n.invalidEmail || 'Please enter a valid email address.');
				if (emailInput) {
					emailInput.setAttribute('aria-invalid', 'true');
					emailInput.focus();
				}
				return;
			}

			// Clear any previous validation state.
			if (nameInput) {
				nameInput.setAttribute('aria-invalid', 'false');
			}
			if (emailInput) {
				emailInput.setAttribute('aria-invalid', 'false');
			}

			hideError(errorEl);
			submitBtn.disabled = true;
			submitBtn.textContent = nettertechEventsWaitlist.i18n.joining;

			fetch(nettertechEventsWaitlist.restUrl + 'join', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nettertechEventsWaitlist.nonce
				},
				body: JSON.stringify({
					occurrence_id: parseInt(occurrenceId, 10),
					email: email,
					name: name,
					phone: phone || null
				})
			})
			.then(function(response) {
				return response.json().then(function(data) {
					return { ok: response.ok, status: response.status, data: data };
				});
			})
			.then(function(result) {
				if (result.ok) {
					setStored(occurrenceId, {
						email: email,
						position: result.data.position,
						leaveToken: result.data.leave_token || ''
					});
					showSuccess(container, toggle, panel, successEl, successText, result.data.position);
				} else {
					showError(errorEl, result.data.message || nettertechEventsWaitlist.i18n.networkError);
					submitBtn.disabled = false;
					submitBtn.textContent = nettertechEventsWaitlist.i18n.joinWaitlist;
				}
			})
			.catch(function() {
				showError(errorEl, nettertechEventsWaitlist.i18n.networkError);
				submitBtn.disabled = false;
				submitBtn.textContent = nettertechEventsWaitlist.i18n.joinWaitlist;
			});
		});

		// Restore success state from localStorage (after all handlers are registered).
		var stored = getStored(occurrenceId);
		if (stored) {
			showSuccess(container, toggle, panel, successEl, successText, stored.position);
		}
	}

	/**
	 * Open the panel with slide animation.
	 */
	function openPanel(toggle, panel) {
		panel.hidden = false;
		toggle.setAttribute('aria-expanded', 'true');
		toggle.textContent = nettertechEventsWaitlist.i18n.close;

		// Trigger reflow before adding open class for transition.
		panel.offsetHeight; // eslint-disable-line no-unused-expressions
		panel.classList.add('nte-waitlist__panel--open');

		// Focus first input.
		var firstInput = panel.querySelector('input');
		if (firstInput) {
			firstInput.focus();
		}
	}

	/**
	 * Close the panel.
	 */
	function closePanel(toggle, panel) {
		toggle.setAttribute('aria-expanded', 'false');
		toggle.textContent = nettertechEventsWaitlist.i18n.joinWaitlist;
		panel.classList.remove('nte-waitlist__panel--open');

		panel.addEventListener('transitionend', function handler() {
			panel.removeEventListener('transitionend', handler);
			if (toggle.getAttribute('aria-expanded') === 'false') {
				panel.hidden = true;
			}
		});
	}

	/**
	 * Show the success state.
	 */
	function showSuccess(container, toggle, panel, successEl, successText, position) {
		toggle.hidden = true;
		panel.hidden = true;
		panel.classList.remove('nte-waitlist__panel--open');

		var message = nettertechEventsWaitlist.i18n.position.replace('%d', position);
		successText.textContent = message;
		successEl.hidden = false;
	}

	/**
	 * Hide success state and restore toggle.
	 */
	function hideSuccess(container, toggle, successEl, submitBtn) {
		successEl.hidden = true;
		toggle.hidden = false;
		toggle.setAttribute('aria-expanded', 'false');

		// Reset submit button for re-join.
		if (submitBtn) {
			submitBtn.disabled = false;
			submitBtn.textContent = nettertechEventsWaitlist.i18n.joinWaitlist;
		}
	}

	/**
	 * Show an error message.
	 */
	function showError(errorEl, message) {
		if (!errorEl) {
			return;
		}
		errorEl.textContent = message;
		errorEl.hidden = false;
	}

	/**
	 * Hide the error message.
	 */
	function hideError(errorEl) {
		if (!errorEl) {
			return;
		}
		errorEl.hidden = true;
	}

	/**
	 * Basic email validation.
	 */
	function isValidEmail(email) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
	}

	/**
	 * Get stored waitlist data from localStorage.
	 */
	function getStored(occurrenceId) {
		try {
			var raw = localStorage.getItem(STORAGE_PREFIX + occurrenceId);
			if (!raw) {
				return null;
			}
			var data = JSON.parse(raw);

			// Clear stale entries (older than 30 days).
			if (data.timestamp && (Date.now() - data.timestamp > 30 * 24 * 60 * 60 * 1000)) {
				localStorage.removeItem(STORAGE_PREFIX + occurrenceId);
				return null;
			}

			return data;
		} catch (e) {
			return null;
		}
	}

	/**
	 * Store waitlist data in localStorage.
	 */
	function setStored(occurrenceId, data) {
		try {
			data.timestamp = Date.now();
			localStorage.setItem(STORAGE_PREFIX + occurrenceId, JSON.stringify(data));
		} catch (e) {
			// localStorage unavailable (private browsing).
		}
	}

	/**
	 * Clear stored waitlist data.
	 */
	function clearStored(occurrenceId) {
		try {
			localStorage.removeItem(STORAGE_PREFIX + occurrenceId);
		} catch (e) {
			// localStorage unavailable.
		}
	}

	// Initialize when DOM is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
