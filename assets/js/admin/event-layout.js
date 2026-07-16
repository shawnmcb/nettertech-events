/**
 * Event Layout Editor JavaScript
 *
 * Handles the layout mode toggle (global/custom), drag-and-drop reordering,
 * visibility checkbox changes, and live preview updates in the event editor.
 *
 * @package NetterTechEvents
 * @since 1.0.0
 */

(function() {
	'use strict';

	const modeRadios = document.querySelectorAll('input[name="nettertech_events_layout_mode"]');
	const customWrapper = document.querySelector('.nte-layout-editor__custom-wrapper');
	const orderInput = document.getElementById('nte-event-layout-order');
	const visibilityInput = document.getElementById('nte-event-layout-visibility');
	const previewFrame = document.getElementById('nte-layout-preview-frame');
	const refreshButton = document.getElementById('nte-layout-refresh-preview');
	const list = document.getElementById('nte-event-layout-list');

	let previewTimeout = null;

	/**
	 * Update hidden inputs with current layout order and visibility state.
	 */
	function updateHiddenInputs() {
		if (!list) return;

		const order = [];
		const visibility = {};

		list.querySelectorAll('.nte-layout-editor__item').forEach(function(item) {
			const componentId = item.dataset.componentId;
			const checkbox = item.querySelector('.nte-layout-editor__checkbox');

			order.push(componentId);
			visibility[componentId] = checkbox ? checkbox.checked : true;
		});

		if (orderInput) orderInput.value = order.join(',');
		if (visibilityInput) visibilityInput.value = JSON.stringify(visibility);
	}

	/**
	 * Schedule a debounced preview update.
	 */
	function schedulePreviewUpdate() {
		if (previewTimeout) {
			clearTimeout(previewTimeout);
		}
		previewTimeout = setTimeout(updatePreview, 300);
	}

	/**
	 * Update the preview iframe with current layout configuration.
	 */
	function updatePreview() {
		if (!previewFrame || !list) return;

		// Check if custom mode is active
		const customRadio = document.querySelector('input[name="nettertech_events_layout_mode"][value="custom"]');
		if (!customRadio || !customRadio.checked) return;

		// Get current config
		const order = [];
		const visibility = {};

		list.querySelectorAll('.nte-layout-editor__item').forEach(function(item) {
			const componentId = item.dataset.componentId;
			const checkbox = item.querySelector('.nte-layout-editor__checkbox');

			order.push(componentId);
			visibility[componentId] = checkbox ? checkbox.checked : true;
		});

		const config = {
			order: order,
			visibility: visibility
		};

		// Encode for URL (base64)
		const encoded = btoa(JSON.stringify(config));

		// Get base URL
		const baseUrl = previewFrame.dataset.previewUrl || previewFrame.src.split('?')[0];

		// Forward the preview nonce so LayoutService::get_preview_config()
		// can verify before honoring the encoded layout. Falls back to
		// rendering the persisted layout if the nonce is unavailable.
		const nonce = window.nettertechEventsLayoutPreview && window.nettertechEventsLayoutPreview.nonce
			? window.nettertechEventsLayoutPreview.nonce
			: '';
		if (!nonce) {
			return;
		}

		// Update iframe src with preview param + nonce.
		previewFrame.src = baseUrl
			+ '?nettertech_events_preview_layout=' + encodeURIComponent(encoded)
			+ '&_wpnonce=' + encodeURIComponent(nonce);
	}

	// Handle layout mode radio changes
	modeRadios.forEach(function(radio) {
		radio.addEventListener('change', function() {
			if (customWrapper) {
				customWrapper.classList.toggle('is-active', this.value === 'custom');
			}

			// Clear hidden inputs when using global
			if (this.value === 'global') {
				if (orderInput) orderInput.value = '';
				if (visibilityInput) visibilityInput.value = '';
				// Reset preview to show global layout
				if (previewFrame) {
					const baseUrl = previewFrame.dataset.previewUrl || previewFrame.src.split('?')[0];
					previewFrame.src = baseUrl;
				}
			} else {
				// Repopulate from current state
				updateHiddenInputs();
				updatePreview();
			}
		});
	});

	// Listen for drag/drop and visibility changes from layout-editor.js
	if (list) {
		// Create a MutationObserver to detect DOM changes (reordering)
		const observer = new MutationObserver(function() {
			updateHiddenInputs();
			schedulePreviewUpdate();
		});

		observer.observe(list, { childList: true });

		// Listen for visibility checkbox changes
		list.addEventListener('change', function(e) {
			if (e.target.classList.contains('nte-layout-editor__checkbox')) {
				updateHiddenInputs();
				schedulePreviewUpdate();
			}
		});
	}

	// Manual refresh button
	if (refreshButton) {
		refreshButton.addEventListener('click', function() {
			updatePreview();
		});
	}
})();
