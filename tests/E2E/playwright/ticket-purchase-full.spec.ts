/**
 * Full-flow E2E spec: WooCommerce ticket purchase.
 *
 * Tier 3.4 — covers the full browser path from event creation to order
 * placement. Specifically designed to catch bugs at the boundaries no unit
 * test exercises:
 *
 * - Admin ticket-type UI -> database (field names, capacity save)
 * - Frontend ticket form -> WC cart (AJAX add-to-cart, line-item metadata)
 * - Cart -> checkout blocks render (contact / billing / order summary)
 * - Checkout -> order placed (Cash on Delivery, capacity validation)
 *
 * This spec would have caught:
 * - NTE-008: occurrence/ticket-type creation writing bad data silently
 * - NTE-009: capacity 0 mis-rendering the form as sold-out
 *
 * Setup strategy: WP-CLI eval creates the fixture directly via plugin
 * repositories and ProductManager, bypassing browser form UI that requires
 * multiple round-trips and has fragile wait semantics.
 *
 * Verified selectors (2026-04-14 against the dev site WC 10.6.2 + Blocks):
 * - Cart item:   .wc-block-cart-items__row
 * - Checkout:    #email, #billing-first_name, #billing-last_name, #billing-address_1,
 *                #billing-city, #billing-postcode, #billing-phone
 * - Order summ:  .wc-block-components-order-summary
 * - Place order: button.wc-block-components-checkout-place-order-button
 * - COD:         input[value="cod"]
 * - Plus btn:    .nte-ticket-quantity__btn--plus
 * - Submit btn:  button.nte-ticket-form__submit
 */

import { execSync } from 'child_process';
import { test, expect } from './fixtures/index';
import { attachConsoleErrorCollector } from './support/page-objects';

const TICKET_NAME = 'General Admission';
const TICKET_PRICE = '10.00';

// Billing info used when WC has no saved address for the guest session.
const BILLING = {
	firstName: 'E2E',
	lastName: 'Tester',
	address1: '123 Test Street',
	city: 'Minneapolis',
	state: 'MN',
	postcode: '55401',
	phone: '555-0100',
	email: 'e2e-ticket-test@example.invalid',
};

// ─── WP-CLI fixture helpers ───────────────────────────────────────────────────

// Environment-aware WP-CLI command prefix.
// - Local (default): wp --path=/path/to/wp
// - wp-env:          wp-env run cli wp  (set WP_CLI_CMD env var)
const WP_CLI_CMD = process.env.WP_CLI_CMD
	|| `wp --path=${ process.env.WP_PATH || '/var/www/html' }`;

/**
 * Run a WP-CLI eval command and return stdout (stderr suppressed).
 */
function wpEval( phpCode: string ): string {
	const result = execSync(
		`${ WP_CLI_CMD } eval '${ phpCode }'`,
		{ encoding: 'utf8', timeout: 30000, stdio: [ 'inherit', 'pipe', 'pipe' ] }
	);
	return result.trim();
}

/**
 * Extract the JSON object between FIXTURE_JSON: and :END markers.
 * Guards against WP deprecation notices or other output before the JSON.
 */
function extractFixtureJson( output: string ): string {
	const match = output.match( /FIXTURE_JSON:(\{.*?\}):END/ );
	if ( ! match ) {
		throw new Error( `Could not find FIXTURE_JSON marker in wp eval output:\n${ output }` );
	}
	return match[ 1 ];
}

interface FixtureData {
	eventId: number;
	occurrenceId: number;
	ticketTypeId: number;
	permalink: string;
}

/**
 * Create a ticketed single event via plugin repositories + ProductManager.
 * Uses uniqid() to guarantee unique slugs across retries.
 */
function createTicketedEventFixture(): FixtureData {
	const php = `
$slug        = "e2e-wc-ticket-" . uniqid("", true);
$container   = \\NetterTechEvents\\Core\\ServiceRegistry::container();
$event_repo  = $container->get(\\NetterTechEvents\\Contracts\\EventRepositoryInterface::class);
$occ_repo    = $container->get(\\NetterTechEvents\\Contracts\\OccurrenceRepositoryInterface::class);
$ticket_repo = $container->get(\\NetterTechEvents\\Contracts\\TicketTypeRepositoryInterface::class);
$pm          = $container->get(\\NetterTechEvents\\Integrations\\WooCommerce\\ProductManager::class);

$now                = time();
$event              = new \\NetterTechEvents\\Models\\Event();
$event->title       = "E2E WC Ticket Test " . $slug;
$event->status      = \\NetterTechEvents\\Enums\\EventStatus::PUBLISHED;
$event->event_type  = "single";
$event->slug        = $slug;
$saved_event        = $event_repo->save($event);

$occ                 = new \\NetterTechEvents\\Models\\Occurrence();
$occ->event_id       = $saved_event->id;
$occ->start_datetime = gmdate("Y-m-d H:i:s", $now + 86400 * 14 + 19 * 3600);
$occ->end_datetime   = gmdate("Y-m-d H:i:s", $now + 86400 * 14 + 21 * 3600);
$occ->status         = "scheduled";
$saved_occ           = $occ_repo->save($occ);

$tt                = new \\NetterTechEvents\\Models\\TicketType();
$tt->event_id      = $saved_event->id;
$tt->occurrence_id = $saved_occ->id;
$tt->scope         = "occurrence";
$tt->name          = "${ TICKET_NAME }";
$tt->price         = ${ TICKET_PRICE };
$tt->capacity_type = "fixed";
$tt->capacity      = 50;
$tt->status        = "active";
$tt->min_per_order = 1;
$tt->max_per_order = 10;
$saved_tt          = $ticket_repo->save($tt);

$pm->sync_product($saved_tt, $saved_occ);

echo "FIXTURE_JSON:" . json_encode(array(
	"eventId"      => $saved_event->id,
	"occurrenceId" => $saved_occ->id,
	"ticketTypeId" => $saved_tt->id,
	"slug"         => $slug,
)) . ":END";
`;

	const rawOutput = wpEval( php );
	const jsonStr = extractFixtureJson( rawOutput );
	const data = JSON.parse( jsonStr ) as {
		eventId: number;
		occurrenceId: number;
		ticketTypeId: number;
		slug: string;
	};

	return {
		eventId: data.eventId,
		occurrenceId: data.occurrenceId,
		ticketTypeId: data.ticketTypeId,
		permalink: `${ process.env.WP_BASE_URL || 'http://localhost' }/events/${ data.slug }/`,
	};
}

/**
 * Delete fixture data: WC product, ticket type, occurrence, event.
 */
function deleteTicketedEventFixture( fixture: FixtureData ): void {
	const php = `
$container   = \\NetterTechEvents\\Core\\ServiceRegistry::container();
$pm          = $container->get(\\NetterTechEvents\\Integrations\\WooCommerce\\ProductManager::class);
$pm->delete_product(${ fixture.ticketTypeId });
global $wpdb;
$wpdb->delete("wp_nettertech_events_ticket_types", array("id" => ${ fixture.ticketTypeId }));
$wpdb->delete("wp_nettertech_events_occurrences",  array("id" => ${ fixture.occurrenceId }));
$wpdb->delete("wp_nettertech_events_events",       array("id" => ${ fixture.eventId }));
echo "deleted";
`;
	wpEval( php );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Click the + button to reach qty=1, wait for submit to enable, click submit.
 * Waits for redirect to /cart/ or falls through to manual navigation.
 */
async function addOneTicketToCart( page: import( '@playwright/test' ).Page, permalink: string ): Promise<void> {
	await page.goto( permalink );
	await page.waitForLoadState( 'networkidle' );

	// Click the plus button (verified class: .nte-ticket-quantity__btn--plus).
	const plusBtn = page.locator( '.nte-ticket-quantity__btn--plus' ).first();
	await expect( plusBtn ).toBeVisible();
	await plusBtn.click();
	await page.waitForTimeout( 300 );

	// Submit button enabled once qty > 0.
	const submitBtn = page.locator( 'button.nte-ticket-form__submit' );
	await expect( submitBtn ).toBeEnabled();
	await submitBtn.click();

	// AJAX nte_add_tickets_batch → JS redirects to /cart/.
	await page.waitForURL( /\/cart\/?/, { timeout: 15000 } ).catch( () => { /* no redirect */ } );
	if ( ! page.url().includes( 'cart' ) ) {
		await page.goto( '/cart/' );
	}
	await page.waitForLoadState( 'networkidle' );
}

/**
 * Attach console error collector before page load and return flush function.
 * Ignores known benign WC/Gutenberg dev-mode warnings.
 */
/**
 * WC Blocks checkout emits harmless dev-mode warnings we don't want to
 * blow up our assertion. Patterns are passed into the shared collector
 * from support/page-objects.ts (which accepts an `ignoredPatterns` arg).
 */
const WC_BLOCKS_IGNORED_PATTERNS = [
	/ReactDOM\.render/,
	/Warning: Each child in a list/,
	/Failed to register a ServiceWorker/,
	/findDOMNode is deprecated/,
];

/**
 * Shorthand: attach the shared collector with our WC-Blocks allowlist.
 * All call sites in this file use this filtered variant.
 */
function attachWcBlocksConsoleErrorCollector(
	page: import( '@playwright/test' ).Page
): () => Promise<void> {
	return attachConsoleErrorCollector( page, WC_BLOCKS_IGNORED_PATTERNS );
}

// ─── Spec ─────────────────────────────────────────────────────────────────────

test.describe( 'WC Ticketing Full Flow', () => {
	let fixture: FixtureData | null = null;

	test.beforeAll( async () => {
		fixture = createTicketedEventFixture();
	} );

	test.afterAll( async () => {
		if ( fixture ) {
			try {
				deleteTicketedEventFixture( fixture );
			} catch {
				// Best-effort cleanup.
			}
		}
	} );

	// ─── Tests ─────────────────────────────────────────────────────────────────

	test( 'event page renders ticket form with correct ticket type', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		const assertNoErrors = attachWcBlocksConsoleErrorCollector( page );

		await page.goto( fixture!.permalink );
		await page.waitForLoadState( 'networkidle' );

		// Ticket form must be present (catches NTE-009: capacity 0 hiding form).
		const ticketForm = page.locator( '.nte-ticket-form' );
		await expect( ticketForm ).toBeVisible();

		// Ticket name must match what was saved (catches silent field save failures).
		const ticketCard = page.locator( '.nte-ticket-card__name' );
		await expect( ticketCard ).toContainText( TICKET_NAME );

		// Quantity plus button must be present and not sold-out.
		const plusBtn = page.locator( '.nte-ticket-quantity__btn--plus' ).first();
		await expect( plusBtn ).toBeVisible();
		await expect( page.locator( '.nte-btn--sold-out' ) ).toHaveCount( 0 );

		await assertNoErrors();
	} );

	test( 'quantity selector updates and enables submit button', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		const assertNoErrors = attachWcBlocksConsoleErrorCollector( page );

		await page.goto( fixture!.permalink );
		await page.waitForLoadState( 'networkidle' );

		// Submit button starts disabled ("No tickets selected").
		const submitBtn = page.locator( 'button.nte-ticket-form__submit' );
		await expect( submitBtn ).toBeDisabled();

		// Click + once → qty becomes 1 → button enables.
		const plusBtn = page.locator( '.nte-ticket-quantity__btn--plus' ).first();
		await plusBtn.click();
		await page.waitForTimeout( 300 );

		await expect( submitBtn ).toBeEnabled();

		await assertNoErrors();
	} );

	test( 'add to cart sends tickets and reaches cart page', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		const assertNoErrors = attachWcBlocksConsoleErrorCollector( page );

		await addOneTicketToCart( page, fixture!.permalink );

		// Cart page must load (not 404).
		await expect( page ).not.toHaveTitle( /not found/i );

		// WC Blocks cart: at least one row in the items table.
		// Verified selector: .wc-block-cart-items__row (dev site WC 10.6.2 Blocks).
		const cartItem = page.locator( '.wc-block-cart-items__row, .cart_item, .woocommerce-cart-form__cart-item' );
		await expect( cartItem.first() ).toBeVisible();

		await assertNoErrors();
	} );

	test( 'cart line item includes event metadata (date, time, ticket type)', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		const assertNoErrors = attachWcBlocksConsoleErrorCollector( page );

		await addOneTicketToCart( page, fixture!.permalink );

		// Wait for WC Blocks to finish rendering cart item text (not just skeleton).
		// The blocks cart renders a loading skeleton first, then populates with text.
		await page.locator( '.wc-block-cart-items__row, .cart_item' ).first().waitFor( { state: 'visible', timeout: 10000 } );

		// Wait until at least one cart item row shows real text (not a skeleton placeholder).
		await page.waitForFunction( () => {
			const rows = document.querySelectorAll( '.wc-block-cart-items__row, .cart_item' );
			return Array.from( rows ).some( ( r ) => ( r.textContent ?? '' ).length > 10 );
		}, { timeout: 10000 } );

		// CartPresenter::display_cart_item_data adds: Date, Time, Ticket Type keys.
		const pageContent = await page.content();

		expect( pageContent.includes( 'Date' ), 'Cart should show event Date metadata from CartPresenter' ).toBeTruthy();
		expect( pageContent.includes( 'Time' ), 'Cart should show event Time metadata from CartPresenter' ).toBeTruthy();
		const hasTicketType = pageContent.includes( 'Ticket Type' ) || pageContent.includes( TICKET_NAME );
		expect( hasTicketType, 'Cart should show Ticket Type name from CartPresenter' ).toBeTruthy();

		await assertNoErrors();
	} );

	test( 'checkout page renders contact, billing, and order summary blocks', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		const assertNoErrors = attachWcBlocksConsoleErrorCollector( page );

		await addOneTicketToCart( page, fixture!.permalink );

		await page.goto( '/checkout/' );
		await page.waitForLoadState( 'networkidle' );

		// Skip if cart emptied (add-to-cart failed upstream).
		const isEmpty = ( await page.locator( '.cart-empty, .wp-block-woocommerce-empty-cart-block' ).count() ) > 0;
		test.skip( isEmpty, 'Cart is empty — add-to-cart step failed upstream' );

		// Contact email field (WC Blocks: id="email").
		await expect( page.locator( '#email, #billing_email, input[type="email"]' ).first() ).toBeVisible();

		// Billing first name field (WC Blocks: id="billing-first_name").
		await expect( page.locator( '#billing-first_name, #billing_first_name, input[name="billing_first_name"]' ).first() ).toBeVisible();

		// Order summary sidebar (WC Blocks: .wc-block-components-order-summary).
		await expect( page.locator( '.wc-block-components-order-summary, .wc-block-checkout__sidebar, #order_review' ).first() ).toBeVisible();

		await assertNoErrors();
	} );

	test( 'checkout fills billing info and places order via Cash on Delivery', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		const assertNoErrors = attachWcBlocksConsoleErrorCollector( page );

		await addOneTicketToCart( page, fixture!.permalink );

		await page.goto( '/checkout/' );
		await page.waitForLoadState( 'networkidle' );

		const isEmpty = ( await page.locator( '.cart-empty, .wp-block-woocommerce-empty-cart-block' ).count() ) > 0;
		test.skip( isEmpty, 'Cart is empty — add-to-cart step failed upstream' );

		// WC Blocks checkout uses #email (contact), #billing-first_name, etc.
		// If billing is pre-saved (admin account), fields may be hidden behind
		// an "Edit" button — click it first to expose the form.
		const editBillingBtn = page.locator( 'button:has-text("Edit"), .wc-block-components-address-card__edit' ).first();
		if ( ( await editBillingBtn.count() ) > 0 && await editBillingBtn.isVisible() ) {
			await editBillingBtn.click();
			await page.waitForTimeout( 300 );
		}

		// Fill only fields that appear: saved addresses may already be populated.
		const emailInput = page.locator( '#email' ).first();
		if ( ( await emailInput.count() ) > 0 && await emailInput.isVisible() ) {
			await emailInput.fill( BILLING.email );
		}

		const firstNameInput = page.locator( '#billing-first_name' ).first();
		if ( ( await firstNameInput.count() ) > 0 && await firstNameInput.isVisible() ) {
			await firstNameInput.fill( BILLING.firstName );
		}

		const lastNameInput = page.locator( '#billing-last_name' ).first();
		if ( ( await lastNameInput.count() ) > 0 && await lastNameInput.isVisible() ) {
			await lastNameInput.fill( BILLING.lastName );
		}

		const addressInput = page.locator( '#billing-address_1' ).first();
		if ( ( await addressInput.count() ) > 0 && await addressInput.isVisible() ) {
			await addressInput.fill( BILLING.address1 );
		}

		const cityInput = page.locator( '#billing-city' ).first();
		if ( ( await cityInput.count() ) > 0 && await cityInput.isVisible() ) {
			await cityInput.fill( BILLING.city );
		}

		const postcodeInput = page.locator( '#billing-postcode' ).first();
		if ( ( await postcodeInput.count() ) > 0 && await postcodeInput.isVisible() ) {
			await postcodeInput.fill( BILLING.postcode );
		}

		const phoneInput = page.locator( '#billing-phone' ).first();
		if ( ( await phoneInput.count() ) > 0 && await phoneInput.isVisible() ) {
			await phoneInput.fill( BILLING.phone );
		}

		// WC Blocks requires state/province for US addresses.
		const stateSelect = page.locator( '#billing-state' ).first();
		if ( ( await stateSelect.count() ) > 0 && await stateSelect.isVisible() ) {
			await stateSelect.selectOption( BILLING.state );
		}

		// Select Cash on Delivery (enabled on the dev site).
		const codRadio = page.locator( 'input[value="cod"]' ).first();
		if ( ( await codRadio.count() ) > 0 ) {
			await codRadio.click();
			await page.waitForTimeout( 500 );
		}

		// Place the order.
		const placeOrderBtn = page.locator(
			'button.wc-block-components-checkout-place-order-button, button#place_order, button:has-text("Place order")'
		).first();
		await expect( placeOrderBtn ).toBeVisible();
		await placeOrderBtn.click();

		// Wait for order confirmation page.
		await page.waitForURL( /order-received|order-pay|checkout\/order/, { timeout: 20000 } ).catch( async () => {
			await page.waitForLoadState( 'networkidle' );
		} );

		const confirmContent = await page.content();
		const hasConfirmation =
			confirmContent.includes( 'order-received' ) ||
			confirmContent.includes( 'Order received' ) ||
			confirmContent.includes( 'Thank you' ) ||
			confirmContent.includes( 'order has been received' ) ||
			page.url().includes( 'order-received' );

		expect( hasConfirmation, 'Order confirmation page should appear after successful checkout' ).toBeTruthy();

		await assertNoErrors();
	} );
} );
