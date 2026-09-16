# NetterTech Events User Manual

> **Audience:** Site administrators running events on a WordPress site. Covers every admin screen, workflow, and setting. For a 5-minute intro, see [ADMIN-QUICKSTART.md](ADMIN-QUICKSTART.md) first.

## Getting Started

After activating the plugin, access it via **Events** (ticket icon) in the WordPress admin sidebar.

### Requirements
- WordPress 6.5+
- PHP 8.2+
- WooCommerce 8.5+ (optional, for paid ticketing)

---

## Admin Menu

| Menu Item | Purpose |
|-----------|---------|
| All Events | View, search, edit, duplicate, delete events |
| Add New | Create a new event |
| Categories | Manage event categories (hierarchical) |
| Attendees | View attendee records across all events |
| Check-In | Door check-in interface for attendees |
| QR Generator | Generate QR codes for event promotion |
| CSV Import | Import events from a CSV file |
| Activity Log | Audit trail of all administrative actions |
| Settings | Configure plugin options |

---

## How Events Are Stored

**Events are NOT WordPress posts.**

Unlike most WordPress plugins that store data as "posts" (like blog posts or pages), NetterTech Events uses dedicated database tables. This is a deliberate design choice for performance.

### What This Means for You

| What You Might Notice | Why It Works This Way |
|-----------------------|----------------------|
| Events don't appear in Posts → All Posts | Events have their own dedicated Events menu |
| Can't use post-related plugins on events | Event data is optimized for calendars, not blogs |
| Calendar pages load quickly | Custom tables = fewer database lookups |
| Search works differently | Events have their own search in the Events list, but are also discoverable via WordPress admin bar search and the Gutenberg link dialog (see below) |

### Shadow Posts: WordPress Search Integration

Although events live in custom tables, the plugin creates lightweight "shadow posts" behind the scenes to bridge the gap with WordPress native search. This means:

- **Gutenberg link dialog** — When editing a page or post, type an event name in the link search box and it will appear as a result with the correct event URL.
- **Admin bar search** — The WordPress admin bar "Search" field can find events by title.
- **Automatic sync** — Shadow posts are created and updated automatically when you save or delete events. No manual action is needed.

Shadow posts contain only the event title and URL — no content, no meta. They are not visible in Posts → All Posts or anywhere in the admin UI. They exist solely to make events findable through WordPress's built-in search infrastructure.

### Practical Implications

**Backups:** Your events are stored in database tables starting with `nte_` (events, occurrences, tickets, attendees). Standard WordPress database backups capture everything automatically. Shadow posts in the standard `wp_posts` table are also backed up automatically and can be regenerated at any time.

**Migrations:** When moving to a new host, export your full database — events transfer automatically with no extra steps.

**REST API:** The plugin provides its own API endpoints at `/wp-json/nettertech-events/v1/` rather than using WordPress's built-in post endpoints.

**Event Editor:** The event editor uses metaboxes (similar to WooCommerce products) rather than the Gutenberg block editor.

### Why This Approach?

Calendar queries are fundamentally different from blog queries. A typical WordPress page loads 10 posts. A calendar view might need to check hundreds of date ranges across recurring events with multiple occurrences.

Custom tables with proper database indexes make this fast — typically under 50 milliseconds even with thousands of events. The same queries using WordPress post meta would take 500ms or more.

---

## Creating Events

### Basic Event Information
1. Go to **Events → Add New**
2. Enter event title and description
3. Set date and time (start/end)
4. Add venue name and address
5. Upload a featured image

### Recurring Events

Set up repeating events with flexible patterns:

| Pattern | Example |
|---------|---------|
| Daily | Every day, every 3 days |
| Weekly | Every Monday and Wednesday |
| Monthly | 2nd Tuesday of each month, 15th of each month |
| Yearly | March 15 every year |

Configure:
- **Frequency**: How often (every 1, 2, 3... intervals)
- **Days**: Which days of the week (for weekly)
- **End**: After X occurrences or by specific date

### Event Categories

NetterTech Events uses its own built-in category system with dedicated database tables (not WordPress taxonomies). Manage categories via **Events → Categories**:

1. Create categories with names, optional descriptions, and parent categories (for hierarchy)
2. In the event editor, assign categories using the **Categories** checkbox metabox in the sidebar
3. Categories appear as filter options in frontend event lists and calendars

---

## Ticketing

### Free Events (RSVP)
1. Enable RSVP in event settings
2. Set capacity limit
3. Visitors register via the RSVP form

### Paid Events (WooCommerce)
1. Add ticket types in the event editor
2. Set name, price, and quantity for each type
3. Tickets appear as WooCommerce products automatically
4. Customers purchase through standard WooCommerce checkout

### Ticket Types
Create multiple ticket types per event:
- General Admission ($25)
- VIP ($50)
- Student ($15)

Each type has its own inventory and pricing.

### When Online Sales Close
Each ticket type has an optional sale window (**Sale starts** / **Sale ends**). Once every ticket type on a date has passed its sale end, the date's ticket form is replaced by the message **"Online ticket sales have closed."** (or **"Online RSVPs have closed."** for a free event). A date whose tickets have not gone on sale yet shows nothing, and a date in the past shows **"Past Event"**.

If your venue sells tickets at the door after online sales close, turn on **Door Sales → Tell visitors that tickets are still available at the door** on the space (Events → Spaces → edit). It is off by default. With it on, the closed-sales message adds **"Tickets are still available at the door until sold out."** for events held in that space. A sold-out date never offers door tickets, whatever the setting.

---

## Attendees

**Events → Attendees** lists everyone holding a seat — ticket buyers and RSVPs — across all events, or for one event when you arrive from an event's row (the "Purchases for:" view).

### Filters
- **Event** — one date of one event.
- **Status** — Confirmed, Pending, Cancelled, Refunded, Voided, or Failed orders (seats voided because the order never paid; hidden from the default view).
- **Data Quality** — real data only, or placeholder rows created by imports.
- **Accessibility** — *Has accessibility notes* / *No accessibility notes* (see below).
- **Search** — name or email.

### Accessibility notes
Attendees can tell you about accessibility needs when they buy a ticket (classic or block checkout) or submit an RSVP. The field is optional, capped at 1,000 characters, and carries the statement "Used only to arrange accommodations for this event." on every form.

In the list, an attendee who entered something shows a small **Accessibility notes** badge under their name. Click or press Enter on the badge to read the full note; nothing is shown for attendees who left it blank. Use the **Accessibility** filter to see only those attendees, and the CSV export (below) to hand the list to the venue or accessibility coordinator.

This is sensitive personal data, so the plugin treats it differently from a name or email: it never appears in confirmation or reminder emails or in Activity Log entries, and it is cleared automatically 30 days after the event ends (see [GDPR Privacy Tools](#gdpr-privacy-tools)).

### Exporting
**Export Selected** (bulk action) and **Export All** (respects the current filters) download a CSV with: ID, Name, Email, Event, Date/Time, Ticket Type, Quantity, Status, Checked In, Notes, Accessibility Notes, followed by one column per custom attendee field.

---

## Displaying Events

### Shortcodes

**Carousel** — Sliding display of upcoming events
```
[nettertech_events_carousel limit="6" columns="3"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| limit | 6 | Number of events |
| columns | 3 | Visible cards (1-4) |
| autoplay | false | Auto-advance |
| interval | 5000 | Autoplay speed (ms) |

**Event List** — Filterable grid or list
```
[nettertech_events_list limit="12" layout="grid" show_filters="true"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| limit | 12 | Events per page |
| columns | 3 | Grid columns |
| layout | grid | `grid`, `list`, or `cards` |
| show_filters | true | Category/search filters |
| category | | Pre-filter by category ID |

**Calendar** — Month/week/day views
```
[nettertech_events_calendar view="month"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| view | month | `month`, `week`, `day`, `list` |
| show_navigation | true | Prev/next buttons |
| show_view_toggle | true | View switcher |

**RSVP Form** — Registration for free events
```
[nettertech_events_rsvp event_id="123"]
```

### Beaver Builder Modules

Three modules available in the Beaver Builder editor:
- **Event Carousel** — Same options as shortcode
- **Event List** — Grid with filtering
- **Event Calendar** — Calendar views

---

## Check-In System

### Setup
1. Go to **Events → Check-In**
2. Select the event/occurrence from the dropdown
3. View attendee list sorted by last name

### Checking In Attendees
- Click the attendee row to expand per-person checkboxes
- Check a box for each person as they arrive
- For parties: individual checkboxes support partial check-in
- Progress shows checked-in vs. total expected

### Demographic Counters
Optional counters for tracking demographics (configured in Settings):
1. Enable counters in **Settings → Check-In**
2. Add labels (e.g., "Youth", "Senior", "Member")
3. Increment counters during check-in
4. Totals included in completion report

### Completion Report
When check-in is complete:
1. Click "Complete Check-In"
2. Summary email sent to configured address
3. Includes attendance totals and counter values

---

## Settings

Access settings via **Events → Settings** in the WordPress admin.

### General

Configure default display options for your events.

| Setting | Description | Default |
|---------|-------------|---------|
| Default Calendar View | Starting view for calendar shortcode and blocks | Month |
| Events Per Page | Default pagination limit for event lists | 12 |
| Timezone | Timezone for all event times; affects how dates display to visitors | Site timezone |

**Tip:** Match your timezone to your venue's physical location so attendees see correct local times.

### Default Venue

Pre-populate venue information for faster event creation.

| Setting | Description |
|---------|-------------|
| Enable Default Venue | Toggle to auto-fill venue fields when creating events |
| Venue Name | Default name (e.g., "Main Hall" or your venue's name) |
| Venue Address | Default street address for map links and directions |

**Use case:** If all your events happen at one location, enable this to save time during event creation.

### URL Settings

Customize your event URL structure. **Changes require permalink flush.**

| Setting | Description | Default |
|---------|-------------|---------|
| Events Base Path | URL prefix for all event pages | `events` |
| Events Archive Path | URL for the events archive/listing page | (empty = same as base) |

**Examples:**
- Base path `events` → `yoursite.com/events/event-slug/`
- Base path `calendar` → `yoursite.com/calendar/event-slug/`

**Important:** After changing URL settings, WordPress automatically flushes rewrite rules. If you experience 404 errors, go to **Settings → Permalinks** and click Save Changes.

### Event Page Layout

Drag-and-drop interface to reorder components on single event pages.

**Available components:**
- Featured Image
- Event Details (date, time, venue)
- Description
- Ticket Types / RSVP Form
- Related Events
- Share Buttons

Toggle visibility with checkboxes. Order determines display sequence on the frontend.

### Features

Enable or disable major plugin functionality.

| Setting | Description | Default |
|---------|-------------|---------|
| Enable RSVP | Allow free event registrations without payment | On |
| Enable Tickets | WooCommerce-powered paid ticket sales | On (requires WC) |
| Show End Times | Display event end times in listings and calendars | On |
| Show Event Time in Lists | Display start time in event archive views | On |

**Note:** Disabling RSVP or Tickets affects existing events. Attendees who already registered remain in the system.

### Tickets & Capacity

Configure default behavior for ticket sales.

| Setting | Description | Default |
|---------|-------------|---------|
| Minimum Per Order | Minimum tickets a customer can purchase | 1 |
| Maximum Per Order | Maximum tickets in a single order | 10 |
| Low Stock Threshold | Show "Low Stock" badge when tickets drop below this number | 5 |

**Low stock display:** When available tickets fall below the threshold, event listings show "Only X left!" to create urgency.

### Waitlist

Control whether visitors can join a waitlist when a ticket type or RSVP sells out.

| Setting | Description | Default |
|---------|-------------|---------|
| Enable Waitlist | Site-wide default. When on, sold-out ticket types and full RSVP events show a "Join the waitlist" form and accept sign-ups. | On |

**Per-event override:** every event's editor has a **Waitlist** box (below Reminder Emails) with three choices — *Use site default (On/Off)*, *On — offer a waitlist for this event*, *Off — no waitlist for this event*. The box always shows what the current site default is, and switching back to *Use site default* returns the event to inheriting. When the waitlist is off for an event, the join form is hidden **and** direct join requests are refused.

### Donations

Add optional donation prompts during WooCommerce checkout.

| Setting | Description | Default |
|---------|-------------|---------|
| Enable Donations | Show donation option at checkout | Off |
| Cause/Message | Text explaining what donations support | (empty) |
| Round-Up To | Round-up option: nearest $1, $5, or $10 | $1 |
| Preset Amounts | Quick-select buttons (comma-separated) | 5, 10, 25 |
| Allow Custom Amount | Let customers enter any donation amount | On |
| Maximum Donation | Cap on single donation | $100 |

**Example cause:** "Support our arts education programs for local youth."

### QR Codes

Customize the appearance of QR codes on tickets and check-in passes.

| Setting | Description | Default |
|---------|-------------|---------|
| Foreground Color | QR code module color | #000000 (black) |
| Background Color | QR code background | #FFFFFF (white) |
| Scale | Size multiplier for generated QR codes | 4 |
| Logo Option | None, Icon (calendar), or Custom (upload) | None |

**Logo recommendations:**
- Use simple, high-contrast logos
- Square images work best
- Logo is centered in QR code using high error correction (H level)
- Test scanning after adding logos to ensure readability

**Preview:** The settings page shows a live preview of your QR code configuration.

### Check-In

Configure the door check-in experience.

| Setting | Description | Default |
|---------|-------------|---------|
| Demographic Counters | Comma-separated labels for manual tallies | (empty) |
| Completion Email | Email address to receive check-in completion reports | (empty) |

**Demographic counters example:** `Youth, Senior, Member, Accessibility`

These appear as increment buttons on the check-in screen. Staff can track demographic data that isn't captured by ticket sales (walk-ups, comped attendees, etc.).

**Completion report:** When check-in is marked complete, an email summary is sent including:
- Total attendees checked in
- No-shows
- Counter totals
- Timestamp and event details

### Email

Configure email notifications for staff and customers.

| Setting | Description | Default |
|---------|-------------|---------|
| Disable Customer Emails | Suppress confirmation emails to ticket buyers | Off |
| Exclude QR Codes | Don't include QR codes in confirmation emails | Off |
| Notification Emails | Comma-separated addresses for RSVP/order alerts | (empty) |
| Cancellation Policy | Text included in confirmation emails (HTML allowed) | (empty) |

**Staff notifications:** Enter email addresses (comma-separated) to receive instant notifications when customers RSVP or purchase tickets.

**Cancellation policy example:**
```html
<p><strong>Cancellation Policy:</strong> Full refunds available up to 48 hours before the event.
Contact us at <a href="mailto:info@venue.com">info@venue.com</a>.</p>
```

### Advanced

**⚠️ Caution:** These settings affect system performance and behavior. Only modify if you understand the implications.

| Setting | Description | Default | Range |
|---------|-------------|---------|-------|
| Occurrence Horizon | Days ahead to pre-generate recurring event instances | 365 | 30–730 |
| API Rate Limit | Maximum REST API requests per time window | 60 requests | 10–1000 |
| Rate Limit Window | Time window for rate limiting | 60 seconds | 10–3600 |
| Cart Hold Time | How long ticket capacity is reserved while items are in cart | 900 seconds (15 min) | 60–3600 |
| Category Cache | How long to cache category dropdowns | 3600 seconds (1 hr) | 60–86400 |
| Delete Data on Uninstall | **DESTRUCTIVE:** Remove all plugin data when uninstalling | Off | — |

**Occurrence Horizon:** Affects how far into the future recurring events appear. A 365-day horizon means a weekly event shows ~52 upcoming occurrences.

**Cart Hold Time:** When a customer adds tickets to their cart, that capacity is reserved for this duration. Shorter times reduce phantom inventory; longer times give customers more checkout time.

**Delete Data on Uninstall:**
> ⚠️ **Warning:** Enabling this permanently deletes all events, ticket types, attendees, and settings when you deactivate and delete the plugin. This cannot be undone. Only enable if you're completely removing the plugin and don't need any historical data.

---

## CSV Import

Import events in bulk from a spreadsheet via **Events → CSV Import**.

### Preparing Your CSV

The import expects a standard CSV file with a header row. Required columns:

| Column | Description |
|--------|-------------|
| `title` | Event title |
| `start_date` | Start date in `YYYY-MM-DD` format |
| `start_time` | Start time in `HH:MM` (24-hour) format |

Optional columns (unrecognized columns are ignored):

| Column | Description |
|--------|-------------|
| `end_date` | End date in `YYYY-MM-DD` format |
| `end_time` | End time in `HH:MM` format |
| `description` | Event description (plain text or HTML) |
| `venue_name` | Venue name |
| `venue_address` | Venue address |
| `category` | Category name (must already exist in Events → Categories) |

### Running an Import

1. Go to **Events → CSV Import**
2. Click **Choose File** and select your `.csv` file
3. Review the column mapping — the importer auto-detects common column names
4. Click **Import** to begin
5. A summary displays when the import completes, showing how many events were created and any rows that were skipped

### Notes

- Rows with missing required fields (`title`, `start_date`) are skipped and reported in the import summary
- Categories must already exist before importing; unmatched category names are ignored
- Large files (hundreds of rows) may take several seconds; do not navigate away during import
- Imported events are created as draft-status events; publish them via Events → All Events when ready

---

## Event Duplication

Quickly create similar events:
1. Go to **Events → All Events**
2. Hover over an event row
3. Click **Duplicate**
4. New event opens for editing with all settings copied

Duplication includes:
- Event details and description
- Recurrence rules
- Ticket types and pricing
- Featured image

---

## Series Pages

For recurring events with tickets, automatic series pages show:
- Event overview and description
- List of upcoming occurrences
- Quick links to purchase tickets for each date

Access via: `yoursite.com/events/event-slug/`

Individual occurrences: `yoursite.com/events/event-slug/2025-01-15-1900/`

---

## Event Reminders

NetterTech Events can automatically send reminder emails to attendees before events.

### How It Works
- A WordPress cron job checks daily for events occurring the next day
- Attendees with tickets or RSVPs receive an email with event details, date, time, and venue
- Each reminder is logged in the `nte_reminder_log` table to prevent duplicates

### Configuration
Reminders are sent automatically — no configuration required. The email template can be customized by copying `nettertech-events/templates/emails/event-reminder.php` to your theme (see [Template Override Guide](./TEMPLATE-OVERRIDE.md)).

---

## Activity Log

The Activity Log provides an audit trail of all administrative actions.

### What Is Logged
- Event creation, updates, and deletion
- Occurrence generation and status changes
- Attendee management (creation, check-in, deletion)
- Ticket type changes
- Settings modifications
- Data exports

### Viewing the Log
Go to **Events → Activity Log** to browse entries. Each entry shows:
- Date and time
- Action type
- Object affected
- User who performed the action
- Additional details

### Retention
Activity log entries are retained for 90 days by default. This can be adjusted via the `nettertech_events_activity_log_retention_days` filter (see [Hooks Reference](./HOOKS.md)).

---

## GDPR Privacy Tools

NetterTech Events integrates with WordPress's built-in privacy tools for GDPR compliance.

### Personal Data Export
When a user requests their data via **Tools → Export Personal Data**, attendee records (name, email, ticket details, check-in history) are included in the export.

### Personal Data Erasure
When a user requests erasure via **Tools → Erase Personal Data**, their attendee records are anonymized or removed.

### Accessibility Notes Retention
Accessibility requirements entered at checkout or on the RSVP form are collected for one purpose — arranging accommodations at the event — so they are not kept indefinitely. A daily job clears the note (the attendee record itself stays) once the event date has been over for 30 days. Adjust the window with the `nettertech_events_accessibility_notes_retention_days` filter (see [Hooks Reference](./HOOKS.md)); return `0` to clear notes as soon as the event ends. Notes are also included in personal-data exports and removed by personal-data erasure.

---

## SEO Integration

NetterTech Events automatically integrates with **Yoast SEO** and **Rank Math** when either is active. No configuration needed.

### What Happens Automatically

- **Schema markup**: Rich Event structured data appears in the SEO plugin's JSON-LD output
- **No duplicates**: NTE's built-in schema and Open Graph tags are disabled when the SEO plugin handles them
- **Sitemaps**: Events are registered with Yoast's sitemap system (or remain in WP Core Sitemaps for Rank Math)
- **Breadcrumbs**: Event pages show Home > Events > [Category] > Event Title

### Custom SEO Variables

Use these in your SEO title/description templates:

| Variable (Yoast) | Variable (Rank Math) | Output |
|-------------------|---------------------|--------|
| `%%nte_event_date%%` | `%nte_event_date%` | Formatted occurrence date |
| `%%nte_event_venue%%` | `%nte_event_venue%` | Venue name |
| `%%nte_event_organizer%%` | `%nte_event_organizer%` | Site name |

For complete documentation, see [SEO-INTEGRATION.md](SEO-INTEGRATION.md).

---

## REST API

For developers integrating with external systems:

| Endpoint | Description |
|----------|-------------|
| `/wp-json/nettertech-events/v1/events/upcoming` | Upcoming events |
| `/wp-json/nettertech-events/v1/events/range?start_date=X&end_date=Y` | Events in date range |
| `/wp-json/nettertech-events/v1/events/{id}` | Single event details |

---

## Troubleshooting

### Events not displaying

**Symptom:** Events don't appear on frontend pages

**Solutions:**
1. Verify shortcode syntax (check for typos, matching brackets)
2. Check that events have future dates (past events don't show by default)
3. Confirm events are published (not draft or private)
4. Clear any caching plugins (WP Super Cache, W3 Total Cache, etc.)
5. Check browser console for JavaScript errors
6. Verify the correct category filter isn't excluding events

### Tickets not appearing in WooCommerce

**Symptom:** Ticket products don't appear or can't be purchased

**Solutions:**
1. Ensure WooCommerce is installed and active
2. Verify **Settings → Features → Enable Tickets** is on
3. Confirm ticket types are added to the event:
   - Edit the event
   - Scroll to "Ticket Types" metabox
   - Add at least one ticket type with name, price, and quantity
4. Check WooCommerce → Status for any database issues
5. Verify WooCommerce guest checkout settings if customers report login issues

### Recurring occurrences missing

**Symptom:** Weekly/monthly recurring events only show a few future dates

**Solutions:**
1. Check **Settings → Advanced → Occurrence Horizon** (default: 365 days)
2. Re-save the event to regenerate occurrences:
   - Edit the event
   - Click "Update" without making changes
   - Occurrences regenerate automatically
3. Verify recurrence rule syntax (weekly events need day selections)
4. Check for valid end condition (end date or occurrence count)

### Check-in not showing attendees

**Symptom:** Check-in page shows no attendees for an event

**Solutions:**
1. Confirm orders are marked **Completed** in WooCommerce (not Processing or On-Hold)
2. Verify you selected the correct **occurrence** in the dropdown (recurring events have multiple)
3. For RSVP events: confirm RSVPs were submitted successfully
4. Check that attendees weren't manually deleted
5. Verify the user has `manage_options` (Administrator). Core v1.0.0 does not yet publish granular capabilities — see [SECURITY.md](SECURITY.md#capabilities-and-roles).

### Capacity and inventory issues

**Symptom:** Tickets show as sold out when they shouldn't, or overselling occurs

**Solutions:**

**Phantom sold-out:**
1. Check **Settings → Advanced → Cart Hold Time** — tickets in abandoned carts are reserved
2. View WooCommerce → Orders for pending/on-hold orders tying up inventory
3. Abandoned carts automatically release after the hold time expires

**Overselling:**
1. Verify ticket type has capacity set (0 = unlimited)
2. Check for concurrent purchases during high-traffic sales
3. Review order status — only Completed orders reduce inventory permanently

**Inventory sync:**
1. Edit the event and verify ticket counts in the Ticket Types metabox
2. Compare with WooCommerce product inventory (Settings → Inventory)
3. Plugin syncs inventory bi-directionally; manual WC edits are preserved

### Email delivery issues

**Symptom:** Customers don't receive confirmation emails

**Solutions:**

**Check plugin settings:**
1. Verify **Settings → Email → Disable Customer Emails** is OFF
2. Check **Settings → Email → Notification Emails** for staff alerts

**Check WordPress email:**
1. Install a test plugin like WP Mail SMTP to verify WordPress can send email
2. Check wp-content/debug.log for mail errors
3. Verify hosting provider's email limits aren't exceeded

**Check spam folders:**
1. Have customers check spam/junk folders
2. Confirmation emails come from WordPress default sender
3. Consider using a transactional email service (SendGrid, Mailgun)

**QR codes missing:**
1. Verify **Settings → Email → Exclude QR Codes** is OFF
2. Check server has GD library installed (required for QR generation)
3. Verify adequate PHP memory_limit (128MB+ recommended)

### Check-in access and permissions

**Symptom:** Staff can't access the check-in page or scan tickets

**Solutions:**

**Admin access:**
1. User needs `manage_options` (Administrator). Core v1.0.0 does not publish granular check-in capabilities — see [SECURITY.md](SECURITY.md#capabilities-and-roles) for the planned model.
2. If QR / volunteer check-in is required, install the NetterTech Events Pro add-on; Pro adds the public token-authenticated check-in URL and the volunteer-role capability set.
3. Verify the user isn't blocked by security plugins.

**QR scanning issues:**
1. Ensure check-in page is accessed via HTTPS (camera requires secure context)
2. Grant browser permission to access camera when prompted
3. Use adequate lighting — QR codes need contrast to scan
4. Hold device steady; some phones need a moment to focus
5. If camera fails, enter ticket code manually

**Check-in token expired:**
1. Tokens are valid for 24 hours by default
2. Generate a new check-in link from the event editor
3. Bookmarked check-in URLs may expire

### WooCommerce integration issues

**Symptom:** Tickets don't sync, orders fail, or capacity doesn't update

**Solutions:**

**Plugin conflicts:**
1. Disable other WooCommerce extensions temporarily to test
2. Check for JavaScript errors in browser console
3. Verify WooCommerce HPOS (High-Performance Order Storage) compatibility

**Order status issues:**
1. Attendees are created when orders reach **Completed** status
2. If using payment gateways with delayed completion, attendees appear after payment confirms
3. Manual orders: remember to mark as Completed

**Product sync:**
1. Ticket products are created automatically when you add ticket types
2. Don't manually delete WooCommerce products linked to events
3. If products are missing, re-save the event to regenerate

**Capacity discrepancies:**
1. Plugin reserves capacity when items are added to cart
2. Capacity releases when cart expires or order is cancelled
3. Refunded orders restore capacity automatically

### Performance issues

**Symptom:** Pages load slowly or timeout on event-heavy sites

**Solutions:**

1. **Reduce occurrence horizon:** Lower from 365 to 180 days if events don't need long-term visibility
2. **Enable object caching:** Use Redis or Memcached for faster query results
3. **Check query count:** Use Query Monitor plugin to identify slow queries
4. **Review category cache TTL:** Increase in Settings → Advanced if categories rarely change
5. **Optimize images:** Large featured images slow page loads; use appropriate sizes

### Import/Export issues

**Symptom:** iCal imports fail or exports are incomplete

**Solutions:**

**Import failures:**
1. Verify iCal file is valid (test with Google Calendar import)
2. Check file size — very large files may timeout
3. Ensure user has `manage_options` capability for import
4. Review server error logs for PHP errors

**Export issues:**
1. Clear caching before testing export
2. Verify event has valid dates (required for iCal)
3. Test exported .ics file in a calendar app

### Can't find events in WordPress Posts menu

**Symptom:** Looking for events under Posts → All Posts or trying to use a posts-related plugin with events

**Explanation:** This is expected behavior. NetterTech Events uses custom database tables instead of WordPress posts for performance reasons. See [How Events Are Stored](#how-events-are-stored) above.

**Solutions:**
1. Access events via **Events → All Events** in the admin menu
2. Events have their own REST API under `/wp-json/nettertech-events/v1/`
3. For backups, your standard WordPress database backup includes all event tables (`nte_*`)
4. Plugins that work with "posts" won't work with events — use the plugin's built-in features or hooks instead

---

### Getting more help

If troubleshooting doesn't resolve your issue:

1. **Check error logs:** wp-content/debug.log (enable WP_DEBUG first)
2. **Capture steps to reproduce:** Note exactly what you clicked and when the error occurs
3. **Note your environment:** WordPress version, PHP version, WooCommerce version
4. **Contact support:** Share the above information with your site administrator or developer

---

## Keyboard Shortcuts

### Carousel
| Key | Action |
|-----|--------|
| ← | Previous slide |
| → | Next slide |

### Calendar
| Key | Action |
|-----|--------|
| ← | Previous period |
| → | Next period |

---

## Known Limitations

### Waitlist success message is not theme-overridable

When a visitor joins the waitlist, the confirmation message ("You are #3 on the waitlist.") is set by JavaScript at runtime using a string passed from PHP via `wp_localize_script`. This means:

- Copying the `waitlist-panel.php` template to your theme will not change this string.
- The string is translatable via standard `.po`/`.mo` translation files (text domain: `nettertech-events`).
- To change the wording without a translation, use a translation override plugin (such as Loco Translate) or filter `gettext` for the specific string.

There is no PHP filter on this string. Customizing it requires either translation tools or a JavaScript override of `veWaitlist.i18n.position` after the script loads.

---

## Related Documentation

| Document | Description |
|----------|-------------|
| [Quick-Start Guide](./ADMIN-QUICKSTART.md) | Get started in 5 minutes |
| [Hooks Reference](./HOOKS.md) | Actions and filters for developers |
| [Template Override Guide](./TEMPLATE-OVERRIDE.md) | Customize templates in your theme |
| [API Reference](./openapi.yaml) | REST API documentation (OpenAPI 3.0) |
| [Performance Guide](./PERFORMANCE.md) | Caching and optimization |

## Support

For issues or feature requests, contact your site administrator or developer.
