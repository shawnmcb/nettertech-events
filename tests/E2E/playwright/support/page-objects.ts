/**
 * Shared page-object helpers for Tier 3 E2E specs.
 *
 * Wraps existing utilities (utils/auth.ts, pages/index.ts) with higher-level
 * helper functions used across multiple spec files.
 */

import { Page, expect } from '@playwright/test';
import { EventAdminPage } from '../pages';

// ─── Types ────────────────────────────────────────────────────────────────────

export interface CreateEventOptions {
	title: string;
	startDate: string;
	startTime: string;
	recurring?: boolean;
	occurrences?: number;
}

export interface CreateEventResult {
	eventId: number;
	permalink: string;
}

export interface CreatePageResult {
	pageId: number;
	permalink: string;
}

// ─── Auth ─────────────────────────────────────────────────────────────────────

/**
 * Log in to WordPress as admin.
 *
 * Idempotent: returns immediately if the session is already authenticated.
 * Robust against WebKit/.local cookie-handoff timing quirks — waits for
 * page load state before checking the URL, and waits for the form
 * container (#loginform) rather than a single child field.
 *
 * Falls back to local dev-site credentials when env vars are absent.
 */
export async function loginAsAdmin(
	page: Page,
	username?: string,
	password?: string
): Promise<void> {
	const user = username ?? ( process.env.WP_ADMIN_USERNAME || 'admin' );
	const pass = password ?? ( process.env.WP_ADMIN_PASSWORD || 'password' );

	await page.goto( '/wp-login.php' );
	// Wait for load state so the URL check sees the final destination, not
	// a transient redirect. WordPress redirects authenticated sessions to
	// wp-admin, so this is the short-circuit.
	await page.waitForLoadState( 'domcontentloaded' );
	if ( page.url().includes( 'wp-admin' ) ) {
		return;
	}

	// Wait for the form container (not just the username field) — some
	// WebKit variants render #user_login briefly before the form is
	// actually interactive.
	const loginForm = page.locator( '#loginform' );
	try {
		await loginForm.waitFor( { state: 'visible', timeout: 10000 } );
	} catch {
		if ( page.url().includes( 'wp-admin' ) ) {
			return;
		}
		throw new Error( 'Login form not found and not already logged in' );
	}

	await page.locator( '#user_login' ).fill( user );
	await page.locator( '#user_pass' ).fill( pass );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/, { timeout: 30000 } );
}

// ─── Event creation ──────────────────────────────────────────────────────────

/**
 * Create an event via the admin UI and return its ID and permalink.
 *
 * For recurring events, sets FREQ=WEEKLY with the given number of occurrences
 * (default 10) via the recurrence fields already established in
 * event-creation.spec.ts.
 */
export async function createEvent(
	page: Page,
	opts: CreateEventOptions
): Promise<CreateEventResult> {
	// Ensure an authenticated session before navigating to the event editor.
	// When multiple browser projects run in parallel their workers each need an
	// independent session.  Calling loginAsAdmin here means each createEvent
	// invocation is self-contained with respect to auth.
	await loginAsAdmin( page );
	const eventAdmin = new EventAdminPage( page );
	await eventAdmin.gotoNew();

	await eventAdmin.fillForm( {
		title: opts.title,
		status: 'published',
		eventType: opts.recurring ? 'recurring' : 'single',
		startDate: opts.startDate,
		startTime: opts.startTime,
	} );

	if ( opts.recurring ) {
		// Use the preset dropdown — value is the raw RRULE string (e.g. 'FREQ=WEEKLY').
		const presetSelect = page.locator( '#recurrence_preset, [name="recurrence_preset"]' );
		if ( ( await presetSelect.count() ) > 0 ) {
			await presetSelect.selectOption( 'FREQ=WEEKLY' );
		}

		// Select the "count" end-type radio so the occurrence count field is active.
		const countRadio = page.locator( 'input[name="recurrence_end_type"][value="count"]' );
		if ( ( await countRadio.count() ) > 0 ) {
			await countRadio.check();
		}

		const countInput = page.locator( '#recurrence_count, [name="recurrence_count"]' );
		const occurrenceCount = opts.occurrences ?? 10;
		if ( ( await countInput.count() ) > 0 ) {
			await countInput.fill( String( occurrenceCount ) );
		}
	}

	const eventId = await eventAdmin.submit();

	let permalink = '';
	if ( eventId > 0 ) {
		const viewLink = page.locator( 'a:has-text("View Event"), a.row-title' ).first();
		if ( ( await viewLink.count() ) > 0 ) {
			permalink = ( await viewLink.getAttribute( 'href' ) ) ?? '';
		}
		if ( ! permalink ) {
			permalink = `/events/${ opts.title.toLowerCase().replace( /\s+/g, '-' ) }/`;
		}
	}

	return { eventId, permalink };
}

// ─── Page/shortcode helpers ───────────────────────────────────────────────────

/**
 * Create a WordPress page containing the given shortcode and return its ID and
 * permalink.
 *
 * Uses the WP REST API (wp/v2/pages) to avoid Gutenberg editor UI complexity.
 * The caller must already be authenticated (loginAsAdmin called first).
 */
export async function createPageWithShortcode(
	page: Page,
	shortcode: string,
	title = 'E2E Shortcode Test Page'
): Promise<CreatePageResult> {
	// Pre-flight login: Local by Flywheel / WebKit / long-session cookie drift
	// can invalidate the admin session between iterations in a tight loop.
	// loginAsAdmin is idempotent — bails early if already on an admin page.
	await loginAsAdmin( page );

	// Navigate to any wp-admin page to obtain the REST nonce from wpApiSettings.
	// Retry once if the response redirects to the login screen (cookie drift).
	await page.goto( '/wp-admin/' );
	await page.waitForLoadState( 'domcontentloaded' );
	if ( page.url().includes( 'wp-login.php' ) ) {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/' );
		await page.waitForLoadState( 'domcontentloaded' );
	}

	const result = await page.evaluate(
		async ( args: { title: string; content: string } ) => {
			const settings = ( window as unknown as { wpApiSettings: { root: string; nonce: string } } ).wpApiSettings;
			if ( ! settings ) {
				return { error: 'wpApiSettings not found', pageId: 0, permalink: '' };
			}

			const response = await fetch( `${ settings.root }wp/v2/pages`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce,
				},
				body: JSON.stringify( {
					title: args.title,
					content: args.content,
					status: 'publish',
				} ),
			} );

			if ( ! response.ok ) {
				const text = await response.text();
				return { error: `HTTP ${ response.status }: ${ text }`, pageId: 0, permalink: '' };
			}

			const data = await response.json() as { id: number; link: string };
			return { error: null, pageId: data.id, permalink: data.link };
		},
		{
			title,
			content: `<!-- wp:shortcode -->\n${ shortcode }\n<!-- /wp:shortcode -->`,
		}
	);

	if ( result.error ) {
		throw new Error(
			`createPageWithShortcode(${ shortcode }): ${ result.error }`
		);
	}

	return { pageId: result.pageId, permalink: result.permalink };
}

// ─── Regulars shortcode helpers ───────────────────────────────────────────────

/**
 * Navigate to a page containing [nettertech_events_regulars] and return its permalink.
 *
 * Searches the site for an existing page with that shortcode, or creates one
 * if none exists.
 */
export async function openRegularsShortcodePage( page: Page ): Promise<string> {
	// First try to find an existing page with the shortcode
	await page.goto( '/wp-admin/edit.php?post_type=page&s=nte_regulars' );
	await page.waitForLoadState( 'domcontentloaded' );

	const existingRow = page.locator( '#the-list tr' ).first();
	if ( ( await existingRow.count() ) > 0 ) {
		const viewLink = existingRow.locator( 'a.row-actions a:has-text("View"), span.view a' );
		if ( ( await viewLink.count() ) > 0 ) {
			const href = await viewLink.getAttribute( 'href' );
			if ( href ) {
				await page.goto( href );
				return href;
			}
		}
	}

	// Create a new page with the shortcode
	const { permalink } = await createPageWithShortcode(
		page,
		'[nettertech_events_regulars]',
		'Weekly Regulars Test'
	);
	if ( permalink ) {
		await page.goto( permalink );
	}
	return permalink;
}

// ─── Assertions ───────────────────────────────────────────────────────────────

/**
 * Attach a console error collector to the page and assert no errors were
 * captured at call time.
 *
 * Call this function BEFORE page navigation to start collection.
 * The returned flush function asserts on whatever has been collected so far.
 *
 * `ignoredPatterns` is an optional list of RegExps; any console.error whose
 * text matches at least one pattern is dropped rather than recorded. Use
 * this to filter third-party dev-mode warnings (WC Blocks, React dev
 * warnings, Firefox NS_BINDING_ABORTED, etc.) that would otherwise drown
 * out real site errors.
 */
export function attachConsoleErrorCollector(
	page: Page,
	ignoredPatterns: RegExp[] = []
): () => Promise<void> {
	const errors: string[] = [];

	page.on( 'console', ( msg ) => {
		if ( msg.type() === 'error' ) {
			const text = msg.text();
			if ( ! ignoredPatterns.some( ( re ) => re.test( text ) ) ) {
				errors.push( text );
			}
		}
	} );

	return async function assertNoConsoleErrors(): Promise<void> {
		expect(
			errors,
			`Expected no console errors but got:\n${ errors.join( '\n' ) }`
		).toHaveLength( 0 );
	};
}

/**
 * Assert that at least one base.css stylesheet is loaded on the current page.
 *
 * This catches NTE-017 bug #2: pages with only [nettertech_events_regulars] had no base.css
 * because PageContextDetector didn't recognise that shortcode.
 */
export async function assertBaseCssLoaded( page: Page ): Promise<void> {
	const baseCssLinks = page.locator( 'link[rel="stylesheet"][href*="base.css"]' );
	await expect( baseCssLinks ).not.toHaveCount( 0 );
}

/**
 * Assert no console errors on the current page.
 *
 * Evaluates existing console messages from the already-loaded page; suitable
 * for a one-shot check after navigation.  For ongoing collection use
 * attachConsoleErrorCollector() before navigation instead.
 */
export async function assertNoConsoleErrors( page: Page ): Promise<void> {
	const errors: string[] = [];
	page.on( 'console', ( msg ) => {
		if ( msg.type() === 'error' ) {
			errors.push( msg.text() );
		}
	} );
	// Yield to allow any pending console events to fire
	await page.evaluate( () => new Promise( ( r ) => setTimeout( r, 100 ) ) );
	expect(
		errors,
		`Expected no console errors but got:\n${ errors.join( '\n' ) }`
	).toHaveLength( 0 );
}

// ─── Navigation ───────────────────────────────────────────────────────────────

/**
 * Click a selector and assert the resulting URL matches the expected pattern.
 */
export async function clickAndAssertNavigate(
	page: Page,
	selector: string,
	expectedUrlPattern: RegExp | string
): Promise<void> {
	await page.locator( selector ).first().click();
	await page.waitForLoadState( 'domcontentloaded' );
	await expect( page ).toHaveURL(
		typeof expectedUrlPattern === 'string'
			? new RegExp( expectedUrlPattern.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) )
			: expectedUrlPattern
	);
}
