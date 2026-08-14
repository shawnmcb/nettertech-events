# Tasks: Single-event image — aspect-ratio fidelity + per-event vertical anchor

**Input**: `specs/001-nte-119-single-event-image/` (spec.md, plan.md)
**Tests**: included (unit + browser by-value) — the spec mandates render verification (SC-001/003).
**Organization**: by user story (US1 clip fix · US2 event-page ratio · US3 vertical anchor). Each ships independently.

> Pilot Note: `speckit-tasks` not run (branch-gated, see plan.md). Hand-authored against `tasks-template.md`.

## Format: `[ID] [P?] [Story] Description`
- **[P]** = parallelizable (different files, no dependency). Single-repo with shared `single.css`, so cross-story parallelism is limited.

---

## Phase 1: Setup

- [x] T001 Baseline `composer test` + record pre-existing failures. **Result: 6300 / 0 fail / 38 skip — green** (matches NTE-120 close).
- [x] T002 [P] Pin the Display-tab settings renderer file. **Result: `includes/Admin/Settings/ImageDisplaySettingsSection.php` → `render()` calls `render_view_ratio_field('cards'|'list'|'carousel'|'calendar', …)`. US2/T010 = add `render_view_ratio_field('single', __('Event Page', …), $settings)` (the generic helper builds `image_aspect_ratio_single` + `_custom`).**

## Phase 2: Foundational (Blocking Prerequisites)

**None shared.** US1 (CSS), US2 (settings PHP+CSS), US3 (schema+model+UI) are independent. US3 carries its own schema migration as its first (story-local) blocking step. *(Deviation from SpecKit's "Phase 2 blocks all stories" — noted as FW-018 harvest: the template assumes a shared foundation that doesn't exist here.)*

---

## Phase 3: User Story 1 — Clip fix (Priority: P1) 🎯 MVP

**Goal**: The configured aspect ratio visibly governs the single-event image (no fixed-height clip).
**Independent Test**: set default ratio to 1:1, load a single event w/ featured image, confirm the **visible container** renders square (+ screenshot); repeat at 16:9.

- [x] T003 [US1] In `assets/dist/css/single.css`: moved `aspect-ratio` onto `.nte-single-event__image` as `var(--nte-image-ratio-single, var(--nte-image-ratio-default, 16 / 9))`; removed `max-height:500px`; `.nte-single-event__img` → `width:100%; height:100%; object-fit:cover; object-position:50% var(--nte-single-image-anchor-y, 50%)`. (`/wp-frontend` briefing applied.)
- [x] T004 [US1] CSS-only — `lint:js` n/a, `composer phpcs`/`phpstan` unaffected (no PHP touched). Confirmed only one `.nte-single-event__image` rule exists (+ irrelevant `.bak`); no remaining `max-height` cap.
- [x] T005 [US1] **Browser by-value (oz, event 6573 w/ featured image):** default 16:9 → container 1104×621 = **1.778**, `aspect-ratio: 16 / 9`, `max-height: none`; set 1:1, reload → container 1104×1104 = **1.0**, `aspect-ratio: 1 / 1`. Measured the **container**, not the `<img>`. Screenshots: `.claude/audits/nte-119-c1-16x9.png`, `nte-119-c1-1x1.png`. SC-001/SC-002 met.

**Checkpoint**: US1 shippable — the clip bug is fixed; the default/event-page ratio now governs the single image.

---

## Phase 4: User Story 2 — Event-page aspect-ratio setting (Priority: P2)

**Goal**: Admin sets a single-page ratio independent of the global default.
**Independent Test**: set event-page ratio ≠ default, leave per-view empty; single page uses event-page value, archive card uses default.
**Depends on**: US1 (the `--nte-image-ratio-single` var is consumed by the A-fixed container rule; without A the setting wouldn't show).

- [x] T006 [US2] `DisplaySettings.php`: added `image_aspect_ratio_single` + `_custom` promoted props (after `image_aspect_ratio_custom`) + docblock; updated `get_view_aspect_ratio` view list. Named-arg construction → no positional-caller breakage; structural-change gate ran (6300/0/38).
- [x] T007 [US2] `NetterTechEventsSettings.php::from_array()`: mapped both new keys.
- [x] T008 [US2] `SettingsSanitizer.php`: registered both (`single`=aspect_ratio, `single_custom`=aspect_ratio_custom); phpcbf normalized alignment.
- [x] T009 [US2] `InlineCssGenerator.php`: added `'single'` to `$views` → emits `--nte-image-ratio-single`.
- [x] T010 [US2] `includes/Admin/Settings/ImageDisplaySettingsSection.php`: added `render_view_ratio_field('single', 'Event Page', …)` after Site Default. UI-verified: "Event Page" field renders + saves (persisted `image_aspect_ratio_single=3:2`).
- [x] T011 [P] [US2] Unit tests added: `get_view_aspect_ratio('single')` round-trip (NetterTechEventsSettingsTest) + sanitizer registers/validates single + single_custom (SettingsSanitizerTest). 104 tests green.
- [x] T012 [US2] **Browser by-value (UI workflow):** set Event Page=3:2 via admin Display tab + saved; single page container 1104×736 = **1.5 (3:2)**, `--nte-image-ratio-single: 3 / 2`; archive card `.nte-event-card__image` 350×197 = **1.778 (16:9)** (cards empty → default). Screenshots: `.claude/audits/nte-119-c2-single-3x2.png`, `nte-119-c2-archive-16x9.png`. Setting restored to empty post-verify.

**Checkpoint**: US1+US2 shippable.

---

## Phase 5: User Story 3 — Per-event vertical anchor (Priority: P2)

**Goal**: Per-event top/center/bottom crop anchor; horizontal fixed center.
**Independent Test**: off-ratio image w/ subject near top → anchor "top" keeps top; "bottom" keeps bottom.
**Depends on**: US1 (the `object-position` var lives in the A-fixed `.nte-single-event__img` rule).

### Foundational (story-local, blocking US3) — schema
- [x] T013 [US3] `EventsTable.php`: added `image_vertical_anchor varchar(10) DEFAULT NULL` to CREATE (after `space_id`; mirrors `qr_logo_mode`). Fresh installs + dbDelta belt-and-suspenders.
- [x] T014 [US3] `Schema.php`: added idempotent `migrate_to_3_13_0` (SHOW COLUMNS guard + ALTER ADD COLUMN, mirrors `migrate_to_3_11_0`), registered `'3.13.0'` in map, bumped `DB_VERSION` 3.12.0→3.13.0. **Update path verified on oz live DB**: 3.12.0→3.13.0 fired via `Plugin::init`/`plugins_loaded`, column added (varchar(10) null default NULL), self-healing retry confirmed (Context7-verified: `plugins_loaded` db-version check is the canonical update-path mechanism since WP 3.1).
- [x] T015 [US3] **Structural Change Gate:** full `composer test` after schema change — caught 1 failure (SchemaTest hard-coded DB_VERSION==3.12.0); relaxed to `>=` (preserves NTE-077 intent) + added `migrate_to_3_13_0` registration test. Re-ran: 6303/0/38 green.

### Model / persistence
- [x] T016 [US3] `Event.php`: added `image_vertical_anchor` property + `from_row` hydration + `to_array` entry + `get_formats` `%s` (all aligned after `space_id`).
- [x] T017 [US3] `EventRepository.php`: no change needed — `save()` persists via `to_array()`+`get_formats()` and hydrates via `from_row()` generically (no explicit column list).
- [x] T018 [P] [US3] Unit test (EventTest): `image_vertical_anchor` round-trips `from_row`→`to_array`; missing column → null.

### Admin UI + persistence
- [x] T019 [US3] `EventMetaboxContentRenderer::render_featured_image_box()`: Center/Top/Bottom `<select>` (`#image_vertical_anchor`, default Center) inside the Featured Image box. UI-verified renders + reflects persisted value (editor showed `was: top` on reload).
- [x] T020 [US3] `EventSaveHandler`: `sanitize_vertical_anchor()` (allowlist top|center|bottom, else center) in `extract_event_fields`; stores NULL for center (clean column). UI E2E: saved top then bottom via the editor form (`admin-post.php`) — both persisted.
- [x] T021 [P] [US3] Unit test (EventSaveHandlerTest, dataProvider): top→top, bottom→bottom, center→null, invalid→null, missing→null.

### Render
- [x] T022 [US3] `single-event-image.php`: emits `--nte-single-image-anchor-y` (top=0% / center|null=50% / bottom=100%) on `.nte-single-event__image`, `esc_attr`'d (survives `wp_kses` allowlist). **Also fixed an integration bug:** `single-event.php` builds a subset context for parts and didn't map the new key — added `image_vertical_anchor` to `$nettertech_events_template_context`. (Caught only by UI-workflow verification; unit tests passed.)
- [x] T023 [US3] **Browser by-value (UI workflow):** image 3005 (4:3) in a 16:9 container (vertical crop active); anchor top → `object-position: 50% 0%`, bottom → `50% 100%`; screenshots `.claude/audits/nte-119-c3-anchor-{top,bottom}.png` show visibly different retained slices, horizontal centered (SC-003/SC-004). Test fields restored to pre-session state.

**Checkpoint**: all three stories functional.

---

## Phase N: Polish & Release

- [ ] T024 Final gates: `composer phpcs` 0 · `phpstan` L8 0 · `test` 0 fail · `lint:js` 0.
- [ ] T025 Version-header bump → 1.0.4 + changelog entry (Tier-3 release tag — with operator).
- [ ] T026 Harvest: fold pilot learnings (Gaps 2/4 + clarify-noop + Phase-2 mismatch) into FW-018 + the plan-decompose SpecKit Integration section.

---

## Dependencies & Execution Order

- **US1 → US2 → US3** (single.css ownership + the var chain; US2/US3 consume the A-fixed rule). US1 is the MVP and independently shippable.
- Within US3: schema (T013-T015) → model (T016-T018) → admin (T019-T021) → render (T022-T023).
- **Schema (T013-T015) is the Tier-2 + structural-change gate** — full `composer test` after T014.
- `[P]` limited to the unit tests (T011/T018/T021) within a story; cross-story serialized by `single.css`.

## Parallel Opportunities

- Minimal — single repo, shared `single.css`. The three unit-test tasks (T011, T018, T021) are `[P]` within their stories. Not a `--team` candidate (effort < the 40h floor, and the chunks aren't file-disjoint).

## Implementation Strategy

MVP = US1 (clip fix) alone — ship-worthy on its own. Then US2, then US3. Each checkpoint is independently verifiable in-browser by value.
