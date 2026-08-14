/* eslint-disable no-unsanitized/property -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * NetterTech Events - Photo Grid
 *
 * Handles grid rendering, pagination, and filter integration.
 *
 * @package NetterTechEvents
 */

(function() {
    'use strict';

    /**
     * Event grid component.
     */
    class NetterTechEventsGrid {
        constructor(element) {
            this.container = element;
            this.grid = element.querySelector('.nte-grid');
            this.pagination = element.querySelector('.nte-pagination');
            this.loadingOverlay = element.querySelector('.nte-loading-overlay');

            this.perPage = parseInt(element.dataset.perPage, 10) || 12;
            this.layout = element.dataset.layout || 'grid';
            this.columns = parseInt(element.dataset.columns, 10) || 3;
            this.currentPage = 1;
            this.totalPages = 1;
            this.currentFilters = {};
            this.isAjax = element.dataset.ajax === 'true';
            this.isPast = element.dataset.past === 'true';

            this.init();
        }

        init() {
            this.bindEvents();

            // Get initial total pages from pagination.
            if (this.pagination) {
                this.totalPages = parseInt(this.pagination.dataset.total, 10) || 1;
            }
        }

        bindEvents() {
            // Listen for filter updates from filters.js.
            this.grid.addEventListener('ve:events-updated', (e) => {
                this.handleEventsUpdate(e.detail.events);
            });

            // Pagination clicks.
            if (this.pagination) {
                this.pagination.addEventListener('click', (e) => {
                    const button = e.target.closest('[data-page]');
                    if (!button || button.disabled) return;

                    const action = button.dataset.page;
                    if (action === 'prev' && this.currentPage > 1) {
                        this.goToPage(this.currentPage - 1);
                    } else if (action === 'next' && this.currentPage < this.totalPages) {
                        this.goToPage(this.currentPage + 1);
                    } else if (!isNaN(parseInt(action, 10))) {
                        this.goToPage(parseInt(action, 10));
                    }
                });
            }

            // Listen for filter form changes to capture current filters.
            const filterForm = this.container.querySelector('.nte-filters__form');
            if (filterForm) {
                filterForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.currentFilters = this.getFiltersFromForm(filterForm);
                    this.currentPage = 1;
                    this.fetchEvents();
                });

                // Auto-submit on change.
                filterForm.querySelectorAll('select, input[type="checkbox"], input[type="date"]').forEach(input => {
                    input.addEventListener('change', () => {
                        this.currentFilters = this.getFiltersFromForm(filterForm);
                        this.currentPage = 1;
                        this.fetchEvents();
                    });
                });

                // Debounced search.
                const searchInput = filterForm.querySelector('input[type="search"]');
                if (searchInput) {
                    let timeout;
                    searchInput.addEventListener('input', () => {
                        clearTimeout(timeout);
                        timeout = setTimeout(() => {
                            this.currentFilters = this.getFiltersFromForm(filterForm);
                            this.currentPage = 1;
                            this.fetchEvents();
                        }, 300);
                    });
                }

                // Reset button.
                const resetButton = filterForm.querySelector('.nte-filters__reset');
                if (resetButton) {
                    resetButton.addEventListener('click', () => {
                        filterForm.reset();
                        this.currentFilters = {};
                        this.currentPage = 1;
                        this.fetchEvents();
                    });
                }
            }
        }

        getFiltersFromForm(form) {
            const formData = new FormData(form);
            const filters = {};

            // Collect repeated keys as arrays so multi-select fields
            // (e.g. <select multiple name="category[]">) preserve all values.
            // Empty strings are dropped to keep the URL clean.
            for (const [key, value] of formData.entries()) {
                if (!value) continue;
                if (Object.prototype.hasOwnProperty.call(filters, key)) {
                    if (Array.isArray(filters[key])) {
                        filters[key].push(value);
                    } else {
                        filters[key] = [filters[key], value];
                    }
                } else {
                    filters[key] = value;
                }
            }

            return filters;
        }

        async goToPage(page) {
            if (page < 1 || page > this.totalPages) return;

            this.currentPage = page;
            await this.fetchEvents();

            // Scroll to top of grid.
            this.container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        async fetchEvents() {
            if (!this.isAjax) return;

            this.showLoading();

            // Build params field-by-field so multi-value filter values
            // (arrays from <select multiple name="X[]">) become repeated
            // `X[]=a&X[]=b` URL pairs rather than the comma-joined string
            // that an object-spread into URLSearchParams would produce.
            const params = new URLSearchParams();
            params.set('page', this.currentPage.toString());
            params.set('per_page', this.perPage.toString());

            for (const [key, value] of Object.entries(this.currentFilters)) {
                const baseKey = key.endsWith('[]') ? key.slice(0, -2) : key;
                if (Array.isArray(value)) {
                    value.forEach(v => params.append(baseKey + '[]', v));
                } else {
                    params.append(baseKey, value);
                }
            }

            if (this.isPast) {
                params.set('past', 'true');
            }

            try {
                const apiUrl = window.nettertechEvents?.apiUrl || '/wp-json/nettertech-events/v1/';
                const response = await fetch(`${apiUrl}occurrences?${params.toString()}`, {
                    headers: {
                        'X-WP-Nonce': window.nettertechEvents?.nonce || ''
                    }
                });

                if (response.ok) {
                    const data = await response.json();
                    this.totalPages = data.total_pages || 1;
                    this.renderEvents(data.events || []);
                    this.updatePagination();
                    this.updateUrl(params);
                }
            } catch (error) {
                console.error('Failed to fetch events:', error);
                this.renderEmpty();
            } finally {
                this.hideLoading();
            }
        }

        handleEventsUpdate(events) {
            this.renderEvents(events);
        }

        renderEvents(events) {
            if (!events || events.length === 0) {
                this.renderEmpty();
                return;
            }

            const html = events.map(event => this.renderCard(event)).join('');
            this.grid.innerHTML = html;

            // Announce to screen readers.
            this.announceUpdate(events.length);

            // Extension lifecycle: lets add-ons decorate AJAX-rendered cards
            // (each article carries data-event-id), mirroring the calendar's
            // nte-calendar:rendered event.
            this.container.dispatchEvent(new CustomEvent('nte-grid:rendered', {
                detail: { grid: this.grid, events },
                bubbles: true
            }));
        }

        renderCard(occurrence) {
            const event = occurrence.event || {};
            const permalink = event.permalink || '#';
            const startDate = new Date(occurrence.start_datetime);
            const currentYear = new Date().getFullYear();
            const eventYear = startDate.getFullYear();
            const showYear = eventYear !== currentYear;

            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                               'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            const imageHtml = event.image
                ? `<a href="${this.escapeHtml(permalink)}" class="nte-event-card__image-link">
                     <div class="nte-event-card__image">
                       <img src="${this.escapeHtml(event.image.url)}"
                            alt="${this.escapeHtml(event.image.alt || event.title || '')}"
                            class="nte-event-card__img" />
                     </div>
                   </a>`
                : `<a href="${this.escapeHtml(permalink)}" class="nte-event-card__image-link">
                     <div class="nte-event-card__image nte-event-card__image--placeholder">
                       <span class="nte-event-card__placeholder-icon" aria-hidden="true"></span>
                     </div>
                   </a>`;

            const yearHtml = showYear
                ? `<span class="nte-event-card__date-year">${eventYear}</span>`
                : '';

            const venueHtml = event.venue_name
                ? `<p class="nte-event-card__venue">${this.escapeHtml(event.venue_name)}</p>`
                : '';

            return `
                <article class="nte-event-card" data-event-id="${occurrence.id}">
                    ${imageHtml}
                    <div class="nte-event-card__content">
                        <div class="nte-event-card__date">
                            <span class="nte-event-card__date-month">${monthNames[startDate.getMonth()]}</span>
                            <span class="nte-event-card__date-day">${startDate.getDate()}</span>
                            ${yearHtml}
                        </div>
                        <div class="nte-event-card__details">
                            <h3 class="nte-event-card__title">
                                <a href="${this.escapeHtml(permalink)}">${this.escapeHtml(event.title || 'Untitled Event')}</a>
                            </h3>
                            <p class="nte-event-card__time">${occurrence.formatted?.time || ''}</p>
                            ${venueHtml}
                        </div>
                    </div>
                </article>
            `;
        }

        renderEmpty() {
            const message = window.nettertechEvents?.strings?.noEvents || 'No events found.';
            this.grid.innerHTML = `
                <div class="nte-empty">
                    <p>${this.escapeHtml(message)}</p>
                </div>
            `;
        }

        updatePagination() {
            if (!this.pagination) return;

            const prevBtn = this.pagination.querySelector('[data-page="prev"]');
            const nextBtn = this.pagination.querySelector('[data-page="next"]');
            const info = this.pagination.querySelector('.nte-pagination__info');

            if (prevBtn) {
                prevBtn.disabled = this.currentPage <= 1;
                prevBtn.classList.toggle('nte-pagination__link--disabled', this.currentPage <= 1);
            }

            if (nextBtn) {
                nextBtn.disabled = this.currentPage >= this.totalPages;
                nextBtn.classList.toggle('nte-pagination__link--disabled', this.currentPage >= this.totalPages);
            }

            if (info) {
                info.textContent = `Page ${this.currentPage} of ${this.totalPages}`;
            }

            // Update total in data attribute.
            this.pagination.dataset.total = this.totalPages.toString();
        }

        updateUrl(params) {
            const url = new URL(window.location);
            url.search = params.toString();
            window.history.replaceState({}, '', url);
        }

        announceUpdate(count) {
            // Use aria-live region for screen reader announcement.
            const announcement = count === 1
                ? `1 event found`
                : `${count} events found`;

            let liveRegion = this.container.querySelector('.nte-sr-announcement');
            if (!liveRegion) {
                liveRegion = document.createElement('div');
                liveRegion.className = 'nte-sr-announcement';
                liveRegion.setAttribute('aria-live', 'polite');
                liveRegion.setAttribute('aria-atomic', 'true');
                liveRegion.style.cssText = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0';
                this.container.appendChild(liveRegion);
            }
            liveRegion.textContent = announcement;
        }

        showLoading() {
            this.container.classList.add('nte-loading');
            if (this.loadingOverlay) {
                this.loadingOverlay.setAttribute('aria-hidden', 'false');
            }
        }

        hideLoading() {
            this.container.classList.remove('nte-loading');
            if (this.loadingOverlay) {
                this.loadingOverlay.setAttribute('aria-hidden', 'true');
            }
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }
    }

    /**
     * Initialize grids on DOM ready.
     */
    function init() {
        document.querySelectorAll('.nte-event-list').forEach(el => {
            new NetterTechEventsGrid(el);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Export for external use.
    window.NetterTechEventsGrid = NetterTechEventsGrid;
})();
