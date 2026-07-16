# ADR-007: Local QR Code Generation

**Date:** 2025-04-01
**Status:** Accepted
**Category:** Infrastructure

## Context

Each ticket needs a unique QR code for check-in. The QR code encodes a URL (`/ticket/{CODE}/`) that works for both:

- **Volunteer check-in**: Volunteer scans with phone camera, cookie-based auth auto-checks-in the attendee.
- **Attendee view**: Attendee views their ticket status and "present at door" confirmation.

The system needs to generate QR codes that are:

1. Scannable by standard phone cameras (no special app)
2. Customizable (colors, logos, dot styles) per venue branding
3. Available offline (no external service dependency)
4. Embeddable in confirmation emails

## Decision

**Generate QR codes locally using the `chillerlan/php-qrcode` library with GD image processing.**

### Architecture

```
QRCodeService (orchestration)
  ├── generate_ticket_code()     → Cryptographic random: XXXX-XXXX-XXXX-XXXX
  ├── generate_for_ticket()      → PNG file in uploads/nettertech-events/qr/
  ├── generate_data_uri()        → Base64 for email embedding
  └── get_check_in_url()         → /ticket/{CODE}/

QRCodeRenderer (rendering engine)
  ├── generate_data_uri()        → SVG/PNG → base64
  ├── generate_data_uri_with_logo() → QR + logo overlay
  └── generate_png()             → Raw PNG binary
  └── (private) GD pipeline: opacity, rounded corners, finder patterns
```

### Customization Options

| Setting | Options | Default |
|---------|---------|---------|
| Foreground color | Any hex | `#000000` |
| Background color | Any hex | `#ffffff` |
| Background opacity | 0-100% | 100% |
| Scale | 3-20 px/module | 5 |
| Dot style | rounded, square | rounded |
| Finder style | rounded, square | square |
| Logo | none, site logo, custom | none |

### File Storage

- QR code PNGs stored in `wp-content/uploads/nettertech-events/qr/`.
- Filename: ticket code without dashes + `.png` (e.g., `A1B2C3D4E5F6G7H8.png`).
- Generated on first access, cached as files.
- Fallback to base64 data URI if uploads directory is unavailable.

## Alternatives Considered

### External QR Code API (qrserver.com, goqr.me)

- **Pros**: Zero server-side dependencies. No GD extension needed.
- **Cons**: External dependency — if the API goes down, no QR codes. Privacy concern — ticket URLs sent to third party. Rate limits on free tiers. Latency on email generation (HTTP call per ticket).

### JavaScript QR Code Generation (Client-Side)

- **Pros**: No server load. Libraries like `qrcode.js` are lightweight.
- **Cons**: Can't embed in confirmation emails (emails don't execute JavaScript). Check-in page would need to generate QR codes on load.

### Simple QR (No Customization)

- **Pros**: Just use `chillerlan/php-qrcode` default output. No GD pipeline.
- **Cons**: Generic black-and-white QR codes don't match venue branding. No logo embedding. Missed opportunity for professional-looking tickets.

## Consequences

- **Positive**: Zero external dependencies at runtime. Full branding control. Works offline. Embeddable in emails as data URIs or as hosted image URLs.
- **Negative**: Requires GD PHP extension (standard on most hosts). QR code rendering is CPU-intensive for large batches. `chillerlan/php-qrcode` is a Composer dependency (~50KB).
- **Mitigations**: File-based caching means each QR code is generated once. Lazy renderer instantiation — `QRCodeRenderer` only constructed when QR generation is actually needed.

## Related

- `includes/Services/QRCodeService.php`: Orchestration and file management
- `includes/Services/QRCodeRenderer.php`: Rendering engine with GD pipeline
- `includes/Contracts/QRCodeServiceInterface.php`: Public API contract
