# ADR-002: Service Locator Pattern (ServiceRegistry)

**Date:** 2024-12-23
**Status:** Superseded by [ADR-013](ADR-013-dependency-injection-migration.md)
**Category:** Dependency Management

## Context

The plugin has ~50 services, repositories, and integrations that depend on each other. A dependency management strategy is needed to:

1. Construct services with their dependencies
2. Ensure singleton instances where appropriate (repositories with identity maps)
3. Allow tests to substitute mock implementations
4. Keep the plugin bootstrappable from a single entry point

## Decision

**Use a Service Locator pattern via `ServiceRegistry`.**

```php
// Registration (composition root in nettertech-events.php)
ServiceRegistry::register('event_repo', fn() => new EventRepository());
ServiceRegistry::register('capacity_service', fn() => new CapacityService(
    ServiceRegistry::get('event_repo'),
    ServiceRegistry::get('ticket_type_repo'),
));

// Consumption
$service = ServiceRegistry::get('capacity_service');
```

### Key Properties

- **Lazy instantiation**: Factory closures execute on first `get()` call, not at registration time. Services unused in a request are never constructed.
- **Singleton by default**: Each service is instantiated once per request. The registry caches the result of the factory closure.
- **String keys**: Services identified by string names (`'event_repo'`, `'capacity_service'`).
- **128 registrations**: Current production codebase has ~128 `ServiceRegistry::get()` call sites.

## Alternatives Considered

### Constructor Injection (Pure DI)

- **Pros**: Explicit dependencies, compile-time validation, easier testing.
- **Cons**: WordPress's hook-based architecture makes pure DI challenging — services need to be available at `plugins_loaded` but their dependencies may not be ready until `init`. No PSR-11 container in WordPress core.

### PSR-11 DI Container (PHP-DI, League Container)

- **Pros**: Industry standard, autowiring, compiled containers.
- **Cons**: Adds Composer dependency. WordPress ecosystem doesn't use PSR-11 — increases onboarding friction. Overkill for a single plugin.

### WordPress `_doing_it_wrong()` Pattern (Global Functions)

- **Pros**: "The WordPress way." Functions like `nte_get_service()`.
- **Cons**: No type safety. No IDE autocomplete. Testing requires function mocking.

## Consequences

- **Positive**: Simple to understand. Lazy loading keeps unused services out of memory. Single composition root makes dependency graph visible.
- **Negative**: Service Locator is an anti-pattern in DI literature — it hides dependencies (constructors don't declare what they need). String keys have no compile-time validation. `ServiceRegistry::get()` calls scattered through codebase create implicit coupling.
- **Mitigations**: All services also accept dependencies via constructor injection — `ServiceRegistry` is used as a composition root, not as a service passed into constructors. Tests construct services directly with mock dependencies, bypassing the registry entirely.

## Superseded

This ADR was superseded by ADR-013 (Dependency Injection Migration) in February 2026. The migration replaced direct `ServiceRegistry::get()` calls with constructor injection throughout the codebase. `ServiceRegistry` now delegates to a `Container` class and its accessor methods are deprecated. See ADR-013 for the current architecture.

## Related

- `includes/Core/ServiceRegistry.php`: Registry implementation
- `nettertech-events.php`: Composition root (service registration)
- `includes/Contracts/`: Interface definitions for all services
