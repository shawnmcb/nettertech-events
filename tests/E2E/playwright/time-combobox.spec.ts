/**
 * E2E tests for the suggest-and-type time combobox (NTE-190).
 *
 * Covers Story 1 acceptance: 15-minute suggestions with filtering, free
 * typing of off-increment times, duration-annotated end times, inline
 * (non-alert) validation, and keyboard-only operation.
 */

import { test, expect } from './fixtures';
import { loginAsAdmin } from './support/page-objects';

const NEW_EVENT_URL = '/wp-admin/admin.php?page=nettertech-events-new';

test.describe( 'Schedule box time combobox', () => {
	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( NEW_EVENT_URL );
		// The combobox upgrade runs on DOMContentLoaded (deferred script);
		// interacting before it lands races the lazy focusin upgrade path.
		await page.waitForSelector( '.nte-time-combobox__input' );
	} );

	test( 'upgrades all time fields and shows the timezone hint', async ( { page } ) => {
		// Schedule start/end + the always-rendered Add-a-date row 0 pair.
		await expect( page.locator( '.nte-time-combobox__input' ) ).toHaveCount( 4 );
		await expect( page.locator( '.nte-timezone-hint' ) ).toContainText( 'Times are in' );
	} );

	test( 'offers 15-minute suggestions that filter as you type', async ( { page } ) => {
		const start = page.locator( '.nte-time-combobox__input' ).first();
		await start.click();
		await start.press( 'ArrowDown' );

		const listbox = page.locator( '#nte-tc-1-listbox' );
		await expect( listbox ).toBeVisible();
		await expect( listbox.locator( '[role="option"]' ) ).toHaveCount( 96 );

		await start.fill( '7:1' );
		const visible = listbox.locator( '[role="option"]' );
		await expect( visible.first() ).toContainText( '7:15' );
	} );

	test( 'accepts an off-increment typed time without error', async ( { page } ) => {
		const start = page.locator( '.nte-time-combobox__input' ).first();
		await start.fill( '7:05 pm' );
		await start.press( 'Tab' );

		await expect( start ).toHaveValue( '7:05 pm' );
		await expect( start ).not.toHaveAttribute( 'aria-invalid', 'true' );
		await expect( page.locator( 'input[name="start_time"]' ) ).toHaveValue( '19:05' );
	} );

	test( 'normalizes 24-hour entry to the display format', async ( { page } ) => {
		const start = page.locator( '.nte-time-combobox__input' ).first();
		await start.fill( '19:00' );
		await start.press( 'Tab' );
		await expect( page.locator( 'input[name="start_time"]' ) ).toHaveValue( '19:00' );
	} );

	test( 'annotates end-time suggestions with duration', async ( { page } ) => {
		const combos = page.locator( '.nte-time-combobox__input' );
		await combos.first().fill( '7:00 pm' );
		await combos.first().press( 'Tab' );

		const end = combos.nth( 1 );
		await end.click();
		await end.press( 'ArrowDown' );
		const options = page.locator( '.nte-time-combobox__listbox' ).nth( 1 ).locator( '[role="option"]' );
		await expect( options.first() ).toContainText( '(15 mins)' );
	} );

	test( 'keyboard-only selection works (ArrowDown, Enter, Escape)', async ( { page } ) => {
		const start = page.locator( '.nte-time-combobox__input' ).first();
		await start.focus();
		await start.press( 'ArrowDown' );
		await start.press( 'ArrowDown' );
		await start.press( 'Enter' );
		await expect( page.locator( '#nte-tc-1-listbox' ) ).toBeHidden();
		await expect( page.locator( 'input[name="start_time"]' ) ).not.toHaveValue( '' );

		await start.press( 'ArrowDown' );
		await expect( page.locator( '#nte-tc-1-listbox' ) ).toBeVisible();
		await start.press( 'Escape' );
		await expect( page.locator( '#nte-tc-1-listbox' ) ).toBeHidden();
	} );

	test( 'invalid duration reports inline, announced, without alert()', async ( { page } ) => {
		let alertFired = false;
		page.on( 'dialog', async ( dialog ) => {
			alertFired = true;
			await dialog.dismiss();
		} );

		await page.fill( 'input[name="event_title"]', `NTE-190 e2e ${ Date.now() }` );
		await page.fill( '#start_date', '2026-09-01' );

		// Expand end-time fields when collapsed.
		const toggle = page.locator( '#nte-end-time-toggle' );
		const fields = page.locator( '#nte-end-time-fields' );
		if ( await fields.isHidden() ) {
			await toggle.click();
		}
		await page.fill( '#end_date', '2026-09-01' );

		const combos = page.locator( '.nte-time-combobox__input' );
		await combos.first().fill( '7:00 pm' );
		await combos.first().press( 'Tab' );
		await combos.nth( 1 ).fill( '6:00 pm' );
		await combos.nth( 1 ).press( 'Tab' );

		await page.locator( '#nettertech-events-editor input[type="submit"], #nettertech-events-editor button[type="submit"]' ).first().click();

		await expect( page.locator( '.nte-field-error' ) ).toContainText( 'at least 10 minutes' );
		await expect( page.locator( '.nte-datetime-live-region' ) ).toContainText( 'at least 10 minutes' );
		expect( alertFired ).toBe( false );
	} );

	test( 'cloned Add-a-date row time fields upgrade on focus', async ( { page } ) => {
		// Open the Add-a-date disclosure and add a second row from the template.
		await page.locator( '.nte-add-date__summary' ).click();
		await page.locator( '#nte-add-date-add-row' ).click();

		const rows = page.locator( '[data-nte-manual-date-row]' );
		await expect( rows ).toHaveCount( 2 );

		const clonedStart = rows.nth( 1 ).locator( 'input[type="time"]' ).first();
		await clonedStart.focus();

		// Lazy focusin upgrade: the native input is replaced by a combobox display.
		const clonedCombo = rows.nth( 1 ).locator( '.nte-time-combobox__input' ).first();
		await expect( clonedCombo ).toBeVisible();
		await clonedCombo.fill( '6:05 pm' );
		await clonedCombo.press( 'Tab' );
		await expect(
			rows.nth( 1 ).locator( 'input[type="time"]' ).first()
		).toHaveValue( '18:05' );

		// No duplicate combobox ids across rows.
		const ids = await page.$$eval(
			'.nte-time-combobox__listbox',
			( els ) => els.map( ( el ) => el.id )
		);
		expect( new Set( ids ).size ).toBe( ids.length );
	} );

	test( 'unparseable time flags the field inline', async ( { page } ) => {
		const start = page.locator( '.nte-time-combobox__input' ).first();
		await start.fill( 'banana' );
		await start.press( 'Tab' );
		await expect( start ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( page.locator( 'input[name="start_time"]' ) ).toHaveValue( '' );
	} );
} );

test.describe( 'Ticket sale windows', () => {
	test( 'boundaries render as grouped date + time with working presets', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( NEW_EVENT_URL );
		await page.waitForSelector( '.nte-time-combobox__input' );

		// Enable ticketing to reveal the ticket form.
		// Styled toggle: the checkbox itself is visually hidden — drive it via
		// its label and force-fallback.
		const ticketingToggle = page.locator( '#nte-ticketing-enabled' );
		if ( ! ( await ticketingToggle.isChecked() ) ) {
			// The checkbox is fully hidden behind a styled toggle — click the label.
			await page.locator( 'label.nte-toggle-label' ).first().click();
			await expect( ticketingToggle ).toBeChecked();
		}

		const windows = page.locator( '.nte-sale-window' );
		await expect( windows.first() ).toBeVisible();
		await expect( page.locator( 'input[type="datetime-local"]' ) ).toHaveCount( 0 );
		await expect( windows.first().locator( 'legend' ) ).toContainText( 'Sale starts' );

		// "Now" preset fills date + time, visibly and editably.
		await windows.first().locator( '[data-nte-sale-preset="now"]' ).click();
		await expect( windows.first().locator( 'input[type="date"]' ) ).not.toHaveValue( '' );
		await expect( windows.first().locator( 'input[type="time"]' ) ).not.toHaveValue( '' );

		// "At event start" copies the schedule fields.
		await page.fill( '#start_date', '2026-09-01' );
		const startCombo = page.locator( '.nte-time-combobox__input' ).first();
		await startCombo.fill( '7:00 pm' );
		await startCombo.press( 'Tab' );

		const endWindow = windows.nth( 1 );
		await endWindow.locator( '[data-nte-sale-preset="event-start"]' ).click();
		await expect( endWindow.locator( 'input[type="date"]' ) ).toHaveValue( '2026-09-01' );
		await expect( endWindow.locator( 'input[type="time"]' ) ).toHaveValue( '19:00' );
	} );
} );

test.describe( 'JS-off degradation', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'native date and time inputs remain fully usable without scripts', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( NEW_EVENT_URL );

		// No upgrade happened; the raw native controls are present and enabled.
		await expect( page.locator( '.nte-time-combobox__input' ) ).toHaveCount( 0 );
		const startTime = page.locator( 'input[name="start_time"]' );
		await expect( startTime ).toBeVisible();
		await startTime.fill( '19:05' );
		await expect( startTime ).toHaveValue( '19:05' );
		await expect( page.locator( '#start_date' ) ).toBeVisible();
	} );
} );

test.describe( 'Occurrence editor time combobox', () => {
	test( 'occurrence editor fields upgrade with duration annotation and timezone hint', async ( { page } ) => {
		await loginAsAdmin( page );
		// Fixture occurrence 11 (oz shared-capacity fixture). Read-only: never save.
		await page.goto( '/wp-admin/admin.php?page=nettertech-events-edit-occurrence&occurrence_id=11' );

		const startNative = page.locator( '#nte-occurrence-start-time' );
		if ( 0 === await startNative.count() ) {
			test.skip( true, 'Fixture occurrence 11 not present on this site' );
			return;
		}

		// At least the schedule pair; ticket sale windows add more (NTE-190 D).
		await expect( page.locator( '.nte-time-combobox__input' ).first() ).toBeVisible();
		await expect(
			page.locator( '#nte-occurrence-start-time' ).locator( 'xpath=ancestor::span[contains(@class,"nte-time-combobox")]' )
		).toHaveCount( 1 );
		await expect( page.locator( '.nte-timezone-hint' ).first() ).toContainText( 'Times are in' );

		const end = page
			.locator( '#nte-occurrence-end-time' )
			.locator( 'xpath=ancestor::span[contains(@class,"nte-time-combobox")]' )
			.locator( '.nte-time-combobox__input' );
		await end.click();
		await end.press( 'ArrowDown' );
		await expect(
			page
				.locator( '#nte-occurrence-end-time' )
				.locator( 'xpath=ancestor::span[contains(@class,"nte-time-combobox")]' )
				.locator( '.nte-time-combobox__listbox [role="option"]' )
				.first()
		).toContainText( 'mins' );
		await end.press( 'Escape' );
	} );
} );
