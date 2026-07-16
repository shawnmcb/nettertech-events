/* eslint-disable no-unused-vars, no-unsanitized/property, no-alert -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * Event QR Code Generator JavaScript
 *
 * Handles QR code generation, color selection, logo selection,
 * download functionality, and stale state detection in the event editor.
 *
 * @package NetterTechEvents
 * @since 1.0.0
 */

(function($) {
	'use strict';

	// Data passed from PHP via wp_localize_script().
	const data = window.nettertechEventsEventQr || {};
	const ajaxUrl = data.ajaxUrl || '';
	const nonce = data.nonce || '';
	const eventUrl = data.eventUrl || '';
	const eventTitle = data.eventTitle || '';
	const eventSlug = data.eventSlug || '';
	const i18n = data.i18n || {};

	const preview = document.querySelector('#nte-event-qr-preview img');
	const downloadBtn = document.getElementById('nte-event-qr-download');
	const staleWarning = document.getElementById('nte-event-qr-stale-warning');
	const titleInput = document.getElementById('event_title');
	const slugInput = document.getElementById('event_slug');
	const logoModeInput = document.getElementById('nte-event-qr-logo-mode');
	const logoIdInput = document.getElementById('nte-event-qr-logo-id');
	const customThumb = document.getElementById('nte-event-qr-custom-thumb');

	let currentDataUri = '';
	let currentLogoMode = data.logoMode || 'default';
	let currentLogoId = parseInt(data.logoId, 10) || 0;
	let mediaFrame = null;

	/**
	 * Sanitize a string for use as a filename.
	 *
	 * @param {string} str - The string to sanitize.
	 * @return {string} Sanitized filename.
	 */
	function sanitizeFilename(str) {
		return str
			.toLowerCase()
			.replace(/\s+/g, '-')
			.replace(/[<>:"/\\|?*#&=+%]/g, '')
			.replace(/-+/g, '-')
			.replace(/^-+|-+$/g, '')
			.substring(0, 100) || 'event-qr';
	}

	/**
	 * Convert data URI to Blob.
	 *
	 * @param {string} dataUri - The data URI to convert.
	 * @return {Blob} The blob object.
	 */
	function dataUriToBlob(dataUri) {
		const parts = dataUri.split(',');
		const mime = parts[0].match(/:(.*?);/)[1];
		const binary = atob(parts[1]);
		const array = new Uint8Array(binary.length);
		for (let i = 0; i < binary.length; i++) {
			array[i] = binary.charCodeAt(i);
		}
		return new Blob([array], { type: mime });
	}

	/**
	 * Escape HTML special characters.
	 *
	 * @param {string} str - The string to escape.
	 * @return {string} Escaped string.
	 */
	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	/**
	 * Select a logo option by mode.
	 *
	 * @param {string} mode - The logo mode to select.
	 */
	function selectLogoOption(mode) {
		currentLogoMode = mode;

		// Update hidden input.
		if (logoModeInput) {
			logoModeInput.value = mode;
		}

		// Update visual selection.
		document.querySelectorAll('.nte-event-qr-logo-opt').forEach(function(opt) {
			const isSelected = opt.dataset.mode === mode;
			opt.classList.toggle('nte-event-qr-logo-opt--selected', isSelected);
			opt.setAttribute('aria-checked', isSelected ? 'true' : 'false');
			const radio = opt.querySelector('input[type="radio"]');
			if (radio) {
				radio.checked = isSelected;
			}
		});

		// If custom is selected and no logo yet, open media library.
		if (mode === 'custom' && !currentLogoId) {
			openMediaLibrary();
		}

		// Regenerate QR.
		generateQRCode();
	}

	/**
	 * Open the WordPress media library for logo selection.
	 */
	function openMediaLibrary() {
		if (!mediaFrame) {
			mediaFrame = wp.media({
				title: i18n.selectLogo || 'Select Logo',
				button: { text: i18n.useLogo || 'Use this logo' },
				library: { type: 'image' },
				multiple: false
			});

			mediaFrame.on('select', function() {
				const attachment = mediaFrame.state().get('selection').first().toJSON();
				setCustomLogo(attachment.id, attachment.sizes?.thumbnail?.url || attachment.url);
			});
		}
		mediaFrame.open();
	}

	/**
	 * Set the custom logo.
	 *
	 * @param {number} id - The attachment ID.
	 * @param {string} url - The thumbnail URL.
	 */
	function setCustomLogo(id, url) {
		currentLogoId = id;

		// Update hidden input.
		if (logoIdInput) {
			logoIdInput.value = id;
		}

		// Update thumbnail.
		if (customThumb) {
			customThumb.innerHTML = '<img src="' + escapeHtml(url) + '" alt="" style="width: 20px; height: 20px; object-fit: contain;">';
		}

		// Select custom option.
		selectLogoOption('custom');
	}

	/**
	 * Generate QR code with the selected color and logo using local service.
	 */
	async function generateQRCode() {
		const color = document.querySelector('input[name="nte-event-qr-color"]:checked')?.value || '000000';

		try {
			const response = await fetch(ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'nettertech_events_generate_qr',
					content: eventUrl,
					color: color,
					logo_mode: currentLogoMode,
					logo_id: currentLogoId,
					_wpnonce: nonce
				})
			});

			const result = await response.json();

			if (!result.success) {
				throw new Error(result.data?.message || 'QR generation failed');
			}

			currentDataUri = result.data.dataUri;
			preview.src = currentDataUri;
		} catch (e) {
			// Warn, not error: the QR preview is best-effort admin UI.
			// Network failures (e.g. containerized environments, slow first
			// load) should not trip console-error assertions or alarm users.
			console.warn('QR preview unavailable:', e.message || e);
		}
	}

	/**
	 * Download the current QR code as an image file.
	 */
	function downloadQRCode() {
		if (!currentDataUri) return;

		try {
			const blob = dataUriToBlob(currentDataUri);
			const url = URL.createObjectURL(blob);
			const filename = sanitizeFilename(eventTitle) + '_QR.png';

			const a = document.createElement('a');
			a.href = url;
			a.download = filename;
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		} catch (e) {
			console.error('Download failed:', e);
			alert(i18n.downloadFailed || 'Download failed.');
		}
	}

	/**
	 * Check if the title or slug has changed from the original values.
	 */
	function checkStaleState() {
		const currentTitle = titleInput?.value || '';
		const currentSlug = slugInput?.value || '';

		const isStale = (currentTitle !== eventTitle) || (currentSlug !== eventSlug);

		if (staleWarning) {
			staleWarning.style.display = isStale ? 'block' : 'none';
		}
	}

	// Initialize: only generate if the event has been saved (has a URL).
	// On "Add New Event", eventUrl is empty or malformed — attempting a
	// fetch would produce a console error with no user-visible benefit.
	if (eventUrl && eventUrl.length > 1) {
		generateQRCode();
	} else if (preview) {
		preview.alt = i18n.saveFirst || 'Save the event to generate a QR code preview';
		preview.src = '';
		preview.style.display = 'none';
		var placeholder = document.createElement('p');
		placeholder.className = 'description';
		placeholder.textContent = i18n.saveFirst || 'Save the event to generate a QR code preview.';
		preview.parentNode.insertBefore(placeholder, preview);
	}

	// Event listeners.
	downloadBtn?.addEventListener('click', downloadQRCode);

	document.querySelectorAll('input[name="nte-event-qr-color"]').forEach(function(radio) {
		radio.addEventListener('change', generateQRCode);
	});

	// Custom color picker.
	const customColorSwatch = document.querySelector('#nte-qr-postbox .nte-qr-color-swatch--custom');
	const customColorPicker = document.querySelector('#nte-qr-postbox .nte-qr-custom-color-picker');
	const customColorRadio = document.querySelector('#nte-qr-postbox .nte-qr-custom-color-radio');

	if (customColorSwatch && customColorPicker && customColorRadio) {
		customColorSwatch.addEventListener('click', function(e) {
			e.preventDefault();
			customColorPicker.click();
		});

		customColorPicker.addEventListener('input', function() {
			const hex = customColorPicker.value.replace('#', '');
			customColorRadio.value = hex;
			customColorRadio.checked = true;
			customColorSwatch.style.background = customColorPicker.value;
			customColorSwatch.classList.add('has-color');
			generateQRCode();
		});
	}

	// Logo option clicks.
	document.querySelectorAll('.nte-event-qr-logo-opt').forEach(function(option) {
		option.addEventListener('click', function(e) {
			// Skip disabled options.
			if (option.classList.contains('nte-event-qr-logo-opt--disabled')) {
				return;
			}
			selectLogoOption(option.dataset.mode);
		});

		// Keyboard navigation.
		option.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				if (!option.classList.contains('nte-event-qr-logo-opt--disabled')) {
					selectLogoOption(option.dataset.mode);
				}
			}
		});
	});

	// Watch for title/slug changes.
	titleInput?.addEventListener('input', checkStaleState);
	slugInput?.addEventListener('input', checkStaleState);
})(jQuery);
