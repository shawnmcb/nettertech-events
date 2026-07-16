# WooCommerce Integration Boundary

> **Audience:** Plugin developers working on the WooCommerce integration layer and add-on developers whose code runs alongside WooCommerce hooks. Defines exactly what this plugin owns vs. what WooCommerce owns along the ticketing path.

## Overview

This document defines the boundary between NetterTech Events (NTE) and WooCommerce (WC) at every integration point across the plugin suite: the base plugin (`nettertech-events`), the Rentals satellite (`nettertech-events-rentals`), and the Seating satellite (`nettertech-events-seating`).

It is the authoritative reference for agents working on checkout, payment, ticketing, cart, and capacity features. Every WC hook used is cataloged, every data-flow direction is explicit, and every ownership decision is documented.

## Boundary Principles

From ADR-008 (accepted 2025-01-20):

1. **WC is the payment infrastructure; NTE is the domain logic.** WooCommerce handles payment gateways, tax, coupons, receipts, and order management UI. NTE handles event capacity, attendee records, ticket generation, check-in, and QR codes.
2. **CRUD API only.** All WC interactions use `wc_get_order()`, `$order->get_meta()`, `$order->update_meta_data()`, `wc_get_product()`, etc. Zero raw SQL against WC tables.
3. **HPOS-compatible from day one.** No `wp_posts`/`wp_postmeta` assumptions for orders. Plugin declares HPOS compatibility via `before_woocommerce_init`.
4. **Graceful degradation.** When WC is not active, the plugin functions in RSVP-only mode. Feature detection via `class_exists('WooCommerce')`.
5. **Price source of truth is WC; capacity source of truth is NTE.** WC product price is authoritative. NTE's `CapacityService` is authoritative for availability. WC stock quantity is a synchronized mirror of NTE capacity, not the other way around.

## Summary: Who Owns What

| Concern | Owner | Notes |
|---------|-------|-------|
| Payment processing | WC | 100+ gateways, PCI compliance, payment forms |
| Tax calculation | WC | WC tax engine, no NTE override |
| Coupons / discounts | WC | Standard WC coupon system (Rentals excludes its products) |
| Order management UI | WC | WC admin order screens; NTE adds panels/notes |
| Order email receipts | WC | Standard WC transactional emails |
| Confirmation emails (tickets) | NTE | `EmailService` sends ticket confirmations with QR codes |
| Reminder emails | NTE | Cron-driven occurrence reminders |
| Cart UI (standard) | WC | WC renders cart; NTE injects metadata via filters |
| Cart validation | NTE | Capacity checks, sale window, min/max per order |
| Capacity management | NTE | `CapacityService` owns all capacity math |
| Stock quantity sync | NTE -> WC | NTE writes WC stock; WC never writes NTE capacity |
| Pending reservations | NTE | Atomic DB reservation (ticket_types.reserved column + nte_reservations table; hourly cron sweep - C1, updated 2026-03-30) |
| Product creation / sync | NTE | NTE creates hidden `WC_Product_Simple` for each ticket type |
| Product pricing | WC (synced from NTE) | NTE sets `regular_price` on sync; WC product is display source of truth |
| Product visibility | NTE | All ticket products are `catalog_visibility = hidden` |
| Product stock status | NTE -> WC | NTE computes available count, pushes to WC `stock_quantity` |
| Attendee records | NTE | Created on order completion, stored in NTE custom tables |
| Ticket records | NTE | Individual tickets with QR codes, NTE custom tables |
| Refund financial processing | WC | WC handles payment reversal |
| Refund domain effects | NTE | NTE cancels tickets, updates attendee status, releases capacity |
| Checkout fields (accessibility, donation) | NTE | Rendered via WC hooks, saved to WC order meta |
| Block checkout support | NTE + WC | NTE extends Store API; WC provides Block infrastructure |
| Seat holds | Seating | Managed in seating custom tables; gateway-aware TTL (60min real-time, immediate assign for offline - C8, updated 2026-03-30) |
| Seat assignments | Seating | Created on `nte_attendee_created`, stored in seating custom tables |
| Rental booking lifecycle | Rentals | Booking state machine in rental custom tables; WC handles payment |
| Custom order statuses | Rentals | `wc-deposit-paid` registered by Rentals |

## Hook Boundary Table

### Base Plugin: nettertech-events

| Hook Name | Type | Fired By | NTE Handler | NTE Action | Data Direction |
|-----------|------|----------|-------------|------------|----------------|
| `nte_ticket_type_saved` | action | NTE | `ProductManager::sync_product` | Create/update WC product for ticket type | NTE -> WC |
| `nte_ticket_type_sync_product` | action | NTE | `ProductManager::sync_product` | Force-sync WC product | NTE -> WC |
| `nte_ticket_type_deleted` | action | NTE | `ProductManager::delete_product` | Delete linked WC product | NTE -> WC |
| `woocommerce_add_to_cart_validation` | filter | WC | `CartHandler::validate_add_to_cart` | Check capacity, sale window, min/max; create pending reservation | WC -> NTE (product ID) -> NTE decides |
| `woocommerce_cart_item_name` | filter | WC | `CartHandler::modify_cart_item_name` (via CartPresenter) | Replace product name with event permalink | WC -> NTE (cart item) -> WC (filtered name) |
| `woocommerce_get_item_data` | filter | WC | `CartHandler::display_cart_item_data` (via CartPresenter) | Add Date, Time, Venue, Ticket Type to cart display | WC -> NTE (cart item) -> WC (display data) |
| `woocommerce_cart_item_thumbnail` | filter | WC | `WooCommerceIntegration::filter_ticket_thumbnail` | Replace product thumbnail with ticket placeholder SVG | WC -> NTE -> WC |
| `woocommerce_admin_order_item_thumbnail` | filter | WC | `WooCommerceIntegration::filter_admin_order_item_thumbnail` | Replace admin order item thumbnail with ticket placeholder SVG | WC -> NTE -> WC |
| `woocommerce_checkout_create_order_line_item` | action | WC | `CartHandler::add_order_item_meta` (via CartPresenter) | Write `_nte_occurrence_id`, `_nte_ticket_type_id`, `_nte_event_id`, human-readable event details to order item | NTE -> WC (order item meta) |
| `woocommerce_cart_item_removed` | action | WC | `CartHandler::handle_cart_item_removed` | Release or reduce pending capacity reservation | WC -> NTE |
| `woocommerce_after_cart_item_quantity_update` | action | WC | `CartHandler::handle_cart_quantity_update` | Adjust pending capacity reservation to match new quantity | WC -> NTE |
| `woocommerce_cart_emptied` | action | WC | `CartHandler::handle_cart_emptied` | Clear all pending reservations for session | WC -> NTE |
| `woocommerce_payment_complete` | action | WC | `OrderHandler::handle_payment_complete` | Process order: validate capacity, create attendees/tickets, reserve capacity, sync stock | WC -> NTE |
| `woocommerce_order_status_completed` | action | WC | `OrderHandler::handle_order_completed` | Same as payment_complete (idempotent) | WC -> NTE |
| `woocommerce_order_status_processing` | action | WC | `OrderHandler::handle_order_processing` | Same as payment_complete (idempotent) | WC -> NTE |
| `woocommerce_order_status_cancelled` | action | WC | `OrderHandler::handle_order_cancelled` | Void attendees, cancel tickets, release capacity, sync stock | WC -> NTE |
| `woocommerce_order_status_refunded` | action | WC | `OrderHandler::handle_order_refunded` | Void attendees, cancel tickets, release capacity, sync stock | WC -> NTE |
| `woocommerce_refund_created` | action | WC | `OrderHandler::handle_refund_created` | Process partial refund: reduce attendee quantity, cancel N tickets, release capacity, sync stock | WC -> NTE |
| `woocommerce_product_data_tabs` | filter | WC | `WooCommerceIntegration::add_product_data_tab` | Add "Event Ticket" tab to WC product editor | NTE -> WC (UI) |
| `woocommerce_product_data_panels` | action | WC | `WooCommerceIntegration::render_product_data_panel` | Render occurrence/ticket type info in product editor | NTE -> WC (UI) |
| `woocommerce_after_order_notes` | action | WC | `AccessibilityNotesHandler::render_field` (priority 10) | Render accessibility requirements textarea on checkout | NTE -> WC (checkout UI) |
| `woocommerce_after_order_notes` | action | WC | `DonationHandler::render_donation_field` (priority 20) | Render donation options (round-up, presets, custom) on checkout | NTE -> WC (checkout UI) |
| `woocommerce_checkout_update_order_meta` | action | WC | `AccessibilityNotesHandler::save_notes` (priority 10) | Save `_nte_accessibility_notes` to order meta | WC -> NTE -> WC (order meta) |
| `woocommerce_checkout_update_order_meta` | action | WC | `DonationHandler::save_donation_meta` (priority 20) | Save `_nte_donation_amount` and `_nte_donation_type` to order meta | WC -> NTE -> WC (order meta) |
| `woocommerce_cart_calculate_fees` | action | WC | `DonationHandler::calculate_donation_fee` | Add donation as non-taxable cart fee | NTE -> WC (fee) |
| `woocommerce_admin_order_data_after_billing_address` | action | WC | `AccessibilityNotesHandler::display_admin` (priority 10) | Show accessibility notes in admin order view | WC -> NTE (read) -> WC (UI) |
| `woocommerce_admin_order_data_after_billing_address` | action | WC | `DonationHandler::display_donation_admin` (priority 20) | Show donation info in admin order view | WC -> NTE (read) -> WC (UI) |
| `wp_ajax_nte_update_donation` | action | WP | `DonationHandler::handle_ajax_update` | Update donation amount/type in WC session via AJAX | NTE -> WC (session) |
| `wp_ajax_nopriv_nte_update_donation` | action | WP | `DonationHandler::handle_ajax_update` | Same for guest checkout | NTE -> WC (session) |
| `wp_enqueue_scripts` | action | WP | `WooCommerceIntegration::maybe_enqueue_checkout_assets` | Enqueue donation CSS/JS on checkout when donations enabled | NTE -> WC (assets) |
| `woocommerce_blocks_loaded` | action | WC | `WooCommerceIntegration::register_block_integration` (closure) | Register Store API extension + Block cart/checkout integration | NTE -> WC (Store API) |
| `woocommerce_blocks_cart_block_registration` | action | WC | closure | Register `BlockIntegration` with WC Cart block | NTE -> WC |
| `woocommerce_blocks_checkout_block_registration` | action | WC | closure | Register `BlockIntegration` with WC Checkout block | NTE -> WC |
| `woocommerce_store_api_checkout_update_order_from_request` | action | WC | closure | Save accessibility notes from Block checkout session to order meta | WC -> NTE -> WC (order meta) |

### NTE Custom Hooks Fired During WC Integration

| Hook Name | Fired By | Context | Consumers |
|-----------|----------|---------|-----------|
| `nte_attendee_created` | `OrderAttendeeCreator` | After attendee + tickets created from order | Seating (`convert_holds_to_assignments`), EmailService |
| `nte_attendee_creation_failed` | `OrderAttendeeCreator` | When attendee creation throws RuntimeException | Logging |
| `nte_registration_voided` | `OrderHandler` | When attendee voided (cancel/refund) | Seating (`handle_registration_voided`) |
| `nte_tickets_refunded` | `OrderRefundProcessor` | After partial refund processed | Reporting |
| `nte_capacity_oversell_detected` | `OrderCapacityValidator` | When payment-time capacity check finds oversell | Alerting |

### Satellite Plugin: nettertech-events-rentals

| Hook Name | Type | Fired By | Handler | Action | Data Direction |
|-----------|------|----------|---------|--------|----------------|
| `init` | action | WP | `RentalWooCommerceIntegration::register_custom_order_statuses` | Register `wc-deposit-paid` order status | Rentals -> WC |
| `wc_order_statuses` | filter | WC | `RentalWooCommerceIntegration::add_custom_order_statuses` | Add `wc-deposit-paid` to WC status dropdown | Rentals -> WC |
| `woocommerce_coupon_is_valid_for_product` | filter | WC | `RentalWooCommerceIntegration::exclude_rental_from_coupons` | Prevent coupons on rental products | WC -> Rentals (decision) |
| `before_delete_post` | action | WP | `RentalWooCommerceIntegration::prevent_rental_product_deletion` | Block deletion of products linked to active bookings | WC -> Rentals (guard) |
| `woocommerce_add_to_cart_validation` | filter | WC | `RentalCartHandler::validate_add_to_cart` | Validate booking exists, set cart hold, extend booking expiry | WC -> Rentals |
| `woocommerce_get_item_data` | filter | WC | `RentalCartHandler::display_cart_item_data` | Show Space, Start, End, Payment Type in cart | WC -> Rentals -> WC |
| `woocommerce_cart_item_removed` | action | WC | `RentalCartHandler::on_item_removed` | Clear cart hold transient for removed booking | WC -> Rentals |
| `woocommerce_payment_complete` | action | WC | `RentalOrderHandler::handle_payment_complete` | Transition booking status, record payment, set order status, clear hold | WC -> Rentals |
| `woocommerce_order_status_processing` | action | WC | `RentalOrderHandler::handle_order_status_processing` | Same as payment_complete (idempotent) | WC -> Rentals |
| `woocommerce_order_status_cancelled` | action | WC | `RentalOrderHandler::handle_order_cancelled` | Transition booking to cancelled or on-hold (source-aware), update payment records | WC -> Rentals |
| `woocommerce_order_status_refunded` | action | WC | `RentalOrderHandler::handle_order_refunded` | Create refund payment record on booking | WC -> Rentals |

### Satellite Plugin: nettertech-events-seating

| Hook Name | Type | Fired By | Handler | Action | Data Direction |
|-----------|------|----------|---------|--------|----------------|
| `woocommerce_add_to_cart_validation` | filter | WC | `SeatingCartHandler::validate_add_to_cart` (priority 15, C3) | Read-only seat availability check (no hold creation); runs after Base validation at priority 10 | WC -> Seating |
| `woocommerce_add_to_cart` | action | WC | `SeatingCartHandler::create_holds_on_add` (C3) | Create seat holds after all validators pass; ConflictException handles TOCTOU races | WC -> Seating |
| `woocommerce_add_cart_item_data` | filter | WC | `SeatingCartHandler::add_seat_data_to_cart_item` | Attach `nte_seat_ids`, `nte_ga_selections`, `nte_seat_hash` to cart item | WC -> Seating -> WC (cart item data) |
| `woocommerce_get_item_data` | filter | WC | `SeatingCartHandler::display_seat_labels` (priority 20) | Show seat labels / GA counts in cart | WC -> Seating -> WC |
| `woocommerce_cart_item_quantity` | filter | WC | `SeatingCartHandler::prevent_quantity_change` | Replace quantity input with static number for seated items | WC -> Seating -> WC |
| `woocommerce_cart_item_removed` | action | WC | `SeatingCartHandler::release_holds_on_removal` | Delete seat holds for removed cart item | WC -> Seating |
| `woocommerce_cart_emptied` | action | WC | `SeatingCartHandler::release_all_session_holds` | Release all session seat holds | WC -> Seating |
| `woocommerce_check_cart_items` | action | WC | `SeatingCartHandler::validate_holds_before_checkout` | Verify all seat holds still active; remove expired items | WC -> Seating |
| `woocommerce_checkout_order_processed` | action | WC | `SeatingOrderHandler::upgrade_holds_to_checkout` | Upgrade cart holds to checkout holds (60min TTL, filterable, order-linked - C8) | WC -> Seating |
| `woocommerce_store_api_checkout_order_processed` | action | WC | `SeatingOrderHandler::upgrade_holds_to_checkout` | Same for Block checkout (C8) | WC -> Seating |
| `woocommerce_checkout_create_order_line_item` | action | WC | `SeatingOrderHandler::save_seat_meta_to_order_item` (priority 20) | Write `_ve_seat_ids`, `_ve_occurrence_id`, visible "Seats" label to order item | Seating -> WC (order item meta) |
| `nte_attendee_created` | action | NTE | `SeatingOrderHandler::convert_holds_to_assignments` | Convert temporary holds to permanent seat assignments, delete holds | NTE -> Seating |
| `woocommerce_order_status_cancelled` | action | WC | `SeatingOrderHandler::handle_order_cancelled` | Delete holds and cancel assignments for order | WC -> Seating |
| `woocommerce_order_status_failed` | action | WC | `SeatingOrderHandler::handle_order_cancelled` | Same as cancelled | WC -> Seating |
| `nte_registration_voided` | action | NTE | `SeatingOrderHandler::handle_registration_voided` | Cancel seat assignments for voided attendee | NTE -> Seating |
| `nte_seating_order_ticket_ids` | filter | Seating | `SeatingOrderHandler::get_order_ticket_ids` | Provide ticket IDs from order items for cancellation pipeline | Seating internal |
| `nte_seating_assignment_partial_failure` | action | Seating | `SeatingOrderHandler::notify_partial_assignment_failure` | Email admin when seat assignment fails post-payment | Seating internal |
| `woocommerce_before_add_to_cart_button` | action | WC | `SeatingProductIntegration::maybe_render_seat_selector` | Render interactive seat selector on product page | Seating -> WC (product page UI) |

### Cross-Plugin Filters Used by Seating

| Filter Name | Applied By | Provider | Purpose |
|-------------|-----------|----------|---------|
| `nte_seating_ticket_capacity_type` | Seating (CartHandler, ProductIntegration) | Core NTE | Resolve whether a ticket type uses seated capacity |
| `nte_seating_resolve_occurrence` | Seating (ProductIntegration) | Core NTE | Resolve occurrence ID from ticket type |
| `nte_seating_resolve_space` | Seating (ProductIntegration) | Core NTE | Resolve space ID from occurrence |
| `nte_seating_cart_hold_duration` | Seating (ProductIntegration) | Any | Configure hold TTL (default 900s) |
| `nte_seating_poll_interval` | Seating (ProductIntegration) | Any | Configure availability poll interval (default 15000ms) |
| `nte_seating_max_per_order` | Seating (ProductIntegration) | Any | Configure max tickets per order for seated events (default 10) |

## Per-Component Boundaries

### Cart (CartHandler, CartValidator, CartPresenter)

**NTE owns:**
- Validation logic: event ended check, on-sale window, min/max per order, capacity availability
- Pending reservation lifecycle: create on add-to-cart, adjust on quantity change, release on removal/empty
- Session key generation (WC session ID with cookie/PHP session fallback)
- Cart item metadata display (date, time, venue, ticket type)
- Batch validation for multi-ticket AJAX add-to-cart
- Ticket type tracking in WC session for cleanup on cart empty

**WC owns:**
- Cart storage, cart item lifecycle, cart totals calculation
- Cart page rendering (NTE injects via filters)
- Cart item removal/quantity update mechanics (NTE reacts via hooks)
- Cart session persistence

**Data flow:**
- NTE reads WC cart contents to compute `cart_quantity_for_ticket_type`
- NTE writes pending reservations to its own capacity system (not WC)
- NTE adds display data to WC cart items via `woocommerce_get_item_data` filter
- NTE writes NTE meta keys to WC order line items at checkout via `woocommerce_checkout_create_order_line_item`

### Checkout and Payment

**NTE owns:**
- Custom checkout fields: accessibility notes textarea, donation options UI
- Donation session management (amount, type stored in WC session)
- Donation fee calculation (added as non-taxable WC cart fee)
- Saving NTE-specific order meta on checkout completion
- Block checkout support: Store API endpoint data extension, update callbacks

**WC owns:**
- Checkout page rendering (NTE injects after order notes)
- Payment form, payment gateway selection, payment processing
- Order creation from cart
- Nonce verification for checkout form
- Block checkout infrastructure (Store API, Checkout block)

**Data flow:**
- NTE -> WC: donation fee via `WC()->cart->add_fee()`, accessibility notes via `$order->update_meta_data()`
- WC -> NTE: `$_POST` fields on classic checkout, session data on Block checkout
- NTE -> WC Store API: cart item extension data (event date, time, venue, ticket type, permalink)

### Order Lifecycle (OrderHandler, OrderAttendeeCreator, OrderRefundProcessor)

**NTE owns:**
- Idempotency: `_nte_attendees_created` meta prevents duplicate processing; per-refund `_nte_refund_processed_{id}` meta prevents duplicate refund processing
- Attendee creation: one attendee record per occurrence per order (with quantity), using billing info from WC order
- Ticket creation: individual ticket records with QR codes, linked to attendee and order item
- Series pass expansion: one attendee per occurrence in the event
- Capacity reservation on order completion (`CapacityService::reserve_capacity`)
- WC stock sync after capacity changes (`ProductManager::sync_stock`)
- Refund processing: reduce attendee quantity or void, cancel tickets, release capacity
- Capacity validation at payment time (with fresh, uncached data)
- Oversell detection and admin alerting (order note + custom hook)

**WC owns:**
- Order status transitions (pending -> processing -> completed, or -> cancelled/refunded)
- Payment gateway communication
- Refund financial processing (payment reversal)
- Order object persistence and HPOS storage
- Order notes UI

**Data flow:**
- WC -> NTE: order ID via hooks, order object via `wc_get_order()`, line items via `$order->get_items()`, billing info via `$order->get_billing_*()`, refund object via `wc_get_order($refund_id)`
- NTE -> WC: order meta (`_nte_attendees_created`, `_nte_refund_processed_*`), order notes (oversell warnings), order item meta (`_nte_ticket_ids`)
- NTE -> NTE: `nte_attendee_created` hook (consumed by Seating, EmailService), `nte_registration_voided` hook (consumed by Seating), `nte_tickets_refunded` hook

**Order processing fires on three hooks** (`woocommerce_payment_complete`, `woocommerce_order_status_processing`, `woocommerce_order_status_completed`) to cover all payment gateway behaviors. Idempotency guard ensures only one execution.

### Product Configuration (ProductManager)

**NTE owns:**
- Product creation: `WC_Product_Simple` with `catalog_visibility = hidden`, `virtual = true`
- Product naming: `{Event Title} - {Date} - {Ticket Type Name}`
- Product pricing: set from ticket type price on sync
- Product stock: computed from NTE capacity, pushed to WC
- Product meta: `_nte_occurrence_id`, `_nte_ticket_type_id`, `_nte_is_event_ticket`, `_nte_event_id`
- Product image: inherited from event/occurrence featured image
- Product lifecycle: created on ticket type save, deleted on ticket type delete
- Reverse lookup: product -> ticket type, product -> occurrence

**WC owns:**
- Product persistence (post type / HPOS)
- Product display in shop (hidden by design; NTE products should never appear in shop)
- Product stock management mechanics (NTE sets the values)

**Data flow:**
- NTE -> WC: product properties (name, price, stock, visibility, virtual flag, image)
- NTE -> WC: product meta via `update_post_meta()`
- WC -> NTE: product ID returned from `$product->save()`
- NTE -> WC (stock sync): `$product->set_stock_quantity()`, `$product->set_stock_status()`

**[RESOLVED]** Migrate to WC CRUD API (`$product->update_meta_data()` + `$product->save()`). The performance argument doesn't hold for admin-frequency operations. Future-proofing and ADR-008 consistency take priority. See task #17.

### Capacity and Inventory (OrderCapacityValidator, CapacityService) (Updated 2026-03-30, C1)

**NTE owns:**
- All capacity math: total capacity, sold count, reserved count, available count
- Reservation system: atomic DB UPDATE on `ticket_types.reserved` column; per-session tracking in `nte_reservations` table (not transients)
- Availability formula: `available = capacity - sold_count - buffer - reserved`
- Hourly cron sweep for expired reservations
- Payment-time validation: fresh capacity check with `skip_cache=true`, `include_pending=false`
- Oversell detection: logs warning, adds order note, fires `nte_capacity_oversell_detected` -- does NOT block order processing (payment already collected)
- Capacity reserve on order completion, release on cancel/refund

**WC owns:**
- WC stock quantity display (if shown anywhere -- products are hidden)
- WC stock status (`instock` / `outofstock`) used by WC's own "add to cart" availability check

**Data flow:**
- NTE -> WC: stock quantity and status via `ProductManager::sync_stock()` after every capacity change
- WC -> NTE: nothing -- WC stock changes do NOT propagate back to NTE capacity

**[RESOLVED]** One-way sync is correct by design. Additionally: hide the WC stock field on NTE-managed products and replace with a notice + link to NTE capacity editor. See task #13.

### Donations (DonationHandler)

**NTE owns:**
- Donation settings (enabled flag, cause text, round-up increment, presets, custom allowed, max)
- Donation UI rendering on checkout (radio buttons for round-up, presets, custom, no thanks)
- Donation session management (amount + type stored in WC session)
- Round-up calculation logic
- AJAX handler for updating donation selection (nonce-verified, session-scoped)
- Donation meta persistence to order (`_nte_donation_amount`, `_nte_donation_type`)
- Admin display of donation info on order screen

**WC owns:**
- Cart fee system (NTE adds donation as non-taxable fee via `WC()->cart->add_fee()`)
- Checkout form rendering (NTE injects after order notes)
- Session storage (NTE uses `WC()->session->set/get`)

**Data flow:**
- NTE -> WC: donation amount as cart fee, donation meta on order
- WC -> NTE: session storage for donation state, `$_POST` for AJAX updates, cart subtotal for round-up calculation

**Donation is only shown when cart contains event tickets.** The `is_enabled()` check requires both the setting to be on AND `cart_has_tickets()` to be true.

### Blocks / Store API (BlockIntegration, StoreApiExtension)

**NTE owns:**
- Store API extension: registers `nettertech-events` namespace on `CartItemSchema`
- Extension data: `is_event_ticket`, `event_date`, `event_time`, `venue_name`, `ticket_type`, `event_permalink`, `event_title`
- Extension schema: JSON schema for the above fields (all read-only strings/boolean)
- Frontend script: `nte-wc-blocks.js` (IIFE, no build step) renders event metadata in Block cart/checkout
- Script data: namespace identifier, donations enabled flag
- Block checkout accessibility notes: saved via Store API update callback -> WC session -> order meta

**WC owns:**
- Store API infrastructure, Block cart/checkout rendering
- `IntegrationInterface` contract, `woocommerce_store_api_register_endpoint_data` API
- Block registration system (`woocommerce_blocks_cart_block_registration`, `woocommerce_blocks_checkout_block_registration`)

**Data flow:**
- NTE -> WC Store API: cart item extension data (read-only, computed from NTE models)
- WC -> NTE: update callback data (accessibility notes from Block checkout)
- NTE -> WC: accessibility notes from session to order meta on `woocommerce_store_api_checkout_update_order_from_request`

### Accessibility (AccessibilityNotesHandler)

**NTE owns:**
- Field rendering: textarea on classic checkout (via `woocommerce_after_order_notes`)
- Field saving: sanitize and store `_nte_accessibility_notes` on order meta (classic checkout via `$_POST`, Block checkout via WC session)
- Admin display: yellow-bordered panel in order view

**WC owns:**
- Checkout form rendering, nonce verification (NTE relies on WC's checkout nonce)
- Order meta storage

**Data flow:**
- NTE -> WC: checkout field HTML, order meta
- WC -> NTE: `$_POST` data on classic checkout

**Block checkout path:** accessibility notes go through Store API update callback -> WC session (`nte_accessibility_notes`) -> order meta on `woocommerce_store_api_checkout_update_order_from_request`.

## Satellite Plugin Extensions

### Rentals (nettertech-events-rentals)

**Architecture:** Completely independent WC integration layer. Does not extend or depend on the base plugin's `WooCommerceIntegration`. Uses its own `RentalProductManager`, `RentalCartHandler`, and `RentalOrderHandler`.

**Product model:** Each booking phase (deposit, balance, full) gets a separate `WC_Product_Simple`. Products are hidden, virtual, sold individually, and unmanaged stock. Products carry rental-specific meta: `_nte_rental_booking_id`, `_nte_rental_space_id`, `_nte_rental_payment_type`, `_nte_rental_is_booking`.

**Cart hold system:** Uses transients (`nte_rental_cart_hold_{booking_id}`) with 30-minute TTL. Tracked in `nte_rental_active_holds` option for cron cleanup. Holds are created on add-to-cart validation and cleared on item removal or order completion.

**Order processing:** Idempotent via `_nte_rental_booking_processed` order meta. On payment complete:
- **Full payment:** booking transitions to Confirmed, order set to completed
- **Deposit:** booking transitions to DepositPaid, order set to `wc-deposit-paid` (custom status)
- **Balance:** booking transitions to Confirmed, order set to completed

**Cancellation:** Source-aware. User/admin session -> booking cancelled. No session (gateway-initiated) -> booking placed on hold for admin review.

**Coupon exclusion:** Rental products are excluded from all WC coupons via `woocommerce_coupon_is_valid_for_product` filter.

**Product deletion guard:** Products linked to active (non-terminal) bookings cannot be deleted.

**Hooks shared with base plugin:** Both Rentals and the base plugin hook into `woocommerce_payment_complete`, `woocommerce_order_status_processing`, `woocommerce_order_status_cancelled`, `woocommerce_order_status_refunded`, `woocommerce_add_to_cart_validation`, `woocommerce_get_item_data`, and `woocommerce_cart_item_removed`. Each handler checks its own product type flag (`_nte_is_event_ticket` vs `_nte_rental_is_booking`) and returns early for non-matching products.

### Seating (nettertech-events-seating)

**Architecture:** Extends the base plugin's WC integration via hooks. Consumes the base plugin's `nte_attendee_created` and `nte_registration_voided` hooks to bridge between WC order lifecycle and seat assignment lifecycle.

**Seat hold lifecycle:** (Updated 2026-03-30, C3 + C8)
1. **Selection:** Seat selector REST API creates hold on seat click (not a WC hook)
2. **Add to cart - validation:** Two-phase validation: Base validates at priority 10 (event active, capacity). Seating checks seat availability at priority 15 (read-only, no hold creation). (C3)
3. **Add to cart - hold creation:** Holds created on `woocommerce_add_to_cart` action (fires only after all validators pass). ConflictException handles TOCTOU races. Zero orphaned holds. (C3)
4. **Cart item data:** seat IDs, GA selections, and a hash (to prevent WC merging) attached to cart item
5. **Quantity lock:** seated cart items cannot have quantity changed (replaced with static display)
6. **Pre-checkout validation:** `woocommerce_check_cart_items` verifies all holds still active
7. **Checkout:** holds upgraded to checkout type (60min TTL, filterable, linked to order ID) via `woocommerce_checkout_order_processed` / `woocommerce_store_api_checkout_order_processed` (C8)
8. **Gateway-aware behavior (C8):**
   - **Real-time gateways:** enforce 60min TTL; expired holds swept by cron
   - **Offline gateways** (BACS/cheque/COD, detected via `is_offline_gateway()`, filterable via `nte_seating_is_offline_gateway`): immediate seat assignment on order creation (no TTL wait)
9. **Payment complete:** base plugin creates attendee, fires `nte_attendee_created`; Seating converts holds to permanent assignments, links to ticket IDs
10. **Cancel/refund:** Seating releases both holds (real-time) and assignments (offline) via `woocommerce_order_status_cancelled`, `woocommerce_order_status_failed`, and `nte_registration_voided` (C8)

**Order item meta:** `_ve_seat_ids` (array of int), `_ve_occurrence_id` (string), visible "Seats" label. Written at priority 20 (after base plugin's priority 10 writes ticket type/occurrence meta).

**[RESOLVED]** The `_ve_` prefix is unintentional (rebrand miss). Migrate all `_ve_` prefixed meta to `_nte_` with activation/update migration for existing order data. Applies across all plugins.

**Cross-plugin filter dependency:** Seating relies on core NTE providing `nte_seating_ticket_capacity_type`, `nte_seating_resolve_occurrence`, and `nte_seating_resolve_space` filters. Without these filters being handled, the seat selector will not render and seated cart validation will not activate. CapacityTypeProvider now uses `TicketTypeRepositoryInterface` (C7) rather than direct `$wpdb`.

**Partial failure handling:** If seat assignment fails for some seats after payment, holds are preserved (not deleted), an order note is added, an admin email is sent via `nte_seating_assignment_partial_failure`, and the order is NOT blocked. This is a conscious decision -- payment was collected, manual resolution is preferred over automated rollback.

**Product page integration:** `SeatingProductIntegration` hooks into `woocommerce_before_add_to_cart_button` to render the interactive seat selector. It resolves the chain: product -> ticket type -> capacity type -> occurrence -> space -> seating map. Assets are enqueued only when a seated ticket is detected.

## Resolved Design Decisions

The following items have been reviewed and resolved:

1. **[RESOLVED] NTE capacity is independent of WC stock (one-way mirror).** One-way sync is correct by design. Additionally: hide the WC stock field on NTE-managed products and replace with a notice + link to NTE capacity editor. See task #13.

2. **[RESOLVED] ProductManager uses `update_post_meta()` for NTE meta on WC products.** Migrate to WC CRUD API (`$product->update_meta_data()` + `$product->save()`). The performance argument doesn't hold for admin-frequency operations. Future-proofing and ADR-008 consistency take priority. See task #17.

3. **[RESOLVED] Seating order item meta uses `_ve_` prefix.** The `_ve_` prefix is unintentional (rebrand miss). Migrate all `_ve_` prefixed meta to `_nte_` with activation/update migration for existing order data. Applies across all plugins.

4. **[IMPLEMENTED] Seating cart validation runs at priority 5 (before base at 10).** (C3, 2026-03-30) Two-phase hold pattern adopted. Seating validation moved to priority 15 (after base validates at 10). Hold creation moved to `woocommerce_add_to_cart` action (fires only after all validators pass). ConflictException handles TOCTOU races. Zero orphaned holds.

5. **[RESOLVED] Oversell detection at payment time does not block order processing.** Partial fulfillment is correct (other items succeed). BUT the failure must notify admin + customer and prevent billing for the failed item. Currently fires `nte_attendee_creation_failed` hook with nothing listening. P0 fix required. See task #23.

6. **[RESOLVED] Rentals gateway-initiated cancellation puts booking on hold.** On-hold behavior is correct (gateway cancellation ≠ user cancellation). BUT must add admin email notification, customer notification, dashboard visibility for on-hold bookings, and 48h escalation reminder. See task #32.

7. **[RESOLVED] Both base plugin and Rentals hook into the same WC order lifecycle hooks.** Currently untested with mixed carts. Add integration tests covering all meaningful permutations (ticket-only, rental-only, mixed, seated, triple). See task #19.
