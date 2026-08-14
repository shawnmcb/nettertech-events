# Implementation Plan: Ticket products carry WooCommerce product categories (NTE-129)

**Spec**: `./spec.md` | **Branch**: `fix/event-scoped-ticketed-detection` | **HITL**: Tier 2 (product taxonomy / WooCommerce coupling)
**Pilot note**: template-fusion (no `speckit-plan`); design hand-authored from the SpecKit plan template.

## Constitution / Invariants check

- **WooCommerce coupling (High risk per env heuristics)**: changes touch product-taxonomy assignment in the WC product CRUD path. No payment-path change; no coupon-evaluation code (WC core owns it).
- **Naming**: new identifiers use `nettertech_events_` (term meta `_nettertech_events_source_category`, filter `nettertech_events_product_cat_ids`). `array()` long syntax. Named hook callbacks.
- **No marketplace feature-flag** concerns (base plugin; the mirror is unconditional for event tickets).

## Approach (name-mirror + stable term-meta back-reference)

A small mapper resolves event categories → `product_cat` term IDs by a back-reference, mirroring the `ShadowPostSyncService` relationship model. `ProductManager::sync_product()` calls it and `set_category_ids()` before save. An event-category-change trigger re-syncs the event's products. A wp-cli backfill covers existing products.

### Components

1. **`CategoryProductCatMapper`** (new) — `includes/Integrations/WooCommerce/CategoryProductCatMapper.php`
   - `resolve_product_cat_ids( int $event_id ): array` — for each `nettertech_event_category` term on the event, return the linked `product_cat` term ID:
     - find `product_cat` where term meta `_nettertech_events_source_category` == event-category term ID (resolve by ID);
     - else adopt an existing same-named `product_cat` (set the back-ref) or `wp_insert_term()` a new one and set the back-ref;
     - sync the `product_cat` name to the event-category name (display value; rename reconciliation).
   - `managed_term_ids(): array` — all `product_cat` IDs carrying the back-ref meta (the NTE-owned set, for no-clobber logic).
   - Back-ref meta key constant `SOURCE_CATEGORY_META = '_nettertech_events_source_category'`.

2. **`ProductManager::sync_product()`** (modify, `includes/Integrations/WooCommerce/ProductManager.php`, before `$product->save()` at ~line 172)
   - Resolve current event's mirrored `product_cat` IDs via the mapper.
   - Compute the new category set = (existing product categories NOT in `managed_term_ids()`, preserved) ∪ (resolved mirrored IDs). This is the no-clobber rule (FR-005).
   - `$product->set_category_ids( $new_set )`.
   - Wrap in `apply_filters( 'nettertech_events_product_cat_ids', $ids, $event_id, $product )`.

3. **Re-sync trigger** (modify event-save path)
   - On event save where `nettertech_event_category` terms changed, re-run `ProductManager::sync_product()` (or a lighter category-only re-assign) for that event's ticket products. Prefer a named method on the WooCommerce integration hooked to the event-saved action; reuse `create_products_for_occurrence`/`sync_product` per ticket type.

4. **wp-cli backfill** (new command in the base CLI command set)
   - `wp nettertech-events backfill-product-cats [--event=<id>] [--dry-run]` — iterate ticket products (meta `_nettertech_events_is_event_ticket = yes`), resolve event, assign mirrored `product_cat`. `--dry-run` default; print summary (products touched, terms created/adopted).

### Data / storage

- No schema change. `product_cat` is core WC taxonomy; the only new persisted data is the `_nettertech_events_source_category` **term meta** on mirrored `product_cat` terms.

### Security surface

- Backfill command: capability-gate to admin context (wp-cli runs as admin); no untrusted input. Term names sanitized via core `wp_insert_term`. No SQL (use term API).
- No new public surface; no REST.

### Error handling

- `wp_insert_term` WP_Error (e.g. race/duplicate) → fall back to `get_term_by('name', …, 'product_cat')` and adopt. Log via existing logger, don't fatal the product save.

### Testing strategy

- **Unit** (`tests/Unit/Integrations/WooCommerce/CategoryProductCatMapperTest.php`): resolve-by-backref hit; adopt-by-name (existing term, no back-ref → linked); create-new; rename reconcile (event-cat renamed → same product_cat ID reused, name updated); `managed_term_ids` excludes manual terms.
- **Unit/integration** (`ProductManager` test): `sync_product` assigns mirrored IDs and preserves a manually-added (non-back-ref) category.
- **Browser (dev site)**: SC-001 coupon-by-category discounts a ticket; SC-003 hidden product absent from storefront `product_cat` archive.
- Baseline must stay ≥ 6347/0.

## Deploy / rollback

- Additive; no migration. Rollback = revert the code; the `product_cat` assignments + back-ref term meta are harmless if the feature is removed (coupons simply stop being auto-maintained). Backfill is idempotent (resolve-or-create).

## Open items for tasks.md

- Confirm how the event's `nettertech_event_category` terms are read in the `sync_product` context (`$full_occurrence->get_event()` → terms via `Taxonomies`/`CategoryRepository`). Grounded at task time.
- Confirm the exact event-saved action name for the re-sync trigger.
