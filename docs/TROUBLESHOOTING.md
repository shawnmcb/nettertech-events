# NetterTech Events Troubleshooting Guide

> **Audience:** Site administrators diagnosing a problem with the plugin. Each entry states the symptom, the likely cause, and the fix.

Common issues, their causes, and solutions.

---

## Ticket & Capacity Issues

### Tickets show "Out of Stock" but seats are available

**Cause:** Stale capacity cache or pending reservations holding seats.

**Solution:**
1. Check for expired reservations: Go to **Events → Settings → Advanced** and verify the reservation timeout (default: 15 minutes). Abandoned checkouts hold capacity until reservations expire.
2. Clear the object cache: If using Redis/Memcached, flush the `nte` cache group. Without persistent cache, capacity recalculates on the next page load.
3. Verify sold counts: Go to the occurrence in the admin. Compare `sold_count` on each ticket type against actual WooCommerce orders. If mismatched, the capacity sync may need a manual trigger.

```bash
# WP-CLI: Check capacity for an occurrence
wp eval "echo json_encode(NetterTechEvents\Core\ServiceRegistry::get('capacity_calculator')->get_capacity_summary(OCCURRENCE_ID));"
```

### Capacity shows negative or zero when it shouldn't

**Cause:** Fixed ticket type allocations exceed occurrence capacity.

**Solution:** Tiers on an occurrence share **one house** — they are not separate allotments, and the house is never the sum of the tier capacities (see [ADR-019](architecture/ADR-019-house-capacity-model.md)). The house is the occurrence's capacity when set, otherwise the largest FIXED tier capacity.

So several tiers each carrying the full room size is a **valid** configuration, not an over-allocation: three 250-cap tiers in a 250-seat hall means each tier may sell the whole room, and the room still seats 250. Do not "correct" it.

Availability for a tier is `min(tier capacity − tier issued, house − total issued)`, so a tier reports whichever binds first — its own cap, or the seats left in the room. If a tier shows 0 available while its own cap looks unused, the house is full: check total issued across **all** tiers on that occurrence, not just the one you are looking at.

### WooCommerce order completed but no attendee created

**Cause:** The `woocommerce_order_status_completed` hook didn't fire, or the order items don't contain nettertech-events products.

**Solution:**
1. Verify the order contains a nettertech-events ticket product (check order item meta for `_ve_ticket_type_id`).
2. Check the PHP error log for exceptions from `OrderAttendeeCreator`.
3. Re-trigger processing: Change the order status to "Processing" then back to "Completed".

---

## Check-In Issues

### QR code won't scan

**Cause:** QR code image quality, camera focus, or code format.

**Solution:**
1. Ensure the QR code is displayed at a reasonable size (minimum 2cm/0.8in).
2. If the QR code is a data URI in an email, try opening the email in a different client — some email clients block inline images.
3. Verify the ticket code format: `XXXX-XXXX-XXXX-XXXX` (uppercase hex characters A-F, 0-9).
4. Test the URL directly: Navigate to `https://yoursite.com/ticket/XXXX-XXXX-XXXX-XXXX/` in a browser.

### Check-in returns "Already checked in"

**Cause:** The ticket was already scanned. The system prevents double check-ins using an atomic database update.

**Solution:** If the check-in was in error, an admin can reverse it via the attendee list in the admin panel. Look for the check-in/out toggle on the attendee row.

### Rate limit errors during check-in (HTTP 429)

**Cause:** The IP address exceeded the rate limit (default: 60 requests per 60 seconds).

**Solution:**
1. Check if a shared WiFi/network means many devices share one IP.
2. Increase the rate limit: **Events → Settings → Advanced → Rate Limit Requests**.
3. Admins are automatically exempt from rate limiting. Ensure the volunteer is logged in as a WordPress user with appropriate capabilities.
4. Manual reset: Delete the transient `ve_rate_limit_{md5_of_ip}` from the database.

---

## Event & Occurrence Issues

### Recurring event doesn't generate all dates

**Cause:** The occurrence generator has a safety limit (default: 365 occurrences per rule).

**Solution:**
1. Check the recurrence rule — daily events for more than a year will hit the limit.
2. Consider using a shorter recurrence with COUNT or UNTIL constraints.
3. For long-running series, generate in yearly batches.

### Occurrences disappear after editing recurrence rule

**Cause:** Changing a recurrence rule regenerates occurrences. Occurrences with existing ticket sales are protected from deletion.

**Solution:**
1. Occurrences with sold tickets are never deleted during regeneration.
2. Unsold occurrences are replaced with the new pattern.
3. To preserve all existing dates, edit individual occurrences instead of the recurrence rule.

### Event page shows 404

**Cause:** Permalink/rewrite rules not flushed after plugin activation.

**Solution:**
```bash
# WP-CLI
wp rewrite flush

# Or: Go to Settings → Permalinks and click "Save Changes" (no changes needed)
```

---

## Email Issues

### Confirmation emails not sending

**Cause:** WordPress mail configuration issue or email template error.

**Solution:**
1. Test WordPress email: Install a test plugin or use `wp eval "wp_mail('you@example.com', 'Test', 'Body');"`.
2. If `wp_mail()` returns false, the issue is in your WordPress mail configuration, not the plugin. Consider an SMTP plugin (WP Mail SMTP, FluentSMTP).
3. Check the PHP error log for template rendering errors.
4. Verify email settings: **Events → Settings → Email**.

### Calendar attachment (.ics) not working

**Cause:** The email client doesn't support inline ICS attachments, or the ICS content is malformed.

**Solution:**
1. Try opening the email in a desktop client (Apple Mail, Outlook) — they handle ICS better than webmail.
2. Check the "Add to Calendar" link on the event page as an alternative.
3. The ICS content follows RFC 5545 (VEVENT). If issues persist, check the occurrence's start/end times and timezone configuration.

---

## QR Code Issues

### QR codes not generating (blank images)

**Cause:** GD PHP extension not installed, or uploads directory not writable.

**Solution:**
1. Check GD extension: `php -m | grep gd` (must be enabled).
2. Check uploads directory: `wp-content/uploads/nettertech-events/qr/` must be writable (755).
3. If the directory doesn't exist, the plugin creates it on first QR generation. If it can't, QR codes fall back to base64 data URIs.

### QR codes look different than expected (wrong colors/style)

**Cause:** Per-event QR settings override global settings.

**Solution:**
1. Check global QR settings: **Events → Settings → QR Code**.
2. Check per-event overrides: Edit the event and look for QR code style settings.
3. After changing settings, existing QR code files are NOT regenerated. Delete files in `wp-content/uploads/nettertech-events/qr/` to force regeneration.

---

## Database Issues

### "Table doesn't exist" errors after plugin update

**Cause:** The schema migration didn't run, or `dbDelta()` failed silently.

**Solution:**
1. Deactivate and reactivate the plugin — this triggers schema creation.
2. Check the PHP error log for `dbDelta()` errors.
3. Verify MySQL user has CREATE TABLE permissions.

### Slow admin pages

**Cause:** Large tables without persistent object cache, or missing indexes.

**Solution:**
1. Install a persistent object cache (Redis or Memcached). See `docs/PERFORMANCE.md` for benchmarks showing 333ms → 6ms improvement.
2. Check table sizes: Large `nte_activity_log` tables can be pruned via **Events → Settings → Advanced → Log Retention** (default: 90 days).
3. Run `EXPLAIN` on slow queries to verify index usage.

### Orphaned records (attendees without occurrences)

**Cause:** Direct database modifications, interrupted operations, or deleted events.

**Solution:**
1. The plugin uses application-level referential integrity (no database foreign keys). Orphans are possible but rare during normal operation.
2. Check for orphans:
```sql
-- Attendees without occurrences
SELECT a.id FROM wp_ve_attendees a
LEFT JOIN wp_ve_occurrences o ON a.occurrence_id = o.id
WHERE o.id IS NULL;

-- Tickets without attendees
SELECT t.id FROM wp_ve_tickets t
LEFT JOIN wp_ve_attendees a ON t.attendee_id = a.id
WHERE a.id IS NULL;
```
3. Delete orphans if found (back up first).

---

## WooCommerce Issues

### "WooCommerce not detected" warning

**Cause:** WooCommerce is not installed, not activated, or loaded after nettertech-events.

**Solution:** The plugin works without WooCommerce (RSVP-only mode). To enable paid tickets, install and activate WooCommerce 8.5+. The warning is informational, not an error.

### Refund doesn't release ticket capacity

**Cause:** The refund was processed outside the normal WooCommerce flow (e.g., manual database change or payment gateway refund without WooCommerce notification).

**Solution:**
1. Process refunds through WooCommerce admin: **WooCommerce → Orders → [Order] → Refund**.
2. The plugin listens to `woocommerce_order_refunded` and `woocommerce_order_partially_refunded` hooks.
3. For manual corrections, use the admin attendee list to cancel the attendee and release capacity.

---

### Purchases view counts tickets from failed orders

**Symptom:** The Attendees / Purchases view lists seats (status "Voided") whose order failed, or on sites upgraded from a version before 1.4.0 the summary counts and capacity still include tickets from orders that never paid.

**Explanation:** Since 1.4.0 a failed or cancelled order voids its attendees automatically and the default Purchases view hides seats voided by an unpaid order (refund-voided seats stay listed; the **Failed orders** status filter shows the hidden ones). Attendees created by orders that failed *before* 1.4.0 are not touched retroactively.

**Fix (one-time, WP-CLI):**

```bash
# Preview — prints counts and a sample, writes nothing.
wp nettertech-events reconcile-order-status

# Apply — voids those attendees through the same path a live status change uses
# (tickets cancelled, capacity released, sold counts and product stock resynced).
wp nettertech-events reconcile-order-status --execute
```

## Getting Help

1. Check the PHP error log (`wp-content/debug.log` if `WP_DEBUG_LOG` is enabled)
2. Enable debug mode: Add `define('WP_DEBUG', true);` and `define('WP_DEBUG_LOG', true);` to `wp-config.php`
3. Check the activity log: **Events → Activity Log** shows recent operations with timestamps and user attribution
