/* eslint-disable no-restricted-syntax, no-unsanitized/property, no-unused-vars -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * NetterTech Events - Calendar
 *
 * Interactive calendar with month/week/day views.
 *
 * @package NetterTechEvents
 */

(function() {
    'use strict';

    /**
     * Calendar component.
     */
    class NetterTechEventsCalendar {
        constructor(element, options = {}) {
            this.element = element;
            this.options = {
                view: element.dataset.view || 'month',
                apiUrl: window.nettertechEvents?.apiUrl || '/wp-json/nettertech-events/v1/',
                ...options
            };

            this.currentDate = this.parseInitialDate(element);
            this.strings = window.nettertechEvents?.strings || {};
            this.events = [];
            this.isLoading = false;
            this.isTouchDevice = false;
            this.activeTooltip = null;
            this.tooltipDismissTimer = null;
            this.tooltipElements = [];
            this.supportsAnchor = CSS.supports('anchor-name: --test');
            this.resizeObserver = null;

            this.init();
        }

        init() {
            this.cacheElements();
            this.bindEvents();
            this.bindResponsiveView();
            this.showLoading();
            this.fetchEvents().then(() => this.render());

            this.element.dispatchEvent(new CustomEvent('nte-calendar:init', {
                detail: { instance: this, config: this.options },
                bubbles: true
            }));
        }

        parseInitialDate(element) {
            var initialDate = element.dataset.initialDate;
            if (initialDate) {
                var parts = initialDate.split('-');
                if (parts.length >= 2) {
                    var d = new Date(
                        parseInt(parts[0], 10),
                        parseInt(parts[1], 10) - 1,
                        parts[2] ? parseInt(parts[2], 10) : 1
                    );
                    if (!isNaN(d.getTime())) {
                        return d;
                    }
                }
            }
            return new Date();
        }

        updateUrlParam() {
            if (!window.history || !window.history.replaceState) {
                return;
            }
            var url = new URL(window.location);
            var year = this.currentDate.getFullYear();
            var month = String(this.currentDate.getMonth() + 1).padStart(2, '0');

            if (this.options.view === 'day') {
                var day = String(this.currentDate.getDate()).padStart(2, '0');
                url.searchParams.set('date', year + '-' + month + '-' + day);
                url.searchParams.delete('month');
            } else {
                url.searchParams.set('month', year + '-' + month);
                url.searchParams.delete('date');
            }
            window.history.replaceState({}, '', url);
        }

        cacheElements() {
            this.header = this.element.querySelector('.nte-calendar__header');
            this.title = this.element.querySelector('.nte-calendar__title');
            this.grid = this.element.querySelector('.nte-calendar__grid');
            this.prevButton = this.element.querySelector('.nte-calendar__nav-button--prev');
            this.nextButton = this.element.querySelector('.nte-calendar__nav-button--next');
            this.viewButtons = this.element.querySelectorAll('.nte-calendar__view-button');
        }

        bindEvents() {
            if (this.prevButton) {
                this.prevButton.addEventListener('click', () => this.navigate(-1));
            }

            if (this.nextButton) {
                this.nextButton.addEventListener('click', () => this.navigate(1));
            }

            this.viewButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    const view = e.target.dataset.view;
                    if (view) this.setView(view);
                });
            });

            // Touch detection.
            this.element.addEventListener('touchstart', () => {
                this.isTouchDevice = true;
            }, { once: true, passive: true });

            // Keyboard navigation.
            this.element.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') this.navigate(-1);
                if (e.key === 'ArrowRight') this.navigate(1);
            });

            // Event popup close on escape.
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this.closePopup();
            });

            // Close popup on click outside.
            document.addEventListener('click', (e) => {
                const popup = document.querySelector('.nte-calendar-popup');
                if (popup && !popup.contains(e.target) && !e.target.closest('.nte-calendar__event')) {
                    this.closePopup();
                }
            });
        }

        /**
         * Switch view based on element width.
         *
         * Below 600px the month and week grids become unusable — switch to day
         * view automatically so the toggle button state matches what renders.
         * Uses ResizeObserver so the check responds to container resizes, not
         * just window resize (handles sidebar collapse, embed contexts, etc.).
         */
        bindResponsiveView() {
            if (typeof ResizeObserver === 'undefined') return;

            const NARROW_BREAKPOINT = 600;

            this.resizeObserver = new ResizeObserver((entries) => {
                for (const entry of entries) {
                    const width = entry.contentRect.width;
                    const currentView = this.options.view;

                    if (width < NARROW_BREAKPOINT && currentView !== 'day') {
                        // Store the preferred wide-viewport view so it can be
                        // restored when the user widens the viewport again.
                        this.options.preferredWideView = currentView;
                        this.setView('day');
                    } else if (width >= NARROW_BREAKPOINT && this.options.preferredWideView) {
                        const restore = this.options.preferredWideView;
                        this.options.preferredWideView = null;
                        this.setView(restore);
                    }
                }
            });

            this.resizeObserver.observe(this.element);
        }

        navigate(direction) {
            this.element.dispatchEvent(new CustomEvent('nte-calendar:navigate', {
                detail: { direction, view: this.options.view, date: new Date(this.currentDate) },
                bubbles: true
            }));

            switch (this.options.view) {
                case 'month':
                    this.currentDate.setMonth(this.currentDate.getMonth() + direction);
                    break;
                case 'week':
                    this.currentDate.setDate(this.currentDate.getDate() + (direction * 7));
                    break;
                case 'day':
                    this.currentDate.setDate(this.currentDate.getDate() + direction);
                    break;
            }

            this.updateUrlParam();
            this.fetchEvents().then(() => this.render());
        }

        setView(view) {
            const oldView = this.options.view;
            this.options.view = view;
            this.element.className = this.element.className.replace(/nte-calendar--\w+/g, '');
            this.element.classList.add(`nte-calendar--${view}`);

            this.viewButtons.forEach(button => {
                const isActive = button.dataset.view === view;
                button.classList.toggle('nte-calendar__view-button--active', isActive);
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            this.element.dispatchEvent(new CustomEvent('nte-calendar:view-change', {
                detail: { from: oldView, to: view },
                bubbles: true
            }));

            this.fetchEvents().then(() => this.render());
        }

        async fetchEvents() {
            if (this.isLoading) return;
            this.isLoading = true;
            this.showLoading();

            const start = this.getStartDate();
            const end = this.getEndDate();

            try {
                const response = await fetch(
                    `${this.options.apiUrl}occurrences?start=${start.toISOString()}&end=${end.toISOString()}`,
                    {
                        headers: {
                            'X-WP-Nonce': window.nettertechEvents?.nonce || ''
                        }
                    }
                );

                if (response.ok) {
                    const data = await response.json();
                    // API returns array directly when using start/end params.
                    this.events = Array.isArray(data) ? data : (data.events || []);

                    this.element.dispatchEvent(new CustomEvent('nte-calendar:data-loaded', {
                        detail: { data: this.events, view: this.options.view },
                        bubbles: true
                    }));
                }
            } catch (error) {
                console.error('Failed to fetch events:', error);
            } finally {
                this.isLoading = false;
            }
        }

        getStartDate() {
            const date = new Date(this.currentDate);

            switch (this.options.view) {
                case 'month':
                    date.setDate(1);
                    date.setDate(date.getDate() - date.getDay());
                    break;
                case 'week':
                    date.setDate(date.getDate() - date.getDay());
                    break;
            }

            date.setHours(0, 0, 0, 0);
            return date;
        }

        getEndDate() {
            const date = new Date(this.getStartDate());

            switch (this.options.view) {
                case 'month':
                    date.setDate(date.getDate() + 42); // 6 weeks.
                    break;
                case 'week':
                    date.setDate(date.getDate() + 7);
                    break;
                case 'day':
                    date.setDate(date.getDate() + 1);
                    break;
            }

            return date;
        }

        render() {
            this.hideLoading();
            this.updateTitle();
            this.destroyTooltips();
            this.renderGrid();
            this.createTooltips();
            this.bindTooltipEvents();

            this.element.dispatchEvent(new CustomEvent('nte-calendar:rendered', {
                detail: { view: this.options.view, grid: this.grid, events: this.events },
                bubbles: true
            }));
        }

        updateTitle() {
            if (!this.title) return;

            const options = { month: 'long', year: 'numeric' };

            switch (this.options.view) {
                case 'month':
                    this.title.textContent = this.currentDate.toLocaleDateString('en-US', options);
                    break;
                case 'week':
                    const start = this.getStartDate();
                    const end = new Date(start);
                    end.setDate(end.getDate() + 6);
                    this.title.textContent = `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
                    break;
                case 'day':
                    this.title.textContent = this.currentDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
                    break;
            }
        }

        renderGrid() {
            if (!this.grid) return;

            switch (this.options.view) {
                case 'month':
                    this.renderMonthView();
                    break;
                case 'week':
                    this.renderWeekView();
                    break;
                case 'day':
                    this.renderDayView();
                    break;
            }
        }

        renderMonthView() {
            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const start = this.getStartDate();
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let html = '<div class="nte-calendar__header-row" role="row">' + days.map(d => `<div class="nte-calendar__day-header" role="columnheader">${d}</div>`).join('') + '</div>';

            for (let i = 0; i < 42; i++) {
                if (i % 7 === 0) html += '<div class="nte-calendar__week-row" role="row">';
                const date = new Date(start);
                date.setDate(date.getDate() + i);

                const isOtherMonth = date.getMonth() !== this.currentDate.getMonth();
                const isToday = date.getTime() === today.getTime();
                const dayEvents = this.getEventsForDate(date);

                let classes = 'nte-calendar__day';
                if (isOtherMonth) classes += ' nte-calendar__day--other-month';
                if (isToday) classes += ' nte-calendar__day--today';

                html += `
                    <div class="${classes}" data-date="${date.toISOString().split('T')[0]}" role="gridcell">
                        <div class="nte-calendar__day-number">${date.getDate()}</div>
                        ${dayEvents.slice(0, 3).map(e =>
                            `<a href="${this.escapeHtml(e.event?.permalink || '#')}"
                                class="nte-calendar__event"
                                data-event-id="${e.id}"
                                title="${this.escapeHtml(e.event?.title || 'Event')}">${this.escapeHtml(e.event?.title || 'Event')}</a>`
                        ).join('')}
                        ${dayEvents.length > 3 ? `<button type="button" class="nte-calendar__more" data-date="${date.toISOString().split('T')[0]}" aria-label="${dayEvents.length - 3} more event${dayEvents.length - 3 === 1 ? '' : 's'} on ${date.toLocaleDateString('en-US', { month: 'long', day: 'numeric' })}">+${dayEvents.length - 3} more</button>` : ''}
                    </div>
                ${i % 7 === 6 ? '</div>' : ''}`;
            }

            this.grid.innerHTML = html;

            // Bind more buttons.
            this.grid.querySelectorAll('.nte-calendar__more').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.showDayEvents(btn.dataset.date);
                });
            });
        }

        renderWeekView() {
            const start = this.getStartDate();
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let html = '<div class="nte-calendar__week-header">';
            html += '<div class="nte-calendar__time-gutter"></div>';

            for (let i = 0; i < 7; i++) {
                const date = new Date(start);
                date.setDate(date.getDate() + i);
                const isToday = date.getTime() === today.getTime();
                const classes = 'nte-calendar__day-header' + (isToday ? ' nte-calendar__day-header--today' : '');

                html += `<div class="${classes}">
                    <span class="nte-calendar__day-name">${date.toLocaleDateString('en-US', { weekday: 'short' })}</span>
                    <span class="nte-calendar__day-date">${date.getDate()}</span>
                </div>`;
            }
            html += '</div>';

            html += '<div class="nte-calendar__week-body">';

            // Time slots for all 24 hours.
            for (let hour = 0; hour <= 23; hour++) {
                html += '<div class="nte-calendar__time-row">';
                html += `<div class="nte-calendar__time-label">${this.formatHour(hour)}</div>`;

                for (let day = 0; day < 7; day++) {
                    const date = new Date(start);
                    date.setDate(date.getDate() + day);
                    const dateStr = date.toISOString().split('T')[0];
                    const dayEvents = this.getEventsForDate(date);

                    html += `<div class="nte-calendar__time-cell" data-date="${dateStr}" data-hour="${hour}">`;

                    // Show events starting in this hour.
                    dayEvents.forEach(e => {
                        const eventHour = this.parseDateTime(e.start_datetime).hours;
                        if (eventHour === hour) {
                            const time = this.formatTime(e.start_datetime);
                            html += `<a href="${this.escapeHtml(e.event?.permalink || '#')}"
                                class="nte-calendar__event nte-calendar__event--week"
                                data-event-id="${e.id}">
                                <span class="nte-calendar__event-time">${time}</span>
                                <span class="nte-calendar__event-title">${this.escapeHtml(e.event?.title || 'Event')}</span>
                            </a>`;
                        }
                    });

                    html += '</div>';
                }

                html += '</div>';
            }

            html += '</div>';

            this.grid.innerHTML = html;

            // Auto-scroll to relevant hour.
            const weekBody = this.grid.querySelector('.nte-calendar__week-body');
            if (weekBody) {
                this.autoScrollToHour(weekBody);
            }
        }

        renderDayView() {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const currentHour = new Date().getHours();
            const dayEvents = this.getEventsForDate(this.currentDate);

            let html = '<div class="nte-calendar__day-grid">';

            // Time slots for all 24 hours.
            for (let hour = 0; hour <= 23; hour++) {
                const isCurrent = this.currentDate.getTime() === today.getTime() && hour === currentHour;
                const classes = 'nte-calendar__time-slot' + (isCurrent ? ' nte-calendar__time-slot--current' : '');

                html += `<div class="${classes}">`;
                html += `<div class="nte-calendar__time-label">${this.formatHour(hour)}</div>`;
                html += '<div class="nte-calendar__time-content">';

                // Show events starting in this hour.
                dayEvents.forEach(e => {
                    const eventHour = this.parseDateTime(e.start_datetime).hours;
                    if (eventHour === hour) {
                        const time = this.formatTime(e.start_datetime);
                        const venue = e.event?.venue_name || '';
                        html += `<a href="${this.escapeHtml(e.event?.permalink || '#')}"
                            class="nte-calendar__event nte-calendar__event--day"
                            data-event-id="${e.id}">
                            <span class="nte-calendar__event-time">${time}</span>
                            <span class="nte-calendar__event-title">${this.escapeHtml(e.event?.title || 'Event')}</span>
                            ${venue ? `<span class="nte-calendar__event-venue">${this.escapeHtml(venue)}</span>` : ''}
                        </a>`;
                    }
                });

                html += '</div></div>';
            }

            html += '</div>';

            this.grid.innerHTML = html;

            // Auto-scroll to relevant hour.
            const dayGrid = this.grid.querySelector('.nte-calendar__day-grid');
            if (dayGrid) {
                this.autoScrollToHour(dayGrid);
            }
        }

        showLoading() {
            this.element.classList.add('nte-calendar--loading');
            if (this.grid) {
                this.grid.setAttribute('aria-busy', 'true');
                // Add loading overlay if not already present.
                if (!this.grid.querySelector('.nte-calendar__loading')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'nte-calendar__loading';
                    overlay.innerHTML = '<div class="nte-loading-spinner"></div>';
                    this.grid.appendChild(overlay);
                }
            }
        }

        hideLoading() {
            this.element.classList.remove('nte-calendar--loading');
            if (this.grid) {
                this.grid.setAttribute('aria-busy', 'false');
                const overlay = this.grid.querySelector('.nte-calendar__loading');
                if (overlay) {
                    overlay.remove();
                }
            }
        }

        /**
         * Auto-scroll a time container to the current hour (today) or 8 AM.
         * Deferred to next frame so the browser has computed layout.
         */
        autoScrollToHour(container) {
            requestAnimationFrame(() => {
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                const isToday = this.currentDate.getFullYear() === today.getFullYear()
                    && this.currentDate.getMonth() === today.getMonth()
                    && this.currentDate.getDate() === today.getDate();

                const targetHour = isToday ? new Date().getHours() : 8;
                const totalHours = 24;
                const scrollTarget = (targetHour / totalHours) * container.scrollHeight;

                container.scrollTop = scrollTarget;
            });
        }

        // === Tooltip Methods ===

        createTooltips() {
            // Skip in Beaver Builder editing mode.
            if (window.FLBuilder) return;

            this.grid.querySelectorAll('.nte-calendar__event[data-event-id]').forEach(el => {
                const eventId = parseInt(el.dataset.eventId, 10);
                const occurrence = this.events.find(e => e.id === eventId);
                if (!occurrence) return;

                const tooltipId = `nte-tooltip-${eventId}`;

                // Anchor name on trigger.
                const anchorName = `--nte-evt-${eventId}`;
                el.style.anchorName = anchorName;
                el.setAttribute('aria-describedby', tooltipId);

                // Build tooltip HTML.
                const ev = occurrence.event || {};
                const tickets = occurrence.tickets || {};
                const startTime = this.formatTime(occurrence.start_datetime);
                const endTime = occurrence.end_datetime
                    ? this.formatTime(occurrence.end_datetime)
                    : '';
                const timeText = endTime ? `${startTime} – ${endTime}` : startTime;

                let ticketsHtml = '';
                if (tickets.min_price !== undefined && tickets.min_price !== null) {
                    const priceText = tickets.min_price === tickets.max_price
                        ? `$${tickets.min_price}`
                        : `$${tickets.min_price} – $${tickets.max_price}`;

                    let availClass = '';
                    let availText = this.strings.available || 'Tickets available';
                    if (tickets.sold_out) {
                        availClass = ' nte-calendar__tooltip-availability--sold-out';
                        availText = this.strings.soldOut || 'Sold out';
                    } else if (tickets.low_stock) {
                        availClass = ' nte-calendar__tooltip-availability--low-stock';
                        availText = this.strings.limited || 'Low stock';
                    }

                    ticketsHtml = `
                        <div class="nte-calendar__tooltip-tickets">
                            <span class="nte-calendar__tooltip-price">${this.escapeHtml(priceText)}</span>
                            <span class="nte-calendar__tooltip-availability${availClass}">${this.escapeHtml(availText)}</span>
                        </div>`;
                }

                const imageHtml = ev.image?.url
                    ? `<img class="nte-calendar__tooltip-image" src="${this.escapeHtml(ev.image.url)}" alt="${this.escapeHtml(ev.image.alt || ev.title || '')}" loading="lazy">`
                    : '';

                const venueHtml = ev.venue_name
                    ? `<div class="nte-calendar__tooltip-venue">${this.escapeHtml(ev.venue_name)}</div>`
                    : '';

                const excerptHtml = ev.excerpt
                    ? `<p class="nte-calendar__tooltip-excerpt">${this.escapeHtml(ev.excerpt)}</p>`
                    : '';

                const tooltip = document.createElement('div');
                tooltip.className = 'nte-calendar__tooltip';
                tooltip.id = tooltipId;
                tooltip.setAttribute('popover', 'auto');
                tooltip.setAttribute('role', 'complementary');
                tooltip.style.positionAnchor = anchorName;

                tooltip.innerHTML = `
                    <div class="nte-calendar__tooltip-inner">
                        ${imageHtml}
                        <div class="nte-calendar__tooltip-body">
                            <div class="nte-calendar__tooltip-title">${this.escapeHtml(ev.title || 'Event')}</div>
                            <div class="nte-calendar__tooltip-time">${timeText}</div>
                            ${venueHtml}
                            ${excerptHtml}
                            ${ticketsHtml}
                            <a href="${this.escapeHtml(ev.permalink || '#')}" class="nte-calendar__tooltip-link">View details</a>
                        </div>
                    </div>`;

                this.element.appendChild(tooltip);
                this.tooltipElements.push({ trigger: el, tooltip });
            });
        }

        bindTooltipEvents() {
            if (window.FLBuilder) return;

            this.tooltipElements.forEach(({ trigger, tooltip }) => {
                // Desktop hover.
                trigger.addEventListener('mouseenter', () => {
                    if (this.isTouchDevice) return;
                    this.showTooltip(trigger, tooltip);
                });

                trigger.addEventListener('mouseleave', (e) => {
                    if (this.isTouchDevice) return;
                    // Keep open if moving to tooltip.
                    if (e.relatedTarget && tooltip.contains(e.relatedTarget)) return;
                    this.scheduleDismiss(tooltip);
                });

                tooltip.addEventListener('mouseenter', () => {
                    this.cancelDismiss();
                });

                tooltip.addEventListener('mouseleave', (e) => {
                    if (this.isTouchDevice) return;
                    // Keep open if moving back to trigger.
                    if (e.relatedTarget === trigger || trigger.contains(e.relatedTarget)) return;
                    this.scheduleDismiss(tooltip);
                });

                // Keyboard: focus/blur.
                trigger.addEventListener('focus', () => {
                    this.showTooltip(trigger, tooltip);
                });

                trigger.addEventListener('blur', (e) => {
                    // Keep open if focus moves into tooltip.
                    if (e.relatedTarget && tooltip.contains(e.relatedTarget)) return;
                    this.scheduleDismiss(tooltip);
                });

                // Keep open while focus inside tooltip; close when focus leaves.
                tooltip.addEventListener('focusout', (e) => {
                    if (e.relatedTarget && (tooltip.contains(e.relatedTarget) || e.relatedTarget === trigger)) return;
                    this.hideTooltip(tooltip);
                });

                // Touch: tap toggles popover, intercept link navigation.
                trigger.addEventListener('click', (e) => {
                    if (!this.isTouchDevice) return;
                    e.preventDefault();
                    e.stopPropagation();

                    if (this.activeTooltip === tooltip && tooltip.matches(':popover-open')) {
                        this.hideTooltip(tooltip);
                    } else {
                        this.showTooltip(trigger, tooltip);
                    }
                });

                // Dismiss via native popover toggle event.
                tooltip.addEventListener('toggle', (e) => {
                    if (e.newState === 'closed') {
                        this.activeTooltip = null;
                    }
                });
            });
        }

        showTooltip(trigger, tooltip) {
            // Hide any other open tooltip first.
            if (this.activeTooltip && this.activeTooltip !== tooltip) {
                this.hideTooltip(this.activeTooltip);
            }
            this.cancelDismiss();

            try {
                tooltip.showPopover();
            } catch (_) {
                // Already open or unsupported.
                return;
            }

            this.activeTooltip = tooltip;

            if (!this.supportsAnchor) {
                this.positionTooltipFallback(trigger, tooltip);
            } else {
                // Reset any previous flip override.
                tooltip.style.positionArea = '';
                // CSS flip-block may not trigger when the browser clamps
                // the tooltip to viewport bounds. Detect overlap and force
                // position below the trigger.
                requestAnimationFrame(() => {
                    if (!tooltip.matches(':popover-open')) return;
                    const tRect = trigger.getBoundingClientRect();
                    const ttRect = tooltip.getBoundingClientRect();
                    if (ttRect.bottom > tRect.top && ttRect.top < tRect.bottom) {
                        tooltip.style.positionArea = 'bottom center';
                    }
                });
            }
        }

        hideTooltip(tooltip) {
            this.cancelDismiss();
            try {
                tooltip.hidePopover();
            } catch (_) {
                // Already closed.
            }
            if (this.activeTooltip === tooltip) {
                this.activeTooltip = null;
            }
        }

        scheduleDismiss(tooltip) {
            this.cancelDismiss();
            this.tooltipDismissTimer = setTimeout(() => {
                this.hideTooltip(tooltip);
            }, 150);
        }

        cancelDismiss() {
            if (this.tooltipDismissTimer) {
                clearTimeout(this.tooltipDismissTimer);
                this.tooltipDismissTimer = null;
            }
        }

        positionTooltipFallback(trigger, tooltip) {
            const triggerRect = trigger.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();
            const gap = 4;

            // Preferred: above trigger, centered.
            let top = triggerRect.top - tooltipRect.height - gap;
            let left = triggerRect.left + (triggerRect.width / 2) - (tooltipRect.width / 2);

            // Flip below if not enough room above.
            if (top < 0) {
                top = triggerRect.bottom + gap;
            }

            // Clamp to viewport horizontally.
            left = Math.max(8, Math.min(left, window.innerWidth - tooltipRect.width - 8));

            tooltip.style.top = `${top}px`;
            tooltip.style.left = `${left}px`;
        }

        destroyTooltips() {
            this.cancelDismiss();
            this.tooltipElements.forEach(({ trigger, tooltip }) => {
                trigger.style.anchorName = '';
                trigger.removeAttribute('aria-describedby');
                tooltip.remove();
            });
            this.tooltipElements = [];
            this.activeTooltip = null;
        }

        getEventsForDate(date) {
            // Use local date string for comparison (YYYY-MM-DD).
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const dateStr = `${year}-${month}-${day}`;

            return this.events.filter(e => {
                // Extract date part directly from datetime string (YYYY-MM-DD HH:MM:SS).
                const eventDateStr = e.start_datetime.split(' ')[0];
                return eventDateStr === dateStr;
            });
        }

        showDayEvents(dateStr) {
            // Parse date string (YYYY-MM-DD) as local date to avoid timezone issues.
            const [year, month, day] = dateStr.split('-').map(Number);
            const date = new Date(year, month - 1, day);
            const dayEvents = this.getEventsForDate(date);

            const formattedDate = date.toLocaleDateString('en-US', {
                weekday: 'long',
                month: 'long',
                day: 'numeric'
            });

            let html = `
                <div class="nte-calendar-popup" role="dialog" aria-modal="true" aria-label="Events for ${formattedDate}">
                    <div class="nte-calendar-popup__header">
                        <h3 class="nte-calendar-popup__title">${formattedDate}</h3>
                        <button type="button" class="nte-calendar-popup__close" aria-label="Close"><svg class="nte-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
                    </div>
                    <div class="nte-calendar-popup__content">
                        ${dayEvents.map(e => `
                            <a href="${this.escapeHtml(e.event?.permalink || '#')}" class="nte-calendar-popup__event" data-event-id="${e.id}">
                                <span class="nte-calendar-popup__time">${this.formatTime(e.start_datetime)}</span>
                                <span class="nte-calendar-popup__name">${this.escapeHtml(e.event?.title || 'Event')}</span>
                                ${e.event?.venue_name ? `<span class="nte-calendar-popup__venue">${this.escapeHtml(e.event.venue_name)}</span>` : ''}
                            </a>
                        `).join('')}
                    </div>
                </div>
            `;

            // Remove existing popup.
            this.closePopup();

            // Add new popup.
            const popup = document.createElement('div');
            popup.className = 'nte-calendar-popup-overlay';
            popup.innerHTML = html;
            document.body.appendChild(popup);

            // Bind close button.
            popup.querySelector('.nte-calendar-popup__close').addEventListener('click', () => this.closePopup());

            // Focus trap.
            popup.querySelector('.nte-calendar-popup__close').focus();

            // Extension lifecycle: lets add-ons decorate the day-events popup
            // (entries carry data-event-id) after every open, mirroring
            // nte-calendar:rendered for the grid.
            this.element.dispatchEvent(new CustomEvent('nte-calendar:popup-rendered', {
                detail: { popup, events: dayEvents, date: dateStr },
                bubbles: true
            }));
        }

        closePopup() {
            const popup = document.querySelector('.nte-calendar-popup-overlay');
            if (popup) {
                popup.remove();
            }
        }

        formatHour(hour) {
            const period = hour >= 12 ? 'PM' : 'AM';
            const displayHour = hour > 12 ? hour - 12 : (hour === 0 ? 12 : hour);
            return `${displayHour} ${period}`;
        }

        /**
         * Parse datetime string (YYYY-MM-DD HH:MM:SS) to extract time components.
         * Avoids timezone issues by parsing string directly instead of using Date.
         */
        parseDateTime(datetime) {
            const [datePart, timePart] = datetime.split(' ');
            const [hours, minutes, seconds] = timePart.split(':').map(Number);
            return { hours, minutes, seconds };
        }

        formatTime(datetime) {
            const { hours, minutes } = this.parseDateTime(datetime);
            const period = hours >= 12 ? 'PM' : 'AM';
            const displayHour = hours > 12 ? hours - 12 : (hours === 0 ? 12 : hours);
            return `${displayHour}:${minutes.toString().padStart(2, '0')} ${period}`;
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }
    }

    /**
     * Initialize calendars on DOM ready.
     */
    function init() {
        document.querySelectorAll('.nte-calendar').forEach(el => {
            new NetterTechEventsCalendar(el);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Export for external use.
    window.NetterTechEventsCalendar = NetterTechEventsCalendar;
})();
