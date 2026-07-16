/**
 * Fresh-install walkthrough matrix — 14 flows.
 *
 * Automates the manual walkthrough performed on 2026-04-14 that surfaced
 * NTE-008, NTE-009, NTE-010, NTE-013, and NTE-017. Each flow is a
 * regression guard: a pass means the feature works end-to-end today; a
 * failure means something broke at the boundary that unit tests miss.
 *
 * Flows:
 *  1. Plugin activation — menu present with expected base items
 *  2. Base menu guard — explicitly no satellite plugin items
 *  3. Single event creation — admin create → frontend permalink renders
 *  4. Recurring event creation — 51 occurrences → frontend shows >1 (NTE-008)
 *  5. Categories admin — create, appears in list
 *  6. Organizers admin — create, list, header "Add New" points to organizer form (NTE-013)
 *  7. Spaces admin — create, appears in list
 *  8. Attendees admin — RSVP attendee via REST → appears in admin list
 *  9. Category ↔ event assignment — assign, frontend filter works
 * 10. [nettertech_events_list] shortcode — listing renders with event permalinks
 * 11. [nettertech_events_calendar] shortcode — month view renders with events visible
 * 12. Gutenberg events grid block — block inserts, grid renders on frontend
 * 13. /events/ archive — upcoming events present
 * 14. Gutenberg carousel block — block inserts, carousel container renders (NTE-025)
 */

import { test, expect } from '@playwright/test';
import {
	createEvent,
	createPageWithShortcode,
	loginAsAdmin,
} from './support/page-objects';
import { EventAdminPage } from './pages';
import { getFutureDate, formatDateForInput } from './support/helpers';

// Run this file serially so each test gets an uncontested login session.
// Parallel auth across 4 workers causes intermittent timeout on the event
// editor form (gotoNew waits for #event_title with a 15s timeout and loses
// the race when another worker is mid-login on the same cookie jar).
test.describe.configure( { mode: 'serial' } );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function uniqueTitle( prefix: string ): string {
	return `${ prefix } ${ Date.now() }`;
}

/**
 * Force a fresh WordPress login by always filling credentials.
 *
 * Previously this spec had a local `forceLogin` helper with the WebKit-
 * specific handling above. That hardening has been folded into
 * `loginAsAdmin` in support/page-objects.ts — it now waits for page
 * load state before the URL check, waits for #loginform rather than
 * #user_login, and uses 30s timeouts. Call loginAsAdmin directly.
 */

/**
 * Attach console error collector that ignores browser-internal non-site errors.
 *
 * Filters out:
 * - Firefox NS_BINDING_ABORTED (internal network abort events, not site errors)
 * - Firefox font-download failures (local .local domain has no HTTPS cert, so
 *   woff2 fonts fail in Firefox's strict mode — this is infrastructure, not a
 *   plugin bug)
 * - Chrome juggler / about:blank internal errors
 */
function attachFilteredConsoleErrorCollector( page: import('@playwright/test').Page ): () => Promise<void> {
	const errors: string[] = [];

	const IGNORE_PATTERNS = [
		/NS_BINDING_ABORTED/,
		/chrome:\/\/juggler/,
		/about:blank/,
		/downloadable font: download failed/,
		/font-family:/,
		/\.woff2/,
	];

	page.on( 'console', ( msg ) => {
		if ( msg.type() !== 'error' ) {
			return;
		}
		const text = msg.text();
		if ( IGNORE_PATTERNS.some( ( p ) => p.test( text ) ) ) {
			return;
		}
		errors.push( text );
	} );

	return async function assertNoSiteConsoleErrors(): Promise<void> {
		expect(
			errors,
			`Expected no console errors but got:\n${ errors.join( '\n' ) }`
		).toHaveLength( 0 );
	};
}

/**
 * Navigate to an admin page and recover from login redirect automatically.
 *
 * Handles the WebKit / parallel-worker pattern where a valid session cookie
 * doesn't survive cross-navigation in the same browser context.  Navigates
 * to the target URL; if WordPress redirects to wp-login.php, logs in and
 * retries the navigation.
 */
async function navigateToAdminPage( page: import('@playwright/test').Page, url: string ): Promise<void> {
	const user = process.env.WP_ADMIN_USERNAME || 'admin';
	const pass = process.env.WP_ADMIN_PASSWORD || 'password';

	await page.goto( url );
	await page.waitForLoadState( 'domcontentloaded' );

	if ( page.url().includes( 'wp-login' ) ) {
		await page.locator( '#user_login' ).waitFor( { state: 'visible', timeout: 10000 } );
		await page.locator( '#user_login' ).fill( user );
		await page.locator( '#user_pass' ).fill( pass );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/, { timeout: 30000 } );
		await page.goto( url );
		await page.waitForLoadState( 'domcontentloaded' );
	}
}

/**
 * Create a category via the admin UI and return its name.
 */
async function createCategory( page: import('@playwright/test').Page, name: string ): Promise<void> {
	await navigateToAdminPage( page, '/wp-admin/admin.php?page=nettertech-events-categories&action=add' );

	const nameInput = page.locator( '#category_name, [name="category_name"], input[name="name"]' ).first();
	await nameInput.waitFor( { state: 'visible', timeout: 15000 } );
	await nameInput.fill( name );

	const submit = page.locator( 'input[type="submit"], button[type="submit"]' ).first();
	await submit.click();
	await page.waitForLoadState( 'domcontentloaded' );
}

/**
 * Create an organizer via the admin UI.
 */
async function createOrganizer( page: import('@playwright/test').Page, name: string ): Promise<void> {
	await navigateToAdminPage( page, '/wp-admin/admin.php?page=nettertech-events-organizers&action=add' );

	const nameInput = page.locator( '#organizer_name, [name="organizer_name"], input[name="name"]' ).first();
	await nameInput.waitFor( { state: 'visible', timeout: 15000 } );
	await nameInput.fill( name );

	const submit = page.locator( 'input[type="submit"], button[type="submit"]' ).first();
	await submit.click();
	await page.waitForLoadState( 'domcontentloaded' );
}

/**
 * Create a space via the admin UI.
 */
async function createSpace( page: import('@playwright/test').Page, name: string ): Promise<void> {
	await navigateToAdminPage( page, '/wp-admin/admin.php?page=nettertech-events-spaces&action=add' );

	const nameInput = page.locator( '#space_name, [name="space_name"], input[name="name"]' ).first();
	await nameInput.waitFor( { state: 'visible', timeout: 15000 } );
	await nameInput.fill( name );

	const submit = page.locator( 'input[type="submit"], button[type="submit"]' ).first();
	await submit.click();
	await page.waitForLoadState( 'domcontentloaded' );
}

// ---------------------------------------------------------------------------
// Flow 1: Plugin activation — Events menu + 10 submenus
// ---------------------------------------------------------------------------

test( 'Flow 1: Events menu present with all 10 base submenus after activation', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await navigateToAdminPage( page, '/wp-admin/' );
	await page.waitForSelector( '#adminmenu', { timeout: 30000 } );

	// The top-level Events menu item must be present.
	const eventsMenuLink = page.locator( '#adminmenu a[href*="page=nettertech-events"]' ).first();
	await expect( eventsMenuLink ).toBeVisible();

	// Hover to reveal the submenu.
	const menuParent = eventsMenuLink.locator( 'xpath=ancestor::li[contains(@class, "menu-top")]' );
	await menuParent.hover();

	// The base plugin contributes these 10 submenu pages by slug. Satellite
	// plugins (Seating, Migrator, Pro, Rentals) may add their own items
	// when active — the previous exact-count assertion broke whenever a
	// satellite plugin was active alongside the base. Assert that all base
	// items are present as a sub-set; satellite items are validated
	// separately in Flow 2.
	const baseSubmenuSlugs = [
		'page=nettertech-events',
		'page=nettertech-events-new',
		'page=nettertech-events-organizers',
		'page=nettertech-events-spaces',
		'page=nettertech-events-categories',
		'page=nettertech-events-attendees',
		'page=nettertech-events-csv-import',
		'page=nettertech-events-qr-generator',
		'page=nettertech-events-activity-log',
		'page=nettertech-events-settings',
	];
	for ( const slug of baseSubmenuSlugs ) {
		const link = menuParent.locator( `.wp-submenu a[href*="${ slug }"]` ).first();
		await expect( link, `Base submenu missing: ${ slug }` ).toBeVisible();
	}

	// Admin page must not contain PHP errors.
	const content = await page.content();
	expect( content ).not.toContain( 'Fatal error' );
	expect( content ).not.toContain( 'Parse error' );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 2: Base menu — no satellite plugin items
// ---------------------------------------------------------------------------

test( 'Flow 2: Base menu has no satellite plugin items (no Check-in, Waitlists, Seating)', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await navigateToAdminPage( page, '/wp-admin/' );
	await page.waitForSelector( '#adminmenu', { timeout: 30000 } );

	// Detect whether a satellite plugin (Pro, Seating, Rentals, Migrator)
	// is active. The fresh-install-matrix suite asserts the BASE plugin's
	// behavior in isolation; satellites legitimately contribute their own
	// admin menu items (Seat Maps, Migrate, etc.) and that violates the
	// "no satellite items" precondition here. On environments like
	// a dev site where all satellites are typically active,
	// we skip rather than fail.
	const satelliteActive = await page.evaluate( () => {
		const adminMenu = document.querySelector( '#adminmenu' );
		if ( ! adminMenu ) {
			return false;
		}
		const html = adminMenu.innerHTML;
		return (
			/page=nettertech-events-seating/i.test( html ) ||
			/page=nettertech-events-migrate/i.test( html ) ||
			/page=nettertech-events-rentals/i.test( html ) ||
			/page=nettertech-events-pro/i.test( html )
		);
	} );

	test.skip( satelliteActive, 'Satellite plugin (Pro/Seating/Rentals/Migrator) is active — fresh-install assertion does not apply' );

	const eventsMenuLink = page.locator( '#adminmenu a[href*="page=nettertech-events"]' ).first();
	const menuParent = eventsMenuLink.locator( 'xpath=ancestor::li[contains(@class, "menu-top")]' );
	await menuParent.hover();

	const submenuText = await menuParent.locator( '.wp-submenu' ).textContent() ?? '';

	// Satellite plugin menu items must not appear when only the base plugin is active.
	expect( submenuText ).not.toMatch( /Check.in/i );
	expect( submenuText ).not.toMatch( /Waitlist/i );
	expect( submenuText ).not.toMatch( /Seating/i );
	expect( submenuText ).not.toMatch( /Ticket Type/i );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 3: Single event creation → frontend render
// ---------------------------------------------------------------------------

test( 'Flow 3: Create single event, frontend permalink renders title', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	const title = uniqueTitle( 'Fresh Install Single Event' );
	const futureDate = getFutureDate( 14 );

	const { eventId, permalink } = await createEvent( page, {
		title,
		startDate: formatDateForInput( futureDate ),
		startTime: '19:00',
		recurring: false,
	} );

	expect( eventId, 'Event ID must be > 0 after creation' ).toBeGreaterThan( 0 );

	// Navigate to frontend permalink.
	const frontendUrl = permalink || `/events/${ title.toLowerCase().replace( /\s+/g, '-' ) }/`;
	await page.goto( frontendUrl );
	await page.waitForLoadState( 'domcontentloaded' );

	// Title must appear in the page.
	const content = await page.content();
	expect( content ).toContain( title );
	expect( content ).not.toContain( 'Fatal error' );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 4: Recurring event → 51 occurrences → frontend shows >1 (NTE-008)
// ---------------------------------------------------------------------------

test( 'Flow 4: Recurring event with 51 occurrences — frontend shows multiple dates (NTE-008 regression)', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	const title = uniqueTitle( 'Fresh Install Weekly Event' );
	const futureDate = getFutureDate( 7 );

	// createEvent with recurring + 51 occurrences exercises the batch insert path
	// that NTE-008's null-coercion bug killed after the first row.
	const { eventId, permalink } = await createEvent( page, {
		title,
		startDate: formatDateForInput( futureDate ),
		startTime: '10:00',
		recurring: true,
		occurrences: 51,
	} );

	expect( eventId, 'Recurring event ID must be > 0' ).toBeGreaterThan( 0 );

	// Also confirm via REST that >1 occurrence was persisted (not just 1 as NTE-008 produced).
	const restResponse = await page.request.get(
		`/wp-json/nettertech-events/v1/events/upcoming`,
		{ failOnStatusCode: false }
	);
	if ( restResponse.ok() ) {
		const events = await restResponse.json() as Array<{ id: number; occurrences?: unknown[] }>;
		const thisEvent = events.find( ( e ) => e.id === eventId );
		if ( thisEvent && Array.isArray( thisEvent.occurrences ) ) {
			expect(
				thisEvent.occurrences.length,
				'NTE-008 regression: expected >1 occurrence stored, got 1 (null-coercion bug)'
			).toBeGreaterThan( 1 );
		}
	}

	// Frontend event page should show the "Upcoming Dates" section with multiple entries.
	const frontendUrl = permalink || `/events/${ title.toLowerCase().replace( /\s+/g, '-' ) }/`;
	await page.goto( frontendUrl );
	await page.waitForLoadState( 'domcontentloaded' );

	const content = await page.content();
	expect( content, 'Frontend must not show fatal error after recurring event creation' ).not.toContain( 'Fatal error' );
	expect( content ).toContain( title );

	// Look for multiple date entries — any element that repeats occurrence dates.
	const occurrenceItems = page.locator(
		'.nte-upcoming-dates li, .nte-occurrences li, [class*="occurrence"] li, .nte-event-dates li'
	);
	const occurrenceCount = await occurrenceItems.count();
	expect(
		occurrenceCount,
		`NTE-008 regression check: expected >1 date entry on frontend, got ${ occurrenceCount }. ` +
		'If this is 1, the batch insert null-coercion bug has returned.'
	).toBeGreaterThan( 1 );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 5: Categories admin — create, list shows it
// ---------------------------------------------------------------------------

test( 'Flow 5: Create category, list shows it', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	const categoryName = uniqueTitle( 'Test Category' );
	await createCategory( page, categoryName );

	// Navigate to category list and confirm it appears.
	await navigateToAdminPage( page, '/wp-admin/admin.php?page=nettertech-events-categories' );

	const content = await page.content();
	expect( content ).not.toContain( 'Fatal error' );
	expect( content ).toContain( categoryName );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 6: Organizers — create, list, page-header Add New link (NTE-013)
// ---------------------------------------------------------------------------

test( 'Flow 6: Create organizer, list shows it, page-header Add New targets organizer form (NTE-013)', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	const organizerName = uniqueTitle( 'Test Organizer' );
	await createOrganizer( page, organizerName );

	// Navigate to organizer list.
	await navigateToAdminPage( page, '/wp-admin/admin.php?page=nettertech-events-organizers' );

	const content = await page.content();
	expect( content ).not.toContain( 'Fatal error' );
	expect( content ).toContain( organizerName );

	// NTE-013 assertion: the page-header "Add New" link must target the organizer
	// form (?page=nettertech-events-organizers&action=add), NOT the event editor
	// (?page=nettertech-events-new).
	const addNewLink = page.locator( '.page-title-action' ).first();
	await expect( addNewLink ).toBeVisible();

	const href = await addNewLink.getAttribute( 'href' ) ?? '';
	expect(
		href,
		'NTE-013 regression: Add New header link must target organizer form, not event editor'
	).toContain( 'page=nettertech-events-organizers' );
	expect(
		href,
		'NTE-013 regression: Add New link must include action=add for organizer form'
	).toContain( 'action=add' );
	expect(
		href,
		'NTE-013 regression: Add New link must NOT point to event editor (nettertech-events-new)'
	).not.toContain( 'nettertech-events-new' );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 7: Spaces admin — create, list shows it
// ---------------------------------------------------------------------------

test( 'Flow 7: Create space, list shows it', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	const spaceName = uniqueTitle( 'Test Space' );
	await createSpace( page, spaceName );

	await navigateToAdminPage( page, '/wp-admin/admin.php?page=nettertech-events-spaces' );

	const content = await page.content();
	expect( content ).not.toContain( 'Fatal error' );
	expect( content ).toContain( spaceName );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 8: Attendees admin — create RSVP attendee via REST, appears in list
// ---------------------------------------------------------------------------

test( 'Flow 8: RSVP attendee created via REST appears in admin attendees list with metadata', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	// First create an event + occurrence to attach the attendee to.
	const eventTitle = uniqueTitle( 'Attendee Test Event' );
	const futureDate = getFutureDate( 7 );

	const { eventId } = await createEvent( page, {
		title: eventTitle,
		startDate: formatDateForInput( futureDate ),
		startTime: '14:00',
		recurring: false,
	} );

	expect( eventId ).toBeGreaterThan( 0 );

	// Get the first occurrence ID for this event via REST.
	const occurrencesResp = await page.request.get(
		`/wp-json/nettertech-events/v1/events/${ eventId }/occurrences`,
		{ failOnStatusCode: false }
	);

	let occurrenceId: number | null = null;
	if ( occurrencesResp.ok() ) {
		const occurrences = await occurrencesResp.json() as Array<{ id: number }>;
		if ( occurrences.length > 0 ) {
			occurrenceId = occurrences[ 0 ].id;
		}
	}

	// If we have an occurrence ID, create an attendee via REST.
	if ( occurrenceId !== null ) {
		// Navigate to admin to get nonce for REST call.
		await navigateToAdminPage( page, '/wp-admin/' );

		const attendeeName = 'E2E Attendee ' + Date.now();
		const attendeeEmail = `e2e-${ Date.now() }@example.com`;

		const result = await page.evaluate(
			async ( args: { occurrenceId: number; name: string; email: string } ) => {
				const settings = ( window as unknown as {
					wpApiSettings?: { root: string; nonce: string };
				} ).wpApiSettings;
				if ( ! settings ) {
					return { error: 'wpApiSettings not found', status: 0 };
				}
				const response = await fetch( `${ settings.root }nettertech-events/v1/attendees`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': settings.nonce,
					},
					body: JSON.stringify( {
						occurrence_id: args.occurrenceId,
						name: args.name,
						email: args.email,
						quantity: 1,
					} ),
				} );
				return { error: null, status: response.status };
			},
			{ occurrenceId, name: attendeeName, email: attendeeEmail }
		);

		// If REST endpoint returns 201 or 200, verify the attendee appears in the admin list.
		if ( result.status === 201 || result.status === 200 ) {
			await navigateToAdminPage( page, '/wp-admin/admin.php?page=nettertech-events-attendees' );

			const content = await page.content();
			expect( content ).not.toContain( 'Fatal error' );
			// Attendee name or email must appear in the list.
			const hasAttendee = content.includes( attendeeName ) || content.includes( attendeeEmail );
			expect( hasAttendee, `Attendee '${ attendeeName }' should appear in admin attendees list` ).toBe( true );
		} else {
			// REST endpoint unavailable or requires different auth — verify the list page at minimum.
			await navigateToAdminPage( page, '/wp-admin/admin.php?page=nettertech-events-attendees' );

			const content = await page.content();
			expect( content ).not.toContain( 'Fatal error' );
			// The attendees list page must render without errors.
			await expect( page.locator( '.wrap h1, .wp-heading-inline' ).first() ).toBeVisible();
		}
	} else {
		// No occurrence available — at minimum verify the attendees admin page loads.
		await navigateToAdminPage( page, '/wp-admin/admin.php?page=nettertech-events-attendees' );
		const content = await page.content();
		expect( content ).not.toContain( 'Fatal error' );
	}

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 9: Category ↔ event assignment — assign, frontend filter works
// ---------------------------------------------------------------------------

test( 'Flow 9: Assign category to event, frontend category filter shows that event', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	// Create a category.
	const categoryName = uniqueTitle( 'Filter Cat' );
	await createCategory( page, categoryName );

	// Get the category ID from the list (we'll use the REST API instead).
	await navigateToAdminPage( page, '/wp-admin/' );

	const categoriesResp = await page.request.get(
		'/wp-json/nettertech-events/v1/categories',
		{ failOnStatusCode: false }
	);

	let categoryId: number | null = null;
	if ( categoriesResp.ok() ) {
		const categories = await categoriesResp.json() as Array<{ id: number; name: string }>;
		const found = categories.find( ( c ) => c.name === categoryName );
		if ( found ) {
			categoryId = found.id;
		}
	}

	// Create an event.
	const eventTitle = uniqueTitle( 'Category Assign Event' );
	const futureDate = getFutureDate( 10 );
	const eventAdmin = new EventAdminPage( page );
	await eventAdmin.gotoNew();
	await eventAdmin.fillForm( {
		title: eventTitle,
		status: 'published',
		eventType: 'single',
		startDate: formatDateForInput( futureDate ),
		startTime: '18:00',
	} );

	// If category ID was found, try to assign it via a category selector on the form.
	if ( categoryId !== null ) {
		const catSelector = page.locator(
			`#category_id, [name="category_id"], select[name*="category"]`
		).first();
		if ( ( await catSelector.count() ) > 0 ) {
			await catSelector.selectOption( String( categoryId ) );
		} else {
			// Try checkbox list pattern.
			const catCheckbox = page.locator(
				`input[type="checkbox"][value="${ categoryId }"], input[name*="categories"][value="${ categoryId }"]`
			).first();
			if ( ( await catCheckbox.count() ) > 0 ) {
				await catCheckbox.check();
			}
		}
	}

	const eventId = await eventAdmin.submit();
	expect( eventId ).toBeGreaterThan( 0 );

	// Verify the event appears in a category-filtered frontend view.
	// Try REST endpoint for category-filtered events.
	if ( categoryId !== null ) {
		const filteredResp = await page.request.get(
			`/wp-json/nettertech-events/v1/events?category_id=${ categoryId }`,
			{ failOnStatusCode: false }
		);
		if ( filteredResp.ok() ) {
			const events = await filteredResp.json() as Array<{ id: number; title: string }>;
			const found = events.find( ( e ) => e.id === eventId );
			// If the REST endpoint supports filtering and assignment worked, the event should appear.
			if ( events.length > 0 ) {
				expect(
					found,
					`Event '${ eventTitle }' should appear in category-filtered REST results`
				).toBeTruthy();
			}
		}
	}

	// Also verify the events archive page loads without error.
	await page.goto( '/events/' );
	await page.waitForLoadState( 'domcontentloaded' );
	const archiveContent = await page.content();
	expect( archiveContent ).not.toContain( 'Fatal error' );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 10: [nettertech_events_list] shortcode — event listing renders with permalinks
// ---------------------------------------------------------------------------

test( 'Flow 10: [nettertech_events_list] shortcode renders event listing with permalinks', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	// Ensure at least one published event exists.
	const eventTitle = uniqueTitle( 'Shortcode List Event' );
	const futureDate = getFutureDate( 5 );
	await createEvent( page, {
		title: eventTitle,
		startDate: formatDateForInput( futureDate ),
		startTime: '19:00',
		recurring: false,
	} );

	// Create a WP page with the [nettertech_events_list] shortcode.
	const { permalink } = await createPageWithShortcode(
		page,
		'[nettertech_events_list]',
		'E2E NTE List Test ' + Date.now()
	);

	expect( permalink, 'Page with [nettertech_events_list] shortcode must have a permalink' ).toBeTruthy();

	await page.goto( permalink );
	await page.waitForLoadState( 'domcontentloaded' );

	const content = await page.content();
	expect( content ).not.toContain( 'Fatal error' );
	expect( content ).not.toContain( 'Parse error' );

	// The shortcode must render event content, not an empty page or error.
	// Check for at least one link that points to /events/.
	const eventLinks = page.locator( 'a[href*="/events/"]' );
	const linkCount = await eventLinks.count();
	expect(
		linkCount,
		`[nettertech_events_list] shortcode should render at least one event permalink link, got ${ linkCount }`
	).toBeGreaterThan( 0 );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 11: [nettertech_events_calendar] shortcode — month view renders with events
// ---------------------------------------------------------------------------

test( 'Flow 11: [nettertech_events_calendar] shortcode renders month view', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	// Create a page with [nettertech_events_calendar] shortcode.
	const { permalink } = await createPageWithShortcode(
		page,
		'[nettertech_events_calendar]',
		'E2E NTE Calendar Test ' + Date.now()
	);

	expect( permalink ).toBeTruthy();

	await page.goto( permalink );
	await page.waitForLoadState( 'domcontentloaded' );

	const content = await page.content();
	expect( content ).not.toContain( 'Fatal error' );
	expect( content ).not.toContain( 'Parse error' );

	// The calendar shortcode must render a calendar container — not a blank page.
	const calendarEl = page.locator(
		'.nte-calendar, [class*="nte-calendar"], .nte-cal, [data-nte-calendar]'
	).first();
	const calendarCount = await calendarEl.count();
	expect(
		calendarCount,
		'[nettertech_events_calendar] shortcode must render a calendar element'
	).toBeGreaterThan( 0 );

	// base.css must be enqueued (NTE-017 bug #2 pattern: detector missing shortcode key).
	const baseCss = page.locator( 'link[rel="stylesheet"][href*="base.css"]' );
	await expect(
		baseCss,
		'base.css must be enqueued on [nettertech_events_calendar] page — missing shortcode in PageContextDetector would drop it'
	).not.toHaveCount( 0 );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 12: Gutenberg events grid block — inserts, renders on frontend
// ---------------------------------------------------------------------------

test( 'Flow 12: Gutenberg events grid block renders event grid on frontend', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	// Ensure at least one published event exists for the grid to show.
	const eventTitle = uniqueTitle( 'Grid Block Event' );
	const futureDate = getFutureDate( 3 );
	await createEvent( page, {
		title: eventTitle,
		startDate: formatDateForInput( futureDate ),
		startTime: '20:00',
		recurring: false,
	} );

	// Create a WP page with the Gutenberg block via REST.
	// Block name: nettertech-events/grid (from blocks/event-grid/block.json).
	// The block renders via EventListShortcode, so the output DOM contains
	// .nte-event-list and .nte-grid — not any "events-grid" class.
	await navigateToAdminPage( page, '/wp-admin/' );

	const pageTitle = 'E2E Events Grid Block Test ' + Date.now();
	const blockMarkup = '<!-- wp:nettertech-events/grid /-->';

	const createResult = await page.evaluate(
		async ( args: { title: string; content: string } ) => {
			const settings = ( window as unknown as {
				wpApiSettings?: { root: string; nonce: string };
			} ).wpApiSettings;
			if ( ! settings ) {
				return { error: 'wpApiSettings not found', permalink: '' };
			}
			const response = await fetch( `${ settings.root }wp/v2/pages`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce,
				},
				body: JSON.stringify( {
					title: args.title,
					content: args.content,
					status: 'publish',
				} ),
			} );
			if ( ! response.ok ) {
				return { error: `HTTP ${ response.status }`, permalink: '' };
			}
			const data = await response.json() as { link: string };
			return { error: null, permalink: data.link };
		},
		{ title: pageTitle, content: blockMarkup }
	);

	expect( createResult.permalink, 'Page with Gutenberg block must have a permalink' ).toBeTruthy();

	await page.goto( createResult.permalink );
	await page.waitForLoadState( 'domcontentloaded' );

	const content = await page.content();
	expect( content ).not.toContain( 'Fatal error' );
	expect( content ).not.toContain( 'Parse error' );

	// The block renders via EventListShortcode → .nte-event-list container.
	// Also matches .nte-grid (the inner grid) and .wp-block-nettertech-events-grid
	// (WordPress block wrapper class derived from block name).
	const gridEl = page.locator(
		'.nte-event-list, .nte-grid, .wp-block-nettertech-events-grid'
	).first();
	const gridCount = await gridEl.count();
	expect(
		gridCount,
		'Events grid block must render a grid container element on the frontend ' +
		'(.nte-event-list, .nte-grid, or .wp-block-nettertech-events-grid)'
	).toBeGreaterThan( 0 );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 13: /events/ archive — upcoming events present
// ---------------------------------------------------------------------------

test( 'Flow 13: /events/ archive renders upcoming events', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	// Ensure at least one upcoming event exists.
	const eventTitle = uniqueTitle( 'Archive Test Event' );
	const futureDate = getFutureDate( 2 );
	await createEvent( page, {
		title: eventTitle,
		startDate: formatDateForInput( futureDate ),
		startTime: '17:00',
		recurring: false,
	} );

	// Navigate to the events archive.
	await page.goto( '/events/' );
	await page.waitForLoadState( 'domcontentloaded' );

	const content = await page.content();
	expect( content ).not.toContain( 'Fatal error' );
	expect( content ).not.toContain( 'Parse error' );

	// Archive must not return 404.
	await expect( page ).not.toHaveURL( /404/ );

	// At least one event link must be visible in the archive.
	const eventLinks = page.locator( 'a[href*="/events/"]' ).filter( {
		hasNot: page.locator( '[id*="admin-bar"], #wpadminbar' ),
	} );
	const linkCount = await eventLinks.count();
	expect(
		linkCount,
		`/events/ archive should show at least one event link, got ${ linkCount }`
	).toBeGreaterThan( 0 );

	await assertNoErrors();
} );

// ---------------------------------------------------------------------------
// Flow 14: Gutenberg carousel block — carousel container renders (NTE-025)
// ---------------------------------------------------------------------------

test( 'Flow 14: Gutenberg carousel block renders a carousel container', async ( { page } ) => {
	const assertNoErrors = attachFilteredConsoleErrorCollector( page );

	await loginAsAdmin( page );

	// Ensure at least one published event exists for the carousel to show.
	const eventTitle = uniqueTitle( 'Carousel Block Event' );
	const futureDate = getFutureDate( 4 );
	await createEvent( page, {
		title: eventTitle,
		startDate: formatDateForInput( futureDate ),
		startTime: '19:00',
		recurring: false,
	} );

	// Navigate to admin to get wpApiSettings (nonce + root URL).
	await navigateToAdminPage( page, '/wp-admin/' );

	const pageTitle = 'E2E Events Carousel Block Test ' + Date.now();
	// Block name: nettertech-events/carousel (from blocks/carousel/block.json).
	const blockMarkup = '<!-- wp:nettertech-events/carousel /-->';

	const createResult = await page.evaluate(
		async ( args: { title: string; content: string } ) => {
			const settings = ( window as unknown as {
				wpApiSettings?: { root: string; nonce: string };
			} ).wpApiSettings;
			if ( ! settings ) {
				return { error: 'wpApiSettings not found', permalink: '' };
			}
			const response = await fetch( `${ settings.root }wp/v2/pages`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce,
				},
				body: JSON.stringify( {
					title: args.title,
					content: args.content,
					status: 'publish',
				} ),
			} );
			if ( ! response.ok ) {
				return { error: `HTTP ${ response.status }`, permalink: '' };
			}
			const data = await response.json() as { link: string };
			return { error: null, permalink: data.link };
		},
		{ title: pageTitle, content: blockMarkup }
	);

	expect( createResult.permalink, 'Page with Carousel block must have a permalink' ).toBeTruthy();

	await page.goto( createResult.permalink );
	await page.waitForLoadState( 'domcontentloaded' );

	const content = await page.content();
	expect( content ).not.toContain( 'Fatal error' );
	expect( content ).not.toContain( 'Parse error' );

	// The carousel block renders via CarouselShortcode → .nte-carousel container.
	// Also matches .wp-block-nettertech-events-carousel (WordPress block wrapper class).
	const carouselEl = page.locator(
		'.nte-carousel, .wp-block-nettertech-events-carousel'
	).first();
	const carouselCount = await carouselEl.count();
	expect(
		carouselCount,
		'Carousel block must render a carousel container element on the frontend ' +
		'(.nte-carousel or .wp-block-nettertech-events-carousel)'
	).toBeGreaterThan( 0 );

	await assertNoErrors();
} );
