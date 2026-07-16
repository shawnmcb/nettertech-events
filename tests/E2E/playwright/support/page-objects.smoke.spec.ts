/**
 * Smoke tests for the shared page-object helpers in page-objects.ts.
 *
 * Each test exercises one exported helper in isolation to verify the helper
 * itself runs without error.  This spec intentionally does not duplicate
 * coverage already provided by the 11 existing spec files.
 */

import { test, expect } from '@playwright/test';
import {
	loginAsAdmin,
	createEvent,
	createPageWithShortcode,
	openRegularsShortcodePage,
	assertBaseCssLoaded,
	assertNoConsoleErrors,
	attachConsoleErrorCollector,
	clickAndAssertNavigate,
} from './page-objects';
import { getFutureDate, formatDateForInput } from './helpers';

// Run sequentially — parallel login on the same WP site causes auth collisions.
test.describe.configure( { mode: 'serial' } );

test.describe( 'page-objects helpers smoke tests', () => {

	// ── loginAsAdmin ──────────────────────────────────────────────────────────

	test( 'loginAsAdmin: logs in successfully with default credentials', async ( { page } ) => {
		await loginAsAdmin( page );
		await expect( page ).toHaveURL( /wp-admin/ );
		await expect( page.locator( '#adminmenu' ) ).toBeVisible();
	} );

	test( 'loginAsAdmin: accepts explicit username and password', async ( { page } ) => {
		const user = process.env.WP_ADMIN_USERNAME || 'admin';
		const pass = process.env.WP_ADMIN_PASSWORD || 'password';
		await loginAsAdmin( page, user, pass );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	// ── createEvent ───────────────────────────────────────────────────────────

	test( 'createEvent: creates a single event and returns numeric ID', async ( { page } ) => {
		await loginAsAdmin( page );
		const futureDate = getFutureDate( 14 );
		const result = await createEvent( page, {
			title: 'PO Smoke Test Single Event',
			startDate: formatDateForInput( futureDate ),
			startTime: '10:00',
		} );
		expect( result.eventId ).toBeGreaterThan( 0 );
	} );

	test( 'createEvent: creates a weekly recurring event', async ( { page } ) => {
		await loginAsAdmin( page );
		const futureDate = getFutureDate( 7 );
		const result = await createEvent( page, {
			title: 'PO Smoke Test Weekly Event',
			startDate: formatDateForInput( futureDate ),
			startTime: '10:00',
			recurring: true,
			occurrences: 4,
		} );
		expect( result.eventId ).toBeGreaterThan( 0 );
	} );

	// ── createPageWithShortcode ───────────────────────────────────────────────

	test( 'createPageWithShortcode: creates page and returns page ID', async ( { page } ) => {
		await loginAsAdmin( page );
		const result = await createPageWithShortcode(
			page,
			'[nettertech_events_list]',
			'PO Smoke: List Shortcode'
		);
		expect( result.pageId ).toBeGreaterThan( 0 );
	} );

	// ── openRegularsShortcodePage ─────────────────────────────────────────────

	test( 'openRegularsShortcodePage: returns a non-empty permalink', async ( { page } ) => {
		await loginAsAdmin( page );
		const permalink = await openRegularsShortcodePage( page );
		expect( typeof permalink ).toBe( 'string' );
		expect( permalink.length ).toBeGreaterThan( 0 );
	} );

	// ── assertBaseCssLoaded ───────────────────────────────────────────────────

	test( 'assertBaseCssLoaded: passes on an NTE events page', async ( { page } ) => {
		await page.goto( '/events/' );
		await page.waitForLoadState( 'domcontentloaded' );
		// The events archive enqueues base.css via PageContextDetector
		await assertBaseCssLoaded( page );
	} );

	// ── assertNoConsoleErrors / attachConsoleErrorCollector ───────────────────

	test( 'assertNoConsoleErrors: does not throw on a clean page', async ( { page } ) => {
		await page.goto( '/' );
		await page.waitForLoadState( 'domcontentloaded' );
		// Should not throw; homepage should have no console errors
		await assertNoConsoleErrors( page );
	} );

	test( 'attachConsoleErrorCollector: collector starts before navigation', async ( { page } ) => {
		const flush = attachConsoleErrorCollector( page );
		await page.goto( '/' );
		await page.waitForLoadState( 'domcontentloaded' );
		// Should not throw; homepage is expected to be error-free
		await flush();
	} );

	// ── clickAndAssertNavigate ────────────────────────────────────────────────

	test( 'clickAndAssertNavigate: follows a link and asserts URL', async ( { page } ) => {
		// Use wp-admin dashboard — guaranteed to have navigation links on any
		// environment (events archive may be empty on fresh wp-env installs and
		// the theme may lack a homepage link, causing a click timeout).
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/' );
		await page.waitForLoadState( 'domcontentloaded' );

		// Click the "Dashboard" menu link and assert we stay on an admin URL.
		await clickAndAssertNavigate(
			page,
			'#menu-dashboard a.menu-top',
			/\/wp-admin\//
		);
	} );

} );
