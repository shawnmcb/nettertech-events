# Glossary

> **Audience:** Anyone encountering a NetterTech Events domain term they don't recognize. One-line definitions; follow the cross-reference links for deeper treatment.

**Last Updated:** 2026-04-20

## Core domain terms

**Event** — A conceptual happening (e.g., "Friday Night Jazz"). Defined once in `nte_events`; has zero or more occurrences. Stored as a custom-table row, optionally mirrored as a [shadow post](#shadow-post).

**Occurrence** — A single instance of an event at a specific start/end datetime. Stored in `nte_occurrences`. Pre-computed from recurrence rules rather than expanded on each page load. This is what users register for.

**Series** — A named grouping of related recurring events (e.g., a jazz series running 12 weeks). Stored in `nte_series`. Optional.

**Single event** — An event with `event_type = 'single'`: exactly one occurrence, no recurrence rule.

**Recurring event** — An event with `event_type = 'recurring'`: one or more occurrences generated from an [RRULE](#rrule). The generator horizon is configurable; the [Occurrence Horizon Extender](#occurrence-horizon-extender) extends it nightly via WP-Cron.

**RRULE** — An iCalendar recurrence rule per [RFC 5545 §3.8.5.3](https://www.rfc-editor.org/rfc/rfc5545#section-3.8.5.3). Supported frequencies: daily, weekly, monthly, yearly with `INTERVAL`, `BYDAY`, `BYMONTHDAY`, `UNTIL`, `COUNT`, `WKST`.

**Space** — A bookable room or venue. Stored in `nte_spaces`. Core columns only; the Rentals add-on appends rental-specific columns via `ALTER TABLE`.

**Organizer** — A party who runs an event (name, bio, contact). Many-to-many with events via `nte_event_organizers`.

**Category** — A hierarchical event taxonomy (e.g., "Music › Jazz"). Stored in `nte_categories`, junctioned via `nte_event_categories`. Internal tables, not WordPress taxonomies.

**Tag** — A flat event label (no hierarchy). Stored in `nte_tags`.

## Ticketing terms

**Ticket type** — A pricing tier (e.g., "General Admission", "VIP", "Member"). Scoped one of three ways — see [Scope](#scope).

**Scope** — How a ticket type attaches to the event/occurrence hierarchy:
- `occurrence` — specific to a single occurrence (`occurrence_id` set)
- `event` — a series pass, valid for all occurrences of the event
- `template` — a template that auto-propagates to new occurrences as they're generated

See [ADR-005](architecture/ADR-005-capacity-model.md) for the capacity model.

**Series pass** — A ticket with `scope='event'` that grants entry to every occurrence in the event's series.

**Template ticket** — A ticket with `scope='template'` whose definition is cloned onto each new occurrence when `OccurrenceGenerator` creates it.

**Ticket** — An individual purchased or RSVP'd ticket with a unique scannable code (`XXXX-XXXX-XXXX-XXXX` hex). One row per person per ticket type in `nte_tickets`.

**Ticket code** — A 16-hex-character code produced by `random_bytes(16)`. Used for check-in scan.

**Attendee** — A registration record (one per order or RSVP) in `nte_attendees`. A single attendee row can represent a party of multiple people (tracked via `quantity`).

**Party size** — The number of people represented by a single attendee row (the `quantity` column). Used for capacity tracking and per-attendee check-in counters.

**Capacity** — The maximum number of tickets (or attendees, depending on context) that can be sold for an occurrence or ticket type. See [ADR-005](architecture/ADR-005-capacity-model.md).

**Reservation** — A per-cart-session capacity hold in `nte_reservations` with an expiry. Prevents overselling while users are mid-checkout. Swept hourly by `ReservationManager::sweep_expired()`.

**Waitlist** — Queue of users wanting to join a sold-out occurrence. Stored in `nte_waitlist`. Promotion to attendee is manual (Pro auto-promotion is a planned feature).

**Sold count** — A denormalized counter on `nte_ticket_types.sold_count` updated atomically on order completion. The authoritative running total for availability.

**Reserved count** — A denormalized counter on `nte_ticket_types.reserved` tracking cart-session holds aggregate.

## Integration terms

**RSVP** — A free registration (no payment). Submitted via the `[nte_rsvp]` shortcode. Produces an attendee record without touching WooCommerce.

**WooCommerce ticketing** — Paid-ticket flow where ticket types correspond to WooCommerce products/variations. Cart, checkout, and orders are owned by WooCommerce; ticket generation happens on order completion. See [WC-INTEGRATION-BOUNDARY.md](WC-INTEGRATION-BOUNDARY.md).

**HPOS** — WooCommerce High-Performance Order Storage. The plugin declares compatibility and uses the HPOS-compatible `wc_get_order()` + `$order->update_meta_data()` API rather than legacy `get_post_meta()` on order IDs.

**Shadow post** — A `wp_posts` row with post type `nte_event` that mirrors a published event's title and slug. Enables WordPress admin-bar search and Gutenberg link-dialog discovery without abandoning the custom-tables architecture. Synchronized by `ShadowPostSyncService`.

**Block** — A Gutenberg block. This plugin ships three: `nettertech-events/calendar`, `nettertech-events/carousel`, `nettertech-events/event-grid`.

**Shortcode** — A WordPress text-macro. The plugin registers `[nte_calendar]`, `[nte_list]` (alias `[nte_grid]`, `[nettertech_events]`), `[nte_carousel]`, `[nte_rsvp]`, `[nte_regulars]`.

**Beaver Builder module** — A page-builder module. The plugin auto-registers modules when Beaver Builder is active.

## Infrastructure terms

**Occurrence Horizon Extender** — A WP-Cron service (`OccurrenceHorizonExtender`) that runs daily to extend pre-computed occurrences into the future for all active recurring events, keeping the calendar populated.

**Activity Log** — An OWASP A09-compliant audit trail of admin actions stored in `nte_activity_log`. Captures who did what, when, from which IP, for every event/occurrence/ticket/attendee mutation.

**Revision** — A pre-save snapshot of an event's data in `nte_event_revisions`. Enables undo/restore and change-history views.

**Reminder log** — Deduplication table (`nte_reminder_log`) that prevents the same reminder email going twice to the same attendee for the same occurrence.

**Cache manager** — `CacheManager` coordinates cache invalidation across object cache and transient stores; responds to `nte_*_cache_invalidate_*` actions.

**Identity map** — Per-request cache of loaded entities to prevent duplicate DB reads within a single HTTP request. See [ADR-010](architecture/ADR-010-identity-maps.md).

**Service registry** — Static facade over the DI Container that was the original composition root. Now used only for composition-root bootstrapping and test-infrastructure overrides; production code uses constructor injection. See [ADR-013](architecture/ADR-013-dependency-injection-migration.md).

**Deferred schema** — Tables defined in `DeferredSchema.php` that are only created when a specific add-on plugin is active. See [ADR-014](architecture/ADR-014-deferred-schema-pattern.md).

## Related

- [Database Schema](DATABASE-SCHEMA.md) — Every table and column defined precisely.
- [Hooks Reference](HOOKS.md) — Actions and filters by domain.
- [architecture/](architecture/) — 16 Architecture Decision Records covering the key design choices referenced above.
