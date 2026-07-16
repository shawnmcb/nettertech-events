<?php
/**
 * Activator class unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\Activator;

/**
 * Test Activator class structure and constants.
 *
 * Note: Full activation flow testing requires integration tests since
 * the activate() method calls Schema::create_tables() which needs
 * a real database. These tests verify the class structure.
 */
class ActivatorTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test Activator class exists.
	 *
	 * @return void
	 */
	public function test_activator_class_exists(): void {
		$this->assertTrue( class_exists( Activator::class ) );
	}

	/**
	 * Test activate method exists and is static.
	 *
	 * @return void
	 */
	public function test_activate_is_static(): void {
		$reflection = new \ReflectionMethod( Activator::class, 'activate' );

		$this->assertTrue( $reflection->isStatic() );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test activate method has void return type.
	 *
	 * @return void
	 */
	public function test_activate_returns_void(): void {
		$reflection  = new \ReflectionMethod( Activator::class, 'activate' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertEquals( 'void', $return_type->getName() );
	}

	/**
	 * Test private helper methods exist.
	 *
	 * @return void
	 */
	public function test_private_methods_exist(): void {
		$reflection = new \ReflectionClass( Activator::class );

		$expected_methods = array(
			'check_requirements',
			'create_tables',
			'set_default_options',
			'schedule_cron_events',
			'flush_rewrite_rules',
		);

		foreach ( $expected_methods as $method ) {
			$this->assertTrue(
				$reflection->hasMethod( $method ),
				"Method {$method} should exist"
			);
		}
	}

	/**
	 * Test private methods are actually private.
	 *
	 * @return void
	 */
	public function test_helper_methods_are_private(): void {
		$reflection = new \ReflectionClass( Activator::class );

		$private_methods = array(
			'check_requirements',
			'create_tables',
			'set_default_options',
			'schedule_cron_events',
			'flush_rewrite_rules',
		);

		foreach ( $private_methods as $method_name ) {
			$method = $reflection->getMethod( $method_name );
			$this->assertTrue(
				$method->isPrivate(),
				"Method {$method_name} should be private"
			);
		}
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test MIN_PHP_VERSION constant exists and has valid value.
	 *
	 * @return void
	 */
	public function test_min_php_version_constant(): void {
		$reflection = new \ReflectionClass( Activator::class );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey( 'MIN_PHP_VERSION', $constants );
		$this->assertEquals( '8.2', $constants['MIN_PHP_VERSION'] );
	}

	/**
	 * Test MIN_WP_VERSION constant exists and has valid value.
	 *
	 * @return void
	 */
	public function test_min_wp_version_constant(): void {
		$reflection = new \ReflectionClass( Activator::class );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey( 'MIN_WP_VERSION', $constants );
		$this->assertEquals( '6.0', $constants['MIN_WP_VERSION'] );
	}

	/**
	 * Test version constants are valid semver format.
	 *
	 * @return void
	 */
	public function test_version_constants_are_valid(): void {
		$reflection = new \ReflectionClass( Activator::class );
		$constants  = $reflection->getConstants();

		// Semver: major.minor or major.minor.patch.
		$semver_pattern = '/^\d+\.\d+(\.\d+)?$/';

		$this->assertMatchesRegularExpression(
			$semver_pattern,
			$constants['MIN_PHP_VERSION'],
			'MIN_PHP_VERSION should be valid semver'
		);

		$this->assertMatchesRegularExpression(
			$semver_pattern,
			$constants['MIN_WP_VERSION'],
			'MIN_WP_VERSION should be valid semver'
		);
	}

	// =========================================================================
	// Current Environment Tests
	// =========================================================================

	/**
	 * Test current PHP version meets requirements.
	 *
	 * @return void
	 */
	public function test_current_php_meets_requirements(): void {
		$reflection = new \ReflectionClass( Activator::class );
		$constants  = $reflection->getConstants();

		$this->assertTrue(
			version_compare( PHP_VERSION, $constants['MIN_PHP_VERSION'], '>=' ),
			'Current PHP version should meet minimum requirements'
		);
	}
}
