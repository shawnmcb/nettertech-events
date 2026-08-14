# NetterTech Events

![PHPCS](https://img.shields.io/badge/PHPCS-0_errors-brightgreen)
![PHPStan](https://img.shields.io/badge/PHPStan-Level_7-blue)
![Tests](https://img.shields.io/badge/Tests-5%2C661_passing-brightgreen)
![Coverage](https://img.shields.io/badge/Coverage-74.48%25-yellow)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple)
![License](https://img.shields.io/badge/License-GPL_v2%2B-blue)

A performant WordPress events plugin with recurring events, ticketing, and WooCommerce integration.

## Requirements

- WordPress 6.5+
- PHP 8.2+
- WooCommerce 8.5+ (optional, for ticketing)

## Features

### Event Management
- Custom database tables for optimized calendar queries
- Single and recurring events with RFC 5545 RRULE support
- Pre-computed occurrences for fast date-range queries
- Venue information with address storage
- Event categories with built-in editor and hierarchical support (internal tables, not WordPress taxonomy)

### Recurrence System
- Daily, weekly, monthly, yearly patterns
- Complex rules: "every 2nd Tuesday", "last Friday of month"
- Configurable occurrence horizon (default: 1 year ahead)
- Automatic regeneration on rule changes

### Frontend Display

#### Shortcodes

**Carousel** — `[nettertech_events_carousel]`

Displays upcoming events in a sliding carousel with navigation.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `limit` | `6` | Number of events to display |
| `columns` | `3` | Visible cards (1-4) |
| `show_image` | `true` | Show featured image/placeholder |
| `show_date` | `true` | Show date badge |
| `show_time` | `true` | Show event time |
| `show_venue` | `true` | Show venue name |
| `show_year` | `false` | Always show year (auto-shown for future years) |
| `autoplay` | `false` | Auto-advance slides |
| `interval` | `5000` | Autoplay interval in milliseconds |
| `class` | `''` | Additional CSS class |

**Event List** — `[nettertech_events_list]`

Displays events in a filterable grid or list layout. `[nettertech_events_grid]` and `[nettertech_events]` are registered as aliases of this shortcode.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `limit` | `12` | Events per page |
| `columns` | `3` | Grid columns (1-4) |
| `layout` | `grid` | Layout: `grid`, `list`, or `cards` |
| `show_filters` | `true` | Show filter controls |
| `show_search` | `true` | Show search box |
| `show_category` | `true` | Show category filter |
| `pagination` | `true` | Enable pagination |
| `ajax` | `true` | AJAX-powered filtering |
| `category` | `''` | Pre-filter by category ID(s) |

**Calendar** — `[nettertech_events_calendar]`

Displays events in a calendar view.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `view` | `month` | Default view: `month`, `week`, `day`, `list` |
| `show_navigation` | `true` | Show prev/next navigation |
| `show_view_toggle` | `true` | Allow switching views |

**RSVP Form** — `[nettertech_events_rsvp]`

Displays an RSVP form for free event registration.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `occurrence_id` | `0` | Occurrence ID (auto-detected on occurrence pages) |
| `show_party` | `true` | Show party size selector |
| `max_party` | `10` | Maximum party size |
| `button_text` | `RSVP Now` | Submit button label |
| `success_text` | `Thank you!...` | Confirmation message |

**Weekly Regulars** — `[nettertech_events_regulars]`

Displays weekly recurring events in a compact table grouped by day of week. Replaces manually maintained static HTML with live data from published recurring events.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `limit` | `50` | Maximum events to display |
| `show_venue` | `true` | Show venue name column |
| `show_time` | `true` | Show event time column |
| `show_day` | `true` | Show day-of-week grouping |
| `heading` | `''` | Optional table heading |
| `class` | `''` | Additional CSS class |

### Admin Interface

- **Events List** — Sortable table with status, date, and occurrence counts
- **Event Editor** — Full editing interface with recurrence rule builder
- **Tickets Metabox** — Manage series passes, templates, and per-occurrence tickets
- **Categories** — Built-in category editor with hierarchy support
- **Spaces** — Admin interface for managing bookable spaces and physical locations (list, create, edit, delete); assign spaces to events via metabox in the event editor
- **Attendees** — Attendee management and search
- **Check-In** — Manual door check-in by name or email search (QR scanning available in [NetterTech Events Pro](https://nettertech.com/events))
- **QR Generator** (Pro) — Generate QR codes for event promotion and check-in. Available in [NetterTech Events Pro](https://nettertech.com/events).
- **Event Revisions** — Revision history with diff view and one-click restore for event changes
- **Activity Log** — OWASP A09 compliant audit trail of administrative actions
- **WordPress Search Integration** — Events are discoverable via the Gutenberg link dialog and admin bar search through automatic shadow posts
- **Settings** — Plugin configuration

### REST API

Base URL: `/wp-json/nettertech-events/v1/` — 21 endpoints across 8 controllers.

| Group | Endpoints | Auth |
|-------|-----------|------|
| Events (Public) | `/events/upcoming`, `/events/range`, `/events/{id}`, `/occurrences` | None |
| iCal | `/ical/feed`, `/ical/event/{id}`, `/ical/occurrence/{id}`, `/ical/import` | Import: admin |
| Attendee Fields | `/admin/events/{id}/attendee-fields`, `.../attendee-fields/{id}`, `/attendees/{id}/custom-fields` | Mixed |
| Waitlist | `/waitlist/join`, `/waitlist/status`, `/waitlist/leave` | None |
| Admin CRUD | `/admin/events`, `/admin/events/{id}`, `/admin/attendees`, `/admin/attendees/{id}`, `/admin/ticket-types`, `/admin/ticket-types/{id}` | Cookie |
| Security | `/csp-report` | None |

See [docs/openapi.yaml](docs/openapi.yaml) for the complete specification.

### Ticketing & RSVP

- **Three ticket scopes**:
  - Per-occurrence tickets with individual pricing
  - Series passes granting access to all occurrences
  - Template tickets that propagate to occurrences
- Capacity tracking with series pass awareness
- Stock management with automatic status updates
- WooCommerce integration for paid tickets
- Free RSVP registrations
- QR code generation for tickets
- Attendee check-in system

### Integrations

**WooCommerce**
- Automatic product creation for ticket types
- Cart validation for capacity limits
- Order processing creates attendee records
- Stock synchronization

**Beaver Builder**
- Event Carousel module
- Event List module
- Event Calendar module

## Database Schema

The plugin uses 20 custom tables for performance. See [docs/DATABASE-SCHEMA.md](docs/DATABASE-SCHEMA.md) for the complete reference with column definitions, indexes, and ER diagram.

| Table | Purpose |
|-------|---------|
| `wp_nte_series` | Event series groupings |
| `wp_nte_events` | Event definitions |
| `wp_nte_occurrences` | Pre-computed occurrences |
| `wp_nte_spaces` | Bookable spaces and physical locations |
| `wp_nte_ticket_types` | Ticket types with scope (occurrence/event/template) |
| `wp_nte_attendees` | Registration records (paid + RSVP) |
| `wp_nte_tickets` | Individual tickets with QR codes |
| `wp_nte_attendee_fields` | Custom registration field definitions per event |
| `wp_nte_attendee_field_values` | Attendee responses to custom fields |
| `wp_nte_organizers` | Event organizers |
| `wp_nte_event_organizers` | Event-organizer junction (many-to-many) |
| `wp_nte_categories` | Hierarchical event categories |
| `wp_nte_event_categories` | Event-category junction (many-to-many) |
| `wp_nte_tags` | Event tags |
| `wp_nte_event_tags` | Event-tag junction (many-to-many) |
| `wp_nte_activity_log` | OWASP A09 audit trail |
| `wp_nte_reminder_log` | Event reminder email tracking |
| `wp_nte_reservations` | Atomic ticket reservations (race-condition safe) |
| `wp_nte_waitlist` | Waitlist entries with automatic promotion |
| `wp_nte_event_revisions` | Event revision history with diff data |

Ticket types use a `scope` column with CHECK constraints to enforce:
- `occurrence` scope requires `occurrence_id`
- `event` scope (series pass) requires `event_id`, no `occurrence_id`
- `template` scope requires `event_id`, propagates to occurrences

## Architecture

```
nettertech-events/
├── includes/
│   ├── API/                 # REST API controllers
│   ├── Admin/               # Admin pages, metaboxes, list tables
│   ├── Core/                # Plugin bootstrap, loader, assets
│   ├── Database/
│   │   ├── Tables/          # Table definitions (20 tables)
│   │   └── Queries/         # Query builders
│   ├── Enums/               # PHP 8.1+ enums (TicketTypeScope, etc.)
│   ├── Frontend/            # Shortcodes, templates, router
│   ├── Integrations/        # WooCommerce, Beaver Builder
│   ├── Models/              # Data models with validation
│   ├── Repositories/        # Database access layer
│   └── Services/            # Business logic (recurrence, capacity)
├── blocks/                  # Gutenberg blocks (calendar, carousel, grid)
├── assets/
│   └── dist/
│       ├── css/             # Compiled stylesheets
│       └── js/              # Compiled JavaScript
├── templates/               # Theme-overridable templates (29 files)
├── tests/                   # PHPUnit tests (Unit + Integration)
└── languages/               # Translation files
```

## CSS Customization

The plugin uses CSS custom properties for theming:

```css
:root {
    --nte-date-bg: #dc2626;
    --nte-color-primary: #667eea;
}
```

## Accessibility

The plugin is designed for **WCAG 2.2 AA compliance** with comprehensive accessibility features:

### Screen Reader Support
- ARIA labels on all interactive elements
- Live regions for dynamic content updates (carousel, filters)
- Semantic HTML structure (tables, fieldsets, time elements)
- Screen-reader-only labels for context (venue, dates)
- Accessible names for all form controls

### Keyboard Navigation
- Full keyboard support for carousel (Arrow keys, Tab)
- Pause/play control for autoplay carousels
- Skip links for bypassing repetitive content
- Focus indicators on all interactive controls
- Proper focus management in modals and dialogs

### Visual Accessibility
- `prefers-reduced-motion` support disables animations
- Sufficient color contrast ratios
- Focus visible states on all controls
- No reliance on color alone for information

### Semantic Structure
- Proper heading hierarchy
- Landmark regions (nav, main, region)
- Data tables with headers for attendee lists
- Calendar grid with proper ARIA roles
- Grouped form controls with fieldsets and legends

## Actions & Filters

### Plugin Lifecycle

```php
// Fires after plugin is fully initialized
do_action( 'nettertech_events_init', $plugin );

// Fires on plugin activation
do_action( 'nettertech_events_activated' );

// Fires on plugin deactivation
do_action( 'nettertech_events_deactivated' );
```

### Event Actions

```php
// Before/after event save (create or update)
do_action( 'nettertech_events_before_save_event', $event, $data );
do_action( 'nettertech_events_after_save_event', $event );

// Before/after event deletion
do_action( 'nettertech_events_before_delete_event', $event );
do_action( 'nettertech_events_after_delete_event', $id, $event );

// Before/after event duplication
do_action( 'nettertech_events_before_duplicate_event', $duplicate, $source );
do_action( 'nettertech_events_after_duplicate_event', $saved, $source );

// After occurrences are generated for recurring event
do_action( 'nettertech_events_occurrences_generated', $event, $occurrences, $rule );

// When occurrence status changes (scheduled, cancelled, etc.)
do_action( 'nettertech_events_occurrence_status_changed', $id, $new_status, $old_status );
```

### Attendee & Ticket Actions

```php
// After attendee created from WooCommerce order
do_action( 'nettertech_events_attendee_created', $attendee, $order, $item );

// After attendee cancelled (order cancelled/refunded)
do_action( 'nettertech_events_attendee_cancelled', $attendee, $order );

// After tickets partially refunded
do_action( 'nettertech_events_tickets_refunded', $attendee, $refunded_qty, $new_qty, $order, $refund );

// After RSVP form submitted
do_action( 'nettertech_events_rsvp_submitted', $attendee );
```

### Cache Actions

```php
// Fires after plugin caches are invalidated
// Hook here to clear your own related caches
do_action( 'nettertech_events_cache_invalidated' );
```

### Template Actions

```php
// After single event content (add ticket forms, related events, etc.)
do_action( 'nettertech_events_after_single_content', $event );

// After series page content
do_action( 'nettertech_events_after_series_content', $event );

// Inside occurrence row - add custom action buttons
do_action( 'nettertech_events_single_occurrence_actions', $occurrence, $event );

// Inside empty state - add custom content
do_action( 'nettertech_events_empty_state_content', $context );

// Before/after template file is loaded
do_action( 'nettertech_events_before_template_load', $file, $args );
do_action( 'nettertech_events_after_template_load', $file, $args );
```

### Display Filters

```php
// Customize empty state messages
add_filter( 'nettertech_events_carousel_empty_message', function( $message ) {
    return 'Check back soon for upcoming events!';
});

add_filter( 'nettertech_events_list_empty_message', function( $message ) {
    return 'No events match your search.';
});

add_filter( 'nettertech_events_empty_state_message', function( $message, $context ) {
    return $context === 'calendar' ? 'Nothing scheduled this month.' : $message;
}, 10, 2 );
```

### Asset Filters

```php
// Modify the detected views (forces view assets to load where added)
add_filter( 'nettertech_events_detected_views', function( $views ) {
    if ( is_page( 'my-events-page' ) ) {
        $views[] = 'list';
    }
    return $views;
});

// Modify localized JavaScript data
add_filter( 'nettertech_events_localize_data', function( $data ) {
    $data['customSetting'] = 'value';
    return $data;
});
```

### Template Filters

```php
// Modify template file candidates
add_filter( 'nettertech_events_get_template_part', function( $templates, $slug, $name ) {
    // Add custom template variant
    array_unshift( $templates, "custom-{$slug}.php" );
    return $templates;
}, 10, 3 );

// Modify template arguments before loading
add_filter( 'nettertech_events_template_args', function( $args, $file ) {
    $args['custom_data'] = 'value';
    return $args;
}, 10, 2 );

// Modify template search paths
add_filter( 'nettertech_events_template_paths', function( $paths ) {
    $paths[] = '/custom/template/path/';
    return $paths;
});
```

## Template Overrides

Override plugin templates by copying them to your theme:

```
your-theme/
└── nettertech-events/
    ├── single-event.php       # Single event/occurrence page
    ├── series-page.php        # Recurring event series page
    └── parts/
        ├── event-card.php     # Grid/carousel card
        ├── occurrence-row.php # Occurrence list item
        ├── more-dates.php     # Sibling occurrences nav
        └── empty-state.php    # No events message
```

Template variables are extracted and available directly (e.g., `$event`, `$occurrence`).

## Development

### File Structure

- Models use active record pattern with validation
- Repositories handle all database queries
- Services contain business logic
- Controllers handle HTTP requests

### Coding Standards

- PHP 8.2+ with `declare(strict_types=1)` in all files
- PSR-4 autoloading under `NetterTechEvents\` namespace
- WordPress Coding Standards (WPCS) — 0 errors, 0 warnings
- OWASP 2025 security compliance (capability check before nonce)
- PHPDoc on all public methods

### Testing

The plugin includes comprehensive test coverage:

| Type | Count | Framework |
|------|-------|-----------|
| Unit Tests | 5,623 (17,290 assertions) | PHPUnit 10 + Brain Monkey |
| E2E Tests | 280 `test()` calls across 17 spec files | Playwright |
| Statement Coverage | 74.48% | pcov |
| Method Coverage | 68.19% | pcov |

**Run Tests:**

```bash
# Run all unit tests
composer test

# Run with coverage report
composer test:coverage

# Run E2E tests
npx playwright test

# Run PHPCS then tests
composer check

# Coding standards only
composer phpcs

# Full CI suite (PHPCS + PHPUnit + syntax)
./scripts/test-all.sh
```

**Test Structure:**

| Directory | Purpose |
|-----------|---------|
| `tests/Unit/` | Pure PHP tests using Brain Monkey |
| `tests/Integration/` | Integration tests with database (6 root + 5 repository tests) |
| `tests/E2E/playwright/` | End-to-end Playwright tests (17 spec files) |
| `tests/Factories/` | Test data factories |

**Factories Available:**

- `EventFactory` — Create test events
- `OccurrenceFactory` — Create test occurrences
- `AttendeeFactory` — Create test attendees
- `TicketTypeFactory` — Create test ticket types
- `WooCommerceFactory` — Create WooCommerce products, orders, and cart items

**Quality Gates (Local):**

Git hooks enforce quality on every commit and push:
- **Pre-commit**: PHPCS runs on staged PHP files
- **Pre-push**: Full test suite must pass before push

Bypass hooks when needed: `git commit --no-verify` or `git push --no-verify`

## Roadmap

### Completed (v1.0.0)
- [x] Single event frontend template
- [x] Recurring event pages with occurrence list
- [x] Occurrence-specific pages with tickets
- [x] Built-in category system with dedicated editor (internal tables)
- [x] Spaces and locations management with admin interface and event metabox assignment
- [x] Theme-overridable template system
- [x] WooCommerce ticketing integration (HPOS compatible)
- [x] Series passes (one ticket for all occurrences)
- [x] Template tickets (define once, apply to all)
- [x] Centralized capacity service with series pass awareness
- [x] Admin ticket management metabox
- [x] QR code generation with color customization and logo embedding
- [x] Atomic check-in operations (race-condition safe)
- [x] Email confirmations (tickets, RSVPs, venue notifications)
- [x] Event reminder emails (cron-scheduled, configurable timing)
- [x] ICS calendar attachments in confirmation emails
- [x] iCal import/export with CategoryRepository integration
- [x] GDPR privacy tools (WordPress exporter/eraser)
- [x] Activity logging (OWASP A09 audit trail)
- [x] WCAG 2.2 AA accessibility compliance
- [x] Comprehensive test suite (5,623 unit + 280 E2E test cases in 17 specs, 74.48% statement coverage)
- [x] Gutenberg blocks (Calendar, Carousel, Grid)
- [x] Beaver Builder modules
- [x] ServiceRegistry DI pattern with 32 contracts
- [x] Security audit remediation (OWASP 2025 compliant)
- [x] N+1 query prevention with identity maps
- [x] Denormalized counter caching for ticket sales
- [x] PHPStan Level 7 static analysis
- [x] TEC migration via companion plugin (NetterTech Events Migrator)
- [x] Waitlist functionality with automatic promotion when capacity opens
- [x] Event revision history with diff view and one-click restore

### Planned (Post-v1.0 Release)
- [ ] Multi-day event support
- [ ] Object caching integration (Redis/Memcached)
- [ ] Google Calendar sync

## Quality Metrics

| Metric | Grade | Score |
|--------|-------|-------|
| Security | A | 94/100 |
| Code Quality | A- | 91/100 |
| Test Coverage | A | 93/100 |
| Standards Compliance | A+ | 97/100 |
| Architecture | B+ | 88/100 |
| Performance | B+ | 86/100 |
| Documentation & i18n | B | 77/100 |
| **Overall** | **B+** | **88/100** |

*Based on an internal software audit (empirically verified against live tooling).*

## Documentation

| Document | Description |
|----------|-------------|
| [Admin Manual](docs/ADMIN-MANUAL.md) | Complete admin documentation |
| [Quick-Start Guide](docs/ADMIN-QUICKSTART.md) | Get started in 5 minutes |
| [Hooks Reference](docs/HOOKS.md) | All actions and filters with examples |
| [Template Override Guide](docs/TEMPLATE-OVERRIDE.md) | Customize templates in your theme |
| [API Reference](docs/openapi.yaml) | OpenAPI 3.0 REST API specification |
| [Test Plan](docs/TEST-PLAN.md) | Testing strategy and coverage targets |
| [Performance Guide](docs/PERFORMANCE.md) | Caching, optimization, benchmarking |
| [Developer Guide](docs/DEVELOPER-GUIDE.md) | Codebase walkthrough, workflows, debugging |
| [Database Schema](docs/DATABASE-SCHEMA.md) | Complete table reference with ER diagram |
| [Contributing](CONTRIBUTING.md) | Development setup and standards |
| [Architecture Docs](docs/architecture/) | 16 Architecture Decision Records |

## License

GPL v2 or later
