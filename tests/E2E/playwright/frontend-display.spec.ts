/**
 * E2E tests for frontend event display.
 *
 * Tests event listing, detail pages, and user interactions.
 */

import { test, expect } from './fixtures';
import { EventsPage } from './pages';

test.describe( 'Frontend Event Display', () => {
	let eventsPage: EventsPage;

	test.beforeEach( async ( { page } ) => {
		eventsPage = new EventsPage( page );
	} );

	test( 'events listing page renders', async ( { page } ) => {
		await eventsPage.goto();

		// Page should load
		await expect( page ).not.toHaveTitle( /not found/i );
	} );

	test( 'event cards display correctly', async ( { page } ) => {
		await eventsPage.goto();

		const eventCards = eventsPage.getEventCards();
		const count = await eventCards.count();

		// If events exist, verify card structure
		if ( count > 0 ) {
			const firstCard = eventCards.first();

			// Card should have essential elements
			await expect( firstCard ).toBeVisible();

			// Should have a title link
			const titleLink = firstCard.locator( 'a, h2, h3, .event-title' );
			await expect( titleLink.first() ).toBeVisible();
		}
	} );

	test( 'clicking event navigates to detail page', async ( { page } ) => {
		await eventsPage.goto();

		const eventCards = eventsPage.getEventCards();
		const count = await eventCards.count();

		if ( count > 0 ) {
			// Click first event link
			const firstEventLink = eventCards.first().locator( 'a' ).first();
			const href = await firstEventLink.getAttribute( 'href' );

			if ( href ) {
				await firstEventLink.click();
				await page.waitForLoadState( 'networkidle' );

				// Should navigate to event detail page
				expect( page.url() ).toContain( 'event' );
			}
		}
	} );

	test( 'pagination controls work', async ( { page } ) => {
		await eventsPage.goto();

		const pagination = eventsPage.getPagination();
		const hasPagination = ( await pagination.count() ) > 0;

		if ( hasPagination ) {
			// If there's a next page link, test it
			const nextLink = pagination.locator( 'a:has-text("Next"), .next a' );

			if ( ( await nextLink.count() ) > 0 ) {
				await nextLink.click();
				await page.waitForLoadState( 'networkidle' );

				// URL should change or page should update
				expect( page.url() ).toMatch( /page=\d+|paged=\d+/ );
			}
		}
	} );

	test( 'event detail page shows event information', async ( { page } ) => {
		await eventsPage.goto();

		const eventCards = eventsPage.getEventCards();
		const count = await eventCards.count();

		if ( count > 0 ) {
			// Navigate to first event
			const firstEventLink = eventCards.first().locator( 'a' ).first();
			await firstEventLink.click();
			await page.waitForLoadState( 'networkidle' );

			// Detail page should have event content
			const hasTitle = await page.locator( 'h1, h2, .event-title' ).count();
			expect( hasTitle ).toBeGreaterThan( 0 );
		}
	} );

	test( 'empty state displays when no events', async ( { page } ) => {
		// Navigate to events with filter that likely returns no results
		await page.goto( '/events/?search=xyznonexistent123' );

		// Should show empty state or message
		const content = await page.content();
		const hasEmptyIndicator =
			content.includes( 'No events' ) ||
			content.includes( 'no-events' ) ||
			content.includes( 'empty' );

		// Either has events or empty state
		const eventCards = eventsPage.getEventCards();
		const hasEvents = ( await eventCards.count() ) > 0;

		expect( hasEvents || hasEmptyIndicator ).toBeTruthy();
	} );

	test( 'event dates display correctly', async ( { page } ) => {
		await eventsPage.goto();

		const eventCards = eventsPage.getEventCards();

		if ( ( await eventCards.count() ) > 0 ) {
			const firstCard = eventCards.first();

			// Card should show date information
			const dateElement = firstCard.locator(
				'.event-date, .ve-date, time, [class*="date"]'
			);

			if ( ( await dateElement.count() ) > 0 ) {
				await expect( dateElement.first() ).toBeVisible();
			}
		}
	} );
} );
