# Contributing to NetterTech Events

Thank you for your interest in contributing to NetterTech Events! This document provides guidelines and workflows for development.

## Development Environment

### Requirements

- **PHP**: 8.2+
- **WordPress**: 6.5+
- **WooCommerce**: 8.5+ (optional, for ticketing features)
- **Node.js**: 18+ (for build tools and E2E tests)
- **Composer**: 2.x

### Local Setup

1. Install [Local by Flywheel](https://localwp.com/)
2. Create a new WordPress site
3. Clone this repository to `wp-content/plugins/nettertech-events`
4. Run `composer install`
5. Run `npm install`
6. Activate the plugin

## Coding Standards

### PHP

- **PHP Version**: 8.2+ with strict types
- **Style**: PSR-12 + WordPress Coding Standards
- **Namespace**: `NetterTechEvents\`
- **Autoloading**: PSR-4

```php
<?php
declare(strict_types=1);

namespace NetterTechEvents\Services;

use NetterTechEvents\Models\Event;

class ExampleService {
    public function __construct(
        private readonly EventRepository $repository
    ) {}
}
```

### Key Conventions

1. **Enums** for status/type fields with `label()`, `fromString()`, `values()` methods
2. **Constructor property promotion** for dependency injection
3. **Match expressions** over switch statements
4. **Readonly properties** where appropriate
5. **Long array syntax**: use `array()` not `[]` — enforced by PHPCS (`Universal.Arrays.DisallowShortArraySyntax`)

### Running Code Checks

```bash
# Check coding standards
composer phpcs

# Auto-fix coding standards
composer phpcbf

# Run all checks
composer check
```

## Architecture

### Directory Structure

```
includes/
├── Admin/          # Admin UI, list tables, editors
├── API/            # REST API controllers
├── Core/           # Plugin bootstrap, loader, assets
├── Database/
│   ├── Tables/     # Table definitions (15 tables)
│   └── Queries/    # Query builders
├── Enums/          # PHP 8.1+ enums
├── Frontend/       # Shortcodes, templates, router
├── Integrations/   # WooCommerce, Beaver Builder
├── Models/         # Data models with validation
├── Repositories/   # Database access layer
└── Services/       # Business logic
```

### Key Patterns

1. **Repository Pattern**: All database access goes through repositories
2. **Service Layer**: Business logic in services, not models
3. **Hook Registration**: Use `Loader` class, not direct `add_action`
4. **Template Loading**: Use `TemplateLoader` for theme override support

## Security Guidelines

### OWASP Top 10 Compliance

1. **SQL Injection**: Always use `$wpdb->prepare()`
2. **XSS**: Escape all output (`esc_html()`, `esc_attr()`, `esc_url()`)
3. **CSRF**: Verify nonces on all form submissions
4. **Authorization**: Check capabilities before nonces

```php
// Correct order: capability first, then nonce
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized' );
}

if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'action_name' ) ) {
    wp_die( 'Invalid nonce' );
}
```

### REST API

- Always include explicit `permission_callback`
- Validate and sanitize all input
- Use schema validation

## Testing

### Running Tests

```bash
# Unit tests (no WordPress required)
composer test:unit

# Integration tests (requires WordPress)
composer test:integration

# All tests with coverage
composer test:coverage
```

### Test Structure

```
tests/
├── Unit/           # Pure PHP tests using Brain Monkey
├── Integration/    # WordPress integration tests
└── Factories/      # Test data factories
```

### Writing Tests

- Unit tests for pure PHP logic (services, parsers)
- Integration tests for WordPress-dependent code
- Use factories for test data

## Continuous Integration

### GitHub Actions

Every PR and push to `main` triggers the CI pipeline (`.github/workflows/ci.yml`):

1. **PHPCS** — WordPress Coding Standards check
2. **PHPStan L6** — Static type analysis
3. **PHPUnit** — Unit tests on PHP 8.2 and 8.3
4. **Coverage** — Code coverage report uploaded to Codecov (main branch only)

### Pre-commit Hooks

Install the local hooks for automatic quality checks before each commit:

```bash
git config core.hooksPath .githooks
```

The pre-commit hook runs: PHPCS, PHPStan, PHPUnit, and PHP syntax checks on staged files.

## Pull Request Process

### Before Submitting

1. Run `composer phpcs` - must pass
2. Run tests - must pass
3. Update documentation if needed
4. Add/update PHPDoc comments

### PR Guidelines

1. **One feature per PR** - Keep PRs focused
2. **Descriptive title** - Use conventional commits format
3. **Description** - Explain what and why
4. **Screenshots** - For UI changes

### Conventional Commits

```
feat: Add series pass ticket type
fix: Correct capacity calculation for refunds
refactor: Centralize BB module field registration
docs: Update README ticketing section
chore: Bump version to 0.8.0
```

## Database Changes

### Schema Modifications

1. Add migration to `Schema.php`
2. Increment `DB_VERSION`
3. Test fresh install AND upgrade path
4. Document in `architecture/01-plugin-architecture.md`

### Query Guidelines

- Use repositories for all queries
- Index frequently queried columns
- Optimize for date range queries (events/occurrences)

## Gutenberg Blocks

### Block Structure

```
blocks/
└── block-name/
    ├── block.json      # Block metadata
    ├── index.js        # Editor script (IIFE)
    ├── render.php      # Server-side render
    └── build/          # Compiled assets
```

### Block Guidelines

- Use IIFE pattern (no build step required)
- Server-side rendering via `render.php`
- Align attributes with shortcode parameters

## Beaver Builder Modules

### Module Structure

- Module class in `Integrations/BeaverBuilder/Modules/`
- Field registration in `BeaverBuilderIntegration.php`
- Frontend template in module's `includes/frontend.php`

## Documentation

### For Theme Developers

- **[Template Override Guide](docs/TEMPLATE-OVERRIDE.md)** — Customize plugin templates in your theme
- **[Hooks Reference](docs/HOOKS.md)** — All actions and filters with examples

### For Site Admins

- **[Admin Manual](docs/ADMIN-MANUAL.md)** — Complete feature documentation
- **[Quick-Start Guide](docs/ADMIN-QUICKSTART.md)** — Get started in 5 minutes

### For Developers

- **[Developer Guide](docs/DEVELOPER-GUIDE.md)** — Codebase walkthrough, workflows, debugging
- **[Database Schema](docs/DATABASE-SCHEMA.md)** — Complete table reference with ER diagram
- **[API Reference](docs/openapi.yaml)** — OpenAPI 3.0 specification
- **[Test Plan](docs/TEST-PLAN.md)** — Testing strategy and coverage targets
- **[Performance Guide](docs/PERFORMANCE.md)** — Caching, optimization, benchmarking
- **[Architecture Docs](architecture/)** — Design decisions and technical architecture

## Questions?

- Check `architecture/` docs for design decisions
- Check `docs/HOOKS.md` for available extension points
- Review existing code for patterns
- Open an issue for discussion

## License

By contributing, you agree that your contributions will be licensed under GPLv2+.
