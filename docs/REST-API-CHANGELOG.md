# REST API Stability Contract

> **Audience:** Integrators and developers consuming the NetterTech Events REST API.

**Namespace:** `nettertech-events/v1`
**Base URL:** `/wp-json/nettertech-events/v1/`
**Last Updated:** 2026-04-20 — inventory verified against `includes/API/` controllers.

## Versioning Policy

- The API uses URL-path versioning (`/v1/`).
- Breaking changes (removed endpoints, changed response shapes, removed fields) require a major version bump (`/v2/`).
- Additive changes (new endpoints, new optional fields in responses) are allowed within the current version.
- Deprecated endpoints will be announced in this file at least one minor plugin release before removal.

## Deprecation Procedure

1. Add `X-NTE-Deprecated: <replacement-endpoint>` header to responses.
2. Document in this changelog with the deprecation date and target removal version.
3. Log usage of deprecated endpoints via `DebugLogger` when `WP_DEBUG` is true.
4. Remove no earlier than the next major version.

## Authentication

| Type | Mechanism | Used By |
|------|-----------|---------|
| **Public** | None | Event listings, iCal feeds, waitlist |
| **Authenticated** | `X-WP-Nonce` cookie auth | Admin endpoints |
| **Capability** | `manage_options` (admin controllers) or `edit_posts` | Write operations |

All admin endpoints verify both nonce and capability before processing.

## Endpoint Inventory

The core plugin registers **20 routes** across 7 REST controllers in `includes/API/`, plus one CSP violation report endpoint registered by `SecurityHeaders`. Routes for QR check-in, volunteer scan, and check-in reporting are provided by the separate NetterTech Events Pro add-on and are documented there.

### Public Endpoints (no authentication required)

| Method | Path | Controller | Description |
|--------|------|------------|-------------|
| GET | `/events/upcoming` | EventsController | Upcoming events with occurrences |
| GET | `/events/range` | EventsController | Events within a date range |
| GET | `/events/{id}` | EventsController | Single event with occurrences |
| GET | `/occurrences` | EventsController | Occurrences (filterable) |
| GET | `/ical/feed` | ICalController | Full iCal feed |
| GET | `/ical/event/{id}` | ICalController | Single event iCal |
| GET / POST | `/ical/occurrence/{id}` | ICalController | Single occurrence iCal |
| POST | `/ical/import` | ICalController | Import iCal feed (public POST, validated) |
| POST / GET | `/waitlist/join` | WaitlistRestController | Join the waitlist for a sold-out occurrence |
| GET / POST | `/waitlist/status` | WaitlistRestController | Check waitlist position by email |
| POST | `/waitlist/leave` | WaitlistRestController | Leave the waitlist with the signed `leave_token` returned by `/waitlist/join` |
| POST | `/csp-report` | SecurityHeaders | CSP violation report receiver (browser-emitted) |

### Admin Endpoints (nonce + capability required)

| Method | Path | Controller | Capability | Description |
|--------|------|------------|------------|-------------|
| GET | `/admin/events` | EventsAdminController | `manage_options` | List events (admin view) |
| POST | `/admin/events` | EventsAdminController | `manage_options` | Create event |
| GET | `/admin/events/{id}` | EventsAdminController | `manage_options` | Single event detail (admin) |
| PUT / PATCH | `/admin/events/{id}` | EventsAdminController | `manage_options` | Update event |
| GET | `/admin/attendees` | AttendeesAdminController | `manage_options` | List attendees for occurrence |
| POST | `/admin/attendees` | AttendeesAdminController | `manage_options` | Create attendee |
| GET | `/admin/attendees/{id}` | AttendeesAdminController | `manage_options` | Single attendee detail |
| PUT / PATCH | `/admin/attendees/{id}` | AttendeesAdminController | `manage_options` | Update attendee |
| GET | `/admin/ticket-types` | TicketTypesAdminController | `manage_options` | List ticket types |
| POST | `/admin/ticket-types` | TicketTypesAdminController | `manage_options` | Create ticket type |
| GET | `/admin/ticket-types/{id}` | TicketTypesAdminController | `manage_options` | Single ticket type detail |
| PUT / PATCH | `/admin/ticket-types/{id}` | TicketTypesAdminController | `manage_options` | Update ticket type |

### Registration Field Endpoints

Attendee-field schemas per event and per-attendee field values.

| Method | Path | Controller | Auth | Description |
|--------|------|------------|------|-------------|
| GET | `/events/{event_id}/attendee-fields` | AttendeeFieldsController | Public | List field definitions for an event |
| POST | `/events/{event_id}/attendee-fields` | AttendeeFieldsController | Capability | Create field definition |
| PUT / PATCH | `/events/{event_id}/attendee-fields/{id}` | AttendeeFieldsController | Capability | Update field definition |
| DELETE | `/events/{event_id}/attendee-fields/{id}` | AttendeeFieldsController | Capability | Delete field definition |
| GET | `/attendees/{attendee_id}/custom-fields` | AttendeeFieldsController | Capability | Retrieve an attendee's field values |

### Rate Limiting

All admin write endpoints and public endpoints with abuse potential (iCal import, waitlist join/leave) are guarded by `RateLimitService`. Authenticated users with `edit_posts` capability bypass rate limiting for their own requests to support bulk operations. Responses set these headers when rate-limited or approaching the limit:
- `X-RateLimit-Limit` — requests per window
- `X-RateLimit-Remaining` — remaining requests
- `X-RateLimit-Reset` — window reset timestamp

When rate-limited, the response is HTTP 429 with a `retry_after` field (seconds to wait) in the body.

### Waitlist Leave Authorization

`POST /waitlist/join` returns a signed `leave_token`. `POST /waitlist/leave` requires that token by default, alongside `occurrence_id` and `email`; extension plugins may authorize alternate proofs through the `nettertech_events_waitlist_leave_authorized` filter.

Deployments upgrading from a build that allowed email-only waitlist removal should note that existing browser-local waitlist state will not contain `leave_token`. Those users must rejoin or use an extension-provided authorization path before self-service removal succeeds.

## Suite Cross-References

The NetterTech Events suite is six WordPress plugins. Each plugin documents its own REST/AJAX surface:

| Plugin | Namespace | Endpoint reference |
|---|---|---|
| nettertech-events (base) | `nettertech-events/v1` | This document + `openapi.yaml` |
| nettertech-events-pro | `nettertech-events/v1` (shared) | `nettertech-events-pro/docs/REST-API.md` + `REST-API-CHANGELOG.md` |
| nettertech-grant-reporter | `nettertech-events-gr/v1` | `nettertech-grant-reporter/docs/REST-API.md` + `REST-API-CHANGELOG.md` |
| nettertech-events-rentals | `nettertech-events-rentals/v1` | `nettertech-events-rentals/docs/api.md` |
| nettertech-magazine | `nettertech-magazine/v1` | `nettertech-magazine/docs/rest-api.md` |
| nettertech-events-migrator | (admin AJAX only; no REST surface) | `nettertech-events-migrator/docs/` |

The full cross-plugin endpoint inventory with auth/rate-limit/PII metadata is maintained at `audit/suite/endpoints/PUBLIC-ENDPOINT-REGISTER.md` (audit-folder; canonical source for endpoint claims).

## Changelog

### v1.0.0 (plugin release 1.0.0)

- Initial API release: 20 routes across 7 controllers plus the SecurityHeaders CSP-report endpoint.
- Routes grouped by controller: EventsController (4), ICalController (4), WaitlistRestController (3), AttendeeFieldsController (3), and three admin controllers (EventsAdmin, AttendeesAdmin, TicketTypesAdmin — 2 each).
- Check-in, volunteer scan, and check-in reporting endpoints are provided by the NetterTech Events Pro add-on and versioned independently.
