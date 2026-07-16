/* eslint-disable no-restricted-syntax -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * QR Code Logo Settings JavaScript
 *
 * Handles logo selection via media library, live QR preview updates,
 * and radio option interaction on the plugin settings page.
 *
 * @package NetterTechEvents
 * @since 1.0.0
 */

(function($) {
	'use strict';

	const data = window.nettertechEventsQrLogoSettings || {};
	const ajaxUrl = data.ajaxUrl || '';
	const nonce = data.nonce || '';
	const i18n = data.i18n || {};

	let currentLogoMode = data.logoMode || 'none';
	let currentLogoId = data.logoId || 0;
	let mediaFrame = null;
	let previewDebounceTimer = null;

	/**
	 * Initialize the settings page functionality.
	 */
	function init() {
		bindLogoOptionClicks();
		bindSelectLogoButton();
		bindRemoveLogoButton();
		bindSettingsChanges();
		bindKeyboardNavigation();
		bindColorRadioSync();
		bindCustomColorPicker();
		bindOpacitySlider();

		// Generate initial preview.
		generatePreview();
	}

	/**
	 * Bind click events on logo option cards.
	 */
	function bindLogoOptionClicks() {
		$('.nte-qr-logo-option').on('click', function(e) {
			// Don't trigger if clicking a button inside the option.
			if ($(e.target).is('button')) {
				return;
			}

			const $option = $(this);
			const mode = $option.data('mode');

			// Skip disabled options.
			if ($option.hasClass('nte-qr-logo-option--disabled')) {
				return;
			}

			selectLogoMode(mode);
		});
	}

	/**
	 * Select a logo mode and update UI.
	 *
	 * @param {string} mode The mode to select: 'none', 'site', or 'custom'.
	 */
	function selectLogoMode(mode) {
		currentLogoMode = mode;

		// Update hidden input.
		$('#qr_default_logo_mode').val(mode);

		// Update visual selection.
		$('.nte-qr-logo-option').removeClass('nte-qr-logo-option--selected')
			.attr('aria-checked', 'false');
		$(`.nte-qr-logo-option[data-mode="${mode}"]`).addClass('nte-qr-logo-option--selected')
			.attr('aria-checked', 'true');

		// If selecting custom and no logo yet, open media library.
		if (mode === 'custom' && !currentLogoId) {
			openMediaLibrary();
		}

		// Regenerate preview.
		generatePreviewDebounced();
	}

	/**
	 * Bind the select logo button.
	 */
	function bindSelectLogoButton() {
		$('#nte-qr-select-logo').on('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			openMediaLibrary();
		});
	}

	/**
	 * Open the WordPress media library.
	 */
	function openMediaLibrary() {
		// Create frame if it doesn't exist.
		if (!mediaFrame) {
			mediaFrame = wp.media({
				title: i18n.selectLogo || 'Select Logo',
				button: {
					text: i18n.useLogo || 'Use this logo'
				},
				library: {
					type: 'image'
				},
				multiple: false
			});

			// Handle selection.
			mediaFrame.on('select', function() {
				const attachment = mediaFrame.state().get('selection').first().toJSON();
				setCustomLogo(attachment.id, attachment.sizes.thumbnail?.url || attachment.url);
			});
		}

		mediaFrame.open();
	}

	/**
	 * Set the custom logo.
	 *
	 * @param {number} id  Attachment ID.
	 * @param {string} url Thumbnail URL.
	 */
	function setCustomLogo(id, url) {
		currentLogoId = id;
		currentLogoMode = 'custom';

		// Update hidden inputs.
		$('#qr_default_logo_id').val(id);
		$('#qr_default_logo_mode').val('custom');

		// Update preview thumbnail.
		$('#nte-qr-custom-logo-preview').html(
			`<img src="${escapeHtml(url)}" alt="Custom logo">`
		);

		// Update button text.
		$('#nte-qr-select-logo').text(i18n.changeLogo || 'Change');

		// Add remove button if not present.
		if ($('#nte-qr-remove-logo').length === 0) {
			$('#nte-qr-select-logo').after(
				'<button type="button" class="button-link" id="nte-qr-remove-logo" style="margin-left: 8px; color: #b32d2e;">Remove</button>'
			);
			bindRemoveLogoButton();
		}

		// Select custom option.
		selectLogoMode('custom');
	}

	/**
	 * Bind the remove logo button.
	 */
	function bindRemoveLogoButton() {
		$(document).off('click', '#nte-qr-remove-logo').on('click', '#nte-qr-remove-logo', function(e) {
			e.preventDefault();
			e.stopPropagation();
			removeCustomLogo();
		});
	}

	/**
	 * Remove the custom logo.
	 */
	function removeCustomLogo() {
		currentLogoId = 0;

		// Update hidden input.
		$('#qr_default_logo_id').val(0);

		// Reset preview to placeholder.
		$('#nte-qr-custom-logo-preview').html(
			'<span class="dashicons dashicons-plus-alt2"></span>'
		);

		// Update button text.
		$('#nte-qr-select-logo').text(i18n.selectLogo || 'Select');

		// Remove the remove button.
		$('#nte-qr-remove-logo').remove();

		// If custom was selected, switch to none.
		if (currentLogoMode === 'custom') {
			selectLogoMode('none');
		} else {
			generatePreviewDebounced();
		}
	}

	/**
	 * Bind settings input changes for live preview.
	 */
	function bindSettingsChanges() {
		$('.nte-qr-setting-input').on('change input', function() {
			generatePreviewDebounced();
		});
	}

	/**
	 * Bind keyboard navigation for logo options.
	 */
	function bindKeyboardNavigation() {
		$('.nte-qr-logo-option').on('keydown', function(e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$(this).click();
			}
		});
	}

	/**
	 * Bind color radio buttons to sync value into the hidden input.
	 */
	function bindColorRadioSync() {
		$('input[name="nte-qr-settings-color"]').on('change', function() {
			$('#qr_foreground_color').val('#' + $(this).val()).trigger('change');
		});
	}

	/**
	 * Bind custom color picker swatch interactions.
	 */
	function bindCustomColorPicker() {
		const $swatch = $('.nte-qr-color-swatch--custom');
		const $picker = $('.nte-qr-custom-color-picker');
		const $radio = $('.nte-qr-custom-color-radio');

		if (!$swatch.length || !$picker.length || !$radio.length) {
			return;
		}

		$swatch.on('click', function(e) {
			e.preventDefault();
			$picker[0].click();
		});

		$picker.on('input', function() {
			const hex = $picker.val().replace('#', '');
			$radio.val(hex).prop('checked', true);
			$swatch.css('background', $picker.val()).addClass('has-color');
			$('#qr_foreground_color').val('#' + hex).trigger('change');
		});
	}

	/**
	 * Bind opacity slider to update the displayed percentage label
	 * and toggle the checkerboard transparency indicator.
	 */
	function bindOpacitySlider() {
		$('#qr_bg_opacity').on('input', function() {
			$('#qr_bg_opacity_value').text($(this).val() + '%');
			updateTransparencyIndicator(parseInt($(this).val(), 10));
		});

		// Set initial state.
		updateTransparencyIndicator(parseInt($('#qr_bg_opacity').val() || '100', 10));
	}

	/**
	 * Toggle checkerboard background on preview when opacity < 100%.
	 *
	 * @param {number} opacity Current opacity value (0-100).
	 */
	function updateTransparencyIndicator(opacity) {
		var $preview = $('#nte-qr-settings-preview');
		if (opacity < 100) {
			$preview.addClass('nte-qr-preview--transparent');
		} else {
			$preview.removeClass('nte-qr-preview--transparent');
		}
	}

	/**
	 * Generate preview with debounce.
	 */
	function generatePreviewDebounced() {
		clearTimeout(previewDebounceTimer);
		previewDebounceTimer = setTimeout(generatePreview, 300);
	}

	/**
	 * Generate QR code preview via AJAX.
	 */
	function generatePreview() {
		const $preview = $('#nte-qr-preview-image');
		const $loading = $('#nte-qr-preview-loading');

		// Show loading state.
		$loading.addClass('is-loading');

		// Gather current settings.
		const color = $('#qr_foreground_color').val().replace('#', '');
		const bgColor = $('#qr_background_color').val().replace('#', '');
		const content = i18n.previewContent || window.location.origin;

		const bgOpacity = $('#qr_bg_opacity').val();

		// Build request data.
		const dotStyle = $('input[name="nettertech_events_settings[qr_dot_style]"]:checked').val() || 'rounded';
		const finderStyle = $('input[name="nettertech_events_settings[qr_finder_style]"]:checked').val() || 'square';

		const requestData = {
			action: 'nettertech_events_generate_qr',
			content: content,
			color: color,
			bg_color: bgColor,
			bg_opacity: bgOpacity,
			scale: $('#qr_scale').val(),
			dot_style: dotStyle,
			finder_style: finderStyle,
			logo_mode: currentLogoMode,
			logo_id: currentLogoId,
			_wpnonce: nonce
		};

		// Update transparency indicator based on current opacity.
		updateTransparencyIndicator(parseInt(bgOpacity || '100', 10));

		$.ajax({
			url: ajaxUrl,
			method: 'POST',
			data: requestData,
			success: function(response) {
				if (response.success && response.data.dataUri) {
					$preview.one('load', function() {
						$('#nte-qr-preview-dimensions').text(
							this.naturalWidth + ' \u00d7 ' + this.naturalHeight + ' px'
						);
					});
					$preview.attr('src', response.data.dataUri);
				}
			},
			error: function(xhr, status, error) {
				console.error('QR preview generation failed:', error);
			},
			complete: function() {
				$loading.removeClass('is-loading');
			}
		});
	}

	/**
	 * Escape HTML special characters.
	 *
	 * @param {string} str String to escape.
	 * @return {string} Escaped string.
	 */
	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	// Initialize on DOM ready.
	$(document).ready(init);

})(jQuery);
