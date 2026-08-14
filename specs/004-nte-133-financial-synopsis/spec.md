# Feature Specification: Event financial sales synopsis + unified WC-order-totals accounting (NTE-133)

**Feature Branch**: `fix/event-scoped-ticketed-detection` (base) + Pro repo dev branch — template-fusion fallback; NOT `speckit-specify`
**Created**: 2026-07-01
**Status**: Draft
**Ticket**: NTE-133 (P2), spans nettertech-events (base) + nettertech-events-pro
**Input**: Operators need, atop an event's Purchases page, a financial synopsis (parity with Event Tickets "Orders"): per-ticket-type sold counts + amounts, discounts, refunds, failed, and a headline TOTAL — "exactly how much money for this event." Plus: unify all NTE revenue accounting on WooCommerce order totals as the single source of truth.

> **Pilot Note (FW-018 template-fusion).** Nested-repo workspace; `speckit-*` scripts branch-gated. Hand-authored from SpecKit templates. Slug `004-nte-133-financial-synopsis` (base plugin specs/ convention; the Pro components live in the Pro repo).

> **Operator decisions (locked 2026-07-01):**
> 1. **Revenue source of truth = WooCommerce order totals** (NOT `tickets.price_paid`).
> 2. **Headline = GROSS**, with discounts + refunds itemized below and a **net** figure at the bottom (invoice-style).
> 3. **"Failed" = WC orders in `failed` status** only (payment failures), containing the event's ticket products.
> 4. **Recurring events roll up** across occurrences with an occurrence **drill-down** (reuse NTE-118's occurrence dropdown).
> 5. **Unify:** migrate Pro's existing `RevenueReportService` + Reports page from `price_paid` to WC order totals so the synopsis and the Reports page report ONE number (the ticket's "no divergent math" requirement).

## User Scenarios & Testing *(mandatory)*

### User Story 1 — One glance = exactly how much money this event made (Priority: P1)

An operator opens an event's Purchases page and sees, above the attendee list: per-ticket-type sold count + gross amount, total **gross**, itemized **discounts** (coupons) and **refunds**, a **failed**-orders count, and a reconciled **net** total.

**Why this priority**: This is the reported gap (parity with the old Event Tickets "Orders" report). NTE-118 built the page with attendee status-counts only ("no revenue"); this adds the money.

**Independent Test**: Seed an event with 2+ ticket types, at least one completed order, one order with a coupon, one refunded order, and one failed order. Open the Purchases page; confirm each line (per-type counts+amounts, gross, discounts, refunds, failed count, net) matches the WC orders.

**Acceptance Scenarios**:
1. **Given** an event with completed WC ticket orders, **When** the Purchases page loads, **Then** gross = sum of the event's ticket line-item totals across completed orders, broken down per ticket type.
2. **Given** an order with a coupon on this event's tickets, **When** the synopsis renders, **Then** the discount amount is itemized and the net reflects gross − discount.
3. **Given** a refunded order, **When** the synopsis renders, **Then** the refund amount is itemized and net reflects it.
4. **Given** a WC `failed`-status order with this event's tickets, **When** the synopsis renders, **Then** it appears in the failed count (with attempted amount), and is EXCLUDED from gross and net.

### User Story 2 — The synopsis and the Pro Reports page agree (Priority: P1)

The per-event synopsis and Pro's global Reports page show the **same** revenue for the same event.

**Why this priority**: The ticket requires no divergent revenue math. Both must derive from one implementation on the WC-order-totals basis.

**Independent Test**: For one event, compare the synopsis net/gross to the Reports page figure for that event — they match to the cent.

**Acceptance Scenarios**:
1. **Given** the migrated `RevenueReportService` (WC-order-totals basis), **When** both the synopsis and the Reports page render the same event, **Then** the figures are identical (same code path).

### User Story 3 — Recurring events roll up with drill-down (Priority: P2)

A recurring event shows event-level totals by default, with an occurrence drill-down.

**Why this priority**: Operators want "how much did this whole event make" plus per-date detail; consistent with NTE-118's occurrence dropdown already on the page.

**Independent Test**: A recurring event with sales on 2 occurrences shows the summed event total; selecting an occurrence scopes the synopsis to that date.

### Edge Cases

- **Behavior change to a shipped feature**: existing customers' Reports page numbers WILL shift (price_paid → WC totals differ once discounts/refunds/partial payments exist). Requires a changelog note; consider a one-line in-UI note on first view post-upgrade.
- **HPOS**: order/line-item/refund reads must be HPOS-aware (use WC CRUD `wc_get_orders`/`$order->get_items()`/refund objects, not raw postmeta).
- **Partial refunds**: refund amount = sum of WC refund objects attributable to the event's line items (not whole-order).
- **Coupon spanning multiple events**: attribute the discount to this event's line items proportionally (or per WC's per-line discount) — define precisely.
- **Order with mixed content** (event tickets + non-ticket products): count only the event's ticket line items.
- **Free tickets / $0**: counted in sold counts, $0 in gross.
- **Pro absent / unlicensed**: base shows only the existing status counts (graceful; no fatal).
- **On-hold / pending orders**: not gross (not completed), not failed — either omit or show as a separate "in progress" line (decide; default: omit for v1, note it).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Revenue MUST be computed from **WooCommerce order totals** — the event's ticket-product line items across orders — NOT from `tickets.price_paid`. HPOS-aware via WC CRUD.
- **FR-002**: Pro's `RevenueReportService` MUST be **migrated to the WC-order-totals basis** via a **single shared WC-totals aggregation primitive** that ALL public methods delegate to (one implementation, no divergent math). Grounding correction (2026-07-01): **FIVE** methods currently query `tickets.price_paid`, not the three the draft named — `get_event_revenue` (RevenueReportService.php:147), `get_occurrence_revenue` (:224/:239), `get_date_range_report` (:385), **`get_date_range_summary` (:467)**, and **`generate_revenue_csv` (:543/:602)**. All five MUST migrate; leaving the Reports summary line (`get_date_range_summary`) or the CSV export (`generate_revenue_csv`) on `price_paid` would make them diverge from the synopsis — a direct FR-002 violation. Sole production consumer today is `ReportsPage` (date-range trio + CSV); `get_event_revenue`/`get_occurrence_revenue` are tested-but-unwired and the synopsis becomes their first production caller. Existing `RevenueReportServiceTest` (26 tests) rewritten to the new basis; the **SEC-001 reflection test** (asserts `->prepare()` / no `implode+intval` IN-clause) MUST still pass — preserve the prepared-statement pattern.
- **FR-003** *(gross basis corrected 2026-07-01)*: The synopsis headline is **GROSS = pre-discount face value = Σ `WC_Order_Item::get_subtotal()`** over the event's completed ticket lines (invoice-style, per the locked operator intent), with **discounts** (Σ `get_subtotal() − get_total()`) and **refunds** (WC refund objects) itemized below, and **net = gross − discounts − refunds** at the bottom (which resolves to Σ `get_total()` − refunds = actual money received). ⚠ Do NOT set gross = Σ `get_total()`: `get_total()` is already post-discount, so subtracting discounts again double-counts them (verified against WC 10.6.2 source: get_subtotal = "before discounts", get_total = "after discounts"). Per-ticket-type breakdown (count + gross amount, gross = subtotal basis).
- **FR-004**: **Failed** = WC orders in status `failed` containing the event's ticket products — shown as a count + attempted amount, **excluded** from gross and net.
- **FR-005**: Recurring events **roll up** across occurrences with an occurrence **drill-down** reusing NTE-118's occurrence dropdown on the Purchases page.
- **FR-006**: The synopsis renders atop the Purchases page via a **base extension hook** on `AttendeesPage`; the renderer is **Pro-gated** (`FEATURE_REVENUE_REPORTS`).
- **FR-007**: With Pro inactive/unlicensed, base shows only the existing attendee status-counts (graceful absence; no fatal).
- **FR-008** *(LOCKED 2026-07-01)*: Gross-eligible order statuses = **`completed` + `processing`** (realized revenue), exposed via a new filter **`nettertech_events_revenue_paid_statuses`** (mirrors the existing `nettertech_events_pro_privacy_paid_order_statuses` precedent in `PrivacyService.php:492`, minus `on-hold`). **Failed** = `failed` only. **Omitted from v1**: `on-hold` (pre-payment), `pending`, `cancelled`.

### Key Entities

- **WC Order + line items** — the source of truth. For an event: the orders whose line items reference the event's ticket products (`_nettertech_events_is_event_ticket` / occurrence/ticket-type meta on the line item, per `CartPresenter`).
- **WC Refund** — refund objects attributable to the event's line items.
- **Ticket type** — the per-type breakdown grouping (from the line item's ticket-type meta).
- **`RevenueReportService`** (Pro) — migrated to WC-order-totals; the single revenue implementation feeding both the synopsis and the Reports page.

## Success Criteria *(mandatory)*

- **SC-001**: On a seeded event (2 types, a coupon, a refund, a failed order), the synopsis shows correct per-type counts+amounts, gross, itemized discounts + refunds, failed count, and net — matching the WC orders to the cent (browser-verified on a working WC env).
- **SC-002**: The synopsis figure and the Reports page figure for the same event are identical.
- **SC-003**: Pro-absent → base shows status counts only, no fatal.
- **SC-004**: Recurring event rolls up + drill-down works.
- **SC-005**: `RevenueReportService` migration keeps its suite green (assertions updated to WC-totals basis); base + Pro suites green.

## Assumptions

- **[DECISION LOCKED]** WC order totals is the single source of truth; Pro's Reports page migrates to it (behavior change — changelog required).
- Completed-revenue status set defaults to WC `completed` (+ `processing` if the site treats it as paid — confirm at build). On-hold/pending omitted from v1 (noted).
- oz's WC checkout env is degraded → end-to-end money browser-verification likely needs a celticjunction.org clone or a fixed WC env; automated tests carry the correctness proof.
- Discount attribution follows WC's per-line-item discount where available; proportional fallback documented.
