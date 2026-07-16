/**
 * Shared utility functions for E2E tests.
 *
 * Separated from fixtures/index.ts because Playwright's fixture module
 * loader drops non-fixture exports (test/expect) during transpilation.
 * See NTE-048.
 */

/**
 * Generate a unique event title for tests.
 *
 * @param prefix - Optional prefix for the title.
 * @returns Unique event title string.
 */
export function uniqueEventTitle( prefix = 'E2E Test Event' ): string {
	const timestamp = Date.now();
	const random = Math.random().toString( 36 ).substring( 7 );
	return `${ prefix } ${ timestamp }-${ random }`;
}

/**
 * Get a future date from now.
 *
 * @param daysFromNow - Number of days in the future.
 * @returns Date object.
 */
export function getFutureDate( daysFromNow = 7 ): Date {
	const date = new Date();
	date.setDate( date.getDate() + daysFromNow );
	return date;
}

/**
 * Format date for HTML date input (YYYY-MM-DD).
 *
 * @param date - Date to format.
 * @returns Formatted date string.
 */
export function formatDateForInput( date: Date ): string {
	const year = date.getFullYear();
	const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( date.getDate() ).padStart( 2, '0' );
	return `${ year }-${ month }-${ day }`;
}

/**
 * Format time for HTML time input (HH:MM).
 *
 * @param hours - Hour (0-23).
 * @param minutes - Minutes (0-59).
 * @returns Formatted time string.
 */
export function formatTimeForInput( hours: number, minutes = 0 ): string {
	return `${ String( hours ).padStart( 2, '0' ) }:${ String( minutes ).padStart( 2, '0' ) }`;
}
