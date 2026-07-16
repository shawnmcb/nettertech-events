/* eslint-disable no-unsanitized/property, no-alert -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * QR Generator Admin Page Scripts.
 *
 * Expects nettertechEventsQRGenerator object to be localized with:
 * - ajaxUrl: Admin AJAX URL
 * - nonce: Security nonce
 * - siteUrl: Site home URL
 * - i18n: Translated strings
 *
 * @package NetterTechEvents
 */
(function() {
	'use strict';

	// Bail if localized data not available.
	if (typeof nettertechEventsQRGenerator === 'undefined') {
		console.error('NetterTechEvents: QR Generator localization missing');
		return;
	}

	const settings = nettertechEventsQRGenerator;
	const { ajaxUrl, nonce, siteUrl, i18n } = settings;

	const contentInput = document.getElementById('nte-qr-content');
	const generateBtn = document.getElementById('nte-qr-generate');
	const downloadBtn = document.getElementById('nte-qr-download');
	const downloadWrap = document.getElementById('nte-qr-download-wrap');
	const preview = document.getElementById('nte-qr-preview');
	const logoIdInput = document.getElementById('nte-qr-logo-id');
	const customThumb = document.getElementById('nte-qr-custom-thumb');
	const selectLogoBtn = document.getElementById('nte-qr-select-logo');
	const bgColorInput = document.getElementById('nte-qr-bg-color');
	const bgOpacityInput = document.getElementById('nte-qr-bg-opacity');
	const bgOpacityValue = document.getElementById('nte-qr-bg-opacity-value');
	const scaleSelect = document.getElementById('nte-qr-scale');
	const dimensionsEl = document.getElementById('nte-qr-dimensions');

	let currentDataUri = '';
	let currentFilename = 'qr-code';
	let currentLogoId = 0;
	let mediaFrame = null;

	/**
	 * Sanitize a string for use as a filename.
	 * Uses hyphens as word separator.
	 */
	function sanitizeFilename(str) {
		return str
			.toLowerCase()
			.replace(/\s+/g, '-')
			.replace(/[<>:"/\\|?*#&=+%]/g, '')
			.replace(/-+/g, '-')
			.replace(/^-+|-+$/g, '')
			.substring(0, 100) || 'qr-code';
	}

	/**
	 * Get the current logo mode from selected radio.
	 */
	function getLogoMode() {
		return document.querySelector('input[name="nte-qr-logo-mode"]:checked')?.value || 'default';
	}

	/**
	 * Get the current dot style from selected radio.
	 */
	function getDotStyle() {
		return document.querySelector('input[name="nte-qr-dot-style"]:checked')?.value || 'rounded';
	}

	/**
	 * Get the current finder style from selected radio.
	 */
	function getFinderStyle() {
		return document.querySelector('input[name="nte-qr-finder-style"]:checked')?.value || 'square';
	}

	/**
	 * Select a logo option by mode.
	 */
	function selectLogoOption(mode) {
		const options = document.querySelectorAll('.nte-qr-logo-option');
		options.forEach(opt => {
			const isSelected = opt.dataset.mode === mode;
			opt.classList.toggle('nte-qr-logo-option--selected', isSelected);
			opt.setAttribute('aria-checked', isSelected ? 'true' : 'false');
			const radio = opt.querySelector('input[type="radio"]');
			if (radio) {
				radio.checked = isSelected;
			}
		});

		// If custom is selected and no logo, open media library.
		if (mode === 'custom' && !currentLogoId) {
			openMediaLibrary();
		}

		// Regenerate QR if content exists.
		if (contentInput.value.trim()) {
			generateQRCode();
		}
	}

	/**
	 * Open the WordPress media library.
	 */
	function openMediaLibrary() {
		if (!mediaFrame) {
			mediaFrame = wp.media({
				title: i18n.selectLogo,
				button: { text: i18n.useLogo },
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
	 */
	function setCustomLogo(id, url) {
		currentLogoId = id;
		logoIdInput.value = id;

		// Update thumbnail.
		customThumb.innerHTML = '<img src="' + escapeHtml(url) + '" alt="">';

		// Update button text.
		selectLogoBtn.textContent = i18n.change;

		// Select custom option.
		selectLogoOption('custom');
	}

	/**
	 * Escape HTML special characters.
	 */
	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	/**
	 * Extract page title from URL if it's a local site URL.
	 */
	async function getFilenameForContent(content) {
		content = content.trim();

		if (!content) {
			return 'qr-code';
		}

		// Check if it's a local URL.
		if (content.startsWith(siteUrl) || content.startsWith(siteUrl.replace('https://', 'http://'))) {
			try {
				const response = await fetch(ajaxUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams({
						action: 'nettertech_events_get_page_title',
						url: content,
						_wpnonce: nonce
					})
				});
				const data = await response.json();
				if (data.success && data.data.title) {
					return sanitizeFilename(data.data.title);
				}
			} catch (e) {
				console.warn('Could not fetch page title:', e);
			}
		}

		// For external URLs, use the path/domain.
		if (content.match(/^https?:\/\//)) {
			try {
				const url = new URL(content);
				const path = url.pathname.replace(/^\/|\/$/g, '');
				if (path) {
					return sanitizeFilename(path.split('/').pop() || url.hostname);
				}
				return sanitizeFilename(url.hostname);
			} catch (e) {
				// Invalid URL, fall through.
			}
		}

		// For plain text, sanitize directly.
		return sanitizeFilename(content);
	}

	/**
	 * Generate QR code from content using local service.
	 */
	async function generateQRCode() {
		const content = contentInput.value.trim();
		if (!content) {
			alert(i18n.noContent);
			return;
		}

		const color = document.querySelector('input[name="nte-qr-color"]:checked')?.value || '000000';
		const logoMode = getLogoMode();
		const logoId = currentLogoId;
		const dotStyle = getDotStyle();
		const finderStyle = getFinderStyle();

		// Show loading state.
		preview.innerHTML = '<p class="nte-qr-preview__placeholder">' + escapeHtml(i18n.generating) + '</p>';

		try {
			const response = await fetch(ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'nettertech_events_generate_qr',
					content: content,
					color: color,
					bg_color: bgColorInput.value.replace('#', ''),
					bg_opacity: bgOpacityInput.value,
					scale: scaleSelect.value,
					logo_mode: logoMode,
					logo_id: logoId,
					dot_style: dotStyle,
					finder_style: finderStyle,
					_wpnonce: nonce
				})
			});

			const data = await response.json();

			if (!data.success) {
				throw new Error(data.data?.message || 'QR generation failed');
			}

			currentDataUri = data.data.dataUri;
			preview.innerHTML = '<img src="' + currentDataUri + '" alt="QR Code">';
			downloadWrap.style.display = 'block';

			// Toggle checkerboard when background is transparent.
			preview.classList.toggle(
				'nte-qr-preview--transparent',
				parseInt(bgOpacityInput.value, 10) < 100
			);

			// Show output dimensions once image loads.
			const img = preview.querySelector('img');
			if (img) {
				img.addEventListener('load', function() {
					dimensionsEl.textContent = img.naturalWidth + ' × ' + img.naturalHeight + ' px';
				});
			}

			// Get smart filename.
			currentFilename = await getFilenameForContent(content);
		} catch (e) {
			console.error('QR generation failed:', e);
			preview.innerHTML = '<p class="nte-qr-preview__placeholder">' + escapeHtml(i18n.placeholder) + '</p>';
			alert(i18n.generateFailed);
		}
	}

	/**
	 * Convert data URI to Blob.
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
	 * Download the generated QR code.
	 */
	function downloadQRCode() {
		if (!currentDataUri) {
			return;
		}

		try {
			const blob = dataUriToBlob(currentDataUri);
			const url = URL.createObjectURL(blob);

			const a = document.createElement('a');
			a.href = url;
			a.download = currentFilename + '_QR.png';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		} catch (e) {
			console.error('Download failed:', e);
			alert(i18n.downloadFailed);
		}
	}

	// Event listeners.
	generateBtn.addEventListener('click', generateQRCode);
	downloadBtn.addEventListener('click', downloadQRCode);

	// Generate on Enter key.
	contentInput.addEventListener('keypress', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			generateQRCode();
		}
	});

	// Regenerate when color changes.
	document.querySelectorAll('input[name="nte-qr-color"]').forEach(function(radio) {
		radio.addEventListener('change', function() {
			if (contentInput.value.trim()) {
				generateQRCode();
			}
		});
	});

	// Regenerate when dot style changes.
	document.querySelectorAll('input[name="nte-qr-dot-style"]').forEach(function(radio) {
		radio.addEventListener('change', function() {
			if (contentInput.value.trim()) {
				generateQRCode();
			}
		});
	});

	// Regenerate when finder style changes.
	document.querySelectorAll('input[name="nte-qr-finder-style"]').forEach(function(radio) {
		radio.addEventListener('change', function() {
			if (contentInput.value.trim()) {
				generateQRCode();
			}
		});
	});

	// Regenerate when background color changes.
	bgColorInput.addEventListener('change', function() {
		if (contentInput.value.trim()) {
			generateQRCode();
		}
	});

	// Update opacity label and regenerate.
	bgOpacityInput.addEventListener('input', function() {
		bgOpacityValue.textContent = bgOpacityInput.value + '%';
		if (contentInput.value.trim()) {
			generateQRCode();
		}
	});

	// Regenerate when scale changes.
	scaleSelect.addEventListener('change', function() {
		if (contentInput.value.trim()) {
			generateQRCode();
		}
	});

	// Custom color picker.
	const customColorSwatch = document.querySelector('.nte-qr-color-swatch--custom');
	const customColorPicker = document.querySelector('.nte-qr-custom-color-picker');
	const customColorRadio = document.querySelector('.nte-qr-custom-color-radio');

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
			if (contentInput.value.trim()) {
				generateQRCode();
			}
		});
	}

	// Logo option clicks.
	document.querySelectorAll('.nte-qr-logo-option').forEach(function(option) {
		option.addEventListener('click', function(e) {
			// Don't trigger if clicking a button.
			if (e.target.tagName === 'BUTTON') {
				return;
			}

			// Skip disabled options.
			if (option.classList.contains('nte-qr-logo-option--disabled')) {
				return;
			}

			selectLogoOption(option.dataset.mode);
		});

		// Keyboard navigation.
		option.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				if (!option.classList.contains('nte-qr-logo-option--disabled')) {
					selectLogoOption(option.dataset.mode);
				}
			}
		});
	});

	// Select logo button.
	if (selectLogoBtn) {
		selectLogoBtn.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			openMediaLibrary();
		});
	}
})();
