# Tasks: Ticket products carry WooCommerce product categories (NTE-129)

**Spec**: `./spec.md` | **Plan**: `./plan.md` | **Chunk**: Wave-2 C5 | **HITL**: Tier 2
**Pilot note**: template-fusion (no `speckit-tasks`); hand-authored, dependency-ordered. `[P]` = parallelizable with siblings.

## Phase 0 — Grounding (do first, at chunk start)

- **T001**: Confirm how the event's `nettertech_event_category` terms are read from a product-sync context — trace `$full_occurrence->get_event()` → terms via `Taxonomies::EVENT_CATEGORY` / `CategoryRepository`. Record the exact call. `[read]`
- **T002**: Confirm the event-saved action name + signature for the re-sync trigger (where category changes are persisted). `[read]`
- **T003**: Confirm `ProductManager::sync_product()` insertion point (before `$product->save()`, ~line 172) and that `WC_Product::set_category_ids()` is the correct CRUD setter. `[read]`

## Phase 1 — Mapper (foundational)

- **T010**: Create `CategoryProductCatMapper` with `SOURCE_CATEGORY_META = '_nettertech_events_source_category'`, `resolve_product_cat_ids(int $event_id): array`, `managed_term_ids(): array`. Resolution order: by back-ref ID → adopt same-named → create; sync name as display value. `[create]` (depends: T001)
- **T011**: Unit test `CategoryProductCatMapperTest`: resolve-by-backref hit; adopt-by-name; create-new; rename reconcile (same ID reused, name updated); `managed_term_ids` excludes manual terms. `[create, P]` (depends: T010)

## Phase 2 — Sync integration

- **T020**: In `ProductManager::sync_product()`, before save: compute new set = (existing categories NOT in `managed_term_ids()`) ∪ resolved mirrored IDs; `set_category_ids()`; wrap in `nettertech_events_product_cat_ids` filter. `[edit]` (depends: T003, T010)
- **T021**: Unit/integration test: `sync_product` assigns mirrored IDs AND preserves a manually-added non-back-ref category (no-clobber). `[create, P]` (depends: T020)
- **T022**: Wire the event-category-change re-sync trigger (named method on the WC integration hooked to the event-saved action; re-sync the event's ticket products). `[edit]` (depends: T002, T020)

## Phase 3 — Backfill

- **T030**: Add wp-cli `backfill-product-cats [--event=<id>] [--dry-run]`: iterate `_nettertech_events_is_event_ticket=yes` products, resolve event, assign mirrored `product_cat`; `--dry-run` default + summary. `[create]` (depends: T010)
- **T031**: Unit test the backfill selection/assignment logic (no live DB execution). `[create, P]` (depends: T030)

## Phase 4 — Gates & verification

- **T040**: `composer phpcs && composer phpstan && composer test` green; suite ≥ 6347/0. Fix introduced phpcs issues. `[run]` (depends: T020, T022, T030)
- **T041**: Browser (dev site) SC-001: coupon restricted to product-category "Concerts" discounts a Concerts-event ticket without per-product listing. `[verify]` (depends: T040)
- **T042**: Browser (dev site) SC-003: hidden ticket product absent from public `product_cat` storefront archive. `[verify]` (depends: T040)
- **T043**: /self-review + /safeguard; commit on the Wave-2 branch. `[run]` (depends: T040)

## Out of scope

- celticjunction.org production backfill execution (operator-run, like the migrator backfills).
- Admin mapping UI (name-mirror chosen; no UI in v1).
- Geocoding / multi-venue / any non-coupon use of categories.

## Notes
- Independent of NTE-127 (no shared settings files) → C5 may run parallel to C3 in its wave.
- All edits on the dev site; production read-only.
