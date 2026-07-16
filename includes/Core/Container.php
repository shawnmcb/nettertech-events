<?php
/**
 * Dependency injection container.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight DI container for centralized dependency management.
 *
 * Provides singleton lazy instantiation with factory closures, test
 * overrides, and an explicit service registration API. Replaces the
 * static ServiceRegistry pattern (ARCH-5 / ADR-002).
 *
 * @since 1.5.0
 * @api
 */
final class Container {

	/**
	 * Factory closures keyed by service identifier.
	 *
	 * @var array<string, \Closure>
	 */
	private array $factories = array();

	/**
	 * Cached singleton instances.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Test overrides (take precedence over factory resolution).
	 *
	 * @var array<string, object>
	 */
	private array $overrides = array();

	/**
	 * Services currently being resolved (for circular dependency detection).
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Register a singleton factory.
	 *
	 * The factory receives the container as its first argument,
	 * enabling dependent service resolution.
	 *
	 * @since 1.5.0
	 *
	 * @param string   $id      Service identifier (typically interface FQCN).
	 * @param \Closure $factory Factory closure: fn(Container $c): object.
	 * @return void
	 */
	public function singleton( string $id, \Closure $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Resolve a service by identifier.
	 *
	 * Resolution order: test override → cached instance → factory.
	 *
	 * @since 1.5.0
	 *
	 * @template T of object
	 * @param string $id Service identifier.
	 * @phpstan-param class-string<T> $id
	 * @return object
	 * @phpstan-return T
	 *
	 * @throws \InvalidArgumentException If no factory registered for $id.
	 * @throws \LogicException           If a circular dependency is detected.
	 */
	public function get( string $id ): object {
		if ( isset( $this->overrides[ $id ] ) ) {
			return $this->overrides[ $id ]; // @phpstan-ignore return.type (value stored as object; PHPStan sees mixed from array<string,mixed> typing)
		}

		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ]; // @phpstan-ignore return.type (value stored as object; PHPStan sees mixed from array<string,mixed> typing)
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Service not registered: %s', esc_html( $id ) )
			);
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new \LogicException(
				'Circular dependency: '
				. implode( ' -> ', array_map( 'esc_html', array_keys( $this->resolving ) ) )
				. ' -> '
				. esc_html( $id )
			);
		}

		$this->resolving[ $id ] = true;

		try {
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		return $this->instances[ $id ];
	}

	/**
	 * Check whether a service is registered.
	 *
	 * @since 1.5.0
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] )
			|| isset( $this->instances[ $id ] )
			|| isset( $this->overrides[ $id ] );
	}

	/**
	 * Set a test override for a service.
	 *
	 * Overrides take precedence over factory resolution.
	 *
	 * @since 1.5.0
	 *
	 * @param string $id       Service identifier.
	 * @param object $instance Override instance.
	 * @return void
	 */
	public function set( string $id, object $instance ): void {
		$this->overrides[ $id ] = $instance;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Check if a test override exists for a service.
	 *
	 * @since 1.5.0
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has_override( string $id ): bool {
		return isset( $this->overrides[ $id ] );
	}

	/**
	 * Reset all overrides and cached instances.
	 *
	 * Call in test tearDown() to ensure clean state.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->instances = array();
		$this->overrides = array();
	}
}
