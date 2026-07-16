<?php
/**
 * Service Registry - static facade over the DI Container.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeCheckInInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistRepositoryInterface;

use NetterTechEvents\Core\Providers\AdminServiceProvider;
use NetterTechEvents\Core\Providers\CoreServiceProvider;
use NetterTechEvents\Core\Providers\FrontendServiceProvider;
use NetterTechEvents\Core\Providers\IntegrationServiceProvider;
use NetterTechEvents\Core\Providers\RepositoryServiceProvider;

/**
 * Static facade over the DI Container.
 *
 * Provides backward-compatible typed accessor methods that delegate
 * to the Container. New code should prefer constructor injection;
 * this facade exists for gradual migration and test compatibility.
 *
 * Service registration is decomposed into domain-specific providers:
 * - {@see RepositoryServiceProvider} - interface-to-implementation bindings
 * - {@see CoreServiceProvider} - capacity, email, recurrence, caching, etc.
 * - {@see FrontendServiceProvider} - shortcodes, check-in page, palette
 * - {@see IntegrationServiceProvider} - WooCommerce, Beaver Builder
 * - {@see AdminServiceProvider} - admin hooks and page handlers
 *
 * Usage (production - prefer constructor injection):
 *   $repo = ServiceRegistry::occurrence_repository();
 *
 * Usage (testing):
 *   ServiceRegistry::set( OccurrenceRepositoryInterface::class, $mock );
 *   ServiceRegistry::reset(); // Clear all overrides in tearDown()
 *
 * @since 0.9.0
 * @since 1.5.0 Refactored to delegate to Container.
 * @since 1.7.0 Decomposed into domain service providers (EN-13).
 * @api
 */
final class ServiceRegistry {

	/**
	 * DI container instance.
	 *
	 * @var Container|null
	 */
	private static ?Container $container = null;

	/**
	 * Initialize the registry with a DI container.
	 *
	 * Called during plugin bootstrap (Plugin::__construct). If not called
	 * explicitly, a container is created lazily on first access (e.g. in
	 * unit tests that don't boot the full plugin).
	 *
	 * @since 1.5.0
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	public static function init( Container $container ): void {
		self::$container = $container;
		self::register_providers( $container );
	}

	/**
	 * Get the DI container.
	 *
	 * @since 1.5.0
	 *
	 * @return Container
	 */
	public static function container(): Container {
		return self::ensure_container();
	}

	/**
	 * Ensure the container is initialized (lazy init for tests).
	 *
	 * @return Container
	 * @throws \RuntimeException If initialization fails to set the container.
	 */
	private static function ensure_container(): Container {
		if ( null === self::$container ) {
			self::init( new Container() );
		}

		$container = self::$container;
		if ( null === $container ) {
			throw new \RuntimeException( 'ServiceRegistry container failed to initialize.' );
		}

		return $container;
	}

	// =========================================================================
	// Repositories
	// =========================================================================

	/**
	 * Get the OccurrenceRepository instance.
	 *
	 * @since 0.9.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return OccurrenceRepositoryInterface
	 */
	public static function occurrence_repository(): OccurrenceRepositoryInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( OccurrenceRepositoryInterface::class );
	}

	/**
	 * Get the TicketTypeRepository instance.
	 *
	 * @since 0.9.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return TicketTypeRepositoryInterface
	 */
	public static function ticket_type_repository(): TicketTypeRepositoryInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( TicketTypeRepositoryInterface::class );
	}

	/**
	 * Get the EventRepository instance.
	 *
	 * @since 0.9.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return EventRepositoryInterface
	 */
	public static function event_repository(): EventRepositoryInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( EventRepositoryInterface::class );
	}

	/**
	 * Get the AttendeeRepository instance.
	 *
	 * @since 0.9.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return AttendeeRepositoryInterface
	 */
	public static function attendee_repository(): AttendeeRepositoryInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( AttendeeRepositoryInterface::class );
	}

	/**
	 * Get the AttendeeCheckInRepository instance.
	 *
	 * @since 1.4.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return AttendeeCheckInInterface
	 */
	public static function attendee_checkin(): AttendeeCheckInInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( AttendeeCheckInInterface::class );
	}

	/**
	 * Get the TicketRepository instance.
	 *
	 * @since 0.9.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return TicketRepositoryInterface
	 */
	public static function ticket_repository(): TicketRepositoryInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( TicketRepositoryInterface::class );
	}

	/**
	 * Get the OrganizerRepository instance.
	 *
	 * @since 0.9.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return OrganizerRepositoryInterface
	 */
	public static function organizer_repository(): OrganizerRepositoryInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( OrganizerRepositoryInterface::class );
	}

	/**
	 * Get the CategoryRepository instance.
	 *
	 * @since 0.9.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return CategoryRepositoryInterface
	 */
	public static function category_repository(): CategoryRepositoryInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( CategoryRepositoryInterface::class );
	}

	/**
	 * Get the TagRepository instance.
	 *
	 * @since 0.9.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return TagRepositoryInterface
	 */
	public static function tag_repository(): TagRepositoryInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( TagRepositoryInterface::class );
	}

	/**
	 * Get the WaitlistRepository instance.
	 *
	 * @since 1.4.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return WaitlistRepositoryInterface
	 */
	public static function waitlist_repository(): WaitlistRepositoryInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( WaitlistRepositoryInterface::class );
	}

	// =========================================================================
	// Services
	// =========================================================================

	/**
	 * Get the CapacityService instance.
	 *
	 * @since 0.9.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return CapacityServiceInterface
	 */
	public static function capacity_service(): CapacityServiceInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( CapacityServiceInterface::class );
	}

	/**
	 * Get the ActivityLogService instance.
	 *
	 * @since 0.9.2
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return ActivityLogServiceInterface
	 */
	public static function activity_log_service(): ActivityLogServiceInterface {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( ActivityLogServiceInterface::class );
	}

	// =========================================================================
	// Database
	// =========================================================================

	/**
	 * Get the WordPress database instance.
	 *
	 * @since 1.1.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @return \wpdb WordPress database instance.
	 */
	public static function wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	// =========================================================================
	// Generic Resolution
	// =========================================================================

	/**
	 * Resolve a service from the container by class or interface name.
	 *
	 * Bridge method for callers migrating away from direct instantiation
	 * but not yet receiving dependencies via constructor injection.
	 *
	 * @since 1.5.0
	 * @deprecated 2.0.0 Use constructor injection. See ADR-013.
	 *
	 * @template T of object
	 * @param string $id Fully-qualified class or interface name.
	 * @phpstan-param class-string<T> $id
	 * @return T
	 */
	public static function get( string $id ): object {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'Constructor injection (ADR-013)' );
		}
		return self::ensure_container()->get( $id );
	}

	// =========================================================================
	// Override Methods (for testing)
	// =========================================================================

	/**
	 * Set a custom implementation for an interface.
	 *
	 * @since 0.9.0
	 *
	 * @param string $contract Interface class name.
	 * @param object $instance Implementation instance.
	 * @return void
	 */
	public static function set( string $contract, object $instance ): void {
		self::ensure_container()->set( $contract, $instance );
	}

	/**
	 * Reset all overrides and cached instances.
	 *
	 * Call in test tearDown() to ensure clean state.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::ensure_container()->reset();
	}

	/**
	 * Check if an override exists for an interface.
	 *
	 * @since 0.9.0
	 *
	 * @param string $contract Interface class name.
	 * @return bool True if override exists.
	 */
	public static function has_override( string $contract ): bool {
		return self::ensure_container()->has_override( $contract );
	}

	// =========================================================================
	// Internal - Provider Registration
	// =========================================================================

	/**
	 * Service providers, in registration order.
	 *
	 * @since 1.7.0
	 *
	 * @return array<ServiceProviderInterface>
	 */
	private static function providers(): array {
		$providers = array(
			new RepositoryServiceProvider(),
			new CoreServiceProvider(),
			new FrontendServiceProvider(),
			new IntegrationServiceProvider(),
			new AdminServiceProvider(),
		);

		/**
		 * Filters the list of service providers registered with the DI container.
		 *
		 * @since 1.0.2
		 *
		 * @param array<ServiceProviderInterface> $providers The service providers.
		 */
		return apply_filters( 'nettertech_events_service_providers', $providers );
	}

	/**
	 * Register all service providers.
	 *
	 * @since 1.7.0
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	private static function register_providers( Container $container ): void {
		foreach ( self::providers() as $provider ) {
			$provider->register( $container );
		}
	}

	/**
	 * Private constructor - static class only.
	 */
	private function __construct() {}
}
