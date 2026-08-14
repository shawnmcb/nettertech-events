# Feature Specification: Single-event image — aspect-ratio fidelity + per-event vertical anchor

**Feature Branch**: `release/1.0.x` (existing branch — hand-authored via the Gap-2/4 fallback, NOT `speckit-specify`; see Pilot Note below)
**Created**: 2026-06-01
**Status**: Draft
**Input**: NTE-119 (re-scoped) — fix the clip that defeats the configured single-event image aspect ratio; add an event-page aspect-ratio setting; add a per-event vertical crop anchor.

> **Pilot Note (FW-018 harvest).** This spec was produced by routing NTE-119 through `/plan-decompose --speckit`. `speckit-specify` could not be used as-designed here: (1) **Gap 2** — its `create-new-feature.sh` hard-codes `git checkout -b`, forking a new branch off our in-flight `release/1.0.x`; (2) **Gap 4 (new)** — `get_repo_root()` anchors to the `.specify/` dir at the **non-git workspace root**, so artifacts would land in a workspace-root `specs/` dir, divorced from the nested plugin git repo, and the `git checkout -b` would run in a non-repo. Fallback applied: hand-author this SpecKit-shaped artifact in the plugin repo (`<plugin>/specs/NNN-slug/`), version-controlled on the feature's real branch. SpecKit naming convention preserved for portability.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Configured aspect ratio actually governs the single-event image (Priority: P1)

An admin sets the featured-image aspect ratio (globally or for event pages) and expects the single-event page to render the image at that ratio. Today it does not: a fixed `max-height: 500px; overflow: hidden` cap on the image container clips every ratio to a constant ~width×500 band, so the setting has no visible effect.

**Why this priority**: This is a confirmed, visible defect on every single-event page with a featured image (verified on the dev site: container rendered 1071×500 / 2.14:1 while the setting was 16:9). It is the root bug; Stories 2 and 3 are meaningless until it is fixed.

**Independent Test**: With a featured image present, set the default ratio to a distinctive value (e.g. 1:1), load the single-event page, and confirm the **rendered image frame** matches — measured on the visible container, plus a screenshot. Repeat at a second, distinct ratio (one wide, one tall).

**Acceptance Scenarios**:
1. **Given** a single event with a featured image and the default ratio set to 16:9, **When** the page renders, **Then** the visible image frame is 16:9 (not a fixed-height band).
2. **Given** the same event, **When** the default ratio is changed to 1:1 and the page reloaded, **Then** the visible frame becomes square.
3. **Given** any configured ratio at a normal content width, **When** the page renders, **Then** no fixed `max-height` overrides the configured ratio.

---

### User Story 2 — Event-page aspect ratio set independently of the global default (Priority: P2)

An admin wants the single-event (detail) page to use a different featured-image ratio than the cards/list/carousel/calendar views, set in global settings alongside the existing per-view controls.

**Why this priority**: Completes the per-view control surface (cards/list/carousel/calendar already have overrides; the single page has none) and is the operator's explicit request. Depends on Story 1 — without the clip fix, the setting still wouldn't show.

**Independent Test**: Set the event-page ratio to a value different from the global default, leave per-view overrides empty, and confirm the single page uses the event-page value while an archive card still uses the default.

**Acceptance Scenarios**:
1. **Given** the event-page ratio is unset, **When** the single page renders, **Then** it falls back to the global default ratio.
2. **Given** the event-page ratio is set to 3:2 and the global default is 16:9, **When** the single page renders, **Then** the single image is 3:2 and archive cards remain 16:9.

---

### User Story 3 — Per-event vertical crop anchor (Priority: P2)

When an image's native ratio differs from the configured ratio, `object-fit: cover` crops it. Some event images have critical content at the top or bottom (e.g. a headline banner, a footer logo) that center-cropping cuts off. The admin needs to choose, per event, whether the crop anchors to the top, center, or bottom. Horizontal stays centered (no control).

**Why this priority**: Directly requested; without it the clip fix can still crop away important content. Per-event because images differ.

**Independent Test**: With an off-ratio image whose subject sits near the top, set the event's vertical anchor to "top" and confirm the rendered crop keeps the top; switch to "bottom" and confirm it keeps the bottom.

**Acceptance Scenarios**:
1. **Given** an event with no anchor set, **When** the single page renders, **Then** the crop is vertically centered (default).
2. **Given** an event with vertical anchor "top", **When** the single page renders, **Then** the top of the image is retained and the bottom is cropped.
3. **Given** an event with vertical anchor "bottom", **When** the single page renders, **Then** the bottom is retained and the top is cropped.
4. **Given** any anchor value, **When** the single page renders, **Then** horizontal positioning is centered.

### Edge Cases

- **No featured image**: the single-event-image part returns early; no frame, no anchor — nothing to render. (Existing behavior; unchanged.)
- **Image whose native height < configured ratio's height**: `object-fit: cover` still fills; the anchor selects which slice shows.
- **"original" preset**: the configured ratio is the image's own ratio → effectively no crop; anchor is a no-op. Confirm no fixed cap reintroduces a band.
- **Existing events after upgrade**: `image_vertical_anchor` is NULL → treated as center. No backfill required.
- **Very tall ratio (e.g. 1:1) on a wide layout**: container becomes tall; confirm this is intended (it is — the ratio governs) and not re-capped.
- **Invalid/legacy anchor value in storage**: sanitize to center.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The single-event featured image MUST render at the configured aspect ratio (event-page override if set, else global default), with no fixed `max-height` overriding it. *(Story 1 — the clip fix.)*
- **FR-002**: The aspect ratio MUST be applied on the visible image container such that the cropping element's box reflects the configured ratio (image fills the container via `object-fit: cover`).
- **FR-003**: Global settings MUST expose an event-page (single) aspect-ratio preset + custom field, mirroring the cards/list/carousel/calendar per-view controls, emitted as `--nte-image-ratio-single`.
- **FR-004**: The single-page CSS MUST consume `var(--nte-image-ratio-single, var(--nte-image-ratio-default, 16 / 9))`.
- **FR-005**: Each event MUST be able to store a vertical crop anchor of `top`, `center`, or `bottom`; absent/invalid MUST be treated as `center`.
- **FR-006**: The vertical anchor MUST be settable per event via the event editor and persisted across saves.
- **FR-007**: The single-event image MUST apply the per-event vertical anchor as `object-position: 50% <0%|50%|100%>` (horizontal fixed at 50%).
- **FR-008**: Horizontal crop positioning MUST remain centered; there MUST NOT be a horizontal anchor control.
- **FR-009**: Existing events MUST continue to render (vertical anchor defaults to center) with no data backfill required.
- **FR-010**: All output MUST be escaped per workspace standards; the per-event anchor is server-rendered (per-request), not a global CSS var.

### Key Entities *(include if feature involves data)*

- **Event**: gains `image_vertical_anchor` (nullable enum: top/center/bottom; NULL = center). Mirrors the existing per-event `qr_logo_mode` column pattern. Requires a schema migration (DB_VERSION 3.12.0 → 3.13.0).
- **DisplaySettings**: gains `image_aspect_ratio_single` + `image_aspect_ratio_single_custom` (mirrors the existing per-view ratio fields; consumed generically by `get_view_aspect_ratio('single')`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Changing the event-page (or default) ratio setting **visibly** changes the rendered single-image frame, confirmed by the **visible container's** measured dimensions at ≥2 distinct ratios (one wide, one tall) **and** a screenshot. *(Verify the container frame and look at the render — NOT the inner `<img>`'s reported box, which honors the var even while the container clips it. This discipline is mandatory: it is the exact failure that hid the bug during the 2026-06-01 investigation.)*
- **SC-002**: No fixed `max-height` (or equivalent cap) silently overrides the configured ratio at any common width.
- **SC-003**: Setting an event's vertical anchor to top vs. bottom visibly changes which portion of an off-ratio image survives the crop (verified with an image whose subject is near an edge + screenshot).
- **SC-004**: Horizontal positioning is centered for every anchor value.
- **SC-005**: Existing events (no anchor) render centered with no migration backfill; the upgrade path (3.12.0 → 3.13.0) applies cleanly on a fresh install and an upgrade.
- **SC-006**: Gates green — `composer phpcs` 0 · `composer phpstan` (L8) 0 · `composer test` 0 fail · `composer lint:js` 0.

## Assumptions

- **Per-event, not per-occurrence**: the vertical anchor is an event-level field. The per-occurrence editor already overrides the featured image, but operator scoped the anchor to per-event; occurrence-level is a possible follow-on, out of scope here.
- **No global vertical default**: vertical default is hardcoded `center`; no site-wide vertical-anchor setting (operator requested only a per-event override). Revisit if requested.
- **Storage = new events-table column** (`image_vertical_anchor`), approved as a Tier-2 schema change (matches the `qr_logo_mode` precedent), not post/term meta.
- **Horizontal anchoring is fixed center** by product decision; not configurable.
- **Target release**: 1.0.4.
- **Codebase grounding** (verified against source 2026-06-01): `single.css` `.nte-single-event__image { max-height:500px; overflow:hidden }` + `.nte-single-event__img { aspect-ratio: var(--nte-image-ratio-default…) }`; `InlineCssGenerator::add_image_ratio_styles()` `$views = array('cards','list','carousel','calendar')`; `DisplaySettings::get_view_aspect_ratio()` is generic (dynamic `image_aspect_ratio_{view}` lookup); events table has the `qr_logo_mode varchar(20)` precedent; `Schema::DB_VERSION = '3.12.0'` with a migration map.
