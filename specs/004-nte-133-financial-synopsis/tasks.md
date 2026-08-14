# Tasks: Financial synopsis + unified WC-order-totals accounting (NTE-133)

**Spec**: `./spec.md` | **Plan**: `./plan.md` | **HITL**: Tier 2 (behavior change to shipped Pro feature) | Repos: base + Pro
**Pilot note**: template-fusion. `[P]` = parallelizable.

## Phase 0 — Grounding (do first)

- **T001**: Read current `RevenueReportService` (get_event_revenue/get_occurrence_revenue/get_date_range_report) + `RevenueReportServiceTest`; document the exact price_paid basis being replaced. `[read]`
- **T002**: Confirm how order line items carry event scope — `CartPresenter::add_order_item_meta` sets `MetaKeys::EVENT_ID`/`OCCURRENCE_ID`/`TICKET_TYPE_ID` on the WC line item; confirm `_nettertech_events_is_event_ticket` on the product. `[read]`
- **T003** *(RESOLVED 2026-07-01, lead + operator)*: (a) **Query strategy** = `wc_get_orders()` filtered by the event's ticket **product IDs** (from base `ProductManager`), HPOS-aware; no new event→order index (greenfield — none exists); per-event transient cache (existing `TTL_REPORT` pattern). Query by product ID, NOT by scanning `EVENT_ID` line-meta (that meta is written only when the occurrence resolves — CartPresenter.php:149-151 — so it's absent on some migrated products; product-id filter is robust). (b) **Discount attribution** = WC-native per line-item `get_subtotal() − get_total()`, summed over the event's lines (exact, not proportional). (c) **Paid-status** = `completed` + `processing`, filter `nettertech_events_revenue_paid_statuses`. `[verify]`

## Phase 1 — Revenue engine migration (Pro, foundational)

- **T009**: Build the **shared WC-totals aggregation primitive** (private) that resolves an event/occurrence → its ticket product IDs → `wc_get_orders()` (paid statuses) → per-line-item **gross (`get_subtotal()` — pre-discount face value; NOT `get_total()`, which double-counts discounts)**, discount (`get_subtotal() − get_total()`), refunds (WC refund objects, event-line-attributed via `get_total_refunded_for_item()`), failed{count,attempted} (`failed` status), grouped by ticket type. net = gross − discounts − refunds = Σ`get_total()` − refunds. This is the ONE implementation every public method delegates to. HPOS-aware; verify line-item/refund API against **live WC 10.6.2** (Pro stubs pin ^8.5 — stale). `[create]` (depends T001-T003)
- **T010**: Migrate `get_event_revenue()` + `get_occurrence_revenue()` to delegate to T009: extend return shape with gross, discounts, refunds, net, failed{count,attempted}, ticket_type_breakdown, occurrences roll-up. Per-event transient cache + invalidation on order status change. `[edit]` (depends T009)
- **T011**: Migrate the remaining three to the SAME primitive: `get_date_range_report()`, **`get_date_range_summary()`** (:467), **`generate_revenue_csv()`** (:543 — CSV amounts now WC-totals, not `price_paid`). All five methods share T009's math. `[edit]` (depends T009, T010)
- **T012**: Rewrite `RevenueReportServiceTest` (26 tests) on the WC-totals basis (seed WC orders/coupons/refunds/failed; assert every field + roll-up). **Preserve the SEC-001 reflection test** (prepared-statement guardrail). `[create]` (depends T010, T011)
- **T013**: Update the existing Pro **Reports page** display (`ReportsPage.php` — `render_summary`/`render_report_table`, `get_date_range_summary`/`get_date_range_report` consumers) to the new fields (gross/discounts/refunds/net/failed); confirm it still renders. `[edit]` (depends T010, T011)

## Phase 2 — Base hook

- **T020**: Add `nettertech_events_purchases_synopsis` extension point on `AttendeesPage` after the status-counts summary (null-safe; base renders nothing if unhooked). `[edit]`
- **T021**: Unit test: hook fires with (event_id, occurrence_id); Pro-absent → no extra output, no fatal. `[create, P]` (depends T020)

## Phase 3 — Pro synopsis renderer

- **T030**: New `EventSynopsisRenderer` — hooks the base action, gated by `FEATURE_REVENUE_REPORTS`; renders per-type count+gross, gross headline, itemized discounts + refunds, failed, net; occurrence drill-down via NTE-118's dropdown. `/wp-frontend` markup (escape, a11y). `[create]` (depends T010, T020)
- **T031**: Unit test the renderer output (given a RevenueReportService result, asserts the lines + net). `[create, P]` (depends T030)
- **T032**: Reconciliation test: synopsis and Reports page derive identical figures for one seeded event. `[create]` (depends T013, T030)

## Phase 4 — Gates, changelog, verification

- **T040**: base + Pro `composer phpcs && phpstan && test` green (Pro suite updated; base suite ≥ baseline). `[run]` (depends all)
- **T041**: **Changelog + upgrade note** for the Reports-number behavior change (price_paid → WC totals). `[edit]` (depends T013)
- **T042**: Browser (SC-001) on a working WC env (celticjunction.org clone or fixed oz WC): seeded event with 2 types + coupon + refund + failed → synopsis matches WC to the cent. If no working WC env, mark BLOCKED and rely on integration tests. `[verify]` (depends T040)
- **T043**: /self-review + /safeguard; commit base + Pro on their branches. `[run]` (depends T040)

## Out of scope

- Cross-event/global financial reporting beyond the existing Reports page.
- Tax breakdowns beyond what WC order totals expose.
- Accounting-software export.

## Notes
- **NTE-134 (rate-limit IP-spoof)** runs as a parallel Wave-3 sidecar chunk (independent files — `RateLimitService`).
- Pro repo dev branch TBD (confirm at build, like the base branch strategy).
- oz WC env degraded → browser money-check likely deferred to a working-WC env.
