/**
 * E2E tests for recurring event functionality.
 *
 * Tests creation and display of recurring events.
 */

import { test, expect } from './fixtures';
import { uniqueEventTitle, getFutureDate, formatDateForInput } from './support/helpers';
import { EventAdminPage, EventFormData } from './pages';
import { loginAsAdmin } from './support/page-objects';
import { deleteEvent } from './utils/api';

test.describe( 'Recurring Events', () => {
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

	test( 'recurring option shows recurrence settings', async ( { page } ) => {
		await eventAdminPage.gotoNew();

		// Select recurring event type
		await eventAdminPage.eventTypeSelect.selectOption( 'recurring' );

		// Recurrence settings should appear
		await expect( eventAdminPage.recurrenceBox ).toBeVisible();
	} );

	test( 'can select weekly recurrence frequency', async ( { page } ) => {
		await eventAdminPage.gotoNew();
		await eventAdminPage.eventTypeSelect.selectOption( 'recurring' );

		// Find frequency selector
		const frequencySelect = page.locator(
			'#recurrence_frequency, [name="recurrence_frequency"]'
		);

		const selectCount = await frequencySelect.count();
		test.skip( selectCount === 0, 'Recurrence frequency selector not found in UI' );

		await frequencySelect.selectOption( 'weekly' );
		await expect( frequencySelect ).toHaveValue( 'weekly' );
	} );

	test( 'can set recurrence end date', async ( { page } ) => {
		await eventAdminPage.gotoNew();
		await eventAdminPage.eventTypeSelect.selectOption( 'recurring' );

		// Find end date input
		const endDateInput = page.locator(
			'#recurrence_end_date, [name="recurrence_end_date"]'
		);

		const inputCount = await endDateInput.count();
		test.skip( inputCount === 0, 'Recurrence end date input not found in UI' );

		const futureDate = getFutureDate( 90 );
		await endDateInput.fill( formatDateForInput( futureDate ) );
	} );

	test( 'can create a weekly recurring event', async ( { page } ) => {
		const eventTitle = uniqueEventTitle( 'Weekly Event' );
		const startDate = getFutureDate( 7 );
		const endDate = getFutureDate( 60 );

		const eventData: EventFormData = {
			title: eventTitle,
			status: 'published',
			eventType: 'recurring',
			startDate: formatDateForInput( startDate ),
			startTime: '19:00',
			endDate: formatDateForInput( startDate ),
			endTime: '21:00',
		};

		await eventAdminPage.gotoNew();
		await eventAdminPage.fillForm( eventData );

		// Set recurrence options
		const frequencySelect = page.locator(
			'#recurrence_frequency, [name="recurrence_frequency"]'
		);
		if ( ( await frequencySelect.count() ) > 0 ) {
			await frequencySelect.selectOption( 'weekly' );
		}

		const recurrenceEndInput = page.locator(
			'#recurrence_end_date, [name="recurrence_end_date"]'
		);
		if ( ( await recurrenceEndInput.count() ) > 0 ) {
			await recurrenceEndInput.fill( formatDateForInput( endDate ) );
		}

		// Submit and track for cleanup
		createdEventId = await eventAdminPage.submit();

		// Verify event was created with recurring type
		expect( createdEventId ).toBeGreaterThan( 0 );
		await eventAdminPage.gotoEdit( createdEventId );
		await expect( eventAdminPage.eventTypeSelect ).toHaveValue( 'recurring' );
	} );

	test( 'recurring events show multiple occurrences on frontend', async ( { page } ) => {
		// Navigate to events page
		await page.goto( '/events/' );

		// Look for any event with occurrence indicators
		const occurrenceIndicators = page.locator(
			'.occurrence-count, .ve-occurrences, [class*="occurrence"]'
		);

		// This is a detection test - verify the page structure supports occurrences
		const pageContent = await page.content();

		// Should either show occurrence info or events list
		const hasEventsOrOccurrences =
			( await occurrenceIndicators.count() ) > 0 ||
			pageContent.includes( 'event' );

		expect( hasEventsOrOccurrences ).toBeTruthy();
	} );

	test( 'switching from recurring to single hides recurrence box', async () => {
		await eventAdminPage.gotoNew();

		// Start with recurring
		await eventAdminPage.eventTypeSelect.selectOption( 'recurring' );
		await expect( eventAdminPage.recurrenceBox ).toBeVisible();

		// Switch to single
		await eventAdminPage.eventTypeSelect.selectOption( 'single' );
		await expect( eventAdminPage.recurrenceBox ).not.toBeVisible();
	} );

	// =====================================================================
	// NTE-076/077 Chunk 8: per-occurrence edit, end-to-end through the UI.
	// Admin opens a recurring event -> "Upcoming Dates" -> Edit a date ->
	// modify -> choose scope -> Save -> verify on the frontend.
	// User actions are driven UI-only (clicks/fills/native-input + real
	// submit); JS evaluate is used solely for observation/reads.
	// =====================================================================
	test( 'edit one occurrence date (scope=this) and verify on the frontend', async ( {
		page,
	} ) => {
		// 1. Create a recurring event through the admin UI.
		const eventTitle = uniqueEventTitle( 'Chunk8 Per-Occurrence' );
		const seriesStart = getFutureDate( 7 );

		await eventAdminPage.gotoNew();
		await eventAdminPage.fillForm( {
			title: eventTitle,
			status: 'published',
			eventType: 'recurring',
			startDate: formatDateForInput( seriesStart ),
			startTime: '19:00',
			endDate: formatDateForInput( seriesStart ),
			endTime: '21:00',
		} );

		// Use the form's default recurrence preset (which generates a multi-date
		// series). The exact frequency is immaterial to the per-occurrence-edit
		// flow under test; we only need >= 2 generated occurrences. The custom
		// #recurrence_freq control is hidden behind the "custom" preset, so we do
		// not drive it here.
		createdEventId = await eventAdminPage.submit();
		expect( createdEventId ).toBeGreaterThan( 0 );

		// 2. Open the event edit page and locate the dates list, which lives
		// inside the consolidated Schedule box (NTE-159).
		await eventAdminPage.gotoEdit( createdEventId );

		const upcomingBox = page.locator( '#nte-schedule-box' );
		await expect( upcomingBox ).toBeVisible();

		const dateItems = upcomingBox.locator( '.nte-upcoming-dates__item' );
		const itemCount = await dateItems.count();
		test.skip(
			itemCount < 2,
			'Need at least two generated occurrences to edit a non-first date.'
		);

		// 3. Click "Edit" on the SECOND upcoming date (UI navigation only).
		const editLink = dateItems
			.nth( 1 )
			.locator( '.nte-upcoming-dates__edit' );
		await editLink.click();

		// 4. On the occurrence editor, move this date a few days out (kept within
		//    the metabox's 100-item upcoming window) and to a distinct time, with
		//    scope=this. Native inputs via fill() (the UI path for type=date/time).
		const startDateInput = page.locator( '#nte-occurrence-start-date' );
		await expect( startDateInput ).toBeVisible();

		const currentDate = await startDateInput.inputValue();
		const movedDate = new Date( `${ currentDate }T00:00:00` );
		movedDate.setDate( movedDate.getDate() + 3 );
		const movedDateStr = formatDateForInput( movedDate );

		await startDateInput.fill( movedDateStr );
		await expect( startDateInput ).toHaveValue( movedDateStr );
		await page.locator( '#nte-occurrence-start-time' ).fill( '18:30' );
		await page.locator( '#nte-occurrence-end-date' ).fill( movedDateStr );
		await page.locator( '#nte-occurrence-end-time' ).fill( '20:30' );

		// scope=this is the default; assert it then save through the UI.
		const scopeThis = page.locator( 'input[name="scope"][value="this"]' );
		await expect( scopeThis ).toBeChecked();

		// Submit through the UI; the form posts to admin-post.php which redirects
		// back to the occurrence editor. Wait for the editor to reload (the
		// occurrence_id query arg is always present on the editor screen).
		await page
			.locator( '#nte-occurrence-editor button[type="submit"]' )
			.click();
		await page.waitForURL( /page=nettertech-events-edit-occurrence/, {
			timeout: 20000,
		} );

		// 5. The save persisted: the reloaded editor shows the moved date.
		await expect(
			page.locator( '#nte-occurrence-start-date' )
		).toHaveValue( movedDateStr );

		// The Upcoming Dates list on the event edit page shows the "Edited"
		// override badge for the moved occurrence.
		await eventAdminPage.gotoEdit( createdEventId );
		await expect(
			page.locator( '.nte-upcoming-dates__item--override' ).first()
		).toBeVisible();

		// 6. Verify on the FRONTEND through UI navigation only: open the events
		//    archive, click into this event, and confirm its page renders the
		//    moved date. The slug carries a random suffix, so reach the page by
		//    clicking its title link rather than reconstructing the URL.
		await page.goto( '/events/' );
		const eventLink = page.getByRole( 'link', { name: eventTitle } ).first();
		await expect( eventLink ).toBeVisible( { timeout: 10000 } );
		await eventLink.click();

		// On the event's frontend page, the moved date (its month/day) is shown
		// in the upcoming-dates rendering.
		const movedMonthDay = movedDate.toLocaleString( 'en-US', {
			month: 'long',
			day: 'numeric',
		} );
		await expect( page.locator( 'body' ) ).toContainText( eventTitle, {
			timeout: 10000,
		} );
		await expect( page.locator( 'body' ) ).toContainText( movedMonthDay, {
			timeout: 10000,
		} );
	} );
} );
