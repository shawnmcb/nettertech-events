<?php
/**
 * Container unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\Container;

/**
 * Test Container circular dependency detection.
 *
 * @coversDefaultClass \NetterTechEvents\Core\Container
 */
class ContainerTest extends \NetterTechEventsTestCase {

	/**
	 * Test circular dependency throws LogicException with cycle path.
	 *
	 * @covers ::get
	 * @return void
	 */
	public function test_circular_dependency_throws_logic_exception(): void {
		$container = new Container();

		$container->singleton(
			'ServiceA',
			function ( Container $c ): object {
				return $c->get( 'ServiceB' );
			}
		);

		$container->singleton(
			'ServiceB',
			function ( Container $c ): object {
				return $c->get( 'ServiceA' );
			}
		);

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'Circular dependency: ServiceA -> ServiceB -> ServiceA' );

		$container->get( 'ServiceA' );
	}

	/**
	 * Test three-service circular dependency includes full path.
	 *
	 * @covers ::get
	 * @return void
	 */
	public function test_three_service_circular_dependency(): void {
		$container = new Container();

		$container->singleton(
			'ServiceA',
			function ( Container $c ): object {
				return $c->get( 'ServiceB' );
			}
		);

		$container->singleton(
			'ServiceB',
			function ( Container $c ): object {
				return $c->get( 'ServiceC' );
			}
		);

		$container->singleton(
			'ServiceC',
			function ( Container $c ): object {
				return $c->get( 'ServiceA' );
			}
		);

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'Circular dependency: ServiceA -> ServiceB -> ServiceC -> ServiceA' );

		$container->get( 'ServiceA' );
	}

	/**
	 * Test non-circular resolution works normally.
	 *
	 * @covers ::get
	 * @return void
	 */
	public function test_non_circular_resolution_works(): void {
		$container = new Container();

		$container->singleton(
			'ServiceA',
			function (): object {
				return new \stdClass();
			}
		);

		$container->singleton(
			'ServiceB',
			function ( Container $c ): object {
				$obj      = new \stdClass();
				$obj->dep = $c->get( 'ServiceA' );
				return $obj;
			}
		);

		$result = $container->get( 'ServiceB' );
		$this->assertInstanceOf( \stdClass::class, $result );
		$this->assertInstanceOf( \stdClass::class, $result->dep );
	}

	/**
	 * Test overrides bypass circular dependency check.
	 *
	 * @covers ::get
	 * @return void
	 */
	public function test_overrides_bypass_circular_check(): void {
		$container = new Container();

		$override = new \stdClass();
		$container->set( 'ServiceA', $override );

		$container->singleton(
			'ServiceB',
			function ( Container $c ): object {
				$obj      = new \stdClass();
				$obj->dep = $c->get( 'ServiceA' );
				return $obj;
			}
		);

		$result = $container->get( 'ServiceB' );
		$this->assertSame( $override, $result->dep );
	}

	/**
	 * Test cached instances bypass circular dependency check.
	 *
	 * @covers ::get
	 * @return void
	 */
	public function test_cached_instances_bypass_circular_check(): void {
		$container = new Container();

		$container->singleton(
			'ServiceA',
			function (): object {
				return new \stdClass();
			}
		);

		// First resolution caches the instance.
		$first = $container->get( 'ServiceA' );

		// Second resolution uses cached instance (no factory call).
		$second = $container->get( 'ServiceA' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test resolving state is cleaned up after factory exception.
	 *
	 * @covers ::get
	 * @return void
	 */
	public function test_resolving_state_cleaned_after_exception(): void {
		$container = new Container();

		$container->singleton(
			'ServiceA',
			function (): object {
				throw new \RuntimeException( 'Factory failed' );
			}
		);

		try {
			$container->get( 'ServiceA' );
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		// Re-register with a working factory to prove resolving state was cleaned.
		$container->singleton(
			'ServiceA',
			function (): object {
				return new \stdClass();
			}
		);

		$result = $container->get( 'ServiceA' );
		$this->assertInstanceOf( \stdClass::class, $result );
	}
}
