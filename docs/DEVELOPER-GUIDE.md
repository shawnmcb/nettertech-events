# Developer Guide

> **Audience:** Plugin developers contributing to or extending NetterTech Events. Assumes comfort with PHP 8.2, WordPress development, and the Composer / Playwright / PHPUnit toolchain.

A practical guide for developers working on the NetterTech Events plugin. Covers codebase navigation, common workflows, dependency injection, and debugging.

> **Prerequisites:** PHP 8.2+, WordPress 6.5+, Composer. See [CONTRIBUTING.md](../CONTRIBUTING.md) for environment setup.

## Codebase Map

```
includes/
├── Admin/          (32 files) Admin pages, list tables, save handlers, branding
├── API/            (8 files)  REST API controllers (public + admin)
├── Contracts/      (18 files) Interfaces for repository and service contracts
├── Core/           (18 files) Plugin bootstrap, ServiceRegistry, Assets, CacheManager
├── Database/       (19 files) Schema, Table definitions, Query builders, Migrations
├── Enums/          (5 files)  PHP 8.1+ enums (AttendeeStatus, EventStatus, etc.)
├── Exceptions/     (5 files)  Custom exception hierarchy (see ADR-012)
├── Frontend/       (11 files) Router, Shortcodes, TicketRouter, Template resolver
├── Integrations/   (17 files) WooCommerce (cart, orders, products), Beaver Builder modules
├── Models/         (13 files) Data models with validation and computed properties
├── Repositories/   (17 files) Database access layer (one per entity type)
├── Services/       (30 files) Business logic (recurrence, capacity, QR, iCal, check-in)
├── TemplateLoader/ (6 files)  Theme-overridable template system (see ADR-011)
└── Utilities/      (3 files)  Path helpers, image helpers, database logger
```

**Total:** ~202 PHP files, ~58,500 authored SLOC

## Architecture at a Glance

### Request Lifecycle

```
WordPress init
  → Plugin::init()
    → ServiceRegistry::init() (registers all services in DI container)
    → Router::register() (rewrite rules for /events/* URLs)
    → Assets::register() (conditional CSS/JS loading)
    → AdminMenu::register() (admin pages)
    → REST API controllers registered via rest_api_init

Frontend request → Router::maybe_load_event_template()
  → Resolves event/occurrence from URL slug + datetime
  → Loads theme-overridable template via TemplateLoader
  → Template calls ServiceRegistry for repositories/services

Admin request → AdminMenu dispatches to page handlers
  → EventEditor, AttendeesPage, CategoryPage, etc.
  → Save handlers process form submissions
  → Repositories handle all database I/O
```

### Layered Architecture

| Layer | Purpose | Key Classes |
|-------|---------|-------------|
| **Controllers** | HTTP interface (REST + admin) | `EventsController`, `AdminMenu`, `Router` |
| **Services** | Business logic, orchestration | `RecurrenceService`, `CapacityCalculator`, `ICalService` |
| **Repositories** | Data access (one per entity) | `EventRepository`, `OccurrenceRepository`, `AttendeeRepository` |
| **Models** | Data structures + validation | `Event`, `Occurrence`, `Attendee`, `TicketType` |
| **Database** | Schema + table definitions | `Schema`, `EventsTable`, `OccurrencesTable` |

### Key Design Decisions

| Pattern | ADR | Why |
|---------|-----|-----|
| Custom tables (not post_meta) | [ADR-001](architecture/ADR-001-custom-database-tables.md) | 10-50x faster aggregate queries, type safety |
| Repository pattern | [ADR-003](architecture/ADR-003-repository-pattern.md) | Testable, swappable data access |
| Application-level integrity | [ADR-009](architecture/ADR-009-referential-integrity.md) | dbDelta() can't handle FK constraints |
| Per-request identity maps | [ADR-010](architecture/ADR-010-identity-maps.md) | Eliminate duplicate queries within a request |
| Theme-overridable templates | [ADR-011](architecture/ADR-011-template-system.md) | Themes can customize without modifying plugin |
| DI via ServiceRegistry | [ADR-013](architecture/ADR-013-dependency-injection-migration.md) | Constructor injection with static facade |

## ServiceRegistry & Dependency Injection

All services and repositories are registered in `ServiceRegistry` and resolved from a DI container. Never instantiate repositories or services directly in production code.

### Getting a Service

```php
use NetterTechEvents\Core\ServiceRegistry;

// Repositories (data access)
$events      = ServiceRegistry::event_repository();
$occurrences = ServiceRegistry::occurrence_repository();
$attendees   = ServiceRegistry::attendee_repository();
$tickets     = ServiceRegistry::ticket_repository();
$ticket_types = ServiceRegistry::ticket_type_repository();
$organizers  = ServiceRegistry::organizer_repository();
$categories  = ServiceRegistry::category_repository();
$tags        = ServiceRegistry::tag_repository();
$waitlist    = ServiceRegistry::waitlist_repository();

// Services (business logic)
$capacity    = ServiceRegistry::capacity_service();
$qr          = ServiceRegistry::qr_code_service();
$activity    = ServiceRegistry::activity_log_service();
$license     = ServiceRegistry::license_service();

// Infrastructure
$wpdb        = ServiceRegistry::wpdb();
$container   = ServiceRegistry::container();
```

### Constructor Injection Pattern

New classes should accept dependencies via constructor parameters:

```php
class MyService {
    public function __construct(
        private EventRepositoryInterface $event_repo,
        private OccurrenceRepositoryInterface $occurrence_repo
    ) {}
}
```

Register in `ServiceRegistry::register_services()`:

```php
$container->singleton(
    MyService::class,
    function () use ( $wpdb ) {
        return new MyService(
            $this->resolve( EventRepositoryInterface::class ),
            $this->resolve( OccurrenceRepositoryInterface::class )
        );
    }
);
```

### Testing with ServiceRegistry

In tests, use `ServiceRegistry::set()` to inject mocks:

```php
ServiceRegistry::set( EventRepositoryInterface::class, $mock_repo );
// ... run code under test ...
ServiceRegistry::reset(); // Clean up in tearDown()
```

## Naming Prefixes

The plugin uses two prefixes by design; this split is intentional and stable:

- **`nettertech_events_` / `nettertech-events`** for every developer-facing identifier: hooks, shortcodes, options, capabilities, the REST namespace, and the text domain. All new work uses this prefix.
- **`nte_` / `nte-` / `--nte-`** for high-volume internal and presentational identifiers: database table names (`wp_nte_*`), the `nte_event` shadow post type, CSS classes, and CSS custom properties. These predate the long prefix and stay short on purpose: table names are internal, and the CSS surface is a published theming API whose rename would break existing site customizations for no functional gain.

Do not "fix" `nte_` identifiers in these categories to the long prefix, and do not introduce `nte_` for anything in the first list.

## Common Workflows

### Adding a REST Endpoint

1. **Create or extend a controller** in `includes/API/`:

```php
class MyController extends \WP_REST_Controller {
    public function register_routes(): void {
        register_rest_route( 'nettertech-events/v1', '/my-endpoint', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_items' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );
    }
}
```

2. **Register in Plugin.php** under `init_rest_api()`.
3. **Add permission_callback** — never use `__return_true` for authenticated endpoints.
4. **Document** in `docs/openapi.yaml`.
5. **Write tests** in `tests/Unit/API/`.

### Adding an Admin Page

1. **Create a page class** in `includes/Admin/` extending the admin page pattern.
2. **Register in AdminMenu** via `add_submenu_page()`.
3. **Handle saves** in a separate handler class (not in the page renderer).
4. **Security:** Check `current_user_can()` first, then `wp_verify_nonce()`.

### Adding a Database Table

1. **Create a Table class** in `includes/Database/Tables/` implementing `TableDefinitionInterface`.
2. **Add to Schema::get_table_definitions()** in dependency order.
3. **Increment schema version** and add migration method if altering existing tables.
4. **Create a Repository** in `includes/Repositories/` with an interface in `Contracts/`.
5. **Register in ServiceRegistry**.
6. **Document** in [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md).

### Adding a Hook (Action or Filter)

1. **Fire the hook** in the appropriate service/repository:

```php
do_action( 'nettertech_events_after_save_event', $event );
// or
$value = apply_filters( 'nettertech_events_capacity_check', $capacity, $event );
```

2. **Document** in `docs/HOOKS.md` with parameters, example, and file location.
3. **Use the `nettertech_events_` prefix** for all hooks (see Naming Prefixes below).

### Extending a Model

Models in `includes/Models/` are value objects with computed properties:

```php
// Models have typed properties and computed getters
$event->title;                    // Direct property
$event->is_recurring();           // Computed from event_type
$event->get_series_url();         // Generated URL
$occurrence->get_formatted_date(); // Formatted display string
```

To add a field: add the column to the Table class, update the Model, update the Repository's `save()` and hydration methods.

## Debugging

### Enable Debug Logging

In `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );    // Logs to wp-content/debug.log
define( 'WP_DEBUG_DISPLAY', false ); // Don't show on screen
```

All catch blocks in the plugin log to `error_log()` when `WP_DEBUG` is true:

```php
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( 'NetterTechEvents: ...' );
}
```

### Common Issues

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| 404 on event URLs | Rewrite rules stale | `wp rewrite flush` or visit Settings → Permalinks |
| "Table doesn't exist" | Plugin not activated properly | Deactivate/reactivate, check `Schema::create_tables()` |
| WooCommerce cart issues | Product sync out of date | Check `ProductManager::ensure_product()` |
| Check-in not working | Token mismatch | Verify `checkin_token` column populated on occurrence |
| Capacity shows wrong | Stale cache | `CacheManager::invalidate_capacity()` or wait for TTL |

### Quality Tools

```bash
# Code standards (WordPress + PSR-12)
composer phpcs

# Auto-fix standards violations
composer phpcbf

# Static analysis (level 6)
composer analyse      # or: vendor/bin/phpstan analyse

# Unit tests
composer test         # or: vendor/bin/phpunit

# Single test file
vendor/bin/phpunit tests/Unit/Services/RecurrenceServiceTest.php
```

### Key Files for Debugging

| What | Where |
|------|-------|
| Plugin bootstrap | `includes/Core/Plugin.php` |
| URL routing | `includes/Frontend/Router.php` |
| Template loading | `includes/Frontend/EventTemplateResolver.php` |
| DI container | `includes/Core/ServiceRegistry.php` |
| Cache system | `includes/Core/CacheManager.php` |
| Schema/migrations | `includes/Database/Schema.php` |
| WooCommerce cart | `includes/Integrations/WooCommerce/CartHandler.php` |
| WooCommerce orders | `includes/Integrations/WooCommerce/OrderHandler.php` |

## Further Reading

- [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) — Complete table reference with ER diagram
- [HOOKS.md](HOOKS.md) — All 92 documented hooks with examples
- [TEMPLATE-OVERRIDE.md](TEMPLATE-OVERRIDE.md) — Theme template override system
- [SECURITY.md](SECURITY.md) — OWASP 2025 security guidelines
- [PERFORMANCE.md](PERFORMANCE.md) — Caching, optimization, query patterns
- [TEST-PLAN.md](TEST-PLAN.md) — Testing strategy and coverage
- [docs/architecture/](architecture/) — 14 Architecture Decision Records
