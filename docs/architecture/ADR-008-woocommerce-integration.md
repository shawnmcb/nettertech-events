# ADR-008: WooCommerce Integration via CRUD API

**Date:** 2025-01-20
**Status:** Accepted
**Category:** Integration

## Context

NetterTechEvents uses WooCommerce for payment processing. Ticket types are mapped to WooCommerce products, and ticket purchases go through the WooCommerce checkout flow. The plugin must:

1. Create/sync WooCommerce products for ticket types
2. Process order completion → create attendees and tickets
3. Handle refunds → cancel tickets, release capacity
4. Work with WooCommerce HPOS (High-Performance Order Storage)

## Decision

**Use WooCommerce's CRUD API exclusively for all order and product operations. No raw SQL against WooCommerce tables.**

### Integration Points

```
Integrations/WooCommerce/
  ├── ProductManager.php         # Ticket type ↔ WC product sync
  ├── CartHandler.php            # Cart validation, capacity checks
  ├── OrderHandler.php           # Order lifecycle orchestration
  ├── OrderAttendeeCreator.php   # Order → attendee + ticket creation
  ├── OrderRefundProcessor.php   # Refund → ticket cancellation
  ├── OrderCapacityValidator.php # Payment-time capacity verification
  └── CheckoutHandler.php        # Checkout field customization
```

### Order Lifecycle

```
Cart Add → CartHandler::validate_add_to_cart()
         → Capacity check + reservation hold

Checkout → CheckoutHandler::add_attendee_fields()
         → Collect attendee name/email per ticket

Payment  → OrderHandler::handle_order_completed()
         → OrderCapacityValidator::validate()
         → OrderAttendeeCreator::create_attendees()
         → EmailService::send_confirmation()

Refund   → OrderHandler::handle_order_refunded()
         → OrderRefundProcessor::process()
         → Capacity released, tickets cancelled
```

### HPOS Compatibility

- All WooCommerce interactions use `wc_get_order()`, `$order->get_meta()`, `$order->update_meta_data()`.
- Zero raw SQL against `wp_posts`, `wp_postmeta`, or `wc_orders` tables.
- Plugin declares HPOS compatibility via `before_woocommerce_init` hook.
- Exception: Migrator repair tools use raw SQL with HPOS detection (see ADR in architecture docs).

### Product Sync

- Each ticket type maps to a WooCommerce Simple Product.
- Product price, stock quantity, and stock status sync bidirectionally.
- `ProductManager` creates products on ticket type creation and updates on changes.
- WooCommerce product is the source of truth for price; plugin is source of truth for capacity.

## Alternatives Considered

### Direct Payment Processing (Stripe/PayPal SDK)

- **Pros**: No WooCommerce dependency. Lighter weight.
- **Cons**: Must build checkout UI, payment form, PCI compliance, refund handling, receipt emails, tax calculation, coupon/discount system. WooCommerce already handles all of this.

### WooCommerce Bookings Plugin

- **Pros**: Built for date-based booking with capacity.
- **Cons**: Opinionated about data model (resources, persons, buffers). Doesn't fit venue events model (occurrences, ticket types, check-ins). License cost. Dependency on third-party plugin updates.

### Custom Order Tables (Bypass WooCommerce)

- **Pros**: Full control over order data model.
- **Cons**: Lose WooCommerce payment gateways (100+), tax system, coupon system, order management UI, email system, reporting, accounting integrations.

## Consequences

- **Positive**: Leverages WooCommerce's payment infrastructure. 100+ payment gateways available. Tax, coupons, and reporting work out of the box. HPOS-compatible from day one.
- **Negative**: WooCommerce is a heavy dependency (~25MB). Plugin must handle WooCommerce not being active (graceful degradation to RSVP-only mode). Order lifecycle hooks are complex and version-sensitive.
- **Mitigations**: WooCommerce integration layer is isolated in `Integrations/WooCommerce/`. Plugin activates and functions without WooCommerce — ticket types just can't be purchased. Feature detection via `class_exists('WooCommerce')`.

## Related

- `includes/Integrations/WooCommerce/`: All WooCommerce integration code
- `docs/openapi.yaml`: API endpoints affected by WooCommerce state
- `includes/Services/CapacityService.php`: WooCommerce stock sync
