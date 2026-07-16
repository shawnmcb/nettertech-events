# ADR-006: Recurring Events via RRule with Materialized Occurrences

**Date:** 2025-02-15
**Status:** Accepted
**Category:** Domain Model

## Context

Venues frequently host recurring events: "Irish Session every Tuesday 7-9pm" or "Dance class every other Saturday for 12 weeks." The system needs to:

1. Allow admins to define recurrence patterns
2. Generate individual event dates/times (occurrences)
3. Support per-occurrence modifications (cancel one date, change time for another)
4. Support per-occurrence ticketing and attendance tracking

## Decision

**Use RFC 5545 RRule patterns stored on events, with materialized occurrences in `nte_occurrences`.**

### Two-Layer Model

```
nte_events (definition layer)
  - title, description, recurrence_rule, ...
  - recurrence_rule: "FREQ=WEEKLY;BYDAY=TU;COUNT=12"

nte_occurrences (instance layer)
  - event_id, start_datetime, end_datetime, status, capacity, ...
  - One row per actual event date/time
  - Can be individually modified or cancelled
```

### How It Works

1. **Admin creates event** with recurrence rule (weekly, biweekly, monthly, custom).
2. **RRuleParser** expands the rule into concrete dates.
3. **Occurrences are materialized**: Each date becomes a row in `nte_occurrences` with its own `start_datetime`, `end_datetime`, `status`, and `capacity`.
4. **Occurrences are independently editable**: Cancel Tuesday the 15th without affecting other Tuesdays. Change capacity for the holiday show. Each occurrence can have its own ticket types.

### RRule Implementation

- **`RRuleParser`**: Parses RFC 5545 recurrence rules. Handles FREQ (DAILY, WEEKLY, MONTHLY, YEARLY), INTERVAL, COUNT, UNTIL, BYDAY, BYMONTHDAY, BYSETPOS.
- **No external library**: Custom parser to avoid heavy dependencies. The subset of RRule needed for venue events is well-defined and bounded.
- **Custom exception**: `RRuleException` for parse errors with descriptive messages.

### Occurrence Lifecycle

```
draft → published → cancelled
                  → completed (past date auto-transition)
```

## Alternatives Considered

### Virtual Occurrences (Calculate on the Fly)

- **Pros**: No materialized data. Change the rule, all occurrences update instantly.
- **Cons**: Can't independently modify a single occurrence (cancellation, time change, capacity override). Can't sell tickets against a virtual date — need a concrete row for foreign keys. Calendar queries become RRule expansion + filtering, which is slow for "all events this month across all series."

### Store Each Occurrence as a Separate Event

- **Pros**: Simplest model. Each occurrence is fully independent.
- **Cons**: No series concept. Changing the title/description of a recurring event requires updating 52 separate records. No way to express "this is the same event, repeating."

### External Calendar Library (ICal4j, php-rrule)

- **Pros**: Full RFC 5545 compliance including complex patterns (BYSETPOS, WKST, EXDATE).
- **Cons**: Composer dependency. Most venue events use simple patterns (weekly, monthly). The long tail of RFC 5545 complexity (EXRULE, RDATE with periods) isn't needed.

## Consequences

- **Positive**: Each occurrence has a concrete database row — ticket types, attendees, and capacity bind naturally via foreign keys. Calendar queries are simple `SELECT WHERE start_datetime BETWEEN`. Individual occurrence modifications are straightforward UPDATEs.
- **Negative**: Materialization means occurrences must be generated upfront. Changing a recurrence rule requires deleting and regenerating occurrences (with safeguards against deleting occurrences that have tickets sold).
- **Mitigations**: Event duplication preserves occurrence structure. `RRuleParser` validates rules before materialization. Admin UI warns when modifying rules on events with existing ticket sales.

## Related

- `includes/Services/RRuleParser.php`: RRule parsing and expansion
- `includes/Exceptions/RRuleException.php`: Parse error handling
- `includes/Database/Tables/OccurrencesTable.php`: Occurrence schema
- ADR-001: Custom Database Tables
