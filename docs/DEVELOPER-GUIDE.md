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
- **`nte_` / `nte-` / `--nte-`** for high-volume internal and presentational identifiers: the `nte_event` shadow post type, CSS classes, and CSS custom properties. These stay short on purpose: the CSS surface is a published theming API whose rename would break existing site customizations for no functional gain.

Do not "fix" `nte_` identifiers in these categories to the long prefix, and do not introduce `nte_` for anything in the first list.

## Comments

Seven rules. They apply to PHP, JavaScript, CSS, shell, and tests alike.

1. **Four properties.** A comment is minimal, addresses the code itself, is idempotent and
   not tied to any state, and appears only where a maintainer would otherwise build
   incorrectly.

2. **The wrong-change test.** Keep a comment only if its absence would let a maintainer make
   a wrong change. Restating the adjacent line fails this test, however accurate. This is the
   discriminator; "but it is true" is not a defence.

   ```php
   // Bad — restates the line.
   // Loop through the occurrences.
   foreach ( $occurrences as $occurrence ) {

   // Good — states a constraint the code does not show.
   // Ordered by start_utc: the caller pages on this order.
   ```

3. **Idempotence.** A comment must still be true after the next three issues ship. No
   "currently", "for now", "until X lands", "was Y before", and no "keep in sync with" notes
   for values that cannot realistically change. Rationale tied to a moment goes in the commit
   description.

4. **No provenance or precedent.** Comments explain the constraint, never the history. No
   ticket ids, no dates, no links, no "Pro already does it this way", no "extracted from
   AdminMenu". Issue linkage lives in the branch name; defect history lives in the commit.

5. **Negatives need a strong default to deny.** Say what something is *not* only when a naive
   read of the code would pull toward the denied possibility. Otherwise state the constraint
   positively.

   ```php
   // Justified — the naive fix is exactly this.
   // Do not add wp_kses_post() here: its allowlist has no <iframe>.
   ```

6. **Docblocks and JSDoc.** A paragraph of *why* usually compresses to one clause; keep only
   the surprising part. The PHPCS-required docblock shape stays, as do `@param`, `@return`,
   `@var`, `@throws`, `@package` and every `@since` value, `translators:` comment, and
   `phpcs:ignore` / `eslint-disable` justification.

7. **Tests.** No comments restating the test name, and none explaining a mock idiom. Keep only
   notes marking a deliberately odd fixture — a boundary value, or a row shape chosen to
   reproduce a specific engine behaviour.

### The gate

`composer check:comments` (`scripts/check-comment-standard.php`) scans comment text only —
strings and code can never trip it — across `includes`, `templates`, `assets/js`, `assets/css`,
`blocks`, `tests`, `scripts` and `.githooks`. It is run on demand and is not yet wired into
`composer check`, `composer quality`, `composer push`, or the pre-commit hook, because the
existing tree does not pass it. There is no escape hatch: rewrite the comment.

The gate catches rules 3, 4 and 5 mechanically. It cannot catch rule 2 — `// Constants.` and a
docblock that restates its method name both pass it — so the wrong-change test stays a human
judgement on every new comment, and belongs in every code-review brief.

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
