# ADR-004: REST API Design

**Date:** 2025-01-15
**Status:** Accepted
**Category:** API Architecture

## Context

The plugin needs a REST API for:

1. **Admin AJAX replacement**: Admin UI operations (event CRUD, ticket management, check-ins) currently use `admin-ajax.php`. REST endpoints are more structured, testable, and self-documenting.
2. **Frontend interactions**: Calendar data fetching, ticket availability checks, RSVP submissions.
3. **External integrations**: Third-party tools querying event data.

## Decision

**Use the WordPress REST API (`register_rest_route()`) with a namespaced, resource-oriented design.**

### Namespace and Versioning

```
/wp-json/nettertech-events/v1/events
/wp-json/nettertech-events/v1/occurrences
/wp-json/nettertech-events/v1/tickets
/wp-json/nettertech-events/v1/check-in
```

- **Namespace**: `nettertech-events/v1`
- **Versioning**: URL path (`/v1/`). Breaking changes get `/v2/`.
- **Documentation**: OpenAPI 3.0 spec in `docs/openapi.yaml`.

### Controller Structure

```
includes/API/
  EventController.php          # CRUD for events
  OccurrenceController.php     # CRUD for occurrences
  TicketController.php         # Ticket operations
  CheckInController.php        # Check-in/out operations
  TicketLookupController.php   # Public ticket validation
  CalendarController.php       # Calendar data feed
  ICalController.php           # iCal import/export
  SettingsController.php       # Plugin settings
```

### Authentication and Authorization

- **Admin endpoints**: `permission_callback` checks `current_user_can('manage_nte')` or specific capabilities.
- **Public endpoints**: `permission_callback => '__return_true'` with rate limiting (ADR: Rate Limiting).
- **Pattern**: Capability check FIRST, then nonce verification. This prevents information leakage — an unauthenticated user gets "forbidden" not "invalid nonce."

```php
'permission_callback' => function ($request) {
    if (!current_user_can('manage_nte')) {
        return new WP_Error('forbidden', '', ['status' => 403]);
    }
    return true;
},
```

### Request Validation

- WordPress's `validate_callback` and `sanitize_callback` on each argument.
- `sanitize_text_field()`, `absint()`, `rest_sanitize_boolean()` per argument type.
- Complex validation (e.g., date ranges, capacity checks) in the controller method, not in callbacks.

### Response Format

- Standard WordPress REST responses with `WP_REST_Response`.
- Pagination via `X-WP-Total` and `X-WP-TotalPages` headers.
- Error responses use `WP_Error` with appropriate HTTP status codes.

## Alternatives Considered

### admin-ajax.php Only

- **Pros**: WordPress standard, works without REST API. Simpler for admin-only operations.
- **Cons**: No URL structure (everything is `admin-ajax.php?action=...`). No content negotiation. No self-documentation. No standard error format. Hard to test — requires full WordPress bootstrap.

### GraphQL (WPGraphQL)

- **Pros**: Flexible queries, single endpoint, strongly typed schema.
- **Cons**: Plugin dependency (WPGraphQL). Overkill for this use case. WordPress community has standardized on REST.

### Custom Endpoints (Non-REST)

- **Pros**: Full control over routing and response format.
- **Cons**: Reinvents what WordPress REST API already provides. No standard authentication, no content negotiation, no discoverability.

## Consequences

- **Positive**: Standard WordPress REST API integration. Self-documenting with OpenAPI spec. Testable without full WordPress bootstrap (mock WP_REST_Request). Standard HTTP semantics (GET/POST/PUT/DELETE).
- **Negative**: REST API must be enabled (some security-hardened sites disable it). Rate limiting needed for public endpoints to prevent abuse.
- **Mitigations**: Rate limiting via `RateLimitService` (transient-based, configurable thresholds). Public endpoints return minimal data.

## Related

- `docs/openapi.yaml`: Full OpenAPI 3.0 specification
- `includes/API/`: All REST controllers
- `includes/Services/RateLimitService.php`: Rate limiting implementation
