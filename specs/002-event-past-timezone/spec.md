# Feature Specification: Past/Completed determination must honor the site timezone (DST-aware)

**Feature Branch**: `release/1.0.x` (existing — template-fusion fallback; NOT `speckit-specify`)
**Created**: 2026-06-01
**Status**: Draft
**Input**: Urgent bug — events are classified as "past"/"Completed" by a literal UTC read of the stored datetime instead of the site's configured timezone (and DST offset where applicable).

> **Pilot Note (FW-018 template-fusion).** Nested-repo workspace: `.specify/` is at the non-git workspace root, above the plugin's git repo, so the `speckit-*` scripts mis-resolve the repo root and are branch-gated. Artifacts hand-authored in the plugin repo from the SpecKit templates; SpecKit's canonical slug obtained via `create-new-feature.sh --dry-run --json`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — An event is not "past" until it is actually over in the site's local time (Priority: P1)

A site in `America/Chicago` (UTC-5/-6) has an event ending today at 9:00 PM local. At 5:00 PM local the event must still show as upcoming/active — not "Completed".

**Why this priority**: This is the reported defect and it is user-visible on every event, on a UTC-hosted server (the common case). Today the event flips to past/"Completed" ~5 hours early (6h in winter), because the stored wall-clock string (`21:00`) is parsed as UTC by `strtotime()`/`time()` on a UTC server.

**Independent Test**: With the site timezone set to a non-UTC zone on a UTC server, create an event whose end time is **after the current UTC instant but before the current local instant would make it past** — i.e. "now" in UTC is past the stored wall-clock but "now" in site-local is not. Confirm the event does NOT render "Completed"/"Past Event" and DOES appear in upcoming, not past. (Verify by value in the browser, not just unit assertion.)

**Acceptance Scenarios**:
1. **Given** site tz `America/Chicago`, server tz UTC, and an event ending 9:00 PM local today, **When** the page renders at 5:00 PM local, **Then** the event is NOT "Completed" and is NOT in the past archive.
2. **Given** the same event, **When** the page renders at 9:30 PM local, **Then** the event IS past/"Completed".
3. **Given** an event spanning a DST boundary date, **When** past/upcoming is computed, **Then** the correct UTC offset for that date is applied (named-timezone, not a fixed offset).

### User Story 2 — Every past/now surface agrees (Priority: P1)

The card "Completed" badge, the single-page "Past Event" status, the past-events archive, the upcoming list, RSVP/cart "event has ended" guards, and the iCal export must all agree on whether an event is past — using the same timezone-correct basis.

**Why this priority**: Inconsistent bases produce contradictory UI (a card says Completed while the archive omits it). The SQL queries already use the site-local basis correctly; the PHP sites must match them.

**Independent Test**: For one event in the "ambiguous window" (past in UTC, not past in site-local), confirm card badge, single-page status, archive membership, and `Occurrence::is_past()`/`has_ended()` all report "not past".

### Edge Cases

- **DST transition dates**: a fixed `gmt_offset` is wrong half the year; interpretation MUST use the named timezone (`wp_timezone()`), which resolves the correct offset per date.
- **Server timezone ≠ UTC**: the fix must not depend on the server's PHP default timezone at all (the bug is that `strtotime()` uses it).
- **`occurrences.timezone` column = 'UTC'**: the column is unreliable (generator never sets it). The fix MUST NOT trust it for interpretation; stored wall-clock is interpreted in the site timezone.
- **iCal export**: a calendar feed consumer of the time contract — its DTSTART/DTEND must reflect the correct instant. Verify it is not independently broken by the same root cause.
- **Events exactly at "now"**: boundary inclusive/exclusive must match existing SQL (`>=` for upcoming).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Past/now determination MUST interpret stored `start_datetime`/`end_datetime` as **site-local wall-clock** in the **site timezone** (`wp_timezone()`, a named zone → DST-correct), then compare against the current instant. It MUST NOT use `strtotime()` (server-default tz) or treat the wall-clock as UTC.
- **FR-002**: The fix MUST be independent of the server's PHP default timezone.
- **FR-003**: The canonical storage basis (site-local wall-clock) is UNCHANGED. Do NOT re-architect to UTC storage. The correct SQL sites (`for_event`, `for_event_grouped`, `next_for_event`, `date_bounds_for_events`, the `upcoming`-filter in `for_event`) MUST remain untouched — they already compare site-local to `current_time('mysql')`.
- **FR-004**: `Occurrence::is_past()`, `has_ended()`, `is_happening_now()` MUST be corrected to the timezone-aware basis. All callers (`TicketDisplay`, `ICalButton`, `RSVPFormShortcode`, `CartValidator`, and any others) inherit the fix.
- **FR-005**: `templates/parts/event-card.php` and `templates/parts/occurrence-row.php` `$is_past` computations MUST use the timezone-aware basis.
- **FR-006**: `OccurrenceQueryRepository::upcoming()` (the `gmdate()` UTC far-future mixed with `current_time('mysql')`) and `get_siblings()` (`strtotime()` vs `current_time('timestamp')`) MUST be corrected to a single consistent basis.
- **FR-007**: The interpretation rule MUST live in ONE place (a small helper or the corrected `Occurrence` getters) so sites do not re-roll it. Display output (formatted wall-clock) MUST remain unchanged (still shows the authored local time).
- **FR-008**: The iCal export MUST be verified (and fixed if it shares the root cause) so feed DTSTART/DTEND reflect the correct instant.

### Key Entities

- **Occurrence**: `start_datetime`/`end_datetime` (site-local wall-clock strings) + a `timezone` column that currently defaults to `'UTC'` and is NOT reliably the authored zone. This spec treats stored times as site-local wall-clock and interprets them in `wp_timezone()`; the `timezone` column is NOT used for interpretation (documented; see Assumptions).

## Success Criteria *(mandatory)*

- **SC-001**: On a UTC server with a non-UTC site timezone, an event whose end is in the "ambiguous window" (past in UTC, not past in site-local) renders as NOT past/Completed across card, single page, and archive — verified by value in the browser.
- **SC-002**: Once the site-local instant passes the event end, it renders as past/Completed (no false "still upcoming").
- **SC-003**: DST correctness — an event on each side of a DST transition computes past/upcoming using that date's actual offset (named-zone), proven by unit tests that pin a non-UTC zone and a UTC server.
- **SC-004**: No regression in the correct SQL sites or in displayed (formatted) event times.
- **SC-005**: New automated tests cover (a) UTC-server-vs-site-tz mismatch and (b) a DST-transition date — neither existed before.
- **SC-006**: Gates green — `composer phpcs` 0 · `composer phpstan` L8 0 · `composer test` 0 fail · `composer lint:js` 0.

## Assumptions

- **Stored = site-local wall-clock.** Verified on oz: stored `19:00:00` is the admin's 7 PM site-local intent; SQL compares it to `current_time('mysql')` correctly. This is the canonical basis.
- **Single authoring timezone = the site (plugin) timezone.** There is no per-occurrence timezone UI; the `occurrences.timezone` column is vestigially `'UTC'`. Interpreting in `wp_timezone()` is correct for all existing data.
- **Column/migration hardening is IN SCOPE (operator-approved 2026-06-01).** `OccurrenceGenerator` (and occurrence save handlers) set the `timezone` column to the site zone (`wp_timezone_string()`) on write; a data migration (DB_VERSION 3.13.0→3.14.0) backfills existing rows whose column is the vestigial `'UTC'` default to the current site zone (safe: all existing wall-clock was authored in the current site tz). `get_start()/get_end()` then interpret via the column (with a `wp_timezone()` fallback for empty/invalid values), so the authored zone is preserved per-occurrence even if the site tz later changes. Re-architecting storage to UTC remains rejected (would break the correct SQL sites).
- **Display unaffected.** `get_start()/get_end()->format()` print wall-clock components regardless of the attached zone, so formatted times are unchanged; only instant comparisons change.
- **Target release**: 1.1.0 (this fix lands before the pending 1.1.0 tag).

## Codebase grounding (verified 2026-06-01 against source + live oz)

- Site tz `America/Chicago`, `gmt_offset -5`; server PHP tz `UTC`; stored occurrences `start_datetime` site-local wall-clock, `timezone` column `'UTC'`.
- Buggy: `Occurrence::is_past` (Occurrence.php:577), `has_ended` (:616), `is_happening_now` (:587-588); `event-card.php:49`; `occurrence-row.php:32`; `OccurrenceQueryRepository::upcoming` (gmdate, ~:413) + `get_siblings` (strtotime, ~:265). `get_start`/`get_end` (:480/:489) read the unreliable `'UTC'` column.
- Correct (do not change): `for_event`, `for_event_grouped`, `next_for_event`, `date_bounds_for_events` (all `current_time('mysql')` vs stored).
- Callers of the buggy model methods: `TicketDisplay.php:147`, `ICalButton.php:64`, `RSVPFormShortcode.php:162`, `CartValidator.php:100`.
- Test gap: `OccurrenceTest` covers is_past/has_ended with hardcoded dates but pins no timezone / DST / UTC-server scenario.
