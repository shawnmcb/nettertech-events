# ADR-011: Theme-Overridable Template System

**Date:** 2025-12-27
**Status:** Accepted
**Category:** Frontend Architecture

## Context

The plugin renders public-facing pages (event listings, single events, ticket views, check-in pages, calendar). These need to:

1. Look acceptable with any WordPress theme out of the box
2. Be fully customizable by theme developers
3. Pass structured data to templates without global state pollution

WordPress's `get_template_part()` doesn't support plugin template overrides. The standard pattern in the ecosystem (WooCommerce, EDD) is a custom template loader that checks the theme directory first, then falls back to the plugin's templates.

## Decision

**Use a custom `TemplateLoader` with WordPress 5.5+ `$args` parameter support and theme override resolution.**

### Template Resolution Order

```
1. {theme}/nettertech-events/{template}.php       (child theme override)
2. {parent-theme}/nettertech-events/{template}.php (parent theme override)
3. {plugin}/templates/{template}.php          (plugin default)
```

### Architecture

```
TemplateLoader/
  ├── TemplateLoaderConfig.php    # Value object for configuration
  ├── TemplateResolver.php        # Path resolution logic
  ├── TemplateResolverInterface.php
  ├── TemplateLoader.php          # load_template() wrapper
  └── TemplateLoaderInterface.php
```

### Data Passing

Uses WordPress 5.5+ native `$args` parameter:

```php
$loader->render('single-event', [
    'event'       => $event,
    'occurrences' => $occurrences,
    'ticket_types' => $ticket_types,
]);

// In template: $args['event']->title
```

Legacy `set_template_data()` method preserved but deprecated for backward compatibility.

### Template Inventory

```
templates/
  ├── single-event.php           # Single event page
  ├── archive-events.php         # Event listing/archive
  ├── series-page.php            # Series landing page
  ├── ticket/
  │   └── public-ticket.php      # Ticket view page
  ├── checkin/
  │   ├── public-checkin.php     # Check-in interface
  │   └── partials/
  │       └── scanner-panel.php  # QR scanner component
  └── email/
      ├── confirmation.php       # Order confirmation email
      └── partials/              # Email template parts
```

### Classes Are Final

`TemplateLoader` and `TemplateResolver` are `final` classes configured via constructor, not extended via inheritance. This prevents the fragile base class problem common in WordPress template loaders (Gamajo pattern).

## Alternatives Considered

### Gutenberg Blocks Only

- **Pros**: Modern WordPress editing experience. React-based. Full Site Editing compatible.
- **Cons**: Not all content is block-editable (transactional pages like ticket view, check-in). Blocks don't solve the template override problem for full pages. Many themes don't support Full Site Editing.

### Shortcodes Only

- **Pros**: Simple. Works in any theme. Familiar to WordPress users.
- **Cons**: Limited layout control. No template hierarchy. Output buffering required. Poor developer experience for customization.

### Gamajo Template Loader (Class Extension)

- **Pros**: Widely used pattern in WordPress plugins.
- **Cons**: Requires extending a base class. Fragile base class problem — internal methods can't change without breaking extensions. PHP 5.2 era design (no type declarations, no interfaces).

## Consequences

- **Positive**: Theme developers can override any template by copying to their theme directory. Structured data via `$args` (no globals). `final` classes prevent fragile inheritance. Interfaces enable testing.
- **Negative**: Theme developers must discover the template override pattern (documented in `docs/TEMPLATE-OVERRIDE.md`). More complex than shortcodes for simple embeds.
- **Mitigations**: Plugin also provides shortcodes (`[ve_calendar]`, `[ve_rsvp_form]`, `[ve_carousel]`) for users who don't need full template control.

## Related

- `includes/TemplateLoader/`: Template system implementation
- `templates/`: Plugin default templates
- `docs/TEMPLATE-OVERRIDE.md`: Theme developer documentation
