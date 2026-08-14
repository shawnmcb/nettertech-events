# Implementation Plan: Past/Completed determination honors the site timezone (DST-aware)

**Branch**: `release/1.0.x` (template-fusion fallback) | **Date**: 2026-06-01 | **Spec**: `./spec.md`

> Template-fusion: `speckit-plan` is branch-gated here; hand-authored against `plan-template.md`.

## Summary

Make every PHP past/now determination interpret stored site-local wall-clock in the **site timezone** (`wp_timezone()`, named-zone → DST-correct) instead of the server default (`strtotime()`/`time()`) or the unreliable `'UTC'` `timezone` column. Centralize the interpretation in `Occurrence::get_start()/get_end()` so callers share one correct basis; rewrite `is_past()/has_ended()/is_happening_now()` in terms of the getters; fix the two templates and the two buggy repo methods; verify the iCal export (a consumer of the contract). No storage change, no data migration (column hardening deferred per spec Assumptions).

## Technical Context

**Language/Version**: PHP 8.2 (strict types); vanilla JS unaffected.
**Primary Dependencies**: WordPress `wp_timezone()` (returns a `DateTimeZone` for the site's named zone or fixed offset). No new deps.
**Storage**: Unchanged. `occurrences.start_datetime`/`end_datetime` remain site-local wall-clock strings. The `timezone` column is NOT used for interpretation (vestigial; documented).
**Testing**: PHPUnit (Brain\Monkey) with `wp_timezone()` stubbed to a named non-UTC zone (e.g. `America/Chicago`) and PHP default tz forced to UTC, to reproduce the bug deterministically. Browser by-value for the final proof.
**Constraints**: PHPCS (WPCS, long-array) 0 · PHPStan L8 0 · `lint:js` 0. No behavior change to displayed (formatted) times or to the correct SQL sites.

## Constitution Check

*GATE: load `.coherence-invariants.md`; re-check after design.*

- **Correctness invariant (time):** the fix establishes a single, DST-correct interpretation rule. Conforms.
- **No-regression invariant:** correct SQL sites untouched; display output unchanged (format prints components). Conforms.
- **Output-escaping:** template change emits an integer comparison / existing escaped output; no new unescaped output. Conforms.
- **Gap-3 closure:** run `/coherence-invariants validate` at the Phase-4 gate. [PENDING at gate]

No HIGH-confidence invariant violations.

## Design

### Central rule (one place) — column-based, with safety fallback

`Occurrence::get_start()/get_end()` already interpret via `$this->timezone` — but the column is the vestigial `'UTC'` default. We make the column **trustworthy** (generator sets it; migration backfills it) and harden the getters to fall back to the site zone for empty/invalid values:

```php
private function resolve_timezone(): \DateTimeZone {
    if ( '' !== $this->timezone ) {
        try { return new \DateTimeZone( $this->timezone ); } catch ( \Exception $e ) { /* fall through */ }
    }
    return wp_timezone(); // named site zone → DST-correct per date
}
public function get_start(): \DateTimeImmutable {
    return new \DateTimeImmutable( $this->start_datetime, $this->resolve_timezone() );
}
public function get_end(): \DateTimeImmutable {
    return new \DateTimeImmutable( $this->end_datetime, $this->resolve_timezone() );
}
```

Post-migration the column holds the authoring zone (e.g. `America/Chicago`), so the authored instant is preserved per-occurrence even if the site tz later changes. `->format()` callers (display) are unaffected — format prints the wall-clock components regardless of the attached zone. `->getTimestamp()` callers now get the correct instant.

### Storage correctness (generator + migration)

- `OccurrenceGenerator` (and `OccurrenceSaveHandler`/any occurrence writer) set `$occurrence->timezone = wp_timezone_string()` on write, so new occurrences record their authoring zone.
- `Schema::migrate_to_3_14_0()` backfills existing rows: `UPDATE occurrences SET timezone = <site tz> WHERE timezone = 'UTC'` (the vestigial default). Safe because all existing wall-clock was authored in the current site tz. Idempotent (re-run sets the same value). Bump `DB_VERSION` 3.13.0→3.14.0, register in the migration map, mirror the `migrate_to_3_13_0` guard/pattern. Verify on the live oz DB (fires via `plugins_loaded`).
- If the site tz IS UTC, the backfill is a no-op and the column legitimately stays `'UTC'`.

### Comparison methods (rewritten in terms of the getters)

```php
public function is_past(): bool    { return $this->get_end()->getTimestamp()   < time(); }
public function has_ended(): bool  { return $this->get_end()->getTimestamp()   < time(); }
public function is_happening_now(): bool {
    $now = time();
    return $this->get_start()->getTimestamp() <= $now && $now <= $this->get_end()->getTimestamp();
}
```

`time()` is the current UTC instant; `getTimestamp()` from a named-zone DateTimeImmutable is the correct UTC instant for that local wall-clock on that date (DST handled). Both sides are true instants → correct.

### Templates

`event-card.php:49` and `occurrence-row.php:32`: replace `strtotime( $occurrence->start_datetime ) < (new DateTimeImmutable('now', wp_timezone()))->getTimestamp()` with `$context->occurrence->get_start()->getTimestamp() < time()` (or call `is_past()`/`is_happening_now()` directly where semantics match). Keeps the year-display `gmdate` usage (cosmetic, not a past/now decision) — but switch the year derivation to the occurrence's local components for correctness if cheap.

### Repository methods

- `OccurrenceQueryRepository::upcoming()`: the far-future bound uses `gmdate()` (UTC) mixed with `current_time('mysql')` (site-local). Make both site-local: `$far_future = gmdate('Y-m-d H:i:s', strtotime('+2 years'))` → use a site-local far-future (e.g. via `current_time` arithmetic) OR widen via `current_datetime()->modify('+2 years')->format('Y-m-d H:i:s')`. Both bounds in the same (site-local) basis.
- `get_siblings()`: replace `strtotime($sibling->start_datetime) < current_time('timestamp')` with `$sibling->get_start()->getTimestamp() < time()` (true-instant comparison), removing the incorrect "same timezone" comment.

### iCal export (consumer — verify, fix if needed)

`IcsGenerator` / iCal emission uses `get_start()/get_end()`. Changing the getter tz from `'UTC'` to the site zone changes the emitted *instant*. Verify the feed format: it must emit DTSTART/DTEND that resolve to the correct moment (either UTC `...Z` computed from the corrected `getTimestamp()`, or local time with a VTIMEZONE). If it currently emitted the wall-clock as `...Z` (i.e. as UTC), it was wrong by the same root cause and is now fixed — confirm by inspecting generated output.

## Source code (files to touch — grounded 2026-06-01)

- `includes/Database/Schema.php` — `migrate_to_3_14_0` (backfill `timezone` 'UTC'→site tz), register in map, bump `DB_VERSION` 3.13.0→3.14.0.
- `includes/Services/OccurrenceGenerator.php` — set `$occurrence->timezone = wp_timezone_string()` on generated occurrences.
- `includes/Admin/OccurrenceSaveHandler.php` (+ any occurrence writer surfaced by grep) — set the tz column on save.
- `includes/Models/Occurrence.php` — `resolve_timezone()` (column + `wp_timezone()` fallback); `get_start`/`get_end` use it; rewrite `is_past`/`has_ended`/`is_happening_now` via the getters.
- `templates/parts/event-card.php` — `$is_past` via `get_start()->getTimestamp() < time()`.
- `templates/parts/occurrence-row.php` — same.
- `includes/Repositories/OccurrenceQueryRepository.php` — `upcoming()` bound basis; `get_siblings()` comparison.
- **Verify (and fix only if broken):** the iCal generator (`includes/...IcsGenerator`/`ICalFeed*`), and any other `get_start()->getTimestamp()` / `is_past()` consumers surfaced by grep.
- **Tests:** `tests/Unit/Models/OccurrenceTest.php` — DST + UTC-server-vs-site-tz cases; `tests/Unit/Database/SchemaTest.php` — `migrate_to_3_14_0` registration test (mirror 3.13.0); generator-sets-tz test.

## Complexity Tracking

> No constitution violations. The change is a correctness fix centralized in two getters; blast radius is wide (many callers) but the change per site is small and shares one rule.
