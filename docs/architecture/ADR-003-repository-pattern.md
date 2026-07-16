# ADR-003: Repository Pattern with Interface Contracts

**Date:** 2024-12-23
**Status:** Accepted
**Category:** Data Access

## Context

With custom database tables (ADR-001), the plugin needs a data access layer. Raw `$wpdb` calls scattered through business logic would create tight coupling to the database schema, make testing difficult, and duplicate query logic.

## Decision

**Use the Repository pattern with interface contracts for all database access.**

### Structure

```
includes/
  Contracts/
    EventRepositoryInterface.php      # Public API contract
    OccurrenceRepositoryInterface.php
    TicketTypeRepositoryInterface.php
    AttendeeRepositoryInterface.php
    TicketRepositoryInterface.php
    ...
  Repositories/
    EventRepository.php               # Implementation with $wpdb
    OccurrenceRepository.php
    TicketTypeRepository.php
    AttendeeRepository.php
    TicketRepository.php
    ...
  Models/
    Event.php                          # Plain data objects
    Occurrence.php
    TicketType.php
    Attendee.php
    Ticket.php
    ...
```

### Key Properties

- **Interface segregation**: Large repositories split into focused interfaces. `AttendeeRepositoryInterface` (CRUD), `AttendeeCheckInInterface` (check-in operations), `AttendeeOrderInterface` (order-related queries), `AttendeeSearchInterface` (search/filter).
- **Identity maps**: Repositories cache fetched entities by ID to prevent duplicate queries within a request. `find(42)` called twice returns the same object instance.
- **Models are plain objects**: No Active Record. Models have public typed properties, no database coupling. Repositories handle hydration from `$wpdb` result rows.
- **Prepared statements**: All queries use `$wpdb->prepare()` with typed placeholders (`%d`, `%s`). No string interpolation.
- **Query extraction**: Complex read queries extracted into dedicated query repositories (e.g., `EventQueryRepository`, `OccurrenceQueryRepository`) to keep write repositories focused.

### Example

```php
interface EventRepositoryInterface {
    public function find(int $id): ?Event;
    public function find_by_slug(string $slug): ?Event;
    public function save(Event $event): Event;
    public function delete(int $id): bool;
    public function paginate(array $args = []): array;
    public function duplicate(int $id): ?Event;
}
```

## Alternatives Considered

### Active Record (Eloquent-style)

- **Pros**: Familiar pattern, less boilerplate.
- **Cons**: Models coupled to database. Hard to test without database. WordPress has no ORM — would need to build or import one.

### Data Mapper (Doctrine-style)

- **Pros**: Clean separation, unit of work, change tracking.
- **Cons**: Massive complexity for a WordPress plugin. Doctrine requires Composer, annotations/attributes, proxy generation. Overkill.

### Raw $wpdb Throughout

- **Pros**: No abstraction overhead. Direct SQL.
- **Cons**: Query duplication. No identity caching. Tests require database. Schema changes ripple through business logic.

## Consequences

- **Positive**: Services depend on interfaces, not implementations. Tests mock repositories without database. Identity maps eliminate N+1 queries for repeated lookups. Schema changes isolated to repository classes.
- **Negative**: Boilerplate — each entity needs model + interface + repository. More files than raw `$wpdb`.
- **Mitigations**: 18 interfaces in `Contracts/` provide clear API surface. Code generation could reduce boilerplate if entity count grows significantly.

## Related

- ADR-001: Custom Database Tables
- ADR-010: Per-Request Identity Maps
- `includes/Contracts/`: All repository interfaces
- `includes/Repositories/`: All repository implementations
