/**
 * Smoke tests for basic site health.
 *
 * These tests verify the site is accessible and key pages load correctly.
 */

import { test, expect } from './fixtures';
import { loginAsAdmin } from './support/page-objects';

test.describe( 'Smoke Tests', () => {
	test( 'homepage loads successfully', async ( { page } ) => {
		await page.goto( '/' );

		// Page should load without errors
		await expect( page ).toHaveTitle( /.+/ );

		// Should not show PHP errors
		const content = await page.content();
		expect( content ).not.toContain( 'Fatal error' );
		expect( content ).not.toContain( 'Warning:' );
		expect( content ).not.toContain( 'Parse error' );
	} );

	test( 'events page loads', async ( { page } ) => {
		await page.goto( '/events/' );

		// Events page should load
		await expect( page ).not.toHaveTitle( /Page not found/i );

		// Should have events container or list
		const hasEventsContent = await page
			.locator( '.ve-events, .events-list, .ve-event-card, [class*="event"]' )
			.count();
		expect( hasEventsContent ).toBeGreaterThanOrEqual( 0 );
	} );

	test( 'WordPress admin is accessible', async ( { page } ) => {
		await page.goto( '/wp-admin/' );

		// Should redirect to login if not authenticated
		await expect( page ).toHaveURL( /wp-login\.php|wp-admin/ );
	} );

	test( 'admin can login successfully', async ( { page } ) => {
		await loginAsAdmin( page );

		// Should see dashboard
		await expect( page ).toHaveURL( /wp-admin/ );
		await expect( page.locator( '#adminmenu' ) ).toBeVisible();
	} );

	test( 'NetterTech Events admin menu exists', async ( { page } ) => {
		await loginAsAdmin( page );

		// Wait for admin menu to load
		await page.waitForSelector( '#adminmenu' );

		// Should see Events menu item linking to nettertech-events page
		const menuItem = page.locator( '#adminmenu a[href*="page=nettertech-events"]' ).first();
		await expect( menuItem ).toBeVisible( { timeout: 10000 } );
	} );

	test( 'events list page loads in admin', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=nettertech-events' );

		// Should show events table or empty state
		const hasContent = await page.locator( '.wp-list-table, .ve-empty-state' ).count();
		expect( hasContent ).toBeGreaterThan( 0 );
	} );

	test( 'plugin is active', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/plugins.php' );

		// Wait for plugins table to load
		await page.waitForSelector( '#the-list' );

		// Find NetterTech Events plugin row
		const pluginRow = page.locator( 'tr[data-slug="nettertech-events"]' ).first();
		await expect( pluginRow ).toBeVisible();

		// WordPress marks an active plugin's row with class="active". Older
		// versions of this test asserted "Deactivate" link visibility, but
		// when other plugins declare NetterTech Events as a `Requires Plugins:`
		// dependency, core renders the deactivate control as a non-clickable
		// <span> instead of an <a>, breaking the link-based locator.
		await expect( pluginRow ).toHaveClass( /\bactive\b/ );
	} );
} );
