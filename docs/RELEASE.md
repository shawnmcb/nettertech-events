# Release Process

> **Audience:** Plugin maintainers cutting a release. Not for site administrators (see [ADMIN-QUICKSTART.md](ADMIN-QUICKSTART.md)) or integrators (see [REST-API-CHANGELOG.md](REST-API-CHANGELOG.md) for the API stability contract).

This document describes the release process for NetterTech Events.

## Version Numbering

WP.org-facing versions follow [Semantic Versioning](https://semver.org/):

- **MAJOR** (1.x.x): Breaking changes
- **MINOR** (x.1.x): New features, backwards compatible
- **PATCH** (x.x.1): Bug fixes, backwards compatible

Internal dist iterations during active development carry a fourth segment for asset cache-busting (see [ADR-018](architecture/ADR-018-four-segment-internal-versioning.md)):

- **INTERNAL** (x.x.x.1): Incremented on every `/dist` rebuild within a single MAJOR.MINOR.PATCH release.
- Reset (absent) whenever any of MAJOR/MINOR/PATCH is bumped.

The plugin Version header and `NETTERTECH_EVENTS_VERSION` constant carry the four-segment version during development (e.g., `1.0.2.3`). The `readme.txt` `Stable tag` always carries exactly three segments and is updated only at WP.org submission time. When cutting a public release, normalize the Version header back down to three segments (`1.0.2.7` → `1.0.3`).

Rationale: `wp_enqueue_*` uses the version constant as the `?ver=X` cache key. Identical version across rebuilds leaves browsers and CDNs serving stale assets; the fourth segment provides cheap, deterministic cache-bust without inflating the public version.

## Commit Convention

All commits must follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

### Types

| Type | Description | Version Bump |
|------|-------------|--------------|
| `feat` | New feature | Minor |
| `fix` | Bug fix | Patch |
| `docs` | Documentation only | None |
| `style` | Code style (formatting) | None |
| `refactor` | Code refactoring | None |
| `perf` | Performance improvement | Patch |
| `test` | Adding tests | None |
| `build` | Build system changes | None |
| `ci` | CI configuration | None |
| `chore` | Maintenance | None |
| `security` | Security fix | Patch |
| `a11y` | Accessibility improvement | Patch |

### Scopes

- `admin` - Admin interface
- `api` - REST API
- `checkin` - Check-in system
- `db` / `schema` - Database
- `frontend` - Public-facing
- `templates` - Template system
- `ticketing` - Ticket functionality
- `woocommerce` - WC integration
- `blocks` - Gutenberg blocks
- `beaver` - Beaver Builder

### Examples

```bash
feat(checkin): add QR scanner support
fix(ticketing): correct capacity calculation
docs: update installation guide
security: upgrade token entropy to 48 bytes
```

## Pre-Release Checklist

### Code Quality
- [ ] All tests passing (`composer test`)
- [ ] PHPCS clean (`composer phpcs`)
- [ ] No PHP errors/warnings in error log
- [ ] Test coverage ≥ 70% (`composer test:coverage`) with regenerated `build/coverage/clover.xml`
- [ ] Mutation artifacts refreshed (`composer infection` or `composer infection:full`) and dated in the release notes when used as audit evidence

### Security
- [ ] Security audit passed
- [ ] No known vulnerabilities (`composer audit`)
- [ ] OWASP compliance verified

### Compatibility
- [ ] WordPress 6.5+ tested
- [ ] PHP 8.2+ tested
- [ ] WooCommerce 8.5+ tested (HPOS)
- [ ] Beaver Builder tested

### Documentation
- [ ] CHANGELOG.md updated
- [ ] Public-text gate green (`composer check:public-text`): CHANGELOG/readme/docs/code comments name no internal dev site, client site, local host, or developer path — the reader only knows the plugin as installed on their WordPress (NTE-216)
- [ ] readme.txt changelog synced
- [ ] Version numbers updated

### Performance
- [ ] Page load < 1.5s
- [ ] No page > 50 queries
- [ ] No memory leaks

### Accessibility
- [ ] WCAG 2.2 AA compliant
- [ ] Screen reader tested
- [ ] Keyboard navigation works

## Release Steps

### 1. Prepare Release Branch

```bash
git checkout main
git pull origin main
git checkout -b release/v1.0.0
```

### 2. Update Version Numbers

Update version in these files:
- `nettertech-events.php` (Plugin header)
- `readme.txt` (Stable tag)
- `includes/Core/Plugin.php` (VERSION constant)

```bash
# Example sed commands
sed -i '' 's/Version: 0.9.0/Version: 1.0.0/' nettertech-events.php
sed -i '' 's/Stable tag: 0.9.0/Stable tag: 1.0.0/' readme.txt
```

### 3. Update CHANGELOG.md

Move items from `[Unreleased]` to new version section:

```markdown
## [1.0.0] - 2026-01-15

### Added
- ...
```

### 4. Sync readme.txt Changelog

Copy recent changelog entries to `readme.txt`:

```
== Changelog ==

= 1.0.0 =
* Added: Feature description
* Fixed: Bug description
```

### 5. Run Full Test Suite

```bash
composer phpcs
composer test
./scripts/test-all.sh
```

### 6. Commit Release

```bash
git add -A
git commit -m "chore: release v1.0.0"
```

### 7. Merge to Main

```bash
git checkout main
git merge release/v1.0.0
```

### 8. Tag Release

```bash
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin main --tags
```

### 9. Create GitHub Release

1. Go to GitHub Releases
2. Click "Draft a new release"
3. Select tag `v1.0.0`
4. Title: "NetterTech Events v1.0.0"
5. Copy changelog section to description
6. Attach ZIP file if distributing outside wp.org

### 10. Post-Release

- [ ] Verify tag on GitHub
- [ ] Test fresh install from release
- [ ] Update demo site
- [ ] Announce release

## Hotfix Process

For critical fixes to released versions:

```bash
git checkout v1.0.0
git checkout -b hotfix/v1.0.1
# Make fix
git commit -m "fix(scope): description"
# Update versions to 1.0.1
git commit -m "chore: release v1.0.1"
git checkout main
git merge hotfix/v1.0.1
git tag -a v1.0.1 -m "Hotfix v1.0.1"
git push origin main --tags
```

## Rollback

If a release has critical issues:

```bash
# Revert to previous version
git revert HEAD
git push origin main

# Or reset to previous tag (destructive)
git reset --hard v0.9.0
git push origin main --force  # Requires admin
```

## Automation (Future)

Consider adding:
- GitHub Actions for automated releases
- `standard-version` or `release-it` for version bumping
- Automated WordPress.org deployment
