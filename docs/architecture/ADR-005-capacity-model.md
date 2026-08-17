# ADR-005: Fixed vs Shared Ticket Capacity Model

**Date:** 2025-03-01
**Status:** SUPERSEDED (2026-07-13) by [ADR-019: The House Capacity Model](ADR-019-house-capacity-model.md)
**Category:** Domain Model

> **⚠ This decision is no longer in force. The model below is wrong and the code implementing it has
> been removed.**
>
> The carve-out arithmetic recorded here — `shared_pool = occurrence.capacity - SUM(fixed capacities)`
> — treats tiers as separate allotments carved out of the room. Venues do not sell rooms that way. A
> tier capped at the house size means "this tier may sell the whole room", not "this tier reserves the
> whole room", so summing per-tier availability reports the room several times over. On one
> production venue's 250-seat hall it reported **638 seats available**.
>
> Tiers on an occurrence share **one house**, sold once. See [ADR-019](ADR-019-house-capacity-model.md)
> for the model now in force, and ticket NTE-144 for the failure that forced the change.
>
> `SharedCapacityCalculator` and its six methods (referenced throughout this document) **no longer
> exist**. This ADR is retained as the historical record of what was decided in 2025-03, not as a
> description of the system.

## Context

A venue event has a physical capacity (e.g., 200 seats). The event offers multiple ticket types (General Admission $25, VIP $50, Student $15). The system needs to control how many tickets of each type can be sold, while ensuring total attendance never exceeds the venue capacity.

Two capacity models exist in the ticketing industry:

1. **Fixed capacity per ticket type**: Each ticket type has its own independent cap. GA: 150, VIP: 30, Student: 20 = 200 total.
2. **Shared capacity pool**: All ticket types draw from a common pool. GA, VIP, and Student share 200 seats — selling 1 GA ticket reduces availability for all types.

## Decision

**Support both models simultaneously via a `capacity_type` column on `nte_ticket_types`.**

```sql
-- capacity_type: 'fixed' or 'shared'
-- capacity: integer (NULL = unlimited for fixed; ignored for shared)

-- Fixed: 50 VIP tickets, independent of other types
INSERT INTO nte_ticket_types (name, capacity_type, capacity) VALUES ('VIP', 'fixed', 50);

-- Shared: draws from occurrence capacity pool
INSERT INTO nte_ticket_types (name, capacity_type, capacity) VALUES ('GA', 'shared', NULL);
```

### Capacity Calculation

```
occurrence.capacity = 200  (physical venue limit)

Fixed ticket types:
  VIP: capacity = 50 (reserved exclusively)

Shared pool = occurrence.capacity - sum(fixed capacities)
            = 200 - 50
            = 150

Shared ticket types (GA, Student) compete for the 150-seat shared pool.
```

### Implementation

- **`CapacityCalculator`**: Per-ticket-type availability (fixed types check own cap, shared types check shared pool).
- **`SharedCapacityCalculator`**: Shared pool calculations — `get_shared_capacity()`, `get_available_for_shared()`, `validate_fixed_allocations()`.
- **`CapacityService`**: Orchestrates capacity checks during checkout. Includes buffer stock (reserved seats not for online sale) and WooCommerce stock sync.
- **`ReservationManager`**: Temporary ticket holds during checkout to prevent overselling under concurrency.

### Concurrency Safety

- Reservation system holds tickets for a configurable window (default 15 minutes).
- `sold_count` updates use atomic SQL: `UPDATE SET sold_count = sold_count + 1 WHERE sold_count < capacity`.
- Shared pool availability subtracts both sold counts and pending reservations.

## Alternatives Considered

### Fixed-Only Model

- **Pros**: Simpler. Each ticket type is independent. No shared pool calculation.
- **Cons**: Inflexible. Admin must predict exact demand per type. If GA sells out but VIP has 20 unsold, those seats are wasted.

### Shared-Only Model (Like Movie Theaters)

- **Pros**: Simplest. All types share one pool.
- **Cons**: Can't guarantee VIP allocation. A rush on GA tickets could consume seats intended for VIP guests.

### Seat-Based Assignment (Reserved Seating)

- **Pros**: Individual seat mapping. Most precise.
- **Cons**: Massive complexity — seat maps, row/section management, accessible seating rules. Scope explosion for a venue events plugin.

## Consequences

- **Positive**: Flexible enough for general admission venues (shared) and reserved-section venues (fixed). Admin can mix types — reserve 50 fixed VIP seats and let GA/Student share the remainder.
- **Negative**: Shared capacity adds complexity to availability checks (must sum across all shared types). Validation must ensure fixed allocations don't exceed occurrence capacity.
- **Mitigations**: `SharedCapacityCalculator::validate_fixed_allocations()` prevents over-allocation at ticket type creation time.

## Related

- `includes/Services/CapacityCalculator.php`: Per-ticket-type capacity
- `includes/Services/SharedCapacityCalculator.php`: Shared pool calculations
- `includes/Services/CapacityService.php`: Orchestration and WooCommerce sync
- `includes/Services/ReservationManager.php`: Temporary ticket holds
