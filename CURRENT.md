# NetterTech Events (Core) - Current State

**Last Audited:** 2026-04-17
**Audit Grade:** B+ (88/100)
**Metrics Refreshed:** 2026-05-22 (PHPUnit tests + coverage re-measured live)
**PHP Version:** >=8.2
**WordPress Version:** >=6.5

## Metrics

| Metric | Value |
|--------|-------|
| PHPUnit Tests | 6,045 (18,602 assertions, 40 skipped, 0 failures) |
| Line Coverage | 82.88% line, 74.21% method |
| Infection MSI | Target 80 / 80 (see `infection.json5`; run `composer infection`) |
| PHPStan Level | 7 |
| PHPStan Baseline | 0 errors |
| PHPCS | Clean (380 files, 0 errors, 0 warnings) |
| E2E Tests | 17 Playwright spec files (280 `test()` calls) |
| `composer audit` | No advisories |

## Documentation

- `docs/architecture/` - Architecture Decision Records (ADRs 001–014 + 016 + 017)
- `docs/ACCESSIBILITY.md` - Accessibility features and WCAG compliance
- `docs/ADMIN-QUICKSTART.md` - Quick-start for admin operations
- `docs/CORE-INTEGRATION-CONTRACT.md` - Contract for add-on integration
- `docs/CROSS-PLUGIN-MATRIX.md` - Cross-plugin dependency matrix
- `docs/DATABASE-SCHEMA.md` - Database schema reference
- `docs/DEVELOPER-GUIDE.md` - Developer onboarding and extension guide
- `docs/HOOKS.md` - Action and filter reference
- `docs/PERFORMANCE.md` - Performance considerations
- `docs/RELEASE.md` - Release process
- `docs/REST-API-CHANGELOG.md` - REST API changelog
- `docs/SCHEMA-COMPATIBILITY-MATRIX.md` - Cross-plugin schema matrix
- `docs/SECURITY.md` - Security model
- `docs/TEMPLATE-OVERRIDE.md` - Theme template overrides
- `docs/TEST-PLAN.md` - Test plan and strategy
- `docs/TROUBLESHOOTING.md` - Troubleshooting guide
- `docs/ADMIN-MANUAL.md` - Admin manual
- `docs/WC-INTEGRATION-BOUNDARY.md` - WooCommerce boundary docs
- `docs/WORKFLOWS.md` - Admin workflows
- `docs/openapi.yaml` - REST API specification

## Quality Tools

- PHPCS (`phpcs.xml`) - WordPress-Extra + PHPCompatibilityWP for PHP 8.2+
- PHPStan Level 7 (`phpstan.neon` + empty baseline)
- PHPUnit 10 (`phpunit.xml.dist` unit + `phpunit-integration.xml.dist` integration)
- Infection (`infection.json5`) - Mutation testing, minMsi 80 / minCoveredMsi 80
- PHPMD (`phpmd.xml`) - Mess detection
- Playwright E2E (`tests/E2E/playwright/`, 17 specs)
- Git hooks (`.githooks/`, installed via `composer hook:install`):
  - pre-commit (ADR-017 Layer 1): gitleaks + PHPCS + PHPStan + PHPUnit + syntax
  - pre-push (ADR-017 Layer 2): Infection + coverage ≥72% threshold
- `composer push`: phpcs → phpstan → deps:audit → test → infection → git push

## Release

1.0.0 released 2026-02-05. Git history: 687 commits spanning 2026-01-04 → 2026-04-17.
