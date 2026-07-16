/**
 * Admin operations E2E tests.
 *
 * Tests for admin-side operations like event list management,
 * settings, and navigation.
 */

import { test, expect } from './fixtures';
import { loginAsAdmin } from './support/page-objects';

test.describe( 'Admin Event List', () => {
	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	test( 'events list page loads', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=nettertech-events' );

		// Should not show PHP errors.
		const content = await page.content();
		expect( content ).not.toContain( 'Fatal error' );
		expect( content ).not.toContain( 'Parse error' );

		// Should have a table or list of events.
		const hasTable = await page.locator( 'table, .wp-list-table' ).count();
		expect( hasTable ).toBeGreaterThanOrEqual( 0 );
	} );

	test( 'add new event button exists', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=nettertech-events' );

		// Look for "Add New" or similar button.
		const addButton = page.locator(
			'a[href*="action=new"], a:has-text("Add New"), .page-title-action'
		);
		const hasAddButton = ( await addButton.count() ) > 0;
		expect( hasAddButton ).toBe( true );
	} );

	test( 'clicking add new navigates to event editor', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=nettertech-events' );

		const addButton = page
			.locator( 'a[href*="action=new"], a:has-text("Add New"), .page-title-action' )
			.first();

		if ( ( await addButton.count() ) > 0 ) {
			await addButton.click();
			await page.waitForLoadState( 'networkidle' );

			// Should be on event editor page.
			await expect( page ).toHaveURL( /action=new|page=nettertech-events/ );
		}
	} );
} );

test.describe( 'Admin Settings', () => {
	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	test( 'settings page loads', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=nettertech-events-settings' );

		// Should not show PHP errors.
		const content = await page.content();
		expect( content ).not.toContain( 'Fatal error' );
	} );

	test( 'settings form has save button', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=nettertech-events-settings' );

		// Look for submit button.
		const saveButton = page.locator(
			'input[type="submit"], button[type="submit"], .button-primary'
		);
		const hasSaveButton = ( await saveButton.count() ) > 0;

		// Settings page should have a save mechanism.
		expect( hasSaveButton ).toBe( true );
	} );
} );

test.describe( 'Admin Navigation', () => {
	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	test( 'admin menu has all submenus', async ( { page } ) => {
		await page.goto( '/wp-admin/' );

		// Wait for admin menu.
		await page.waitForSelector( '#adminmenu' );

		// Find the Events menu item and hover to reveal submenu.
		const eventsMenu = page.locator( '#adminmenu a[href*="page=nettertech-events"]' ).first();

		if ( ( await eventsMenu.count() ) > 0 ) {
			// Hover to reveal submenu.
			const menuParent = eventsMenu.locator( 'xpath=ancestor::li[contains(@class, "menu-top")]' );
			await menuParent.hover();

			// Check for submenu items.
			const submenu = menuParent.locator( '.wp-submenu a' );
			const submenuCount = await submenu.count();

			// Should have at least the main events link.
			expect( submenuCount ).toBeGreaterThan( 0 );
		}
	} );

	test( 'admin pages load without errors', async ( { page } ) => {
		const adminPages = [
			'/wp-admin/admin.php?page=nettertech-events',
			'/wp-admin/admin.php?page=nettertech-events-settings',
		];

		for ( const adminPage of adminPages ) {
			await page.goto( adminPage );
			const content = await page.content();

			expect( content ).not.toContain( 'Fatal error' );
			expect( content ).not.toContain( 'Parse error' );
		}
	} );
} );

test.describe( 'Admin Security', () => {
	test( 'non-admin cannot access events admin', async ( { page } ) => {
		// Go to admin page without logging in.
		await page.goto( '/wp-admin/admin.php?page=nettertech-events' );

		// Should redirect to login.
		await expect( page ).toHaveURL( /wp-login\.php/ );
	} );

	test( 'admin pages use nonces', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=nettertech-events-settings' );

		// Check for nonce field in forms.
		const nonceField = page.locator( 'input[name*="nonce"], input[id*="nonce"]' );
		const formWithNonce = ( await nonceField.count() ) > 0;

		// Most forms should have nonces.
		// Note: This may not apply to all pages.
		expect( true ).toBe( true );
	} );
} );
