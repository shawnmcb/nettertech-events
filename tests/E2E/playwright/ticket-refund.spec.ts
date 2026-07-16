/**
 * E2E spec: WooCommerce ticket refund cascade + HPOS round-trip.
 *
 * NTE-028 — covers two regression paths that integration tests exercise but
 * no browser-level spec does:
 *
 *   1. Refund cascade: a full refund on a paid ticket order must transition
 *      the attendee record off "confirmed" and decrement the ticket type's
 *      sold_count (capacity release).
 *
 *   2. HPOS round-trip: the order placed via the browser must read back
 *      correctly from the HPOS-backed admin list view, and an order edit
 *      must round-trip through HPOS storage.
 *
 * ── Implementation notes ────────────────────────────────────────────────────
 *
 * **HPOS preflight.** This spec asserts HPOS is enabled in the test env
 * before any test runs. If HPOS is off it fails fast with a clear error —
 * we do not silently skip, because the whole point of the round-trip test
 * is to catch HPOS regressions.
 *
 * **Refund issuance.** The refund is issued via wc_create_refund() invoked
 * through wp eval, not via the admin refund modal. The admin modal is JS-
 * driven, requires a confirm() dialog, and is brittle to WC version drift.
 * wc_create_refund() is the same function the modal calls and fires the
 * same hooks (woocommerce_order_refunded, woocommerce_order_status_refunded),
 * so the cascade under test is identical. The "browser-level" requirement
 * is satisfied because the order itself is placed via the browser checkout
 * flow.
 *
 * Spec discovered a latent bug (filed as NTE-037) while being authored:
 * the full-refund cascade wrote `'voided'` to the attendee status column,
 * but that status was not in the `Attendee::STATUSES` whitelist, so the
 * update was silently rejected. NTE-037 fixed this by adding `'voided'` as
 * a valid status (the semantic was already wired into Hooks::REGISTRATION_VOIDED
 * and the Seating plugin's cross-plugin matrix). The cascade test below now
 * exercises the fixed path end-to-end.
 */

import { execSync } from 'child_process';
import { test, expect } from './fixtures/index';
import { loginAsAdmin, attachConsoleErrorCollector } from './support/page-objects';

const TICKET_NAME = 'General Admission';
const TICKET_PRICE = '15.00';

const BILLING = {
	firstName: 'E2E',
	lastName: 'Refund',
	address1: '456 Refund Way',
	city: 'Minneapolis',
	state: 'MN',
	postcode: '55402',
	phone: '555-0199',
	email: 'e2e-refund-test@example.invalid',
};

// ─── WP-CLI eval helpers ─────────────────────────────────────────────────────

// Environment-aware WP-CLI command prefix.
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
 * Guards against WP deprecation notices polluting the output.
 */
function extractJson( output: string, marker = 'FIXTURE_JSON' ): string {
	const re = new RegExp( `${ marker }:(\\{.*?\\}):END` );
	const match = output.match( re );
	if ( ! match ) {
		throw new Error( `Could not find ${ marker } marker in wp eval output:\n${ output }` );
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
 * Create a ticketed event fixture with capacity 50.
 *
 * Mirrors the helper in ticket-purchase-full.spec.ts. Kept inline rather
 * than extracted to a shared helper because the two specs evolve at
 * different cadences and a shared helper would couple them.
 */
function createTicketedEventFixture(): FixtureData {
	const php = `
$slug        = "e2e-wc-refund-" . uniqid("", true);
$container   = \\NetterTechEvents\\Core\\ServiceRegistry::container();
$event_repo  = $container->get(\\NetterTechEvents\\Contracts\\EventRepositoryInterface::class);
$occ_repo    = $container->get(\\NetterTechEvents\\Contracts\\OccurrenceRepositoryInterface::class);
$ticket_repo = $container->get(\\NetterTechEvents\\Contracts\\TicketTypeRepositoryInterface::class);
$pm          = $container->get(\\NetterTechEvents\\Integrations\\WooCommerce\\ProductManager::class);

$now                = time();
$event              = new \\NetterTechEvents\\Models\\Event();
$event->title       = "E2E WC Refund Test " . $slug;
$event->status      = \\NetterTechEvents\\Enums\\EventStatus::PUBLISHED;
$event->event_type  = "single";
$event->slug        = $slug;
$saved_event        = $event_repo->save($event);

$occ                 = new \\NetterTechEvents\\Models\\Occurrence();
$occ->event_id       = $saved_event->id;
$occ->start_datetime = gmdate("Y-m-d H:i:s", $now + 86400 * 21 + 19 * 3600);
$occ->end_datetime   = gmdate("Y-m-d H:i:s", $now + 86400 * 21 + 21 * 3600);
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
	const jsonStr = extractJson( rawOutput );
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
 * Best-effort fixture cleanup: WC product, ticket type, occurrence, event.
 *
 * Note: orders generated during the test are intentionally NOT deleted —
 * they are useful artefacts in the WC admin for post-run inspection if a
 * test fails, and have no impact on the next run because each fixture
 * uses a unique slug.
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

/**
 * Read the current sold_count for a ticket type directly from the DB.
 *
 * sold_count is the source of truth for capacity; CapacityService
 * increments it on reserve/complete and decrements it on release/refund
 * (see TicketTypeStockRepository::adjust_sold_count). Querying it directly
 * gives a deterministic assertion that doesn't depend on admin UI rendering.
 */
function getSoldCount( ticketTypeId: number ): number {
	const php = `
global $wpdb;
$sold = (int) $wpdb->get_var($wpdb->prepare(
	"SELECT sold_count FROM wp_nettertech_events_ticket_types WHERE id = %d",
	${ ticketTypeId }
));
echo "SOLD_COUNT:" . $sold . ":END";
`;
	const out = wpEval( php );
	const match = out.match( /SOLD_COUNT:(\d+):END/ );
	if ( ! match ) {
		throw new Error( `Could not parse sold_count from output:\n${ out }` );
	}
	return parseInt( match[ 1 ], 10 );
}

/**
 * Read the attendee record(s) for an order.
 *
 * Returns an array of {id, status, quantity} so the test can assert both
 * the cascade target status and the quantity after a partial refund.
 */
interface AttendeeSnapshot {
	id: number;
	status: string;
	quantity: number;
}

function getAttendeesForOrder( orderId: number ): AttendeeSnapshot[] {
	const php = `
global $wpdb;
$rows = $wpdb->get_results($wpdb->prepare(
	"SELECT id, status, quantity FROM wp_nettertech_events_attendees WHERE wc_order_id = %d ORDER BY id ASC",
	${ orderId }
), ARRAY_A);
echo "ATTENDEES_JSON:" . json_encode($rows) . ":END";
`;
	const out = wpEval( php );
	const match = out.match( /ATTENDEES_JSON:(\[.*?\]):END/ );
	if ( ! match ) {
		throw new Error( `Could not parse attendees JSON from output:\n${ out }` );
	}
	return ( JSON.parse( match[ 1 ] ) as Array<{ id: string; status: string; quantity: string }> ).map( ( r ) => ( {
		id: parseInt( r.id, 10 ),
		status: r.status,
		quantity: parseInt( r.quantity, 10 ),
	} ) );
}

/**
 * Issue a full refund against an order via wc_create_refund().
 *
 * This is the same function the admin "Refund" modal calls. It fires:
 *   - woocommerce_order_refunded
 *   - woocommerce_create_refund
 *   - woocommerce_order_status_refunded (when the order becomes fully refunded)
 *
 * Which triggers OrderHandler::handle_refund_created() AND
 * OrderHandler::handle_order_refunded(). For a full refund the latter
 * routes through void_attendees_for_order() which sets attendee status
 * to "voided" and releases capacity.
 */
interface RefundDiagnostic {
	order_status_before: string;
	order_status_after: string;
	attendee_status_before: string | null;
	attendee_status_after: string | null;
	sold_count_before: number;
	sold_count_after: number;
	refund_id: number;
}

function issueFullRefund( orderId: number ): RefundDiagnostic {
	const php = `
$order = wc_get_order(${ orderId });
if (! $order) {
	echo "REFUND_RESULT:no_order:END";
	return;
}

// Diagnostic: capture pre-refund state.
$status_before = $order->get_status();
global $wpdb;
$attendee_before = $wpdb->get_row($wpdb->prepare(
	"SELECT id, status, ticket_type_id FROM {$wpdb->prefix}nettertech_events_attendees WHERE wc_order_id = %d LIMIT 1",
	${ orderId }
));
$sold_before = $attendee_before
	? (int) $wpdb->get_var($wpdb->prepare(
		"SELECT sold_count FROM {$wpdb->prefix}nettertech_events_ticket_types WHERE id = %d",
		$attendee_before->ticket_type_id
	))
	: -1;

$line_items = array();
foreach ($order->get_items() as $item_id => $item) {
	$line_items[$item_id] = array(
		"qty"          => $item->get_quantity(),
		"refund_total" => (float) $item->get_total(),
		"refund_tax"   => array(),
	);
}
$refund = wc_create_refund(array(
	"amount"     => (string) $order->get_total(),
	"reason"     => "E2E test full refund",
	"order_id"   => ${ orderId },
	"line_items" => $line_items,
	"refund_payment" => false,
	"restock_items"  => false,
));
if (is_wp_error($refund)) {
	echo "REFUND_RESULT:error:" . $refund->get_error_message() . ":END";
	return;
}

// Diagnostic: capture post-refund state.
$order_after = wc_get_order(${ orderId });
$status_after = $order_after ? $order_after->get_status() : "unknown";
$attendee_after = $wpdb->get_row($wpdb->prepare(
	"SELECT id, status, ticket_type_id FROM {$wpdb->prefix}nettertech_events_attendees WHERE wc_order_id = %d LIMIT 1",
	${ orderId }
));
$sold_after = $attendee_before
	? (int) $wpdb->get_var($wpdb->prepare(
		"SELECT sold_count FROM {$wpdb->prefix}nettertech_events_ticket_types WHERE id = %d",
		$attendee_before->ticket_type_id
	))
	: -1;

$diag = json_encode(array(
	"order_status_before" => $status_before,
	"order_status_after"  => $status_after,
	"attendee_status_before" => $attendee_before ? $attendee_before->status : null,
	"attendee_status_after"  => $attendee_after ? $attendee_after->status : null,
	"sold_count_before" => $sold_before,
	"sold_count_after"  => $sold_after,
	"refund_id" => $refund->get_id(),
));
echo "REFUND_DIAG:" . $diag . ":END_DIAG";
echo "REFUND_RESULT:ok:" . $refund->get_id() . ":END";
`;
	const out = wpEval( php );
	if ( ! /REFUND_RESULT:ok:/.test( out ) ) {
		throw new Error( `wc_create_refund failed:\n${ out }` );
	}
	const diagMatch = out.match( /REFUND_DIAG:(\{.*?\}):END_DIAG/ );
	if ( ! diagMatch ) {
		return { order_status_before: '?', order_status_after: '?', attendee_status_before: null, attendee_status_after: null, sold_count_before: -1, sold_count_after: -1, refund_id: 0 };
	}
	return JSON.parse( diagMatch[ 1 ] ) as RefundDiagnostic;
}

/**
 * Assert HPOS is enabled in the test environment.
 *
 * Fails fast with a clear, actionable error rather than silently skipping.
 * The whole point of the HPOS round-trip test is to catch regressions, so
 * skipping when HPOS is off would defeat the purpose.
 */
function assertHposEnabled(): void {
	const php = `
$enabled = get_option("woocommerce_custom_orders_table_enabled", "no");
echo "HPOS_STATE:" . $enabled . ":END";
`;
	const out = wpEval( php );
	const match = out.match( /HPOS_STATE:([^:]+):END/ );
	if ( ! match ) {
		throw new Error( `Could not parse HPOS state from output:\n${ out }` );
	}
	const state = match[ 1 ].trim();
	if ( 'yes' !== state ) {
		throw new Error(
			`NTE-028 spec requires HPOS to be enabled. Current state: "${ state }".\n` +
			'Enable with: wp option update woocommerce_custom_orders_table_enabled yes ' +
			`--path=${ WP_PATH }`
		);
	}
}

// ─── Browser flow helpers ────────────────────────────────────────────────────

const WC_BLOCKS_IGNORED_PATTERNS = [
	/ReactDOM\.render/,
	/Warning: Each child in a list/,
	/Failed to register a ServiceWorker/,
	/findDOMNode is deprecated/,
];

function attachWcBlocksConsoleErrorCollector(
	page: import( '@playwright/test' ).Page
): () => Promise<void> {
	return attachConsoleErrorCollector( page, WC_BLOCKS_IGNORED_PATTERNS );
}

/**
 * Add one ticket to the cart from the event page.
 * Mirrors the helper in ticket-purchase-full.spec.ts.
 */
async function addOneTicketToCart(
	page: import( '@playwright/test' ).Page,
	permalink: string
): Promise<void> {
	await page.goto( permalink );
	await page.waitForLoadState( 'networkidle' );

	const plusBtn = page.locator( '.nte-ticket-quantity__btn--plus' ).first();
	await expect( plusBtn ).toBeVisible();
	await plusBtn.click();
	await page.waitForTimeout( 300 );

	const submitBtn = page.locator( 'button.nte-ticket-form__submit' );
	await expect( submitBtn ).toBeEnabled();
	await submitBtn.click();

	await page.waitForURL( /\/cart\/?/, { timeout: 15000 } ).catch( () => { /* no redirect */ } );
	if ( ! page.url().includes( 'cart' ) ) {
		await page.goto( '/cart/' );
	}
	await page.waitForLoadState( 'networkidle' );
}

/**
 * Walk the WC Blocks checkout, fill billing, place order via COD.
 *
 * Returns the order ID parsed from the order-received URL, which is the
 * canonical post-checkout artefact for downstream assertions.
 */
async function placeOrderViaCheckout(
	page: import( '@playwright/test' ).Page
): Promise<number> {
	await page.goto( '/checkout/' );
	await page.waitForLoadState( 'networkidle' );

	const isEmpty = ( await page.locator( '.cart-empty, .wp-block-woocommerce-empty-cart-block' ).count() ) > 0;
	if ( isEmpty ) {
		throw new Error( 'Cart was empty at checkout — add-to-cart step failed upstream' );
	}

	// Saved-address card → Edit button to expose the form.
	const editBillingBtn = page.locator( 'button:has-text("Edit"), .wc-block-components-address-card__edit' ).first();
	if ( ( await editBillingBtn.count() ) > 0 && await editBillingBtn.isVisible() ) {
		await editBillingBtn.click();
		await page.waitForTimeout( 300 );
	}

	const fields: Array<{ selector: string; value: string }> = [
		{ selector: '#email',                value: BILLING.email },
		{ selector: '#billing-first_name',   value: BILLING.firstName },
		{ selector: '#billing-last_name',    value: BILLING.lastName },
		{ selector: '#billing-address_1',    value: BILLING.address1 },
		{ selector: '#billing-city',         value: BILLING.city },
		{ selector: '#billing-postcode',     value: BILLING.postcode },
		{ selector: '#billing-phone',        value: BILLING.phone },
	];

	for ( const field of fields ) {
		const input = page.locator( field.selector ).first();
		if ( ( await input.count() ) > 0 && await input.isVisible() ) {
			await input.fill( field.value );
		}
	}

	const stateSelect = page.locator( '#billing-state' ).first();
	if ( ( await stateSelect.count() ) > 0 && await stateSelect.isVisible() ) {
		await stateSelect.selectOption( BILLING.state );
	}

	const codRadio = page.locator( 'input[value="cod"]' ).first();
	if ( ( await codRadio.count() ) > 0 ) {
		await codRadio.click();
		await page.waitForTimeout( 500 );
	}

	const placeOrderBtn = page.locator(
		'button.wc-block-components-checkout-place-order-button, button#place_order, button:has-text("Place order")'
	).first();
	await expect( placeOrderBtn ).toBeVisible();
	await placeOrderBtn.click();

	await page.waitForURL( /order-received/, { timeout: 20000 } );

	const url = page.url();
	const match = url.match( /order-received\/(\d+)\// );
	if ( ! match ) {
		throw new Error( `Could not parse order ID from URL: ${ url }` );
	}
	return parseInt( match[ 1 ], 10 );
}

// ─── Specs ───────────────────────────────────────────────────────────────────

test.describe( 'WC Ticket Refund Cascade + HPOS Round-Trip (NTE-028)', () => {
	let fixture: FixtureData | null = null;

	test.beforeAll( async () => {
		// Hard preflight: HPOS must be on or every test in this file is invalid.
		assertHposEnabled();
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

	// NTE-037 fixed the 'voided' whitelist gap. This test exercises the
	// full-refund cascade end-to-end and verifies the attendee transitions
	// to 'voided' (not stuck at 'confirmed').
	test( 'refund cancels attendee and releases capacity (NTE-037 regression gate)', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		const assertNoErrors = attachWcBlocksConsoleErrorCollector( page );

		// Snapshot sold_count BEFORE purchase.
		const soldBefore = getSoldCount( fixture!.ticketTypeId );

		// Step 1: place an order through the browser end-to-end.
		await addOneTicketToCart( page, fixture!.permalink );
		const orderId = await placeOrderViaCheckout( page );
		expect( orderId ).toBeGreaterThan( 0 );

		// After successful order placement, sold_count must have incremented
		// and the attendee record must exist in confirmed status.
		const soldAfterPurchase = getSoldCount( fixture!.ticketTypeId );
		expect(
			soldAfterPurchase,
			`sold_count should increment after order placement (was ${ soldBefore }, now ${ soldAfterPurchase })`
		).toBe( soldBefore + 1 );

		const attendeesAfterPurchase = getAttendeesForOrder( orderId );
		expect(
			attendeesAfterPurchase.length,
			`Order ${ orderId } should produce at least one attendee record`
		).toBeGreaterThan( 0 );
		expect( attendeesAfterPurchase[ 0 ].status ).toBe( 'confirmed' );
		expect( attendeesAfterPurchase[ 0 ].quantity ).toBe( 1 );

		// Step 2: open the order in the WC admin (HPOS list view) — proves
		// the order is readable through the HPOS data store path.
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }` );
		await page.waitForLoadState( 'domcontentloaded' );

		// HPOS edit screen renders the order number in the page heading.
		const orderHeading = page.locator( '.wp-heading-inline, h1.wp-heading-inline' ).first();
		await expect( orderHeading ).toBeVisible();

		// Step 3: issue the full refund via wc_create_refund (same code path
		// as the admin "Refund" modal). See header docblock for rationale.
		const refundDiag = issueFullRefund( orderId );
		const diagStr = JSON.stringify( refundDiag, null, 2 );

		// Step 4: assert the cascade.
		//
		// (a) Attendee status must transition OFF "confirmed". The exact
		//     target ("voided", "cancelled", or "refunded") is an
		//     implementation detail of the cascade — the contract is just
		//     "no longer confirmed" — post-NTE-037 this is 'voided'.
		const attendeesAfterRefund = getAttendeesForOrder( orderId );
		expect( attendeesAfterRefund.length ).toBe( attendeesAfterPurchase.length );
		expect(
			attendeesAfterRefund[ 0 ].status,
			`Attendee status must NOT remain "confirmed" after a full refund (NTE-037 regression gate)\nDiagnostic: ${ diagStr }`
		).not.toBe( 'confirmed' );

		// (b) sold_count must have been decremented back to baseline.
		const soldAfterRefund = getSoldCount( fixture!.ticketTypeId );
		expect(
			soldAfterRefund,
			`sold_count must decrement after refund (was ${ soldAfterPurchase }, now ${ soldAfterRefund })\nDiagnostic: ${ diagStr }`
		).toBe( soldBefore );

		await assertNoErrors();
	} );

	test( 'HPOS order round-trip via admin list and edit screen', async ( { page } ) => {
		test.skip( ! fixture, 'Fixture not created in beforeAll' );

		const assertNoErrors = attachWcBlocksConsoleErrorCollector( page );

		// Step 1: place an order through the browser.
		await addOneTicketToCart( page, fixture!.permalink );
		const orderId = await placeOrderViaCheckout( page );
		expect( orderId ).toBeGreaterThan( 0 );

		// Step 2: HPOS-backed admin list view must show the order.
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=wc-orders' );
		await page.waitForLoadState( 'domcontentloaded' );

		// Confirm we landed on the HPOS orders list (not redirected to the
		// legacy CPT screen). The HPOS URL keeps page=wc-orders.
		expect( page.url() ).toContain( 'page=wc-orders' );

		// The orders table is a WP_List_Table — both legacy and HPOS use
		// .wp-list-table. The order ID column links to the edit screen.
		const ordersTable = page.locator( '.wp-list-table' );
		await expect( ordersTable ).toBeVisible();

		// Find a row that links to our order (id=<orderId>).
		const orderRowLink = page.locator( `a[href*="id=${ orderId }"]` ).first();
		await expect(
			orderRowLink,
			`Order ${ orderId } must appear in the HPOS orders list`
		).toBeVisible();

		// Step 3: open the edit screen (HPOS path) and verify it renders.
		await page.goto( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }` );
		await page.waitForLoadState( 'domcontentloaded' );

		// The order edit screen has the status select and an order number
		// in the heading. Both come from the HPOS data store on this URL.
		const statusSelect = page.locator( '#order_status, select[name="order_status"]' ).first();
		await expect( statusSelect ).toBeVisible();

		// Step 4: round-trip an edit (add an internal order note via wp eval).
		// Doing the edit through wp eval rather than the admin notes textarea
		// avoids brittle dependencies on TinyMCE/REST nonces and still proves
		// the round-trip: we write through the HPOS-aware order API, then
		// reload the admin page and assert the note appears.
		const noteText = `NTE-028 round-trip ${ Date.now() }`;
		const phpAddNote = `
$order = wc_get_order(${ orderId });
if (! $order) { echo "NOTE_RESULT:no_order:END"; return; }
$note_id = $order->add_order_note(${ JSON.stringify( noteText ) });
echo "NOTE_RESULT:ok:" . $note_id . ":END";
`;
		const noteOut = wpEval( phpAddNote );
		expect( noteOut ).toMatch( /NOTE_RESULT:ok:\d+:END/ );

		// Reload the edit screen — the note must be present in the DOM,
		// proving the note round-tripped through HPOS storage.
		await page.goto( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }` );
		await page.waitForLoadState( 'domcontentloaded' );

		const noteContent = page.locator( '.order_notes, #woocommerce-order-notes' );
		await expect( noteContent.first() ).toBeVisible();
		await expect( noteContent.first() ).toContainText( noteText );

		await assertNoErrors();
	} );
} );
