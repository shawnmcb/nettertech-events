# NetterTech Events Hook Reference

> **Audience:** Integrators and add-on developers extending NetterTech Events.

**Last Updated:** 2026-07-16

Complete reference for the actions and filters in NetterTech Events. All hooks use the `nettertech_events_` prefix and follow WordPress naming conventions.

This document has two layers: detailed guides for the most commonly used hooks (the sections below), and a [Complete Hook Index](#complete-hook-index) covering every hook the plugin fires. Every entry in the index cites the source location it fires from, and a structural test keeps the index in sync with the code: a hook cannot ship undocumented.

> **Authoritative names:** `includes/Core/Hooks.php` defines hook names as class constants grouped by domain. Subscribe using the constant where possible; the string values are stable and never change once published.

---

## Quick Reference

| Type | Documented Here | Total fired in codebase |
|------|----------------:|------------------------:|
| Actions (`do_action`) | 36 | 74 |
| Filters (`apply_filters`) | 27 | 43 |
| AJAX | 1 | 1 |

*Codebase counts measured by grepping `do_action\(\s*Hooks::…\)` and `apply_filters\(\s*Hooks::…\)` across `includes/`. Totals count unique hook names. Some hooks defined in `Hooks.php` are Pro-plugin extension points and are only fired when the Pro plugin is active.*

Hook categories in `Hooks.php`: Plugin Lifecycle, Data Lifecycle (Events/Occurrences and Attendees/Tickets), Email & Notification, Waitlist, Cache, Capacity, Activity Log, Template & Display Filters, Calendar, Shortcode Display, Single Occurrence, Settings, Security & Rate Limiting, Frontend Branding, Ticket Admin Extensibility, Cron, REST API Response Filters, Bulk Import, Extension Hooks (Pro Plugin Integration), Email Extension Hooks.

---

## Actions

### Plugin Lifecycle

#### `nettertech_events_init`

Fired after the plugin is fully initialized. Safe to hook into all plugin functionality.

**Parameters:**
- `$plugin` (Plugin) — The main plugin instance

**Example:**
```php
add_action( 'nettertech_events_init', function( $plugin ) {
    // Plugin is ready, register custom integrations
    error_log( 'NetterTech Events initialized' );
}, 10, 1 );
```

**Location:** `includes/Core/Plugin.php:115`

---

#### `nettertech_events_activated`

Fired when the plugin is activated. Use for one-time setup tasks.

**Parameters:** None

**Example:**
```php
add_action( 'nettertech_events_activated', function() {
    // Create custom database tables or options
    update_option( 'my_integration_setup_complete', true );
} );
```

**Location:** `includes/Core/Activator.php:52`

---

#### `nettertech_events_deactivated`

Fired when the plugin is deactivated. Use for cleanup tasks.

**Parameters:** None

**Example:**
```php
add_action( 'nettertech_events_deactivated', function() {
    // Clean up scheduled events
    wp_clear_scheduled_hook( 'my_custom_cron' );
} );
```

**Location:** `includes/Core/Deactivator.php:32`

---

### Event Operations

#### `nettertech_events_before_save_event`

Fired before an event is saved to the database.

**Parameters:**
- `$event` (Event) — The event being saved
- `$data` (array) — The data being saved

**Example:**
```php
add_action( 'nettertech_events_before_save_event', function( $event, $data ) {
    // Validate custom fields
    if ( empty( $data['custom_field'] ) ) {
        throw new Exception( 'Custom field is required' );
    }
}, 10, 2 );
```

**Location:** `includes/Repositories/EventRepository.php:434`

---

#### `nettertech_events_after_save_event`

Fired after an event is saved to the database.

**Parameters:**
- `$event` (Event) — The saved event

**Built-in listener:** `ShadowPostSyncService::sync()` — Automatically creates or updates a shadow post (`nettertech_event` post type) mirroring the event's title, slug, and status. This enables WordPress admin bar search and Gutenberg link dialog discovery.

**Example:**
```php
add_action( 'nettertech_events_after_save_event', function( $event ) {
    // Sync to external calendar
    sync_to_google_calendar( $event );

    // Notify team
    wp_mail( 'team@venue.com', 'Event Updated', $event->get_title() );
}, 10, 1 );
```

**Location:** `includes/Repositories/EventRepository.php:472`

---

#### `nettertech_events_before_delete_event`

Fired before an event is deleted.

**Parameters:**
- `$event` (Event) — The event being deleted

**Example:**
```php
add_action( 'nettertech_events_before_delete_event', function( $event ) {
    // Archive to external system before deletion
    archive_event_to_external_system( $event );
}, 10, 1 );
```

**Location:** `includes/Repositories/EventRepository.php:499`

---

#### `nettertech_events_after_delete_event`

Fired after an event is deleted.

**Parameters:**
- `$id` (int) — The ID of the deleted event
- `$event` (Event) — The event that was deleted (snapshot before deletion)

**Built-in listener:** `ShadowPostSyncService::on_delete()` — Automatically deletes the corresponding shadow post when an event is removed.

**Example:**
```php
add_action( 'nettertech_events_after_delete_event', function( $id, $event ) {
    // Clean up external integrations
    remove_from_google_calendar( $id );
}, 10, 2 );
```

**Location:** `includes/Repositories/EventRepository.php:519`

---

#### `nettertech_events_before_duplicate_event`

Fired before an event is duplicated.

**Parameters:**
- `$duplicate` (Event) — The new duplicate event (not yet saved)
- `$source` (Event) — The original event being duplicated

**Example:**
```php
add_action( 'nettertech_events_before_duplicate_event', function( $duplicate, $source ) {
    // Modify duplicate before saving
    $duplicate->set_title( $source->get_title() . ' (Copy)' );
}, 10, 2 );
```

**Location:** `includes/Repositories/EventRepository.php:601`

---

#### `nettertech_events_after_duplicate_event`

Fired after an event is duplicated and saved.

**Parameters:**
- `$saved` (Event) — The saved duplicate event
- `$source` (Event) — The original event

**Example:**
```php
add_action( 'nettertech_events_after_duplicate_event', function( $saved, $source ) {
    // Copy custom meta that isn't handled by default
    $custom_meta = get_post_meta( $source->get_id(), 'custom_meta', true );
    update_post_meta( $saved->get_id(), 'custom_meta', $custom_meta );
}, 10, 2 );
```

**Location:** `includes/Repositories/EventRepository.php:616`

---

### Occurrence Operations

#### `nettertech_events_occurrences_generated`

Fired after occurrences are generated for a recurring event.

**Parameters:**
- `$event` (Event) — The event
- `$occurrences` (array) — Array of generated Occurrence objects
- `$rule` (RecurrenceRule) — The recurrence rule used

**Example:**
```php
add_action( 'nettertech_events_occurrences_generated', function( $event, $occurrences, $rule ) {
    // Log occurrence generation
    error_log( sprintf(
        'Generated %d occurrences for event %s',
        count( $occurrences ),
        $event->get_title()
    ) );
}, 10, 3 );
```

**Location:** `includes/Services/RecurrenceService.php:148`

---

#### `nettertech_events_templates_applied`

Fired after occurrence templates are applied (for capacity inheritance).

**Parameters:**
- `$event` (Event) — The event
- `$occurrences` (array) — Array of Occurrence objects
- `$created` (int) — Number of ticket types created

**Location:** `includes/Services/RecurrenceService.php:467`

---

#### `nettertech_events_occurrence_status_changed`

Fired when an occurrence status changes.

**Parameters:**
- `$id` (int) — Occurrence ID
- `$status` (string) — New status
- `$old_status` (string) — Previous status

**Example:**
```php
add_action( 'nettertech_events_occurrence_status_changed', function( $id, $status, $old_status ) {
    if ( $status === 'cancelled' ) {
        // Notify attendees of cancellation
        notify_attendees_of_cancellation( $id );
    }
}, 10, 3 );
```

**Location:** `includes/Repositories/OccurrenceRepository.php:748`

---

### Capacity Operations

#### `nettertech_events_capacity_reserved`

Fired when ticket capacity is reserved (e.g., added to cart).

**Parameters:**
- `$ticket_type_id` (int) — Ticket type ID
- `$quantity` (int) — Number of tickets reserved

**Example:**
```php
add_action( 'nettertech_events_capacity_reserved', function( $ticket_type_id, $quantity ) {
    // Track cart analytics
    analytics_track( 'capacity_reserved', [
        'ticket_type_id' => $ticket_type_id,
        'quantity' => $quantity,
    ] );
}, 10, 2 );
```

**Location:** `includes/Services/CapacityService.php:117`

---

#### `nettertech_events_capacity_released`

Fired when reserved capacity is released (e.g., cart expired).

**Parameters:**
- `$ticket_type_id` (int) — Ticket type ID
- `$quantity` (int) — Number of tickets released

**Location:** `includes/Services/CapacityService.php:155`

---

#### `nettertech_events_buffer_stock_updated`

Fired when buffer stock for a ticket type is updated.

**Parameters:**
- `$ticket_type_id` (int) — Ticket type ID
- `$amount` (int) — New buffer amount

**Location:** `includes/Services/CapacityCalculator.php:202`

---

#### `nettertech_events_capacity_oversell_detected`

Fired when an oversell situation is detected during order processing.

**Parameters:**
- `$order` (WC_Order) — The WooCommerce order
- `$issues` (array) — Array of oversell issues detected

**Example:**
```php
add_action( 'nettertech_events_capacity_oversell_detected', function( $order, $issues ) {
    // Alert admin of oversell
    wp_mail(
        get_option( 'admin_email' ),
        'Oversell Alert',
        'Order ' . $order->get_id() . ' may have oversold tickets.'
    );
}, 10, 2 );
```

**Location:** `includes/Integrations/WooCommerce/OrderCapacityValidator.php:189`

---

### RSVP Operations

#### `nettertech_events_rsvp_submitted`

Fired after a successful RSVP submission.

**Parameters:**
- `$attendee` (Attendee) — The created attendee record

**Example:**
```php
add_action( 'nettertech_events_rsvp_submitted', function( $attendee ) {
    // Add to mailing list
    mailchimp_subscribe(
        $attendee->get_email(),
        $attendee->get_name()
    );
}, 10, 1 );
```

**Location:** `includes/Frontend/Shortcodes/RSVPFormShortcode.php:276`

---

### Order Operations

#### `nettertech_events_attendee_creation_failed`

Fired when attendee creation fails during order processing.

**Parameters:**
- `$exception` (Exception) — The exception that occurred
- `$order_id` (int) — WooCommerce order ID
- `$item` (WC_Order_Item_Product) — The order item

**Example:**
```php
add_action( 'nettertech_events_attendee_creation_failed', function( $e, $order_id, $item ) {
    // Log failure for manual resolution
    error_log( sprintf(
        'Attendee creation failed for order %d: %s',
        $order_id,
        $e->getMessage()
    ) );
}, 10, 3 );
```

**Location:** `includes/Integrations/WooCommerce/OrderAttendeeCreator.php:370`

---

### Activity Logging

#### `nettertech_events_activity_logged`

Fired whenever an activity is logged. Useful for custom logging integrations.

**Parameters:**
- `$action` (string) — Action type (create, update, delete, etc.)
- `$object_type` (string) — Object type (event, occurrence, attendee, etc.)
- `$object_id` (int|null) — Object ID
- `$title` (string) — Human-readable title
- `$details` (array) — Additional details

**Example:**
```php
add_action( 'nettertech_events_activity_logged', function( $action, $type, $id, $title, $details ) {
    // Forward to external audit log
    audit_log_service_record( [
        'action' => $action,
        'type' => $type,
        'id' => $id,
        'title' => $title,
        'details' => $details,
        'user_id' => get_current_user_id(),
        'timestamp' => current_time( 'mysql' ),
    ] );
}, 10, 5 );
```

**Location:** `includes/Services/ActivityLogService.php` (multiple locations)

---

### Ticket Admin Extensibility

These hooks allow add-ons to add ticketing UI and frontend content without modifying Events core code.

#### `nettertech_events_ticket_add_button_area`

Fires after the ticket type list in admin metaboxes. Add-ons hook here to inject supplemental UI.

**Parameters:**
- `$event` (Event|null) — The event being edited
- `$ticket_count` (int) — Number of existing ticket types in this context
- `$context` (string) — Metabox context: `'legacy'`, `'occurrence'`, `'series'`, or `'template'`

**Example:**
```php
add_action( 'nettertech_events_ticket_add_button_area', function( $event, $ticket_count, $context ) {
    echo '<p class="description">' . esc_html__( 'Custom ticketing tools can appear here.', 'my-plugin' ) . '</p>';
}, 10, 3 );
```

**Locations:** `includes/Admin/Metaboxes/TicketingMetaboxHandler.php`, `includes/Admin/Metaboxes/TicketsMetabox.php` (4 tabs)

---

#### `nettertech_events_after_ticket_form`

Fires after the ticket form on the frontend single-event page. Add-ons hook here to inject content after the ticket purchase form, such as waitlist or seating information.

**Parameters:**
- `$ticket_types` (array) — Array of TicketType objects displayed
- `$occurrence` (Occurrence) — The occurrence

**Example:**
```php
add_action( 'nettertech_events_after_ticket_form', function( $ticket_types, $occurrence ) {
    if ( count( $ticket_types ) > 1 ) {
        echo '<p class="nte-pro-notice">Multi-ticket batch checkout enabled.</p>';
    }
}, 10, 2 );
```

**Location:** `includes/Frontend/TicketDisplay.php`

---

### Cache Operations

#### `nettertech_events_cache_invalidated`

Fired when the main plugin cache is invalidated.

**Parameters:** None

**Example:**
```php
add_action( 'nettertech_events_cache_invalidated', function() {
    // Clear related caches
    wp_cache_delete( 'my_custom_event_cache', 'nettertech_events' );
} );
```

**Location:** `includes/Core/CacheManager.php:102`

---

#### `nettertech_events_capacity_cache_invalidated`

Fired when capacity cache for a specific ticket type is invalidated.

**Parameters:**
- `$ticket_type_id` (int) — Ticket type ID

**Location:** `includes/Core/CacheManager.php:147`

---

### Template Operations

#### `nettertech_events_get_template_part_{$slug}`

Fired before loading a template part. Dynamic hook name based on template slug.

**Parameters:**
- `$slug` (string) — Template slug
- `$name` (string|null) — Template variation name

**Example:**
```php
// Hook into event-card template loading
add_action( 'get_template_part_parts/event-card', function( $slug, $name ) {
    // Track template usage
    error_log( "Loading template: {$slug}" );
}, 10, 2 );
```

**Location:** `includes/TemplateLoader/TemplateLoader.php:224`

---

#### `nettertech_events_before_template_load`

Fired immediately before a template file is included.

**Parameters:**
- `$file` (string) — Full path to template file
- `$args` (array) — Template arguments

**Location:** `includes/TemplateLoader/TemplateLoader.php:269`

---

#### `nettertech_events_after_template_load`

Fired immediately after a template file is included.

**Parameters:**
- `$file` (string) — Full path to template file
- `$args` (array) — Template arguments

**Location:** `includes/TemplateLoader/TemplateLoader.php:295`

---

### Frontend Content Hooks

These hooks fire inside theme-overridable template files. They allow add-ons and themes to inject content at key positions on frontend pages without modifying plugin templates.

#### `nettertech_events_after_single_content`

Fired at the end of the single event page article, after all standard content sections.

**Parameters:**
- `$event` (Event) — The event being displayed

**Example:**
```php
add_action( 'nettertech_events_after_single_content', function( $event ) {
    echo '<div class="my-addon-content">' . esc_html( $event->title ) . '</div>';
}, 10, 1 );
```

**Location:** `templates/single-event.php:119`

---

#### `nettertech_events_after_series_content`

Fired at the end of the recurring event series page article.

**Parameters:**
- `$event` (Event) — The event series being displayed

**Example:**
```php
add_action( 'nettertech_events_after_series_content', function( $event ) {
    // Add custom content after series page body
}, 10, 1 );
```

**Location:** `templates/series-page.php:208`

---

#### `nettertech_events_single_space_content`

Fired inside the space article, for injecting content into the space detail view. Used by the Rentals add-on to add a rental CTA.

**Parameters:**
- `$space` (object) — The space data object

**Location:** `templates/single-space.php:163`

---

#### `nettertech_events_after_single_space_content`

Fired after the space article wrapper, for appending content below the space detail view.

**Parameters:**
- `$space` (object) — The space data object

**Location:** `templates/single-space.php:177`

---

#### `nettertech_events_after_sold_out`

Fired below the "Sold Out" label on each sold-out ticket type. The base plugin hooks this to render the waitlist panel. Add-ons can hook here to inject alternative content (e.g., alternative purchasing options).

**Parameters:**
- `$ticket_type` (TicketType) — The sold-out ticket type
- `$occurrence` (Occurrence) — The occurrence

**Built-in listener:** `WaitlistFrontend::render_waitlist_panel()` — Renders the waitlist signup form when the `nettertech_events_has_waitlist` filter returns true.

**Example:**
```php
add_action( 'nettertech_events_after_sold_out', function( $ticket_type, $occurrence ) {
    if ( my_addon_has_alternative_option( $occurrence ) ) {
        echo '<a href="' . esc_url( my_addon_url( $occurrence ) ) . '">Notify me</a>';
    }
}, 10, 2 );
```

**Location:** `templates/parts/ticket-form.php:88`

---

#### `nettertech_events_event_card_status`

Fired in the event card's status slot for active occurrences — only when the card is neither cancelled nor past (those states print base labels in the same slot). Extensions print status markup here, e.g. a sold-out badge built from the context's `prefetched_availability` verdict (see the `nettertech_events_cards_need_availability` filter). Output must satisfy `ShortcodeOutput::get_allowlist()`, which the listing shortcodes apply to the whole card.

**Parameters:**
- `$context` (TemplateContext) — Card template context (`occurrence`, `event`, `prefetched_availability`, ...)

**Example:**
```php
add_action( 'nettertech_events_event_card_status', function( $context ) {
    $availability = (array) $context->get( 'prefetched_availability', array() );
    if ( ! empty( $availability['sold_out'] ) ) {
        echo '<p class="nte-event-card__status my-addon-sold-out">' . esc_html__( 'Sold Out', 'my-addon' ) . '</p>';
    }
} );
```

**Location:** `templates/parts/event-card.php` (status slot)

---

#### `nettertech_events_empty_state_content`

Fired inside the empty state container, for injecting content when an event list or calendar has no results.

**Parameters:**
- `$context` (string) — Context string: `'list'`, `'search'`, or `'filter'`

**Example:**
```php
add_action( 'nettertech_events_empty_state_content', function( $context ) {
    if ( 'search' === $context ) {
        echo '<p>Try broadening your search terms.</p>';
    }
}, 10, 1 );
```

**Location:** `templates/parts/empty-state.php:58`

---

#### `nettertech_events_single_occurrence_actions`

Fired in each occurrence row on event pages, after the date/time display. Use to inject ticket buttons, booking links, or other actions per occurrence.

**Parameters:**
- `$occurrence` (Occurrence) — The occurrence
- `$event` (Event) — The parent event

**Example:**
```php
add_action( 'nettertech_events_single_occurrence_actions', function( $occurrence, $event ) {
    echo '<a href="' . esc_url( get_permalink( $occurrence->id ) ) . '" class="button">Book Now</a>';
}, 10, 2 );
```

**Location:** `templates/parts/occurrence-row.php:71`

---

## Filters

### Template Filters

#### `nettertech_events_get_template_part`

Filter template filename candidates before resolution.

**Parameters:**
- `$templates` (array) — Template filenames to search for
- `$slug` (string) — Template slug
- `$name` (string|null) — Template variation name

**Returns:** `array` — Modified template filenames

**Example:**
```php
add_filter( 'nettertech_events_get_template_part', function( $templates, $slug, $name ) {
    // Add custom template variation
    if ( $slug === 'parts/event-card' ) {
        array_unshift( $templates, 'parts/event-card-custom.php' );
    }
    return $templates;
}, 10, 3 );
```

**Location:** `includes/TemplateLoader/TemplateLoader.php:197`

---

#### `nettertech_events_template_args`

Filter template arguments before the template is loaded.

**Parameters:**
- `$args` (array) — Template arguments
- `$file` (string) — Template file path

**Returns:** `array` — Modified template arguments

**Example:**
```php
add_filter( 'nettertech_events_template_args', function( $args, $file ) {
    // Add custom data to all templates
    $args['site_logo'] = get_custom_logo();
    return $args;
}, 10, 2 );
```

**Location:** `includes/TemplateLoader/TemplateLoader.php:253`

---

#### `nettertech_events_template_paths`

Filter the template search paths and their priorities.

**Parameters:**
- `$paths` (array) — Paths keyed by priority (lower = higher priority)
- `$config` (TemplateLoaderConfig) — Configuration object

**Returns:** `array` — Modified paths array

**Example:**
```php
add_filter( 'nettertech_events_template_paths', function( $paths, $config ) {
    // Add custom template directory with high priority
    $paths[5] = WP_CONTENT_DIR . '/nettertech-events-custom/';
    return $paths;
}, 10, 2 );
```

**Default priorities:**
- 1: Child theme
- 10: Parent theme
- 100: Plugin

**Location:** `includes/TemplateLoader/TemplateResolver.php:200`

---

#### `nettertech_events_container_class`

Filter CSS classes for template containers.

**Parameters:**
- `$classes` (array) — CSS class names
- `$context` (string) — Template context
- `$variant` (string) — Template variant

**Returns:** `array` — Modified class names

**Example:**
```php
add_filter( 'nettertech_events_container_class', function( $classes, $context, $variant ) {
    $classes[] = 'my-custom-class';
    return $classes;
}, 10, 3 );
```

**Location:** `includes/TemplateLoader/Templates.php:332`

---

#### `nettertech_events_empty_state_message`

Filter the message shown when an event list or calendar has no results.

**Parameters:**
- `$message` (string) — The message to display
- `$context` (string) — Context string: `'list'`, `'search'`, or `'filter'`

**Returns:** `string` — Modified message

**Example:**
```php
add_filter( 'nettertech_events_empty_state_message', function( $message, $context ) {
    if ( 'search' === $context ) {
        return 'No events matched your search. Try different keywords.';
    }
    return $message;
}, 10, 2 );
```

**Location:** `templates/parts/empty-state.php:47`

---

#### `nettertech_events_ticket_scan_data`

Filter the data array passed to the ticket scan result template. Allows add-ons to inject additional display data into the check-in scan result view.

**Parameters:**
- `$scan_data` (array) — Scan result data (ticket status, attendee name, event title, etc.)

**Returns:** `array` — Modified scan data

**Example:**
```php
add_filter( 'nettertech_events_ticket_scan_data', function( $scan_data ) {
    $scan_data['custom_field'] = get_my_addon_data( $scan_data['ticket_id'] );
    return $scan_data;
} );
```

**Location:** `templates/ticket/scan-result.php:77`

---

### Data Filters

#### `nettertech_events_layout_components`

Filter available layout components for event pages.

**Parameters:**
- `$components` (array) — Component definitions

**Returns:** `array` — Modified components

**Example:**
```php
add_filter( 'nettertech_events_layout_components', function( $components ) {
    // Add custom component
    $components['custom_section'] = [
        'label' => 'Custom Section',
        'default_visible' => true,
    ];
    return $components;
} );
```

**Location:** `includes/Services/LayoutService.php:292`

---

#### `nettertech_events_localize_data`

Filter JavaScript localization data.

**Parameters:**
- `$data` (array) — Data to be localized

**Returns:** `array` — Modified data

**Example:**
```php
add_filter( 'nettertech_events_localize_data', function( $data ) {
    $data['custom_setting'] = get_option( 'my_custom_setting' );
    return $data;
} );
```

**Location:** `includes/Core/Assets.php:526`

---

#### `nettertech_events_detected_views`

Filter detected view types for asset loading.

**Parameters:**
- `$detected_views` (array) — Detected view contexts

**Returns:** `array` — Modified views

**Location:** `includes/Core/Assets.php:437`

---

#### `nettertech_events_css_overrides`

Filter CSS overrides applied to templates.

**Parameters:**
- `$css_overrides` (string) — CSS content
- `$template` (string) — Template name

**Returns:** `string` — Modified CSS

**Location:** `includes/Core/Assets.php:595`

---

### Security Filters

#### `nettertech_events_csp_directives`

Filter Content Security Policy directives.

**Parameters:**
- `$directives` (array) — CSP directive values

**Returns:** `array` — Modified directives

**Example:**
```php
add_filter( 'nettertech_events_csp_directives', function( $directives ) {
    // Allow additional script source
    $directives['script-src'] .= ' https://analytics.example.com';
    return $directives;
} );
```

**Location:** `includes/Core/SecurityHeaders.php:180`

---

#### `nettertech_events_csp_frame_src`

Filter the origins allowed in the public/admin Content Security Policy `frame-src` directive. Defaults to the common oEmbed video providers so embedded YouTube/Vimeo videos in event descriptions are not blocked by the `default-src 'self'` fallback. Merged with `'self'`; provide origins as scheme + host. CR/LF and other non-origin characters are stripped to prevent header injection.

**Parameters:**
- `$default_sources` (array) — Default video-provider origins: `https://www.youtube.com`, `https://www.youtube-nocookie.com`, `https://player.vimeo.com`

**Returns:** `array` — Origins to allow in `frame-src` (in addition to `'self'`)

**Example:**
```php
add_filter( 'nettertech_events_csp_frame_src', function( $sources ) {
    // Allow an additional self-hosted video provider.
    $sources[] = 'https://video.example.com';
    return $sources;
} );
```

**Location:** `includes/Core/SecurityHeaders.php` (`get_frame_src_directive()`)

---

#### `nettertech_events_rate_limit_bypass`

Filter whether to bypass rate limiting for the current user.

**Parameters:**
- `$bypass` (bool) — Whether to bypass (default: true for admins)

**Returns:** `bool` — Whether to bypass rate limiting

**Example:**
```php
add_filter( 'nettertech_events_rate_limit_bypass', function( $bypass ) {
    // Also bypass for shop managers
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return true;
    }
    return $bypass;
} );
```

**Location:** `includes/Services/RateLimitService.php:335`

---

### Configuration Filters

#### `nettertech_events_cards_need_availability`

Filter whether listing controllers should compute per-card availability. Return `true` to have the list/carousel shortcodes and the series page run the batched on-sale ticket-type prefetch, compute per-occurrence sold-out verdicts via `OccurrenceAvailabilityPresenter`, and pass a `prefetched_availability` array (`'sold_out' => bool`) into every event-card context. This is the no-N+1 path for extensions that render availability labels via `nettertech_events_event_card_status`; the default `false` keeps installs without such an extension free of the extra queries.

**Parameters:**
- `$needed` (bool) — Whether card availability is needed (default: false)

**Example:**
```php
add_filter( 'nettertech_events_cards_need_availability', '__return_true' );
```

**Related JS lifecycle events** (for decorating client-rendered surfaces): `nte-calendar:rendered` (detail: `view`, `grid`, `events`), `nte-calendar:popup-rendered` (detail: `popup`, `events`, `date`), `nte-grid:rendered` (detail: `grid`, `events`). Calendar/grid entries carry `data-event-id`; each REST occurrence's `tickets.sold_out` carries the verdict.

#### `nettertech_events_activity_logging_enabled`

Filter whether activity logging is enabled.

**Parameters:**
- `$enabled` (bool) — Whether logging is enabled (default: true)

**Returns:** `bool` — Whether to enable logging

**Location:** `includes/Services/ActivityLogService.php:59`

---

#### `nettertech_events_activity_log_retention_days`

Filter how many days to retain activity log entries.

**Parameters:**
- `$days` (int) — Retention period in days

**Returns:** `int` — Modified retention period

**Example:**
```php
add_filter( 'nettertech_events_activity_log_retention_days', function( $days ) {
    // Extend retention to 1 year
    return 365;
} );
```

**Location:** `includes/Services/ActivityLogService.php:285`

---

#### `nettertech_events_activity_retention`

Filter the cutoff date for activity log cleanup.

**Parameters:**
- `$cutoff` (string) — MySQL datetime string
- `$days_to_keep` (int) — Days to keep

**Returns:** `string` — Modified cutoff datetime

**Location:** `includes/Repositories/ActivityLogRepository.php:308`

---

### Email Filters

#### `nettertech_events_reminder_email_data`

Filter the reminder email template data before rendering.

**Parameters:**
- `$data` (array) — Template data array (occurrence, event, attendee, venue_logo, site_name, site_url, event_url)
- `$occurrence` (Occurrence) — Occurrence object
- `$event` (Event) — Event object
- `$attendee` (Attendee) — Attendee object

**Returns:** `array` — Modified template data

**Example:**
```php
add_filter( 'nettertech_events_reminder_email_data', function( $data, $occurrence, $event, $attendee ) {
    // Add custom data to reminder emails
    $data['custom_message'] = get_option( 'nettertech_events_reminder_custom_message', '' );
    return $data;
}, 10, 4 );
```

**Location:** `includes/Services/EmailTemplateRenderer.php:422`

---

#### `nettertech_events_reminder_email_content`

Filter the rendered reminder email HTML after template rendering.

**Parameters:**
- `$html` (string) — Rendered HTML content
- `$occurrence` (Occurrence) — Occurrence object
- `$event` (Event) — Event object
- `$attendee` (Attendee) — Attendee object

**Returns:** `string` — Modified HTML

**Example:**
```php
add_filter( 'nettertech_events_reminder_email_content', function( $html, $occurrence, $event, $attendee ) {
    // Append a custom footer to reminder emails
    $html = str_replace( '</body>', '<p>Custom footer</p></body>', $html );
    return $html;
}, 10, 4 );
```

**Location:** `includes/Services/EmailTemplateRenderer.php:438`

---

### CSS Filters

#### `nettertech_events_image_ratio_css_vars`

Filter CSS custom property variables for image aspect ratios.

**Parameters:**
- `$css_vars` (array) — CSS variable name => value pairs

**Returns:** `array` — Modified CSS variables

**Example:**
```php
add_filter( 'nettertech_events_image_ratio_css_vars', function( $css_vars ) {
    // Override image aspect ratio to 16:9
    $css_vars['--nte-image-ratio-default'] = '56.25%';
    return $css_vars;
} );
```

**Location:** `includes/Core/Assets.php:661`

---

### Branding Filters

#### `nettertech_events_show_frontend_branding`

Filter whether to show optional plugin branding on the frontend.

Public-facing credit links are disabled by default and should only be enabled after the site owner explicitly opts in.

**Parameters:**
- `$show` (bool) — Whether to show branding (default: the `show_frontend_branding` setting, false on new installs)

**Returns:** `bool` — Whether to show branding

**Example:**
```php
// Show optional "Powered by NetterTech Events" branding after site-owner opt-in.
add_filter( 'nettertech_events_show_frontend_branding', '__return_true' );
```

**Location:** `includes/Frontend/FrontendBranding.php:93`

---

### SEO Integration Filters

These hooks are used by NTE's Yoast SEO and Rank Math integrations. They are relevant if you are building a custom SEO plugin integration.

#### `nettertech_events_schema_org_data`

Filter the Schema.org JSON-LD data before output. The Yoast and Rank Math integrations use this (at priority 1) to return an empty array, disabling NTE's built-in schema when the SEO plugin handles it.

**Parameters:**
- `$data` (array) - Schema.org Event data

**Returns:** `array` - Modified schema data (return empty array to disable output)

**Example:**
```php
// Disable NTE's built-in schema for a custom SEO plugin
add_filter( 'nettertech_events_schema_org_data', function( $data ) {
    if ( my_seo_plugin_is_active() ) {
        return array();
    }
    return $data;
}, 1 );
```

**Location:** `includes/Frontend/SchemaMarkup.php`, filtered in `includes/Integrations/Yoast/YoastIntegration.php` and `includes/Integrations/RankMath/RankMathIntegration.php`

---

#### External hooks consumed

NTE filters the following hooks from Yoast SEO and Rank Math. These are not NTE hooks but are documented here for completeness.

| Hook | Plugin | NTE Callback | Purpose |
|------|--------|-------------|---------|
| `wpseo_schema_graph` | Yoast | `YoastIntegration::filter_schema_graph()` | Inject Event schema into Yoast's graph |
| `wpseo_sitemaps_providers` | Yoast | `YoastIntegration::register_sitemap_provider()` | Register event sitemap |
| `wpseo_register_extra_replacements` | Yoast | `YoastIntegration::register_custom_variables()` | Register `%%nettertech_events_event_*%%` variables |
| `wpseo_breadcrumb_links` | Yoast | `YoastIntegration::filter_breadcrumb_links()` | Inject event breadcrumbs |
| `rank_math/json_ld` | Rank Math | `RankMathIntegration::filter_json_ld()` | Inject Event schema |
| `rank_math/vars/register_extra_replacements` | Rank Math | `RankMathIntegration::register_custom_variables()` | Register `%nettertech_events_event_*%` variables |
| `rank_math/frontend/breadcrumb/html` | Rank Math | `RankMathIntegration::filter_breadcrumb_html()` | Inject event breadcrumbs |

For full details, see [SEO-INTEGRATION.md](SEO-INTEGRATION.md).

---

## AJAX Actions

WordPress AJAX actions registered by NetterTech Events. These use standard WordPress `wp_ajax_` and `wp_ajax_nopriv_` hooks.

### Cart Operations

#### `wp_ajax_nettertech_events_add_tickets_batch` / `wp_ajax_nopriv_nettertech_events_add_tickets_batch`

Add multiple ticket types to the WooCommerce cart in a single batch operation. Registered only when WooCommerce is active.

**Handler:** `includes/Frontend/TicketCartAjax.php` (`handle_add_batch()`)

**POST Parameters:**
- `tickets` (string) — JSON-encoded array of ticket selections
  - `ticket_type_id` (int) — Ticket type ID
  - `quantity` (int) — Quantity to add
- `nettertech_events_ticket_nonce` (string) — Security nonce created with action `nettertech_events_ticket_cart_nonce`

**Response (Success, full or partial):**
```json
{
  "success": true,
  "data": {
    "cart_url": "/cart/",
    "cart_count": 3,
    "message": "Tickets added to cart.",
    "ticket_errors": { "2": "This ticket could not be added to cart." }
  }
}
```
`ticket_errors` is present only on partial success.

**Response (Error):** `success: false` with `data.message` (and `data.ticket_errors` for validation failures). Status codes: 400 (invalid/empty ticket data, batch validation failure), 403 (nonce failure), 429 (rate limited), 500 (nothing could be added).

**JavaScript Example:**
```javascript
const tickets = [
    { ticket_type_id: 1, quantity: 2 },
    { ticket_type_id: 2, quantity: 1 }
];

const formData = new FormData();
formData.append( 'action', 'nettertech_events_add_tickets_batch' );
formData.append( 'nettertech_events_ticket_nonce', nonceValue );
formData.append( 'tickets', JSON.stringify( tickets ) );

fetch( ajaxUrl, {
    method: 'POST',
    body: formData
} )
.then( response => response.json() )
.then( data => {
    if ( data.success ) {
        window.location.href = data.data.cart_url;
    } else {
        console.error( data.data.ticket_errors || data.data.message );
    }
} );
```

**Location:** Form markup in `templates/parts/ticket-form.php` (nonce field and hidden `action` input)

---

## Complete Hook Index

Every hook the plugin fires, grouped by kind, with the version it first shipped in and where it fires. Detailed guides for the most commonly used hooks appear in the sections above; this index is the complete surface. Hooks marked **internal** coordinate the plugin suite and carry no backwards-compatibility guarantee.

### Actions and filters fired in core

| Hook | Type | Since | Fired at | Description |
|---|---|---|---|---|
| `nettertech_events_activated` | action | 1.0.2 | `includes/Core/Activator.php:61` | Fires on plugin activation, after tables are created. |
| `nettertech_events_activity_log_retention_days` | filter | 1.0.2 | `includes/Core/PrivacyHooks.php:169`; `includes/Services/ActivityLogService.php:291` | Filters the activity log retention days. |
| `nettertech_events_activity_logged` (internal) | action | 1.0.2 | `includes/Services/ActivityLogService.php:114`; `includes/Services/ActivityLogService.php:133`; `includes/Services/ActivityLogService.php:152` (+4 more) | Fires after an activity is logged. |
| `nettertech_events_activity_logging_enabled` | filter | 1.0.2 | `includes/Services/ActivityLogService.php:65` | Filters whether activity logging is enabled. |
| `nettertech_events_activity_retention` | filter | 1.0.2 | `includes/Repositories/ActivityLogRepository.php:305` | Filters the activity retention cutoff date. |
| `nettertech_events_admin_ready` | action | 1.0.2 | `includes/Core/Plugin.php:381` | Fires after admin initialization is complete. |
| `nettertech_events_admin_ticket_rows` | filter | 1.1.2 | `includes/Admin/Metaboxes/TicketsMetabox.php:507` | Filters the tiers offered for editing in the tickets metabox. |
| `nettertech_events_after_delete_event` | action | 1.0.2 | `includes/Repositories/EventRepository.php:487` | Fires after an event has been deleted. |
| `nettertech_events_after_duplicate_event` | action | 1.0.2 | `includes/Services/EventDuplicationService.php:150` | Fires after an event is duplicated. |
| `nettertech_events_after_save_event` | action | 1.0.2 | `includes/Repositories/EventRepository.php:396` | Fires after an event is created or updated. |
| `nettertech_events_after_series_content` | action | 1.0.2 | `templates/series-page.php:209` | Fires after the main content on series pages. |
| `nettertech_events_after_single_content` | action | 1.0.2 | `templates/single-event.php:113` | Fires after the main content on single event pages. |
| `nettertech_events_after_single_space_content` | action | 1.0.2 | `templates/single-space.php:173` | Fires after the space article on single space pages. |
| `nettertech_events_after_sold_out` | action | 1.0.2 | `includes/Frontend/Shortcodes/RSVPFormShortcode.php:196`; `templates/parts/ticket-form.php:81` | Fires after the sold-out message renders, before the closing wrapper. |
| `nettertech_events_after_template_load` | action | 1.0.2 | `includes/TemplateLoader/TemplateLoader.php:293` | Fires immediately after a template file has been included. |
| `nettertech_events_after_ticket_form` | action | 1.0.2 | `includes/Frontend/TicketDisplay.php:331` | Fires after the ticket form on the frontend single-event page. |
| `nettertech_events_attendee_checked_in` | action | 1.1.2 | `includes/Admin/Ajax/AttendeeCheckInAjaxHandler.php:135` | Fires when an attendee is checked in. |
| `nettertech_events_attendee_created` | action | 1.0.2 | `includes/Core/ActivityLogHooks.php:191`; `includes/Integrations/WooCommerce/OrderAttendeeCreator.php:391`; `includes/Integrations/WooCommerce/OrderAttendeeCreator.php:528` | Fires after an attendee is created from a WooCommerce order. |
| `nettertech_events_attendee_creation_failed` | action | 1.0.2 | `includes/Integrations/WooCommerce/OrderAttendeeCreator.php:394`; `includes/Integrations/WooCommerce/OrderAttendeeCreator.php:531` | Fires when attendee creation fails. |
| `nettertech_events_attendee_failure_handled` | action | 1.0.2 | `includes/Integrations/WooCommerce/OrderFailureHandler.php:110` | Fires after the failure handler has completed all corrective actions. |
| `nettertech_events_attendees_column_content` | filter | 1.1.2 | `includes/Admin/AttendeesPage.php:822` | Filters the cell HTML for an extension-added attendees table column. |
| `nettertech_events_attendees_columns` | filter | 1.1.2 | `includes/Admin/AttendeesPage.php:554` | Filters extra columns for the admin attendees table. |
| `nettertech_events_attendees_exported` | action | 1.1.2 | `includes/Services/ExportService.php:210` | Fires when attendees are exported. |
| `nettertech_events_attendees_prime` | action | 1.1.2 | `includes/Admin/AttendeesPage.php:569` | Fires once before the attendee rows render, with the full page set. |
| `nettertech_events_available_count` | filter | 1.0.2 | `includes/Services/CapacityCalculator.php:119` | Filters the available count for a ticket type. |
| `nettertech_events_before_delete_event` | action | 1.0.2 | `includes/Repositories/EventRepository.php:466` | Fires before an event is deleted. |
| `nettertech_events_before_duplicate_event` | action | 1.0.2 | `includes/Services/EventDuplicationService.php:135` | Fires before an event is duplicated. |
| `nettertech_events_before_save_event` | action | 1.0.2 | `includes/Repositories/EventRepository.php:348` | Fires before an event is created or updated. |
| `nettertech_events_before_template_load` | action | 1.0.2 | `includes/TemplateLoader/TemplateLoader.php:266` | Fires immediately before a template file is included. |
| `nettertech_events_buffer_stock_updated` | action | 1.0.2 | `includes/Services/CapacityCalculator.php:321` | Fires after buffer stock is updated for a ticket type. |
| `nettertech_events_cache_invalidated` | action | 1.0.2 | `includes/Core/CacheManager.php:207`; `includes/Services/BulkImportHandler.php:142` | Fires after plugin caches are intentionally invalidated. Useful for other plugins/themes to clear their own related caches. |
| `nettertech_events_calendar_enqueue_scripts` | action | 1.0.2 | `includes/Core/Assets.php:457` | Fires when calendar scripts are enqueued. |
| `nettertech_events_calendar_header_html` | filter | 1.0.2 | `includes/Frontend/Shortcodes/CalendarShortcode.php:171` | Filters the calendar header HTML content. |
| `nettertech_events_calendar_js_config` | filter | 1.0.2 | `includes/Core/Assets.php:648` | Filters the calendar JavaScript configuration data. |
| `nettertech_events_calendar_render_complete` | action | 1.0.2 | `includes/Frontend/Shortcodes/CalendarShortcode.php:225` | Fires after calendar rendering is complete. |
| `nettertech_events_calendar_shortcode_atts` | filter | 1.0.2 | `includes/Frontend/Shortcodes/CalendarShortcode.php:85` | Filters the calendar shortcode attributes. |
| `nettertech_events_calendar_wrapper_classes` | filter | 1.0.2 | `includes/Frontend/Shortcodes/CalendarShortcode.php:124` | Filters the calendar wrapper CSS classes. |
| `nettertech_events_capacity_cache_invalidated` | action | 1.0.2 | `includes/Core/CacheManager.php:305` | Fires after capacity cache is invalidated for a ticket type. |
| `nettertech_events_capacity_check` | filter | 1.0.2 | `includes/Services/CapacityService.php:181` | Filters the capacity check result. |
| `nettertech_events_capacity_oversell_detected` | action | 1.0.2 | `includes/Integrations/WooCommerce/OrderCapacityValidator.php:190` | Fires when a capacity oversell is detected during order validation. |
| `nettertech_events_capacity_released` | action | 1.0.2 | `includes/Services/CapacityService.php:145` | Fires after capacity is released for a ticket type. |
| `nettertech_events_capacity_reserved` | action | 1.0.2 | `includes/Services/CapacityService.php:107` | Fires after capacity is reserved for a ticket type. |
| `nettertech_events_capacity_types` | filter | 1.0.2 | `includes/Enums/CapacityType.php:141` | Filters the available capacity types. |
| `nettertech_events_carousel_empty_message` | filter | 1.0.2 | `includes/Frontend/Shortcodes/CarouselShortcode.php:167` | Filters the empty state message for the carousel shortcode. |
| `nettertech_events_confirmation_emails_sent` | action | 1.0.2 | `includes/Services/OrderEmailHandler.php:183` | Fires after confirmation emails (customer and venue) are sent for an order. |
| `nettertech_events_container_class` | filter | 1.0.2 | `includes/TemplateLoader/Templates.php:365` | Filters the container CSS classes. |
| `nettertech_events_csp_directives` | filter | 1.0.2 | `includes/Core/SecurityHeaders.php:387` | Filters the CSP directives for admin pages. |
| `nettertech_events_csp_frame_src` | filter | 1.1.1 | `includes/Core/SecurityHeaders.php:540` | Filters the frame-src origins for the Content Security Policy. |
| `nettertech_events_csp_report_uri` | filter | 1.0.2 | `includes/Core/SecurityHeaders.php:641` | Filters the CSP violation report URI. |
| `nettertech_events_csp_script_src` | filter | 1.1.2 | `includes/Core/SecurityHeaders.php:596` | Filters the script-src origins for the Content Security Policy. |
| `nettertech_events_css_overrides` | filter | 1.0.2 | `includes/Core/InlineCssGenerator.php:98` | Filters the CSS overrides applied to templates. |
| `nettertech_events_custom_field_values_saved` | action | 1.0.2 | `includes/Services/AttendeeFieldService.php:146` | Fires after custom field values are saved for an attendee. |
| `nettertech_events_deactivated` | action | 1.0.2 | `includes/Core/Deactivator.php:37` | Fires on plugin deactivation. |
| `nettertech_events_detected_views` | filter | 1.0.2 | `includes/Core/PageContextDetector.php:124` | Filters the detected views for asset enqueueing. |
| `nettertech_events_email_qr_codes` | action | 1.0.2 | `templates/emails/customer-confirmation.php:154` | Renders QR code section in customer confirmation emails. |
| `nettertech_events_email_rsvp_qr_code` | action | 1.0.2 | `templates/emails/rsvp-confirmation.php:140` | Renders QR code section in RSVP confirmation emails. |
| `nettertech_events_empty_state_content` | action | 1.0.2 | `templates/parts/empty-state.php:56` | Fires inside the empty-state container, after the message. |
| `nettertech_events_empty_state_message` | filter | 1.0.2 | `templates/parts/empty-state.php:46` | Filters the empty-state message shown when no events match. |
| `nettertech_events_event_created` | action | 1.0.2 | `includes/Repositories/EventRepository.php:423` | Fires when an event is created. |
| `nettertech_events_event_deleted` | action | 1.0.2 | `includes/Repositories/EventRepository.php:490` | Fires when an event is deleted. |
| `nettertech_events_event_published` | action | 1.0.2 | `includes/Repositories/EventRepository.php:427`; `includes/Repositories/EventRepository.php:436` | Fires when an event is published. |
| `nettertech_events_event_restored` | action | 1.0.2 | `includes/Services/RevisionService.php:271` | Fires when an event is restored from a revision. |
| `nettertech_events_event_unpublished` | action | 1.0.2 | `includes/Repositories/EventRepository.php:438` | Fires when an event is unpublished. |
| `nettertech_events_event_updated` | action | 1.0.2 | `includes/Repositories/EventRepository.php:431` | Fires when an event is updated. |
| `nettertech_events_frontend_ready` | action | 1.0.2 | `includes/Core/Plugin.php:453` | Fires after frontend initialization is complete. |
| `nettertech_events_get_template_part` | filter | 1.0.2 | `includes/TemplateLoader/TemplateLoader.php:199` | Filters the array of candidate template files for a given template part. |
| `nettertech_events_get_template_part_rendered` | action | 1.0.2 | `includes/TemplateLoader/TemplateLoader.php:225` | Fires after a template part is rendered, scoped to a particular slug. |
| `nettertech_events_has_waitlist` | filter | 1.0.2 | `includes/Frontend/WaitlistFrontend.php:97`; `includes/Frontend/Shortcodes/RSVPFormShortcode.php:182`; `includes/Frontend/Shortcodes/RSVPFormShortcode.php:448` | Filters whether waitlist functionality is available. |
| `nettertech_events_horizon_extension_batch_size` | filter | 1.0.2 | `includes/Services/OccurrenceHorizonExtender.php:369` | Filters the batch size for occurrence horizon extension processing. |
| `nettertech_events_horizon_extension_threshold` | filter | 1.0.2 | `includes/Services/OccurrenceHorizonExtender.php:351` | Filters the number of days before horizon expiry that triggers occurrence horizon extension. |
| `nettertech_events_image_ratio_css_vars` | filter | 1.0.2 | `includes/Core/InlineCssGenerator.php:227` | Filters the image ratio CSS variables. |
| `nettertech_events_init` | action | 1.0.2 | `includes/Core/Plugin.php:219` | Centralized action and filter hooks. |
| `nettertech_events_layout_components` | filter | 1.0.2 | `includes/Services/LayoutService.php:334` | Filters the layout components list. |
| `nettertech_events_legacy_rebrand_complete` (internal) | action | 1.0.2 | `includes/Database/MigrationManager.php:286` | Fires after the one-time legacy (ve_ era) rebrand migration completes. Add-on plugins listen to migrate their own legacy data. |
| `nettertech_events_list_column` | action | 1.1.1 | `includes/Admin/ListTables/EventsListTable.php:808` | Fires when rendering a registered custom column cell on the All Events list. |
| `nettertech_events_list_columns` | filter | 1.1.1 | `includes/Admin/ListTables/EventsListTable.php:185` | Filters the columns shown on the admin All Events list table. |
| `nettertech_events_list_empty_message` | filter | 1.0.2 | `includes/Frontend/Shortcodes/EventListShortcode.php:672` | Filters the empty state message for the event list shortcode. |
| `nettertech_events_localize_data` | filter | 1.0.2 | `includes/Core/Assets.php:636` | Filters the data array localized for frontend scripts. |
| `nettertech_events_max_revisions` | filter | 1.0.2 | `includes/Services/RevisionService.php:170`; `includes/Services/RevisionService.php:273` | Filter the maximum number of revisions to keep per event. |
| `nettertech_events_occurrence_cancelled` | action | 1.0.2 | `includes/Repositories/OccurrenceRepository.php:692` | Fires when an occurrence is cancelled. |
| `nettertech_events_occurrence_created` | action | 1.0.2 | `includes/Repositories/OccurrenceRepository.php:372` | Fires when an occurrence is created. |
| `nettertech_events_occurrence_deleted` | action | 1.0.2 | `includes/Repositories/OccurrenceRepository.php:543` | Fires when an occurrence is deleted. |
| `nettertech_events_occurrence_deletion_blocked` | action | 1.0.2 | `includes/Services/RecurrenceService.php:323`; `includes/Services/RecurrenceService.php:559`; `includes/Services/RecurrenceService.php:821` | Fires when an occurrence deletion is blocked because it has attendees. |
| `nettertech_events_occurrence_status_changed` | action | 1.0.2 | `includes/Repositories/OccurrenceRepository.php:685` | Fires when an occurrence's status changes. |
| `nettertech_events_occurrences_generated` | action | 1.0.2 | `includes/Services/RecurrenceService.php:183` | Fires after occurrences are generated for a recurring event. |
| `nettertech_events_on_sale_ticket_types` | filter | 1.1.2 | `includes/Repositories/TicketTypeQueryRepository.php:312` | Filters the ticket types offered to a buyer for an occurrence. |
| `nettertech_events_open_graph_tags` | filter | 1.0.2 | `includes/Frontend/OpenGraphTags.php:68` | Filters the Open Graph meta tags before output. |
| `nettertech_events_palette_map` | filter | 1.0.2 | `includes/Services/PaletteResolver.php:47` | Filters the palette map for theme color integration. |
| `nettertech_events_prefix_migration_complete` (internal) | action | 1.0.2 | `includes/Database/PrefixMigrationManager.php:250` | Fires after the table/option prefix migration completes successfully. |
| `nettertech_events_product_cat_ids` | filter | 1.1.2 | `includes/Integrations/WooCommerce/ProductManager.php:259` | Filters the final product_cat term-ID set for a ticket product. |
| `nettertech_events_public_csp_directives` | filter | 1.0.2 | `includes/Core/SecurityHeaders.php:465` | Filters the CSP directives for public pages. |
| `nettertech_events_purchases_synopsis` | action | 1.1.2 | `includes/Admin/AttendeesPage.php:408` | Fires inside the attendees summary card as an extension slot. |
| `nettertech_events_rate_limit_bypass` | filter | 1.0.2 | `includes/Services/RateLimitService.php:317` | Filters whether rate limiting should be bypassed. |
| `nettertech_events_rate_limit_settings` | filter | 1.0.2 | `includes/Services/RateLimitService.php:85` | Filters the rate limit settings. |
| `nettertech_events_register_admin_pages` | action | 1.0.2 | `includes/Admin/AdminMenuRegistrar.php:168` | Fires after admin submenu pages are registered (priority 20). |
| `nettertech_events_register_assets` | action | 1.0.2 | `includes/Core/Assets.php:376` | Fires after base scripts and styles are registered. |
| `nettertech_events_registration_voided` | action | 1.0.2 | `includes/Integrations/WooCommerce/OrderHandler.php:463` | Fires when a registration is voided due to order cancellation/full refund. |
| `nettertech_events_reminder_email_content` | filter | 1.0.2 | `includes/Services/EmailTemplateRenderer.php:463` | Filters the rendered reminder email HTML content. |
| `nettertech_events_reminder_email_data` | filter | 1.0.2 | `includes/Services/EmailTemplateRenderer.php:447` | Filters the reminder email data before rendering. |
| `nettertech_events_reminder_email_subject` | filter | 1.0.2 | `includes/Services/EmailTemplateRenderer.php:393` | Filters the reminder email subject line. |
| `nettertech_events_reservation_changed` | action | 1.0.2 | `includes/Services/ReservationManager.php:95`; `includes/Services/ReservationManager.php:126`; `includes/Services/ReservationManager.php:159` (+2 more) | Fires when a capacity reservation changes (created, released, or expired). |
| `nettertech_events_rest_admin_attendees_get_response` | filter | 1.0.2 | `includes/API/AttendeesAdminController.php:346` | Filters the REST response for a single admin attendee. |
| `nettertech_events_rest_admin_attendees_list_response` | filter | 1.0.2 | `includes/API/AttendeesAdminController.php:239` | Filters the REST response for admin attendees list. |
| `nettertech_events_rest_admin_events_get_response` | filter | 1.0.2 | `includes/API/EventsAdminController.php:256` | Filters the REST response for a single admin event. |
| `nettertech_events_rest_admin_events_list_response` | filter | 1.0.2 | `includes/API/EventsAdminController.php:220` | Filters the REST response for admin events list. |
| `nettertech_events_rest_admin_ticket_types_get_response` | filter | 1.0.2 | `includes/API/TicketTypesAdminController.php:358` | Filters the REST response for a single admin ticket type. |
| `nettertech_events_rest_admin_ticket_types_list_response` | filter | 1.0.2 | `includes/API/TicketTypesAdminController.php:266` | Filters the REST response for admin ticket types list. |
| `nettertech_events_rest_events_get_response` | filter | 1.0.2 | `includes/API/EventsController.php:259` | Filters the REST response for a single event (public). |
| `nettertech_events_rest_events_range_response` | filter | 1.0.2 | `includes/API/EventsController.php:222` | Filters the REST response for the events/range endpoint. |
| `nettertech_events_rest_events_upcoming_response` | filter | 1.0.2 | `includes/API/EventsController.php:178` | Filters the REST response for the events/upcoming endpoint. |
| `nettertech_events_rest_occurrences_list_response` | filter | 1.0.2 | `includes/API/EventsController.php:660`; `includes/API/EventsController.php:716` | Filters the REST response for the occurrences endpoint. |
| `nettertech_events_route_template` | filter | 1.0.2 | `includes/Frontend/Router.php:387` | Filters the template to load for custom route handling. |
| `nettertech_events_rsvp_submitted` | action | 1.0.2 | `includes/Frontend/Shortcodes/RSVPFormShortcode.php:379` | Fires after an RSVP form is successfully submitted and an attendee is created. |
| `nettertech_events_sale_schedule_ui_available` | filter | 1.1.2 | `includes/Admin/Metaboxes/TicketFormRenderer.php:222` | Filters whether a sale-schedule control is present in the ticket form. |
| `nettertech_events_save_pro_extensions` (internal) | action | 1.0.2 | `includes/Admin/EventSaveHandler.php:495` | Fires after Pro extension data is saved for an event. |
| `nettertech_events_schema_org_data` | filter | 1.0.2 | `includes/Frontend/SchemaMarkup.php:61` | Filters the Schema.org structured data for an event. |
| `nettertech_events_search_label` | filter | 1.1.1 | `includes/Frontend/Shortcodes/EventListShortcode.php:280` | Filters the screen-reader label for the search input in the event list. |
| `nettertech_events_search_placeholder` | filter | 1.1.1 | `includes/Frontend/Shortcodes/EventListShortcode.php:278` | Filters the search input placeholder text in the event list. |
| `nettertech_events_service_providers` | filter | 1.0.2 | `includes/Core/ServiceRegistry.php:414` | Filters the list of service providers registered with the DI container. |
| `nettertech_events_settings_tab_render` | action | 1.0.2 | `includes/Admin/SettingsPage.php:182` | Fired when rendering a settings page extension tab. |
| `nettertech_events_settings_tab_save` | action | 1.0.2 | `includes/Admin/Settings/SettingsSaveHandler.php:254` | Fires when saving a settings page extension tab. |
| `nettertech_events_settings_tabs` | filter | 1.0.2 | `includes/Admin/SettingsPage.php:99` | Filters the settings page tabs. |
| `nettertech_events_settings_updated` | action | 1.1.2 | `includes/Admin/Settings/SettingsSaveHandler.php:203` | Fires when settings are updated. |
| `nettertech_events_show_frontend_branding` | filter | 1.0.2 | `includes/Frontend/FrontendBranding.php:102` | Filters whether to show optional frontend branding. |
| `nettertech_events_single_occurrence_actions` | action | 1.0.2 | `templates/parts/occurrence-row.php:72` | Fires to render actions for a single occurrence display. |
| `nettertech_events_single_space_content` | action | 1.0.2 | `templates/single-space.php:160` | Fires within the space detail page article. |
| `nettertech_events_template_args` | filter | 1.0.2 | `includes/TemplateLoader/TemplateLoader.php:256` | Filters the arguments passed to a template file before it is rendered. |
| `nettertech_events_template_paths` | filter | 1.0.2 | `includes/TemplateLoader/TemplateResolver.php:203` | Filters the array of paths to search for template files. |
| `nettertech_events_templates_applied` | action | 1.0.2 | `includes/Services/RecurrenceService.php:903` | Fires after templates are applied to occurrences. |
| `nettertech_events_ticket_add_button_area` | action | 1.0.2 | `includes/Admin/Metaboxes/TicketsMetabox.php:294`; `includes/Admin/Metaboxes/TicketsMetabox.php:389`; `includes/Admin/Metaboxes/TicketsMetabox.php:424` (+1 more) | Fires after the ticket type list in admin metaboxes. |
| `nettertech_events_ticket_row_fields` | action | 1.1.2 | `includes/Admin/Metaboxes/TicketFormRenderer.php:294` | Fires inside a ticket-type row in the admin form, after the tier's own fields. |
| `nettertech_events_ticket_scan_data` | filter | 1.0.2 | `templates/ticket/scan-result.php:74` | Filters ticket scan result template data. |
| `nettertech_events_ticket_type_revenue` | filter | 1.1.2 | `includes/Admin/AttendeesPage.php:356` | Filters per-ticket-type revenue for the consolidated attendees overview; return a tier-id-keyed map to add a net-revenue column. Pro answers this filter. |
| `nettertech_events_ticket_type_created` | action | 1.1.2 | `includes/Repositories/TicketTypeRepository.php:234` | Fires when a ticket type is created. |
| `nettertech_events_ticket_type_deleted` | action | 1.1.1 | `includes/Repositories/TicketTypeRepository.php:274`; `includes/Repositories/TicketTypeRepository.php:357` | Fires when a ticket type is deleted. |
| `nettertech_events_ticket_type_sync_product` | action | 1.0.2 | `includes/Services/BulkImportHandler.php:224`; `includes/Services/RecurrenceService.php:886` | Fires when a ticket type needs WooCommerce product synchronization. |
| `nettertech_events_ticket_type_updated` | action | 1.1.2 | `includes/Repositories/TicketTypeRepository.php:244` | Fires when a ticket type is updated. |
| `nettertech_events_ticket_types_saved` | action | 1.0.2 | `includes/Admin/Metaboxes/TicketSaveHandler.php:195`; `includes/Services/TicketTypeSaver.php:212` | Fires after ticket types are saved for an event/occurrence. |
| `nettertech_events_ticket_types_to_delete` | filter | 1.1.2 | `includes/Services/TicketTypeSaver.php:423` | Filters the tiers about to be deleted for not coming back with the ticket form. |
| `nettertech_events_tickets_refunded` | action | 1.0.2 | `includes/Integrations/WooCommerce/OrderRefundProcessor.php:232` | Fires when tickets are refunded (covers partial refunds). |
| `nettertech_events_forwarded_header` | filter | 1.4.0 | `includes/Services/ClientIpResolver.php:261` | Selects the single forwarded header trusted for generic (non-Cloudflare) proxies. Defaults to `HTTP_X_FORWARDED_FOR`; set to `HTTP_X_REAL_IP` for proxies that write X-Real-IP without appending to XFF. Exactly one header is trusted per trust reason (NTE-SEC-2026-07-A). |
| `nettertech_events_trusted_proxy_ranges` | filter | 1.1.2 | `includes/Services/ClientIpResolver.php:226` | Filters the CIDR ranges of trusted reverse proxies for client IP resolution. |
| `nettertech_events_waitlist_email_body` | filter | 1.0.2 | `includes/Services/WaitlistEmailHandler.php:183` | Filters the waitlist promotion email body. |
| `nettertech_events_waitlist_email_subject` | filter | 1.0.2 | `includes/Services/WaitlistEmailHandler.php:156` | Filters the waitlist promotion email subject. |
| `nettertech_events_waitlist_entry_joined` | action | 1.0.2 | `includes/API/WaitlistRestController.php:245` | Fires after a visitor joins a waitlist via the REST API. |
| `nettertech_events_waitlist_joined` | action | 1.0.2 | `includes/Services/WaitlistService.php:106` | Fires when someone joins the waitlist. |
| `nettertech_events_waitlist_leave_authorized` | filter | 1.0.2 | `includes/API/WaitlistRestController.php:173` | Filters authorization for a waitlist leave request. |
| `nettertech_events_waitlist_leave_token_ttl` | filter | 1.0.2 | `includes/API/WaitlistRestController.php:642` | Filters the waitlist leave-token lifetime in seconds. |
| `nettertech_events_waitlist_left` | action | 1.0.2 | `includes/Services/WaitlistService.php:136` | Fires when someone leaves the waitlist. |
| `nettertech_events_waitlist_notification_sent` | action | 1.0.2 | `includes/Services/WaitlistEmailHandler.php:204` | Fires after a waitlist notification email is sent (or attempted). |
| `nettertech_events_waitlist_promoted` | action | 1.0.2 | `includes/Services/WaitlistService.php:213` | Fires when a waitlist entry is promoted (next in line). |
| `nettertech_events_waitlist_status_authorized` | filter | 1.0.2 | `includes/API/WaitlistRestController.php:320` | Filters whether a waitlist status query may reveal the real status. |

### Dynamic hooks

| Hook | Type | Fired at | Description |
|---|---|---|---|
| `get_template_part_{$slug}` | action | `includes/TemplateLoader/TemplateLoader.php:221` | WordPress-core-compatible template part hook, fired with the resolved slug and name so integrations that hook WordPress's own template-part actions also see plugin template parts. |

### Cron action hooks

Fired by WP-Cron on schedule, not by plugin code paths. Subscribing works like any action, but these are scheduled tasks rather than extension points.

| Hook | Schedule origin | Description |
|---|---|---|
| `nettertech_events_daily_cleanup` | `includes/Core/ActivityLogHooks.php (daily schedule)` | Cron hook for daily activity log cleanup. |
| `nettertech_events_generate_occurrences` | `includes/Core/Activator.php (schedule)` | Cron hook for generating occurrences. |
| `nettertech_events_ical_static_regenerate` | `includes/Services/ICalFeedRegenerationListener.php (debounced single event)` | One-off cron hook for (re)building the static iCal feed file (NTE-016). |
| `nettertech_events_purge_activity_log_pii` | `includes/Core/PrivacyHooks.php (daily schedule)` | Cron hook for purging activity log PII. |
| `nettertech_events_send_reminder_emails` | `includes/Core/ReminderEmailHooks.php (hourly schedule)` | Cron hook for sending reminder emails. |
| `nettertech_events_sweep_expired_reservations` | `includes/Core/Plugin.php (schedule)` | Cron hook for sweeping expired capacity reservations. |

### Extension-fired hooks

Core registers listeners for these but never fires them itself; extension or tooling plugins fire them and core reacts.

| Hook | Fired by | Core listener | Description |
|---|---|---|---|
| `nettertech_events_bulk_import_completed` | import tooling (e.g. the Migrator plugin) | `includes/Services/BulkImportHandler.php` | Fires after a bulk import batch completes; core performs cache, log, and stock housekeeping. |
| `nettertech_events_events_exported` | export tooling | `includes/Core/ActivityLogHooks.php` | Fires after events are exported; core records an activity-log entry. |
| `nettertech_events_checkin_lookup_data` | Pro plugin (check-in scan controller) | (none in core; Seating subscribes) | Filters the check-in lookup response data; core reserves the name as part of the cross-plugin contract. |
| `nettertech_events_checkin_search_item` | Pro plugin (check-in scan controller) | (none in core; Seating subscribes) | Filters a check-in search result item; core reserves the name as part of the cross-plugin contract. |

### Reserved hook names

Declared in `includes/Core/Hooks.php` but not currently fired by anything. Do not subscribe to these expecting delivery; they are reserved so the names stay stable if wired later.

| Name | Note |
|---|---|
| `nettertech_events_attendee_cancelled` | Attendee removal in the WooCommerce order flow fires `nettertech_events_registration_voided` instead. |
| `nettertech_events_ticket_type_saved` | Use `nettertech_events_ticket_type_created` / `_updated` (per entity) or `nettertech_events_ticket_types_saved` (batch). |

---

## Hook Naming Conventions

All hooks follow these conventions:

| Pattern | Example | Description |
|---------|---------|-------------|
| `nettertech_events_{action}` | `nettertech_events_init` | General plugin actions |
| `nettertech_events_before_{action}` | `nettertech_events_before_save_event` | Pre-operation hooks |
| `nettertech_events_after_{action}` | `nettertech_events_after_save_event` | Post-operation hooks |
| `nettertech_events_{object}_{action}` | `nettertech_events_capacity_reserved` | Object-specific actions |
| `nettertech_events_{filter}` | `nettertech_events_template_args` | Data filters |

---

## Best Practices

### 1. Check Hook Availability

```php
// Wait for plugin initialization
add_action( 'nettertech_events_init', function() {
    // Safe to use all plugin functionality here
} );
```

### 2. Use Appropriate Priority

```php
// Run before other callbacks (default is 10)
add_action( 'nettertech_events_after_save_event', 'my_handler', 5 );

// Run after other callbacks
add_action( 'nettertech_events_after_save_event', 'my_cleanup', 20 );
```

### 3. Handle Errors Gracefully

```php
add_action( 'nettertech_events_after_save_event', function( $event ) {
    try {
        external_api_sync( $event );
    } catch ( Exception $e ) {
        // Log but don't break the save operation
        error_log( 'External sync failed: ' . $e->getMessage() );
    }
} );
```

### 4. Document Your Hooks

When using hooks in themes or plugins, document which hooks you're using for future maintenance.

---

## See Also

- [Template Override Guide](./TEMPLATE-OVERRIDE.md) — Customize plugin templates
- [Admin Manual](./ADMIN-MANUAL.md) — Admin documentation
- [Contributing Guide](../CONTRIBUTING.md) — Development guidelines
