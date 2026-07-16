<?php
/**
 * TicketDisplay unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use NetterTechEvents\Frontend\TicketDisplay;

/**
 * Test TicketDisplay class structure.
 *
 * Note: Full render testing requires database integration tests since
 * the class depends on TicketTypeRepository queries. These tests
 * verify class structure and method existence.
 */
class TicketDisplayTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test TicketDisplay class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( TicketDisplay::class ) );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'init',
			'render_occurrence_actions',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( TicketDisplay::class, $method ),
				"Method {$method} should exist"
			);
		}
	}

	/**
	 * Test init method is static.
	 *
	 * @return void
	 */
	public function test_init_is_static(): void {
		$reflection = new \ReflectionMethod( TicketDisplay::class, 'init' );
		$this->assertTrue( $reflection->isStatic() );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test init method returns void.
	 *
	 * @return void
	 */
	public function test_init_returns_void(): void {
		$reflection  = new \ReflectionMethod( TicketDisplay::class, 'init' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertEquals( 'void', $return_type->getName() );
	}

	/**
	 * Test render_occurrence_actions is static.
	 *
	 * @return void
	 */
	public function test_render_occurrence_actions_is_static(): void {
		$reflection = new \ReflectionMethod( TicketDisplay::class, 'render_occurrence_actions' );
		$this->assertTrue( $reflection->isStatic() );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test render_occurrence_actions returns void.
	 *
	 * @return void
	 */
	public function test_render_occurrence_actions_returns_void(): void {
		$reflection  = new \ReflectionMethod( TicketDisplay::class, 'render_occurrence_actions' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertEquals( 'void', $return_type->getName() );
	}

	/**
	 * Test render_occurrence_actions accepts correct parameters.
	 *
	 * @return void
	 */
	public function test_render_occurrence_actions_parameters(): void {
		$reflection = new \ReflectionMethod( TicketDisplay::class, 'render_occurrence_actions' );
		$params     = $reflection->getParameters();

		$this->assertCount( 2, $params );
		$this->assertEquals( 'occurrence', $params[0]->getName() );
		$this->assertEquals( 'event', $params[1]->getName() );
	}

	/**
	 * Test class has scripts_enqueued property.
	 *
	 * @return void
	 */
	public function test_has_scripts_enqueued_property(): void {
		$reflection = new \ReflectionClass( TicketDisplay::class );

		$this->assertTrue(
			$reflection->hasProperty( 'scripts_enqueued' ),
			'Class should have scripts_enqueued property'
		);
	}

	/**
	 * Test static properties are boolean.
	 *
	 * @return void
	 */
	public function test_static_properties_are_boolean(): void {
		$reflection = new \ReflectionClass( TicketDisplay::class );

		$scripts_prop = $reflection->getProperty( 'scripts_enqueued' );

		$this->assertIsBool( $scripts_prop->getValue() );
	}

	// =========================================================================
	// Regression Tests
	// =========================================================================

	/**
	 * Test ticket form scripts are not attached to a stylesheet handle.
	 *
	 * Regression: BUG-004. wp_add_inline_script() was called with the
	 * 'nettertech-events-base' handle, which is registered as a stylesheet via
	 * wp_register_style(). Inline scripts attached to stylesheet handles
	 * are silently discarded by WordPress, breaking ticket +/- buttons.
	 *
	 * Since 1.2.0, scripts use wp_enqueue_script() for an external JS file.
	 * The old method is deprecated and delegates to enqueue_ticket_scripts().
	 *
	 * @return void
	 */
	public function test_ticket_form_scripts_not_on_stylesheet_handle(): void {
		$reflection = new \ReflectionMethod( TicketDisplay::class, 'enqueue_ticket_scripts' );
		$file       = $reflection->getFileName();
		$start      = $reflection->getStartLine();
		$end        = $reflection->getEndLine();

		$lines  = array_slice( file( $file ), $start - 1, $end - $start + 1 );
		$source = implode( '', $lines );

		// Must NOT use wp_add_inline_script with a stylesheet handle.
		$this->assertStringNotContainsString(
			"wp_add_inline_script( 'nettertech-events-base'",
			$source,
			'Ticket form scripts must not use wp_add_inline_script with a stylesheet handle (BUG-004)'
		);

		// Must use wp_enqueue_script for an external JS file.
		$this->assertStringContainsString(
			'wp_enqueue_script',
			$source,
			'Ticket scripts should use wp_enqueue_script for an external JS file'
		);
	}

	/**
	 * Test class has private helper methods.
	 *
	 * @return void
	 */
	public function test_has_private_helper_methods(): void {
		$reflection = new \ReflectionClass( TicketDisplay::class );
		$methods    = $reflection->getMethods( \ReflectionMethod::IS_PRIVATE );

		$method_names = array_map( fn( $m ) => $m->getName(), $methods );

		// Should have helper methods for scripts.
		$this->assertContains( 'enqueue_scripts', $method_names );
		$this->assertContains( 'enqueue_ticket_scripts', $method_names );
	}
}
