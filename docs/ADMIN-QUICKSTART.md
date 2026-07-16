# NetterTech Events Quick-Start Guide

> **Audience:** Site administrators publishing their first event. No development experience required.

Get your first event published in under 5 minutes.

---

## Before You Start

**Requirements:**
- WordPress 6.5+ installed
- PHP 8.2+
- Administrator access to your site
- WooCommerce 8.5+ (only needed for paid tickets)

---

## First 5 Minutes

### 1. Activate the Plugin

1. Go to **Plugins → Installed Plugins**
2. Find "NetterTech Events" and click **Activate**
3. The **Events** menu appears in your admin sidebar

### 2. Configure Essential Settings

Go to **Events → Settings** and configure:

| Setting | Action |
|---------|--------|
| Timezone | Set to your venue's local timezone |
| Default Venue | If events share a location, enable and fill in venue details |
| Enable RSVP | Turn on for free event registration |
| Enable Tickets | Turn on if selling paid tickets (requires WooCommerce) |

Click **Save Changes**.

### 3. Create a Category (Optional)

Go to **Events → Categories**:
1. Enter a category name (e.g., "Concerts", "Workshops", "Classes")
2. Add a description
3. Click **Add New Category**

Categories help visitors filter events on your site.

---

## Create Your First Event

### Step 1: Basic Information

1. Go to **Events → Add New**
2. Enter the **event title** (e.g., "Summer Concert Series")
3. Write a **description** in the editor — what attendees need to know
4. Upload a **featured image** (displayed in listings and on the event page)

### Step 2: Date and Time

In the **Event Details** metabox:

1. Select **Start Date** and **Start Time**
2. Select **End Date** and **End Time**
3. For recurring events, click **Make Recurring**:
   - Choose frequency: Daily, Weekly, Monthly, Yearly
   - Select specific days (for weekly)
   - Set end condition: after X occurrences or by specific date

### Step 3: Venue

1. Enter **Venue Name**
2. Enter **Venue Address** (used for map links and directions)
3. Tip: If you enabled Default Venue in settings, these auto-fill

### Step 4: Category

1. In the **Categories** metabox (right sidebar), check relevant categories
2. Events can belong to multiple categories

### Step 5: Publish

Click **Publish** (or **Schedule** for future publishing).

Your event is now live at `yoursite.com/events/event-slug/`

---

## Set Up Ticketing

### Free Events (RSVP)

For events without payment:

1. Edit your event
2. In **Event Details**, find **Enable RSVP**
3. Check the box to enable
4. Set **Capacity** (leave blank for unlimited)
5. Update the event

Visitors can now RSVP through the form on the event page.

### Paid Events (WooCommerce Tickets)

For events with ticket sales:

1. Ensure WooCommerce is installed and active
2. Edit your event
3. Scroll to the **Ticket Types** metabox
4. Click **Add Ticket Type**
5. For each ticket type, enter:
   - **Name**: e.g., "General Admission", "VIP", "Student"
   - **Price**: e.g., 25.00
   - **Quantity Available**: e.g., 100
   - **Description** (optional): Benefits or restrictions
6. Repeat for additional ticket types
7. Update the event

WooCommerce products are created automatically. Customers purchase tickets through your normal checkout flow.

---

## Display Events on Your Site

### Shortcodes

Add these to any page or post:

**Event List** — Grid of upcoming events
```
[nte_list limit="12" layout="grid" show_filters="true"]
```

**Calendar** — Month/week/day views
```
[nte_calendar view="month"]
```

**Carousel** — Sliding display
```
[nte_carousel limit="6" columns="3"]
```

### Gutenberg Blocks

In the block editor:
1. Add a new block (+)
2. Search for "NetterTech Events"
3. Choose Event Calendar, Event List, or Event Carousel
4. Configure options in the block sidebar

### Beaver Builder Modules

In Beaver Builder:
1. Open the modules panel
2. Look under "NetterTech Events"
3. Drag Event Calendar, Event List, or Event Carousel to your layout

---

## Check-In at the Door

On event day:

1. Go to **Events → Check-In**
2. Select your event from the dropdown
3. For recurring events, select the specific date
4. Scan QR codes or search attendees by name
5. Click to mark guests as checked in
6. When done, click **Complete Check-In** for a summary report

**Tip:** Access check-in on a mobile device — the interface is touch-friendly.

---

## Next Steps

Now that you're running:

- **[Full Admin Manual](./ADMIN-MANUAL.md)** — Complete feature documentation
- **[Settings Reference](./ADMIN-MANUAL.md#settings)** — All configuration options explained
- **[Troubleshooting](./ADMIN-MANUAL.md#troubleshooting)** — Common issues and solutions

### Advanced Features

| Feature | Where to Learn More |
|---------|---------------------|
| Recurring event patterns | Admin Manual → Creating Events |
| Bulk event import from CSV | Admin Manual → CSV Import |
| Donation prompts at checkout | Admin Manual → Settings → Donations |
| Email notifications | Admin Manual → Settings → Email |
| QR code customization | Admin Manual → Settings → QR Codes |
| Check-in demographic counters | Admin Manual → Settings → Check-In |
| iCal import/export | REST API documentation |

---

## Quick Reference

| Task | Where |
|------|-------|
| Create event | Events → Add New |
| Edit event | Events → All Events → click event |
| Duplicate event | Events → All Events → hover → Duplicate |
| Import events from CSV | Events → CSV Import |
| Manage categories | Events → Categories |
| View activity log | Events → Activity Log |
| Configure settings | Events → Settings |
| Door check-in | Events → Check-In |
| Generate QR codes | Events → QR Generator |
| View attendees | Events → Attendees |

---

**Next step:** Create your first event at Events > Add New. See the [Admin Manual](ADMIN-MANUAL.md) for detailed configuration options.
