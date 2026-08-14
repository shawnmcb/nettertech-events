# Feature Specification: Ticket products carry WooCommerce product categories (coupon-by-category)

**Feature Branch**: `fix/event-scoped-ticketed-detection` (existing dev HEAD — template-fusion fallback; NOT `speckit-specify`)
**Created**: 2026-06-29
**Status**: Draft
**Ticket**: NTE-129 (P1)
**Input**: Member coupon restricted to product categories ("Concerts", "Performances") does not apply to NTE ticket purchases; the operator must list each ticket product on the coupon individually. Root cause (source-verified): `ProductManager::sync_product()` never assigns a WooCommerce `product_cat` to ticket products, so a category-scoped coupon has nothing to match.

> **Pilot Note (FW-018 template-fusion).** Nested-repo workspace: `.specify/` is at the non-git workspace root, above the plugin's git repo, so the `speckit-*` scripts mis-resolve the repo root and are branch-gated. Artifacts hand-authored in the plugin repo from the SpecKit templates. Canonical slug intended `003-nte-129-product-category` (the dry-run script mis-numbered against the workspace-root `specs/`; the plugin's `specs/` already has 001 + 002).

> **Operator decision (locked 2026-06-29).** Mapping approach = **name-mirror with a stable term-meta back-reference** (rename-safe). Chosen over an admin mapping UI (more build/upkeep, settings-file overlap with NTE-127) and a single default category (cannot satisfy the Concerts-vs-Performances split). See FR-002/FR-003.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — A category-scoped coupon discounts NTE tickets without per-product listing (Priority: P1)

A member coupon is restricted to product categories "Concerts" and "Performances". A customer buys a ticket for an event in the "Concerts" category and applies the coupon.

**Why this priority**: This is the reported defect (Emily Flagstad, celticjunction.org). The current workaround — adding each ticket product to the coupon by ID — does not scale (the Hooley has many ticket products) and silently breaks for any ticket type the operator forgets to add.

**Independent Test**: Create a WC product category "Concerts"; create an NTE event in the corresponding event category with a ticket type (→ a synced ticket product); create a coupon restricted to product category "Concerts"; add the ticket to cart and apply the coupon. The discount applies **without** the product being listed individually on the coupon.

**Acceptance Scenarios**:
1. **Given** an NTE event in event-category "Concerts" with a synced ticket product, and a WC coupon restricted to product category "Concerts", **When** the ticket is in the cart and the coupon is applied, **Then** the discount applies.
2. **Given** an event in event-category "Performances" only, **When** the same Concerts-only coupon is applied to its ticket, **Then** the coupon does NOT apply (correct scoping).
3. **Given** an event assigned to multiple event categories, **When** its ticket product is synced, **Then** the product belongs to all the corresponding `product_cat` terms.

### User Story 2 — Renaming a category (either taxonomy) keeps the coupon working (Priority: P2)

An operator renames the event category "Concerts" → "Concert Series", or renames the WC product category directly.

**Why this priority**: Pure literal-name matching would orphan the `product_cat` (and any coupon scoped to it) on rename. The suite has a history of rename-drift bugs; the mapping must survive renames on either side.

**Independent Test**: With a synced ticket product in product-category "Concerts" and a coupon scoped to it, rename the event category to "Concert Series". Re-sync. The linked `product_cat` is renamed to match (or remains linked), the coupon still matches the ticket, and no orphaned/duplicate `product_cat` is created.

**Acceptance Scenarios**:
1. **Given** a synced ticket product linked to product-category "Concerts" via the back-reference, **When** the event category is renamed and products re-sync, **Then** the SAME `product_cat` term is reused (resolved by back-ref ID, not name) and its display name updated — no new term created.
2. **Given** the operator renames the `product_cat` directly, **When** products re-sync, **Then** the back-reference still resolves and the coupon keeps working.

### User Story 3 — Hidden ticket products do not leak into the storefront (Priority: P2)

Assigning a `product_cat` must not surface internal ticket products on the public shop / category archive pages.

**Why this priority**: Ticket products are `catalog_visibility = 'hidden'`. Assigning them a public product category must not make them appear on `/product-category/concerts/` storefront listings.

**Independent Test**: After assigning the Concerts `product_cat` to a hidden ticket product, view the public Concerts product-category archive. The ticket product does NOT appear.

### Edge Cases

- **Category removed from an event**: on re-sync, the product is removed from the corresponding `product_cat` (managed set), but NTE-owned removals must not touch product categories added manually by the operator.
- **Event with no categories**: product carries no NTE-managed `product_cat` (no error).
- **Pre-existing `product_cat` of the same name not created by NTE** (e.g. operator already made "Concerts"): the sync should ADOPT it — link via back-ref to the matching name on first encounter rather than creating a duplicate — and thereafter resolve by back-ref. (Decision: adopt-by-name on first link, then ID-stable.)
- **Existing already-synced products** (celticjunction.org): have no `product_cat` until a backfill runs — covered by FR-007.
- **Coupon evaluation itself**: unchanged — WooCommerce core matches `product_categories` against line-item `product_cat`. No NTE coupon code.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `ProductManager::sync_product()` MUST assign WooCommerce `product_cat` terms to the ticket product (via `$product->set_category_ids()`), derived from the linked event's categories (`nettertech_event_category`), before `$product->save()`.
- **FR-002**: Each NTE-managed `product_cat` term MUST carry a stable back-reference term meta `_nettertech_events_source_category` = the source event-category term ID. The mapping is resolved by this ID, **never by the name string**.
- **FR-003**: Resolution MUST be: find the `product_cat` whose back-ref meta == the event-category term ID; if none, adopt an existing same-named `product_cat` (set its back-ref) or create one; sync the term NAME as a display value (rename on either side reconciles via the back-ref, never orphans).
- **FR-004**: When an event's categories change, its ticket products MUST re-sync so their `product_cat` set stays current (hook the existing event-save / `nettertech_events_ticket_type_saved` path or an event-category-changed trigger).
- **FR-005**: `set_category_ids()` MUST manage only the NTE-owned set (terms carrying the back-ref meta). Product categories added manually by the operator (no back-ref) MUST be preserved across re-sync (additive/managed semantics; document the ownership rule).
- **FR-006**: Assigning `product_cat` MUST NOT surface hidden ticket products on the public storefront category archive (rely on `catalog_visibility = 'hidden'`; verify in-browser, do not assume).
- **FR-007**: A wp-cli backfill command MUST assign `product_cat` to already-synced ticket products (for celticjunction.org's existing products), with `--dry-run` default and a summary.
- **FR-008**: Coupon matching is WooCommerce core — NO NTE coupon code. The feature's only job is to put the right `product_cat` on the products.

### Key Entities

- **Event category** — `nettertech_event_category` taxonomy term on the event. The source of truth for which categories a ticket belongs to.
- **Product category** — WooCommerce `product_cat` term, NTE-managed when it carries `_nettertech_events_source_category` term meta linking it to an event category. This is the term coupons match against.
- **Ticket product** — `WC_Product_Simple` created by `ProductManager::sync_product()`, `catalog_visibility = 'hidden'`, now also assigned the mirrored `product_cat` set.

## Success Criteria *(mandatory)*

- **SC-001**: A coupon restricted to product category "Concerts" discounts a ticket for a Concerts event **without** the product being listed individually on the coupon — verified by value in the browser on the dev site.
- **SC-002**: Renaming the event category (or the `product_cat`) keeps the coupon matching the ticket and creates no orphaned/duplicate `product_cat` — verified by re-sync test.
- **SC-003**: Hidden ticket products do NOT appear on the public `product_cat` archive — verified in-browser.
- **SC-004**: The wp-cli backfill assigns `product_cat` to all already-synced ticket products for a given event (dry-run shows the plan; write applies it).
- **SC-005**: Base suite stays green (≥ 6347/0) with new unit coverage for the resolution + adopt + no-clobber + re-sync logic.

## Assumptions

- **Name-mirror with term-meta back-reference** is the locked approach (operator decision 2026-06-29); no admin mapping UI in v1; mirrors the `ShadowPostSyncService` relationship model already in the codebase.
- `product_cat` terms are auto-created/adopted by name on first link, then resolved by stable ID.
- Existing manual product categories (no back-ref) are out of NTE's managed set and preserved.
- celticjunction.org backfill is run separately (operator), like the other migrator backfills; the 25-orphan-style edge cases are not in scope here.
- This feature is independent of NTE-127 (no shared settings files — name-mirror needs no settings UI), so it may run in parallel with the CSP work.
