<?php
/**
 * Deactivator class unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\Deactivator;
use Brain\Monkey\Functions;

/**
 * Test Deactivator class functionality.
 */
class DeactivatorTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test Deactivator class exists.
	 *
	 * @return void
	 */
	public function test_deactivator_class_exists(): void {
		$this->assertTrue( class_exists( Deactivator::class ) );
	}

	/**
	 * Test deactivate method is static.
	 *
	 * @return void
	 */
	public function test_deactivate_is_static(): void {
		$reflection = new \ReflectionMethod( Deactivator::class, 'deactivate' );

		$this->assertTrue( $reflection->isStatic() );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test deactivate method has void return type.
	 *
	 * @return void
	 */
	public function test_deactivate_returns_void(): void {
		$reflection  = new \ReflectionMethod( Deactivator::class, 'deactivate' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertEquals( 'void', $return_type->getName() );
	}

	// =========================================================================
	// Deactivation Flow Tests
	// =========================================================================

	/**
	 * Test deactivate clears scheduled events.
	 *
	 * @return void
	 */
	public function test_deactivate_clears_scheduled_events(): void {
		$cleared_hooks = array();

		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared_hooks ) {
				$cleared_hooks[] = $hook;
			}
		);
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );
		Functions\when( 'do_action' )->justReturn( null );

		Deactivator::deactivate();

		$this->assertContains( 'nettertech_events_generate_occurrences', $cleared_hooks );
	}

	/**
	 * Test deactivate flushes rewrite rules.
	 *
	 * @return void
	 */
	public function test_deactivate_flushes_rewrite_rules(): void {
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( null );
		Functions\when( 'do_action' )->justReturn( null );

		$flushed = false;
		Functions\when( 'flush_rewrite_rules' )->alias(
			function () use ( &$flushed ) {
				$flushed = true;
			}
		);

		Deactivator::deactivate();

		$this->assertTrue( $flushed, 'Rewrite rules should be flushed' );
	}

	/**
	 * Test deactivate fires action hook.
	 *
	 * @return void
	 */
	public function test_deactivate_fires_action_hook(): void {
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( null );
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );

		$action_fired = false;
		Functions\when( 'do_action' )->alias(
			function ( $hook ) use ( &$action_fired ) {
				if ( 'nettertech_events_deactivated' === $hook ) {
					$action_fired = true;
				}
			}
		);

		Deactivator::deactivate();

		$this->assertTrue( $action_fired, 'nettertech_events_deactivated action should fire' );
	}

	/**
	 * Test deactivate does not remove options.
	 *
	 * Options should only be removed via uninstall.php if configured.
	 *
	 * @return void
	 */
	public function test_deactivate_does_not_remove_options(): void {
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( null );
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );
		Functions\when( 'do_action' )->justReturn( null );

		$delete_called = false;
		Functions\when( 'delete_option' )->alias(
			function () use ( &$delete_called ) {
				$delete_called = true;
			}
		);

		Deactivator::deactivate();

		$this->assertFalse( $delete_called, 'delete_option should NOT be called during deactivation' );
	}

	/**
	 * Test deactivate can be called multiple times safely.
	 *
	 * @return void
	 */
	public function test_deactivate_can_be_called_multiple_times(): void {
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( null );
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );
		Functions\when( 'do_action' )->justReturn( null );

		// Should not throw errors when called multiple times.
		Deactivator::deactivate();
		Deactivator::deactivate();

		$this->assertTrue( true );
	}

	/**
	 * Test deactivate clears all cron hooks.
	 *
	 * @return void
	 */
	public function test_deactivate_clears_both_cron_hooks(): void {
		$cleared_hooks = array();

		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared_hooks ) {
				$cleared_hooks[] = $hook;
			}
		);
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );
		Functions\when( 'do_action' )->justReturn( null );

		Deactivator::deactivate();

		$this->assertContains( 'nettertech_events_generate_occurrences', $cleared_hooks );
		$this->assertContains( 'nettertech_events_daily_cleanup', $cleared_hooks );
		$this->assertContains( 'nettertech_events_send_reminder_emails', $cleared_hooks );
		$this->assertContains( 'nettertech_events_purge_activity_log_pii', $cleared_hooks );
		$this->assertContains( 'nettertech_events_sweep_expired_reservations', $cleared_hooks );
		$this->assertContains( 'nettertech_events_extend_horizons_batch', $cleared_hooks );
		$this->assertContains( 'nettertech_events_ical_static_regenerate', $cleared_hooks );
		$this->assertCount( 7, $cleared_hooks );
	}
}
