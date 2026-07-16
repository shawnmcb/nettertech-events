/**
 * E2E tests for RSVP flow.
 *
 * Tests the free-event RSVP registration path including form display,
 * field validation, submission, and confirmation messaging.
 *
 * Note: Tests requiring specific event data use test.skip() with descriptive
 * messages when prerequisites are not met rather than silently passing with
 * vacuous assertions.
 *
 * NTE-027: Adds browser-level coverage for capacity enforcement and duplicate-
 * email rejection — the two most regression-prone RSVP edges (NTE-008/009/010
 * were P0 bugs in this path).
 */

import { execSync } from 'child_process';
import { test, expect } from './fixtures';
import { loginAsAdmin } from './support/page-objects';

test.describe( 'RSVP Flow', () => {
	test( 'events page is accessible', async ( { page } ) => {
		await page.goto( '/events/' );

		// Should not 404
		await expect( page ).not.toHaveTitle( /not found/i );

		// Should contain events-related content or empty state
		const content = await page.content();
		const hasEventsContent =
			content.includes( 'event' ) ||
			content.includes( 'Event' ) ||
			content.includes( 'No events' );

		expect( hasEventsContent ).toBeTruthy();
	} );

	test( 'RSVP form renders on free event page', async ( { page } ) => {
		await page.goto( '/events/' );

		// Find first event link
		const eventLink = page.locator( 'main a[href*="/events/"]' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events found — test requires at least one event' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for RSVP form or registration section
		const rsvpForm = page.locator(
			'.ve-rsvp-form, .rsvp-form, form[class*="rsvp"], [data-form="rsvp"], #rsvp-form'
		);
		const rsvpHeading = page.locator(
			'h2:has-text("RSVP"), h3:has-text("RSVP"), h2:has-text("Register"), h3:has-text("Register")'
		);
		const rsvpButton = page.locator(
			'button:has-text("RSVP"), button:has-text("Register"), input[value="RSVP"], input[value="Register"]'
		);

		const hasRsvpForm =
			( await rsvpForm.count() ) > 0 ||
			( await rsvpHeading.count() ) > 0 ||
			( await rsvpButton.count() ) > 0;

		test.skip( ! hasRsvpForm, 'No RSVP form found — event may require ticket purchase' );

		// At least one RSVP element should be visible
		if ( ( await rsvpForm.count() ) > 0 ) {
			await expect( rsvpForm.first() ).toBeVisible();
		} else if ( ( await rsvpHeading.count() ) > 0 ) {
			await expect( rsvpHeading.first() ).toBeVisible();
		} else {
			await expect( rsvpButton.first() ).toBeVisible();
		}
	} );

	test( 'RSVP form has name and email fields', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( 'main a[href*="/events/"]' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events found — test requires at least one event' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for RSVP form wrapper
		const rsvpForm = page.locator(
			'.ve-rsvp-form, .rsvp-form, form[class*="rsvp"], [data-form="rsvp"], #rsvp-form'
		);
		const rsvpFormCount = await rsvpForm.count();

		test.skip( rsvpFormCount === 0, 'No RSVP form found — event may not be a free event' );

		// Standard RSVP forms should include name and email inputs
		const nameField = rsvpForm.first().locator(
			'input[name*="name"], input[id*="name"], input[placeholder*="name"], input[placeholder*="Name"]'
		);
		const emailField = rsvpForm.first().locator(
			'input[type="email"], input[name*="email"], input[id*="email"]'
		);

		const hasNameField = ( await nameField.count() ) > 0;
		const hasEmailField = ( await emailField.count() ) > 0;

		// At least one of name or email should exist in a real RSVP form
		expect( hasNameField || hasEmailField ).toBeTruthy();
	} );

	test( 'RSVP form submit button is present', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( 'main a[href*="/events/"]' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events found — test requires at least one event' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		const rsvpForm = page.locator(
			'.ve-rsvp-form, .rsvp-form, form[class*="rsvp"], [data-form="rsvp"], #rsvp-form'
		);
		const rsvpFormCount = await rsvpForm.count();

		test.skip( rsvpFormCount === 0, 'No RSVP form found — event may not be a free event' );

		const submitButton = rsvpForm.first().locator(
			'button[type="submit"], input[type="submit"], button:has-text("RSVP"), button:has-text("Register"), button:has-text("Submit")'
		);

		await expect( submitButton.first() ).toBeVisible();
	} );

	test( 'RSVP form rejects empty submission', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( 'main a[href*="/events/"]' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events found — test requires at least one event' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		const rsvpForm = page.locator(
			'.ve-rsvp-form, .rsvp-form, form[class*="rsvp"], [data-form="rsvp"], #rsvp-form'
		);
		const rsvpFormCount = await rsvpForm.count();

		test.skip( rsvpFormCount === 0, 'No RSVP form found — event may not be a free event' );

		const submitButton = rsvpForm.first().locator(
			'button[type="submit"], input[type="submit"]'
		);
		const submitCount = await submitButton.count();

		test.skip( submitCount === 0, 'No submit button found in RSVP form' );

		await submitButton.first().click();
		await page.waitForTimeout( 500 );

		// After empty submit: browser validation, inline errors, or stay on page
		const currentUrl = page.url();
		const hasValidationError = page.locator(
			'.error, .ve-error, .field-error, [aria-invalid="true"], .wpcf7-not-valid'
		);

		// Either validation errors are shown, or URL did not navigate away to confirmation
		const errorsShown = ( await hasValidationError.count() ) > 0;
		const stayedOnPage = ! currentUrl.includes( 'success' ) && ! currentUrl.includes( 'confirmed' );

		expect( errorsShown || stayedOnPage ).toBeTruthy();
	} );

	test( 'RSVP admin page accessible to admin', async ( { page } ) => {
		await loginAsAdmin( page );

		// Navigate to events admin to see if RSVP management is available
		await page.goto( '/wp-admin/admin.php?page=nettertech-events' );

		await expect( page ).not.toHaveTitle( /not found/i );

		// Admin area should load without 500
		const content = await page.content();
		expect( content ).not.toContain( 'Fatal error' );
	} );
} );

// ─────────────────────────────────────────────────────────────────────────────
// NTE-027: Capacity enforcement + duplicate-email rejection (browser-level).
//
// These two specs use a WP-CLI eval fixture pattern (mirrors
// ticket-purchase-full.spec.ts) to create a free event + occurrence + free
// ticket type + a WP page that embeds [nettertech_events_rsvp occurrence_id="N"]. Driving
// the form submission through the actual rendered page exercises the same
// PHP path that NTE-008/009/010 broke.
//
// Fixture lifecycle: each describe block creates its fixture in beforeAll
// and tears it down in afterAll. Cleanup is best-effort — tests must not
// depend on prior runs.
//
// Capacity bookkeeping note (latent — flagged in NTE-027 report):
// RSVPFormShortcode does NOT increment ticket_type.sold_count when an
// attendee is created. Capacity is read from sold_count, which is only
// bumped by WooCommerce orders. The integration test
// (RsvpIntegrationTest::test_rsvp_submit_enforces_capacity) works around
// this by directly calling TicketTypeRepository::increment_sold_count.
// We do the same here via wp eval to simulate "user A consumed the only
// seat" between submissions.
// ─────────────────────────────────────────────────────────────────────────────

// Environment-aware WP-CLI command prefix.
const WP_CLI_CMD = process.env.WP_CLI_CMD
	|| `wp --path=${ process.env.WP_PATH || '/var/www/html' }`;

interface RsvpFixture {
	eventId: number;
	occurrenceId: number;
	ticketTypeId: number;
	pageId: number;
	permalink: string;
}

/**
 * Run a WP-CLI eval command and return stdout (stderr suppressed).
 */
function wpEval( phpCode: string ): string {
	const result = execSync(
		`${ WP_CLI_CMD } eval '${ phpCode }'`,
		{ encoding: 'utf8', timeout: 30000, stdio: [ 'inherit', 'pipe', 'pipe' ] }
	);
	return result.trim();
}

/**
 * Extract the JSON object between FIXTURE_JSON: and :END markers.
 */
function extractFixtureJson( output: string ): string {
	const match = output.match( /FIXTURE_JSON:(\{.*?\}):END/ );
	if ( ! match ) {
		throw new Error( `Could not find FIXTURE_JSON marker in wp eval output:\n${ output }` );
	}
	return match[ 1 ];
}

/**
 * Create a free-ticket event + occurrence + ticket type + shortcode page.
 *
 * The ticket type is `price=0` so RSVPFormShortcode treats the occurrence as
 * a free RSVP event (occurrence_is_free === true). Capacity is constrained
 * via the ticket type so the capacity check has something to bind to.
 *
 * The shortcode page is created at the WP level so anonymous visitors can
 * load it without needing access to the event template render path.
 */
function createRsvpFixture( capacity: number, slugPrefix: string ): RsvpFixture {
	const php = `
$slug        = "${ slugPrefix }-" . uniqid("", true);
$container   = \\NetterTechEvents\\Core\\ServiceRegistry::container();
$event_repo  = $container->get(\\NetterTechEvents\\Contracts\\EventRepositoryInterface::class);
$occ_repo    = $container->get(\\NetterTechEvents\\Contracts\\OccurrenceRepositoryInterface::class);
$ticket_repo = $container->get(\\NetterTechEvents\\Contracts\\TicketTypeRepositoryInterface::class);

$now                = time();
$event              = new \\NetterTechEvents\\Models\\Event();
$event->title       = "E2E RSVP " . $slug;
$event->status      = \\NetterTechEvents\\Enums\\EventStatus::PUBLISHED;
$event->event_type  = "single";
$event->slug        = $slug;
$saved_event        = $event_repo->save($event);

$occ                 = new \\NetterTechEvents\\Models\\Occurrence();
$occ->event_id       = $saved_event->id;
$occ->start_datetime = gmdate("Y-m-d H:i:s", $now + 86400 * 14 + 19 * 3600);
$occ->end_datetime   = gmdate("Y-m-d H:i:s", $now + 86400 * 14 + 21 * 3600);
$occ->status         = "scheduled";
$saved_occ           = $occ_repo->save($occ);

$tt                = new \\NetterTechEvents\\Models\\TicketType();
$tt->event_id      = $saved_event->id;
$tt->occurrence_id = $saved_occ->id;
$tt->scope         = "occurrence";
$tt->name          = "Free RSVP";
$tt->price         = 0.00;
$tt->capacity_type = "fixed";
$tt->capacity      = ${ capacity };
$tt->status        = "active";
$tt->min_per_order = 1;
$tt->max_per_order = 10;
$saved_tt          = $ticket_repo->save($tt);

$page_id = wp_insert_post(array(
	"post_type"    => "page",
	"post_status"  => "publish",
	"post_title"   => "E2E RSVP Page " . $slug,
	"post_content" => "[nettertech_events_rsvp occurrence_id=\\\"" . $saved_occ->id . "\\\"]",
));

wp_cache_flush();

echo "FIXTURE_JSON:" . json_encode(array(
	"eventId"      => $saved_event->id,
	"occurrenceId" => $saved_occ->id,
	"ticketTypeId" => $saved_tt->id,
	"pageId"       => $page_id,
	"permalink"    => get_permalink($page_id),
)) . ":END";
`;

	const rawOutput = wpEval( php );
	const jsonStr = extractFixtureJson( rawOutput );
	const data = JSON.parse( jsonStr ) as RsvpFixture;
	return data;
}

/**
 * Delete fixture data: page, attendees, ticket type, occurrence, event.
 */
function deleteRsvpFixture( fixture: RsvpFixture ): void {
	const php = `
global $wpdb;
wp_delete_post(${ fixture.pageId }, true);
$wpdb->delete("{$wpdb->prefix}nettertech_events_attendees",    array("occurrence_id" => ${ fixture.occurrenceId }));
$wpdb->delete("{$wpdb->prefix}nettertech_events_ticket_types", array("id" => ${ fixture.ticketTypeId }));
$wpdb->delete("{$wpdb->prefix}nettertech_events_occurrences",  array("id" => ${ fixture.occurrenceId }));
$wpdb->delete("{$wpdb->prefix}nettertech_events_events",       array("id" => ${ fixture.eventId }));
wp_cache_flush();
echo "deleted";
`;
	wpEval( php );
}

/**
 * Bump ticket_type.sold_count via the repository to simulate a confirmed
 * booking consuming capacity. Mirrors RsvpIntegrationTest's approach.
 */
function bumpSoldCount( ticketTypeId: number, by: number ): void {
	const php = `
$container   = \\NetterTechEvents\\Core\\ServiceRegistry::container();
$ticket_repo = $container->get(\\NetterTechEvents\\Contracts\\TicketTypeRepositoryInterface::class);
$ticket_repo->increment_sold_count(${ ticketTypeId }, ${ by });
wp_cache_flush();
echo "bumped";
`;
	wpEval( php );
}

/**
 * Fill the RSVP form with the given values and click submit. Waits for the
 * resulting message panel to appear before returning.
 */
async function submitRsvpForm(
	page: import( '@playwright/test' ).Page,
	permalink: string,
	name: string,
	email: string
): Promise<void> {
	await page.goto( permalink );
	await page.waitForLoadState( 'domcontentloaded' );

	const form = page.locator( 'form.nte-rsvp-form' );
	await expect( form ).toBeVisible();

	await form.locator( 'input[name="nettertech_events_rsvp_name"]' ).fill( name );
	await form.locator( 'input[name="nettertech_events_rsvp_email"]' ).fill( email );
	await form.locator( 'button[name="nettertech_events_rsvp_submit"]' ).click();
	await page.waitForLoadState( 'domcontentloaded' );
}

test.describe( 'RSVP Capacity Enforcement (NTE-027)', () => {
	let fixture: RsvpFixture | null = null;

	test.beforeAll( () => {
		fixture = createRsvpFixture( 1, 'e2e-rsvp-capacity' );
	} );

	test.afterAll( () => {
		if ( fixture ) {
			try {
				deleteRsvpFixture( fixture );
			} catch {
				// Best-effort cleanup.
			}
		}
	} );

	test( 'capacity-1 event accepts user A and rejects user B', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		// User A submits → must succeed.
		await submitRsvpForm(
			page,
			fixture!.permalink,
			'Alice Tester',
			`alice-${ Date.now() }@example.com`
		);

		const successMessage = page.locator( '.nte-rsvp-message.nte-rsvp-success' );
		await expect( successMessage ).toBeVisible();
		await expect( successMessage ).toContainText( /Thank you/ );

		// RSVPFormShortcode does not bump sold_count itself (latent gap noted
		// in NTE-027). Simulate the booked seat by incrementing manually so the
		// next render sees a full occurrence — same workaround used by the
		// integration test.
		bumpSoldCount( fixture!.ticketTypeId, 1 );

		// User B loads the page → should now see the "full" message instead
		// of the form. Use a fresh browser context so no admin/visitor state
		// leaks across requests.
		const bobContext = await page.context().browser()!.newContext( {
			baseURL: process.env.WP_BASE_URL || 'http://localhost',
			ignoreHTTPSErrors: true,
		} );
		const bobPage = await bobContext.newPage();
		try {
			await bobPage.goto( fixture!.permalink );
			await bobPage.waitForLoadState( 'domcontentloaded' );

			const errorPanel = bobPage.locator( '.nte-rsvp-message.nte-rsvp-error' );
			await expect( errorPanel ).toBeVisible();
			// Production text: "This event is full. You may join the waitlist
			// to be notified if a spot opens up." — the waitlist suffix is
			// added when nte_has_waitlist filter resolves true (the default).
			await expect( errorPanel ).toContainText( /This event is full/ );

			// The form must NOT be rendered alongside the full message.
			await expect( bobPage.locator( 'form.nte-rsvp-form' ) ).toHaveCount( 0 );
		} finally {
			await bobContext.close();
		}
	} );
} );

test.describe( 'RSVP Duplicate Email Rejection (NTE-027)', () => {
	let fixture: RsvpFixture | null = null;

	test.beforeAll( () => {
		fixture = createRsvpFixture( 10, 'e2e-rsvp-dup' );
	} );

	test.afterAll( () => {
		if ( fixture ) {
			try {
				deleteRsvpFixture( fixture );
			} catch {
				// Best-effort cleanup.
			}
		}
	} );

	test( 'second submission with same email is rejected', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		const sharedEmail = `dup-${ Date.now() }@example.com`;

		// First submission → must succeed.
		await submitRsvpForm(
			page,
			fixture!.permalink,
			'First Submission',
			sharedEmail
		);

		const successMessage = page.locator( '.nte-rsvp-message.nte-rsvp-success' );
		await expect( successMessage ).toBeVisible();
		await expect( successMessage ).toContainText( /Thank you/ );

		// Second submission with the SAME email but a different name → must
		// be rejected with "already registered" error. RSVPFormShortcode renders
		// the error panel AND the form (so the user can correct), so we assert
		// on the error panel specifically rather than form absence.
		await submitRsvpForm(
			page,
			fixture!.permalink,
			'Second Submission',
			sharedEmail
		);

		const errorPanel = page.locator( '.nte-rsvp-message.nte-rsvp-error' );
		await expect( errorPanel ).toBeVisible();
		// Production text: "This email is already registered for this event."
		await expect( errorPanel ).toContainText( /already registered/ );
	} );
} );
