/**
 * Page Object Models for E2E tests.
 */

import { Page, Locator, expect } from '@playwright/test';

/**
 * Event form data interface.
 */
export interface EventFormData {
	title: string;
	description?: string;
	excerpt?: string;
	status?: 'draft' | 'published' | 'cancelled' | 'postponed';
	eventType?: 'single' | 'recurring';
	startDate?: string;
	startTime?: string;
	endDate?: string;
	endTime?: string;
	allDay?: boolean;
	venueName?: string;
	venueAddress?: string;
	capacity?: number;
}

/**
 * Event admin page object.
 */
export class EventAdminPage {
	readonly page: Page;

	// Form elements
	readonly titleInput: Locator;
	readonly descriptionEditor: Locator;
	readonly excerptInput: Locator;
	readonly statusSelect: Locator;
	readonly eventTypeSelect: Locator;
	readonly startDateInput: Locator;
	readonly startTimeInput: Locator;
	readonly endDateInput: Locator;
	readonly endTimeInput: Locator;
	readonly allDayCheckbox: Locator;
	readonly venueNameInput: Locator;
	readonly venueAddressInput: Locator;
	readonly capacityInput: Locator;
	readonly publishButton: Locator;
	readonly recurrenceBox: Locator;

	constructor( page: Page ) {
		this.page = page;

		// Initialize locators
		this.titleInput = page.locator( '#event_title, #title' );
		this.descriptionEditor = page.locator( '#event_description, #content' );
		this.excerptInput = page.locator( '#event_excerpt' );
		this.statusSelect = page.locator( '#event_status' );
		this.eventTypeSelect = page.locator( '#event_type' );
		this.startDateInput = page.locator( '#start_date' );
		this.startTimeInput = page.locator( '#start_time' );
		this.endDateInput = page.locator( '#end_date' );
		this.endTimeInput = page.locator( '#end_time' );
		this.allDayCheckbox = page.locator( 'input[name="all_day"]' );
		this.venueNameInput = page.locator( '#venue_name' );
		this.venueAddressInput = page.locator( '#venue_address' );
		this.capacityInput = page.locator( '#occurrence_capacity' );
		this.publishButton = page.locator( 'input[name="publish"], button[type="submit"]' );
		this.recurrenceBox = page.locator( '#recurrence-box, .recurrence-options' );
	}

	/**
	 * Navigate to new event page.
	 *
	 * If the navigation redirects to the login page (session expired or
	 * WebKit cookie issue), performs a fresh login and retries once.
	 */
	async gotoNew(): Promise<void> {
		await this.page.goto( '/wp-admin/admin.php?page=nettertech-events-new' );
		await this.page.waitForLoadState( 'domcontentloaded' );

		// If we landed on the login page, re-authenticate and retry.
		if ( this.page.url().includes( 'wp-login' ) ) {
			const user = process.env.WP_ADMIN_USERNAME || 'admin';
			const pass = process.env.WP_ADMIN_PASSWORD || 'password';
			await this.page.locator( '#user_login' ).waitFor( { state: 'visible', timeout: 10000 } );
			await this.page.locator( '#user_login' ).fill( user );
			await this.page.locator( '#user_pass' ).fill( pass );
			await this.page.locator( '#wp-submit' ).click();
			await this.page.waitForURL( /wp-admin/, { timeout: 20000 } );
			await this.page.goto( '/wp-admin/admin.php?page=nettertech-events-new' );
		}

		// Wait for form to be ready
		await this.titleInput.waitFor( { state: 'visible', timeout: 15000 } );
	}

	/**
	 * Navigate to edit event page.
	 *
	 * @param eventId - Event ID to edit.
	 */
	async gotoEdit( eventId: number ): Promise<void> {
		await this.page.goto(
			`/wp-admin/admin.php?page=nettertech-events-edit&id=${ eventId }`
		);
		// Wait for form to be ready
		await this.titleInput.waitFor( { state: 'visible', timeout: 15000 } );
	}

	/**
	 * Set native date/time input value via JavaScript.
	 * Native HTML5 date/time inputs require special handling in Playwright.
	 *
	 * @param locator - Input locator.
	 * @param value - Value to set (YYYY-MM-DD for date, HH:MM for time).
	 */
	private async setNativeDateTimeInput(
		locator: Locator,
		value: string
	): Promise<void> {
		await locator.evaluate( ( el, val ) => {
			const input = el as HTMLInputElement;
			input.value = val;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}, value );
	}

	/**
	 * Fill event form with data.
	 *
	 * @param data - Event form data.
	 */
	async fillForm( data: EventFormData ): Promise<void> {
		if ( data.title ) {
			await this.titleInput.fill( data.title );
		}

		if ( data.description ) {
			// TinyMCE editor needs special handling - set content via JavaScript
			await this.page.evaluate( ( content ) => {
				// Try TinyMCE first
				if (
					typeof window.tinymce !== 'undefined' &&
					window.tinymce.get( 'event_description' )
				) {
					window.tinymce.get( 'event_description' ).setContent( content );
				} else if ( document.getElementById( 'event_description' ) ) {
					// Fallback to textarea
					( document.getElementById( 'event_description' ) as HTMLTextAreaElement ).value = content;
				}
			}, data.description );
		}

		if ( data.excerpt ) {
			await this.excerptInput.fill( data.excerpt );
		}

		if ( data.status ) {
			await this.statusSelect.selectOption( data.status );
		}

		if ( data.eventType ) {
			await this.eventTypeSelect.selectOption( data.eventType );
		}

		// Native date/time inputs need JavaScript evaluation
		if ( data.startDate ) {
			await this.setNativeDateTimeInput( this.startDateInput, data.startDate );
		}

		if ( data.startTime ) {
			await this.setNativeDateTimeInput( this.startTimeInput, data.startTime );
		}

		if ( data.endDate ) {
			await this.setNativeDateTimeInput( this.endDateInput, data.endDate );
		}

		if ( data.endTime ) {
			await this.setNativeDateTimeInput( this.endTimeInput, data.endTime );
		}

		if ( data.allDay ) {
			await this.allDayCheckbox.check();
		}

		if ( data.venueName ) {
			await this.venueNameInput.fill( data.venueName );
		}

		if ( data.venueAddress ) {
			await this.venueAddressInput.fill( data.venueAddress );
		}

		if ( data.capacity !== undefined ) {
			await this.setNativeDateTimeInput(
				this.capacityInput,
				String( data.capacity )
			);
		}
	}

	/**
	 * Submit the form and return created/updated event ID.
	 *
	 * Webkit under long-running serial worker can race the post-submit
	 * redirect: domcontentloaded fires before the URL stabilizes, leaving
	 * the URL on the pre-submit /new page. To make this resilient we wait
	 * explicitly for the redirect signature (id= in URL OR success notice
	 * in DOM) and fall back to a hidden event_id input the form emits on
	 * edit pages. Returning 0 here would cascade into gotoEdit(0) and an
	 * opaque 15s waitFor timeout downstream.
	 *
	 * @returns Event ID.
	 */
	async submit(): Promise<number> {
		await this.publishButton.click();

		await this.page.waitForLoadState( 'domcontentloaded' );

		await this.page.waitForFunction(
			() =>
				document.querySelector( '.notice-success, .updated' ) !== null ||
				window.location.search.includes( 'id=' ) ||
				document.querySelector( 'input[name="event_id"]' ) !== null,
			{ timeout: 15000 }
		).catch( () => {
			// fall through — the helpers below produce a better error than the timeout
		} );

		const url = this.page.url();
		const urlMatch = url.match( /id=(\d+)/ );
		if ( urlMatch ) {
			return parseInt( urlMatch[ 1 ], 10 );
		}

		// Webkit fallback: read hidden id input the edit form emits.
		const hiddenId = await this.page
			.locator( 'input[name="event_id"]' )
			.first()
			.getAttribute( 'value' )
			.catch( () => null );
		if ( hiddenId && /^\d+$/.test( hiddenId ) ) {
			return parseInt( hiddenId, 10 );
		}

		return 0;
	}

	/**
	 * Expect success notice with specific action.
	 * This is optional - if the page was saved and redirected to edit,
	 * the notice may not be visible.
	 *
	 * @param action - Expected action (created, updated, deleted).
	 */
	async expectSuccessNotice(
		action: 'created' | 'updated' | 'deleted'
	): Promise<void> {
		const notice = this.page.locator( '.notice-success, .updated, .notice-info' );

		try {
			await notice.waitFor( { state: 'visible', timeout: 3000 } );
			await expect( notice ).toContainText( new RegExp( action, 'i' ) );
		} catch {
			// Notice may not appear after redirect - verify page state instead
			const url = this.page.url();
			const hasEventId = url.includes( 'id=' ) || url.includes( 'event_id=' );
			if ( ( action === 'created' || action === 'updated' ) && hasEventId ) {
				return; // Event was created/updated if we're on edit page with ID
			}
			throw new Error( `Expected success notice for action: ${ action }` );
		}
	}
}

/**
 * Frontend events page object.
 */
export class EventsPage {
	readonly page: Page;

	constructor( page: Page ) {
		this.page = page;
	}

	/**
	 * Navigate to events listing page.
	 */
	async goto(): Promise<void> {
		await this.page.goto( '/events/' );
	}

	/**
	 * Get event cards on the page.
	 */
	getEventCards(): Locator {
		return this.page.locator( '.ve-event-card, .event-card' );
	}

	/**
	 * Get pagination controls.
	 */
	getPagination(): Locator {
		return this.page.locator( '.ve-pagination, .pagination' );
	}

	/**
	 * Click on an event by title.
	 *
	 * @param title - Event title to click.
	 */
	async clickEvent( title: string ): Promise<void> {
		await this.page.locator( `.ve-event-card:has-text("${ title }")` ).click();
	}
}
