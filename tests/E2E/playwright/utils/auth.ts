/**
 * Authentication utilities for E2E tests.
 */

import { Page } from '@playwright/test';

/**
 * WordPress admin credentials.
 * In production, these should come from environment variables.
 */
const ADMIN_USERNAME = process.env.WP_ADMIN_USERNAME || 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD || 'password';

/**
 * Log in to WordPress as admin.
 *
 * @param page - Playwright page object.
 */
export async function loginAsAdmin( page: Page ): Promise<void> {
	await page.goto( '/wp-login.php' );

	// Check if already logged in (redirected to wp-admin)
	if ( page.url().includes( 'wp-admin' ) ) {
		return; // Already logged in
	}

	// Wait for login form - with timeout to handle redirect race conditions
	const loginForm = page.locator( '#user_login' );

	try {
		await loginForm.waitFor( { state: 'visible', timeout: 5000 } );
	} catch {
		// If login form doesn't appear, check if we're already logged in
		if ( page.url().includes( 'wp-admin' ) ) {
			return;
		}
		throw new Error( 'Login form not found and not logged in' );
	}

	// Fill login form
	await page.locator( '#user_login' ).fill( ADMIN_USERNAME );
	await page.locator( '#user_pass' ).fill( ADMIN_PASSWORD );

	// Click submit and wait for navigation
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/, { timeout: 15000 } );
}

/**
 * Log out from WordPress.
 *
 * @param page - Playwright page object.
 */
export async function logout( page: Page ): Promise<void> {
	// Click on admin menu to get logout link
	await page.goto( '/wp-admin/' );
	await page.locator( '#wp-admin-bar-logout a' ).click();

	// Wait for logout confirmation
	await page.waitForURL( /loggedout=true/ );
}

/**
 * Check if currently logged in as admin.
 *
 * @param page - Playwright page object.
 * @returns True if logged in.
 */
export async function isLoggedIn( page: Page ): Promise<boolean> {
	await page.goto( '/wp-admin/' );
	return ! page.url().includes( 'wp-login.php' );
}
