# Tasks: Past/Completed determination honors the site timezone (DST-aware)

**Input**: `specs/002-event-past-timezone/` (spec.md, plan.md)
**Tests**: included (migration registration, generator-sets-tz, DST/UTC-server model cases, browser by-value).
**Organization**: C1 storage correctness (column trustworthy) → C2 interpretation fix → C3 proof.
**Scope**: includes column hardening + data migration (operator-approved 2026-06-01).

> Template-fusion: `speckit-tasks` branch-gated; hand-authored against `tasks-template.md`.

## Phase 1: Setup
- [x] T001 Baseline `composer test` — 6309/0/38.
- [x] T002 [P] Blast radius: consumers = TicketDisplay:147, ICalButton:64, RSVPFormShortcode:162, CartValidator:100 (all `has_ended()`). Writers all persist via `OccurrenceRepository::save()`/`save_batch()`→`batch_insert_occurrences()` → single chokepoint (generator, save handler, duplication, CSV all route through it).

## Phase 2: C1 — Storage correctness (make the timezone column trustworthy)
- [x] T003 [C1] **Chokepoint (not per-writer):** `OccurrenceRepository::ensure_timezone()` stamps `$occurrence->timezone = wp_timezone_string()` when the column is the unset default (`''`/`'UTC'`); called in `save()` + `batch_insert_occurrences()` → covers every writer. Preserves a deliberately-set zone.
- [x] T004 [C1] `Schema.php`: added `migrate_to_3_14_0()` (prepared idempotent `UPDATE occurrences SET timezone = %s WHERE timezone IN ('UTC','') OR NULL`); registered `'3.14.0'`; bumped `DB_VERSION` 3.13.0→3.14.0.
- [x] T005 [C1] Tests: `SchemaTest::test_occurrence_timezone_backfill_migration_registered`; `OccurrenceRepositoryTest::test_ensure_timezone_stamps_authoring_zone` (default stamped, empty stamped, explicit preserved) + `setUp()` stubs `wp_timezone_string`.
- [x] T006 [C1] **Structural gate:** caught 11 save-test breakages (unstubbed `wp_timezone_string`) → fixed via `setUp()` stub; removed deprecated `setAccessible()`. Suite 6311/0/38.
- [x] T007 [C1] **Verified on oz live DB:** db_version→3.14.0; all 31 occurrences backfilled `'UTC'`→`'America/Chicago'`; migration success logged (2 prior "failed" = opcache-during-edit artifact, retry succeeded — atomic install succeeds first try).

## Phase 3: C2 — Interpretation fix (interpret wall-clock in the authoring zone) ✅
- [x] T008 [C2] `Occurrence.php`: `resolve_timezone()` (column → `new DateTimeZone`, else `wp_timezone()` for empty/invalid); `get_start`/`get_end` use it; `is_past`/`has_ended` = `get_end()->getTimestamp() < time()`; `is_happening_now` via start/end instants. (`get_duration_minutes` left — a delta, tz cancels.)
- [x] T009 [C2] Templates: `$is_past` via `get_start()->getTimestamp() < time()` in both. **Display left on the `strtotime`+`date_i18n` WP idiom** (renders authored local components by design) — changing only `$is_past` avoids a display regression.
- [x] T010 [C2] `OccurrenceQueryRepository.php`: `upcoming()` far-future via `wp_date` (site-local, was `gmdate` UTC); `get_siblings()` → `$sibling->get_start()->getTimestamp() < time()`.
- [x] T011 [P] [C2] Unit tests (`OccurrenceTest`): authoring-zone interpretation (get_end != UTC-misread); **DST-aware** (12:00 CDT=17:00 UTC vs CST=18:00 UTC); empty-column → `wp_timezone()` fallback. Deterministic (server-tz-independent); live ambiguous-window proof in T014.
- [x] T012 [C2] **Full-suite gate:** 6314/0/38; phpcs/phpstan clean.

## Phase 4: C3 — Consumer verification + proof
- [x] T013 [C3] **iCal export verification:** generate the feed for an event; confirm DTSTART/DTEND resolve to the correct instant under the corrected getters; fix only if broken.
- [x] T014 [C3] **Browser by-value (proof):** on oz, set an event end into the ambiguous window (past in UTC, not site-local); confirm NOT Completed on card + single page + absent from past archive; move end before site-local now → flips to past. Screenshot both. Restore test data.
- [x] T015 [C3] Final gates: `phpcs` 0 · `phpstan` L8 0 · `composer test` 0 · `lint:js` 0.
- [ ] T016 [C3] Changelog — folded into the 1.1.0 release-prep commit (readme.txt): tz fix + schema 3.14.0.

> C3 findings: iCal export was ALSO broken (IcsGenerator strtotime->getTimestamp at both DTSTART sites; 171 iCal tests pass). Browser by-value both ways: ambiguous-window event (2-3h future Chicago = past in UTC) NOT Completed + in Upcoming; clearly-past event Completed + in past archive. Screenshots in .claude/audits/tz-fix-*.png.

## Dependencies & Order
- C1 → C2 → C3. The migration (C1) makes the column correct, so the column-based getters (C2) are correct; the browser proof + iCal (C3) depend on C2.
- T006 (structural gate) and T012 (full-suite gate) are hard within-chunk gates. T007 verifies the migration on the live DB before building interpretation on it.
