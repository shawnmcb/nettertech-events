# Architecture Decision Records

This directory contains Architecture Decision Records (ADRs) documenting key design choices in the nettertech-events plugin. Each ADR explains the context, decision, alternatives considered, and consequences.

## Index

| ADR | Title | Category | Status |
|-----|-------|----------|--------|
| [001](ADR-001-custom-database-tables.md) | Custom Database Tables Over post_meta | Database | Accepted |
| [002](ADR-002-service-locator-pattern.md) | Service Locator Pattern (ServiceRegistry) | Dependencies | Accepted (under review) |
| [003](ADR-003-repository-pattern.md) | Repository Pattern with Interface Contracts | Data Access | Accepted |
| [004](ADR-004-rest-api-design.md) | REST API Design | API | Accepted |
| [005](ADR-005-capacity-model.md) | Fixed vs Shared Ticket Capacity Model | Domain | Accepted |
| [006](ADR-006-recurring-events-rrule.md) | Recurring Events via RRule | Domain | Accepted |
| [007](ADR-007-qr-code-generation.md) | Local QR Code Generation | Infrastructure | Accepted |
| [008](ADR-008-woocommerce-integration.md) | WooCommerce Integration via CRUD API | Integration | Accepted |
| [009](ADR-009-referential-integrity.md) | Application-Level Referential Integrity | Database | Accepted |
| [010](ADR-010-identity-maps.md) | Per-Request Identity Maps | Performance | Accepted |
| [011](ADR-011-template-system.md) | Theme-Overridable Template System | Frontend | Accepted |
| [012](ADR-012-exception-hierarchy.md) | Custom Exception Hierarchy | Error Handling | Accepted |
| [013](ADR-013-dependency-injection-migration.md) | Dependency Injection Migration | Dependencies | Accepted |
| [014](ADR-014-deferred-schema-pattern.md) | Deferred Schema Pattern for Add-ons | Database | Accepted |
| [016](ADR-016-local-repo-strategy.md) | Local-Repo Strategy (Dropbox-Hosted Bare Repository) | Development Infrastructure | Accepted |
| [017](ADR-017-push-validation-strategy.md) | Push Validation Strategy | Development Infrastructure | Accepted |

## Format

Each ADR follows this structure:

- **Context**: The forces at play, including technical, business, and social
- **Decision**: The change being proposed or enacted
- **Alternatives Considered**: Options evaluated and why they were rejected
- **Consequences**: What becomes easier or harder as a result
