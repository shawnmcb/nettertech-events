# Documentation Index

> **Audience:** Anyone opening the `docs/` directory. This index points you to the right document for your goal.

**Last Updated:** 2026-04-20

## By Persona

### Site administrators (you manage the plugin on a WordPress site)
- **[Admin Quick-Start](ADMIN-QUICKSTART.md)** — Publish your first event in under 5 minutes.
- **[Admin Manual](ADMIN-MANUAL.md)** — Complete feature reference for the admin UI.
- **[Workflows](WORKFLOWS.md)** — End-to-end walkthroughs of common admin tasks.
- **[Troubleshooting](TROUBLESHOOTING.md)** — Fixes for the most common problems.
- **[Glossary](GLOSSARY.md)** — Domain terms defined in one line each.

### End users (attendees viewing public event pages)
- Public-facing documentation lives in the main [`readme.txt`](../readme.txt) and on the plugin's WordPress.org page. There is no separate end-user manual in this directory — this `docs/` tree is for site administrators, developers, and integrators.

### Theme developers (you want to customize the event display)
- **[Template Override Guide](TEMPLATE-OVERRIDE.md)** — Copy templates into your theme and override them safely.
- **[Accessibility](ACCESSIBILITY.md)** — WCAG 2.2 AA conformance notes and customization hooks.
- **[SEO Integration](SEO-INTEGRATION.md)** — Schema.org, Yoast, and Rank Math interop.
- **[Hooks Reference](HOOKS.md)** — Actions and filters available for theme customization.

### Plugin developers (you are contributing to or extending NetterTech Events)
- **[Developer Guide](DEVELOPER-GUIDE.md)** — Codebase walkthrough, workflows, debugging.
- **[Contributing](../CONTRIBUTING.md)** — Development environment setup and standards.
- **[Hooks Reference](HOOKS.md)** — Curated action/filter reference plus a pointer to all 117 hook constants in `includes/Core/Hooks.php`.
- **[Database Schema](DATABASE-SCHEMA.md)** — All 20 custom tables with columns, indexes, and relationships.
- **[REST API Stability Contract](REST-API-CHANGELOG.md)** — Versioning policy, endpoint inventory, authentication.
- **[OpenAPI Specification](openapi.yaml)** — OpenAPI 3.0 spec (see scope note inside).
- **[Security Model](SECURITY.md)** — OWASP-aligned security architecture and hardening options.
- **[Performance Guide](PERFORMANCE.md)** — Caching, query patterns, benchmarking.
- **[Test Plan](TEST-PLAN.md)** — Testing strategy and coverage targets.
- **[Release Process](RELEASE.md)** — Steps to cut a release.
- **[Architecture Decision Records](architecture/)** — 16 ADRs documenting key design choices.

### Add-on developers (you are building a companion plugin)
- **[Core Integration Contract](CORE-INTEGRATION-CONTRACT.md)** — Stable API surface add-ons may depend on.
- **[Cross-Plugin Matrix](CROSS-PLUGIN-MATRIX.md)** — Compatibility and feature-ownership matrix across the NetterTech Events suite.
- **[Schema Compatibility Matrix](SCHEMA-COMPATIBILITY-MATRIX.md)** — Database schema versions and required core versions.
- **[WooCommerce Integration Boundary](WC-INTEGRATION-BOUNDARY.md)** — What the Core plugin takes responsibility for vs. what WooCommerce owns.
- **[Deferred Schema Pattern (ADR-014)](architecture/ADR-014-deferred-schema-pattern.md)** — How add-ons declare schema extensions.

## By Journey

### I want to…

| Goal | Read |
|------|------|
| Install and publish my first event | [ADMIN-QUICKSTART.md](ADMIN-QUICKSTART.md) |
| Understand what every admin screen does | [ADMIN-MANUAL.md](ADMIN-MANUAL.md) |
| Customize a template in my theme | [TEMPLATE-OVERRIDE.md](TEMPLATE-OVERRIDE.md) |
| Hook into an event save | [HOOKS.md](HOOKS.md) → `nettertech_events_after_save_event` |
| Build a REST integration | [REST-API-CHANGELOG.md](REST-API-CHANGELOG.md) + [openapi.yaml](openapi.yaml) |
| Add custom registration fields | [ADMIN-MANUAL.md](ADMIN-MANUAL.md) → "Attendee Fields" |
| Understand ticketing and capacity | [architecture/ADR-005-capacity-model.md](architecture/ADR-005-capacity-model.md) |
| Understand recurring events | [architecture/ADR-006-recurring-events-rrule.md](architecture/ADR-006-recurring-events-rrule.md) |
| Build an add-on that extends the schema | [architecture/ADR-014-deferred-schema-pattern.md](architecture/ADR-014-deferred-schema-pattern.md) |
| Verify security posture | [SECURITY.md](SECURITY.md) |
| Tune performance | [PERFORMANCE.md](PERFORMANCE.md) |
| Ship a release | [RELEASE.md](RELEASE.md) |
| Look up a term I don't recognize | [GLOSSARY.md](GLOSSARY.md) |

## Document Inventory

All documentation files in this directory:

| Document | Audience | Description |
|----------|----------|-------------|
| [ACCESSIBILITY.md](ACCESSIBILITY.md) | Theme developers | WCAG 2.2 AA conformance and customization |
| [ADMIN-QUICKSTART.md](ADMIN-QUICKSTART.md) | Site admins | 5-minute getting-started guide |
| [CORE-INTEGRATION-CONTRACT.md](CORE-INTEGRATION-CONTRACT.md) | Add-on developers | Stable Core API surface for add-ons |
| [CROSS-PLUGIN-MATRIX.md](CROSS-PLUGIN-MATRIX.md) | Add-on developers | Suite compatibility matrix |
| [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) | Plugin developers | All 20 custom tables |
| [DEVELOPER-GUIDE.md](DEVELOPER-GUIDE.md) | Plugin developers | Codebase walkthrough and workflows |
| [GLOSSARY.md](GLOSSARY.md) | All | Domain term definitions |
| [HOOKS.md](HOOKS.md) | Theme / Plugin developers | Curated action/filter reference |
| [openapi.yaml](openapi.yaml) | Integrators | OpenAPI 3.0 REST spec |
| [PERFORMANCE.md](PERFORMANCE.md) | Plugin developers | Caching and query-optimization guide |
| [RELEASE.md](RELEASE.md) | Maintainers | Release cut procedure |
| [REST-API-CHANGELOG.md](REST-API-CHANGELOG.md) | Integrators | REST API stability contract |
| [SCHEMA-COMPATIBILITY-MATRIX.md](SCHEMA-COMPATIBILITY-MATRIX.md) | Add-on developers | Schema version matrix |
| [SECURITY.md](SECURITY.md) | Site admins / developers | Security model |
| [SEO-INTEGRATION.md](SEO-INTEGRATION.md) | Theme developers | Schema.org, Yoast, Rank Math interop |
| [TEMPLATE-OVERRIDE.md](TEMPLATE-OVERRIDE.md) | Theme developers | Theme template override system |
| [TEST-PLAN.md](TEST-PLAN.md) | Plugin developers | Testing strategy and coverage targets |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Site admins | Common problems and fixes |
| [ADMIN-MANUAL.md](ADMIN-MANUAL.md) | Site admins | Full feature reference |
| [WC-INTEGRATION-BOUNDARY.md](WC-INTEGRATION-BOUNDARY.md) | Plugin / Add-on developers | WooCommerce responsibility boundary |
| [WORKFLOWS.md](WORKFLOWS.md) | Site admins | End-to-end task walkthroughs |
| [architecture/](architecture/) | Plugin developers | 16 Architecture Decision Records |

## External Documentation

- [README.md](../README.md) — Developer-facing project overview (root of repo)
- [CHANGELOG.md](../CHANGELOG.md) — Version history
- [readme.txt](../readme.txt) — WordPress.org plugin directory listing
- [CONTRIBUTING.md](../CONTRIBUTING.md) — How to contribute
- [CURRENT.md](../CURRENT.md) — Current build state dashboard
