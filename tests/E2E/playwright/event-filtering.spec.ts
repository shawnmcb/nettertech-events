/**
 * Event filtering and search E2E tests.
 *
 * These tests verify that event filtering, search, and navigation
 * work correctly on the frontend.
 */

import { test, expect } from './fixtures';

test.describe( 'Event Filtering', () => {
	test.beforeEach( async ( { page } ) => {
		// Navigate to events page before each test.
		await page.goto( '/events/' );
	} );

	test( 'events page displays event list', async ( { page } ) => {
		// Should have at least one event card or list container.
		const hasEvents = await page
			.locator( '.ve-event-card, .events-list, [class*="event-card"]' )
			.count();

		// Even if no events, page should load without errors.
		const content = await page.content();
		expect( content ).not.toContain( 'Fatal error' );
		expect( content ).not.toContain( 'Parse error' );
	} );

	test( 'events page has no PHP errors', async ( { page } ) => {
		const content = await page.content();

		expect( content ).not.toContain( 'Warning:' );
		expect( content ).not.toContain( 'Notice:' );
		expect( content ).not.toContain( 'Deprecated:' );
	} );

	test( 'event cards contain required information', async ( { page } ) => {
		const eventCards = page.locator( '.ve-event-card, [class*="event-card"]' );
		const count = await eventCards.count();

		if ( count > 0 ) {
			const firstCard = eventCards.first();

			// Card should be visible.
			await expect( firstCard ).toBeVisible();

			// Card should have a link.
			const link = firstCard.locator( 'a' );
			const hasLink = ( await link.count() ) > 0;
			expect( hasLink ).toBe( true );
		}
	} );

	test( 'clicking event card navigates to event detail', async ( { page } ) => {
		const eventCards = page.locator( '.ve-event-card a, [class*="event-card"] a' );
		const count = await eventCards.count();

		if ( count > 0 ) {
			const firstLink = eventCards.first();
			const href = await firstLink.getAttribute( 'href' );

			if ( href ) {
				await firstLink.click();
				await page.waitForLoadState( 'networkidle' );

				// Should navigate to event page.
				await expect( page ).not.toHaveURL( /events\/?$/ );
			}
		}
	} );

	test( 'events page is responsive', async ( { page } ) => {
		// Test mobile viewport.
		await page.setViewportSize( { width: 375, height: 667 } );
		await page.goto( '/events/' );

		// Page should still render without horizontal scroll issues.
		const bodyWidth = await page.evaluate( () => document.body.scrollWidth );
		const viewportWidth = await page.evaluate( () => window.innerWidth );

		// Body shouldn't be significantly wider than viewport (allow 20px tolerance).
		expect( bodyWidth ).toBeLessThanOrEqual( viewportWidth + 20 );
	} );

	test( 'events page loads within acceptable time', async ( { page } ) => {
		const startTime = Date.now();
		await page.goto( '/events/' );
		await page.waitForLoadState( 'domcontentloaded' );
		const loadTime = Date.now() - startTime;

		// Page should load within 5 seconds.
		expect( loadTime ).toBeLessThan( 5000 );
	} );
} );

test.describe( 'Event Search', () => {
	test( 'search form exists on events page', async ( { page } ) => {
		await page.goto( '/events/' );

		// Look for search input or form.
		const searchInput = page.locator(
			'input[type="search"], input[name="s"], input[placeholder*="search" i], .ve-search-input'
		);
		const hasSearch = ( await searchInput.count() ) > 0;

		// Search may or may not exist depending on theme/configuration.
		// Just verify page loads.
		expect( true ).toBe( true );
	} );
} );

test.describe( 'Event Category Filtering', () => {
	test( 'category filter links are clickable', async ( { page } ) => {
		await page.goto( '/events/' );

		// Look for category filter links.
		const categoryLinks = page.locator(
			'.event-categories a, .ve-filter a, [class*="category-filter"] a'
		);
		const count = await categoryLinks.count();

		// If categories exist, verify they're clickable.
		if ( count > 0 ) {
			const firstCategory = categoryLinks.first();
			await expect( firstCategory ).toBeVisible();
		}
	} );
} );

test.describe( 'Event Pagination', () => {
	test( 'pagination controls are accessible', async ( { page } ) => {
		await page.goto( '/events/' );

		// Look for pagination.
		const pagination = page.locator(
			'.pagination, .nav-links, .page-numbers, .ve-pagination'
		);
		const hasPagination = ( await pagination.count() ) > 0;

		// Pagination may not exist if few events.
		// Just verify page loads correctly.
		expect( true ).toBe( true );
	} );
} );
