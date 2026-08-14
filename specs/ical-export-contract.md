# iCal Export Contract (RFC 5545)

| Field | Value |
|-------|-------|
| **Status** | Authoritative — governs `ICalService` export output |
| **Introduced** | 2026-05-29 (NTE-077 Chunk 7 — compact-recurring refactor) |
| **Supersedes** | Expanded-VEVENT model (one standalone VEVENT per occurrence, no RRULE) |
| **Scope** | **Export only.** Import (`parse_ical`/`import_ical`/`VEventParser`) is unchanged and remains expanded-flat-aware. |
| **Spec source** | `RFC 5545` §3.6.1 (VEVENT), §3.8.5.3 (RRULE), §3.8.5.1 (EXDATE), §3.8.4.4 (RECURRENCE-ID) |

This is a **contract with external consumers** (calendar subscribers: Google/Apple/Outlook) and with the plugin's own import path. Changes here are breaking for subscribers and must be deliberate.

## 1. VCALENDAR envelope (unchanged)

`VERSION:2.0` · `PRODID:-//NetterTech Events//Events Plugin//EN` · `CALSCALE:GREGORIAN` · `METHOD:PUBLISH` · `X-WR-CALNAME:{site name} Events`. CRLF (`\r\n`) line endings throughout.

## 2. Recurring event → ONE master VEVENT

- `UID:event-{event_id}@{host}` — **stable per series** (was `occurrence-{id}` in the old model — see §7 churn note).
- `DTSTART`/`DTEND` from the **earliest** occurrence (`for_event` ordered `start_datetime ASC`; sort explicitly, don't rely on default).
- `RRULE:` from `RecurrenceService::parse_rule( $event->recurrence_rule )->to_string()` — round-trip normalizes `UNTIL` to UTC `Z`. Do **not** hand-emit; reuse the serializer.
- `DTSTAMP`, `SUMMARY`, `LOCATION`, `DESCRIPTION` (stripped tags), `URL`, `CATEGORIES` as before.
- `STATUS:CONFIRMED` (the master is always CONFIRMED; cancellations are EXDATE, not STATUS — fixes the prior hardcoded-CONFIRMED-on-cancelled bug).
- `TRANSP:OPAQUE`.

## 3. Cancelled occurrences → EXDATE on the master

For each occurrence with `status='cancelled'` (set via the Chunk 6 cancel path, `is_override=1`):
- Emit an `EXDATE` line carrying the occurrence's **ORIGINAL recurrence-slot datetime** (see §6).
- **Value type must match DTSTART:** timed → `EXDATE:{Ymd\THis\Z}`; all-day → `EXDATE;VALUE=DATE:{Ymd}`. Mismatched value types break subscriber reconciliation.

## 4. Overridden instances → RECURRENCE-ID VEVENT

For each occurrence with `is_override=1`, not cancelled, with changes:
- Separate VEVENT, **same `UID` as the master**.
- `RECURRENCE-ID:` = the **ORIGINAL** recurrence-slot datetime (§6), value-type-matched as in §3.
- `DTSTART`/`DTEND` = the occurrence's (possibly moved) actual datetime.
- Overridden fields via the Occurrence **effective getters** (`get_title()`/`get_description()`/`get_venue_name()`/`get_venue_address()`/`get_virtual_url()`/`get_featured_image_id()`), not raw event fields.

## 5. Single / non-recurring events → one plain VEVENT

`UID:event-{event_id}@{host}` (consistent with the master scheme). DTSTART/DTEND/SUMMARY/etc. as today. No RRULE/EXDATE/RECURRENCE-ID.

## 6. Original-slot recovery (approach C — operator-approved 2026-05-29)

RECURRENCE-ID and EXDATE must carry the occurrence's **original** recurrence slot, but a Chunk-6 edit may have moved `start_datetime`, and **no original slot is stored** (only `sequence_number`). Recovery: **regenerate the theoretical slot set** via `OccurrenceGenerator::generate( Event, start, end, RecurrenceRule )` and map each override occurrence to its slot by `sequence_number` (nearest slot fallback). Reuses verified generation logic; handles BYDAY/BYSETPOS that arithmetic reconstruction would get wrong. Implemented as `resolve_original_slot( Occurrence, Event, RecurrenceRule ): DateTimeImmutable`. (Rejected: A = `DTSTART + (seq-1)×INTERVAL` — wrong for complex rules; B = stored `recurrence_id_datetime` column — schema cost + retrofit.)

## 7. Series-split + datetime + churn rules

- **Series-split (scope=following, Chunk 6):** a split series is TWO events → TWO master VEVENTs with distinct UIDs (`event-{old}` capped by RRULE `UNTIL` + `event-{new}`). Assert no instance overlap (parent `UNTIL` < new `DTSTART`).
- **Datetimes:** timed → UTC `Ymd\THis\Z`; all-day → `VALUE=DATE:Ymd` (DTEND = end + 1 day per the existing all-day convention).
- **Text escaping:** RFC 5545 §3.3.11 — escape `\`, `,`, `;`, newline; strip `\r` (existing `escape_text`).
- **Subscriber churn (acceptable, documented):** UID changes from `occurrence-{id}` → `event-{id}` cause one round of churn on first refresh for existing subscriptions. Do not attempt to preserve old UIDs.
- **URL:** event permalink uses `PathHelper::get_base_path()` (`events/`), not the legacy singular `event/`.

## 8. Import round-trip (known asymmetry)

Export is compact-recurring (this contract); import remains expanded-flat-aware (`VEventParser` maps `RRULE`→`rrule`, sets `$event->recurrence_rule`). Round-tripping this export back through import is **not required** and not guaranteed. ~20 parse/import tests assert the flat model and must stay green — the refactor is export-only.

## Verification

`curl` `/wp-json/nettertech-events/v1/ical/event/{id}` + `/ical/feed`; assert master has RRULE, cancelled→EXDATE, overridden→RECURRENCE-ID VEVENT with override values, UNTIL/COUNT/BYDAY round-trip, UTC `Z`. Validate against an RFC-5545 linter (no Apple Calendar step, per operator).
