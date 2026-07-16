<?php
/**
 * WaitlistFrontend unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use NetterTechEvents\Frontend\WaitlistFrontend;

/**
 * Test WaitlistFrontend class.
 *
 * Verifies class structure, method existence, and type signatures.
 * Full render testing requires integration tests with template system.
 */
class WaitlistFrontendTest extends \NetterTechEventsTestCase {

	/**
	 * Test class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( WaitlistFrontend::class ) );
	}

	/**
	 * Test namespace is in Base plugin.
	 *
	 * @return void
	 */
	public function test_namespace_is_base(): void {
		$reflection = new \ReflectionClass( WaitlistFrontend::class );
		$this->assertSame( 'NetterTechEvents\Frontend', $reflection->getNamespaceName() );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'init',
			'register_assets',
			'render_waitlist_panel',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( WaitlistFrontend::class, $method ),
				"Method {$method} should exist"
			);
		}
	}

	/**
	 * Test init method returns void.
	 *
	 * @return void
	 */
	public function test_init_returns_void(): void {
		$reflection  = new \ReflectionMethod( WaitlistFrontend::class, 'init' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertEquals( 'void', $return_type->getName() );
	}

	/**
	 * Test register_assets is public.
	 *
	 * @return void
	 */
	public function test_register_assets_is_public(): void {
		$reflection = new \ReflectionMethod( WaitlistFrontend::class, 'register_assets' );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test render_waitlist_panel accepts TicketType and Occurrence parameters.
	 *
	 * @return void
	 */
	public function test_render_panel_parameter_types(): void {
		$reflection = new \ReflectionMethod( WaitlistFrontend::class, 'render_waitlist_panel' );
		$params     = $reflection->getParameters();

		$this->assertCount( 2, $params );
		$this->assertSame( 'ticket_type', $params[0]->getName() );
		$this->assertSame( 'occurrence', $params[1]->getName() );

		$this->assertSame(
			\NetterTechEvents\Models\TicketType::class,
			$params[0]->getType()->getName()
		);
		$this->assertSame(
			\NetterTechEvents\Models\Occurrence::class,
			$params[1]->getType()->getName()
		);
	}

	/**
	 * Test enqueue_assets is private.
	 *
	 * @return void
	 */
	public function test_enqueue_assets_is_private(): void {
		$reflection = new \ReflectionMethod( WaitlistFrontend::class, 'enqueue_assets' );
		$this->assertTrue( $reflection->isPrivate() );
	}

	/**
	 * Test assets_enqueued property exists and defaults to false.
	 *
	 * @return void
	 */
	public function test_assets_enqueued_default(): void {
		$frontend   = new WaitlistFrontend();
		$reflection = new \ReflectionProperty( WaitlistFrontend::class, 'assets_enqueued' );

		$this->assertFalse( $reflection->getValue( $frontend ) );
	}
}
