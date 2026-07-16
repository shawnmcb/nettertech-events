# ADR-012: Custom Exception Hierarchy

**Date:** 2026-01-20
**Status:** Accepted
**Category:** Error Handling

## Context

PHP exceptions in the plugin need to:

1. Distinguish between domain errors (invalid capacity, duplicate email) and infrastructure errors (database failure, file write failure)
2. Provide meaningful error messages for admin UI display
3. Be catchable at appropriate levels (controller catches domain exceptions → user-friendly response; infrastructure exceptions bubble up)
4. Support structured error data beyond a message string

## Decision

**Use a custom exception hierarchy rooted in domain-specific base exceptions.**

### Hierarchy

```
\RuntimeException
  └── NetterTechEventsException (base for all plugin exceptions)
        ├── ValidationException     # Input/business rule violations
        ├── CapacityException       # Capacity-related failures
        ├── NotFoundException       # Entity not found
        ├── DuplicateException      # Uniqueness constraint violations
        ├── RRuleException          # Recurrence rule parse errors
        └── ConfigurationException  # Missing/invalid configuration
```

### Usage Pattern

```php
// Service layer throws domain exceptions
class CapacityService {
    public function reserve_ticket(int $ticket_type_id, int $qty): void {
        if (!$this->calculator->has_availability($occurrence_id, $qty)) {
            throw new CapacityException(
                'Insufficient capacity for this ticket type.',
                ['available' => $available, 'requested' => $qty]
            );
        }
    }
}

// Controller catches and converts to user-facing response
class TicketController {
    public function purchase(WP_REST_Request $request): WP_REST_Response {
        try {
            $this->capacity_service->reserve_ticket($type_id, $qty);
        } catch (CapacityException $e) {
            return new WP_REST_Response(
                ['message' => $e->getMessage()],
                409  // Conflict
            );
        }
    }
}
```

### Structured Error Data

Exceptions carry context beyond the message string:

```php
class NetterTechEventsException extends \RuntimeException {
    private array $context;

    public function __construct(string $message, array $context = [], ...) {
        $this->context = $context;
        parent::__construct($message, ...);
    }

    public function get_context(): array {
        return $this->context;
    }
}
```

### Repository Adoption

Repositories throw specific exceptions instead of returning null/false for error conditions:

- `find()` returns `?Entity` (null is valid — entity may not exist)
- `save()` throws `ValidationException` on constraint violations
- `delete()` throws `NotFoundException` if entity doesn't exist

## Alternatives Considered

### WordPress-Style Error Handling (WP_Error)

- **Pros**: WordPress standard. Works with `is_wp_error()` checks. Carries error codes and data.
- **Cons**: Not catchable — callers must check return values. Easy to forget the check, leading to silent failures. Can't propagate through call stacks without manual passing.

### Generic \Exception Everywhere

- **Pros**: No custom classes needed. Simple.
- **Cons**: Can't catch domain errors separately from infrastructure errors. All error handling becomes catch-all. Message parsing needed to determine error type.

### Result Objects (Result\<T, E\>)

- **Pros**: Explicit success/failure in type signature. No hidden control flow.
- **Cons**: PHP doesn't have native Result types. Would need a library or custom implementation. Verbose — every call site needs `if ($result->isErr())`. Not idiomatic PHP.

## Consequences

- **Positive**: Clear error taxonomy. Controllers can catch specific exceptions and return appropriate HTTP status codes. Structured context aids debugging. Exception types are documented with `@throws` in PHPDoc.
- **Negative**: Exception classes are additional files. WordPress developers may expect WP_Error instead. Must ensure exceptions don't bubble to the user as unhandled.
- **Mitigations**: Top-level exception handler in REST controllers catches `NetterTechEventsException` and returns structured error responses. Uncaught exceptions logged via `error_log()`.

## Related

- `includes/Exceptions/`: All exception classes
- `includes/API/`: Controllers with exception handling
- `includes/Repositories/`: Repository exception usage
