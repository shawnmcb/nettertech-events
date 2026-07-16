# Cross-Plugin Interaction Matrix

> **Audience:** Add-on developers integrating with the NetterTech Events suite. Describes which plugin owns which behavior and how the plugins interact at runtime.

> **Note:** This document is superseded by [SCHEMA-COMPATIBILITY-MATRIX.md](SCHEMA-COMPATIBILITY-MATRIX.md) for schema and table compatibility information. This file is retained for the interaction matrix content but may contain outdated details.

> **Stability tiers and audit-engineering reference:** for the formal stability tier (`Public Contract` / `Internal` / `Private`) per surface, version compatibility assertions, and remediation candidates, see the internal audit-engineering companion `COMPATIBILITY-MATRIX.md` (maintained outside this repository). That document and `CONTRACT-INVENTORY.md` are the source of truth for cross-plugin contract policy; this file is the runtime-developer-facing description.

## Overview

The NetterTech Events suite consists of 5 plugins with a hub-and-spoke architecture. The **Base** plugin is the hub; every satellite requires it. No satellite requires any other satellite. The satellites extend base functionality via hooks, shared interfaces, and direct database reads.

| Plugin | Slug | Namespace | Role |
|--------|------|-----------|------|
| **Base** | `nettertech-events` | `NetterTechEvents` | Core: events, tickets, calendar, check-in, WC integration, capacity, waitlist |
| **Pro** | `nettertech-events-pro` | `NetterTechEventsPro` | Paid: multi-ticket, promo codes, waitlist notifications, export, revenue reports |
| **Rentals** | `nettertech-events-rentals` | `NetterTechEventsRentals` | Paid: space rental booking, availability, contracts, WC integration |
| **Seating** | `nettertech-events-seating` | `NetterTechEventsSeating` | Paid: seating maps, seat selection, holds, assignments, WC integration |
| **Migrator** | `nettertech-events-migrator` | `NetterTechEventsMigrator` | Free: data import from competing plugins (TEC) - lowers switching barrier |

---

## Dependency Direction Matrix

| From / To | Base | Pro | Rentals | Seating | Migrator |
|-----------|------|-----|---------|---------|----------|
| **Base** | -- | fires hooks consumed by Pro | fires hooks consumed by Rentals | fires hooks consumed by Seating | none |
| **Pro** | **requires** (hard) | -- | none | fires the two check-in filters consumed by Seating | none |
| **Rentals** | **requires** (hard) | none | -- | none | none |
| **Seating** | **requires** (hard) | consumes check-in filters fired by Pro (optional) | none | -- | none |
| **Migrator** | **requires** (hard) | none | none | none | -- |

**Relationship types used:**
- `requires` -- hard dependency; satellite will not initialize without base plugin active
- `fires hooks consumed by` -- base fires actions/filters that satellite plugins optionally consume
- `none` -- no direct dependency or interaction

**One satellite-to-satellite dependency exists** (corrected 2026-07-16). Seating subscribes to `nettertech_events_checkin_lookup_data` and `nettertech_events_checkin_search_item`, and Pro's `CheckInScanController` is what fires them: Core reserves the two names but has no check-in scan controller of its own. The dependency is optional and one-way (Seating degrades to no seat labels in check-in results when Pro is inactive; nothing fatals), and it is still hook-mediated rather than a direct call, so the no-direct-calls rule holds. No satellite *requires* another satellite. Separately, Rentals and Seating both use the `nettertech_events_spaces` table, now owned by Base (C2, updated 2026-03-30). Base provides `SpaceRepositoryInterface`; Rentals and Seating consume it. Rentals extends the Base `Space` model and adds rental-specific columns via `ALTER TABLE`.

---

## Plugin Load Order

| Priority | Hook | Plugin | Mechanism |
|----------|------|--------|-----------|
| 5 | `plugins_loaded` | **Pro** | `nte_pro_init()` registers Pro service providers and the `nettertech_events_init` listener before Base initializes |
| 10 | `plugins_loaded` | **Base** | Default WP load; defines `NETTERTECH_EVENTS_VERSION` constant; builds the DI container, then fires `nettertech_events_init` |
| 20 | `plugins_loaded` | **Rentals** | Explicit priority 20; checks `defined('NETTERTECH_EVENTS_VERSION')` |
| 20 | `plugins_loaded` | **Migrator** | Explicit priority 20; checks `defined('NETTERTECH_EVENTS_VERSION')` |
| 25 | `plugins_loaded` | **Seating** | Explicit priority 25; checks `defined('NETTERTECH_EVENTS_VERSION')` |
| -- | `nettertech_events_init` | **Pro** | Pro's `Plugin::init_modules()` called on `nettertech_events_init` (fired by Base after full init) |

### Timing Dependencies

- **Pro loads at priority 5**, before Base at priority 10. Pro must register its service providers (via the `nettertech_events_service_providers` filter) and its `nettertech_events_init` listener before Base builds its DI container and fires that action at priority 10. Registering later means `init_modules()` never runs and no Pro module loads. Pro's module initialization (`init_modules`) still runs on the `nettertech_events_init` action that Base fires after it is fully bootstrapped. (Pro was briefly set to priority 15 by commit a4c2fdb on the mistaken premise that load order did not matter; that broke `init_modules()` entirely. See `.claude/plans/plan-nte-pro-init-timing-fix-2026-05-20.md`.)
- **Seating loads at priority 25** explicitly, after Base, Pro, and the priority-20 satellites.
- **Rentals and Migrator** load at priority 20, after Base, with `defined('NETTERTECH_EVENTS_VERSION')` / `class_exists()` guards as a safety net.
- **Base fires `nettertech_events_init`** from `Plugin::init()`, which runs during `plugins_loaded`. All satellites that need the base container or services should hook into `nettertech_events_init`, not `plugins_loaded`.

### Runtime Dependency Checks (`plugins_loaded`)

All satellites check `defined('NETTERTECH_EVENTS_VERSION')` + minimum version at runtime. Failure = admin notice, no initialization.

| Plugin | Min Base Version | Failure Behavior |
|--------|-----------------|-----------------|
| **Pro** | >= 0.9.0 | Admin notice; no initialization |
| **Rentals** | >= 0.9.4 | Admin notice; no initialization |
| **Seating** | >= 0.9.4 | Admin notice; no initialization |
| **Migrator** | >= 0.9.4 | Admin notice; no initialization |

### Activation Checks (`register_activation_hook`)

Activation checks use `wp_die()` to block plugin activation when hard prerequisites are missing. Not all satellites have activation checks.

| Plugin | Checks | Failure Behavior |
|--------|--------|-----------------|
| **Pro** | Core plugin active (silent) | Returns without creating tables; no `wp_die()` |
| **Rentals** | PHP >= 8.2, WP >= 6.4, `NETTERTECH_EVENTS_VERSION` >= 0.9.4 | `wp_die()` with back link |
| **Seating** | MySQL >= 8.0 (JSON columns), `enum_exists(CapacityType)` | `wp_die()` with back link |
| **Migrator** | None (creates tables unconditionally) | N/A |

---

## Hook Interaction Inventory

> **Calibration (2026-07-16):** The legacy `nte_*` hook literals that this section used to carry were backported to the current `nettertech_events_*` names, and every name and firing site below was re-verified against source. Base hook literals are defined in `includes/Core/Hooks.php` (e.g. `Hooks::INIT = 'nettertech_events_init'`, `Hooks::CAPACITY_RELEASED = 'nettertech_events_capacity_released'`). When subscribing to a base hook, prefer the constant from `Hooks::*` over the literal string: constants are stable across renames, literals can drift. Two prefixes did not move and are not typos: the Migrator plugin still fires `nte_migrator_*` hooks and owns `nte_import_*` tables, and Pro's bootstrap function is still `nte_pro_init()`. The audit-engineering reference at `audit/suite/contracts/COMPATIBILITY-MATRIX.md` carries the same literal names alongside their stability tiers.

### Hooks Fired by Base, Consumed by Satellites

#### Actions

| Hook | Base Location | Consumer(s) | Purpose |
|------|--------------|-------------|---------|
| `nettertech_events_init` | `Plugin::init()` | Pro (`Plugin::init_modules`) | Satellite module initialization after base is fully loaded |
| `nettertech_events_attendee_created` | `OrderAttendeeCreator::create_individual_attendees()`, `ActivityLogHooks` | Seating (`SeatingOrderHandler::convert_holds_to_assignments`) | Convert temporary seat holds to permanent assignments on order completion |
| `nettertech_events_registration_voided` | `OrderHandler::void_attendees_for_order()` | Seating (`SeatingOrderHandler::handle_registration_voided`) | Cancel seat assignments when attendee is voided |
| `nettertech_events_capacity_released` | `CapacityService::release_capacity()` | Pro (`WaitlistModule::handle_capacity_released`) | Trigger waitlist auto-promotion when capacity becomes available |
| `nettertech_events_waitlist_promoted` | `WaitlistService::promote_next()` | Base itself (`WaitlistEmailHandler::handle_promoted`); no satellite consumer | Send notification email to promoted waitlist entry |
| `nettertech_events_after_sold_out` | Template: `ticket-form.php`; `RSVPFormShortcode::render()` | Pro (`WaitlistFrontend::render_waitlist_panel`) | Render waitlist join panel on sold-out ticket types |
| `nettertech_events_cache_invalidated` | `CacheManager::invalidate_all()`, `BulkImportHandler` | Rentals (`EventAwarenessService::invalidate_calendar_caches`) | Clear rental calendar caches when base plugin caches are flushed |
| `nettertech_events_after_save_event` | `EventRepository::save()` | Rentals (`EventAwarenessService::invalidate_calendar_caches`) | Clear rental calendar caches when events change |
| `nettertech_events_legacy_rebrand_complete` | `MigrationManager::run_migration()` (fired via the `COMPLETE_ACTION` constant) | Seating (`Plugin::rename_legacy_transients`) | Rename legacy `ve_` transients after the base plugin rebrand migration |
| `nettertech_events_calendar_enqueue_scripts` | `Assets::maybe_enqueue_frontend()` | Rentals (`AvailabilityCalendar::enqueue_scripts`) | Enqueue rental availability calendar scripts alongside base calendar |
| `nettertech_events_calendar_render_complete` | `CalendarShortcode::render()` | (none currently) | Extension point for post-calendar rendering |
| `nettertech_events_ticket_add_button_area` | `TicketsMetabox` (4 sites) | (none currently) | Injection point for supplemental ticketing UI beside the base controls |
| `nettertech_events_settings_tab_render` | `SettingsPage::render()` | Pro (`Plugin::render_license_tab_if_active`) | Render custom settings tab content; receives `$active_tab`, `$settings` |
| `nettertech_events_settings_tab_save` | `SettingsSaveHandler::handle_save()` | Pro (`Plugin::save_license_settings_if_active`) | Save custom settings tab data; receives `$active_tab` |

#### Filters

| Filter | Base Location | Consumer(s) | Purpose |
|--------|--------------|-------------|---------|
| `nettertech_events_settings_tabs` | `SettingsPage::get_tabs()` | Pro (`Plugin::add_license_tab`), Rentals (`Plugin::add_license_tab`, `RentalsSettingsTab::add_tab`), Seating (`LicenseSettingsPage::add_license_tab`) | Add license/rentals tabs to settings page |
| `nettertech_events_show_frontend_branding` | `FrontendBranding::should_show_branding()` | (none currently; reserved for opt-in integrations) | Override the site-owner opt-in setting for "Powered by" branding |
| `nettertech_events_ticket_scan_data` | Template: `scan-result.php` | Seating (`SeatingCheckInIntegration::filter_ticket_scan_data`) | Add seat info to ticket scan display |
| `nettertech_events_calendar_shortcode_atts` | `CalendarShortcode::render()` | Rentals (`AvailabilityCalendar::filter_shortcode_atts`) | Add rental-specific shortcode attributes |
| `nettertech_events_calendar_wrapper_classes` | `CalendarShortcode::render()` | Rentals (`AvailabilityCalendar::filter_wrapper_classes`) | Add CSS classes for rental availability mode |
| `nettertech_events_calendar_header_html` | `CalendarShortcode::render()` | Rentals (`AvailabilityCalendar::filter_header_html`) | Inject space/availability filters into calendar header |
| `nettertech_events_calendar_js_config` | `Assets::localize_scripts()` | Rentals (`AvailabilityCalendar::filter_js_config`) | Add rental availability endpoints/config to calendar JS |
| `nettertech_events_detected_views` | `PageContextDetector::page_has_nettertech_events_content()` | Rentals (`CalendarModule::register_rental_calendar_view`) | Register rental calendar as a recognized view for asset loading |
| `nettertech_events_capacity_types` | `CapacityType::for_scope()` | (none currently; Seating resolves capacity type through the Seating-owned `nettertech_events_seating_ticket_capacity_type` filter instead) | Extension point for adding custom capacity types (e.g. `seated`) |
| `nettertech_events_available_count` | `CapacityCalculator::get_available_count()` | Pro (`SalesModule::bound_by_allotment`) | Override availability calculation for custom capacity types |
| `nettertech_events_capacity_check` | `CapacityService::has_availability()` | (none currently) | Override capacity check for custom capacity types |

### Hooks Fired by Pro, Reserved by Core

These two filter names are declared in the Core `Hooks` class as part of the cross-plugin contract, but Core does not fire them: the firing site is Pro's `CheckInScanController` (Core ships no check-in scan controller). A subscriber therefore receives nothing unless Pro is active. This is a satellite-to-satellite path, not a base-to-satellite one: Pro fires, Seating consumes.

| Filter | Location | Consumer(s) | Purpose |
|--------|----------|-------------|---------|
| `nettertech_events_checkin_lookup_data` | Pro (`CheckInScanController`) | Seating (`SeatingCheckInIntegration::filter_lookup_response`) | Add seat assignment info to check-in lookup response |
| `nettertech_events_checkin_search_item` | Pro (`CheckInScanController`) | Seating (`SeatingCheckInIntegration::filter_search_item`) | Add seat label to check-in search results |

### Hooks Fired by Satellites

#### Pro Plugin Actions

| Hook | Location | Purpose | Consumers |
|------|----------|---------|-----------|
| `nettertech_events_pro_loaded` | `Plugin::init_modules()` | Signals Pro modules are loaded | None currently |
| `nettertech_events_pro_waitlist_notification_sent` | `WaitlistNotificationService` | Fires after waitlist notification email sent | None currently |
| `nettertech_events_pro_waitlist_entry_expired` | `WaitlistCron` | Fires when a notified waitlist entry expires | None currently |

#### Pro Plugin Filters

| Filter | Location | Purpose | Consumers |
|--------|----------|---------|-----------|
| `nettertech_events_pro_validate_promo_code` | `PromoCodeService` | Override promo code validation | None currently |
| `nettertech_events_pro_calculate_discount` | `PromoCodeService` | Override discount calculation | None currently |
| `nettertech_events_pro_report_query_args` | `RevenueReportService` | Customize report query parameters | None currently |
| `nettertech_events_pro_export_async_threshold` | `BackgroundExportAjax`, `AdvancedExportModule` | Configure async export threshold | None currently |
| `nettertech_events_pro_waitlist_email_subject` | `WaitlistNotificationService` | Customize waitlist email subject | None currently |
| `nettertech_events_pro_waitlist_email_body` | `WaitlistNotificationService` | Customize waitlist email body | None currently |
| `nettertech_events_pro_export_columns` | `ColumnRegistry` | Customize export columns | None currently |
| `nettertech_events_pro_export_file_ttl` | `ExportFileManager` | Configure export file retention | None currently |
| `nettertech_events_pro_waitlist_notification_ttl` | `WaitlistCron` | Configure notification expiry TTL | None currently |

#### Seating Plugin Actions

| Hook | Location | Purpose | Consumers |
|------|----------|---------|-----------|
| `nettertech_events_seating_init` | `Plugin::init()` | Signals Seating plugin is initialized | None currently |
| `nettertech_events_seating_schema_upgraded` | `Plugin::init()` | Fires after schema migration | None currently |
| `nettertech_events_seating_assigned` | `SeatAssignmentService::create_assignment()` | Fires after seat assignment created | None currently |
| `nettertech_events_seating_released` | `SeatAssignmentService::release_assignment()` | Fires after seat assignment released | None currently |
| `nettertech_events_seating_transferred` | `SeatAssignmentService::transfer_assignment()` | Fires after seat transferred | None currently |
| `nettertech_events_seating_hold_created` | `SeatHoldService::create_hold()` | Fires after hold created | None currently |
| `nettertech_events_seating_hold_released` | `SeatHoldService::release_hold()` | Fires after hold released | None currently |
| `nettertech_events_seating_holds_swept` | `Plugin::sweep_expired_holds()` | Fires after expired holds swept | None currently |
| `nettertech_events_seating_map_created` | `SeatingMapService::create()` | Fires after map created | None currently |
| `nettertech_events_seating_map_published` | `SeatingMapService::publish()` | Fires after map published | None currently |
| `nettertech_events_seating_assignment_partial_failure` | `SeatingOrderHandler` | Fires when seat assignment fails post-payment | SeatingOrderHandler (self: `notify_partial_assignment_failure`) |
| `nettertech_events_seating_ambiguous_space` | `SpaceResolver` | Fires when space resolution is ambiguous | None currently |
| `nettertech_events_seating_rate_limit_exceeded` | `SeatHoldsController` | Fires when rate limit exceeded | None currently |

#### Seating Plugin Filters

| Filter | Location | Purpose | Consumers |
|--------|----------|---------|-----------|
| `nettertech_events_seating_ticket_capacity_type` | `SeatingCartHandler`, `SeatingProductIntegration` | Resolve capacity type for a ticket type | Seating itself (`CapacityTypeProvider::resolve_capacity_type`) |
| `nettertech_events_seating_resolve_space` | `SeatingProductIntegration` | Resolve space ID from occurrence | Seating itself (`SpaceResolver::resolve_space`, priority 10); Base (`OccurrenceSpaceResolver::resolve_space`, priority 5) |
| `nettertech_events_seating_resolve_occurrence` | `SeatingProductIntegration` | Resolve occurrence ID from ticket type | None currently (Base subscribes to `..._seating_resolve_space`, not to this filter) |
| `nettertech_events_seating_occurrence_space_id` | `SeatAvailabilityService` | Resolve space ID for availability | Seating itself (`SpaceResolver::resolve_space`) |
| `nettertech_events_seating_order_ticket_ids` | `SeatAssignmentService` | Get ticket IDs for order cancellation | Seating itself (`SeatingOrderHandler::get_order_ticket_ids`) |
| `nettertech_events_seating_cart_hold_duration` | `SeatHoldService`, `SeatingProductIntegration` | Configure cart hold TTL (default 900s) | Any |
| `nettertech_events_seating_checkout_hold_duration` | `SeatHoldService` | Configure checkout hold TTL (default 1800s) | Any |
| `nettertech_events_seating_poll_interval` | `SeatingProductIntegration` | Configure availability poll interval (default 15000ms) | Any |
| `nettertech_events_seating_max_per_order` | `SeatingProductIntegration` | Configure max seats per order (default 10) | Any |
| `nettertech_events_seating_cache_ttl` | `SeatingMapService` | Configure map cache TTL | Any |
| `nettertech_events_seating_rate_limit_availability` | `SeatAvailabilityController` | Configure availability rate limit | Any |
| `nettertech_events_seating_rate_limit_holds` | `SeatHoldsController` | Configure holds rate limit | Any |
| `nettertech_events_seating_price_tiers` | `MapBuilderPage` | Provide zone-based price tiers | None currently |
| `nettertech_events_seating_health_data` | `SeatAssignmentsAdminController` | Extend seating health check data | None currently |

#### Rentals Plugin Actions

| Hook | Location | Purpose | Consumers |
|------|----------|---------|-----------|
| `nettertech_events_rentals_init` | `Plugin::init()` | Signals Rentals plugin initialized | None currently |
| `nettertech_events_rentals_activated` | `Activator` | Fires on activation | None currently |
| `nettertech_events_rentals_deactivated` | `Deactivator` | Fires on deactivation | None currently |
| `nettertech_events_rentals_booking_status_changed` | `BookingTransitionService` | Fires on any booking status change | Rentals (`ApprovalService::maybe_auto_approve`, `EventAwarenessService::invalidate_calendar_caches`) |
| `nettertech_events_rentals_booking_created` | `BookingTransitionService` | Fires on booking creation | Rentals (`NotificationService::on_booking_created`) |
| `nettertech_events_rentals_booking_approved` | `BookingTransitionService` | Fires on booking approval | Rentals (`NotificationService::on_booking_approved`, `PaymentsModule::on_booking_approved`) |
| `nettertech_events_rentals_booking_deposit_paid` | `BookingTransitionService` | Fires on deposit paid | Rentals (`NotificationService::on_booking_deposit_paid`) |
| `nettertech_events_rentals_booking_confirmed` | `BookingTransitionService` | Fires on booking confirmation | Rentals (`NotificationService::on_booking_confirmed`) |
| `nettertech_events_rentals_booking_on_hold` | `BookingTransitionService` | Fires on booking hold | Rentals (`NotificationService::on_booking_on_hold`) |
| `nettertech_events_rentals_booking_cancelled` | `BookingTransitionService` | Fires on booking cancellation | Rentals (`NotificationService::on_booking_cancelled`) |
| `nettertech_events_rentals_booking_completed` | `BookingTransitionService` | Fires on booking completion | Rentals (`NotificationService::on_booking_completed`) |
| `nettertech_events_rentals_balance_reminder_due` | `CronService` | Fires when balance reminder is due | Rentals (`NotificationService::on_balance_reminder_due`) |
| `nettertech_events_rentals_pre_event_reminder` | `CronService` | Fires for pre-event reminders | Rentals (`NotificationService::on_pre_event_reminder`) |
| `nettertech_events_rentals_payment_order_created` | `PaymentsModule` | Fires after WC payment order created for booking | None currently |
| `nettertech_events_rentals_balance_order_created` | `BalancePaymentHandler` | Fires after balance payment order created | None currently |
| `nettertech_events_rentals_space_saved` | `SpaceService` | Fires after space saved | Rentals (`EventAwarenessService::invalidate_calendar_caches`) |
| `nettertech_events_rentals_space_deleted` | `SpaceService` | Fires after space deleted | Rentals (`EventAwarenessService::invalidate_calendar_caches`) |
| `nettertech_events_rentals_email_sent` | `NotificationService` | Fires after rental email sent | None currently |
| `nettertech_events_rentals_email_failed` | `NotificationService` | Fires when rental email fails | None currently |
| `nettertech_events_rentals_invalid_transition` | `BookingTransitionService` | Fires on invalid booking state transition | None currently |

#### Migrator Plugin (Updated 2026-03-30, C4)

| Hook | Location | Purpose | Consumers |
|------|----------|---------|-----------|
| `nte_migrator_import_complete` | `ImportOrchestrator` | Fires after import complete | Migrator internal (`PostImportTicketService::maybe_create_default_tickets`) |
| `nettertech_events_bulk_import_completed` | Migrator (`AbstractLoader`, `ImportOrchestrator`), fired via the Base `Hooks::BULK_IMPORT_COMPLETED` constant | Fires after bulk import finishes | Base (`BulkImportHandler`: referential integrity check, capacity sync, activity logging) |

### Cross-Satellite Hooks

**One path, corrected 2026-07-16.** Seating consumes two filters that Pro fires: `nettertech_events_checkin_lookup_data` and `nettertech_events_checkin_search_item` (Pro `CheckInScanController` -> Seating `SeatingCheckInIntegration`). Both names are reserved by Core in `includes/Core/Hooks.php`, which is why this once read as a base-to-satellite path; Core does not fire either one. See "Hooks Fired by Pro, Reserved by Core" above.

Every other cross-plugin path flows through the Base plugin as an intermediary (Base fires hook -> satellite consumes). No satellite calls another satellite's code directly.

---

## Class/Interface Dependencies

### Interfaces Defined in Base, Implemented by Satellites

| Interface | Base Location | Implementors |
|-----------|--------------|-------------|
| `LicenseServiceInterface` | `Contracts\LicenseServiceInterface` (Pro-internal, moved from Base in Phase 8.0) | Pro (`LicenseService`) |
| `TableDefinitionInterface` | `Database\Tables\TableDefinitionInterface` | Pro (`PromoCodesTable`, `PromoCodeUsageTable`) |

### Classes Used Across Plugin Boundaries

| Class / Function | Defined In | Used By | How |
|------------------|-----------|---------|-----|
| `NetterTechEvents\Core\ServiceRegistry` | Base | Pro | Pro retrieves base services via `ServiceRegistry` |
| `NetterTechEvents\Database\Schema` | Base | Pro, Rentals | Table name resolution via `Schema::table()` |
| `NetterTechEvents\Database\DeferredSchema` | Base | Rentals | Table SQL definitions for DeferredSchema tables (spaces, bookings, etc.) |
| `NetterTechEvents\Admin\Branding` | Base | Pro | Admin page branding/styling utilities |
| `NetterTechEventsPro\Core\ProFeatures` | Pro | Pro modules | Feature access control (moved from Base FeatureGate in Phase 8.0) |
| `NetterTechEvents\Core\CacheManager` | Base | Pro | Cache key management, invalidation |
| `NetterTechEvents\Core\NetterTechEventsSettings` | Base | Pro | Access to base plugin settings |
| `NetterTechEventsPro\Contracts\LicenseServiceInterface` | Pro | Pro modules | License validation contract (moved from Base in Phase 8.0) |
| `NetterTechEvents\Contracts\OccurrenceRepositoryInterface` | Base | Pro | Occurrence data access for waitlist |
| `NetterTechEvents\Contracts\TicketTypeRepositoryInterface` | Base | Pro, Seating (CapacityTypeProvider - C7) | Ticket type data access for waitlist; Seating uses for capacity type resolution |
| `NetterTechEvents\Contracts\SpaceRepositoryInterface` | Base | Rentals, Seating (C2) | Space data access; Rentals extends for rental features, Seating reads via SpaceResolver |
| `NetterTechEvents\Contracts\WaitlistRepositoryInterface` | Base | Pro | Waitlist data access |
| `NetterTechEvents\Models\WaitlistEntry` | Base | Pro | Waitlist entry model |
| `NetterTechEvents\Models\Occurrence` | Base | Pro | Occurrence model for waitlist frontend |
| `NetterTechEvents\Models\TicketType` | Base | Pro, Migrator | Ticket type model |
| `NetterTechEvents\Services\WaitlistService` | Base | Pro | Waitlist business logic |
| `NetterTechEvents\Services\RateLimitService` | Base | Pro | Rate limiting for waitlist REST endpoint |
| `NetterTechEvents\Services\ExportService` | Base | Pro | Base export functionality extended by Advanced Export |
| `NetterTechEvents\Services\QRCodeService` | Base | Migrator | QR code generation for reissued tickets |
| `NetterTechEvents\Utilities\DatabaseLogger` | Base | Pro | Structured database logging |
| `NetterTechEvents\Exceptions\ValidationException` | Base | Pro | Shared exception type |
| `NetterTechEvents\Repositories\TicketTypeRepository` | Base | Migrator | Ticket type persistence for post-import |
| `NetterTechEvents\Repositories\AttendeeRepository` | Base | Migrator | Attendee data access for ticket reissue |
| `NetterTechEvents\Repositories\TicketRepository` | Base | Migrator | Ticket data access for ticket reissue |
| `NetterTechEvents\Repositories\OccurrenceRepository` | Base | Migrator | Occurrence data access for ticket reissue |
| `NetterTechEvents\Repositories\EventRepository` | Base | Migrator | Event data access for ticket reissue |
| `NetterTechEvents\Admin\Branding` | Base | Migrator | Admin page styling |
| `\NetterTechEvents\nettertech_events_container()` | Base | Pro | DI container access for service resolution |

### Service Registry Integration

**Pro** is the only satellite that uses the base ServiceRegistry/DI container directly:
- `Plugin::init_modules()` calls `\NetterTechEvents\nettertech_events_container()` to resolve base services
- Pro's `LicenseServiceInterface` and `ProFeatures` are Pro-internal (moved from Base in Phase 8.0)

**Rentals** does not use the base ServiceRegistry. It uses `Schema::table()` for table name resolution and `DeferredSchema` for table SQL, but manages its own service wiring internally.

**Seating** interacts with the base plugin through: (Updated 2026-03-30, C7)
1. WordPress hooks (actions and filters)
2. Base-provided interface contracts (`TicketTypeRepositoryInterface` for capacity type resolution, `SpaceRepositoryInterface` for space data)
3. WC order item meta keys set by the base plugin (`_nettertech_events_occurrence_id`, `_nettertech_events_ticket_type_id`)

Zero direct `$wpdb` queries from Seating to Base tables remain. All Base table access is now through interface contracts (only FK references in code comments).

**Migrator** uses interface contracts for all Base repository access (C4, updated 2026-03-30). It fires `nettertech_events_bulk_import_completed` after import; Base listens via `BulkImportHandler` for post-import reconciliation (referential integrity, capacity sync, activity logging). Direct `$wpdb` writes are retained for bulk import performance.

---

## Shared Data Access

### Tables Owned by Base, Accessed by Satellites

| Table | Owner | Readers | Writers | Access Pattern |
|-------|-------|---------|---------|---------------|
| `nettertech_events_events` | Base | Pro (RevenueReportService, AdvancedExportService, WaitlistAdminPage, PromoCodesPage), Rentals (EventAwarenessService), Migrator (TecMappingBuilder, PostMigrationDataService, TicketReissueService) | Migrator (import loaders, RollbackService) | Pro: `Schema::table('events')` + JOIN queries. Rentals: `Schema::table('events')`. Migrator: `$wpdb->prefix . 'nettertech_events_events'` |
| `nettertech_events_occurrences` | Base | Pro (RevenueReportService, AdvancedExportService, WaitlistAdminPage), Rentals (EventAwarenessService), Migrator (TecMappingBuilder, PostMigrationDataService, TicketReissueService) | Migrator (import loaders, RollbackService) | Same patterns as events |
| `nettertech_events_ticket_types` | Base | Pro (RevenueReportService, AdvancedExportService), Seating (CapacityTypeProvider via TicketTypeRepositoryInterface - C7), Migrator (via interface contracts - C4) | Migrator (PostImportTicketService via interface), Pro (uses base repo interface) | Seating: `TicketTypeRepositoryInterface` (no direct `$wpdb`). Pro: `Schema::table()`. Migrator: interface contracts for reads, `$wpdb->prefix` for bulk writes |
| `nettertech_events_tickets` | Base | Pro (RevenueReportService, AdvancedExportService), Migrator (TicketReissueService) | Migrator (import loaders) | Pro: `Schema::table()`. Migrator: `$wpdb->prefix` |
| `nettertech_events_attendees` | Base | Pro (RevenueReportService, AdvancedExportService), Migrator (AttendeeRepairService, PostMigrationDataService, TicketReissueService) | Migrator (import loaders, AttendeeRepairService, RollbackService) | Pro: `Schema::table()`. Migrator: `$wpdb->prefix` |
| `nettertech_events_waitlist` | Base | Pro (WaitlistAdminPage, WaitlistCron) | Pro (via base WaitlistService/WaitlistRepository) | Pro: `Schema::table('waitlist')` for admin page; base service interfaces for business logic |
| `nettertech_events_spaces` | Base (C2, updated 2026-03-30) | Rentals (extends Space model, adds rental-specific columns via ALTER TABLE), Seating (SpaceResolver via SpaceRepositoryInterface) | Rentals (rental columns), Base (core space data) | Base owns core space definition (name, capacity). Rentals adds rental columns. Seating reads via `SpaceRepositoryInterface`. |
| `wp_posts` (post_type `nettertech_event`) | WordPress / Base | Pro (ReportsPage, PromoCodesPage) | None by satellites | Pro queries shadow posts for event picker dropdowns |

### Tables Owned by Pro

| Table | Owner | Other Accessors | Notes |
|-------|-------|----------------|-------|
| `nettertech_events_promo_codes` | Pro | None | Promo code definitions |
| `nettertech_events_promo_code_usage` | Pro | None | Promo code usage tracking per order |

### Tables Owned by Rentals

| Table | Owner | Other Accessors | Notes |
|-------|-------|----------------|-------|
| `nettertech_events_bookings` | Rentals | None | Rental booking records |
| `nettertech_events_space_configurations` | Rentals | None | Layout configurations per space |
| `nettertech_events_addon_types` | Rentals | None | Bookable add-on service definitions |
| `nettertech_events_booking_addons` | Rentals | None | Add-ons selected per booking |
| `nettertech_events_booking_payments` | Rentals | None | Payment records for bookings |
| `nettertech_events_booking_status_log` | Rentals | None | Booking state transition audit trail |
| `nettertech_events_rate_limits` | Rentals | None | Rate limit tracking (separate from base) |

### Tables Owned by Seating

| Table | Owner | Other Accessors | Notes |
|-------|-------|----------------|-------|
| `nettertech_events_seating_maps` | Seating | None | Seating map definitions with SVG/config data |
| `nettertech_events_map_sections` | Seating | None | Sections within a map |
| `nettertech_events_seat_groups` | Seating | None | Groups of seats (rows, etc.) |
| `nettertech_events_seats` | Seating | None | Individual seat definitions |
| `nettertech_events_seat_assignments` | Seating | None | Permanent seat assignments (ticket -> seat) |
| `nettertech_events_seat_holds` | Seating | None | Temporary seat holds during purchase flow |

### Tables Owned by Migrator

| Table | Owner | Other Accessors | Notes |
|-------|-------|----------------|-------|
| `nte_import_sessions` | Migrator | None | Import session tracking |
| `nte_import_logs` | Migrator | None | Per-record import log |
| `nte_import_mappings` | Migrator | None | Source ID -> NTE ID mappings |
| `nte_import_mappings_canonical` | Migrator | None | Canonical mapping records |
| `nte_export_sessions` | Migrator | None | Export session tracking |
| `nte_reissue_notifications` | Migrator | None | Ticket reissue email tracking |

---

## WooCommerce Hook Contention

Both Base and Rentals hook into the same WC order lifecycle hooks. They coexist by checking product-type flags on each order item.

| WC Hook | Base Handler | Rentals Handler | Seating Handler | Discrimination |
|---------|-------------|-----------------|-----------------|----------------|
| `woocommerce_add_to_cart_validation` | `CartHandler` (priority 10) | `RentalCartHandler` (priority 10) | `SeatingCartHandler` (priority 15, C3) | Base checks `_nettertech_events_is_event_ticket`; Rentals checks `_nettertech_events_rental_is_booking`; Seating read-only availability check after Base (holds on `woocommerce_add_to_cart` action) |
| `woocommerce_get_item_data` | `CartHandler` (default) | `RentalCartHandler` (default) | `SeatingCartHandler` (priority 20) | Each checks its own product meta |
| `woocommerce_cart_item_removed` | `CartHandler` | `RentalCartHandler` | `SeatingCartHandler` | Each checks its own cart item data |
| `woocommerce_payment_complete` | `OrderHandler` | `RentalOrderHandler` | -- | Base checks `_nettertech_events_is_event_ticket`; Rentals checks `_nettertech_events_rental_is_booking` |
| `woocommerce_order_status_processing` | `OrderHandler` | `RentalOrderHandler` | -- | Same as above |
| `woocommerce_order_status_cancelled` | `OrderHandler` | `RentalOrderHandler` | `SeatingOrderHandler` | Base/Rentals check product flags; Seating checks seat meta |
| `woocommerce_order_status_refunded` | `OrderHandler` | `RentalOrderHandler` | -- | Base/Rentals check product flags |
| `woocommerce_checkout_create_order_line_item` | `CartHandler` (priority 10) | -- | `SeatingOrderHandler` (priority 20) | Additive: Base writes `_nettertech_events_*` meta, then Seating writes `_ve_seat_ids` |
| `woocommerce_cart_emptied` | `CartHandler` | -- | `SeatingCartHandler` | Both fire; each cleans its own state |
| `woocommerce_checkout_order_processed` | -- | -- | `SeatingOrderHandler` | Seating-only: upgrades holds to checkout type |

**[RESOLVED]** Currently untested with mixed carts. Add integration tests covering all meaningful permutations (ticket-only, rental-only, mixed, seated, triple). See task #19.

---

## Coupling Assessment

### Intended Coupling (by design)

| Pattern | Source | Target | Mechanism |
|---------|--------|--------|-----------|
| Satellite initialization after base | All satellites | Base | `nettertech_events_init` action, `plugins_loaded` priority ordering, `NETTERTECH_EVENTS_VERSION` guard |
| Satellite feature modules | Pro | Pro (self-contained) | Pro services/modules register their own functionality without unlocking disabled base code |
| Waitlist promotion notification | Base | Base | `nettertech_events_waitlist_promoted` action (base fires and base sends the email via `WaitlistEmailHandler`; Pro does not subscribe) |
| Supplemental ticket UI | (none currently) | Base | `nettertech_events_ticket_add_button_area` action; fires from `TicketsMetabox` but has no subscriber in the suite |
| Optional frontend branding opt-in | Base settings/custom integrations | Base | `show_frontend_branding` setting, `nettertech_events_show_frontend_branding` filter |
| Seat lifecycle on order events | Seating | Base | `nettertech_events_attendee_created`, `nettertech_events_registration_voided` actions |
| Check-in seat display | Seating | Pro, Base | `nettertech_events_checkin_lookup_data` and `nettertech_events_checkin_search_item` (fired by Pro), `nettertech_events_ticket_scan_data` (fired by Base) |
| Calendar extension for rentals | Rentals | Base | `nettertech_events_calendar_*` filters/actions |
| Settings page extension | Pro, Rentals, Seating | Base | `nettertech_events_settings_tabs` filter, plus the generic `nettertech_events_settings_tab_render` / `nettertech_events_settings_tab_save` actions (the per-tab dynamic actions were retired in 1.0.2) |
| Cache coordination | Rentals | Base | `nettertech_events_cache_invalidated`, `nettertech_events_after_save_event` actions |
| DeferredSchema tables | Rentals | Base | Base defines SQL templates; Rentals creates tables via `DeferredSchema` |
| Rebrand migration coordination | Seating | Base | `nettertech_events_legacy_rebrand_complete` action |
| Sold-out waitlist panel | Pro | Base | `nettertech_events_after_sold_out` action in ticket form template |

### Incidental Coupling

| Pattern | Source | Target | Concern |
|---------|--------|--------|---------|
| Seating reads `nettertech_events_ticket_types.capacity_type` via direct `$wpdb` query | Seating (CapacityTypeProvider) | Base | **[IMPLEMENTED]** (C7, 2026-03-30) Replaced with `TicketTypeRepositoryInterface`. Zero direct `$wpdb` queries from Seating to Base tables remain. |
| Seating reads `nettertech_events_spaces` table directly via `$wpdb` | Seating (SpaceResolver) | Base (table owner, C2) | **[IMPLEMENTED]** (C2, 2026-03-30) Space ownership moved to Base. Seating reads via `SpaceRepositoryInterface`. |
| Seating reads `nettertech_events_seating_maps` table in same query as `nettertech_events_spaces` | Seating (SpaceResolver) | Seating + Base | SpaceResolver uses `SpaceRepositoryInterface` for space data, then JOINs with Seating-owned `nettertech_events_seating_maps` |
| Migrator uses concrete repository classes | Migrator (TicketReissueService, PostImportTicketService) | Base | **[IMPLEMENTED]** (C4, 2026-03-30) Switched to interface contracts. Fires `nettertech_events_bulk_import_completed`; Base listens via `BulkImportHandler`. |
| Migrator writes directly to base tables via `$wpdb` | Migrator (import loaders, repair services) | Base | **[IMPLEMENTED]** (C4, 2026-03-30) Retained direct `$wpdb` for bulk writes (performance). Added `nettertech_events_bulk_import_completed` hook and `BulkImportHandler` for post-import reconciliation. |
| Pro reads WC order item meta `_nettertech_events_occurrence_id` set by Base | Pro (WaitlistModule) | Base | Relies on specific meta key naming convention |
| Seating writes WC order item meta with legacy `_ve_` prefix | Seating (SeatingOrderHandler) | WC orders | **[RESOLVED]** Unintentional rebrand miss. Migrate to `_nettertech_events_` with activation migration. (Also resolved in WC-INTEGRATION-BOUNDARY.md) |
| Pro accesses `wp_posts` with `post_type = 'nettertech_event'` directly | Pro (ReportsPage, PromoCodesPage) | Base (shadow posts) | Relies on shadow post type naming |
| PromoCodeService reads `_nettertech_events_event_id` from WC product meta | Pro | Base | Relies on ProductManager meta key convention |

---

## Shared WP Options and Transients

| Key | Owner | Accessed By | Purpose |
|-----|-------|-------------|---------|
| `nettertech_events_settings` | Base | Pro (uninstall reads `delete_data_on_uninstall`) | Shared settings array |
| `nettertech_events_pro_license_key` | Pro | Pro | License key storage |
| `nettertech_events_pro_license` | Pro | Pro | License data cache |
| `nettertech_events_pro_db_version` | Pro | Pro | Schema version tracking |
| `nettertech_events_seating_db_version` | Seating | Seating | Schema version tracking |
| `nettertech_events_rentals_db_version` | Rentals | Rentals | Schema version tracking |
| `nte_migrator_db_version` | Migrator | Migrator | Schema version tracking (superseded `nte_importer_db_version`, still read as the legacy fallback) |

---

## Resolved Design Decisions (Consolidated)

All items have been reviewed and resolved:

1. **[IMPLEMENTED] Seating direct `$wpdb` to `nettertech_events_ticket_types`.** (C7, 2026-03-30) Replaced with `TicketTypeRepositoryInterface`. CapacityTypeProvider now uses the interface contract. Zero direct queries from Seating to Base tables remain.

2. **[IMPLEMENTED] Seating reads Rentals-owned `nettertech_events_spaces`.** (C2, 2026-03-30) Space ownership moved from Rentals to Base. Base provides `SpaceRepositoryInterface`. Rentals Space model extends Base Space model and adds rental-specific columns via ALTER TABLE. Seating SpaceResolver uses `SpaceRepositoryInterface` (no direct `$wpdb`).

3. **[IMPLEMENTED] Migrator uses concrete base repository classes.** (C4, 2026-03-30) Switched to interface contracts for all Base repository access.

4. **[IMPLEMENTED] Migrator writes directly to base tables.** (C4, 2026-03-30) Retained direct `$wpdb` for bulk writes (performance). Added `nettertech_events_bulk_import_completed` hook; Base listens via `BulkImportHandler` for post-import reconciliation (referential integrity check, capacity sync, activity logging).

5. **[RESOLVED] Base + Rentals share WC hooks at default priority.** Untested with mixed carts. Add integration tests covering all meaningful permutations (ticket-only, rental-only, mixed, seated, triple). See task #19.

6. **[RESOLVED] Seating `_ve_` prefix on order meta.** Unintentional rebrand miss. Migrate to `_nettertech_events_` with activation migration. (Also resolved in WC-INTEGRATION-BOUNDARY.md)

7. **[RESOLVED] Pro plugin load order.** Standardized to Base 10, Pro 15, Rentals 20, Migrator 20, Seating 25. All satellites load after Base.

---

## How To Add A New Contract

When adding a new cross-plugin extension point — interface, hook, table, REST route, or convention-keyed meta — follow this procedure so the contract surface stays inspectable and drift-tested.

### 1. Decide the surface type

| If you are adding a... | Place it in... | Stability tier (default) |
|---|---|---|
| Repository / service interface | `nettertech-events/includes/Contracts/` | **Public Contract** (visible reach with documented intent) |
| Concrete repository / service | `nettertech-events/includes/Repositories/` or `Services/` (and bind in a ServiceProvider) | **Internal** (concrete classes are by definition Internal; interface-first preferred) |
| Action or filter hook | Constant in `nettertech-events/includes/Core/Hooks.php`; fire via `do_action(Hooks::*, ...)` / `apply_filters(Hooks::*, ...)` | **Public Contract** if satellites are expected to consume; **Internal** otherwise |
| Custom table | Define via `TableDefinitionInterface` if owned by a satellite; via base `Schema` if owned by base; access through `Schema::table('name')` | **Public Contract** if cross-plugin reach is expected |
| REST route | `register_rest_route` in a controller; document in `openapi.yaml` and `REST-API-CHANGELOG.md` | **Public Contract** if `__return_true` or public; **Internal** if capability-gated and not documented |
| Convention-keyed WC meta | Constant in `nettertech-events/includes/Core/MetaKeys.php` using `_nettertech_events_*` literal | **Public Contract** if cross-plugin |

### 2. Document it here and in the audit-engineering matrix

- Add a row to the relevant table in this file (`CROSS-PLUGIN-MATRIX.md`).
- Add the entry to `audit/suite/contracts/CONTRACT-INVENTORY.md` with `file:line` citations to producer + consumers.
- Add the entry to `audit/suite/contracts/COMPATIBILITY-MATRIX.md` with stability tier, minimum base version, and drift-test priority.

### 3. Add a contract drift test (Public Contract only)

Per the Phase 1 / Chunk 5 scaffolding (`tests/Contract/Static/`, `tests/Contract/Integration/`):

- **Static drift test** — assert class/interface existence, method signatures, value-object field shapes. Place in the consumer plugin's `tests/Contract/Static/` directory. Runs on every pre-push.
- **WP-integration drift test** — assert hook fires with expected payload, table exists with expected columns, REST route registers with expected schema. Place in `tests/Contract/Integration/`. Runs on configured cadence.
- **Docs-only contract** — for fragile or stateful contracts where runtime testing would be more brittle than the contract, document the manual verification procedure in COMPATIBILITY-MATRIX.md instead. Use sparingly.

### 4. Plan the deprecation window

Public Contract surfaces follow this deprecation policy:

1. **Deprecation in N.x.0** — add `@deprecated` PHPDoc; runtime `_deprecated_function`/`_deprecated_hook` notice; document the replacement in CHANGELOG.
2. **Backward-compatible default in N.x+1** — delegate, alias, or throw with helpful message.
3. **Removal allowed in (N+1).0.0** — major-version event.

Constructor and signature changes additionally invoke the project's Structural Change Gate (run full `composer test` immediately after the change in the changed plugin; do not batch with subsequent edits).

### 5. Communicate to consumers

For Public Contract changes that may affect satellite consumers, post a coordination note in the relevant active sibling plan's `.status.jsonl` sidecar AND in the suite-wide compatibility matrix. The coordination ledger format from `audit/suite/baselines/2026-05-06/COORDINATION-LEDGER.md` is the template.

---

## Companion Documents

- **[WC-INTEGRATION-BOUNDARY.md](WC-INTEGRATION-BOUNDARY.md)** -- Per-hook WC integration ownership across Base, Rentals, and Seating
- **[WORKFLOWS.md](WORKFLOWS.md)** -- 6 workflow state machines showing cross-plugin service calls
- **[DATABASE-SCHEMA.md](DATABASE-SCHEMA.md)** -- Complete table reference for base plugin
- **[HOOKS.md](HOOKS.md)** -- Full hook reference for base plugin
- **`audit/suite/contracts/CONTRACT-INVENTORY.md`** -- Per-entry consumer-reach catalog with `file:line` citations (created 2026-05-08, suite Phase 1 Chunk 3)
- **`audit/suite/contracts/COMPATIBILITY-MATRIX.md`** -- Stability tiers, version assertions, and remediation candidates per cross-plugin contract (created 2026-05-08, suite Phase 1 Chunk 4)
