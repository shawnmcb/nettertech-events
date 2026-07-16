# ADR-014: Deferred Schema Pattern for Add-on Plugins

**Date:** 2026-02-15
**Status:** Accepted
**Category:** Database Architecture

## Context

The NetterTech Events suite follows a modular architecture: a core plugin provides event management, and add-on plugins (Rentals, Pro, Seating) extend it with domain-specific functionality. Each add-on requires its own database tables that reference core tables via foreign keys.

Two approaches exist for managing add-on table creation:

1. **Each add-on defines its own schema** on activation and creates tables independently.
2. **Core holds deferred table definitions** that add-ons trigger when they activate.

## Decision

We use a **DeferredSchema** class in the core plugin (`includes/Database/DeferredSchema.php`) that holds SQL definitions for add-on tables organized by feature domain:

- **Space Rentals**: `ve_spaces`, `ve_bookings`, `ve_space_configurations`, `ve_addon_types`, `ve_booking_addons`
- **Seating**: `ve_seating_maps`, `ve_seats`, `ve_seat_assignments`, `ve_seat_holds`
- **Resources**: `ve_resources`, `ve_certification_types`, `ve_user_certifications`

Add-on plugins call `DeferredSchema::get_*_sql()` methods during their activation hook rather than embedding SQL in their own codebase.

## Rationale

### Centralized schema ownership

All table definitions live in one place. This prevents:
- Schema drift between core and add-on table definitions.
- Duplicate `dbDelta()` logic across plugins.
- Foreign key reference errors from mismatched column types or names.

### Consistent conventions

DeferredSchema enforces the same patterns as `Schema.php`: `ve_` prefix, `id BIGINT UNSIGNED AUTO_INCREMENT`, `created_at`/`updated_at` timestamps, `utf8mb4_unicode_ci` collation, consistent index naming.

### Upgrade coordination

When core tables change (e.g., column type widening), the DeferredSchema definitions update in the same commit, ensuring add-on tables stay compatible on next activation/upgrade.

### Clean add-on activation

Add-on activation code is simple: call the appropriate DeferredSchema methods, run `dbDelta()`, done. No SQL string construction in add-on code.

## Trade-offs

- **Core plugin awareness of add-on schemas**: Core must know about add-on table structures. This is acceptable because the tables reference core tables and must match core conventions.
- **Version coupling**: Add-on table definitions depend on the core plugin version. Mitigated by semantic versioning and upgrade hooks.
- **Single point of change**: All schema changes go through core, even for add-on tables. This is a feature, not a bug — it prevents uncoordinated changes.

## Alternatives Considered

- **Add-ons define their own SQL**: Rejected because it leads to schema drift, duplicated boilerplate, and foreign key mismatches.
- **Shared schema library (Composer package)**: Rejected as over-engineered for a plugin suite where the core plugin is always present.
- **Database migration framework (Phinx/Doctrine)**: Rejected for WordPress context — `dbDelta()` is the standard, and migrations add deployment complexity for self-hosted users.
