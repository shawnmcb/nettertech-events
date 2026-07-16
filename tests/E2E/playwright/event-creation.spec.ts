/**
 * E2E tests for event creation workflow.
 *
 * Tests the admin event creation flow including:
 * - Creating a new single event
 * - Editing an existing event
 * - Setting event dates and venue
 * - Verifying event appears on frontend
 */

import { test, expect } from './fixtures';
import { uniqueEventTitle, getFutureDate, formatDateForInput, formatTimeForInput } from './support/helpers';
import { EventAdminPage, EventFormData } from './pages';
import { loginAsAdmin } from './support/page-objects';
import { deleteEvent } from './utils/api';

test.describe( 'Event Creation', () => {
	let eventAdminPage: EventAdminPage;
	let createdEventId: number | null = null;

	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
		eventAdminPage = new EventAdminPage( page );
	} );

	test.afterEach( async ( { request } ) => {
		// Clean up created event if test created one
		if ( createdEventId ) {
			try {
				await deleteEvent( request, createdEventId );
			} catch {
				// Ignore cleanup errors
			}
			createdEventId = null;
		}
	} );

	test( 'can navigate to Add New Event page', async ( { page } ) => {
		await eventAdminPage.gotoNew();
		await expect( page.locator( '.wrap h1' ) ).toContainText( 'Add New Event' );
		await expect( eventAdminPage.titleInput ).toBeVisible();
		await expect( eventAdminPage.publishButton ).toBeVisible();
	} );

	test( 'can create a single event with basic details', async ( { page } ) => {
		const eventTitle = uniqueEventTitle();
		const futureDate = getFutureDate( 14 );

		const eventData: EventFormData = {
			title: eventTitle,
			description: 'This is a test event created by E2E tests.',
			excerpt: 'A brief summary of the test event.',
			status: 'published',
			eventType: 'single',
			startDate: formatDateForInput( futureDate ),
			startTime: '19:00',
			endDate: formatDateForInput( futureDate ),
			endTime: '22:00',
			venueName: 'E2E Test Venue',
			venueAddress: '123 Test Street, Test City, TC 12345',
		};

		await eventAdminPage.gotoNew();
		await eventAdminPage.fillForm( eventData );

		createdEventId = await eventAdminPage.submit();

		// Verify redirect and success
		expect( createdEventId ).toBeGreaterThan( 0 );
		await eventAdminPage.expectSuccessNotice( 'created' );

		// Verify form shows saved data
		await expect( eventAdminPage.titleInput ).toHaveValue( eventTitle );
	} );

	test( 'can edit an existing event', async ( { page, request } ) => {
		// Create event first
		const eventTitle = uniqueEventTitle();
		const futureDate = getFutureDate( 7 );

		const eventData: EventFormData = {
			title: eventTitle,
			status: 'draft',
			eventType: 'single',
			startDate: formatDateForInput( futureDate ),
			startTime: '10:00',
			endDate: formatDateForInput( futureDate ),
			endTime: '12:00',
		};

		await eventAdminPage.gotoNew();
		await eventAdminPage.fillForm( eventData );
		createdEventId = await eventAdminPage.submit();

		// Surface a clean failure here rather than letting gotoEdit(0)
		// time out waiting for the title input on an invalid URL.
		expect( createdEventId ).toBeGreaterThan( 0 );

		// Now edit the event
		await eventAdminPage.gotoEdit( createdEventId );

		// Change title and status
		const updatedTitle = eventTitle + ' (Updated)';
		await eventAdminPage.titleInput.fill( updatedTitle );
		await eventAdminPage.statusSelect.selectOption( 'published' );

		await eventAdminPage.submit();

		// Verify changes were saved
		await eventAdminPage.expectSuccessNotice( 'updated' );
		await expect( eventAdminPage.titleInput ).toHaveValue( updatedTitle );
		await expect( eventAdminPage.statusSelect ).toHaveValue( 'published' );
	} );

	test( 'validates required fields', async ( { page } ) => {
		await eventAdminPage.gotoNew();

		// Try to submit without title
		await eventAdminPage.publishButton.click();

		// Form should not submit - title is required HTML5 validation
		await expect( page ).toHaveURL( /page=nettertech-events-new/ );
	} );

	test( 'can create event with venue information', async () => {
		const eventTitle = uniqueEventTitle();
		const futureDate = getFutureDate( 21 );

		const eventData: EventFormData = {
			title: eventTitle,
			status: 'published',
			eventType: 'single',
			startDate: formatDateForInput( futureDate ),
			startTime: '18:00',
			endDate: formatDateForInput( futureDate ),
			endTime: '21:00',
			venueName: 'Concert Hall',
			venueAddress: '456 Music Avenue\nSuite 100\nMelody City, MC 67890',
		};

		await eventAdminPage.gotoNew();
		await eventAdminPage.fillForm( eventData );
		createdEventId = await eventAdminPage.submit();
		expect( createdEventId ).toBeGreaterThan( 0 );

		// Verify venue info was saved.
		// venueAddressInput is a <textarea>; assert .value rather than .textContent.
		// Webkit's textarea node serialization for toContainText is inconsistent
		// under long serial runs; toHaveValue with a regex is engine-stable.
		await eventAdminPage.gotoEdit( createdEventId );
		await expect( eventAdminPage.venueNameInput ).toHaveValue( 'Concert Hall' );
		await expect( eventAdminPage.venueAddressInput ).toHaveValue( /456 Music Avenue/ );
	} );

	test( 'can create an all-day event', async () => {
		const eventTitle = uniqueEventTitle();
		const futureDate = getFutureDate( 10 );

		const eventData: EventFormData = {
			title: eventTitle,
			status: 'published',
			eventType: 'single',
			startDate: formatDateForInput( futureDate ),
			allDay: true,
		};

		await eventAdminPage.gotoNew();
		await eventAdminPage.fillForm( eventData );
		createdEventId = await eventAdminPage.submit();

		// Verify all-day checkbox is checked
		await eventAdminPage.gotoEdit( createdEventId );
		await expect( eventAdminPage.allDayCheckbox ).toBeChecked();
	} );

	test( 'can set event capacity', async () => {
		const eventTitle = uniqueEventTitle();
		const futureDate = getFutureDate( 7 );

		const eventData: EventFormData = {
			title: eventTitle,
			status: 'published',
			eventType: 'single',
			startDate: formatDateForInput( futureDate ),
			startTime: '14:00',
			endDate: formatDateForInput( futureDate ),
			endTime: '16:00',
			capacity: 100,
		};

		await eventAdminPage.gotoNew();
		await eventAdminPage.fillForm( eventData );
		createdEventId = await eventAdminPage.submit();

		// Verify capacity was saved
		await eventAdminPage.gotoEdit( createdEventId );
		await expect( eventAdminPage.capacityInput ).toHaveValue( '100' );
	} );

	test( 'can save event as draft', async () => {
		const eventTitle = uniqueEventTitle();
		const futureDate = getFutureDate( 30 );

		const eventData: EventFormData = {
			title: eventTitle,
			status: 'draft',
			eventType: 'single',
			startDate: formatDateForInput( futureDate ),
			startTime: '09:00',
			endDate: formatDateForInput( futureDate ),
			endTime: '17:00',
		};

		await eventAdminPage.gotoNew();
		await eventAdminPage.fillForm( eventData );
		createdEventId = await eventAdminPage.submit();

		// Verify status is draft
		await eventAdminPage.gotoEdit( createdEventId );
		await expect( eventAdminPage.statusSelect ).toHaveValue( 'draft' );
	} );

	test( 'shows recurrence box only for recurring events', async () => {
		await eventAdminPage.gotoNew();

		// Initially with single event type, recurrence box should be hidden
		await eventAdminPage.eventTypeSelect.selectOption( 'single' );
		await expect( eventAdminPage.recurrenceBox ).not.toBeVisible();

		// Switch to recurring - recurrence box should appear
		await eventAdminPage.eventTypeSelect.selectOption( 'recurring' );
		await expect( eventAdminPage.recurrenceBox ).toBeVisible();

		// Switch back to single - should hide again
		await eventAdminPage.eventTypeSelect.selectOption( 'single' );
		await expect( eventAdminPage.recurrenceBox ).not.toBeVisible();
	} );

	test( 'created event appears in events list', async ( { page } ) => {
		const eventTitle = uniqueEventTitle();
		const futureDate = getFutureDate( 7 );

		const eventData: EventFormData = {
			title: eventTitle,
			status: 'published',
			eventType: 'single',
			startDate: formatDateForInput( futureDate ),
			startTime: '19:00',
			endDate: formatDateForInput( futureDate ),
			endTime: '22:00',
		};

		await eventAdminPage.gotoNew();
		await eventAdminPage.fillForm( eventData );
		createdEventId = await eventAdminPage.submit();

		// Navigate to events list
		await page.goto( '/wp-admin/admin.php?page=nettertech-events' );

		// Verify event appears in list (use specific selector to avoid Query Monitor tables)
		await expect(
			page.locator( '.wp-list-table, #the-list, table.widefat' ).first()
		).toContainText( eventTitle );
	} );
} );
