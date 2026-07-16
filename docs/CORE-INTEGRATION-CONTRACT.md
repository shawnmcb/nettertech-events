# Core Integration Contract

> **Audience:** Add-on developers building companion plugins (Pro, Rentals, Seating, or third-party extensions). Defines the stable API surface the Core plugin guarantees for add-on integration.

How extension plugins (Pro, Rentals, Seating) integrate with NetterTech Events Core.

## Architecture

```
Events Core (free)
  |-- fires nettertech_events_* hooks
  |
  +-- Pro (hooks into Core for multi-ticket, reports, promo codes)
  +-- Rentals (hooks into Core for space booking lifecycle)
  +-- Seating (hooks into Core for seat maps + check-in integration)
```

Extensions never call each other directly. All coordination flows through Core hooks or WooCommerce hooks.

## Lifecycle Hooks

| Hook | Type | Arguments | Purpose |
|------|------|-----------|---------|
| `nettertech_events_init` | Action | Plugin instance | Core fully initialized: safe to resolve services |
| `nettertech_events_activated` | Action | — | Plugin activation |
| `nettertech_events_deactivated` | Action | — | Plugin deactivation |

**Consumed by:** Pro (`nettertech_events_init` for module init, via `Plugin::init_modules`). Seating and Rentals do not subscribe to these three: both bootstrap on `plugins_loaded` behind a `NETTERTECH_EVENTS_VERSION` guard instead.

## Ticket System Hooks

### Admin UI Injection Points

| Hook | Type | Arguments | Purpose |
|------|------|-----------|---------|
| `nettertech_events_ticket_add_button_area` | Action | Event\|null, int count, string context | Injection point for supplemental ticketing UI |

**Primary consumer:** Add-ons that need to display supplemental controls beside the base ticketing UI.

### Capacity System

| Hook | Type | Arguments | Purpose |
|------|------|-----------|---------|
| `nettertech_events_capacity_reserved` | Action | int ticket_type_id, int qty | Capacity reserved (triggers waitlist check) |
| `nettertech_events_capacity_released` | Action | int ticket_type_id, int qty | Capacity released (triggers waitlist promotion) |

**Primary consumer:** Pro WaitlistModule — auto-promotes waitlisted attendees.

## Event Lifecycle Hooks

| Hook | Type | Arguments | Purpose |
|------|------|-----------|---------|
| `nettertech_events_before_save_event` | Action | Event, array data | Pre-save |
| `nettertech_events_after_save_event` | Action | Event | Post-save |
| `nettertech_events_before_delete_event` | Action | Event | Pre-delete |
| `nettertech_events_after_delete_event` | Action | int id, Event | Post-delete |
| `nettertech_events_occurrence_status_changed` | Action | int id, string status, string old_status | Occurrence status transition |
| `nettertech_events_cache_invalidated` | Action | — | Cache invalidation trigger |

**Consumed by:** Rentals EventAwarenessService (listens to save/cache events).

## Branding and Settings Extensions

| Hook | Type | Arguments | Purpose |
|------|------|-----------|---------|
| `nettertech_events_show_frontend_branding` | Filter | bool | Override optional "Powered by" branding visibility |

**Primary consumer:** Site-specific integrations that intentionally override the saved site-owner opt-in setting.

## Settings Integration

| Hook | Type | Arguments | Purpose |
|------|------|-----------|---------|
| `nettertech_events_settings_tabs` | Filter | array tabs | Add tabs to Settings page |
| `nettertech_events_settings_tab_render` | Action | string active_tab, array settings | Render tab content |
| `nettertech_events_settings_tab_save` | Action | string active_tab | Save tab data |

The per-tab dynamic hooks (`nte_settings_tab_{name}` / `nte_settings_save_{name}`) were retired in 1.0.2. Core now fires two generic hooks instead and passes the active tab slug as the first argument: a listener subscribes once and switches on that slug, returning early when the slug is not its own. `nettertech_events_settings_tab_render` fires from `SettingsPage::render()`; `nettertech_events_settings_tab_save` fires from `SettingsSaveHandler::handle_save()`.

**Primary consumer:** Satellites that add their own settings pages. Pro uses this generic contract: `Plugin::add_license_tab` on the tabs filter, `Plugin::render_license_tab_if_active` and `Plugin::save_license_settings_if_active` on the two generic actions.

## Check-In Integration

These two filter names are reserved by Core as part of the cross-plugin contract, but Core does not fire them. The Pro plugin's `CheckInScanController` is the firing site (Core ships no `CheckInScanController`). Add-ons that subscribe get nothing unless Pro is active.

| Hook | Type | Arguments | Fired by | Purpose |
|------|------|-----------|----------|---------|
| `nettertech_events_checkin_lookup_data` | Filter | array, Attendee, int occurrence_id | Pro (`CheckInScanController`) | Enrich check-in lookup data |
| `nettertech_events_checkin_search_item` | Filter | array item_data, Attendee | Pro (`CheckInScanController`) | Enrich check-in search results |

**Primary consumer:** Seating `SeatingCheckInIntegration` (adds seat assignment info).

## Template System

| Hook | Type | Arguments | Purpose |
|------|------|-----------|---------|
| `nettertech_events_template_paths` | Filter | array paths, TemplateLoaderConfig | Add template search paths |
| `nettertech_events_get_template_part` | Filter | array templates, string slug, string\|null name | Override template candidates |
| `nettertech_events_template_args` | Filter | array args, string file | Modify template variables |

## Security Headers

| Hook | Type | Arguments | Purpose |
|------|------|-----------|---------|
| `nettertech_events_csp_directives` | Filter | array directives | Admin CSP policy directives |
| `nettertech_events_public_csp_directives` | Filter | array directives | Public CSP policy directives |
| `nettertech_events_csp_report_uri` | Filter | string uri | CSP report URI endpoint |

## WooCommerce Meta Keys

Core adds no custom keys to the WC cart item array. Ticket identity lives in product meta and is copied onto the order line item at checkout. All keys are declared as constants in `includes/Core/MetaKeys.php`: prefer the constant over the literal.

### Product meta (written by `ProductManager`)

| Key | Constant | Type | Purpose |
|-----|----------|------|---------|
| `_nettertech_events_is_event_ticket` | (literal in `ProductManager`) | string `yes` | Flag for ticket identification |
| `_nettertech_events_event_id` | `MetaKeys::EVENT_ID` | int | Event ID |
| `_nettertech_events_occurrence_id` | `MetaKeys::OCCURRENCE_ID` | int | Occurrence ID |
| `_nettertech_events_ticket_type_id` | `MetaKeys::TICKET_TYPE_ID` | int | Ticket type ID |
| `_nettertech_events_is_series_pass` | `MetaKeys::IS_SERIES_PASS` | string `yes` | Marks a pass spanning every date of an event |

### Order line item meta (written by `CartPresenter::add_order_item_meta`, on `woocommerce_checkout_create_order_line_item`)

| Key | Constant | Type | Purpose |
|-----|----------|------|---------|
| `_nettertech_events_occurrence_id` | `MetaKeys::OCCURRENCE_ID` | int | Occurrence purchased |
| `_nettertech_events_ticket_type_id` | `MetaKeys::TICKET_TYPE_ID` | int | Ticket type purchased |
| `_nettertech_events_event_id` | `MetaKeys::EVENT_ID` | int | Event the line item belongs to |
| `_nettertech_events_is_series_pass` | `MetaKeys::IS_SERIES_PASS` | string `yes` | Set instead of an occurrence ID for series passes |

To decide whether a cart or order line item is an event ticket, resolve its product and call `ProductManager::is_event_ticket()` rather than reading the flag meta directly.

## Integration Rules

1. **Never call extension code directly** — use hooks for all cross-plugin communication
2. **Never assume an extension is active** — always check via `class_exists()` or feature gates
3. **Never modify Core database tables** — extensions own their own tables
4. **All Core hooks use the `nettertech_events_` prefix** (extensions use `nettertech_events_{plugin}_`, e.g. `nettertech_events_rentals_`, `nettertech_events_seating_`). Prefer the `Hooks::*` constant over the literal string: constants survive renames, literals drift. The Migrator plugin is the one exception still shipping `nte_migrator_*` hook and table names.
5. **Hook argument shapes are stable** — changing argument types/count is a breaking change requiring version bump

---

**Last Updated:** 2026-07-16
**Source:** Automated scan of all 4 plugin codebases; hook names and firing sites re-verified against source 2026-07-16
