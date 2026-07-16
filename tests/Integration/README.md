# NetterTech Events Integration Tests

Integration tests run the WordPress test framework against a real MySQL database. The bootstrap (`tests/wp-tests-config.php`) reads connection settings from environment variables, falling back to portable defaults when none are set.

## Database environment variables

| Variable | Purpose | Fallback |
|----------|---------|----------|
| `NTE_TEST_DB_HOST` | MySQL host or `localhost:/path/to/socket` | `localhost` |
| `NTE_TEST_DB_NAME` | Database name | `local_nte_test` |
| `NTE_TEST_DB_USER` | Database user | `root` |
| `NTE_TEST_DB_PASSWORD` | Database password | `root` |
| `NTE_TEST_DOMAIN` | WP test-site domain | `example.test` |
| `NTE_TEST_EMAIL` | WP test-site admin email | `admin@example.test` |

## Local by Flywheel setups

Flywheel's MySQL listens on a per-site socket rather than TCP. Export the socket form before invoking the suite (find the socket under the site's `run/` directory in Local's application-support folder):

```
export NTE_TEST_DB_HOST="localhost:/absolute/path/to/mysqld.sock"
export NTE_TEST_DOMAIN="your-site.local"
composer test:integration
```

## Clean-room (wp-env / ddev)

Export all four `NTE_TEST_DB_*` variables before invoking the suite. Example for a `wp-env` instance with TCP MySQL on port 3306:

```
export NTE_TEST_DB_HOST=127.0.0.1:3306
export NTE_TEST_DB_NAME=wordpress
export NTE_TEST_DB_USER=root
export NTE_TEST_DB_PASSWORD=password
composer test:integration
```

The Tier 4 submission gate (`scripts/nte-submission-gate.sh`) uses this path to run integration tests against a freshly provisioned clean install rather than the developer's working database.

## Notes

- Test isolation comes from `START TRANSACTION / ROLLBACK` in the integration base test case; the bootstrap intentionally points at the live `wp_` table prefix.
- If you need to run against a non-default socket on macOS, use the `localhost:/absolute/path/to/mysqld.sock` form for `NTE_TEST_DB_HOST`.
