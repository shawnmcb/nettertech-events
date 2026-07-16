# Changelog

All notable changes to NetterTech Events will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.2] - Unreleased

### Added

- **Twelve new extension hooks since 1.1.1**, all now registered as `Hooks` constants and documented: `nettertech_events_attendees_columns`, `_attendees_column_content`, and `_attendees_prime` (attendees-table extensibility), `_purchases_synopsis` (attendees summary-card slot), `_admin_ticket_rows`, `_ticket_row_fields`, `_ticket_types_to_delete`, `_sale_schedule_ui_available`, and `_on_sale_ticket_types` (ticket-tier admin and sale-window extensibility), `_product_cat_ids` (WooCommerce product category resolution), `_csp_script_src` (CSP script origins), and `_trusted_proxy_ranges` (client IP resolution behind proxies).
- **New lifecycle hooks now fired by core:** `nettertech_events_ticket_type_created` and `_ticket_type_updated` (fired from the ticket type repository on every creation and update path), `_attendee_checked_in` (fired on admin check-in), `_settings_updated` (fired with the changed setting keys after a settings save), and `_attendees_exported` (fired after an attendee CSV export). Activity-log listeners for these hooks were previously registered but could never fire.
- **Documentation completeness gate.** A structural test (`HooksDocumentationTest`) derives every fired hook from source, including template call sites, constant-referenced names, and cron schedules, and fails if any is missing from the hook reference. A hook can no longer ship undocumented.

### Changed

- **Hook reference rewritten as a complete reference.** The hook documentation now carries a Complete Hook Index covering every hook the plugin fires, with type, first shipped version, firing location, and description, plus explicit sections for dynamic, cron, extension-fired, and reserved hook names. Previously it was a curated subset (54 of ~145) and documented one AJAX action that did not exist.
- **Hook registry completed.** 31 hooks that fired without a `Hooks` constant are now registered with documented constants. Constants that are fired by extension tooling rather than core (`bulk_import_completed`, `events_exported`) and reserved names that nothing fires (`attendee_cancelled`, `ticket_type_saved`) are labeled as such.
- **`@since` tags normalized on hook docblocks.** Values now name the first public release containing the hook (floor 1.0.2, the first published version); 81 tags referenced internal or nonexistent versions (for example 2.2.0, 3.6.0).
- Cross-plugin contract documentation updated to current hook names and firing attributions.

### Removed

- Two activity-log listeners on hooks core never fires (`attendee_checked_in` was logged directly by the check-in handler; no core path fires `attendee_cancelled`). Check-in logging behavior is unchanged.
- A WooCommerce product-sync listener on the never-fired `ticket_type_saved` hook (sync runs via `ticket_type_sync_product`).

## [1.1.1] - 2026-06-11

Consolidates the internal 1.1.0.1–1.1.0.4 patch line into the first public
release since 1.1.0.

### Fixed

- **Block-theme header/footer rendered without layout styles (NTE-130).** `TemplateCompat` output `wp_head()` before rendering the theme's header/footer template parts, so the per-render layout styles (`wp-container-*`) those blocks generate entered the style engine store after core had already printed it — navigation and group blocks in the header/footer collapsed unstyled on plugin pages under block themes. Both parts now pre-render before `wp_head()` via the core template-part block, which also restores the semantic `<header>`/`<footer class="wp-block-template-part">` wrappers core emits.
- **Date row and RSVP/ticket actions missing on event-level views when Upcoming Dates is hidden (NTE-131).** The Current Date/Time layout component only rendered on occurrence-specific URLs, so events whose layout config hides `upcoming_dates` (saved by pre-rebrand versions, which stored per-event layout but never applied it at render) lost their date row — and with it the RSVP button — on `/events/{slug}/` pages. The component now falls back to the next upcoming occurrence when Upcoming Dates won't render it, never duplicating the same date.
- **RSVP form rendered expanded with a collapsed toggle state.** The form container's inline `display:none` was stripped by the template pipeline's `wp_kses()` (style attributes are not allowlisted), leaving the form visible while the toggle reported `aria-expanded="false"`. The toggle script now collapses the container at init — progressive enhancement: without JavaScript the form is simply visible and usable.
- **Organizer metabox was never wired in the event editor.** The constructor checked `isset()` on a parameter that did not exist, so the organizer metabox silently never rendered. The repository is now constructor-injected through `EventsPage` and the admin service provider, with regression tests pinning the wiring.
- **RSVP form accessibility attributes were stripped by the sanitization allowlist.** `aria-label` on `<form>` and `aria-required` on inputs — both emitted by the RSVP form — were silently removed by the template pipeline. The allowlist now carries the full ARIA set on forms and inputs, pinned by a new sanitization contract test.

### Changed

- **Fluid video embeds in event descriptions.** YouTube/Vimeo oEmbed iframes in single-event and series-page descriptions now fill the available content width at a 16:9 aspect ratio instead of WordPress's fixed 500px embed size. Scoped to the default video providers; operator-added embed origins keep their intrinsic size. Non-16:9 videos letterbox inside the frame.

### Security

- Settings save handler now checks capability before nonce, matching the project standard (defense-in-depth ordering; no exploitable gap existed).
- The public `/csp-report` endpoint is now rate-limited (10 reports/IP/minute) with body-size and per-field length caps, closing a debug-log flooding/injection vector.

### Internal

- **PHPStan gate restored after being silently dead since 2026-04-05.** An `ABSPATH` guard in the PHPStan-only `WordPressHookUsageProvider` made every analysis exit 0 before running. Guard removed; gate verified alive via planted-probe test (INV-M1); honest 438-error baseline established and documented in `phpstan.neon` as a debt ledger; 13 unparseable `@phpstan-ignore` comments repaired.
- Coherence invariants declared and elicited (`.coherence-invariants.md`, 18 invariants, ISO/IEC 25010:2023 taxonomy) with ADR-018 consolidating gate rationale.
- New release gates (`composer release:check`): asset budgets, pot freshness, CycloneDX SBOM, Playwright E2E, axe-core accessibility (WCAG 2.2 AA tags added). gitleaks secrets scan added to pre-push. Integration-suite memory floor encoded at 1G.
- New invariant tests: schema-migration idempotency, sanitization allowlist contract, structural REST permission_callback check.

## [1.1.0.1] - 2026-06-02

(Previously misfiled as "1.0.4 - Unreleased"; these changes shipped in the internal 1.1.0.1 build.)

### Added

- **Configurable video embed sources (Settings → Email & Advanced).** A new "Allowed Video Embed Sources" field lets operators extend the CSP `frame-src` allowlist beyond the built-in YouTube/Vimeo defaults so event-description videos from other providers render. One origin per line (`scheme://host`); paths are dropped to the origin. Validation on save: https origins are accepted; bare hosts, non-http(s) schemes, lone `*`, mid-host wildcards, and CSP keyword tokens are rejected with an admin notice; leading-subdomain wildcards (`https://*.example.com`) are accepted with a risk warning. A separate **"Allow insecure (HTTP) embed sources"** checkbox (off by default) gates `http://` origins — they are rejected on save unless it is enabled, and accepted with a mixed-content warning when it is. Stored in the `nettertech_events_settings` option (additive keys `allowed_embed_sources` / `allow_insecure_embed_sources`, default empty/false via `from_option()` fallback — no migration needed), merged into the public and report-only CSP via `SecurityHeaders::get_frame_src_directive()`, and still extensible in code through the `nettertech_events_csp_frame_src` filter. Validation logic lives in the pure, unit-tested `AdvancedSettingsSection::parse_embed_sources()`.
- **Carousel configurable max tags per card (NTE-063).** New `max_tags` attribute (integer, default `3`) caps the number of tags rendered per carousel card. When the event has more tags than the cap, a `…and N more` overflow indicator appears as a non-link `<span class="nte-tag-more">` styled distinctly from the tag pills so it reads as a count, not an extra tag. `max_tags=0` disables the cap and shows all tags with no overflow indicator. The attribute is wired through every embed surface: the Gutenberg block Inspector (a RangeControl 0–20 in the Display Options panel), the BB EventCarousel module settings form, the `[nettertech_events_carousel]` shortcode (default + sanitize), and the card template (`templates/parts/event-card.php`). Overflow string uses `_n()` for i18n pluralization. Sanitization uses `max(0, (int))` — negative values from shortcode input collapse to 0 (show all). Existing embeds default to `3`; operators who want the previous unlimited behavior set `max_tags=0`.
- **Beaver Themer integration: assignable header/footer layouts + field connections (NTE-071).** New `Settings → NetterTech Events → Beaver Themer` tab lets operators map any published Beaver Themer header or footer layout to each of the five NTE virtual contexts (events archive, past events archive, single event, single occurrence, single space). At render time, `LayoutInjector` hooks the `fl_theme_builder_current_page_layouts` filter (the supported extension seam in `bb-plugin/extensions/fl-theme-builder-core/`) and replaces the chosen slot with the operator's pick — NTE-context assignments win unconditionally over Themer's own "Entire site" rules. Stale assignments (layout deleted, unpublished, or `_fl_theme_layout_type` meta changed post-assignment) silently fall through; nothing fatals. Beaver Themer's built-in location matcher cannot target NTE pages directly because the shadow `nte_event` CPT is registered with `public=false, has_archive=false` and event/space URLs are served via `Frontend\Router`'s custom rewrites that don't trigger `is_post_type_archive()` or `is_singular()`. Also registers three FLPageData field-connection groups — "NetterTech Events: Event" (12 properties), "NetterTech Events: Space" (5), and "NetterTech Events: Archive" (2) — so layouts built against NTE contexts can bind dynamic text/photo modules to event title, description, featured image, venue, recurrence rule, virtual URL, archive title/context key, and the rest. Getters tolerate the Router-not-yet-initialized state thrown in admin preview contexts. Gated on `class_exists('FLThemeBuilder')` — no overhead when Themer is not installed. v1 ships header + footer slot assignments only; archive/singular full-template replacement deferred. Block-theme support deferred (existing `TemplateCompat::block_theme_header()` bypasses `get_header()`). 53 unit tests across 6 files.
- **Carousel playback mode (NTE-061).** New `playback_mode` attribute (`'rewind' | 'loop'`, default `'rewind'`) wired through every embed surface: the Gutenberg block Inspector (under Autoplay), the BB module settings form, and the `[nettertech_events_carousel]` shortcode. Rewind preserves the existing snap-back behavior; loop clones the leading cards onto the end of the strip so autoplay advances seamlessly without a visible jump. `prefers-reduced-motion: reduce` forces rewind at runtime regardless of the operator's choice — the JS reads `matchMedia('(prefers-reduced-motion: reduce)')` and skips the clones / nav-button override accordingly. Shortcode whitelists the value (anything outside `['rewind', 'loop']` falls back to `'rewind'`); the `data-playback-mode` attribute is `esc_attr()`-escaped on output. Existing embeds default to `'rewind'` so no current carousel changes behavior.
- **Timeframe-aware search placeholder (NTE-067).** The event list search input now shows context-specific placeholder text: "Search upcoming events…" on the upcoming-events list and "Search past events…" on the past-events archive. The screen-reader label follows the same pattern. Two new filter hooks — `nettertech_events_search_placeholder` and `nettertech_events_search_label` (documented in `Core/Hooks.php` as `Hooks::SEARCH_PLACEHOLDER` and `Hooks::SEARCH_LABEL`) — allow themes and add-ons to override both strings without forking the plugin. The past-events archive template already passes `past="true"` to the list shortcode, so the correct variant fires automatically.
- **Archive filter visibility toggles (NTE-069).** Five new checkboxes in Settings → Display → Archive Display control which filter elements appear on the `/events/` and past-events archive pages: Show filter bar (master toggle), Show search input, Show category filter, Show tag filter, Show date range filter. All five default to `true` for back-compat — existing sites see no change until the operator explicitly unchecks a box. Unchecking the master "Show filter bar" disables and dims the four sub-checkboxes via a vanilla-JS IIFE (no build step, no jQuery). Settings are wired through `DisplaySettings` DTO, hydrated with `! isset() || ! empty()` back-compat logic in `NetterTechEventsSettings::from_option()`, sanitized via `SettingsSanitizer::FIELD_CONFIGS`, and passed as `show_filters`, `show_search`, `show_category`, `show_tag`, and `show_date_range` shortcode attributes in both `templates/archive-events.php` and `templates/archive-past-events.php`. Custom pages built with the List block, shortcode, or Beaver Builder module are unaffected — those surfaces expose per-instance toggles.

  > **Translator note:** The previous hardcoded strings `Search events` and `Search events...` are replaced by four new `_x()` strings with translator-context comments (`Search upcoming events`, `Search upcoming events…`, `Search past events`, `Search past events…`). Existing translations targeting the old strings will no longer match — translators should map to the new strings. This is an intentional UX-clarity change, not a silent swap.
- **Multi-select + timeframe-limited taxonomy filters (NTE-068).** The category and tag controls in the list shortcode (and its block + Beaver Builder module surfaces) accept multiple values and only show terms with events in the current timeframe. `<select>` controls are now `<select multiple size="4" name="category[]" / "tag[]">` so users can express "Music OR Theater" without two page loads, and the dropdowns are scoped to terms attached to at least one `status='scheduled'` occurrence in the active timeframe — eliminating dead-end "Workshops" entries on upcoming-events views when Workshops only existed on past events. Repository signature gains an optional `?string $timeframe` argument (`'upcoming'` / `'past'` / `null` = unfiltered, with caller-mistake safety: unknown values collapse to `null`) on both `CategoryRepositoryInterface::get_all()` and `TagRepositoryInterface::get_all()`; all existing single-arg callers continue to work unchanged. Cache keys are branched per timeframe under the existing `nettertech_events` object-cache group, with hourly-bucket granularity so dropdowns naturally re-evaluate as time passes even without a save hook firing. `CacheManager` now hooks `nettertech_events_occurrence_created` / `_deleted` to close the single-occurrence-edit invalidation gap. Selected-term union: a category or tag preselected via shortcode attribute or URL state renders as a `selected` option even when no events in the active timeframe carry it — so toggling between Upcoming and Past doesn't silently drop the user's selection. Pre-filter shortcode attribute (`category="5,10"`, `tag="jazz,rock"`) accepts comma-separated lists; multi-value triggers SQL `IN()` OR semantics in `OccurrenceFilterRepository::build_tag_join()` (already supported for category). Single-value pre-filter and the scalar query-builder path are preserved unchanged for back-compat.

### Changed

- **Series-page active tab inherits date badge color (NTE-065).** `.nte-series-page__nav-current` background now uses the CSS custom-property fallback chain `var(--nte-date-bg, var(--nte-color-primary, #2563eb))`. When an operator sets a custom date badge color in Settings → Display, the Upcoming/Past selector active tab automatically matches the date badges on event cards — no additional configuration required. No change when the custom color is unchecked (falls through to the existing primary blue). Settings help text updated to note the WCAG AA 4.5:1 contrast requirement for white text on the chosen color.
- **Empty-state surfaces no longer render a gray card (NTE-066).** Removed `background` and `border-radius` from `.nte-empty` (grid.css) and `.nte-carousel--empty` (carousel.css). All "no events found" states — archive, list shortcode/block/BB module, regulars shortcode/block/BB module, and the carousel embed — now pass through to the underlying page background color. `grid-column: 1 / -1`, padding, and text-align are preserved so the empty message still spans the full grid width with breathing room.
- **Recurring events default to description-above-dates layout (NTE-062).** `LayoutService::get_hardcoded_default()` now accepts an optional event context. When the event is a recurring series, it returns `DEFAULT_ORDER_RECURRING` — placing `description` before `upcoming_dates` — so visitors read what a series is before scanning the date list. Single-occurrence events, events with a saved per-event layout, and events falling through to a customised global default are all unaffected. `SingleEventPageHandler::resolve()` passes the event through to `get_layout()`, which forwards it to `get_hardcoded_default()` only when the hardcoded fallback is reached.
- **Multi-select category/tag filters render as accessible disclosure widgets (NTE-075).** The native `<select multiple>` from NTE-068 was visually inconsistent with the other filter pills (stacked list-box rendering inside the form bar). Replaced with a WAI-ARIA Authoring Practices listbox-disclosure pattern: a button-styled trigger that opens a popup listbox of options. Trigger label reflects state — the category/tag label when nothing is selected, the selected option's name when exactly one is selected, and "N selected" when multiple are selected. Selected options display a checkmark via `::before` on `.nte-multiselect__option-check`. Keyboard interaction follows the WAI-ARIA listbox pattern (Tab to trigger, Enter/Space/ArrowDown opens, Arrow keys/Home/End navigate, Enter/Space toggles, Escape closes and returns focus to the trigger). `wp.a11y.speak()` announces selection toggles. The native `<select multiple>` is preserved underneath the custom UI (visually hidden via clip-rect; remains in the form) so URL/POST serialization keeps the same `category[]=X&category[]=Y` shape NTE-068 introduced. Implemented as vanilla JS IIFE with no build step and no jQuery dependency (`assets/dist/js/nte-multiselect.js`). Required adding `data-nte-multiselect-label` to the `ShortcodeOutput::get_allowlist()` select-attribute allowlist so `wp_kses()` doesn't strip the JS hook from rendered output.

### Fixed

- **YouTube/Vimeo video embeds in event descriptions silently disappeared.** A bare provider URL on its own line in an event description was auto-embedded by WordPress oEmbed into an `<iframe>`, then stripped before display by two independent layers. (1) **Output sanitization:** `single-event.php` and `series-page.php` sanitize the rendered description through `ShortcodeOutput::get_allowlist()`, which had no `iframe` entry, so `wp_kses()` removed the embed (leaving an empty wrapper); `single-event-description.php` also pre-stripped it via `wp_kses_post()`. Added an `iframe` entry to the allowlist (src/width/height/frameborder/allow/allowfullscreen/referrerpolicy/loading/title/name/class/style/sandbox), made the description part emit `the_content` output for the parent allowlist to sanitize, and switched the series template to the shared allowlist. Safe because author-pasted raw `<iframe>` is already stripped at the input boundary (`EventSaveHandler` `wp_kses_post()`), so only oEmbed-provider iframes reach output. (2) **CSP:** the public Content-Security-Policy had no `frame-src` directive, so it fell back to `default-src 'self'` and the browser blocked `youtube.com`/`vimeo.com` frames even when present. Added `SecurityHeaders::get_frame_src_directive()` allowlisting `https://www.youtube.com`, `https://www.youtube-nocookie.com`, and `https://player.vimeo.com` by default (applied to both the enforcing public CSP and the report-only admin CSP), filterable via the new `nettertech_events_csp_frame_src` hook with CR/LF stripping to prevent header injection. Surfaced on celticjunction.org single-event pages.
- **Search placeholder rendered literal `\xe2\x80\xa6` instead of the ellipsis character (NTE-074).** The NTE-067 placeholder source used single-quoted PHP strings containing `\xe2\x80\xa6` — but PHP single-quoted strings don't interpret escape sequences, so the 12 literal characters reached the browser instead of the intended `…` (U+2026). Switched the source strings to use the actual ellipsis character directly. Existing unit tests asserted only the prefix "Search upcoming events" / "Search past events"; new regression assertions explicitly check for the U+2026 character AND assert absence of the literal escape sequence. Visible on archive `/events/` filter input.

## [1.0.3] - 2026-05-25

Production-hardening release driven by a real-world TEC → NTE migration on celticjunction.org. Bundles a recurring-event ticket-persistence fix, a dormant-event horizon-extension guard, a new configurable defaults system in Settings → Display, plus several admin and template polish items. Suite at 6,051 tests / 18,629 assertions / 0 failures / 40 pre-existing skips. (The intermediate 1.0.2 tag carried internal release-prep work — vendor cleanup, CREDITS — and was not user-facing; this entry is the first changelog entry since 1.0.1.)

### Added

- **Settings → Display: Default Start Time + Default Duration (NTE-060).** Two site-tunable defaults flow through the entire create-event experience: `default_event_start_time` (ship: 19:00) and `default_event_duration_minutes` (ship: 120, floor-clamped at 10). The event editor's date-time metabox soft-fills blank start/end times against these values; EventSaveHandler's submit path honours them too. All-day events continue to use the 00:00/23:59 pattern regardless. Defaults are site-tunable in `Settings → Display`.
- **Events Page intro.** New `display.events_archive_intro` setting renders above the upcoming-events listing. Empty value falls back to the prior localized "Browse our upcoming events..." copy so existing sites see no change until they customize it.
- **`PathHelper::get_base_url()`** helper parallels the existing `get_archive_url()` and exposes the base events listing URL so templates don't hand-build URLs.
- **`docs/architecture/ADR-018-four-segment-internal-versioning.md`** — documents the four-segment internal versioning policy used during the 1.0.2.* cache-bust sequence; readme.txt Stable tag and WP.org submission stay at three segments.

### Fixed

- **Recurring events lost their ticket types on save.** `EventSaveHandler` delegated all ticket persistence through `process_occurrences()`, which only knows the per-occurrence scope; event-level (EVENT) and template-level (TEMPLATE) ticket scopes — the two scopes recurring events depend on — were silently dropped. Surfaced as the Beoga investigation on celticjunction.org where a 28-ticket recurring event showed 13 tickets after import. `TicketTypeSaver::save_for_event()` handles event-scope persistence directly, processing both EVENT and TEMPLATE scopes from the scope-nested form payload. `TicketsMetabox` renders a `nte_tickets_metabox_rendered` hidden input that `save_for_event()` uses as the "form actually shipped" gate, avoiding destructive deletes when the metabox was hidden / unrendered.
- **Dormant-event guard for past-only recurring series.** `OccurrenceHorizonExtender` previously extended every recurring event with an unbounded RRULE, including events whose latest occurrence was already in the past. Migrated stale series (TEC events imported with a still-open RRULE but a real-world end before migration) sprouted phantom future occurrences across the horizon. Policy fix: an event whose latest occurrence is in the past is treated as concluded regardless of the recurrence rule. Replicates the celticjunction.org "Harp Resonance Sessions" case.
- **Recurrence generation-window time-of-day anchor.** `RecurrenceService::calculate_generation_window()` now anchors generation at the event's `start_date` time-of-day rather than current wall-clock time when the stored start_date is in the past. Keeps "every Monday at 10:30 AM" patterns intact instead of drifting to current-time-of-day on horizon extension.
- **Bulk-action form URL-length truncation.** Events list-table form submitted via GET, so bulk actions on large selections (50+ events) blew past Apache/PHP/Cloudflare URL length limits and lost trailing IDs. `EventsPage` now POSTs the form; `EventsBulkActionHandler` reads from `$_REQUEST` so both GET (single-row links) and POST (bulk form) paths resolve through one code path. Nonce + capability verification still happen up-front.
- **Breadcrumb routing on event detail pages.** Upcoming-event detail pages now link back to the base events listing (`/events/`); past-event detail pages link back to the archive (`/events/archive/`).
- **Ticket-section text scaling.** Dropped explicit rem-based `font-size` declarations on ticket-card sub-elements (`__name`, `__description`, `__price`, `__submit`, badge). The fixed sizes were shrinking text to unusable scale inside theme containers; inheriting size from the parent text style keeps ticket listings legible across themes.

### Changed

- **Ticket-fields grid auto-fits narrow columns.** `ticket-form-assets.css` ticket-fields grid moved from rigid `1fr 1fr` to `auto-fit minmax(160px, 1fr)`, so narrow metabox columns collapse cleanly into one column instead of overflowing.
- **EventMetaboxHandler now constructs `TicketsMetabox`** (the canonical class) rather than the legacy `TicketingMetaboxHandler`; `set_context()` threads the current event and occurrence through the metabox lifecycle.

### Development

- **WP_Post stub** added to `tests/bootstrap.php`. The final-marked WP core class isn't autoloaded at runtime, so unit tests that check `instanceof WP_Post` need a lightweight equivalent; #[AllowDynamicProperties] matches WP core's annotation. `BeaverBuilderIntegrationTest` switched from a per-test eval'd shim to the bootstrap stub.
- **Suite totals:** Unit 6,051 / 18,629 assertions / 0 failures / 40 pre-existing skips. PHPCS clean (423 files); PHPStan level 8 "No errors".

## [1.0.1] - 2026-05-06

Remediation patch responding to WordPress.org plugin-review feedback (2026-05-05). The reviewer flagged the `nte` prefix as too short (< 4 chars). This release renames every prefixed identifier across the suite and bundles the corresponding DB migration. Substantial diff for what's nominally a patch — treated as a remediation patch within the same WP.org review cycle rather than a feature release.

### Changed

- **Identifier prefix:** all `nte_*` identifiers in PHP source renamed to `nettertech_events_*` — hooks, options, meta keys (`_nte_*` → `_nettertech_events_*`), transients, AJAX action strings, nonces, `$_POST` keys, JS-localized variables, table prefixes (`{wp_prefix}nte_*` → `{wp_prefix}nettertech_events_*`), cron hooks, shortcodes (`[nte_list]` → `[nettertech_events_list]`, etc.).
- **Custom post type:** `nte_event` → `nettertech_event` (16 chars). The originally-planned `nettertech_events_event` (23 chars) would have failed `register_post_type()`'s 20-char limit; shortened.
- **Taxonomies:** `nte_event_category` → `nettertech_event_category` (25 chars); `nte_event_tag` → `nettertech_event_tag` (20 chars).
- **CPT REST base:** `nte-events` → `nettertech-events` (rest_base; `/wp-json/nettertech-events/...`).
- **Style handle:** `nte-public-ticket` → `nettertech-events-public-ticket`.

### Added

- **PrefixMigrationManager** (`includes/Database/PrefixMigrationManager.php`) — Step 2 migration runs on plugin upgrade after the legacy `ve_* → nte_*` MigrationManager. Renames tables (RENAME TABLE), post_type, taxonomies, options (exact-match, no broad REPLACE), post/user/order-item meta keys, transients (cache delete vs stateful rename), reschedules cron hooks with cadence preserved, rewrites shortcodes in post_content.
- **Conflict-detection rule** — if both old and new tables exist, migration logs the conflict and aborts rather than auto-merging. Transient lock (600s TTL) prevents parallel runs.
- **`preflight_inventory()`** read-only dry-run reporter and **`verify()`** post-migration straggler check.

### Fixed

- **MigrationManager carve-out** documented: the existing legacy `ve_* → nte_*` migration's `nte_*` outputs are intentionally retained — Plugin Check may flag those literal strings, and the carve-out is documented in the class docblock.
- **Cron rename completeness:** added 3 base cron hooks missed in the original CRON_HOOK_MAP (`_purge_activity_log_pii`, `_daily_cleanup`, `_sweep_expired_reservations`) plus 1 obsolete hook (`nte_cleanup`, no replacement) handled via new `OBSOLETE_CRON_HOOKS` clear-only branch.
- **`uninstall.php` post-type filter** was hardcoded to `'nettertech_events_event'` (23 chars; never a live slug — exceeded WP's 20-char `register_post_type()` limit). Replaced with the canonical `ShadowPostType::POST_TYPE` constant so it stays in sync with the registered slug.
- **`uninstall.php` transient cleanup** was matching `_transient_nte_%` (the pre-1.0.1 prefix) and would have left every `nettertech_events_*` transient orphaned post-migration. Patterns updated to match the post-migration prefix.
- **Sweep of stale `nte_*` references** in comments, PHPDocs, local PHP variable names, test fixtures, block render templates (`blocks/*/render.php`), Beaver Builder module render templates, and template files. The only `nte_*` strings remaining in the codebase are inside `MigrationManager.php` (Step-1 migrator, never executes on a fresh install) and `PrefixMigrationManager.php` (Step-2 input keys for the rename map).

### Breaking

- **Yoast SEO title-template variables** (`%%nte_event_date%%`, `%%nte_event_venue%%`, `%%nte_event_organizer%%`) were renamed to `%%nettertech_events_event_date%%`, `%%nettertech_events_event_venue%%`, `%%nettertech_events_event_organizer%%`. Sites with the old names in their Yoast title templates need to update those templates after the upgrade — the legacy variables no longer resolve. Rank Math equivalents were already on the new prefix and are unaffected.

### Migration notes

- **Automatic on upgrade.** `PrefixMigrationManager::maybe_migrate()` runs on `Activator::activate()` and `Plugin::init()`; idempotent and lock-protected.
- **Defensive intermediate-state handling:** the migration's `POST_TYPE_MAP` and `TAXONOMY_MAP` include defensive entries for sites that may have run an early Wave-1 build with the broken intermediate `nettertech_events_event` slug or its truncated form `nettertech_events_ev` — all three map to the canonical `nettertech_event`.
- **DB DDL is auto-commit** (MySQL `RENAME TABLE`); operators are expected to take a `wp db export` snapshot before running on production data.

## [1.0.0] - 2026-02-05

### Added
- **Category System Migration**: Replaced WordPress taxonomy with internal `nte_categories` and `nte_event_categories` tables, dedicated CategoryPage admin UI with hierarchy support
- **Event Reminder Emails**: Cron-scheduled pre-event reminders with configurable timing, `nte_reminder_log` table, `event-reminder.php` email template
- **GDPR Privacy Tools**: WordPress privacy exporter/eraser integration for attendee data
- **Event Card Redesign**: Full-tile clickability, responsive images (800x800 with srcset), accessible focus states
- **Category Editor UI**: Checkbox metabox in event editor sidebar with pre-check for existing assignments
- **WooCommerce Cart Meta**: `event_id` meta on cart items for external integration support
- **Activity Logging**: OWASP A09 compliant audit trail via ActivityLogHooks (events, occurrences, attendees, tickets, settings, exports)
- **Distribution Packaging**: `.distignore`, `composer dist` script, GPL v2 LICENSE file
- **HPOS Compatibility**: Declared WooCommerce High-Performance Order Storage support
- **Release Infrastructure**: Conventional Commits config, CHANGELOG, release process docs
- **Performance Documentation**: Comprehensive caching and optimization guide
- **PHPStan Level 7**: Static analysis at Level 7 with an empty (zero-error) baseline
- **Shadow Post Type**: Hidden `nte_event` post type that mirrors custom-table events, enabling WordPress admin bar search and Gutenberg link dialog discovery
- **Shadow Post Sync Service**: Automatic sync of shadow posts on event create/update/delete via `nte_after_save_event` and `nte_after_delete_event` hooks
- **REST Search Integration**: Shadow posts injected into `WP_REST_Post_Search_Handler` subtypes via Reflection, making events searchable through the WordPress REST search API
- **Open Graph Meta Tags**: `og:title`, `og:description`, `og:image`, `og:type`, and `twitter:card` meta tags on single event pages for social media preview
- **Core Sitemap Provider**: Custom `WP_Sitemaps_Provider` subclass for event and occurrence URLs in WordPress core sitemaps
- **Dependency Injection Container**: Full constructor injection replacing static ServiceRegistry facades (ADR-013)
- **16 Architecture Decision Records**: Comprehensive documentation of design decisions (ADRs 001–014, 016, 017)
- **Waitlist Infrastructure**: Table, model, repository, and service for waitlist management
- **Calendar Extension Hooks**: Integration points for rental plugin calendar extensions

### Changed
- WooCommerce order meta access now uses HPOS-compatible CRUD API
- God class decomposition: EventMetaboxHandler, Schema, OrderHandler, AttendeesPage, QRGeneratorPage, CalendarShortcode, CarouselShortcode, RSVPFormShortcode refactored into focused single-responsibility modules
- CSS custom properties for themeable colors (`--nte-date-bg`, `--nte-color-background-alt`, `--nte-color-border`)
- Systemic rem-to-em CSS conversion across 6 stylesheet files
- 12px minimum font floor enforced across all frontend components
- OccurrenceFilterRepository uses `nte_event_categories` junction table instead of `term_relationships`
- iCal import/export uses CategoryRepository instead of WordPress taxonomy functions
- ActivityLogHooks accepts Attendee model instead of (int, array) for hook signature compatibility
- **PHPStan**: Raised from Level 5 → 6 → 7 across the release; baseline reduced to zero errors
- **Constructor Injection**: All repositories, services, controllers, admin classes, and integrations use DI
- **ServiceRegistry**: Static accessors deprecated in favor of container-backed injection
- **Repository Decomposition**: Extracted query repositories (OccurrenceQueryRepository, EventQueryRepository, TicketTypeQueryRepository, AttendeeCheckInRepository)
- **Service Decomposition**: Extracted SharedCapacityCalculator, QRCodeRenderer
- **Custom Exceptions**: Adopted typed exception hierarchy across all repositories and service layers
- **Test Suite**: Expanded to over 5,000 unit tests with over 17,000 assertions
- **i18n**: Standardized translator comments, regenerated .pot file with zero warnings
- **CSS**: Unified fallback palette to canonical Tailwind-derived tokens

### Fixed
- **BUG-001**: Event time not saving — occurrence loading guard, empty time handling, default time removal
- **BUG-002**: Past events AJAX pagination missing `past` parameter in request
- **BUG-003**: Category filter dropdown empty after iCal import — dual-write to taxonomy and custom table
- **BUG-004**: Ticket quantity +/- buttons non-functional — moved inline script to `wp_footer` action
- **BUG-005**: Event editor browser tab title stuck on "All Events" — added `admin_title` filter
- **BUG-007**: No UI to assign categories in event editor — added checkbox metabox
- Admin bar "Edit Event" hover color not applying (moved from inline style to stylesheet)
- Frontend ticket display and occurrence template rendering issues
- PHP 8.3 DateMalformedStringException compatibility
- Constructor dependency wiring in composition root and callers
- Gutenberg block stubs replaced with full editor implementations (InspectorControls, attribute handling, preview CSS)
- OccurrenceRepository missing from EventListShortcode in block and BB render callbacks

### Improved (UX)
- **UX-001**: Sharp tile images with 800x800 size registration and responsive srcset
- **UX-002**: Themeable date badge background via CSS custom properties with 3-level fallback
- **UX-003**: Entire event tile clickable with `<a>` wrapper and aria-label
- **UX-004**: Upcoming/Past toggle visual clarity — blue filled active state, white inactive
- **UX-005**: Search/filter bar consistent 44px heights and font sizing
- **UX-006**: Reset button visible with white background and border
- **UX-007**: Ticket area themed with CSS custom properties replacing hardcoded hex
- **UX-008**: Admin bar "Edit Event" hover color verified via getComputedStyle()

### Security
- All order metadata access patterns verified HPOS-compatible
- OWASP 2025 compliance verified across all endpoints
- Rate limiting hardened on sensitive endpoints
- All `error_log()` calls verified guarded by WP_DEBUG checks
- 100% nonce coverage verified on all AJAX/form/REST handlers

## [0.9.0] - 2026-01-11

### Added
- **Check-In System**: QR scanner integration and public check-in page
- **Ticket Lookup**: REST endpoint for ticket validation
- **Public Volunteer UI**: Token-based auth for counter updates
- **Complete Check-In**: Button and workflow for finalizing events
- **Activity Logging**: OWASP A09 compliant audit trail
- **Rate Limiting**: Protection for check-in API and AJAX endpoints
- **Admin Controllers**: Full CRUD REST API for events, attendees, ticket types
- **Custom Exceptions**: Typed exception hierarchy for better error handling
- **URL Conflict Detection**: Base path setting validation
- **OpenAPI Specification**: Complete REST API documentation

### Changed
- Switched check-in code generation to `random_bytes(16)` (cryptographically secure, 16-hex-char code format per OWASP 2025)
- Reduced cyclomatic complexity in OrderHandler and OccurrenceGenerator
- Expanded test coverage to 42.63% (1282 unit tests)

### Fixed
- Authorization order and nonce handling (security)
- XSS prevention using WordPress disabled() helper
- Slug generation before validation in EventRepository
- Fresh capacity validation at payment time
- Complete button styles and states in check-in UI

### Security
- All 8 security audit findings remediated
- OWASP 2025 compliance verified
- Rate limiting on sensitive endpoints

## [0.8.0] - 2026-01-06

### Added
- **Shared Capacity**: CapacityType enum for occurrence/event/template scoping
- **Drag-Drop Layout Editor**: Live preview for event page layouts
- **Theme-Aware CSS**: Variable overrides and template improvements
- **Sortable Date Column**: Next Date column in events admin list
- **End Time Settings**: Configurable event duration defaults
- **Branding System**: NetterTech branding and distinctive menu icon

### Changed
- Improved admin form validation
- Removed Series menu (consolidated)
- Updated contract interfaces and ServiceRegistry for DI

### Security
- Added wp_unslash() to all $_POST/$_GET sanitization
- Fixed authorization order and nonce handling

## [0.7.0] - 2026-01-04

### Added
- **Testing Infrastructure**: 242 unit tests + 53 E2E tests
- **ARIA Accessibility**: Screen reader support throughout
- **Schema Versioning**: Database migration system
- **Repository Integration Tests**: Full database layer testing

### Changed
- Improved Playwright test infrastructure
- Expanded model and service test coverage
- Extracted testable methods for WooCommerce integration

### Fixed
- E2E test stability for recurring events
- Schema migration edge cases

## [0.6.0] - 2026-01-02

### Added
- **Ticket Type Scopes**: Occurrence, event, and template-level tickets
- **Rate Limiting Infrastructure**: Configurable request throttling
- **Settings Improvements**: Enhanced admin configuration UI

### Security
- Security, accessibility, and settings hardening

## [0.5.0] - 2025-12-28

### Added
- **Schema 2.2.0**: Organizers, categories, tags with junction tables
- **Performance Indexes**: Optimized check-in queries
- **QRCodeService Improvements**: Better code generation

### Changed
- Agent team definition upgraded to v0.4.0

## [0.4.0] - 2025-12-20

### Added
- **Recurring Events**: Full RFC 5545 RRULE support
- **RecurrenceService**: Complex pattern handling (every second Tuesday, etc.)
- **OccurrenceGenerator**: Pre-computed occurrence instances

### Changed
- Moved from post_meta to custom tables for performance

## [0.3.0] - 2025-12-15

### Added
- **WooCommerce Integration**: Ticket product type and cart handling
- **OrderHandler**: Attendee creation from orders
- **CartHandler**: Capacity validation during checkout

## [0.2.0] - 2025-12-10

### Added
- **Admin Interface**: Event editor with metaboxes
- **List Tables**: Events and occurrences admin lists
- **Template System**: Theme-overridable templates

## [0.1.0] - 2025-12-01

### Added
- Initial plugin scaffold
- Database schema (events, occurrences, ticket_types, attendees)
- Event and Occurrence models
- Basic repository layer
- Plugin activation/deactivation hooks

