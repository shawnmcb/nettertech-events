# ADR-009: Application-Level Referential Integrity

**Date:** 2025-06-01
**Status:** Accepted
**Category:** Database Integrity

## Context

The database schema defines relationships between 16 tables (events → occurrences → ticket_types → attendees → tickets → check_ins). MySQL supports FOREIGN KEY constraints with CASCADE rules to enforce these relationships at the database level.

However, WordPress's `dbDelta()` function — the standard mechanism for creating and updating plugin tables — does not reliably handle FOREIGN KEY constraints. It uses regex-based SQL parsing that drops FK clauses silently.

## Decision

**Enforce referential integrity at the application layer. No database-level FOREIGN KEY constraints.**

### How Integrity Is Maintained

| Mechanism | Implementation |
|-----------|----------------|
| **Cascade delete** | Repository `delete()` methods explicitly delete children before parent. `EventRepository::delete()` calls `OccurrenceRepository::delete_for_event()` first. |
| **Existence checks** | Services verify parent exists before creating children. `AttendeeRepository::save()` confirms occurrence exists. |
| **Orphan detection** | `HealthCheckService` scans for orphaned records (attendees without occurrences, tickets without attendees). |
| **Transactional boundaries** | Critical multi-table operations (order processing) use atomic repository methods with error checking. |

### Why Not Workaround dbDelta?

```php
// This is fragile and version-dependent:
$wpdb->query("ALTER TABLE {$wpdb->prefix}nte_occurrences
    ADD CONSTRAINT fk_event_id
    FOREIGN KEY (event_id) REFERENCES {$wpdb->prefix}nte_events(id)
    ON DELETE CASCADE");
```

- Fails silently on some MySQL/MariaDB configurations.
- Runs on every activation — must check if constraint exists first.
- `ON DELETE CASCADE` silently deletes attendee records (real ticket purchases) when an event is deleted. This is dangerous — the admin should see a warning, not lose data.

## Consequences

- **Positive**: Compatible with `dbDelta()`. No silent cascades — deletions are explicit and auditable. Easier migration and data repair (no FK violations blocking operations).
- **Negative**: Orphaned records possible via direct SQL access, interrupted operations, or bugs. Application code must remember to cascade.
- **Mitigations**: HealthCheckService detects orphans. Repository delete methods encapsulate cascade logic.

## Related

- `includes/Database/Schema.php`: Table definitions (no FK clauses)
- `includes/Repositories/EventRepository.php`: Cascade delete implementation
