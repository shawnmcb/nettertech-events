/* eslint-disable no-unsanitized/property -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * NetterTech Events - Filters
 *
 * @package NetterTechEvents
 */

(function() {
    'use strict';

    /**
     * Event filters component.
     */
    class NetterTechEventsFilters {
        constructor(element) {
            this.element = element;
            this.form = element.querySelector('.nte-filters__form');
            this.targetSelector = element.dataset.target;
            this.target = document.querySelector(this.targetSelector);

            this.init();
        }

        init() {
            if (!this.form) return;

            this.bindEvents();
        }

        bindEvents() {
            this.form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.applyFilters();
            });

            // Auto-apply on change for select/checkbox/date fields
            this.form.querySelectorAll('select, input[type="checkbox"], input[type="date"]').forEach(input => {
                input.addEventListener('change', () => this.applyFilters());
            });

            // Debounced search
            const searchInput = this.form.querySelector('input[type="search"]');
            if (searchInput) {
                let timeout;
                searchInput.addEventListener('input', () => {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => this.applyFilters(), 300);
                });
            }

            // Reset button
            const resetButton = this.form.querySelector('.nte-filters__reset');
            if (resetButton) {
                resetButton.addEventListener('click', () => this.resetFilters());
            }
        }

        getFilters() {
            const formData = new FormData(this.form);
            const filters = {};

            for (const [key, value] of formData.entries()) {
                if (value) {
                    if (filters[key]) {
                        // Handle multiple values (checkboxes)
                        if (Array.isArray(filters[key])) {
                            filters[key].push(value);
                        } else {
                            filters[key] = [filters[key], value];
                        }
                    } else {
                        filters[key] = value;
                    }
                }
            }

            return filters;
        }

        async applyFilters() {
            if (!this.target) return;

            const filters = this.getFilters();
            const params = new URLSearchParams();

            for (const [key, value] of Object.entries(filters)) {
                // Strip a trailing [] from the form input name (e.g. category[])
                // so we don't double-bracket the URL parameter (category[][]).
                // Multi-value detection adds [] back exactly once.
                const baseKey = key.endsWith('[]') ? key.slice(0, -2) : key;
                if (Array.isArray(value)) {
                    value.forEach(v => params.append(baseKey + '[]', v));
                } else {
                    params.append(baseKey, value);
                }
            }

            // Show loading state
            this.target.classList.add('nte-loading');

            try {
                const apiUrl = window.nettertechEvents?.apiUrl || '/wp-json/nettertech-events/v1/';
                const response = await fetch(`${apiUrl}occurrences?${params.toString()}`, {
                    headers: {
                        'X-WP-Nonce': window.nettertechEvents?.nonce || ''
                    }
                });

                if (response.ok) {
                    const events = await response.json();
                    this.updateTarget(events);
                }
            } catch (error) {
                console.error('Failed to fetch filtered events:', error);
            } finally {
                this.target.classList.remove('nte-loading');
            }

            // Update URL without reload
            const url = new URL(window.location);
            url.search = params.toString();
            window.history.pushState({}, '', url);
        }

        updateTarget(events) {
            // This will be expanded based on target type (grid, calendar, etc.)
            if (events.length === 0) {
                this.target.innerHTML = `
                    <div class="nte-empty">
                        ${window.nettertechEvents?.strings?.noEvents || 'No events found.'}
                    </div>
                `;
                return;
            }

            // Trigger custom event for other components to handle
            this.target.dispatchEvent(new CustomEvent('ve:events-updated', {
                detail: { events }
            }));
        }

        resetFilters() {
            this.form.reset();
            this.applyFilters();
        }
    }

    /**
     * Initialize filters on DOM ready.
     */
    function init() {
        document.querySelectorAll('.nte-filters').forEach(el => {
            new NetterTechEventsFilters(el);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Export for external use
    window.NetterTechEventsFilters = NetterTechEventsFilters;
})();
