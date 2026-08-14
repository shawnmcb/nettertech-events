# NetterTech Events Accessibility Statement

> **Audience:** Theme developers, site administrators, and anyone evaluating the plugin's WCAG conformance. Describes what the plugin delivers out of the box and what you should not undo when customizing.

**Version:** 1.1.0
**Last Updated:** 2026-04-06
**Compliance Target:** WCAG 2.2 Level AA

---

## Commitment to Accessibility

NetterTech Events is committed to ensuring digital accessibility for people with disabilities. We continually improve the user experience for everyone and apply the relevant accessibility standards.

## Conformance Status

NetterTech Events has been tested for conformance with **WCAG 2.2 Level AA** standards and has achieved the following results:

| Standard | Status | Notes |
|----------|--------|-------|
| WCAG 2.1 Level A | Conformant | All applicable criteria met |
| WCAG 2.1 Level AA | Conformant | All applicable criteria met |
| WCAG 2.2 Level AA (new criteria) | Conformant | All 6 new criteria met |
| WCAG 2.1 Level AAA | Partially Conformant | Selected criteria implemented |

### WCAG 2.2 AA New Criteria

| Criterion | ID | Status | Evidence |
|-----------|----|--------|----------|
| Focus Not Obscured (Minimum) | 2.4.11 | Conformant | No sticky/fixed plugin elements obscure focus. Header scrolls with page. `scroll-padding-top: 32px` compensates for WP admin bar. |
| Dragging Movements | 2.5.7 | Conformant | Layout editor (settings + per-event) provides Alt+Arrow keyboard navigation alongside drag-and-drop. No other drag interactions in plugin. |
| Target Size (Minimum) | 2.5.8 | Conformant | All plugin interactive targets >= 24x24 CSS px. Smallest: breadcrumb (21px height, passes via spacing exception at 41px to nearest target), calendar "+N more" buttons (24.4px height). |
| Consistent Help | 3.2.6 | Conformant | Plugin does not implement help mechanisms on public pages. Optional frontend branding is disabled by default. |
| Redundant Entry | 3.3.7 | Conformant | RSVP is single-step. Ticket purchase delegates to WooCommerce. Check-in uses URL tokens. No re-entry required. |
| Accessible Authentication (Minimum) | 3.3.8 | Conformant | No CAPTCHA, puzzles, or cognitive function tests. Check-in uses URL-based tokens (copy/paste or QR scan). |

### Testing Methodology

- **Automated Testing:** axe-core via @axe-core/playwright
- **Manual Testing:** Keyboard navigation, screen reader (VoiceOver), getComputedStyle() target size verification
- **Browser Testing:** Chrome 120+, Firefox 120+, Safari 17+
- **WCAG 2.2 Audit Date:** 2026-04-06
- **WCAG 2.1 Test Date:** 2026-01-18

### Automated Test Results

| Test Category | Result |
|---------------|--------|
| Critical violations | 0 |
| Serious violations | 0 |
| Moderate violations | 0 |
| Minor violations | 0 |

---

## Accessibility Features

### Keyboard Navigation

All interactive elements are fully keyboard accessible:

- **Tab navigation** through all controls in logical order
- **Enter/Space** to activate buttons and links
- **Arrow keys** for dropdown menus and comboboxes
- **Escape** to close dialogs and dropdowns
- **Skip link** to bypass navigation and jump to main content

### Screen Reader Support

- Semantic HTML5 elements (`<main>`, `<nav>`, `<article>`, `<header>`, `<footer>`)
- ARIA landmarks for content regions
- ARIA labels on form controls and interactive widgets
- Live regions (`aria-live`) for dynamic content updates
- Descriptive link text (no "click here" patterns)

### Visual Design

- **Color contrast:** All text meets 4.5:1 minimum contrast ratio (AA)
- **Focus indicators:** Visible focus states on all interactive elements
- **Text resizing:** Content remains usable up to 200% zoom
- **Reduced motion:** Respects `prefers-reduced-motion` media query

### Forms and Controls

- All form inputs have associated labels
- Error messages are linked to inputs via `aria-describedby`
- Required fields are indicated both visually and programmatically
- Form validation errors are announced to screen readers

---

## Plugin Components

### Events List (`[nettertech_events_list]`)

| Feature | Implementation |
|---------|----------------|
| Heading hierarchy | H1 for page title, H2 for event titles |
| Skip link | "Skip to content" link at page start |
| Filter controls | Labeled search box and category dropdown |
| Pagination | Button elements with disabled state support |
| Event cards | Article elements with accessible link text |

### Single Event Page

| Feature | Implementation |
|---------|----------------|
| Semantic structure | `<article>` wrapper with proper headings |
| Date/Time | Uses `<time>` elements with `datetime` attribute |
| Images | All images have alt text via WordPress media library |
| Registration forms | Labeled inputs with validation feedback |

### Series Page (Recurring Events)

| Feature | Implementation |
|---------|----------------|
| Occurrence list | `role="list"` with proper list semantics |
| Navigation | Aria-labels on occurrence links |
| Current date indicator | Visual + screen reader text "(current)" |

### Calendar Widget

| Feature | Implementation |
|---------|----------------|
| Grid role | `role="grid"` for calendar table |
| Arrow key navigation | Move between days |
| Date selection | Enter to select focused date |
| Month navigation | Previous/Next buttons with labels |

### Check-in Interface

| Feature | Implementation |
|---------|----------------|
| QR Scanner | Status announcements via `aria-live` |
| Attendee list | Table with proper headers |
| Toggle buttons | `aria-pressed` state for check-in status |

---

## Known Limitations

1. **Calendar keyboard navigation** is limited to basic Tab/Enter on dates. Full arrow key navigation is planned for a future release.

2. **Third-party content** (embedded videos, external iframes) may not be fully accessible. We recommend adding captions and transcripts for any embedded media.

3. **PDF tickets** generated by the plugin include text content but may not be fully tagged for screen readers.

---

## Testing Your Content

When adding events with NetterTech Events, follow these guidelines for accessible content:

### Images

- Always add alt text describing the image content
- For decorative images, use empty alt text (`alt=""`)
- Featured images should describe the event visually

### Event Descriptions

- Use heading levels correctly (H2, H3, H4 in order)
- Don't skip heading levels
- Write descriptive link text (not "click here")
- Provide text alternatives for any images in descriptions

### Videos

- Add captions to all videos
- Provide transcripts for audio content
- Ensure video players have keyboard controls

---

## Feedback

We welcome feedback on the accessibility of NetterTech Events. Please contact us if you:

- Encounter accessibility barriers
- Need content in an alternative format
- Have suggestions for improvement

**Contact:** [Plugin support channel]

---

## Technical Standards

This plugin follows these accessibility guidelines and standards:

- [WCAG 2.2](https://www.w3.org/WAI/WCAG22/quickref/)
- [WAI-ARIA 1.2](https://www.w3.org/TR/wai-aria-1.2/)
- [WordPress Accessibility Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/accessibility/)
- [ARIA Authoring Practices Guide](https://www.w3.org/WAI/ARIA/apg/)

---

## Changelog

### 1.1.0 (2026-04-06)
- Upgraded compliance target from WCAG 2.1 to WCAG 2.2 Level AA
- Audited and confirmed conformance for all 6 new WCAG 2.2 AA criteria
- Verified target sizes via getComputedStyle() measurement
- Confirmed keyboard alternatives for layout editor drag-and-drop

### 1.0.0 (2026-02-05)
- Updated version to 1.0.0
- Verified zero WCAG 2.1 AA violations for v1.0 release

### 0.9.0 (2026-01-18)
- Initial accessibility documentation
- axe-core automated testing integrated
- Zero WCAG 2.1 AA violations in plugin elements
- Keyboard navigation validated
- Screen reader support documented
