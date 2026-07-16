/**
 * E2E tests for waitlist flow.
 *
 * Tests the sold-out event waitlist path including join form, position
 * display, and admin visibility of waitlist entries.
 *
 * Note: Tests requiring a sold-out event use test.skip() with descriptive
 * messages. "Sold out" state cannot be guaranteed without seeded data.
 */

import { test, expect } from './fixtures';
import { loginAsAdmin } from './support/page-objects';

test.describe( 'Waitlist Page Accessibility', () => {
	test( 'events page loads without errors', async ( { page } ) => {
		await page.goto( '/events/' );

		await expect( page ).not.toHaveTitle( /not found/i );

		const content = await page.content();
		expect( content ).not.toContain( 'Fatal error' );
		expect( content ).not.toContain( 'Parse error' );
	} );

	test( 'sold-out indicator visible when event has no availability', async ( { page } ) => {
		await page.goto( '/events/' );

		// Look for any sold-out indicators across all events
		const soldOutIndicator = page.locator(
			'.sold-out, .ve-sold-out, [class*="sold-out"], .ve-capacity-full, [class*="capacity-full"]'
		);
		const soldOutText = page.locator(
			':text("Sold Out"), :text("sold out"), :text("Fully Booked"), :text("No Availability")'
		);

		const indicatorCount = await soldOutIndicator.count();
		const textCount = await soldOutText.count();

		// Skip if no sold-out events exist
		test.skip(
			indicatorCount === 0 && textCount === 0,
			'No sold-out events found — test requires at least one sold-out event'
		);

		// If sold-out events exist, indicators should be visible
		if ( indicatorCount > 0 ) {
			await expect( soldOutIndicator.first() ).toBeVisible();
		} else {
			await expect( soldOutText.first() ).toBeVisible();
		}
	} );
} );

test.describe( 'Waitlist Join Form', () => {
	test( 'waitlist form visible on sold-out event', async ( { page } ) => {
		await page.goto( '/events/' );

		// Collect all event hrefs up front. Reading hrefs inside the navigation
		// loop fails because page.goto() detaches the original locator scope.
		const eventHrefs = await page.locator( 'main a[href*="/events/"]' ).evaluateAll(
			( links ) => links.map( ( link ) => link.getAttribute( 'href' ) ).filter( ( h ): h is string => !! h )
		);

		test.skip( eventHrefs.length === 0, 'No events found — test requires at least one event' );

		let waitlistFound = false;
		const maxCheck = Math.min( eventHrefs.length, 5 );

		for ( let i = 0; i < maxCheck; i++ ) {
			await page.goto( eventHrefs[ i ] );
			await page.waitForLoadState( 'networkidle' );

			const waitlistForm = page.locator(
				'.ve-waitlist-form, .waitlist-form, form[class*="waitlist"], [data-form="waitlist"], #waitlist-form'
			);
			const waitlistButton = page.locator(
				'button:has-text("Join Waitlist"), button:has-text("Waitlist"), a:has-text("Join Waitlist")'
			);

			if ( ( await waitlistForm.count() ) > 0 || ( await waitlistButton.count() ) > 0 ) {
				waitlistFound = true;
				if ( ( await waitlistForm.count() ) > 0 ) {
					await expect( waitlistForm.first() ).toBeVisible();
				} else {
					await expect( waitlistButton.first() ).toBeVisible();
				}
				break;
			}
		}

		test.skip( ! waitlistFound, 'No sold-out events with waitlist form found — test requires a sold-out event with waitlist enabled' );
	} );

	test( 'waitlist form has email field', async ( { page } ) => {
		await page.goto( '/events/' );

		// Collect all event hrefs up front. Reading hrefs inside the navigation
		// loop fails because page.goto() detaches the original locator scope.
		const eventHrefs = await page.locator( 'main a[href*="/events/"]' ).evaluateAll(
			( links ) => links.map( ( link ) => link.getAttribute( 'href' ) ).filter( ( h ): h is string => !! h )
		);

		test.skip( eventHrefs.length === 0, 'No events found — test requires at least one event' );

		let waitlistFormFound = false;
		const maxCheck = Math.min( eventHrefs.length, 5 );

		for ( let i = 0; i < maxCheck; i++ ) {
			await page.goto( eventHrefs[ i ] );
			await page.waitForLoadState( 'networkidle' );

			const waitlistForm = page.locator(
				'.ve-waitlist-form, .waitlist-form, form[class*="waitlist"], [data-form="waitlist"]'
			);

			if ( ( await waitlistForm.count() ) > 0 ) {
				waitlistFormFound = true;

				const emailField = waitlistForm.first().locator(
					'input[type="email"], input[name*="email"], input[id*="email"]'
				);

				const fieldCount = await emailField.count();
				expect( fieldCount ).toBeGreaterThan( 0 );
				break;
			}
		}

		test.skip( ! waitlistFormFound, 'No waitlist form found — test requires a sold-out event with waitlist enabled' );
	} );

	test( 'waitlist form has submit button', async ( { page } ) => {
		await page.goto( '/events/' );

		// Collect all event hrefs up front. Reading hrefs inside the navigation
		// loop fails because page.goto() detaches the original locator scope.
		const eventHrefs = await page.locator( 'main a[href*="/events/"]' ).evaluateAll(
			( links ) => links.map( ( link ) => link.getAttribute( 'href' ) ).filter( ( h ): h is string => !! h )
		);

		test.skip( eventHrefs.length === 0, 'No events found — test requires at least one event' );

		let waitlistFormFound = false;
		const maxCheck = Math.min( eventHrefs.length, 5 );

		for ( let i = 0; i < maxCheck; i++ ) {
			await page.goto( eventHrefs[ i ] );
			await page.waitForLoadState( 'networkidle' );

			const waitlistForm = page.locator(
				'.ve-waitlist-form, .waitlist-form, form[class*="waitlist"], [data-form="waitlist"]'
			);

			if ( ( await waitlistForm.count() ) > 0 ) {
				waitlistFormFound = true;

				const submitButton = waitlistForm.first().locator(
					'button[type="submit"], input[type="submit"], button:has-text("Join"), button:has-text("Submit")'
				);

				await expect( submitButton.first() ).toBeVisible();
				break;
			}
		}

		test.skip( ! waitlistFormFound, 'No waitlist form found — test requires a sold-out event with waitlist enabled' );
	} );
} );

test.describe( 'Waitlist Position Display', () => {
	test.skip( true, 'TODO: Requires confirmed waitlist submission. Implement when test data fixtures are available.' );

	// Implementation notes for when fixtures are available:
	// 1. Create event with capacity = 1
	// 2. Register one attendee to fill capacity
	// 3. Navigate to event page as second visitor
	// 4. Verify waitlist form appears (not ticket/RSVP form)
	// 5. Submit waitlist form with name and email
	// 6. Verify confirmation message appears with position number
	//    e.g. "You are #1 on the waitlist" or "Position: 1"
	// 7. Navigate as third visitor, join waitlist
	// 8. Verify position is #2
} );

test.describe( 'Waitlist Admin', () => {
	test( 'waitlist admin section is accessible to admin', async ( { page } ) => {
		await loginAsAdmin( page );

		// Events admin should be accessible
		await page.goto( '/wp-admin/admin.php?page=nettertech-events' );

		await expect( page ).not.toHaveTitle( /not found/i );

		const content = await page.content();
		expect( content ).not.toContain( 'Fatal error' );
	} );

	test( 'waitlist REST endpoint responds', async ( { page } ) => {
		// The waitlist REST endpoint should respond (even if empty)
		const response = await page.request.get( '/wp-json/nettertech-events/v1/waitlist', {
			failOnStatusCode: false,
		} );

		// 200 (OK), 401 (unauthorized for private endpoint), or 404 (no occurrence specified)
		// are all acceptable - the endpoint should not 500
		expect( response.status() ).not.toBe( 500 );
		expect( response.status() ).not.toBe( 502 );
		expect( response.status() ).not.toBe( 503 );
	} );
} );

test.describe( 'Waitlist Promotion', () => {
	test.skip( true, 'TODO: Requires cancellation flow to test promotion. Implement when test data fixtures are available.' );

	// Implementation notes for when fixtures are available:
	// 1. Create event with capacity = 2
	// 2. Register two attendees to fill capacity
	// 3. Add third visitor to waitlist (position #1)
	// 4. Cancel one of the original registrations via admin
	// 5. Verify waitlist person is promoted (email sent or position updated)
	// 6. Verify event shows available again
} );
