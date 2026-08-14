# Template Override Guide

> **Audience:** Theme developers customizing the visual output of events, occurrences, tickets, and emails. Shows how to copy the plugin's templates into your theme and override them safely across plugin updates.

Customize NetterTech Events templates in your WordPress theme without modifying plugin files.

---

## Overview

NetterTech Events uses a template system that allows theme developers to override any template file. The plugin searches for templates in this order:

1. **Child theme:** `wp-content/themes/your-child-theme/nettertech-events/`
2. **Parent theme:** `wp-content/themes/your-parent-theme/nettertech-events/`
3. **Plugin:** `wp-content/plugins/nettertech-events/templates/`

This means you can customize any template by copying it to your theme and modifying it there.

---

## Quick Start

### 1. Create the Theme Directory

In your theme (or child theme), create a `nettertech-events` folder:

```
your-theme/
├── functions.php
├── style.css
└── nettertech-events/           ← Create this folder
    └── (your overrides go here)
```

### 2. Copy a Template

Copy the template you want to customize from the plugin:

```bash
# Example: Override the event card
cp wp-content/plugins/nettertech-events/templates/parts/event-card.php \
   wp-content/themes/your-theme/nettertech-events/parts/event-card.php
```

### 3. Customize

Edit the copied file in your theme. Your changes will be used instead of the plugin's version.

---

## Available Templates

29 templates are theme-overridable. Admin-screen templates under `templates/admin/`
load via direct `include()` and are not part of the override system.

### Main Templates

| Template | Path | Purpose |
|----------|------|---------|
| Single Event | `single-event.php` | Individual event page |
| Series Page | `series-page.php` | Recurring event series overview |
| Event Archive | `archive-events.php` | Events listing page |
| Past Events | `archive-past-events.php` | Past events archive |
| Single Space | `single-space.php` | Individual venue/space page |

### Template Parts

| Template | Path | Purpose |
|----------|------|---------|
| Event Card | `parts/event-card.php` | Card display in grids and lists |
| Occurrence Row | `parts/occurrence-row.php` | Row in occurrence lists |
| Event Filters | `parts/event-filters.php` | Category/search filters |
| Pagination | `parts/pagination.php` | Page navigation |
| Empty State | `parts/empty-state.php` | "No events found" message |
| More Dates | `parts/more-dates.php` | "More dates available" display |
| Event Breadcrumb | `parts/event-breadcrumb.php` | "Back to Events" navigation link |
| Regulars Table | `parts/regulars-table.php` | Weekly recurring events grouped by day |
| Ticket Form | `parts/ticket-form.php` | Multi-ticket-type purchase form with running total |
| Waitlist Panel | `parts/waitlist-panel.php` | Waitlist signup panel for sold-out ticket types |

### Single Event Parts

| Template | Path | Purpose |
|----------|------|---------|
| Header | `parts/single-event-header.php` | Event title and meta |
| Image | `parts/single-event-image.php` | Featured image display |
| Description | `parts/single-event-description.php` | Event content |
| Occurrence | `parts/single-event-occurrence.php` | Single occurrence details |
| Upcoming | `parts/single-event-upcoming.php` | Upcoming dates list |
| More Dates | `parts/single-event-more-dates.php` | Additional dates panel |

### Email Templates

| Template | Path | Purpose |
|----------|------|---------|
| Customer Confirmation | `emails/customer-confirmation.php` | Ticket purchase email |
| RSVP Confirmation | `emails/rsvp-confirmation.php` | RSVP registration email |
| Venue Notification | `emails/venue-notification.php` | Staff order notification |
| RSVP Venue Notification | `emails/rsvp-venue-notification.php` | Staff RSVP notification |
| Event Reminder | `emails/event-reminder.php` | Pre-event reminder email |
| Waitlist Promotion | `emails/waitlist-promotion.php` | Sent when a waitlisted customer is promoted |

### Ticket Templates

| Template | Path | Purpose |
|----------|------|---------|
| Public Ticket | `ticket/public-ticket.php` | Printable ticket view |
| Scan Result | `ticket/scan-result.php` | QR scan result display |

---

## Template Variables

Each template receives specific variables. These are documented in the template files themselves and below.

### Event Card (`parts/event-card.php`)

| Variable | Type | Description |
|----------|------|-------------|
| `$occurrence` | Occurrence | The occurrence being displayed |
| `$event` | Event | The parent event |
| `$show_image` | bool | Whether to show featured image |
| `$show_date` | bool | Whether to show date |
| `$show_time` | bool | Whether to show time |
| `$show_venue` | bool | Whether to show venue info |
| `$show_excerpt` | bool | Whether to show description excerpt |
| `$prefetched_availability` | array | Optional availability verdict (`'sold_out' => bool`), supplied by listing controllers when an extension opts in via the `nettertech_events_cards_need_availability` filter. Exposed as the `nte-event-card--sold-out` class and to the `nettertech_events_event_card_status` action; base renders no label itself. |

**Example usage:**
```php
<?php
// parts/event-card.php in your theme

/** @var NetterTechEvents\Models\Occurrence $occurrence */
/** @var NetterTechEvents\Models\Event $event */
/** @var bool $show_image */
?>

<article class="my-event-card">
    <?php if ( $show_image && $event->get_featured_image_url() ) : ?>
        <img src="<?php echo esc_url( $event->get_featured_image_url( 'medium' ) ); ?>"
             alt="<?php echo esc_attr( $event->get_title() ); ?>">
    <?php endif; ?>

    <h3>
        <a href="<?php echo esc_url( $occurrence->get_permalink() ); ?>">
            <?php echo esc_html( $event->get_title() ); ?>
        </a>
    </h3>

    <?php if ( $show_date ) : ?>
        <time datetime="<?php echo esc_attr( $occurrence->get_start_datetime()->format( 'c' ) ); ?>">
            <?php echo esc_html( $occurrence->get_formatted_date() ); ?>
        </time>
    <?php endif; ?>
</article>
```

### Occurrence Row (`parts/occurrence-row.php`)

| Variable | Type | Description |
|----------|------|-------------|
| `$occurrence` | Occurrence | The occurrence |
| `$event` | Event | The parent event |
| `$show_actions` | bool | Whether to show action buttons |

### Pagination (`parts/pagination.php`)

| Variable | Type | Description |
|----------|------|-------------|
| `$current_page` | int | Current page number |
| `$total_pages` | int | Total number of pages |
| `$instance_id` | string | Unique identifier for this instance |
| `$show_info` | bool | Whether to show "Page X of Y" |
| `$base_url` | string | Base URL for pagination links |

### Event Filters (`parts/event-filters.php`)

| Variable | Type | Description |
|----------|------|-------------|
| `$instance_id` | string | Unique identifier |
| `$target_id` | string | ID of element to filter |
| `$show_search` | bool | Whether to show search input |
| `$show_category` | bool | Whether to show category dropdown |
| `$categories` | array | Available categories |

### Empty State (`parts/empty-state.php`)

| Variable | Type | Description |
|----------|------|-------------|
| `$message` | string | Message to display |
| `$context` | string | Context (e.g., 'archive', 'search') |

### Email Templates

All email templates receive:

| Variable | Type | Description |
|----------|------|-------------|
| `$site_name` | string | WordPress site name |
| `$site_url` | string | WordPress site URL |

**Customer Confirmation** additional variables:

| Variable | Type | Description |
|----------|------|-------------|
| `$order` | WC_Order | WooCommerce order object |
| `$tickets` | array | Array of ticket data |
| `$grouped_tickets` | array | Tickets grouped by occurrence |
| `$venue_logo` | string | Logo URL for header |
| `$show_qr_codes` | bool | Whether to include QR codes |
| `$cancellation_policy` | string | Cancellation policy HTML |

**RSVP Confirmation** additional variables:

| Variable | Type | Description |
|----------|------|-------------|
| `$ticket` | array | Ticket data |
| `$occurrence` | Occurrence | The occurrence |
| `$event` | Event | The event |
| `$attendee_name` | string | Attendee's name |

**Event Reminder** additional variables:

| Variable | Type | Description |
|----------|------|-------------|
| `$occurrence` | Occurrence | The occurrence being reminded about |
| `$event` | Event | The parent event |
| `$attendee` | Attendee | The attendee receiving the reminder |
| `$venue_logo` | string | Logo URL for header |
| `$event_url` | string | Permalink to the event page |

---

## Code Examples

### Example 1: Custom Event Card

Create `nettertech-events/parts/event-card.php` in your theme:

```php
<?php
/**
 * Custom event card template.
 *
 * @var NetterTechEvents\Models\Occurrence $occurrence
 * @var NetterTechEvents\Models\Event $event
 */

$image_url = $event->get_featured_image_url( 'large' );
?>

<article class="custom-event-card">
    <?php if ( $image_url ) : ?>
        <div class="custom-event-card__image"
             style="background-image: url('<?php echo esc_url( $image_url ); ?>')">
        </div>
    <?php endif; ?>

    <div class="custom-event-card__content">
        <span class="custom-event-card__category">
            <?php echo esc_html( $event->get_category_name() ); ?>
        </span>

        <h3 class="custom-event-card__title">
            <a href="<?php echo esc_url( $occurrence->get_permalink() ); ?>">
                <?php echo esc_html( $event->get_title() ); ?>
            </a>
        </h3>

        <div class="custom-event-card__meta">
            <span class="custom-event-card__date">
                <?php echo esc_html( $occurrence->get_formatted_date() ); ?>
            </span>
            <span class="custom-event-card__time">
                <?php echo esc_html( $occurrence->get_formatted_time() ); ?>
            </span>
        </div>

        <?php if ( $event->get_venue_name() ) : ?>
            <div class="custom-event-card__venue">
                <?php echo esc_html( $event->get_venue_name() ); ?>
            </div>
        <?php endif; ?>

        <a href="<?php echo esc_url( $occurrence->get_permalink() ); ?>"
           class="custom-event-card__button">
            <?php esc_html_e( 'View Details', 'your-theme' ); ?>
        </a>
    </div>
</article>
```

### Example 2: Custom Empty State

Create `nettertech-events/parts/empty-state.php`:

```php
<?php
/**
 * Custom empty state template.
 *
 * @var string $message
 * @var string $context
 */
?>

<div class="custom-empty-state">
    <svg class="custom-empty-state__icon" viewBox="0 0 24 24">
        <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10z"/>
    </svg>

    <h3 class="custom-empty-state__title">
        <?php esc_html_e( 'No Events Found', 'your-theme' ); ?>
    </h3>

    <p class="custom-empty-state__message">
        <?php echo esc_html( $message ); ?>
    </p>

    <?php if ( 'search' === $context ) : ?>
        <p class="custom-empty-state__suggestion">
            <?php esc_html_e( 'Try adjusting your search or browse all events.', 'your-theme' ); ?>
        </p>
    <?php endif; ?>

    <a href="<?php echo esc_url( home_url( '/events/' ) ); ?>"
       class="custom-empty-state__button">
        <?php esc_html_e( 'View All Events', 'your-theme' ); ?>
    </a>
</div>
```

### Example 3: Custom Email Header

Create `nettertech-events/emails/customer-confirmation.php`:

```php
<?php
/**
 * Custom customer confirmation email.
 *
 * @var WC_Order $order
 * @var array $tickets
 * @var string $venue_logo
 * @var bool $show_qr_codes
 * @var string $cancellation_policy
 * @var string $site_name
 */
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        /* Your custom email styles */
        body { font-family: Arial, sans-serif; }
        .header { background: #1a1a2e; color: white; padding: 20px; }
        .content { padding: 20px; }
        .ticket { border: 1px solid #ddd; padding: 15px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="header">
        <?php if ( $venue_logo ) : ?>
            <img src="<?php echo esc_url( $venue_logo ); ?>" alt="" height="50">
        <?php endif; ?>
        <h1><?php esc_html_e( 'Your Tickets', 'your-theme' ); ?></h1>
    </div>

    <div class="content">
        <p>
            <?php
            printf(
                /* translators: %s: Customer first name */
                esc_html__( 'Hi %s, thank you for your order!', 'your-theme' ),
                esc_html( $order->get_billing_first_name() )
            );
            ?>
        </p>

        <?php foreach ( $tickets as $ticket ) : ?>
            <div class="ticket">
                <h3><?php echo esc_html( $ticket['event_title'] ); ?></h3>
                <p><strong><?php esc_html_e( 'Date:', 'your-theme' ); ?></strong>
                   <?php echo esc_html( $ticket['date'] ); ?></p>
                <p><strong><?php esc_html_e( 'Ticket:', 'your-theme' ); ?></strong>
                   <?php echo esc_html( $ticket['ticket_type'] ); ?></p>

                <?php if ( $show_qr_codes && ! empty( $ticket['qr_code'] ) ) : ?>
                    <img src="<?php echo esc_url( $ticket['qr_code'] ); ?>"
                         alt="<?php esc_attr_e( 'Ticket QR Code', 'your-theme' ); ?>"
                         width="150">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ( $cancellation_policy ) : ?>
            <div class="policy">
                <?php echo wp_kses_post( $cancellation_policy ); ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
```

---

## Using Hooks with Templates

### Add Data to Templates

```php
// In your theme's functions.php
add_filter( 'nettertech_events_template_args', function( $args, $file ) {
    // Add custom data to event card
    if ( str_contains( $file, 'event-card.php' ) ) {
        $args['custom_field'] = get_option( 'my_custom_field' );
    }
    return $args;
}, 10, 2 );
```

### Modify Template Search Order

```php
// Add a custom template directory with higher priority
add_filter( 'nettertech_events_template_paths', function( $paths ) {
    // Priority 5 = higher than parent theme (10)
    $paths[5] = get_stylesheet_directory() . '/custom-venue-templates/';
    return $paths;
} );
```

### Conditionally Load Templates

```php
// Use different template for specific category (via URL query parameter)
add_filter( 'nettertech_events_get_template_part', function( $templates, $slug, $name ) {
    if ( $slug === 'parts/event-card' && isset( $_GET['category'] ) && $_GET['category'] === 'concerts' ) {
        array_unshift( $templates, 'parts/event-card-concert.php' );
    }
    return $templates;
}, 10, 3 );
```

---

## Best Practices

### 1. Copy the Entire Template

Always copy the full template file, not just parts. This ensures you have all the markup and don't break functionality.

### 2. Maintain Accessibility

The default templates follow WCAG guidelines. When customizing:
- Keep proper heading hierarchy
- Maintain focus indicators
- Preserve ARIA attributes
- Test with screen readers

### 3. Preserve Required Markup

Some elements are required for JavaScript functionality:
- Elements with `data-` attributes
- Elements with specific class names used by JS
- Form inputs with specific names

### 4. Test Thoroughly

After customizing:
- Test on multiple screen sizes
- Test with and without JavaScript
- Verify email rendering in multiple clients
- Check that check-in functionality still works

### 5. Document Your Overrides

Create a README in your theme's `nettertech-events` folder documenting which templates you've overridden and why.

### 6. Check After Plugin Updates

After updating NetterTech Events, check for template changes that might affect your overrides. The plugin follows semantic versioning:
- **Patch (1.0.x):** Safe, no template changes
- **Minor (1.x.0):** May add new variables (backwards compatible)
- **Major (x.0.0):** May have breaking template changes

---

## Troubleshooting

### Template Not Loading

1. Verify the file is in the correct location: `your-theme/nettertech-events/path/to/template.php`
2. Check file permissions (readable by web server)
3. Clear any caching plugins
4. Enable WP_DEBUG to see template resolution logs

### Variables Not Available

1. Check the documented variables for that template
2. Use the `nettertech_events_template_args` filter to inspect available variables
3. Verify you're not overwriting variables (use different names for custom vars)

### Styles Not Applying

1. Custom templates don't automatically include plugin styles
2. Add required CSS classes or enqueue your own styles
3. Check for CSS specificity conflicts

### Debug Template Loading

Enable debugging to see which templates are loaded:

```php
// In wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Then check `wp-content/debug.log` for `[NetterTechEvents TemplateLoader]` entries.

---

## See Also

- [Hooks Reference](./HOOKS.md) — All available actions and filters
- [Admin Manual](./ADMIN-MANUAL.md) — Admin documentation
- [Contributing Guide](../CONTRIBUTING.md) — Development guidelines
