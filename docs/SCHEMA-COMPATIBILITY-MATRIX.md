# Schema Compatibility Matrix

> **Audience:** Add-on developers verifying which tables exist in which Core version. Complements [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) (column-level definitions) with a cross-plugin compatibility view.

All custom database tables across the NTE plugin suite. Core, Seating, and Rentals tables use the `{$wpdb->prefix}nettertech_events_` prefix. (The prefix was renamed from `nte_` to `nettertech_events_` in Core 1.0.1 via `PrefixMigrationManager`; Seating and Rentals migrate in lockstep via their own `PrefixMigration` classes. The Migrator plugin retains the legacy `nte_` prefix because its tables are internal scratch data not affected by the WP.org rebrand.)

## Core Plugin Tables (20)

| Table | Owner | FK Dependencies |
|-------|-------|-----------------|
| `nettertech_events_series` | Core | `nettertech_events_events.id` |
| `nettertech_events_events` | Core | — |
| `nettertech_events_occurrences` | Core | `nettertech_events_events.id` |
| `nettertech_events_spaces` | Core | — |
| `nettertech_events_ticket_types` | Core | `nettertech_events_occurrences.id` |
| `nettertech_events_attendees` | Core | `nettertech_events_occurrences.id` |
| `nettertech_events_tickets` | Core | `nettertech_events_ticket_types.id`, `nettertech_events_attendees.id` |
| `nettertech_events_attendee_fields` | Core | `nettertech_events_events.id` |
| `nettertech_events_attendee_field_values` | Core | `nettertech_events_attendees.id`, `nettertech_events_attendee_fields.id` |
| `nettertech_events_organizers` | Core | — |
| `nettertech_events_event_organizers` | Core | `nettertech_events_events.id`, `nettertech_events_organizers.id` |
| `nettertech_events_categories` | Core | — |
| `nettertech_events_event_categories` | Core | `nettertech_events_events.id`, `nettertech_events_categories.id` |
| `nettertech_events_tags` | Core | — |
| `nettertech_events_event_tags` | Core | `nettertech_events_events.id`, `nettertech_events_tags.id` |
| `nettertech_events_activity_log` | Core | — |
| `nettertech_events_reminder_log` | Core | `nettertech_events_occurrences.id` |
| `nettertech_events_reservations` | Core | `nettertech_events_ticket_types.id` |
| `nettertech_events_waitlist` | Core (Pro feature) | `nettertech_events_ticket_types.id`, `nettertech_events_occurrences.id` |
| `nettertech_events_event_revisions` | Core | `nettertech_events_events.id` |

Source: `includes/Database/Schema.php::get_table_definitions()` and the per-table classes in `includes/Database/Tables/`.

### Deferred Schema Tables (7) (created by Core, used by extensions)

| Table | SQL Source | Used By |
|-------|-----------|---------|
| `nettertech_events_space_configurations` | DeferredSchema | Rentals |
| `nettertech_events_bookings` | DeferredSchema | Rentals |
| `nettertech_events_addon_types` | DeferredSchema | Rentals |
| `nettertech_events_booking_addons` | DeferredSchema | Rentals |
| `nettertech_events_resources` | DeferredSchema | Future |
| `nettertech_events_certification_types` | DeferredSchema | Future |
| `nettertech_events_user_certifications` | DeferredSchema | Future |

Source: `includes/Database/DeferredSchema.php::get_table_names()`.

## Seating Plugin Tables (6)

| Table | Owner | FK Dependencies |
|-------|-------|-----------------|
| `nettertech_events_seating_maps` | Seating | — |
| `nettertech_events_map_sections` | Seating | `nettertech_events_seating_maps.id` |
| `nettertech_events_seat_groups` | Seating | `nettertech_events_map_sections.id` |
| `nettertech_events_seats` | Seating | `nettertech_events_seat_groups.id` |
| `nettertech_events_seat_assignments` | Seating | `nettertech_events_seats.id` |
| `nettertech_events_seat_holds` | Seating | `nettertech_events_seats.id` |

Source: `nettertech-events-seating/includes/Database/SeatingSchema.php`.

## Rentals Plugin Tables (4 deferred)

Rentals uses 4 deferred tables created by Core's DeferredSchema:

| Table | Owner | Notes |
|-------|-------|-------|
| `nettertech_events_space_configurations` | Rentals (via DeferredSchema) | Room layout configurations |
| `nettertech_events_bookings` | Rentals (via DeferredSchema) | Booking records |
| `nettertech_events_addon_types` | Rentals (via DeferredSchema) | Add-on type definitions |
| `nettertech_events_booking_addons` | Rentals (via DeferredSchema) | Per-booking add-on instances |

Source: `nettertech-events-rentals/includes/Database/RentalsSchema.php`.

## Migrator Plugin Tables (4)

Migrator tables retain the legacy `nte_` prefix — they are internal import-session scratch data and were not part of the WP.org rebrand.

| Table | Owner | FK Dependencies |
|-------|-------|-----------------|
| `nte_import_sessions` | Migrator | — |
| `nte_import_logs` | Migrator | `nte_import_sessions.id` |
| `nte_import_mappings` | Migrator | `nte_import_sessions.id` |
| `nte_import_mappings_canonical` | Migrator | — |

Source: `nettertech-events-migrator/includes/Database/ImportSchema.php`.

## Pro Plugin Tables

Pro does not own any custom tables. It uses Core's `nettertech_events_waitlist` and `nettertech_events_ticket_types` tables.

## Cross-Plugin Dependencies

```
Core tables ← Seating  (reads nettertech_events_occurrences, _ticket_types, _attendees)
Core tables ← Rentals  (reads nettertech_events_events, uses DeferredSchema tables)
Core tables ← Migrator (reads/writes all Core tables during import/export)
Core tables ← Pro      (reads nettertech_events_waitlist, nettertech_events_ticket_types)
```

## Schema Versioning

Each plugin maintains its own schema version via `wp_options`:

| Plugin | Option Key | Source |
|--------|-----------|--------|
| Core | `nettertech_events_db_version` | `Schema::DB_VERSION` |
| Seating | `nettertech_events_seating_db_version` | `SeatingSchema::VERSION_OPTION` |
| Rentals | `nettertech_events_rentals_db_version` | `RentalsSchema::VERSION_KEY` |
| Migrator | `nte_importer_db_version` | `ImportSchema::DB_VERSION_OPTION` |

---

**Last Updated:** 2026-05-11
