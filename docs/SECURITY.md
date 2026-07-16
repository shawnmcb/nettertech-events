# NetterTech Events Security Guide

> **Audience:** Site administrators hardening a production deployment, security reviewers evaluating the plugin, and developers changing authentication or authorization code. Covers the OWASP-aligned security architecture.

Security configuration, hardening options, and best practices for production deployments.

---

## Security Architecture Overview

The plugin follows OWASP 2025 guidelines across all layers:

| Layer | Protection | Implementation |
|-------|-----------|----------------|
| Input | Sanitization | `sanitize_text_field()`, `absint()`, type-specific sanitizers |
| Output | Escaping | `esc_html()`, `esc_attr()`, `esc_url()` on all output |
| Database | Prepared statements | `$wpdb->prepare()` with typed placeholders (`%d`, `%s`) |
| Authentication | Capability checks | `current_user_can()` before every privileged operation |
| CSRF | Nonce verification | `wp_verify_nonce()` on all state-changing requests |
| API | Permission callbacks | Every REST endpoint has an explicit `permission_callback` |
| Audit | Activity logging | All CRUD operations logged with user, timestamp, IP |
| Rate limiting | Transient-based throttling | Configurable per-IP request limits |

**Security check ordering:** Capability check FIRST, then nonce verification. This prevents information leakage — an unauthenticated user gets "forbidden" not "invalid nonce."

---

## Rate Limiting

### Configuration

**Location:** Events → Settings → Advanced

| Setting | Default | Description |
|---------|---------|-------------|
| Rate Limit Requests | 60 | Maximum requests per window per IP |
| Rate Limit Window | 60 seconds | Sliding window duration |

### Response Headers

Rate-limited endpoints return standard headers:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1707350400
Retry-After: 30          (only on 429 responses)
```

### Protected Endpoints

- `POST /wp-json/nettertech-events/v1/check-in/{code}` — Check-in operations
- Ticket lookup endpoints
- Public calendar data feeds

### Customization

```php
// Increase rate limit for high-traffic check-in scenarios
add_filter('nte_rate_limit_config', function($config) {
    $config['requests'] = 120;  // 120 requests
    $config['window']   = 60;   // per 60 seconds
    return $config;
});

// Exempt logged-in users from rate limiting
add_filter('nte_rate_limit_bypass', function($bypass) {
    return is_user_logged_in();
});
```

### IP Detection

The rate limiter identifies clients through `ClientIpResolver` (NTE-142), which decides per request whether forwarded headers can be trusted — using only signals a direct internet client cannot forge:

1. **Local reverse proxy:** when `REMOTE_ADDR` is non-public (RFC 1918, CGNAT, loopback), the connection came from local infrastructure, so the forwarded header is trusted. Internet packets cannot source from these ranges.
2. **Known proxy ranges:** when `REMOTE_ADDR` falls inside a shipped list of Cloudflare egress ranges (extendable via the `nettertech_events_trusted_proxy_ranges` filter), the matching header is trusted. A TCP handshake cannot be completed from a spoofed source address.
3. **Otherwise the connection is direct:** `REMOTE_ADDR` is used and all forwarded headers are ignored. Trusting them unconditionally would let an attacker bypass the per-IP limit by rotating header values (tracked as `SEC-MED-01`).

Forwarded headers are consulted in priority order `CF-Connecting-IP` → `X-Real-IP` → `X-Forwarded-For`. For `X-Forwarded-For` the **last** entry is used — it is the one appended by the proxy that fronted the request; the first entry is client-supplied whenever proxies append rather than overwrite.

Most sites need no configuration: local proxies and Cloudflare are recognized automatically, and **Site Health** ("NetterTech Events visitor identification") reports which path requests are taking, flagging sites that appear to sit behind an unrecognized CDN.

Overrides, in precedence order:

1. **wp-config.php constant** (highest; back-compat with 1.1.1.3):

   ```php
   define( 'NETTERTECH_EVENTS_TRUSTED_PROXY', true );  // always trust forwarded headers
   define( 'NETTERTECH_EVENTS_TRUSTED_PROXY', false ); // never trust them
   ```

2. **Settings → Advanced → Proxy Handling:** Auto-detect (default) / Direct / Behind a proxy.

3. **Proxy ranges filter** — the zero-risk way to support another CDN or load balancer, since trust stays conditional on the connection's true source:

   ```php
   add_filter( 'nettertech_events_trusted_proxy_ranges', function ( $ranges ) {
       $ranges[] = '203.0.113.0/24'; // your LB or CDN egress range
       return $ranges;
   } );
   ```

**Do not** force header trust (constant `true` or the "Behind a proxy" setting) unless requests genuinely pass through a proxy that controls those headers — otherwise the rate limiter becomes bypassable.

---

## Capabilities and Roles

### Current State (v1.0.0)

NetterTech Events Core does **not** register custom capabilities. All admin UI and REST admin endpoints gate on `manage_options` (WordPress Administrator). This is a deliberate ship decision for v1.0.0 — the audit tracked under `SEC-02` in the project's audit ledger recommends introducing granular custom capabilities (`nte_manage_events`, `nte_edit_attendees`, `nte_check_in_attendees`, `nte_view_reports`) in a future release with a migration. Until that ships, granular capability management is done at the WordPress-role level via a companion plugin like Members or User Role Editor.

### Planned Capability Model (tracked for post-1.0.0)

Once custom capabilities ship, the intended model is:

| Capability (planned) | Grants | Default Role |
|-----------|--------|-------------|
| `nte_manage_events` | Full event management | Administrator |
| `nte_edit_events` | Create and edit events | Administrator |
| `nte_delete_events` | Delete events | Administrator |
| `nte_manage_attendees` | View and manage attendees | Administrator |
| `nte_check_in_attendees` | Perform check-in operations | Administrator, Shop Manager |
| `manage_options` | Plugin settings access | Administrator (unchanged — WordPress core capability) |

### Custom Role for Volunteers (planned)

Once custom capabilities ship, a check-in-only role can be added as:

```php
// Planned — not yet available in v1.0.0.
add_role( 'nte_volunteer', 'Event Volunteer', array(
    'read'                      => true,
    'nte_check_in_attendees'    => true,
) );
```

### Restricting Access (planned)

```php
// Planned — not yet available in v1.0.0.
$editor = get_role( 'editor' );
$editor->remove_cap( 'nte_edit_events' );
```

---

## Check-In Security

### Token-Based Authentication

The volunteer check-in interface uses cryptographically secure tokens:

- **Token generation:** 48 bytes of random data via `random_bytes()`, encoded as hex
- **Storage:** Stored as a WordPress user meta value
- **Expiry:** Configurable (default: session-based)
- **Cookie-based workflow:** Volunteer scans QR code with phone camera → cookie validates → auto check-in

### Public Ticket Pages

When a non-authenticated user scans a QR code:

- They see a "Present this at the door" confirmation page
- No sensitive data is exposed (no attendee email, no order details)
- The ticket status (valid/used/cancelled) is shown

---

## Activity Logging

### What's Logged

| Category | Events Tracked |
|----------|---------------|
| Events | Create, update, delete, duplicate, status change |
| Occurrences | Generate, cancel, capacity change |
| Attendees | Create, cancel, check-in, check-out |
| Tickets | Issue, cancel, refund |
| Settings | Any settings change |
| Data | Export, erasure (GDPR) |

### Log Entry Fields

Each log entry records:

- `user_id` — WordPress user who performed the action
- `action` — Operation type (create, update, delete, etc.)
- `entity_type` — What was affected (event, attendee, ticket, etc.)
- `entity_id` — ID of the affected record
- `changes` — JSON of before/after values for updates
- `ip_address` — Client IP address
- `created_at` — Timestamp

### Retention

**Location:** Events → Settings → Advanced → Log Retention

- Default: 90 days
- Minimum: 30 days
- Automatic cleanup runs on WordPress cron

### Viewing Logs

Go to **Events → Activity Log** in the admin. Filter by:
- Date range
- Action type
- Entity type
- User

---

## Data Protection (GDPR)

### Personal Data Stored

| Table | Personal Data | Purpose |
|-------|--------------|---------|
| `nte_attendees` | Name, email | Event registration |
| `nte_activity_log` | IP address, user ID | Audit trail |
| `nte_waitlist` | Email | Waitlist notification |

### Data Export

The plugin integrates with WordPress's personal data export tool (**Tools → Export Personal Data**). When an export is requested for an email address:

- All attendee records for that email are included
- Ticket details and check-in history are included
- Waitlist entries are included

### Data Erasure

The plugin integrates with WordPress's personal data erasure tool (**Tools → Erase Personal Data**). When erasure is requested:

- Attendee records are anonymized (name and email cleared)
- Activity log entries retain the action but the user association is removed
- Waitlist entries are deleted

---

## File Security

### QR Code Storage

- **Path:** `wp-content/uploads/nettertech-events/qr/`
- **Protection:** `index.php` placed in directory to prevent directory listing
- **Filenames:** Derived from ticket codes (hex characters only — no user input in filenames)
- **Access:** Public (QR code images are accessed via direct URL in emails)

### Recommended .htaccess

For Apache, add to `wp-content/uploads/nettertech-events/.htaccess`:

```apache
# Prevent PHP execution in uploads
<FilesMatch "\.php$">
    Order Deny,Allow
    Deny from all
</FilesMatch>
```

For Nginx, add to your server block:

```nginx
location ~* /wp-content/uploads/nettertech-events/.*\.php$ {
    deny all;
}
```

---

## Production Hardening Checklist

- [ ] Set `WP_DEBUG` to `false` in production
- [ ] Configure SMTP for reliable email delivery (WP Mail SMTP or similar)
- [ ] Install a persistent object cache (Redis/Memcached) for capacity accuracy under load
- [ ] Set appropriate rate limits for your expected check-in volume
- [ ] Review activity log retention period
- [ ] Ensure WordPress is served over HTTPS (QR code URLs use `home_url()`)
- [ ] Keep WordPress, WooCommerce, and NetterTech Events updated
- [ ] Restrict database user permissions to minimum required (SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP on plugin tables)
- [ ] Add PHP execution prevention in uploads directory (see above)
- [ ] Configure backup schedule that includes custom `ve_*` tables
