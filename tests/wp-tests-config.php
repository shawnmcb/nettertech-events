<?php
/**
 * WordPress Test Configuration for Integration Tests.
 *
 * This file configures the WordPress test framework for running
 * integration tests with a real WordPress database.
 *
 * @package NetterTechEvents\Tests
 */

// Path to WordPress source (not the tests).
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
}

/*
 * Test database configuration. Env vars: NTE_TEST_DB_HOST, NTE_TEST_DB_NAME,
 * NTE_TEST_DB_USER, NTE_TEST_DB_PASSWORD.
 *
 * NTE-146: the default MUST be a disposable database, never the dev site's live database.
 * The suite is destructive — FixtureFactory::tear_down() TRUNCATEs every plugin
 * table, and TRUNCATE is DDL, so it auto-commits and defeats the base test case's
 * START TRANSACTION / ROLLBACK isolation. Pointed at `local` (as it was until
 * 2026-07-12) a single run wipes every occurrence, ticket type and attendee on the
 * dev site. `local_nte_test` is a clone of `local`, rebuildable at any time:
 *
 *   mysqldump ... local | mysql ... local_nte_test
 *
 * FixtureFactory enforces the `_test` suffix independently, so overriding this back
 * to a real database throws rather than truncating.
 */
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', getenv( 'NTE_TEST_DB_NAME' ) ?: 'local_nte_test' );
}
if ( ! defined( 'DB_USER' ) ) {
	define( 'DB_USER', getenv( 'NTE_TEST_DB_USER' ) ?: 'root' );
}
if ( ! defined( 'DB_PASSWORD' ) ) {
	define( 'DB_PASSWORD', getenv( 'NTE_TEST_DB_PASSWORD' ) ?: 'root' );
}
if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'NTE_TEST_DB_HOST' ) ?: 'localhost' );
}
if ( ! defined( 'DB_CHARSET' ) ) {
	define( 'DB_CHARSET', 'utf8' );
}
if ( ! defined( 'DB_COLLATE' ) ) {
	define( 'DB_COLLATE', '' );
}

// The wp_ prefix matches the dev site's schema, so the clone in DB_NAME boots identically
// to the dev site. Isolation comes from the database being disposable (NTE-146), NOT from
// the prefix and NOT from transaction rollback alone — see the DB_NAME note above.
$table_prefix = 'wp_';

// Test with debug mode enabled.
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

// Honour Pro test license keys (TEST-PRO-*, TEST-STARTER-*, etc.). These
// integration tests boot a dev database where NetterTech Events Pro may be
// active. Without this constant Pro's LicenseService rejects the test key,
// falls back to the free tier, and caches that into the shared
// nettertech_events_pro_license_data transient in the live database,
// leaving the site on a free license after the suite runs.
if ( ! defined( 'NETTERTECH_EVENTS_LICENSE_TEST_MODE' ) ) {
	define( 'NETTERTECH_EVENTS_LICENSE_TEST_MODE', true );
}

// Domain for tests.
if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
	define( 'WP_TESTS_DOMAIN', getenv( 'NTE_TEST_DOMAIN' ) ?: 'example.test' );
}
if ( ! defined( 'WP_TESTS_EMAIL' ) ) {
	define( 'WP_TESTS_EMAIL', getenv( 'NTE_TEST_EMAIL' ) ?: 'admin@example.test' );
}
if ( ! defined( 'WP_TESTS_TITLE' ) ) {
	define( 'WP_TESTS_TITLE', 'NetterTech Events Test Site' );
}

// PHP error logging.
if ( ! defined( 'WP_PHP_BINARY' ) ) {
	define( 'WP_PHP_BINARY', 'php' );
}
