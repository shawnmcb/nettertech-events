/* eslint-disable no-unused-vars -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * Layout Editor
 *
 * Native HTML5 drag-and-drop interface for reordering event page components.
 *
 * @package NetterTechEvents
 */

(function() {
    'use strict';

    /**
     * Initialize layout editor when DOM is ready.
     */
    document.addEventListener('DOMContentLoaded', function() {
        const editors = document.querySelectorAll('.nte-layout-editor');
        editors.forEach(initEditor);
    });

    /**
     * Initialize a single layout editor instance.
     *
     * @param {HTMLElement} editor The editor container element.
     */
    function initEditor(editor) {
        const list = editor.querySelector('.nte-layout-editor__list');
        const orderInput = editor.querySelector('#nte-layout-order');
        const visibilityInput = editor.querySelector('#nte-layout-visibility');
        const resetButton = editor.querySelector('.nte-layout-editor__reset');
        const context = editor.dataset.context || 'settings';

        if (!list) {
            return;
        }

        let draggedItem = null;

        // Drag and drop handlers
        list.addEventListener('dragstart', handleDragStart);
        list.addEventListener('dragend', handleDragEnd);
        list.addEventListener('dragover', handleDragOver);
        list.addEventListener('drop', handleDrop);
        list.addEventListener('dragleave', handleDragLeave);

        // Visibility toggle handlers
        const checkboxes = list.querySelectorAll('.nte-layout-editor__checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', handleVisibilityChange);
        });

        // Reset button
        if (resetButton) {
            resetButton.addEventListener('click', handleReset);
        }

        // Keyboard navigation
        list.addEventListener('keydown', handleKeyDown);

        // Mode toggle for metabox (use global vs customize)
        const modeRadios = editor.querySelectorAll('input[name="nettertech_events_layout_mode"]');
        const customWrapper = editor.querySelector('.nte-layout-editor__custom-wrapper');

        modeRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                if (customWrapper) {
                    customWrapper.classList.toggle('is-active', this.value === 'custom');
                }
                updateHiddenInputs();
            });
        });

        /**
         * Handle drag start.
         *
         * @param {DragEvent} e The drag event.
         */
        function handleDragStart(e) {
            const item = e.target.closest('.nte-layout-editor__item');
            if (!item) {
                return;
            }

            draggedItem = item;
            item.classList.add('is-dragging');

            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', item.dataset.componentId);

            // Needed for Firefox
            setTimeout(function() {
                item.style.opacity = '0.5';
            }, 0);
        }

        /**
         * Handle drag end.
         *
         * @param {DragEvent} e The drag event.
         */
        function handleDragEnd(e) {
            const item = e.target.closest('.nte-layout-editor__item');
            if (item) {
                item.classList.remove('is-dragging');
                item.style.opacity = '';
            }

            // Remove all drag-over classes
            list.querySelectorAll('.is-drag-over, .is-drag-over-bottom').forEach(function(el) {
                el.classList.remove('is-drag-over', 'is-drag-over-bottom');
            });

            draggedItem = null;
        }

        /**
         * Handle drag over.
         *
         * @param {DragEvent} e The drag event.
         */
        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            const item = e.target.closest('.nte-layout-editor__item');
            if (!item || item === draggedItem) {
                return;
            }

            // Remove previous indicators
            list.querySelectorAll('.is-drag-over, .is-drag-over-bottom').forEach(function(el) {
                el.classList.remove('is-drag-over', 'is-drag-over-bottom');
            });

            // Determine drop position based on mouse position
            const rect = item.getBoundingClientRect();
            const midpoint = rect.top + rect.height / 2;

            if (e.clientY < midpoint) {
                item.classList.add('is-drag-over');
            } else {
                item.classList.add('is-drag-over-bottom');
            }
        }

        /**
         * Handle drag leave.
         *
         * @param {DragEvent} e The drag event.
         */
        function handleDragLeave(e) {
            const item = e.target.closest('.nte-layout-editor__item');
            if (item) {
                item.classList.remove('is-drag-over', 'is-drag-over-bottom');
            }
        }

        /**
         * Handle drop.
         *
         * @param {DragEvent} e The drag event.
         */
        function handleDrop(e) {
            e.preventDefault();

            const item = e.target.closest('.nte-layout-editor__item');
            if (!item || !draggedItem || item === draggedItem) {
                return;
            }

            // Determine insert position
            const rect = item.getBoundingClientRect();
            const midpoint = rect.top + rect.height / 2;
            const insertBefore = e.clientY < midpoint;

            // Move the element
            if (insertBefore) {
                list.insertBefore(draggedItem, item);
            } else {
                list.insertBefore(draggedItem, item.nextSibling);
            }

            // Update hidden inputs
            updateHiddenInputs();

            // Trigger preview update if available
            updatePreview();
        }

        /**
         * Handle visibility toggle change.
         *
         * @param {Event} e The change event.
         */
        function handleVisibilityChange(e) {
            const checkbox = e.target;
            const item = checkbox.closest('.nte-layout-editor__item');

            if (item) {
                item.classList.toggle('nte-layout-editor__item--hidden', !checkbox.checked);
                item.setAttribute('aria-selected', checkbox.checked ? 'true' : 'false');
            }

            updateHiddenInputs();
            updatePreview();
        }

        /**
         * Handle reset button click.
         */
        function handleReset() {
            const defaultOrder = ['header', 'featured_image', 'occurrence_date', 'upcoming_dates', 'description', 'more_dates'];

            // Reorder items
            defaultOrder.forEach(function(componentId) {
                const item = list.querySelector('[data-component-id="' + componentId + '"]');
                if (item) {
                    list.appendChild(item);
                }
            });

            // Reset visibility
            list.querySelectorAll('.nte-layout-editor__item').forEach(function(item) {
                const checkbox = item.querySelector('.nte-layout-editor__checkbox');
                if (checkbox) {
                    checkbox.checked = true;
                    item.classList.remove('nte-layout-editor__item--hidden');
                    item.setAttribute('aria-selected', 'true');
                }
            });

            updateHiddenInputs();
            updatePreview();
        }

        /**
         * Handle keyboard navigation.
         *
         * @param {KeyboardEvent} e The keyboard event.
         */
        function handleKeyDown(e) {
            const item = document.activeElement.closest('.nte-layout-editor__item');
            if (!item) {
                return;
            }

            const items = Array.from(list.querySelectorAll('.nte-layout-editor__item'));
            const currentIndex = items.indexOf(item);

            switch (e.key) {
                case 'ArrowUp':
                    e.preventDefault();
                    if (e.altKey && currentIndex > 0) {
                        // Move item up
                        list.insertBefore(item, items[currentIndex - 1]);
                        item.focus();
                        updateHiddenInputs();
                        updatePreview();
                    } else if (!e.altKey && currentIndex > 0) {
                        // Focus previous item
                        items[currentIndex - 1].focus();
                    }
                    break;

                case 'ArrowDown':
                    e.preventDefault();
                    if (e.altKey && currentIndex < items.length - 1) {
                        // Move item down
                        list.insertBefore(item, items[currentIndex + 2] || null);
                        item.focus();
                        updateHiddenInputs();
                        updatePreview();
                    } else if (!e.altKey && currentIndex < items.length - 1) {
                        // Focus next item
                        items[currentIndex + 1].focus();
                    }
                    break;

                case ' ':
                case 'Enter':
                    // Toggle visibility
                    const checkbox = item.querySelector('.nte-layout-editor__checkbox');
                    if (checkbox && e.target === item) {
                        e.preventDefault();
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    break;

                case 'Home':
                    e.preventDefault();
                    if (e.altKey) {
                        // Move to top
                        list.insertBefore(item, items[0]);
                        item.focus();
                        updateHiddenInputs();
                        updatePreview();
                    } else {
                        items[0].focus();
                    }
                    break;

                case 'End':
                    e.preventDefault();
                    if (e.altKey) {
                        // Move to bottom
                        list.appendChild(item);
                        item.focus();
                        updateHiddenInputs();
                        updatePreview();
                    } else {
                        items[items.length - 1].focus();
                    }
                    break;
            }
        }

        /**
         * Update hidden input values.
         */
        function updateHiddenInputs() {
            // Check if using global mode
            const modeGlobal = editor.querySelector('input[name="nettertech_events_layout_mode"][value="global"]');
            if (modeGlobal && modeGlobal.checked) {
                // Clear inputs to use global
                if (orderInput) {
                    orderInput.value = '';
                }
                if (visibilityInput) {
                    visibilityInput.value = '';
                }
                return;
            }

            // Get current order
            const order = [];
            const visibility = {};

            list.querySelectorAll('.nte-layout-editor__item').forEach(function(item) {
                const componentId = item.dataset.componentId;
                const checkbox = item.querySelector('.nte-layout-editor__checkbox');

                order.push(componentId);
                visibility[componentId] = checkbox ? checkbox.checked : true;
            });

            // Update inputs
            if (orderInput) {
                orderInput.value = order.join(',');
            }
            if (visibilityInput) {
                visibilityInput.value = JSON.stringify(visibility);
            }
        }

        /**
         * Update preview iframe if available.
         */
        function updatePreview() {
            const iframe = editor.querySelector('.nte-layout-editor__preview-frame');
            if (!iframe) {
                return;
            }

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

            // Encode for URL
            const encoded = btoa(JSON.stringify(config));

            // Get base URL from iframe src or data attribute
            let baseUrl = iframe.dataset.previewUrl || iframe.src.split('?')[0];

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
            const separator = baseUrl.indexOf('?') > -1 ? '&' : '?';
            iframe.src = baseUrl + separator
                + 'nettertech_events_preview_layout=' + encodeURIComponent(encoded)
                + '&_wpnonce=' + encodeURIComponent(nonce);
        }
    }
})();
