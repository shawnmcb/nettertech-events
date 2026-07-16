/**
 * E2E regression spec for NTE-017: Weekly Regulars three-bug chain.
 *
 * Bug 1: LIST_COLUMNS missing recurrence_rule — shortcode always showed empty state.
 * Bug 2: PageContextDetector missing 'nettertech_events_regulars' — base.css never enqueued.
 * Bug 3: Click target was only the title text — time/venue cells were dead.
 *
 * Each assertion is individually named so failures pinpoint the exact bug.
 */

import { test, expect, BrowserContext } from '@playwright/test';
import {
	loginAsAdmin,
	createEvent,
	createPageWithShortcode,
	attachConsoleErrorCollector,
	assertBaseCssLoaded,
} from './support/page-objects';
import { getFutureDate, formatDateForInput } from './support/helpers';

// Run sequentially — parallel login on the same WP site causes auth collisions.
test.describe.configure( { mode: 'serial' } );

test.describe( 'NTE-017: Weekly Regulars shortcode', () => {
	let regularsPagePermalink = '';
	let eventPermalink = '';
	let guestContext: BrowserContext;

	test.beforeAll( async ( { browser } ) => {
		// Admin setup: create the recurring event and a page with [nettertech_events_regulars].
		// browser.newPage() does not inherit baseURL; create a full context instead.
		const baseURL = process.env.WP_BASE_URL || 'http://localhost';
		const adminContext = await browser.newContext( { baseURL, ignoreHTTPSErrors: true } );
		const adminPage = await adminContext.newPage();

		await loginAsAdmin( adminPage );

		// Next Monday relative to today (ensures a future weekday).
		const futureMonday = getFutureDate( 7 );
		const dayOfWeek = futureMonday.getDay();
		const daysUntilMonday = dayOfWeek === 0 ? 1 : 8 - dayOfWeek;
		const startDate = getFutureDate( daysUntilMonday );

		const eventResult = await createEvent( adminPage, {
			title: 'E2E Regulars Event',
			startDate: formatDateForInput( startDate ),
			startTime: '19:00',
			recurring: true,
			occurrences: 10,
		} );

		eventPermalink = eventResult.permalink;

		const pageResult = await createPageWithShortcode(
			adminPage,
			'[nettertech_events_regulars]',
			'Weekly Regulars E2E Test Page'
		);

		regularsPagePermalink = pageResult.permalink;

		await adminContext.close();

		// Guest context: unauthenticated browser session.
		guestContext = await browser.newContext( { baseURL, ignoreHTTPSErrors: true } );
	} );

	test.afterAll( async () => {
		await guestContext.close();
	} );

	test( 'setup: regulars page was created with a valid permalink', async () => {
		expect( regularsPagePermalink.length, 'Regulars page permalink must not be empty' ).toBeGreaterThan( 0 );
	} );

	test( 'NTE-017 bug 1: page body contains event title (recurrence_rule in SELECT)', async () => {
		const page = await guestContext.newPage();
		const flush = attachConsoleErrorCollector( page );

		await page.goto( regularsPagePermalink );
		await page.waitForLoadState( 'domcontentloaded' );

		await expect(
			page.locator( 'body' ),
			'Body must contain "E2E Regulars Event" — absence means recurrence_rule was missing from LIST_COLUMNS and every row was skipped'
		).toContainText( 'E2E Regulars Event' );

		await flush();
		await page.close();
	} );

	test( 'NTE-017 bug 2: base.css is enqueued on a regulars-only page (PageContextDetector)', async () => {
		const page = await guestContext.newPage();
		const flush = attachConsoleErrorCollector( page );

		await page.goto( regularsPagePermalink );
		await page.waitForLoadState( 'domcontentloaded' );

		await assertBaseCssLoaded( page );

		await flush();
		await page.close();
	} );

	test( 'NTE-017 bug 3a: .nte-regulars__row establishes a containing block (stretched-link target)', async () => {
		const page = await guestContext.newPage();
		const flush = attachConsoleErrorCollector( page );

		await page.goto( regularsPagePermalink );
		// 'load' (not 'domcontentloaded'): WebKit returns CSS defaults from
		// getComputedStyle before external stylesheets finish loading (NTE-053).
		await page.waitForLoadState( 'load' );

		const containingBlockState = await page.evaluate( () => {
			const row = document.querySelector( '.nte-regulars__row' );
			if ( ! row ) {
				return null;
			}
			const styles = getComputedStyle( row );
			return {
				position: styles.position,
				transform: styles.transform,
				contain: styles.contain,
			};
		} );

		expect(
			containingBlockState,
			'.nte-regulars__row must exist on the page'
		).not.toBeNull();

		// A `<tr>` becomes a containing block if any of the following hold:
		//   - position is non-static (relative/absolute/fixed/sticky), OR
		//   - transform is not `none`, OR
		//   - contain includes `layout` or `paint`.
		// Chromium and Firefox respect `position: relative` on <tr>; WebKit
		// historically computes `position: static` on <tr> regardless of the
		// stylesheet (bugs.webkit.org/15397). The plugin CSS therefore also
		// applies `transform: translate(0)` so the containing block is
		// established on every engine. The behavioral check (the stretched
		// link covers the row and is hit by elementFromPoint) lives in bugs
		// 3c and 3d — this test only verifies the structural precondition.
		const isContainingBlock =
			containingBlockState!.position !== 'static' ||
			containingBlockState!.transform !== 'none' ||
			/\b(layout|paint|strict|content)\b/.test( containingBlockState!.contain );

		expect(
			isContainingBlock,
			`.nte-regulars__row must establish a containing block so the ::after pseudo-element is contained within the row. Computed: position=${ containingBlockState!.position }, transform=${ containingBlockState!.transform }, contain=${ containingBlockState!.contain }`
		).toBe( true );

		await flush();
		await page.close();
	} );

	test( 'NTE-017 bug 3b: .nte-regulars__link::after has content and position: absolute (stretched pseudo)', async () => {
		const page = await guestContext.newPage();
		const flush = attachConsoleErrorCollector( page );

		await page.goto( regularsPagePermalink );
		// 'load': ::after computed styles need the stylesheet applied (NTE-053).
		await page.waitForLoadState( 'load' );

		const pseudoStyles = await page.evaluate( () => {
			const link = document.querySelector( '.nte-regulars__link' );
			if ( ! link ) {
				return null;
			}
			const styles = getComputedStyle( link, '::after' );
			return {
				content: styles.content,
				position: styles.position,
			};
		} );

		expect(
			pseudoStyles,
			'.nte-regulars__link must exist on the page'
		).not.toBeNull();

		expect(
			pseudoStyles!.content,
			'.nte-regulars__link::after must have content set (stretched-link pseudo-element not applied)'
		).toBe( '""' );

		expect(
			pseudoStyles!.position,
			'.nte-regulars__link::after must have position: absolute to fill the row'
		).toBe( 'absolute' );

		await flush();
		await page.close();
	} );

	test( 'NTE-017 bug 3c: elementFromPoint at time cell center returns the link, not the <td>', async () => {
		const page = await guestContext.newPage();
		const flush = attachConsoleErrorCollector( page );

		await page.goto( regularsPagePermalink );
		// 'load': elementFromPoint hit-testing needs the stretched-link CSS
		// positioning applied, which requires the stylesheet loaded (NTE-053).
		await page.waitForLoadState( 'load' );

		const hitTestResult = await page.evaluate( () => {
			// Use the second <td> in the first row as the "time cell" proxy.
			// The exact column order is: day, time, title — any non-title cell will do.
			const firstRow = document.querySelector( '.nte-regulars__row' );
			if ( ! firstRow ) {
				return { error: 'No .nte-regulars__row found', tagName: null, className: null };
			}

			const cells = firstRow.querySelectorAll( 'td' );
			// Time cell: second <td> (index 1). Fall back to first if only one exists.
			const targetCell = cells[ 1 ] ?? cells[ 0 ];
			if ( ! targetCell ) {
				return { error: 'No <td> found in first row', tagName: null, className: null };
			}

			// Scroll the cell into view so elementFromPoint() can hit it. Without this,
			// the bounding-rect center can land below the viewport (especially under
			// shorter wp-env-default viewport heights) and elementFromPoint() returns null.
			targetCell.scrollIntoView( { block: 'center', inline: 'center' } );

			const rect = targetCell.getBoundingClientRect();
			const centerX = rect.left + rect.width / 2;
			const centerY = rect.top + rect.height / 2;

			const hitElement = document.elementFromPoint( centerX, centerY );
			if ( ! hitElement ) {
				return {
					error: `elementFromPoint returned null at (${ centerX }, ${ centerY }); viewport ${ window.innerWidth }x${ window.innerHeight }; rect ${ JSON.stringify( rect ) }`,
					tagName: null,
					className: null,
				};
			}

			return {
				error: null,
				tagName: hitElement.tagName.toLowerCase(),
				className: hitElement.className,
			};
		} );

		expect(
			hitTestResult.error,
			`Hit-test precondition failed: ${ hitTestResult.error }`
		).toBeNull();

		expect(
			hitTestResult.tagName,
			'elementFromPoint at the time cell center must return an <a> — if it returns <td> the stretched-link ::after is not covering the row'
		).toBe( 'a' );

		expect(
			hitTestResult.className,
			'The <a> hit by elementFromPoint must be .nte-regulars__link'
		).toContain( 'nte-regulars__link' );

		await flush();
		await page.close();
	} );

	test( 'NTE-017 bug 3d: clicking the time cell navigates to the event permalink', async () => {
		const page = await guestContext.newPage();
		const flush = attachConsoleErrorCollector( page );

		await page.goto( regularsPagePermalink );
		// 'load': the time-cell click relies on the stretched-link ::after being
		// positioned by CSS; without the stylesheet the click hits the <td> (NTE-053).
		await page.waitForLoadState( 'load' );

		// Get the href from the link before clicking, so we know what to expect.
		const linkHref = await page.evaluate( () => {
			const link = document.querySelector<HTMLAnchorElement>( '.nte-regulars__link' );
			return link ? link.href : null;
		} );

		expect(
			linkHref,
			'.nte-regulars__link must have an href attribute'
		).not.toBeNull();

		// Click the second <td> in the first row (time cell) — NOT the title link directly.
		const timeCellClicked = await page.evaluate( () => {
			const firstRow = document.querySelector( '.nte-regulars__row' );
			if ( ! firstRow ) {
				return false;
			}
			const cells = firstRow.querySelectorAll( 'td' );
			const targetCell = cells[ 1 ] ?? cells[ 0 ];
			if ( ! targetCell ) {
				return false;
			}
			// Scroll the cell into view so elementFromPoint() can hit it
			// (the bounding-rect center can otherwise land below the viewport
			// on default wp-env viewport heights).
			targetCell.scrollIntoView( { block: 'center', inline: 'center' } );
			const rect = targetCell.getBoundingClientRect();
			const centerX = rect.left + rect.width / 2;
			const centerY = rect.top + rect.height / 2;
			const hitElement = document.elementFromPoint( centerX, centerY );
			if ( hitElement && hitElement instanceof HTMLElement ) {
				hitElement.click();
				return true;
			}
			return false;
		} );

		expect(
			timeCellClicked,
			'Could not programmatically click the time cell center element'
		).toBe( true );

		// Wait for navigation away from the regulars page.
		// The programmatic click triggers async navigation; waitForURL ensures the
		// address bar reflects the new page before we assert.
		await page.waitForURL( /\/events\//, { timeout: 15000 } );

		const currentUrl = page.url();
		expect(
			currentUrl,
			`After clicking the time cell, page URL must be under /events/. Got: ${ currentUrl }`
		).toContain( '/events/' );

		await flush();
		await page.close();
	} );

	test( 'no console errors across the full regulars page lifecycle', async () => {
		const page = await guestContext.newPage();
		const flush = attachConsoleErrorCollector( page );

		await page.goto( regularsPagePermalink );
		await page.waitForLoadState( 'networkidle' );

		await flush();
		await page.close();
	} );
} );
