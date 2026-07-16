# NetterTech Events Performance Guide

> **Audience:** Plugin developers, site administrators investigating slow queries or cache behavior, and add-on developers whose code runs on the event path. Covers query patterns, caching layers, and tuning knobs.

**Version:** 1.0.0
**Last Updated:** 2026-02-05
**Status:** Production Ready (Validated)

---

## Performance Targets

| Metric | Target | Critical Threshold | Measurement |
|--------|--------|-------------------|-------------|
| TTFB | < 200ms | < 500ms | Server response time |
| Page Load | < 2s | < 3s | Full page render |
| Frontend Queries | < 30 | < 50 | Per page load |
| Admin Queries | < 50 | < 100 | Per page load |
| Slow Query | < 100ms | < 500ms | Individual query |

---

## Live Benchmark Results (2026-01-17)

Benchmarks performed on a copy of a production event management site using Chrome DevTools Performance Traces with page reload (cold cache).

### Frontend Pages (Cold Load)

| Page | TTFB | LCP | CLS | Status |
|------|------|-----|-----|--------|
| Event List (`/events/`) | 333ms | 466ms | 0.01 | ⚠️ Within critical threshold |
| Series Page | 361ms | 459ms | 0.00 | ⚠️ Within critical threshold |
| Occurrence Page | 377ms | 475ms | 0.00 | ⚠️ Within critical threshold |

**Frontend Analysis:**
- TTFB averaging 357ms (above 200ms target, within 500ms critical)
- LCP averaging 467ms - acceptable performance
- Excellent CLS (0.00-0.01) - no layout shifts
- TTFB is dominated by PHP/database processing, not network latency
- Performance acceptable for production use

### Frontend Pages (Warm Cache)

With WP Rocket or browser caching active:

| Page | TTFB | LCP | CLS | Status |
|------|------|-----|-----|--------|
| Event List (`/events/`) | ~11ms | ~222ms | 0.00 | ✅ Excellent |
| Single Event | ~6ms | ~82ms | 0.00 | ✅ Excellent |

**Cached Performance Analysis:**
- TTFB drops dramatically with page caching (6-11ms)
- LCP under 250ms with warm cache
- Zero cumulative layout shift
- WP Rocket or similar caching strongly recommended for production

### Admin Pages

| Page | TTFB | LCP | CLS | Network Requests | Status |
|------|------|-----|-----|------------------|--------|
| Events List (`nettertech-events`) | 489ms | 955ms | 0.05 | 206 | ⚠️ Acceptable |

**Admin Analysis:**
- TTFB at 489ms (just under 500ms critical threshold)
- Higher request count expected due to WordPress admin + multiple plugins
- CLS at 0.05 is acceptable (threshold is 0.1)
- Admin performance impacted by plugin-heavy environment (Yoast, WooCommerce, TEC, etc.)

### Performance Summary

| Environment | TTFB Target | Achieved | Status |
|-------------|-------------|----------|--------|
| Frontend (cold) | <200ms | 333-377ms | ⚠️ Above target, within critical |
| Frontend (cached) | <200ms | 6-11ms | ✅ Excellent |
| Admin | <500ms | 489ms | ✅ Within threshold |

### Recommendations

1. **Enable Page Caching** (Critical):
   - WP Rocket, W3 Total Cache, or hosting-level caching
   - Reduces TTFB from 350ms to <15ms
   - Exclude dynamic pages (checkout, my-account)

2. **Consider Object Caching** (Recommended):
   - Redis or Memcached for database query caching
   - Helps cold page performance
   - Required for high-traffic sites

3. **Admin Optimization** (Optional):
   - Review active plugins on admin pages
   - Consider admin-only plugin deactivation

---

## Architecture Optimizations

### 1. Custom Tables (Not post_meta)

NetterTech Events uses custom database tables instead of WordPress `post_meta` for critical performance benefits:

**Tables (15 total):**
- `nte_events` - Event definitions
- `nte_occurrences` - Pre-computed occurrence instances
- `nte_ticket_types` - Ticket type definitions
- `nte_attendees` - Registration records
- `nte_tickets` - Individual ticket records
- `nte_categories` - Event category definitions
- `nte_event_categories` - Event-to-category junction
- `nte_tags` - Event tag definitions
- `nte_event_tags` - Event-to-tag junction
- `nte_organizers` - Organizer records
- `nte_event_organizers` - Event-to-organizer junction
- `nte_series` - Series groupings
- `nte_activity_log` - Administrative audit trail
- `nte_reminder_log` - Email reminder tracking
- `nte_deferred` - Deferred task queue

**Benefits:**
- Proper indexes on queried columns
- No serialized data requiring PHP processing
- Efficient JOINs vs N+1 meta queries
- BIGINT IDs for scalability

### 2. Denormalized Occurrences

Recurring events pre-compute occurrences at save time, not query time:

```
Event (RRULE: FREQ=WEEKLY;BYDAY=TU,TH;COUNT=52)
    ↓ OccurrenceGenerator (on save)
    ↓
52 Occurrence rows in nte_occurrences table
```

**Benefits:**
- Calendar queries are simple date range lookups
- No RRULE parsing at display time
- Predictable query performance regardless of recurrence complexity

### 3. Composite Database Indexes

Strategic indexes for common query patterns:

```sql
-- Calendar/list views (date range queries)
KEY calendar_query (start_datetime, end_datetime, status)

-- Event schedule lookups
KEY event_schedule (event_id, start_datetime)

-- Check-in queries
KEY checkin_query (occurrence_id, checked_in)
KEY occurrence_status (occurrence_id, status)

-- Ticket availability
KEY occurrence_status (occurrence_id, status)
KEY event_scope (event_id, scope)
```

---

## Caching Infrastructure

### Cache Groups

| Group | TTL | Purpose |
|-------|-----|---------|
| `nte` | varies | Main plugin cache group |
| Capacity data | 60s | Ticket availability (short TTL for checkout accuracy) |
| Occurrence queries | 300s | Calendar data |
| Event metadata | 3600s | Event details |
| Siblings cache | 3600s | Hourly granularity for navigation |
| Filtered queries | 3600s | Paginated list results |

### CacheManager (`includes/Core/CacheManager.php`)

Central cache invalidation:

```php
// TTL Constants
CacheManager::TTL_CAPACITY    = 60;    // 1 minute (checkout-sensitive)
CacheManager::TTL_OCCURRENCE  = 300;   // 5 minutes
CacheManager::TTL_EVENT       = 3600;  // 1 hour

// Cache group
CacheManager::CACHE_GROUP = 'nte';
```

### Invalidation Hooks

Cache automatically invalidates on:

| Action | Hook |
|--------|------|
| Event saved | `nte_after_save_event` |
| Event deleted | `nte_after_delete_event` |
| Occurrences generated | `nte_occurrences_generated` |
| Occurrence status change | `nte_occurrence_status_changed` |
| Attendee created | `nte_attendee_created` |
| Attendee cancelled | `nte_attendee_cancelled` |
| Capacity reserved | `nte_capacity_reserved` |
| Capacity released | `nte_capacity_released` |
| Buffer stock updated | `nte_buffer_stock_updated` |

### Object Cache Integration

Works with persistent object cache (Redis, Memcached):

```php
// Cache read
$cached = wp_cache_get( $cache_key, 'nte' );
if ( false !== $cached ) {
    return $cached;
}

// Cache write
wp_cache_set( $cache_key, $result, 'nte', HOUR_IN_SECONDS );

// Cache flush (group-aware)
wp_cache_flush_group( 'nte' );
```

### Transient Caching

Used for long-lived data and sites without persistent object cache:

```php
// Pending reservations (15-minute hold)
set_transient( 've_pending_capacity_' . $id, $data, 900 );

// Calendar data
set_transient( 'nte_calendar_' . $key, $data, 3600 );
```

### Versioned Key Invalidation

CacheManager uses version-stamped cache keys for bulk invalidation without
iterating individual entries:

```php
// Build a versioned key: "capacity_42_v3"
$key = CacheManager::versioned_key( 'capacity_' . $id );

// Bump version to invalidate all keys at once (v3 → v4)
CacheManager::bump_cache_version();
```

Version is stored in `wp_options` (`nte_cache_version`). A single
`bump_cache_version()` call stales every versioned key site-wide — no need
to enumerate or delete them individually.

### CapacityCalculator and Persistent Object Cache

`CapacityCalculator` uses `wp_cache_get()`/`wp_cache_set()` with a 60-second
TTL for ticket availability. **Without a persistent object cache (Redis or
Memcached), these calls are per-request only** — the cache is rebuilt on
every page load, providing no cross-request benefit.

For sites selling tickets, a persistent object cache is **strongly
recommended**:

| Setup | Capacity cache behavior |
|-------|------------------------|
| No persistent cache | Per-request only; every page load re-queries the database |
| Redis / Memcached | Cached across requests; 60s TTL prevents stale availability |

Install the [Redis Object Cache](https://wordpress.org/plugins/redis-cache/)
plugin or configure Memcached at the hosting level.

---

## Query Optimization Patterns

### 1. Identity Map Pattern

Repositories prevent duplicate queries within a request:

```php
class OccurrenceRepository {
    private array $identity_map = [];

    public function find( int $id ): ?Occurrence {
        // Check identity map first
        if ( isset( $this->identity_map[ $id ] ) ) {
            return $this->identity_map[ $id ];
        }

        // Query database
        $row = $this->db->get_row( /* ... */ );

        // Cache in identity map
        $occurrence = Occurrence::from_row( $row );
        $this->identity_map[ $occurrence->id ] = $occurrence;

        return $occurrence;
    }
}
```

### 2. Eager Loading (JOINs)

Single query for related data:

```php
// Bad: N+1 queries
foreach ( $occurrences as $occ ) {
    $event = $event_repo->find( $occ->event_id ); // Query per occurrence!
}

// Good: Single JOIN query
$sql = "SELECT o.*, e.title as event_title, e.slug as event_slug
        FROM {$this->table} o
        JOIN {$this->events_table} e ON o.event_id = e.id
        WHERE ...";
```

### 3. Prepared Statements

All queries use `$wpdb->prepare()`:

```php
$row = $this->db->get_row(
    $this->db->prepare(
        "SELECT * FROM {$this->table} WHERE id = %d",
        $id
    )
);
```

### 4. Pagination with LIMIT/OFFSET

Large result sets are paginated:

```php
$items_sql = "SELECT ...
              ORDER BY o.start_datetime ASC
              LIMIT %d OFFSET %d";
```

### 5. Count Queries Separate from Data

For pagination, count is cached separately:

```php
// Total count (can be cached longer)
$cache_key = 'filtered_count_' . crc32( wp_json_encode( $args ) );
$total = wp_cache_get( $cache_key, 'nte' );
if ( false === $total ) {
    $total = (int) $this->db->get_var( $count_sql );
    wp_cache_set( $cache_key, $total, 'nte', HOUR_IN_SECONDS );
}
```

---

## Frontend Performance

### Event List Shortcode

The `[nte_list]` shortcode uses:

1. **Cached queries** with hourly granularity for time-based filtering
2. **AJAX pagination** to avoid full page reloads
3. **Lazy loading** for images (native `loading="lazy"`)
4. **Minimal DOM** - renders only visible items

### JavaScript Assets

- **No jQuery dependency** for new frontend code
- **Deferred loading** for non-critical scripts
- **ES6 modules** with modern browser targets
- **Minified production builds** in `assets/dist/`

### Image Optimization

- Uses WordPress responsive images (`srcset`)
- `loading="lazy"` attribute on featured images
- Proper `width`/`height` attributes to prevent CLS

---

## Admin Performance

### List Tables

Admin list tables use:

- Pagination (default 20 items per page)
- Cached counts for tabs/filters
- Bulk operations to reduce round-trips

### Event Editor

- Lazy loading of metabox content
- AJAX for occurrence regeneration
- Debounced autosave

---

## Performance Testing

### Using Query Monitor

Install [Query Monitor](https://wordpress.org/plugins/query-monitor/) to identify:

- Slow queries (> 100ms)
- Duplicate queries (N+1 patterns)
- Total query count per page
- Memory usage

### Expected Query Counts

| Page | Target Queries | Notes |
|------|----------------|-------|
| Event list (12 items) | 5-10 | Single query + pagination |
| Single event | 10-15 | Event + occurrences + tickets |
| Calendar month | 5-10 | Date range query |
| Admin event list | 15-25 | List + counts + filters |
| Admin event editor | 20-40 | Full event data + metaboxes |
| Check-in page | 10-20 | Occurrence + attendees |

### Benchmarking Script

Test with WP-CLI:

```bash
# Time a page load
wp eval 'echo shell_exec("curl -s -o /dev/null -w \"%{time_total}\" https://example.com/events/");'

# Count queries for a shortcode
wp eval '
global $wpdb;
$start = $wpdb->num_queries;
do_shortcode("[nte_list limit=12]");
echo "Queries: " . ($wpdb->num_queries - $start);
'
```

### Browser DevTools

1. **Network tab**: Check TTFB, total transfer size
2. **Performance tab**: Record page load, identify bottlenecks
3. **Lighthouse**: Run performance audit

---

## Optimization Recommendations

### High Impact

1. **Enable persistent object cache** (Redis or Memcached)
   - Eliminates repeated database queries
   - Required for multi-server deployments
   - Recommended: Redis Object Cache plugin

2. **Enable page caching**
   - WP Super Cache, W3 Total Cache, or hosting-level
   - Exclude dynamic pages (checkout, my-account)

3. **Use CDN for static assets**
   - Images, CSS, JavaScript
   - Reduces server load and improves TTFB globally

### Medium Impact

4. **Database optimization**
   ```sql
   -- Periodic optimization
   OPTIMIZE TABLE wp_ve_occurrences;
   OPTIMIZE TABLE wp_ve_attendees;
   ```

5. **Index verification**
   ```sql
   -- Check index usage
   SHOW INDEX FROM wp_ve_occurrences;
   EXPLAIN SELECT * FROM wp_ve_occurrences WHERE start_datetime >= '2026-01-01';
   ```

6. **PHP OPcache**
   - Ensure opcache is enabled and properly configured
   - `opcache.memory_consumption=256` minimum

### Low Impact (Nice to Have)

7. **HTTP/2 or HTTP/3** for parallel asset loading
8. **Gzip/Brotli compression** for text responses
9. **Preload critical assets** via `<link rel="preload">`

---

## Monitoring

### Recommended Tools

| Tool | Purpose |
|------|---------|
| Query Monitor | Development query analysis |
| New Relic / Datadog | Production APM |
| Lighthouse CI | Automated performance testing |
| WebPageTest | Real-world performance testing |

### Key Metrics to Track

- **TTFB** (Time to First Byte)
- **LCP** (Largest Contentful Paint)
- **CLS** (Cumulative Layout Shift)
- **Total Blocking Time**
- **Database query count per page**
- **Memory usage per request**

---

## Troubleshooting

### Slow Page Loads

1. Check Query Monitor for slow/duplicate queries
2. Verify object cache is active: `wp cache type`
3. Check for N+1 query patterns in custom code
4. Review error logs for warnings/notices

### High Memory Usage

1. Check `WP_MEMORY_LIMIT` in wp-config.php
2. Look for large arrays being loaded
3. Verify occurrences aren't being regenerated on every request

### Cache Not Working

1. Verify persistent object cache is connected
2. Check cache invalidation hooks are firing
3. Use Query Monitor's cache panel to verify hits/misses

---

## Configuration

### wp-config.php Recommendations

```php
// Memory limits
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );

// Object cache (if using Redis)
define( 'WP_REDIS_HOST', '127.0.0.1' );
define( 'WP_REDIS_PORT', 6379 );
define( 'WP_REDIS_DATABASE', 0 );

// Debug (development only)
define( 'SAVEQUERIES', true ); // Enable for Query Monitor
```

### PHP Configuration

```ini
; OPcache settings
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0  ; Set to 1 in development

; Execution limits
max_execution_time=60
memory_limit=256M
```

---

## Changelog

### 1.0.0 (2026-02-05)
- Updated version to 1.0.0
- Expanded table list from 5 to 15 tables

### 0.9.0 (2026-01-12)
- Initial performance documentation
- Documented caching infrastructure
- Added query optimization patterns
- Created benchmarking guidelines
