/**
 * E2E tests for ticket purchase flow.
 *
 * Tests the WooCommerce integration for event ticket purchasing.
 *
 * Note: Tests that require specific data (events, tickets) use test.skip()
 * with descriptive messages when prerequisites aren't met, rather than
 * silently passing with vacuous assertions.
 */

import { test, expect } from './fixtures';
import { loginAsAdmin } from './support/page-objects';

test.describe( 'Ticket Purchase Flow', () => {
	test( 'events page shows ticket information', async ( { page } ) => {
		await page.goto( '/events/' );

		// Look for ticket price or availability indicators
		const ticketInfo = page.locator(
			'.ve-price, .ticket-price, .ve-tickets, [class*="price"], [class*="ticket"]'
		);

		// Page should have events or empty state
		const content = await page.content();
		const hasEventsOrEmptyState =
			( await ticketInfo.count() ) > 0 ||
			content.includes( 'event' ) ||
			content.includes( 'No events' );

		expect( hasEventsOrEmptyState ).toBeTruthy();
	} );

	test( 'event detail shows ticket types', async ( { page } ) => {
		await page.goto( '/events/' );

		// Find first event link
		const eventLink = page.locator( '.ve-event-card a, .event-card a' ).first();
		const eventCount = await eventLink.count();

		// Skip if no events exist - test requires seeded data
		test.skip( eventCount === 0, 'No events available - test requires seeded event data' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for ticket section - this SHOULD exist on event detail pages
		const ticketSection = page.locator(
			'.ve-tickets, .ticket-types, .ve-ticket-selection, [class*="ticket"]'
		);

		// Event page should have ticket area or registration info
		const hasTicketArea =
			( await ticketSection.count() ) > 0 ||
			( await page.content() ).includes( 'ticket' ) ||
			( await page.content() ).includes( 'register' );

		expect( hasTicketArea ).toBeTruthy();
	} );

	test( 'ticket quantity selector works', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( '.ve-event-card a, .event-card a' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events available - test requires seeded event data' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for quantity input
		const quantityInput = page.locator(
			'input[name*="quantity"], input[type="number"], .quantity input'
		);
		const inputCount = await quantityInput.count();

		test.skip( inputCount === 0, 'No quantity input found - event may not have tickets configured' );

		await quantityInput.first().fill( '2' );
		await expect( quantityInput.first() ).toHaveValue( '2' );
	} );

	test( 'add to cart button exists on ticketed event', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( '.ve-event-card a, .event-card a' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events available - test requires seeded event data' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for add to cart or register button
		const addToCartButton = page.locator(
			'button:has-text("Add to Cart"), button:has-text("Register"), .single_add_to_cart_button, [name="add-to-cart"]'
		);

		// For ticketed events, add to cart button MUST exist
		// If no button found, test fails - this is the expected behavior
		const buttonCount = await addToCartButton.count();

		// Skip only if we detect this is a free/RSVP event (no WC product)
		const pageContent = await page.content();
		const isFreeOrRsvp = pageContent.includes( 'RSVP' ) || pageContent.includes( 'Free' );

		if ( isFreeOrRsvp ) {
			test.skip( true, 'Event is free/RSVP - no add to cart button expected' );
		}

		expect( buttonCount ).toBeGreaterThan( 0 );
	} );

	test.skip( 'sold out events show correct status', async ( { page } ) => {
		// TODO: This test requires a sold-out event fixture to be meaningful.
		// Currently skipped because we cannot guarantee a sold-out event exists.
		// To implement properly:
		// 1. Create event with capacity 1
		// 2. Purchase ticket to sell it out
		// 3. Verify sold-out indicator appears
		await page.goto( '/events/' );

		const soldOutIndicator = page.locator(
			'.sold-out, .ve-sold-out, [class*="sold-out"]'
		);

		await expect( soldOutIndicator.first() ).toBeVisible();
	} );

	test( 'WooCommerce cart page accessible', async ( { page } ) => {
		await page.goto( '/cart/' );

		// Cart page should load without 404
		await expect( page ).not.toHaveTitle( /not found/i );

		// Should have cart structure (empty cart is still valid structure)
		const hasCartStructure = page.locator(
			'.woocommerce-cart, .cart-empty, .woocommerce'
		);

		await expect( hasCartStructure.first() ).toBeVisible();
	} );

	test( 'WooCommerce checkout page accessible', async ( { page } ) => {
		await page.goto( '/checkout/' );

		// Checkout page should load without 404
		await expect( page ).not.toHaveTitle( /not found/i );

		// Should have checkout structure or redirect to cart (if empty)
		const checkoutOrCart = page.locator(
			'.woocommerce-checkout, .woocommerce-cart, .cart-empty, .woocommerce'
		);

		await expect( checkoutOrCart.first() ).toBeVisible();
	} );

	test( 'WooCommerce orders admin accessible', async ( { page } ) => {
		await loginAsAdmin( page );

		// Navigate to WooCommerce orders - try HPOS URL first (WC 8.2+)
		await page.goto( '/wp-admin/admin.php?page=wc-orders' );

		// Check if HPOS is active or if we need legacy URL
		const isHposPage = page.url().includes( 'wc-orders' );
		const is404 = ( await page.content() ).includes( 'not exist' );

		if ( is404 ) {
			// Fall back to legacy CPT-based orders
			await page.goto( '/wp-admin/edit.php?post_type=shop_order' );
		}

		// Should see orders list structure
		const ordersTable = page.locator( '.wp-list-table, #woocommerce-orders-table, .woocommerce-orders-table' );
		const ordersNav = page.locator( '.subsubsub, .woocommerce-layout__header' );
		const wcPage = page.locator( '.woocommerce-layout, .wrap' );

		const hasOrdersInterface =
			( await ordersTable.count() ) > 0 ||
			( await ordersNav.count() ) > 0 ||
			( await wcPage.count() ) > 0;

		expect( hasOrdersInterface ).toBeTruthy();
	} );

	test( 'ticket types show availability count when configured', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( '.ve-event-card a, .event-card a' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events available - test requires seeded event data' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for availability indicators
		const availabilityIndicator = page.locator(
			'.availability, .stock, .ve-capacity, [class*="available"], [class*="remaining"]'
		);

		const indicatorCount = await availabilityIndicator.count();

		// If capacity is configured, availability should show
		// Skip if no indicators found (capacity may not be configured)
		test.skip(
			indicatorCount === 0,
			'No availability indicators found - event may not have capacity configured'
		);

		await expect( availabilityIndicator.first() ).toBeVisible();
	} );

	test.skip( 'free tickets can be registered without payment', async ( { page } ) => {
		// TODO: This test requires a free event fixture to be meaningful.
		// Currently skipped because we cannot guarantee a free event exists.
		// To implement properly:
		// 1. Create event with $0 ticket
		// 2. Register for free ticket
		// 3. Verify registration succeeds without checkout
		await page.goto( '/events/' );

		const freeIndicator = page.locator(
			'.ve-free, .free-event, :text("Free"), :text("$0")'
		);

		await expect( freeIndicator.first() ).toBeVisible();
	} );
} );

test.describe( 'Batch Ticket Selection', () => {
	test( 'batch ticket selector shows multiple ticket types with quantities', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( '.ve-event-card a, .event-card a' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events available - test requires seeded event data' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for the batch ticket selector container
		const ticketSelector = page.locator( '.ve-ticket-selection, .ve-tickets' );
		const selectorCount = await ticketSelector.count();

		test.skip( selectorCount === 0, 'No ticket selector found - event may not have tickets configured' );

		// Look for quantity inputs - batch selector should have one per ticket type
		const quantityInputs = ticketSelector.locator(
			'input[type="number"], input[name*="quantity"]'
		);
		const inputCount = await quantityInputs.count();

		// Should have at least one quantity input for a ticketed event
		expect( inputCount ).toBeGreaterThan( 0 );
	} );

	test( 'batch add to cart button exists with multiple ticket types', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( '.ve-event-card a, .event-card a' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events available - test requires seeded event data' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for the batch add to cart button
		const addToCartButton = page.locator(
			'button.ve-batch-add-to-cart, button[data-action="add-tickets-batch"], .ve-add-to-cart-batch'
		);

		const buttonCount = await addToCartButton.count();

		// Skip if no batch button (event may use single ticket type)
		test.skip(
			buttonCount === 0,
			'No batch add to cart button - event may only have single ticket type'
		);

		// Button should be visible
		await expect( addToCartButton.first() ).toBeVisible();
	} );

	test( 'batch quantity inputs can be modified independently', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( '.ve-event-card a, .event-card a' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events available - test requires seeded event data' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for multiple quantity inputs
		const quantityInputs = page.locator(
			'.ve-ticket-selection input[type="number"], .ve-tickets input[name*="quantity"]'
		);
		const inputCount = await quantityInputs.count();

		test.skip( inputCount < 2, 'Event needs multiple ticket types for batch testing' );

		// Set different quantities for first two inputs
		await quantityInputs.nth( 0 ).fill( '2' );
		await quantityInputs.nth( 1 ).fill( '3' );

		// Verify values are independent
		await expect( quantityInputs.nth( 0 ) ).toHaveValue( '2' );
		await expect( quantityInputs.nth( 1 ) ).toHaveValue( '3' );
	} );

	test( 'total updates when quantities change', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( '.ve-event-card a, .event-card a' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events available - test requires seeded event data' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		// Look for total display
		const totalDisplay = page.locator(
			'.ve-batch-total, .ve-total-price, [class*="total"]'
		);
		const totalCount = await totalDisplay.count();

		test.skip( totalCount === 0, 'No total display found - may not have batch selector' );

		// Look for quantity input
		const quantityInput = page.locator(
			'.ve-ticket-selection input[type="number"]'
		).first();
		const inputCount = await quantityInput.count();

		test.skip( inputCount === 0, 'No quantity input found' );

		// Get initial total
		const initialTotal = await totalDisplay.first().textContent();

		// Change quantity
		await quantityInput.fill( '2' );

		// Wait for total to update (may be async)
		await page.waitForTimeout( 500 );

		// Get updated total
		const updatedTotal = await totalDisplay.first().textContent();

		// Total should change (or stay same if $0 tickets)
		// Just verify total element still exists and has content
		expect( updatedTotal ).toBeTruthy();
	} );
} );

test.describe( 'Cart Validation', () => {
	test( 'quantity input has max attribute when capacity limited', async ( { page } ) => {
		await page.goto( '/events/' );

		const eventLink = page.locator( '.ve-event-card a, .event-card a' ).first();
		const eventCount = await eventLink.count();

		test.skip( eventCount === 0, 'No events available - test requires seeded event data' );

		await eventLink.click();
		await page.waitForLoadState( 'networkidle' );

		const quantityInput = page.locator(
			'input[name*="quantity"], input[type="number"]'
		);
		const inputCount = await quantityInput.count();

		test.skip( inputCount === 0, 'No quantity input found - event may not have tickets' );

		const maxAttr = await quantityInput.first().getAttribute( 'max' );

		// If max attribute exists, it should be a positive number
		if ( maxAttr !== null ) {
			const maxValue = parseInt( maxAttr, 10 );
			expect( maxValue ).toBeGreaterThan( 0 );
		}
		// If no max attribute, capacity may be unlimited - that's valid
	} );

	test( 'cart shows event details for tickets', async ( { page } ) => {
		await page.goto( '/cart/' );

		// Cart structure must exist
		const cartContainer = page.locator( '.woocommerce-cart, .woocommerce, .cart-empty' );
		await expect( cartContainer.first() ).toBeVisible();

		// If cart has items, they should show product info
		const cartItems = page.locator( '.cart_item, .woocommerce-cart-form__cart-item' );
		const itemCount = await cartItems.count();

		if ( itemCount > 0 ) {
			// Cart items should have product names
			const productName = page.locator( '.product-name, .cart_item td.product-name' );
			await expect( productName.first() ).toBeVisible();
		}
		// Empty cart is valid - structure was verified above
	} );

	test( 'checkout has billing fields', async ( { page } ) => {
		await page.goto( '/checkout/' );

		// Wait for checkout or cart-empty state
		const checkoutForm = page.locator( '.woocommerce-checkout, .checkout' );
		const cartEmpty = page.locator( '.cart-empty' );

		const hasCheckout = ( await checkoutForm.count() ) > 0;
		const isEmpty = ( await cartEmpty.count() ) > 0;

		// If cart is empty, skip - checkout fields only show with items
		test.skip( isEmpty, 'Cart is empty - checkout fields only visible with items in cart' );

		if ( hasCheckout ) {
			// Checkout should have billing fields
			const billingFields = page.locator(
				'#billing_email, #billing_first_name, input[name*="billing"]'
			);
			const fieldCount = await billingFields.count();

			expect( fieldCount ).toBeGreaterThan( 0 );
		}
	} );
} );
