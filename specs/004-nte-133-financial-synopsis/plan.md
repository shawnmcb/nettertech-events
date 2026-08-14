# Implementation Plan: Financial synopsis + unified WC-order-totals accounting (NTE-133)

**Spec**: `./spec.md` | **HITL**: Tier 2 (WooCommerce + **behavior change to a shipped Pro feature**) | **Repos**: base (hook) + Pro (renderer + RevenueReportService migration)
**Pilot note**: template-fusion (no `speckit-plan`).

## Constitution / Invariants check

- **WooCommerce coupling + payment-path reads (High risk)**: all order/line-item/refund reads via WC CRUD (HPOS-aware); no raw postmeta.
- **Behavior change**: migrating `RevenueReportService` off `price_paid` changes existing Reports numbers → changelog entry + test-assertion updates are in-scope, not optional.
- **Marketplace**: revenue is Pro-gated (`FEATURE_REVENUE_REPORTS`); base only exposes a null-safe extension hook (WP.org Guideline 5 — no premium code in base).
- **Naming**: `nettertech_events_` for new hooks/identifiers; `array()` long syntax; named callbacks.

## Approach

Build ONE revenue implementation on WC order totals, used by both the Reports page and the new synopsis. Base exposes a hook on the Purchases page; Pro renders the synopsis.

### Components

1. **`RevenueReportService` migration (Pro)** — `includes/Pro/Reports/RevenueReportService.php`
   - Replace the `tickets.price_paid` SUM basis with WC-order-totals aggregation. For an event/occurrence: find the orders whose line items reference the event's ticket products (line-item meta `MetaKeys::EVENT_ID` / `OCCURRENCE_ID` / `TICKET_TYPE_ID`, set by base `CartPresenter::add_order_item_meta`), then sum line-item totals by ticket type, plus discounts, refunds, and failed.
   - Query orders HPOS-aware via `wc_get_orders()` (filter by the event's product IDs or by a meta/line-item scan) — decide the most efficient path at build (a product-id `IN` filter on line items, or a stored event→order index). Cache per event (transient), invalidate on order status change.
   - Extend the return shape: `gross`, `discounts`, `refunds`, `net`, `failed{count, attempted}`, `ticket_type_breakdown[{name, count, gross}]`, `occurrences[]` for roll-up.
   - Keep method names (`get_event_revenue`, `get_occurrence_revenue`, `get_date_range_report`) so the Reports page keeps working; update its display to the new fields.
   - **Update `RevenueReportServiceTest`** to the WC-totals basis (seed WC orders/refunds, assert against them).

2. **Base extension hook** — `includes/Admin/AttendeesPage.php`
   - After the existing status-counts summary (from NTE-118), fire `do_action('nettertech_events_purchases_synopsis', $event_id, $occurrence_id)` (or a filter that returns rendered HTML). Null-safe: base renders nothing extra if nothing hooks it.

3. **Pro synopsis renderer** — new `includes/Pro/Reports/EventSynopsisRenderer.php`
   - Hooks the base action; gated by `FEATURE_REVENUE_REPORTS`. Calls `RevenueReportService::get_event_revenue($event_id)` (or occurrence variant for drill-down), renders: per-type count+gross, gross headline, itemized discounts + refunds, failed count+attempted, net. Reuses NTE-118's occurrence dropdown value for drill-down.
   - `/wp-frontend` for the markup (admin UI, escaping, a11y).

### Revenue status semantics (enumerate at build)

- **Gross-eligible statuses**: `wc-completed` (+ `wc-processing` if the site treats it paid — config or filter `nettertech_events_revenue_paid_statuses`).
- **Failed**: `wc-failed` only.
- **Refunds**: WC refund objects on gross-eligible orders, attributed to the event's line items.
- **Omitted (v1)**: `wc-on-hold`, `wc-pending`, `wc-cancelled` (note in UI-less docs).

### Security / data / rollback

- Read-only reporting; no writes except cache transients. Cap-gated by the Purchases page (`edit_posts` / manage). No new REST.
- Rollback = revert code; the migration is display/computation only (no schema/data change). The Reports-number shift reverts with the code.

### Testing strategy

- **Pro unit/integration**: `RevenueReportServiceTest` rewritten to seed WC orders/coupons/refunds/failed and assert gross/discounts/refunds/net/failed/breakdown; occurrence roll-up.
- **Base unit**: the `AttendeesPage` hook fires with correct args; Pro-absent renders nothing.
- **Reconciliation test**: synopsis and Reports page derive identical figures for one event.
- **Browser** (SC-001): needs a working WC checkout — oz is degraded, so likely a celticjunction.org clone or a fixed WC env; automated tests are the primary correctness proof.

## Open items for tasks.md / DECISION-REQUIRED

- **Order→event query strategy** (efficiency): line-item product-id `IN` filter vs. a maintained event→order index. Ground at build.
- **Discount attribution** for multi-event coupons: per-line WC discount vs. proportional. Define.
- **Paid-status set**: `completed` only, or `+processing`? (filterable default).
- **Changelog + upgrade note** for the Reports-number behavior change.
