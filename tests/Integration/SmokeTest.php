<?php
/**
 * Integration test smoke test.
 *
 * Verifies the WordPress test framework is properly configured.
 *
 * @package NetterTechEvents\Tests\Integration
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration;

/**
 * Smoke test for integration test setup.
 */
class SmokeTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Test WordPress is loaded.
	 *
	 * @return void
	 */
	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( function_exists( 'add_filter' ) );
		$this->assertTrue( defined( 'ABSPATH' ) );
	}

	/**
	 * Test wpdb is available.
	 *
	 * @return void
	 */
	public function test_wpdb_is_available(): void {
		$wpdb = $this->get_wpdb();

		$this->assertNotNull( $wpdb );
		$this->assertInstanceOf( \wpdb::class, $wpdb );
	}

	/**
	 * Test database connection works.
	 *
	 * @return void
	 */
	public function test_database_connection_works(): void {
		$wpdb = $this->get_wpdb();

		// Perform a simple query to verify connection.
		$result = $wpdb->get_var( 'SELECT 1' );

		$this->assertEquals( '1', $result );
	}

	/**
	 * Test plugin classes are autoloaded.
	 *
	 * @return void
	 */
	public function test_plugin_classes_are_autoloaded(): void {
		// Core classes.
		$this->assertTrue( class_exists( 'NetterTechEvents\Core\Plugin' ) );
		$this->assertTrue( class_exists( 'NetterTechEvents\Core\Loader' ) );

		// Models.
		$this->assertTrue( class_exists( 'NetterTechEvents\Models\Event' ) );
		$this->assertTrue( class_exists( 'NetterTechEvents\Models\Occurrence' ) );
		$this->assertTrue( class_exists( 'NetterTechEvents\Models\TicketType' ) );
		$this->assertTrue( class_exists( 'NetterTechEvents\Models\Attendee' ) );

		// Repositories.
		$this->assertTrue( class_exists( 'NetterTechEvents\Repositories\EventRepository' ) );
		$this->assertTrue( class_exists( 'NetterTechEvents\Repositories\OccurrenceRepository' ) );
	}

	/**
	 * Test repositories can be instantiated.
	 *
	 * @return void
	 */
	public function test_repositories_can_be_instantiated(): void {
		$wpdb = $this->get_wpdb();

		$event_repo = new \NetterTechEvents\Repositories\EventRepository( $wpdb );
		$this->assertInstanceOf( \NetterTechEvents\Repositories\EventRepository::class, $event_repo );

		$occurrence_repo = new \NetterTechEvents\Repositories\OccurrenceRepository( $wpdb );
		$this->assertInstanceOf( \NetterTechEvents\Repositories\OccurrenceRepository::class, $occurrence_repo );
	}

	/**
	 * Test plugin tables exist.
	 *
	 * @return void
	 */
	public function test_plugin_tables_exist(): void {
		$wpdb = $this->get_wpdb();

		// The development database should have the plugin tables.
		// Note: test database prefix is 'wptests_', but we're using dev database.
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '%nte_%'" );

		// If tables don't exist, the plugin hasn't been activated in this database.
		if ( empty( $tables ) ) {
			$this->markTestSkipped(
				'Plugin tables not found. Run integration tests against dev database with activated plugin.'
			);
		}

		$this->assertNotEmpty( $tables, 'Plugin tables should exist' );
	}
}
