# ADR-018: Four-Segment Internal Versioning for Asset Cache-Busting

**Date:** 2026-05-24
**Status:** Accepted
**Deciders:** Human + Engineering
**Category:** Development Infrastructure
**References:** RELEASE.md

## Context

The plugin enqueues admin and frontend CSS/JS through `wp_enqueue_style`/`wp_enqueue_script` with the `NETTERTECH_EVENTS_VERSION` constant as the `?ver=X` query parameter. Browsers (and CDNs like Cloudflare in front of CJAC) cache asset URLs by the full URL string, including the query argument. When `NETTERTECH_EVENTS_VERSION` stays constant across multiple `/dist` rebuilds within the same release, the URL `…/ticket-form-assets.css?ver=1.0.2` is identical between iterations — users continue to see the previously-cached file even after a fresh zip is deployed.

This bit during the CJAC TEC→NTE migration session (2026-05-24): a CSS fix (`grid-template-columns: 1fr 1fr` → `repeat(auto-fit, minmax(160px, 1fr))`) shipped in the dist zip but the deployed page still computed `218px 218px` because the browser served cached CSS keyed on the unchanged `?ver=1.0.2`.

Standard mitigations:

1. **Bump the plugin version on every dist** — clean URL diff, but conflates "released to WP.org" with "rebuilt locally," and inflates the public version number rapidly during active development.
2. **Use file-mtime or content-hash as the `ver` arg** — automatic cache-bust on file change, but every asset has its own version string, which clutters the rendered HTML, and the hash changes are opaque to humans diagnosing in DevTools.
3. **Four-segment internal version** (chosen) — explicit, human-readable, and decoupled from the public release cadence.

### Problem

We need cache-bust granularity finer than the public WP.org release cadence, without polluting the WP.org-facing version number or relying on opaque hash strings.

## Decision

### 1. Use a Four-Segment Version Internally

The plugin Version header and `NETTERTECH_EVENTS_VERSION` constant carry a four-segment version of the form `MAJOR.MINOR.PATCH.INTERNAL` (e.g., `1.0.2.1`, `1.0.2.2`, …). The fourth segment is incremented every time `/dist` is rebuilt during ongoing development within a single MAJOR.MINOR.PATCH release.

### 2. Reset Fourth Segment on Public Bump

Whenever any of MAJOR/MINOR/PATCH is bumped (e.g., 1.0.2.5 → 1.0.3), the fourth segment resets to absent (`1.0.3`, not `1.0.3.0`). The next internal iteration becomes `1.0.3.1`.

### 3. WP.org Submissions Stay Three-Segment

The `Stable tag` in `readme.txt` (the WP.org-facing version) always carries exactly three segments. When a release is cut to WP.org, the Version header is normalized down to three segments (`1.0.2.7` → `1.0.3`). WP.org users never see four-segment versions.

The `Stable tag` is updated only at WP.org submission time, not on every internal dist iteration.

### 4. Internal Version Drives the Cache-Bust

`wp_enqueue_*` calls passing `NETTERTECH_EVENTS_VERSION` as the `$ver` argument inherit cache-bust behavior automatically. No per-asset versioning logic required.

## Consequences

**Positive:**

- Cache-bust works deterministically: a fresh dist with new assets ships a new version string, so browsers and CDNs treat the URLs as fresh.
- Public version number stays clean and SemVer-compliant for WP.org users.
- Version bumps remain a single line in `nettertech-events.php` — no build-system change required.
- The fourth segment provides a human-readable counter of how many internal iterations preceded the next public release — useful for diagnosis ("the deployed CSS was from 1.0.2.3, current fix is in 1.0.2.5").

**Negative:**

- Manual bump per dist rebuild. Forgetting to bump reintroduces the cache-stale bug.
- The Version header and Stable tag temporarily diverge during active development. Anyone inspecting the codebase mid-stream sees `Version: 1.0.2.4` and `Stable tag: 1.0.2` — must understand the policy to interpret correctly.
- WordPress core's `version_compare()` treats `1.0.2.1 > 1.0.2`, so any version-gated upgrade logic (Activator, schema migrations) fires on every fourth-segment bump unless guarded by a SemVer-only comparison.

**Future Work:**

- Optional: extend `build-dist.sh` with `--bump-internal` to auto-increment the fourth segment, eliminating the manual-bump-forgotten failure mode.
- Optional: pre-push hook check that fails if the Version header equals the last-pushed Version (forces explicit bump).

## Alternatives Considered

| Alternative | Why Rejected |
|-------------|--------------|
| File-mtime as `$ver` | Opaque to humans; one cache-bust per file rather than per release; harder to correlate "what version is deployed" |
| Content-hash as `$ver` | Same opacity concerns; longer URLs; harder for support-debugging |
| Bump PATCH on every rebuild | Inflates public version, conflicts with SemVer (PATCH bumps imply released bug fixes), confuses WP.org users |
| Manually pass `filemtime()` to each `wp_enqueue_*` | Touches every enqueue site; loses the single-source-of-truth nature of `NETTERTECH_EVENTS_VERSION` |

## Implementation

- `nettertech-events.php` Version header: four-segment during active development, three-segment at WP.org submission
- `NETTERTECH_EVENTS_VERSION` constant: mirrors Version header
- `readme.txt` Stable tag: always three-segment, updated only at WP.org submission
- Same policy applies to sibling plugins (`nettertech-events-migrator`, `nettertech-events-pro`, etc.) using their own respective version constants
