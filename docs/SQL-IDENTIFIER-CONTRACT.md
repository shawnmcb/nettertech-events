# SQL Identifier Safety Contract

> **Audience:** Plugin developers adding or reviewing database query code.

SQL placeholders protect values, not table names, column names, sort directions, or whole `ORDER BY` clauses. Any interpolated SQL identifier in NetterTech Events must come from a trusted local source and document that source at the interpolation point.

## Allowed Sources

| Identifier type | Allowed source | Notes |
|---|---|---|
| Table names | `Schema::table( '<known-key>' )`, repository constructor constants, or `$wpdb` core table properties | Never accept table names from request input. |
| Sort columns | Local allowlist map or builder allowlist | Request `orderby` values must resolve through the allowlist before interpolation. |
| Sort directions | Explicit `ASC` / `DESC` normalization | Any other input falls back to the default direction. |
| Whole order clause | `sanitize_sql_orderby()` after allowlist mapping | If sanitization fails, fall back to a static safe clause. |
| Placeholder lists | Generated placeholders only (`%d`, `%s`) plus prepared values | Do not concatenate raw IDs or strings into `IN (...)`. |

## Required Pattern

When a query interpolates an identifier:

1. Resolve the identifier from one of the allowed sources above.
2. Keep user-controlled values in `$wpdb->prepare()` placeholders.
3. Add a PHPCS suppression comment that names the trusted source, not a generic "safe" claim.
4. Add or update a unit test when the identifier can be influenced by request args.

## Current Enforcement Points

- `EventQuery::order_by()` keeps column names in a hard allowlist and normalizes direction. `EventQueryTest::test_order_by_rejects_sql_like_identifier_input()` covers SQL-like column and direction input.
- Admin attendee sorting uses `AttendeesPage::SORTABLE_COLUMNS` before building `ORDER BY`.
- Repository list methods using request `orderby` normalize through allowlists and/or `sanitize_sql_orderby()` before interpolation.
- Schema and migration SQL interpolate table names only from `Schema::table()` or known table-definition classes.
- `ColumnExistenceContractTest` reads the `CREATE TABLE` statements in `includes/Database/Tables` and fails if any SQL string in `includes/` filters, orders, or selects by a name that is not a column of a table that file addresses. A wrong column name is not a PHP error: MySQL rejects the query, `$wpdb` swallows it, and the method returns null. Hand-built row fixtures cannot catch it.

## Review Checklist

- [ ] No request parameter is interpolated as a table name, column name, direction, or SQL fragment.
- [ ] Dynamic table names are derived from `Schema::table()` or `$wpdb` core table properties.
- [ ] Dynamic sort columns are mapped through a local allowlist.
- [ ] Dynamic sort directions collapse to exactly `ASC` or `DESC`.
- [ ] Values remain prepared with placeholders after identifier resolution.
- [ ] Tests cover invalid identifier-like input for new public or admin query args.
