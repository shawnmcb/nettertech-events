/**
 * Tier 3.2 — Shortcode enqueue matrix spec.
 *
 * For every registered NTE shortcode, this spec:
 *   1. Creates a WP page containing ONLY that shortcode.
 *   2. Navigates to it as an anonymous visitor.
 *   3. Asserts base.css is enqueued (link[href*="base.css"] present in DOM).
 *   4. Asserts no console errors.
 *
 * This is a pure PageContextDetector contract test at the browser level. It
 * catches shortcode-map drift — the bug class from NTE-017 bug #2 — for every
 * shortcode, not just nte_regulars. If a shortcode is missing from the detector's
 * hardcoded map, base.css will not be enqueued and the test fails precisely on
 * that shortcode.
 *
 * Catches: NTE-017 bug #2 class (PageContextDetector shortcode-map drift).
 *
 * Architecture note: each test creates its own admin context + page in a
 * self-contained beforeEach. An earlier version used a shared beforeAll loop
 * to create all 7 pages up front, but that was flaky: WordPress admin cookies
 * drifted across iterations, and any single iteration failure marked every
 * subsequent test as failed rather than isolating the problem. Per-test
 * isolation costs ~2s per case but produces deterministic results.
 */

import { test, expect, type Page } from '@playwright/test';
import {
	loginAsAdmin,
	createPageWithShortcode,
	assertBaseCssLoaded,
	attachConsoleErrorCollector,
} from './support/page-objects';

// ─── Shortcode matrix ─────────────────────────────────────────────────────────

const NTE_SHORTCODES = [
	'nettertech_events_list',
	'nettertech_events_calendar',
	'nettertech_events_carousel',
	'nettertech_events_grid',
	'nettertech_events_regulars',
	'nettertech_events_rsvp',
	'nettertech_events',
] as const;

type NteShortcode = ( typeof NTE_SHORTCODES )[ number ];

// ─── Suite ────────────────────────────────────────────────────────────────────

test.describe( 'Shortcode enqueue matrix', () => {

	NTE_SHORTCODES.forEach( ( shortcode: NteShortcode ) => {
		test( `[${ shortcode }] enqueues base.css for anonymous visitor`, async ( { browser } ) => {
			// Each test gets its own admin context + anonymous context so
			// session state can't leak between shortcodes.
			const baseURL = process.env.WP_BASE_URL || 'http://localhost';
			const adminContext = await browser.newContext( { baseURL, ignoreHTTPSErrors: true } );
			const adminPage: Page = await adminContext.newPage();

			let permalink: string;
			try {
				await loginAsAdmin( adminPage );
				const result = await createPageWithShortcode(
					adminPage,
					`[${ shortcode }]`,
					`E2E Enqueue Test — ${ shortcode } — ${ Date.now() }`
				);
				permalink = result.permalink;
				expect( permalink, `createPageWithShortcode returned empty permalink for [${ shortcode }]` ).toBeTruthy();
			} finally {
				await adminContext.close();
			}

			// Separate anonymous context for the actual assertion — mirrors
			// a real visitor's first load (no admin cookies, no stale state).
			const guestContext = await browser.newContext( { baseURL, ignoreHTTPSErrors: true } );
			const guestPage = await guestContext.newPage();
			try {
				const assertNoConsoleErrors = attachConsoleErrorCollector( guestPage );

				await guestPage.goto( permalink, { waitUntil: 'domcontentloaded' } );

				// Primary assertion: PageContextDetector must recognise this shortcode
				// and trigger base.css enqueue via Assets::maybe_enqueue_frontend().
				await assertBaseCssLoaded( guestPage );

				// Secondary assertion: no JS errors during page load.
				await assertNoConsoleErrors();
			} finally {
				await guestContext.close();
			}
		} );
	} );

} );
