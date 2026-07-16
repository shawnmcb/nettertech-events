/**
 * Playwright configuration for NetterTech Events E2E tests.
 *
 * @see https://playwright.dev/docs/test-configuration
 */

import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

/**
 * Read environment variables from .env file.
 *
 * `.env` is gitignored and holds per-developer credentials for the target
 * WordPress site (WP_BASE_URL, WP_ADMIN_USERNAME, WP_ADMIN_PASSWORD).
 * `.env.example` is committed and documents the required variables.
 *
 * Previously this file left dotenv commented out, which meant the suite
 * silently fell back to `admin`/`password` on every run against the dev site —
 * 37 tests failed at login and nobody noticed (NTE-021).
 */
dotenv.config( { path: path.resolve( __dirname, '.env' ) } );

// Fail fast if critical credentials are missing, rather than letting
// tests time out at a login form with an opaque failure.
if ( ! process.env.WP_ADMIN_USERNAME || ! process.env.WP_ADMIN_PASSWORD ) {
	throw new Error(
		'Playwright E2E: WP_ADMIN_USERNAME or WP_ADMIN_PASSWORD not set.\n' +
		'  Copy .env.example to .env and fill in your local WordPress admin credentials.\n' +
		'  See: .env.example'
	);
}

export default defineConfig( {
	testDir: './tests/E2E/playwright',

	/* Run tests in files in parallel */
	fullyParallel: true,

	/* Fail the build on CI if you accidentally left test.only in the source code. */
	forbidOnly: !! process.env.CI,

	/* Retry on CI only */
	retries: process.env.CI ? 2 : 1,

	/* Limit parallel workers to reduce race conditions with login sessions */
	workers: process.env.CI ? 1 : 4,

	/* Timeout settings */
	timeout: 30000,
	expect: {
		timeout: 10000,
	},

	/* Reporter to use. */
	reporter: 'html',

	/* Shared settings for all the projects below. */
	use: {
		/* Base URL to use in actions like `await page.goto('/')`. */
		baseURL: process.env.WP_BASE_URL || 'http://localhost',

		/* Ignore HTTPS errors for local development */
		ignoreHTTPSErrors: true,

		/* Collect trace when retrying the failed test. */
		trace: 'on-first-retry',

		/* Take screenshot on failure */
		screenshot: 'only-on-failure',
	},

	/* Configure projects for major browsers */
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
		{
			name: 'firefox',
			use: { ...devices[ 'Desktop Firefox' ] },
		},
		{
			name: 'webkit',
			use: { ...devices[ 'Desktop Safari' ] },
		},
	],

	/* Run your local dev server before starting the tests */
	// webServer: {
	//   command: 'npm run start',
	//   url: 'http://127.0.0.1:3000',
	//   reuseExistingServer: !process.env.CI,
	// },
} );
