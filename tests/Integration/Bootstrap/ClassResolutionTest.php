<?php
/**
 * Canary regression test for NTE-038.
 *
 * Verifies that NetterTechEvents classes resolve to the worktree's
 * `includes/` directory, not to the installed sibling plugin at
 * `wp-content/plugins/nettertech-events/`. If the test-bootstrap
 * class-shadowing hazard ever returns, this test fails loudly and
 * blocks the commit before any downstream integration test masks
 * the regression with false-green or false-red behavior.
 *
 * See `tests/bootstrap-integration.php` for the structural fix
 * (Option A from the NTE-038 ticket) that this test guards.
 *
 * @package NetterTechEvents\Tests\Integration\Bootstrap
 * @since   3.8.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Bootstrap;

/**
 * Asserts plugin classes load from the worktree, not the sibling plugin.
 */
class ClassResolutionTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * The worktree's expected includes directory, resolved once.
	 *
	 * @return string
	 */
	private function expected_includes_root(): string {
		$path = realpath( dirname( __DIR__, 3 ) . '/includes' );
		$this->assertNotFalse(
			$path,
			'Worktree includes/ directory must exist for this canary test to run.'
		);

		return $path;
	}

	/**
	 * Every listed class must resolve to a file under the worktree's includes/.
	 *
	 * The list covers one class per top-level subsystem: core bootstrap,
	 * repository layer, model layer, service layer, and frontend shortcode.
	 * If any of these resolve outside the worktree, the autoloader race is
	 * back and `tests/bootstrap-integration.php` is no longer doing its job.
	 *
	 * @return void
	 */
	public function test_classes_resolve_to_worktree_not_installed_plugin(): void {
		$expected_root = $this->expected_includes_root();

		$classes_to_check = array(
			\NetterTechEvents\Core\Plugin::class,
			\NetterTechEvents\Repositories\EventRepository::class,
			\NetterTechEvents\Repositories\TicketTypeRepository::class,
			\NetterTechEvents\Models\Attendee::class,
			\NetterTechEvents\Models\Event::class,
			\NetterTechEvents\Services\CapacityCalculator::class,
			\NetterTechEvents\Services\RsvpCapacityHandler::class,
			\NetterTechEvents\Frontend\Shortcodes\RSVPFormShortcode::class,
		);

		foreach ( $classes_to_check as $class_name ) {
			$reflection = new \ReflectionClass( $class_name );
			$actual     = realpath( $reflection->getFileName() );

			$this->assertNotFalse(
				$actual,
				sprintf( '%s has no resolvable file path', $class_name )
			);

			$this->assertStringStartsWith(
				$expected_root,
				$actual,
				sprintf(
					'%s resolved from %s, expected a path under %s. ' .
					'The sibling `nettertech-events/` plugin may be shadowing the worktree — ' .
					'see tests/bootstrap-integration.php for the NTE-038 fix.',
					$class_name,
					$actual,
					$expected_root
				)
			);
		}
	}

	/**
	 * The sibling plugin must not appear in active_plugins during the test run.
	 *
	 * The NTE-038 fix suppresses `nettertech-events/nettertech-events.php`
	 * from the active_plugins list via a pre_option_active_plugins filter.
	 * If this test fails, the filter has been removed or is no longer
	 * taking effect, and class shadowing will return on the next integration
	 * test run.
	 *
	 * @return void
	 */
	public function test_sibling_nettertech_events_plugin_is_not_active(): void {
		$active = get_option( 'active_plugins', array() );
		$this->assertIsArray( $active );
		$this->assertNotContains(
			'nettertech-events/nettertech-events.php',
			$active,
			'The sibling `nettertech-events/` plugin must be filtered out of active_plugins ' .
			'during integration tests to prevent class shadowing. See NTE-038 fix in ' .
			'tests/bootstrap-integration.php.'
		);
	}
}
