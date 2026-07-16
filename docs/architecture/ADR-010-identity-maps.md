# ADR-010: Per-Request Identity Maps

**Date:** 2025-06-01
**Status:** Accepted
**Category:** Performance

## Context

Repository methods like `find($id)` are called multiple times during a request. For example, processing a ticket check-in requires:

1. `TicketRepository::find($ticket_id)` → get the ticket
2. `AttendeeRepository::find($ticket->attendee_id)` → get the attendee
3. `OccurrenceRepository::find($attendee->occurrence_id)` → get the occurrence
4. `EventRepository::find($occurrence->event_id)` → get the event

Later in the same request, rendering the check-in response calls several of these again. Without caching, this doubles the database queries.

## Decision

**Use per-request identity maps in repositories. No persistent ORM or cross-request caching for entity lookups.**

### Implementation

```php
class EventRepository implements EventRepositoryInterface {
    private array $identity_map = [];

    public function find(int $id): ?Event {
        if (isset($this->identity_map[$id])) {
            return $this->identity_map[$id];
        }

        $row = $this->fetch_from_db($id);
        if ($row) {
            $event = $this->hydrate($row);
            $this->identity_map[$id] = $event;
            return $event;
        }

        return null;
    }
}
```

### Properties

- **Scope**: PHP array — lives for the duration of the HTTP request, then garbage collected.
- **No eviction**: Maps grow unboundedly within a request.
- **No cross-request persistence**: Unlike Redis/Memcached caching (ADR-011), identity maps don't survive across requests.
- **Write-through**: `save()` updates both the database and the identity map.

### Risk Assessment

| Scenario | Records in Map | Memory | Risk |
|----------|---------------|--------|------|
| Frontend page | 10-50 | <1 MB | None |
| Admin list table | 20-100 | <2 MB | None |
| Calendar month view | 30-90 | <2 MB | None |
| Hypothetical bulk CLI | 10,000+ | 50+ MB | Medium |

## Alternatives Considered

### No Caching (Query Every Time)

- **Pros**: Simplest. Always fresh data.
- **Cons**: 2-3x more database queries per request. Noticeable on pages that display multiple events with tickets and attendees.

### Persistent Object Cache (Redis/wp_cache)

- **Pros**: Cross-request caching. Shared across processes.
- **Cons**: Stale data risk. Cache invalidation complexity. Entity graphs (event→occurrences→attendees) require careful invalidation when any piece changes.

### Full ORM (Doctrine/Eloquent)

- **Pros**: Unit of work, change tracking, lazy loading, identity map built in.
- **Cons**: Massive dependency. WordPress doesn't support Doctrine's entity manager lifecycle. Overkill.

## Consequences

- **Positive**: Eliminates duplicate queries within a request (typically 30-50% reduction). Zero external dependencies. Zero stale data risk — map is fresh each request.
- **Negative**: Unbounded growth in batch/CLI contexts. No benefit across requests — each page load starts cold.
- **Mitigations**: If WP-CLI bulk operations are added, implement `reset_identity_map()` between batches. Cross-request caching handled separately by `CacheManager` with `wp_cache_*` (see `CapacityCalculator`).

## Related

- ADR-003: Repository Pattern
- `includes/Repositories/EventRepository.php`: Identity map implementation
- `includes/Repositories/OccurrenceRepository.php`: Identity map implementation
- `docs/PERFORMANCE.md`: Identity map pattern documentation
