# ADR-019: The House Capacity Model

**Date:** 2026-07-13
**Status:** Accepted
**Category:** Domain Model
**Supersedes:** [ADR-005](ADR-005-capacity-model.md) (Fixed vs Shared Ticket Capacity Model)

## Context

ADR-005 ruled that shared ticket types draw from a *carve-out* pool:

```
shared_pool = occurrence.capacity - SUM(fixed capacities)
```

That is, a 200-seat room with a 50-seat fixed VIP tier offers a 150-seat pool to everything else.

The model does not survive contact with how venues actually sell tickets. A room is sold once, and
tiers are ways of describing the same seats, not separate allotments carved out of them. Two failures
made this concrete:

1. **The Attendees screen reported 638 seats available in a 250-seat hall** (a real production event). Three tiers — Standing, Seated, Youth — each carried the hall's 250 capacity,
   because from the operator's point of view each tier *can* sell the whole room. Summing
   `capacity - issued` per tier reported the room two and a half times over.

2. **The carve-out arithmetic and the display arithmetic disagreed.** Three incompatible readings of
   "capacity" coexisted: `TicketTypeQueryRepository::reduce_event_capacity()` (Events list),
   `AttendeesSummaryService::resolve_house()` (Attendees screen), and
   `SharedCapacityCalculator::get_shared_capacity()` (the carve-out). The screens and the till could
   not agree on how big the room was.

## Decision

**Tiers on an occurrence share one house. The house is sold once, however many ways it is described.**

```
house(occurrence) = occurrence.capacity            when set (the ceiling)
                  = MAX(capacity of FIXED tiers)   otherwise
                  = unbounded                      when neither is known

available(tier)   = min( own_remaining(tier), house - total_issued )

own_remaining(tier) = tier.capacity - tier.issued   for FIXED
                    = unbounded                     for SHARED
                    = from the seating add-on       for SEATED
```

The house is **never the sum** of tier capacities. Each occurrence is its own house; event-scoped
tiers collapse to one.

Under this model that production case is correct and unremarkable: three tiers each capped at 250 in a
250-seat hall with 112 issued each report 138 available, and the house has 138 left — not 638.

The rule has exactly one home: `NetterTechEvents\Services\Capacity\HouseRule`. Every consumer —
the Attendees summary, the Events list denominator, the WooCommerce stock sync, and the reservation
path — computes availability through it. A second implementation of the rule is a bug.

### Enforcement, not just display

The house binds at the point of sale, not only on screen. `TicketTypeStockRepository::increment_sold_count()`
opens a transaction, locks the tier row **and its peer tiers** in a deterministic order, and refuses
the write when `house_issued + quantity > house`. Two concurrent checkouts on different tiers of the
same house therefore cannot jointly oversell it.

Availability floors at zero. A house that has already been oversold (possible on sites that ran the
pre-NTE-144 code) reports 0 remaining and refuses further sales, rather than reporting a negative
remainder or fataling.

### Reading `ticket_types.capacity`

Only `CapacityType::FIXED` populates the `capacity` column meaningfully. `shared` and `seated` rows
may carry **stale** values there, because the admin form hides the capacity input without disabling
it. **Never read `capacity` without first checking `capacity_type`.**

## Consequences

- **Positive**: one rule, one implementation, one answer. The admin screens, the Events list, the
  WooCommerce stock figure and the till cannot disagree about the size of the room.
- **Positive**: the operator's mental model is honoured — "this tier can sell the whole room" is a
  legitimate configuration, not a misconfiguration to be validated away.
- **Negative**: a tier's advertised capacity is no longer a promise of exclusive allotment. A 250-cap
  tier in a 250-seat house shares those seats; it does not reserve them. Operators wanting a genuinely
  exclusive allotment must set a FIXED tier whose capacity is smaller than the house.
- **Removed**: `SharedCapacityCalculator` and the six methods that exposed the carve-out
  (`get_shared_capacity()`, `get_available_for_shared()`, `has_availability_for_shared()`,
  `get_shared_capacity_summary()`, `validate_fixed_allocations()`,
  `invalidate_shared_capacity_cache()`). They had no correct caller once the house rule landed.
  `validate_fixed_allocations()` in particular enforced the *wrong* invariant — it would have rejected
  the legitimate production configuration as over-allocated.

## Related

- Ticket NTE-144 — "Checkout can oversell an occurrence's house across ticket tiers"
- `includes/Services/Capacity/HouseRule.php` — the rule
- `includes/Repositories/HouseCapacityRepository.php` — the house context for a ticket type
- `includes/Repositories/TicketTypeStockRepository.php` — enforcement under lock at the point of sale
- `includes/Admin/Attendees/AttendeesSummaryService.php` — the Ticket Overview
- [ADR-005](ADR-005-capacity-model.md) — the superseded carve-out model
