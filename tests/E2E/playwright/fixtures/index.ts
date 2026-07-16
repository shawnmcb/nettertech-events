/**
 * Playwright test fixtures for NetterTech Events.
 *
 * Only test/expect live here. Utility functions (uniqueEventTitle, etc.)
 * are in support/helpers.ts — Playwright's fixture module loader drops
 * non-fixture exports during transpilation. See NTE-048.
 */

import { test as base, expect } from '@playwright/test';

/**
 * Extended test fixture with custom helpers.
 */
export const test = base.extend( {} );

export { expect };
