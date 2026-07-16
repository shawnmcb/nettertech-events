<?php
/**
 * Test stub for NetterTechEvents\nettertech_events_container().
 *
 * Defines a permanent PHP-level function so that function_exists() checks in
 * templates return a consistent true value regardless of test execution order.
 *
 * Block render tests register a mock-populated Container via $nettertech_events_test_container
 * global in setUp() and clear it in tearDown(). All other tests receive a
 * fallback container with null-safe stubs for the services that templates call
 * via nettertech_events_container() (primarily TicketTypeRepositoryInterface and TagRepositoryInterface
 * referenced in templates/parts/event-card.php).
 *
 * Stub classes are in their own file (stub-repositories.php) so both files can
 * use a top-level namespace declaration.
 *
 * @package NetterTechEvents\Tests\Stubs
 */

declare(strict_types=1);

namespace NetterTechEvents;

require_once __DIR__ . '/stub-repositories.php';

/**
 * Return the active test container, or a safe fallback container.
 *
 * Block render tests register a mock-populated Container via $nettertech_events_test_container
 * in setUp() and clear it in tearDown(). All other tests get a shared fallback
 * container with no-op service stubs, preserving existing test behaviour.
 *
 * @return \NetterTechEvents\Core\Container
 */
function nettertech_events_container(): \NetterTechEvents\Core\Container { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- namespaced under NetterTechEvents.
	global $nettertech_events_test_container;
	if ( $nettertech_events_test_container instanceof \NetterTechEvents\Core\Container ) {
		return $nettertech_events_test_container;
	}

	static $fallback = null;
	if ( null === $fallback ) {
		$fallback = new \NetterTechEvents\Core\Container();
		$fallback->singleton(
			\NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class,
			function (): \NetterTechEvents\Tests\Stubs\StubTicketTypeRepository {
				return new \NetterTechEvents\Tests\Stubs\StubTicketTypeRepository();
			}
		);
		$fallback->singleton(
			\NetterTechEvents\Contracts\TagRepositoryInterface::class,
			function (): \NetterTechEvents\Tests\Stubs\StubTagRepository {
				return new \NetterTechEvents\Tests\Stubs\StubTagRepository();
			}
		);

		// Stateless support services resolved by render-method DI (SA-19 closure).
		// These are pure-value helpers — registering them as no-arg singletons mirrors
		// the production binding in AdminServiceProvider/CoreServiceProvider and lets
		// unit tests exercise render paths that now call the container.
		$fallback->singleton(
			\NetterTechEvents\Admin\SettingsSanitizer::class,
			fn() => new \NetterTechEvents\Admin\SettingsSanitizer()
		);
		$fallback->singleton(
			\NetterTechEvents\Services\LayoutService::class,
			fn() => new \NetterTechEvents\Services\LayoutService()
		);
		$fallback->singleton(
			\NetterTechEvents\Services\PaletteResolver::class,
			fn() => new \NetterTechEvents\Services\PaletteResolver()
		);
		$fallback->singleton(
			\NetterTechEvents\Services\PathConflictDetector::class,
			fn() => new \NetterTechEvents\Services\PathConflictDetector()
		);

		// Permissive null-object stubs for page handlers and list tables that
		// the production DI binding constructs from a live $wpdb + repo graph.
		// Unit tests that exercise render paths don't need a real WP_List_Table;
		// they assert against the surrounding template scaffolding, so a Mockery
		// mock with shouldIgnoreMissing() (returns null from any method call) is
		// sufficient and avoids pulling in WP_List_Table or wpdb into Unit suites.
		$null_safe_factories = array(
			\NetterTechEvents\Admin\ListTables\EventsListTable::class,
			\NetterTechEvents\Admin\OrganizerPage::class,
			\NetterTechEvents\Admin\SpacesPage::class,
			\NetterTechEvents\Admin\CategoryPage::class,
			\NetterTechEvents\Admin\AttendeesPage::class,
			\NetterTechEvents\Admin\CsvImportPage::class,
			\NetterTechEvents\Admin\ActivityLogPage::class,
			\NetterTechEvents\Admin\SettingsPage::class,
		);
		foreach ( $null_safe_factories as $fqcn ) {
			$fallback->singleton(
				$fqcn,
				function () use ( $fqcn ): object {
					return \Mockery::mock( $fqcn )->shouldIgnoreMissing();
				}
			);
		}
	}

	return $fallback;
}
