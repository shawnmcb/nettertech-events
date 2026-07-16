# SEO Plugin Integration

> **Audience:** Theme developers and site administrators configuring Yoast SEO or Rank Math on sites running NetterTech Events. Explains the Schema.org data the plugin emits and the integration points both SEO plugins expose.

NetterTech Events integrates with **Yoast SEO** and **Rank Math** to provide rich structured data, sitemaps, breadcrumbs, and custom SEO variables for your events.

---

## What Happens Automatically

When either Yoast SEO or Rank Math is active, the following features activate without any configuration:

| Feature | What It Does |
|---------|-------------|
| **Schema.org Event markup** | Injects full Event structured data into the SEO plugin's JSON-LD output (name, dates, venue, status, attendance mode, image, organizer) |
| **Schema deduplication** | Disables NTE's built-in JSON-LD output so there's only one Event schema block per page |
| **Open Graph deduplication** | Disables NTE's built-in OG tags when the SEO plugin handles them |
| **Sitemap registration** (Yoast) | Registers NTE events as a custom sitemap type in Yoast's sitemap index |
| **Breadcrumbs** | Replaces default breadcrumbs on event pages with: Home > Events > [Category] > Event Title |

No settings page. No toggles. If the SEO plugin is active, the integration works.

---

## Supported Plugins

| Plugin | Schema | Sitemap | Variables | Breadcrumbs |
|--------|--------|---------|-----------|-------------|
| **Yoast SEO** (free & premium) | Yes | Yes (dedicated sitemap) | Yes | Yes |
| **Rank Math** (free & pro) | Yes | Via WP Core Sitemaps | Yes | Yes |
| **Neither installed** | NTE's built-in JSON-LD + OG tags | WP Core Sitemaps | N/A | N/A |

---

## Schema Markup

On event pages, the SEO plugin's structured data output will include a full `Event` schema piece with:

- **name** - Event title
- **description** - Event excerpt or description
- **startDate** / **endDate** - ISO 8601 with timezone (from the current occurrence)
- **eventStatus** - Scheduled, Cancelled, or Rescheduled
- **eventAttendanceMode** - Offline, Online, or Mixed
- **location** - Place (physical), VirtualLocation, or both
- **image** - Featured image (occurrence-level, falling back to event-level)
- **organizer** - Site name and URL
- **url** - Canonical URL for the event or occurrence

### How It Works

- **Yoast**: Filters `wpseo_schema_graph` to add an Event piece linked to Yoast's WebPage via `mainEntityOfPage`
- **Rank Math**: Filters `rank_math/json_ld` to add an `Event` key to the JSON-LD data array

NTE's built-in `SchemaMarkup` class is automatically disabled via the `nte_schema_org_data` filter when either plugin is detected.

### Validating

After installation, use [Google's Rich Results Test](https://search.google.com/test/rich-results) to verify your event pages produce valid Event schema.

---

## Sitemaps

### Yoast SEO

NTE registers a dedicated sitemap provider with Yoast. Your events appear in Yoast's sitemap index as `nte-events-sitemap.xml`.

The sitemap includes:

- All published event URLs (`/events/{slug}/`)
- All occurrence URLs for recurring events (`/events/{slug}/{YYYY-MM-DD-HHMM}/`)
- `lastmod` dates based on the event's `updated_at` timestamp
- Automatic pagination for large event catalogs

**To verify:** Visit `{your-site}/nte-events-sitemap.xml`

### Rank Math

Rank Math replaces WordPress Core Sitemaps with its own sitemap system. NTE events remain available through the WordPress Core Sitemaps at `/wp-sitemap.xml`. Both sitemap systems can coexist - search engines will discover events through whichever sitemap they crawl.

---

## Custom SEO Variables

Both plugins support custom replacement variables that you can use in SEO title and meta description templates.

| Variable | Yoast Format | Rank Math Format | Output |
|----------|-------------|-----------------|--------|
| Event date | `%%nte_event_date%%` | `%nte_event_date%` | Formatted date of the current occurrence |
| Venue name | `%%nte_event_venue%%` | `%nte_event_venue%` | Venue name from the event |
| Organizer | `%%nte_event_organizer%%` | `%nte_event_organizer%` | Site name |

### Example Usage

In Yoast's SEO title template for event pages:

```
%%nte_event_venue%% - %%title%% | %%nte_event_date%%
```

Would produce: `The Grand Hall - Summer Concert | June 15, 2026`

### Notes

- Variables return an empty string when not on an event page (safe to use in global templates)
- The date variable uses the current occurrence's date if viewing an occurrence page
- The organizer variable defaults to your site name

---

## Breadcrumbs

On event pages, the SEO plugin's breadcrumbs are replaced with an event-aware hierarchy:

```
Home > Events > Event Title
```

With optional category and occurrence levels:

```
Home > Events > Music > Summer Concert > June 15, 2026
```

### Structure

| Crumb | When Shown | Links To |
|-------|-----------|----------|
| Home | Always | Site homepage |
| Events | Always | Events listing page (`/events/`) |
| Category | If the event has a category assigned | Category archive |
| Event Title | Always | Event page (linked when viewing an occurrence) |
| Occurrence Date | Only on occurrence pages | Current page (no link) |

### Requirements

- **Yoast**: Breadcrumbs must be enabled in Yoast settings (SEO > Search Appearance > Breadcrumbs)
- **Rank Math**: Breadcrumbs must be enabled in Rank Math settings (General Settings > Breadcrumbs)
- Your theme must call the SEO plugin's breadcrumb function in its templates

---

## What If Both Plugins Are Installed?

Both integrations register their hooks during plugin initialization. Only the active SEO plugin's hooks will fire (each callback checks for its plugin's version constant before executing). If somehow both are active, both will inject schema - deactivate the one you're not using.

---

## Troubleshooting

| Issue | Solution |
|-------|---------|
| Duplicate JSON-LD on event pages | Check that only one SEO plugin is active. NTE disables its own schema when Yoast or Rank Math is detected. |
| Events missing from Yoast sitemap | Verify events are published (draft events are excluded). Visit `/nte-events-sitemap.xml` directly. |
| Custom variables showing as empty | Variables only populate on event pages. Check that the page is routed through NTE's event router. |
| Breadcrumbs not showing event hierarchy | Ensure the SEO plugin's breadcrumbs are enabled and your theme calls the breadcrumb function. |
| Schema validation errors | Run [Rich Results Test](https://search.google.com/test/rich-results). Common fix: ensure events have a start date (add an occurrence). |

---

## Developer Reference

### Hooks Used

| Hook | Plugin | Purpose |
|------|--------|---------|
| `wpseo_schema_graph` | Yoast | Inject Event schema piece |
| `wpseo_sitemaps_providers` | Yoast | Register sitemap provider |
| `wpseo_register_extra_replacements` | Yoast | Register custom variables |
| `wpseo_breadcrumb_links` | Yoast | Modify breadcrumb trail |
| `nte_schema_org_data` | NTE | Disable built-in schema |
| `rank_math/json_ld` | Rank Math | Inject Event schema |
| `rank_math/vars/register_extra_replacements` | Rank Math | Register custom variables |
| `rank_math/frontend/breadcrumb/html` | Rank Math | Modify breadcrumb HTML |

### Files

| File | Purpose |
|------|---------|
| `includes/Integrations/Yoast/YoastIntegration.php` | Yoast schema, variables, breadcrumbs |
| `includes/Integrations/Yoast/YoastSitemapProvider.php` | Yoast sitemap provider |
| `includes/Integrations/RankMath/RankMathIntegration.php` | Rank Math schema, variables, breadcrumbs |

### Adding Support for Other SEO Plugins

Follow the pattern in `YoastIntegration.php`:

1. Create a class in `includes/Integrations/{PluginName}/`
2. Add an `init()` method that registers hooks
3. Check for the plugin's version constant in each callback
4. Filter `nte_schema_org_data` to disable NTE's built-in schema
5. Wire into `Plugin::init_integrations()`

---

**Last Updated:** 2026-04-03
