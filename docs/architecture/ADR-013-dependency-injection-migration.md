# ADR-013: Dependency Injection Migration

**Date:** 2026-02-09
**Status:** Accepted
**Category:** Dependency Management
**Supersedes:** ADR-002 (Service Locator Pattern)

## Context

ADR-002 established a Service Locator pattern via `ServiceRegistry`. Over time, the architecture evolved:

1. A `Container` class (since v1.5.0) provides proper DI — singleton factories, lazy resolution, test overrides
2. `ServiceRegistry` became a static facade delegating to the Container
3. `Plugin.php` already wires most services via `$this->container->get()` (composition root)
4. Pro plugin classes adopted "poor man's DI" — nullable constructor parameters with `ServiceRegistry::method()` fallbacks

However, ~20 production call sites in Base and ~21 in Pro still used ServiceRegistry directly instead of receiving dependencies via constructor injection. This was the single remaining gap preventing Maintainability from reaching 92nd+ percentile.

## Decision

**Migrate all production code to constructor injection. Retain ServiceRegistry only for composition root bootstrapping and test infrastructure.**

### Four Injection Patterns

| Pattern | When | Example |
|---------|------|---------|
| **Constructor injection** | Services, repositories, controllers | `__construct(EventRepositoryInterface $repo)` |
| **Static `init()` method** | Static facade classes (FeatureGate, TicketDisplay) | `FeatureGate::init($license_service)` called from Plugin.php |
| **`nte_container()`** | Templates (view layer) | `nte_container()->get(Interface::class)` |
| **Composition root** | Plugin bootstrap only | `Plugin::__construct()` wires everything |

### ServiceRegistry Retention

ServiceRegistry continues to serve two purposes:

1. **Composition root**: `ServiceRegistry::init($container)` called from `Plugin::__construct()`
2. **Test infrastructure**: `set()`, `reset()`, `has_override()` used across 125+ test files for mock injection

Named accessor methods (`occurrence_repository()`, `capacity_service()`, etc.) are marked `@deprecated` and will be removed in a future major version.

### Pro Plugin Override

Pro's `ServiceRegistry::set(LicenseServiceInterface::class, $license_service)` is retained — this IS composition root behavior (the Pro plugin extending the Base container with its own implementation).

## Alternatives Considered

### PSR-11 Container (PHP-DI, League Container)

- **Pros**: Industry standard, autowiring, compiled containers
- **Cons**: Adds Composer dependency. Plugin already has a working Container. WordPress ecosystem doesn't use PSR-11. Migration overhead not justified for the marginal benefit.
- **Verdict**: The existing Container class provides everything needed. No external dependency required.

### Remove ServiceRegistry Entirely

- **Pros**: Forces pure DI everywhere. Clean break.
- **Cons**: Breaks test infrastructure (125+ test files). Breaks Pro plugin override mechanism. Theme-overridden templates may reference ServiceRegistry. Migration cost outweighs benefit.
- **Verdict**: Deprecate accessor methods; retain set/reset/has_override API.

### Global Functions for Template Access

- **Pros**: `nte_capacity_service()` — specific, typed
- **Cons**: One function per service is excessive. Would need ~14 functions. No IDE discoverability.
- **Verdict**: Single `nte_container()` function returning the Container is cleaner.

## Consequences

- **Positive**: All service dependencies are explicit in constructors. IDE autocomplete and static analysis work correctly. Classes are testable by constructing with mocks directly. Composition root in Plugin.php makes the full dependency graph visible.
- **Negative**: Static facade classes (FeatureGate, TicketDisplay) use `init()` pattern which is less pure than constructor injection. Templates use a service locator function. These are pragmatic trade-offs given WordPress's architecture.
- **Mitigations**: `init()` is called exactly once from the composition root. Templates are view files where service location is acceptable. Both patterns are documented in this ADR.

## Related

- `includes/Core/Container.php`: DI container implementation
- `includes/Core/ServiceRegistry.php`: Static facade (deprecated accessors)
- `includes/Core/Plugin.php`: Composition root
- `includes/Core/FeatureGate.php`: Static init() pattern example
- `nettertech-events.php`: `nte_container()` helper
- ADR-002: Original service locator decision (superseded)
