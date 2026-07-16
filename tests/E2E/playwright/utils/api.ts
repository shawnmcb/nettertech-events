/**
 * API utilities for E2E tests.
 */

import { APIRequestContext } from '@playwright/test';

/**
 * Delete an event via REST API.
 *
 * @param request - Playwright API request context.
 * @param eventId - Event ID to delete.
 */
export async function deleteEvent(
	request: APIRequestContext,
	eventId: number
): Promise<void> {
	await request.delete( `/wp-json/nettertech-events/v1/events/${ eventId }`, {
		headers: {
			'X-WP-Nonce': 'test',
		},
	} );
}

/**
 * Create an event via REST API.
 *
 * @param request - Playwright API request context.
 * @param eventData - Event data.
 * @returns Created event ID.
 */
export async function createEvent(
	request: APIRequestContext,
	eventData: Record<string, unknown>
): Promise<number> {
	const response = await request.post( '/wp-json/nettertech-events/v1/events', {
		data: eventData,
		headers: {
			'X-WP-Nonce': 'test',
		},
	} );

	const data = await response.json();
	return data.id;
}
