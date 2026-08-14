# Implementation Plan: Single-event image — aspect-ratio fidelity + per-event vertical anchor

**Branch**: `release/1.0.x` (existing — Gap-2/4 fallback) | **Date**: 2026-06-01 | **Spec**: `./spec.md`

**Input**: Feature specification from `specs/001-nte-119-single-event-image/spec.md`

> **Pilot Note:** `speckit-plan` could not run — `check-prerequisites.sh` requires a `NNN-slug` feature branch + `REPO_ROOT/specs/` layout and `exit 1`s on `release/1.0.x`. Hand-authored against `plan-template.md`. (FW-018 harvest: the whole SpecKit script chain is branch-gated.)

## Summary

Fix the defect where the single-event featured image ignores the configured aspect ratio (a `max-height:500px` container cap clips every ratio to a constant band), then add an event-page aspect-ratio setting and a per-event vertical crop anchor. Three independently-shippable slices: A (clip fix, P1), B (event-page ratio setting, P2), C (per-event vertical anchor, P2, requires a schema migration).

## Technical Context

**Language/Version**: PHP 8.2 (strict types); vanilla JS; CSS.
**Primary Dependencies**: WordPress 6.x, WooCommerce (unaffected here). No build step for CSS — `assets/dist/css/*.css` is **hand-maintained** (verified: no SCSS source; a `single.css.bak` exists).
**Storage**: Custom table `wp_nettertech_events_events` — new nullable column `image_vertical_anchor varchar(10)`. Migration via `Schema` (`DB_VERSION 3.12.0 → 3.13.0`, `migrate_to_3_13_0`).
**Testing**: PHPUnit (Brain\Monkey) for PHP units; **browser by-value** for render verification (mandatory per SC-001/003 — measure the visible container + screenshot, not the inner `<img>`).
**Target Platform**: WP admin + public single-event template.
**Project Type**: WordPress plugin (single repo: `nettertech-events`).
**Constraints**: PHPCS (WPCS, long-array syntax) 0 · PHPStan L8 0 · `lint:js` 0. Additive, upgrade-safe schema (nullable, no backfill).
**Scale/Scope**: ~7-10h; ~12-16 files; one migration.

## Constitution Check

*GATE: load `.coherence-invariants.md` (per `.specify/memory/constitution.md`); re-check after design.*

- **Data integrity / upgrade-safety invariant**: the schema change is **additive + nullable + forward-migrated**; existing rows default to center (NULL), no backfill, no breaking read. Conforms.
- **No-`!important` / token discipline (frontend)**: the CSS fix uses existing `--nte-image-ratio-*` tokens + `object-position`; no `!important`, no new hardcoded ratios. Conforms.
- **Output-escaping invariant**: the per-event anchor is server-rendered → `esc_attr` on the emitted `object-position`. Conforms.
- **Gap 3 closure (this pilot):** run `/coherence-invariants validate` at the Phase 4 gate as the *checked* binding (not prose). [PENDING at gate]

No HIGH-confidence invariant violations. No Complexity Tracking entries required.

## Project Structure

### Documentation (this feature)
```text
specs/001-nte-119-single-event-image/
├── spec.md              # done
├── plan.md              # this file
├── tasks.md             # next (speckit-tasks fallback)
└── execution-plan.md    # native chunk overlay (Phase 3 bridge)
```

### Source code (files to touch — grounded 2026-06-01)

**Slice A — clip fix (CSS only):**
- `assets/dist/css/single.css` — move `aspect-ratio` onto `.nte-single-event__image` (container) as `var(--nte-image-ratio-single, var(--nte-image-ratio-default, 16 / 9))`; remove `max-height:500px`; `.nte-single-event__img` → `width:100%; height:100%; object-fit:cover; object-position:50% var(--nte-single-image-anchor-y, 50%)`.

**Slice B — event-page ratio setting:**
- `includes/Core/Settings/DisplaySettings.php` — `+ image_aspect_ratio_single`, `+ image_aspect_ratio_single_custom`.
- `includes/Core/NetterTechEventsSettings.php` — map both keys in `from_array()`.
- `includes/Admin/SettingsSanitizer.php` — register both (mirror lines 214-250).
- `includes/Core/InlineCssGenerator.php` — add `'single'` to `$views` (emits `--nte-image-ratio-single`).
- Display-tab settings renderer — add the single-view preset+custom row. *[T-locate: the form rendering the `nettertech_events_settings[image_aspect_ratio_{view}]` selects — pin exact file as first US2 task.]*

**Slice C — per-event vertical anchor (+ schema):**
- `includes/Database/Tables/EventsTable.php` — `image_vertical_anchor varchar(10) DEFAULT NULL` in CREATE (matches `qr_logo_mode` precedent).
- `includes/Database/Schema.php` — `migrate_to_3_13_0` (ADD COLUMN), register in migration map, bump `DB_VERSION`.
- `includes/Models/Event.php` — `image_vertical_anchor` property.
- `includes/Repositories/EventRepository.php` — read/write/save mapping.
- Event editor template (featured-image area) — Top/Center/Bottom select.
- `includes/Admin/EventSaveHandler.php` — sanitize (allowlist top|center|bottom→center) + persist.
- `templates/parts/single-event-image.php` — emit `--nte-single-image-anchor-y` (0%/50%/100%) inline on the container, `esc_attr`'d.

**Tests:** `tests/Unit/...` — DisplaySettings `get_view_aspect_ratio('single')`; EventSaveHandler anchor sanitize; EventRepository anchor round-trip; migration (fresh + upgrade). Browser by-value per SC-001/003.

**Structure Decision**: single-repo plugin; artifacts in `specs/001-.../` inside the plugin (Gap-4 fallback). No new dirs in `src/`.

## Complexity Tracking

> No constitution violations → not applicable. The schema column is justified by the `qr_logo_mode` precedent (per-event enum), not a new pattern.
