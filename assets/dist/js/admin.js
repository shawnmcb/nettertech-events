/**
 * NetterTech Events - Admin Scripts
 *
 * @package NetterTechEvents
 */

(function() {
    'use strict';

    /**
     * Admin functionality namespace.
     */
    const NetterTechEventsAdmin = {
        /**
         * Initialize admin features.
         */
        init() {
            this.initDatePickers();
            this.initImageRatioFields();
            this.initThemeColorFields();
            this.initAttendeeBulkSelection();
            this.initVirtualEventToggle();
        },

        /**
         * Initialize date pickers.
         */
        initDatePickers() {
            // Use native date/time inputs - WordPress compatibility
            document.querySelectorAll('.nte-datetime-picker').forEach(input => {
                // Enhance with WordPress date format if needed
                if (input.type !== 'datetime-local') {
                    input.type = 'datetime-local';
                }
            });
        },

        /**
         * Toggle custom image-ratio inputs when the custom preset is selected.
         */
        initImageRatioFields() {
            document.querySelectorAll('.nte-ratio-select').forEach(select => {
                const customInput = select.closest('td')?.querySelector('.nte-ratio-custom');

                if (!customInput) {
                    return;
                }

                const toggleCustom = () => {
                    customInput.style.display = select.value === 'custom' ? 'inline-block' : 'none';
                };

                select.addEventListener('change', toggleCustom);
                toggleCustom();
            });
        },

        /**
         * Toggle and reset theme color fields on the settings page.
         */
        initThemeColorFields() {
            const toggle = document.getElementById('nettertech_events_theme_colors_customize');
            const panel = document.getElementById('nte-theme-colors-custom');
            const resetBtn = document.getElementById('nte-theme-colors-reset');

            if (toggle && panel) {
                toggle.addEventListener('change', function() {
                    panel.style.display = this.checked ? '' : 'none';
                });
            }

            if (resetBtn && panel) {
                resetBtn.addEventListener('click', function(e) {
                    e.preventDefault();

                    panel.querySelectorAll('.nte-theme-color-picker').forEach(picker => {
                        const defaultVal = picker.getAttribute('data-default');

                        if (!defaultVal) {
                            return;
                        }

                        picker.value = defaultVal;
                        const code = picker.parentNode?.querySelector('code');

                        if (code) {
                            code.textContent = defaultVal;
                        }
                    });
                });
            }

            document.querySelectorAll('.nte-theme-color-picker').forEach(picker => {
                picker.addEventListener('input', function() {
                    const code = this.parentNode?.querySelector('code');

                    if (code) {
                        code.textContent = this.value;
                    }
                });
            });
        },

        /**
         * Support select-all and shift-click range selection on the attendees page.
         */
        initAttendeeBulkSelection() {
            const selectAll = document.getElementById('cb-select-all');
            const checkboxes = Array.from(document.querySelectorAll('input[name="attendee_ids[]"]'));
            let lastChecked = null;

            if (!checkboxes.length) {
                return;
            }

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }

            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function(e) {
                    if (e.shiftKey && lastChecked) {
                        const start = checkboxes.indexOf(lastChecked);
                        const end = checkboxes.indexOf(checkbox);
                        const range = checkboxes.slice(
                            Math.min(start, end),
                            Math.max(start, end) + 1
                        );

                        range.forEach(rangeCheckbox => {
                            rangeCheckbox.checked = lastChecked.checked;
                        });
                    }

                    lastChecked = checkbox;

                    if (selectAll) {
                        const allChecked = checkboxes.every(item => item.checked);
                        const someChecked = checkboxes.some(item => item.checked);

                        selectAll.checked = allChecked;
                        selectAll.indeterminate = someChecked && !allChecked;
                    }
                });
            });
        },

        /**
         * Toggle virtual event URL fields on the event editor.
         */
        initVirtualEventToggle() {
            const checkbox = document.getElementById('nettertech_events_is_virtual');
            const urlWrap = document.getElementById('nte-virtual-url-wrap');

            if (!checkbox || !urlWrap) {
                return;
            }

            checkbox.addEventListener('change', function() {
                urlWrap.style.display = this.checked ? '' : 'none';
            });
        }
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => NetterTechEventsAdmin.init());
    } else {
        NetterTechEventsAdmin.init();
    }

    // Export for external use
    window.NetterTechEventsAdmin = NetterTechEventsAdmin;
})();
