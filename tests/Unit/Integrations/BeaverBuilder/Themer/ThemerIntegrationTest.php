<?php
/**
 * ThemerIntegration unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer;

use NetterTechEvents\Integrations\BeaverBuilder\Themer\ThemerIntegration;

/**
 * Test ThemerIntegration orchestrator.
 *
 * Covers the gating on FLThemeBuilder presence and the no-op-when-absent
 * branch. Subsystem wiring is exercised by each subsystem's own test file.
 *
 * @coversDefaultClass \NetterTechEvents\Integrations\BeaverBuilder\Themer\ThemerIntegration
 */
class ThemerIntegrationTest extends \NetterTechEventsTestCase {

	/**
	 * is_themer_active() reports false when FLThemeBuilder is not loaded.
	 *
	 * Runs in an isolated process: a sibling test eval()s an FLThemeBuilder stub into
	 * the global class table, and PHP cannot undefine a class. Without isolation this
	 * assertion depends on execution order and fails under Infection's random run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_is_themer_active_false_when_absent(): void {
		$this->assertFalse(
			class_exists( 'FLThemeBuilder' ),
			'FLThemeBuilder must be absent in the unit-test environment.'
		);

		$this->assertFalse( ThemerIntegration::is_themer_active() );
	}

	/**
	 * init() is a safe no-op when FLThemeBuilder is missing.
	 *
	 * @return void
	 */
	public function test_init_is_noop_when_themer_absent(): void {
		$integration = new ThemerIntegration();
		$integration->init();

		// Reaching this line without fatal is the assertion.
		$this->assertTrue( true );
	}

	/**
	 * init() runs without error when FLThemeBuilder is present (stubbed).
	 *
	 * @return void
	 */
	public function test_init_runs_when_themer_present(): void {
		if ( ! class_exists( 'FLThemeBuilder' ) ) {
			eval( 'class FLThemeBuilder { /* stub */ }' );
		}

		$integration = new ThemerIntegration();
		$integration->init();

		$this->assertTrue( ThemerIntegration::is_themer_active() );
	}
}
