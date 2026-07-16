# ADR-001: Custom Database Tables Over post_meta

**Date:** 2024-12-23
**Status:** Accepted
**Category:** Database Architecture

## Context

WordPress provides several built-in storage mechanisms for structured data:

- **Custom Post Types + post_meta**: The "WordPress way" — store entities as posts with metadata in `wp_postmeta`. Leverages WP_Query, admin UI scaffolding, and REST API out of the box.
- **Custom tables**: Direct SQL tables with `$wpdb`, created via `dbDelta()` on activation.
- **Taxonomies**: For categorization relationships.

NetterTechEvents manages events, occurrences (event dates/times), ticket types, attendees, tickets, orders, and check-ins. These entities have:

- **High cardinality relationships**: An event has many occurrences, each occurrence has many attendees, each attendee has many tickets. A venue running 10 events/week generates ~50,000+ attendee records per year.
- **Frequent aggregate queries**: "How many tickets sold for this occurrence?", "What's the capacity remaining?", "List all check-ins today." These require JOINs and GROUP BY.
- **Strict data typing**: Capacity is an integer, prices are decimals, timestamps are datetimes. `post_meta` stores everything as `longtext`.

## Decision

**Use custom database tables for all domain entities.** WordPress post types and taxonomies are used only for public-facing content that benefits from WordPress's routing and template system (event categories, tags).

### Table Inventory (21 tables, showing representative subset)

| Table | Purpose | Indexed Columns |
|-------|---------|-----------------|
| `nte_events` | Event definitions | `slug`, `status`, `series_id` |
| `nte_occurrences` | Event date/time instances | `event_id`, `start_datetime`, `status` |
| `nte_ticket_types` | Pricing tiers per event | `event_id`, `occurrence_id`, `capacity_type` |
| `nte_attendees` | People attending events | `occurrence_id`, `email`, `order_id` |
| `nte_tickets` | Individual tickets | `attendee_id`, `ticket_code`, `status` |
| `nte_categories` | Event categories (with WP term sync) | `slug` |
| `nte_tags` | Event tags | `slug` |
| `nte_organizers` | Event organizers | `slug` |
| `nte_spaces` | Venue spaces/rooms | `slug` |
| `nte_series` | Event series groupings | `slug` |
| `nte_waitlist` | Waitlist entries | `occurrence_id`, `email`, `status` |
| `nte_reservations` | Temporary ticket holds | `ticket_type_id`, `expires_at` |
| `nte_check_ins` | Check-in records | `ticket_id`, `checked_in_at` |
| `nte_activity_log` | Audit trail | `entity_type`, `entity_id`, `created_at` |
| `nte_settings` | Plugin settings | `setting_key` |
| `nte_migrations` | Schema version tracking | `version` |

## Alternatives Considered

### Custom Post Types + post_meta

- **Pros**: WP_Query, admin columns, REST API, revisions, trash — all free.
- **Cons**:
  - **EAV performance**: `wp_postmeta` is an Entity-Attribute-Value table. Querying "all occurrences for event 42 ordered by start time" requires a JOIN per meta key. With 5 meta fields per occurrence, that's 5 JOINs.
  - **No type safety**: All values stored as `longtext`. Capacity "50" and "fifty" are equally valid.
  - **Table bloat**: The Events Calendar (TEC) stores events as CPTs — their `postmeta` table reaches millions of rows on active sites, degrading all WordPress queries (WP_Query scans `postmeta` for every query with meta_query).
  - **No foreign keys**: Relationships between CPTs require storing IDs in meta, with no referential integrity.
  - **Aggregate queries impossible**: "Total tickets sold per occurrence" requires loading all attendee posts, extracting meta values, then summing in PHP.

### Hybrid (CPT for events, custom tables for tickets/attendees)

- **Pros**: Events get WP URL routing, archive pages, SEO.
- **Cons**: Split storage complicates queries that span both. Event deletion must cascade to custom tables manually. Two query APIs (WP_Query + $wpdb) in the same codebase.

## Consequences

- **Positive**: 10-50x faster aggregate queries. Type-safe columns. Composite indexes on common query patterns. Clean data model visible in schema. JOINs are natural SQL, not meta_query hacks.
- **Negative**: No WP_Query, no built-in admin list tables (must build from scratch), no automatic REST API, no revisions, no trash. URL routing requires custom rewrite rules.
- **Mitigations**: Repository pattern abstracts all queries behind interfaces. Custom admin list tables extend `WP_List_Table`. REST API built manually with `register_rest_route()`. Template system with theme overrides replaces CPT template hierarchy.

## Related

- ADR-009: Application-Level Referential Integrity
- `includes/Database/Schema.php`: Table definitions
- `includes/Database/Tables/`: Individual table classes
