# NetterTech Events Plugin - Comprehensive Test Plan

> **Audience:** Plugin developers designing or executing test coverage. Complements the automated test suite (`tests/Unit`, `tests/Integration`, `tests/E2E`) with a narrative map of what gets tested and why.

## Overview

This test plan covers all aspects of the NetterTech Events plugin using available agentic tools and manual verification. Tests are organized by category with specific test cases, expected outcomes, and tooling.

**Test Environment**:
- Local by Flywheel (WordPress 6.8.x, PHP 8.2+)
- WooCommerce (latest)
- Beaver Builder Pro (latest)
- Chrome DevTools MCP (browser automation)

---

## Table of Contents

1. [Installation & Activation](#1-installation--activation)
2. [Database & Schema](#2-database--schema)
3. [Admin Interface](#3-admin-interface)
4. [Event CRUD Operations](#4-event-crud-operations)
5. [Recurrence System](#5-recurrence-system)
6. [Frontend Shortcodes](#6-frontend-shortcodes)
7. [REST API](#7-rest-api)
8. [WooCommerce Integration](#8-woocommerce-integration)
9. [Check-In System](#9-check-in-system)
10. [Beaver Builder Integration](#10-beaver-builder-integration)
11. [Performance](#11-performance)
12. [Security](#12-security)
13. [Accessibility](#13-accessibility)
14. [Browser Compatibility](#14-browser-compatibility)
15. [Edge Cases & Error Handling](#15-edge-cases--error-handling)

---

## 1. Installation & Activation

### 1.1 Clean Installation

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Plugin activates | Activate plugin on fresh WP install | No errors, admin menu appears | WP-CLI / Browser |
| Tables created | Check database after activation | All 21 `nte_*` tables exist | `wp db query` |
| No PHP notices | Check error log after activation | Empty or no VE-related notices | `wp eval` |
| Assets registered | Load frontend page | No 404s for VE assets | Chrome DevTools |

### 1.2 Activation with Dependencies

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| WooCommerce active | Activate with WC installed | WC integration hooks registered | Browser |
| WooCommerce missing | Activate without WC | Graceful degradation, no errors | Browser |
| Beaver Builder active | Activate with BB | BB modules available | BB Editor |

### 1.3 Deactivation & Reactivation

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Deactivate preserves data | Deactivate, check tables | Tables and data intact | `wp db query` |
| Reactivate | Reactivate after deactivation | Works normally, no duplicates | Browser |

**Automation Script**:
```bash
# Test activation/deactivation cycle
wp plugin deactivate nettertech-events
wp plugin activate nettertech-events
wp db query "SHOW TABLES LIKE '%nte_%'"
```

---

## 2. Database & Schema

### 2.1 Table Structure

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| nte_events table | `DESCRIBE wp_nte_events` | All columns per schema | `wp db query` |
| nte_occurrences table | `DESCRIBE wp_nte_occurrences` | Includes indexes | `wp db query` |
| nte_ticket_types table | `DESCRIBE wp_nte_ticket_types` | Includes `sold_count`, `stock_status` | `wp db query` |
| nte_attendees table | `DESCRIBE wp_nte_attendees` | Includes `party_size`, `guests_checked_in` | `wp db query` |
| Foreign keys | `SHOW CREATE TABLE` | FK constraints present | `wp db query` |

### 2.2 Indexes

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Calendar query index | `SHOW INDEX FROM wp_nte_occurrences` | `calendar_query` composite index exists | `wp db query` |
| Event schedule index | Check occurrences indexes | `event_schedule` index exists | `wp db query` |

### 2.3 Schema Migrations

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Version tracking | Check options table | `nte_db_version` exists | `wp option get` |
| Migration on init | Update plugin, load page | Schema updates applied | Browser + DB check |

---

## 3. Admin Interface

### 3.1 Menu Structure

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Main menu | Log in as admin | "Events" menu with calendar icon | Chrome DevTools |
| Submenus present | Expand Events menu | All Events, Add New, Categories, Attendees, Check-In, QR Generator, Activity Log, Settings | Chrome DevTools |
| Capability check | Log in as subscriber | Events menu not visible | Chrome DevTools |

### 3.2 Events List Table

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| List displays | Navigate to Events | Table with columns: Title, Type, Date, Status | Chrome DevTools |
| Sorting | Click column headers | Sorts correctly | Chrome DevTools |
| Search | Enter search term | Filters results | Chrome DevTools |
| Pagination | Add 25+ events, navigate | Pagination works | Chrome DevTools |
| Row actions | Hover over event row | Edit, Duplicate, Delete links appear | Chrome DevTools |

### 3.3 Event Editor

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Editor loads | Click Add New | Form with all fields loads | Chrome DevTools |
| Required fields | Submit empty form | Validation errors shown | Chrome DevTools |
| Date picker | Click date field | Calendar picker opens | Chrome DevTools |
| Time picker | Click time field | Time selector works | Chrome DevTools |
| Rich text editor | Description field | TinyMCE or block editor | Chrome DevTools |
| Featured image | Click image area | Media library opens | Chrome DevTools |
| Save draft | Save without publishing | Status = draft | Chrome DevTools |
| Publish | Click Publish | Status = published, success message | Chrome DevTools |

### 3.4 Settings Page

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Settings load | Navigate to Settings | All sections visible | Chrome DevTools |
| Save settings | Change values, save | Success message, values persist | Chrome DevTools |
| Donation settings | Configure donations | Options saved correctly | Chrome DevTools |
| Check-in counters | Add counter labels | Labels saved as array | Chrome DevTools |

### 3.5 Category Management

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Categories page loads | Navigate to Events → Categories | Category editor visible | Chrome DevTools |
| Create category | Add name, save | Category created in nte_categories | Chrome DevTools |
| Hierarchy support | Create child category | Parent-child relationship saved | Chrome DevTools |
| Category in editor | Edit event | Category checkboxes in sidebar | Chrome DevTools |
| Assign categories | Check categories, save | Junction records in nte_event_categories | Chrome DevTools |

### 3.6 Activity Log

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Activity log page | Navigate to Events → Activity Log | Log entries visible | Chrome DevTools |
| Event action logged | Create/edit/delete event | Entry appears in log | Chrome DevTools |
| User recorded | Check log entry | Current user ID stored | Chrome DevTools |
| Filtering | Filter by action type | Log filters correctly | Chrome DevTools |

---

## 4. Event CRUD Operations

### 4.1 Create Event

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Single event | Create with title, date, time | Event saved, visible in list | Chrome DevTools |
| With venue | Add venue name and address | Venue info saved | Chrome DevTools |
| With category | Assign category | Category association saved | Chrome DevTools |
| With image | Upload featured image | Image displays in list/frontend | Chrome DevTools |

### 4.2 Read Event

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| View in admin | Click event in list | Edit form populated correctly | Chrome DevTools |
| View on frontend | Navigate to series page | Event details displayed | Chrome DevTools |

### 4.3 Update Event

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Edit title | Change title, save | New title in list and frontend | Chrome DevTools |
| Edit datetime | Change date/time | Occurrences regenerated | Chrome DevTools + DB |
| Change status | Set to cancelled | Status updated, display changes | Chrome DevTools |

### 4.4 Delete Event

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Delete event | Click Delete, confirm | Event removed from list | Chrome DevTools |
| Cascade delete | Check occurrences | Related occurrences deleted | `wp db query` |
| Nonce verification | Attempt without nonce | Security error | Bash (curl) |

### 4.5 Duplicate Event

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Duplicate event | Click Duplicate | New event created, edit form opens | Chrome DevTools |
| Ticket types copied | Check new event | Ticket types duplicated | Chrome DevTools |
| Recurrence copied | Check new event | Recurrence rules duplicated | Chrome DevTools |

---

## 5. Recurrence System

### 5.1 Daily Recurrence

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Every day | Set daily, no end | Occurrences for next 365 days | `wp db query` |
| Every N days | Set every 3 days | Correct spacing | `wp db query` |
| With end date | Set end date | Stops at end date | `wp db query` |
| With count | Set after 10 occurrences | Exactly 10 occurrences | `wp db query` |

### 5.2 Weekly Recurrence

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Every week | Set weekly, one day | Weekly occurrences | `wp db query` |
| Multiple days | Mon, Wed, Fri | 3 occurrences per week | `wp db query` |
| Every N weeks | Every 2 weeks | Biweekly pattern | `wp db query` |

### 5.3 Monthly Recurrence

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Day of month | 15th of each month | Falls on 15th | `wp db query` |
| Nth weekday | 2nd Tuesday | Correct calculation | `wp db query` |
| Last weekday | Last Friday | End-of-month calculation | `wp db query` |

### 5.4 Yearly Recurrence

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Same date | March 15 yearly | Annual occurrences | `wp db query` |

### 5.5 Recurrence Edge Cases

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Feb 29 | Yearly on Feb 29 | Handles leap years | `wp db query` |
| DST transition | Occurrence during DST | Time preserved correctly | `wp db query` |
| Edit recurrence | Change rule | Old occurrences removed, new generated | `wp db query` |

---

## 6. Frontend Shortcodes

### 6.1 Carousel Shortcode

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Basic render | `[nettertech_events_carousel]` | Carousel with events | Chrome DevTools |
| Limit attribute | `limit="3"` | Shows 3 events | Chrome DevTools |
| Columns attribute | `columns="4"` | 4-column layout | Chrome DevTools |
| Empty state | No upcoming events | Empty message displayed | Chrome DevTools |
| Navigation | Click prev/next | Slides change | Chrome DevTools |
| Keyboard nav | Arrow keys | Carousel navigates | Chrome DevTools |
| Autoplay | `autoplay="true"` | Auto-advances | Chrome DevTools |

### 6.2 Event List Shortcode

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Basic render | `[nettertech_events_list]` | Grid of events | Chrome DevTools |
| Grid layout | `layout="grid"` | Grid display | Chrome DevTools |
| List layout | `layout="list"` | List display | Chrome DevTools |
| Cards layout | `layout="cards"` | Cards grid | Chrome DevTools |
| Filters visible | `show_filters="true"` | Search and category filter | Chrome DevTools |
| Category filter | Select category | Filters via AJAX | Chrome DevTools |
| Search | Enter search term | Results filter | Chrome DevTools |
| Pagination | Navigate pages | Page changes via AJAX | Chrome DevTools |

### 6.3 Calendar Shortcode

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Month view | `[nettertech_events_calendar view="month"]` | Month calendar | Chrome DevTools |
| Week view | `view="week"` | Week view | Chrome DevTools |
| Day view | `view="day"` | Day view | Chrome DevTools |
| View toggle | Click view buttons | View switches | Chrome DevTools |
| Navigation | Click prev/next | Month/week changes | Chrome DevTools |
| Event click | Click event | Event details shown | Chrome DevTools |

### 6.4 RSVP Shortcode

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Form render | `[nettertech_events_rsvp event_id="1"]` | RSVP form displayed | Chrome DevTools |
| Submit RSVP | Fill form, submit | Success message, attendee created | Chrome DevTools |
| Party size | Select party size | Party size saved | Chrome DevTools |
| Validation | Submit invalid email | Error message | Chrome DevTools |

### 6.5 Conditional Asset Loading

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Page with shortcode | Load page with carousel | VE CSS/JS loaded | Chrome DevTools Network |
| Page without shortcode | Load regular page | No VE assets | Chrome DevTools Network |

---

## 7. REST API

### 7.1 Events Endpoints

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| GET /events/upcoming | `curl /wp-json/nettertech-events/v1/events/upcoming` | JSON array of events | Bash |
| Limit parameter | `?limit=5` | Returns 5 events | Bash |
| GET /events/range | `?start_date=X&end_date=Y` | Events in range | Bash |
| GET /events/{id} | Valid ID | Single event with occurrences | Bash |
| Invalid ID | Non-existent ID | 404 response | Bash |

### 7.2 Occurrences Endpoint

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| GET /occurrences | Request endpoint | Paginated occurrences | Bash |
| Search parameter | `?search=concert` | Filtered results | Bash |
| Category filter | `?category=5` | Category-filtered results | Bash |

### 7.3 Check-In Endpoint

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| POST /check-in | Valid ticket code | Success, status updated | Bash |
| Invalid code | Wrong ticket code | Error response | Bash |
| Already checked in | Resubmit same code | Error: already checked in | Bash |
| Authentication | Without auth | 401 unauthorized | Bash |

### 7.4 Response Times

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| GET response time | Measure /events/upcoming | < 200ms | Bash (time curl) |
| Large dataset | 1000+ events | < 500ms | Bash |

---

## 8. WooCommerce Integration

### 8.1 Product Creation

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Auto product | Add ticket type to event | WC product created | WC Admin |
| Product attributes | Check product | Price, SKU, stock correct | WC Admin |
| Hidden product | Check catalog | Product not in shop | Chrome DevTools |

### 8.2 Cart Flow

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Add to cart | Click Buy Tickets | Ticket in cart | Chrome DevTools |
| Cart validation | Exceed capacity | Error, blocked | Chrome DevTools |
| Multiple tickets | Add 3 of same type | Quantity correct | Chrome DevTools |
| Mixed cart | Add regular + ticket products | Both in cart | Chrome DevTools |

### 8.3 Checkout Flow

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Complete checkout | Purchase ticket | Order created | Chrome DevTools |
| Attendee created | Check after order | Attendee record exists | `wp db query` |
| Ticket created | Check after order | Ticket with code exists | `wp db query` |
| Stock decremented | Check ticket type | `sold_count` increased | `wp db query` |
| Email sent | Complete order | Confirmation email received | Email |

### 8.4 Donations

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Round-up display | Enable donations, go to checkout | Round-up option shown | Chrome DevTools |
| Preset amounts | Check donation UI | Preset buttons displayed | Chrome DevTools |
| Custom amount | Enter custom donation | Amount added to cart | Chrome DevTools |
| Donation in order | Complete with donation | Donation line item in order | WC Admin |

### 8.5 Refunds

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Full refund | Refund entire order | Ticket cancelled, stock restored | WC Admin + DB |
| Partial refund | Refund one ticket | Correct ticket cancelled | WC Admin + DB |

---

## 9. Check-In System

### 9.1 Check-In Page

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Page loads | Navigate to Check-In | Occurrence selector visible | Chrome DevTools |
| Select occurrence | Choose from dropdown | Attendee list loads | Chrome DevTools |
| Attendee list | View list | Shows name, email, party size | Chrome DevTools |
| Sort by name | Default sort | Sorted by last name | Chrome DevTools |

### 9.2 Individual Check-In

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Check in single | Click attendee row | Expand per-person checkboxes | Chrome DevTools |
| Check one person | Click checkbox | Person marked checked in | Chrome DevTools |
| Partial check-in | Check 2 of 4 in a party | Shows 2/4 checked in | Chrome DevTools |
| Full check-in | Check all in a party | Row marked complete | Chrome DevTools |

### 9.3 Demographic Counters

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Counters visible | Configure in settings | Counter buttons displayed | Chrome DevTools |
| Increment counter | Click counter button | Count increases | Chrome DevTools |
| Persist across refresh | Reload page | Counts preserved | Chrome DevTools |

### 9.4 Completion

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Complete button | Click Complete Check-In | Confirmation dialog | Chrome DevTools |
| Email sent | Complete with email configured | Report email received | Email |
| Report contents | Check email | Includes totals, counters | Email |

---

### 9.5 Event Reminders

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Reminder cron scheduled | Check cron schedule | `nte_send_reminders` scheduled | WP-CLI |
| Reminder sent | Create event tomorrow, run cron | Email sent to attendee | Email |
| No duplicate reminders | Run cron twice | Entry in nte_reminder_log, no second email | `wp db query` |
| Template override | Copy template to theme | Theme template used | Browser + Logs |

---

## 10. Beaver Builder Integration

### 10.1 Module Availability

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Modules listed | Open BB editor | VE modules in panel | Chrome DevTools |
| Carousel module | Drag to page | Module settings appear | Chrome DevTools |
| List module | Drag to page | Module settings appear | Chrome DevTools |
| Calendar module | Drag to page | Module settings appear | Chrome DevTools |

### 10.2 Module Settings

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Limit setting | Change limit | Preview updates | Chrome DevTools |
| Layout setting | Change layout | Preview updates | Chrome DevTools |
| Style settings | Modify colors | Styles apply | Chrome DevTools |

### 10.3 Live Preview

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Live preview | Make changes | Real-time update | Chrome DevTools |
| Save page | Publish page | Settings persist | Chrome DevTools |
| Frontend match | View published page | Matches preview | Chrome DevTools |

---

## 11. Performance

### 11.1 Page Load Times

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Homepage with carousel | Load page with 10 events | < 2s TTFB | Chrome DevTools |
| Event list (50 events) | Load list page | < 2s | Chrome DevTools |
| Calendar month view | Load with 30 events | < 2s | Chrome DevTools |
| Admin event list | Load with 100 events | < 2s | Chrome DevTools |
| Check-in page | Load with 500 attendees | < 2s | Chrome DevTools |

### 11.2 Database Query Counts

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Frontend carousel | Query Monitor | < 30 queries | Query Monitor |
| Frontend list | Query Monitor | < 40 queries | Query Monitor |
| Single event | Query Monitor | < 25 queries | Query Monitor |
| Admin list | Query Monitor | < 60 queries | Query Monitor |

### 11.3 Anti-Pattern Detection

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| N+1 queries | Check "Queries by Component" | No loops detected | Query Monitor |
| Duplicate queries | Check duplicates | 0 duplicates | Query Monitor |
| Slow queries | Check > 100ms | 0 slow queries | Query Monitor |
| Global assets | Load non-VE page | No VE assets loaded | Chrome DevTools Network |

### 11.4 REST API Performance

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| GET /events/upcoming | Measure response | < 200ms | `time curl` |
| GET with large dataset | 1000+ events | < 500ms | `time curl` |

### 11.5 Memory Usage

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Frontend page | Check memory | < 64MB | Query Monitor |
| Admin page | Check memory | < 64MB | Query Monitor |

---

## 12. Security

### 12.1 SQL Injection

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Search input | Enter `'; DROP TABLE--` | Escaped, no error | Chrome DevTools |
| URL parameters | Inject in event_id | Sanitized | Bash (curl) |
| REST parameters | Inject in query params | Sanitized | Bash (curl) |

### 12.2 XSS Prevention

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Event title | Save `<script>alert(1)</script>` | Escaped on output | Chrome DevTools |
| Venue name | Save XSS payload | Escaped on output | Chrome DevTools |
| Search display | Search with XSS | Escaped in results | Chrome DevTools |

### 12.3 CSRF Protection

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Event save | Submit without nonce | Rejected | Bash (curl) |
| Event delete | Delete without nonce | Rejected | Bash (curl) |
| Settings save | Save without nonce | Rejected | Bash (curl) |

### 12.4 Capability Checks

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Subscriber access | Log in as subscriber | Admin pages inaccessible | Chrome DevTools |
| Editor access | Log in as editor | Limited access | Chrome DevTools |
| REST write ops | POST without auth | 401 unauthorized | Bash (curl) |

### 12.5 Data Validation

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Invalid email | Submit RSVP with bad email | Validation error | Chrome DevTools |
| Negative price | Set ticket price to -10 | Rejected or 0 | Chrome DevTools |
| Future-past dates | End before start | Validation error | Chrome DevTools |

---

## 13. Accessibility

### 13.1 WCAG 2.2 AA Compliance

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Carousel a11y | Run audit | No violations | `/accessibility-audit` |
| Event list a11y | Run audit | No violations | `/accessibility-audit` |
| Calendar a11y | Run audit | No violations | `/accessibility-audit` |
| Admin pages a11y | Run audit | No violations | `/accessibility-audit` |

### 13.2 Keyboard Navigation

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Carousel | Tab + Arrow keys | Full navigation | Chrome DevTools |
| Calendar | Tab + Arrow keys | Date selection works | Chrome DevTools |
| Event list | Tab through filters | All controls reachable | Chrome DevTools |
| Forms | Tab through fields | Logical order | Chrome DevTools |

### 13.3 Screen Reader

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Carousel announcements | Navigate slides | Slide changes announced | VoiceOver |
| Form labels | Focus form fields | Labels read correctly | VoiceOver |
| Error messages | Trigger validation | Errors announced | VoiceOver |

### 13.4 Visual

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Color contrast | Check text/background | 4.5:1 ratio minimum | `/wcag-check` |
| Focus indicators | Tab through controls | Visible focus ring | Chrome DevTools |
| Reduced motion | Enable preference | Animations disabled | Chrome DevTools |

---

## 14. Browser Compatibility

### 14.1 Desktop Browsers

| Browser | Version | Test Areas |
|---------|---------|------------|
| Chrome | Latest | All features |
| Firefox | Latest | All features |
| Safari | Latest | All features, date pickers |
| Edge | Latest | All features |

### 14.2 Mobile Browsers

| Browser | Version | Test Areas |
|---------|---------|------------|
| Safari iOS | Latest | Touch, responsive |
| Chrome Android | Latest | Touch, responsive |

### 14.3 Responsive Design

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Mobile carousel | 375px width | Single column, touch swipe | Chrome DevTools |
| Tablet grid | 768px width | 2 columns | Chrome DevTools |
| Desktop grid | 1200px width | 3-4 columns | Chrome DevTools |
| Admin mobile | Mobile view | Usable interface | Chrome DevTools |

---

## 15. Edge Cases & Error Handling

### 15.1 Empty States

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| No events | Delete all events | Empty message, not error | Chrome DevTools |
| No upcoming | All events in past | "No upcoming events" | Chrome DevTools |
| No attendees | View check-in, no orders | Empty state message | Chrome DevTools |

### 15.2 Large Data Sets

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| 1000 events | Create via script | No timeout, pagination works | WP-CLI + Browser |
| 5000 occurrences | Yearly + daily events | Queries still fast | Query Monitor |
| 500 attendees | Bulk import | Check-in page responsive | Chrome DevTools |

### 15.3 Concurrent Operations

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Simultaneous checkout | Two users buy last ticket | One succeeds, one fails gracefully | Two browsers |
| Edit during checkout | Admin edits while cart active | No data corruption | Two browsers |

### 15.4 Network Failures

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| AJAX timeout | Throttle network, filter | Error message, retry option | Chrome DevTools |
| Lost connection | Go offline mid-action | Graceful error | Chrome DevTools |

### 15.5 Invalid Data

| Test | Steps | Expected | Tool |
|------|-------|----------|------|
| Corrupted recurrence | Invalid RRULE in DB | No fatal error, logged | Browser + Logs |
| Missing FK | Delete event, keep occurrence | Graceful handling | `wp db query` |
| Invalid JSON | Bad category_ids JSON | Default to empty array | Browser |

---

## Test Execution Commands

### Browser Automation (Chrome DevTools MCP)

```
# Navigate and take snapshot
mcp__chrome-devtools-isolated__navigate_page url="http://nettertech-events-dev.local/wp-admin/"
mcp__chrome-devtools-isolated__take_snapshot

# Fill login form
mcp__chrome-devtools-isolated__fill_form elements=[{"uid":"user_login","value":"admin"},{"uid":"user_pass","value":"password"}]
mcp__chrome-devtools-isolated__click uid="wp-submit"

# Check for console errors
mcp__chrome-devtools-isolated__list_console_messages types=["error"]

# Take screenshot
mcp__chrome-devtools-isolated__take_screenshot filePath="./test-screenshots/admin-dashboard.png"

# Check network for asset loading
mcp__chrome-devtools-isolated__list_network_requests resourceTypes=["script","stylesheet"]
```

### Database Verification

```bash
# Check table structure
wp db query "DESCRIBE wp_nte_events"
wp db query "DESCRIBE wp_nte_occurrences"
wp db query "SHOW INDEX FROM wp_nte_occurrences"

# Count records
wp db query "SELECT COUNT(*) FROM wp_nte_events"
wp db query "SELECT COUNT(*) FROM wp_nte_occurrences"

# Check recurrence generation
wp db query "SELECT event_id, COUNT(*) as occ_count FROM wp_nte_occurrences GROUP BY event_id"
```

### REST API Testing

```bash
# Test endpoints
curl -s http://nettertech-events-dev.local/wp-json/nettertech-events/v1/events/upcoming | jq
curl -s "http://nettertech-events-dev.local/wp-json/nettertech-events/v1/events/range?start_date=2025-01-01&end_date=2025-12-31" | jq

# Measure response time
time curl -s http://nettertech-events-dev.local/wp-json/nettertech-events/v1/events/upcoming > /dev/null
```

### Accessibility Auditing

```
# Run accessibility audit on page
/accessibility-audit

# Check WCAG compliance
/wcag-check
```

---

## Test Data Setup

### Create Test Events

```bash
# Single event
wp eval '
$repo = new NetterTechEvents\Repositories\EventRepository();
$event = new NetterTechEvents\Models\Event();
$event->title = "Test Concert";
$event->status = "published";
$event->event_type = "single";
$repo->save($event);
'

# Recurring event (weekly)
wp eval '
$repo = new NetterTechEvents\Repositories\EventRepository();
$event = new NetterTechEvents\Models\Event();
$event->title = "Weekly Meetup";
$event->status = "published";
$event->event_type = "recurring";
$event->recurrence_rule = "FREQ=WEEKLY;BYDAY=TU";
$repo->save($event);
'
```

### Create Test Orders

```bash
# Create WooCommerce test order with ticket
wp eval '
// Programmatic order creation for testing
'
```

---

## Reporting

### Test Results Template

```markdown
## Test Run: [DATE]

### Summary
- Total Tests: X
- Passed: X
- Failed: X
- Skipped: X

### Failures
| Test | Expected | Actual | Screenshot |
|------|----------|--------|------------|
| ... | ... | ... | link |

### Performance Metrics
| Page | TTFB | Queries | Memory |
|------|------|---------|--------|
| ... | ... | ... | ... |

### Notes
- ...
```

---

## Continuous Integration Hooks

For automated testing on commit:

1. **PHP Syntax**: `php -l` on all PHP files
2. **PHPStan**: Level 6 analysis
3. **Schema Check**: Verify all tables exist
4. **Asset Check**: Verify no 404s for registered assets
5. **REST Smoke Test**: Basic endpoint availability

---

*Last updated: February 2026*
