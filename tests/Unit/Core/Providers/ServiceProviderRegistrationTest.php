<?php
/**
 * Tests for service provider registration.
 *
 * Verifies that the decomposed provider pattern correctly registers
 * all services that were previously in the monolithic register_services().
 *
 * @package NetterTechEvents\Tests\Unit\Core\Providers
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core\Providers;

use NetterTechEvents\Core\Container;
use NetterTechEvents\Core\ServiceProviderInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Core\Providers\AdminServiceProvider;
use NetterTechEvents\Core\Providers\CoreServiceProvider;
use NetterTechEvents\Core\Providers\FrontendServiceProvider;
use NetterTechEvents\Core\Providers\IntegrationServiceProvider;
use NetterTechEvents\Core\Providers\RepositoryServiceProvider;

use NetterTechEvents\Contracts\AttendeeCheckInInterface;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EmailTemplateRendererInterface;
use NetterTechEvents\Contracts\EventDeletionCascadeInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceFilterRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceQueryRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\ReminderLogRepositoryInterface;
use NetterTechEvents\Contracts\ReservationManagerInterface;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistRepositoryInterface;

use NetterTechEvents\Core\ActivityLogHooks;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Frontend\CheckInPageHandler;
use NetterTechEvents\Frontend\Shortcodes\CalendarShortcode;
use NetterTechEvents\Frontend\Shortcodes\CarouselShortcode;
use NetterTechEvents\Frontend\Shortcodes\EventListShortcode;
use NetterTechEvents\Frontend\Shortcodes\RSVPFormShortcode;
use NetterTechEvents\Frontend\Shortcodes\RegularsShortcode;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Services\CalendarLinkService;
use NetterTechEvents\Services\CheckInReportService;
use NetterTechEvents\Services\CheckInTokenService;
use NetterTechEvents\Services\CookieCheckInService;
use NetterTechEvents\Services\CsvColumnMapper;
use NetterTechEvents\Services\CsvImporter;
use NetterTechEvents\Services\CsvParser;
use NetterTechEvents\Services\CsvValidator;
use NetterTechEvents\Services\EmailService;
use NetterTechEvents\Services\EventDuplicationService;
use NetterTechEvents\Services\ExportService;
use NetterTechEvents\Services\ICalService;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Services\OccurrenceHorizonExtender;
use NetterTechEvents\Services\PaletteResolver;
use NetterTechEvents\Services\PrivacyService;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\ReminderEmailService;
use NetterTechEvents\Services\RevisionService;
use NetterTechEvents\Services\TicketTypeSaver;
use NetterTechEvents\Services\WaitlistEmailHandler;
use NetterTechEvents\Services\WaitlistService;

/**
 * Test that decomposed service providers register all expected services.
 *
 * @group wiring
 */
class ServiceProviderRegistrationTest extends \NetterTechEventsTestCase {

	/**
	 * Reset ServiceRegistry between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ServiceRegistry::reset();
		parent::tearDown();
	}

	// =========================================================================
	// Provider Interface Tests
	// =========================================================================

	/**
	 * Test all providers implement ServiceProviderInterface.
	 *
	 * @return void
	 */
	public function test_all_providers_implement_interface(): void {
		$providers = array(
			new RepositoryServiceProvider(),
			new CoreServiceProvider(),
			new FrontendServiceProvider(),
			new IntegrationServiceProvider(),
			new AdminServiceProvider(),
		);

		foreach ( $providers as $provider ) {
			$this->assertInstanceOf(
				ServiceProviderInterface::class,
				$provider,
				get_class( $provider ) . ' must implement ServiceProviderInterface'
			);
		}
	}

	// =========================================================================
	// Repository Provider Tests
	// =========================================================================

	/**
	 * Test RepositoryServiceProvider registers all repository interfaces.
	 *
	 * @return void
	 */
	public function test_repository_provider_registers_all_repos(): void {
		$container = ServiceRegistry::container();

		$repo_interfaces = array(
			OccurrenceFilterRepositoryInterface::class,
			OccurrenceQueryRepositoryInterface::class,
			OccurrenceRepositoryInterface::class,
			TicketTypeRepositoryInterface::class,
			EventRepositoryInterface::class,
			AttendeeRepositoryInterface::class,
			AttendeeCheckInInterface::class,
			TicketRepositoryInterface::class,
			OrganizerRepositoryInterface::class,
			CategoryRepositoryInterface::class,
			TagRepositoryInterface::class,
			WaitlistRepositoryInterface::class,
			AttendeeFieldRepositoryInterface::class,
			AttendeeFieldValueRepositoryInterface::class,
			SpaceRepositoryInterface::class,
			RevisionRepositoryInterface::class,
			ReminderLogRepositoryInterface::class,
		);

		foreach ( $repo_interfaces as $interface ) {
			$this->assertTrue(
				$container->has( $interface ),
				$interface . ' not registered by RepositoryServiceProvider'
			);
		}
	}

	// =========================================================================
	// Core Provider Tests
	// =========================================================================

	/**
	 * Test CoreServiceProvider registers capacity services.
	 *
	 * @return void
	 */
	public function test_core_provider_registers_capacity_services(): void {
		$container = ServiceRegistry::container();

		$this->assertTrue( $container->has( ReservationManagerInterface::class ) );
		$this->assertTrue( $container->has( CapacityCalculatorInterface::class ) );
		$this->assertTrue( $container->has( CapacityServiceInterface::class ) );
	}

	/**
	 * Test CoreServiceProvider registers email services.
	 *
	 * @return void
	 */
	public function test_core_provider_registers_email_services(): void {
		$container = ServiceRegistry::container();

		$this->assertTrue( $container->has( EmailTemplateRendererInterface::class ) );
		$this->assertTrue( $container->has( EmailService::class ) );
		$this->assertTrue( $container->has( ReminderEmailService::class ) );
		$this->assertTrue( $container->has( WaitlistEmailHandler::class ) );
	}

	/**
	 * Test CoreServiceProvider registers settings.
	 *
	 * @return void
	 */
	public function test_core_provider_registers_settings(): void {
		$container = ServiceRegistry::container();

		$this->assertTrue( $container->has( NetterTechEventsSettings::class ) );
	}

	/**
	 * Test CoreServiceProvider registers CacheManager.
	 *
	 * @return void
	 */
	public function test_core_provider_registers_cache_manager(): void {
		$container = ServiceRegistry::container();

		$this->assertTrue( $container->has( CacheManager::class ) );
	}

	/**
	 * Test CacheManager resolves from container.
	 *
	 * @return void
	 */
	public function test_cache_manager_resolves_from_container(): void {
		$cache_manager = ServiceRegistry::container()->get( CacheManager::class );

		$this->assertInstanceOf( CacheManager::class, $cache_manager );
	}

	/**
	 * Test CoreServiceProvider registers utility services.
	 *
	 * @return void
	 */
	public function test_core_provider_registers_utility_services(): void {
		$container = ServiceRegistry::container();

		$this->assertTrue( $container->has( ActivityLogServiceInterface::class ) );
		$this->assertTrue( $container->has( EventDeletionCascadeInterface::class ) );
		$this->assertTrue( $container->has( EventDuplicationService::class ) );
		$this->assertTrue( $container->has( RecurrenceService::class ) );
		$this->assertTrue( $container->has( RateLimitService::class ) );
		$this->assertTrue( $container->has( PrivacyService::class ) );
		$this->assertTrue( $container->has( ICalService::class ) );
		$this->assertTrue( $container->has( ExportService::class ) );
		$this->assertTrue( $container->has( LayoutService::class ) );
		$this->assertTrue( $container->has( CsvImporter::class ) );
	}

	// =========================================================================
	// Frontend Provider Tests
	// =========================================================================

	/**
	 * Test FrontendServiceProvider registers shortcodes.
	 *
	 * @return void
	 */
	public function test_frontend_provider_registers_shortcodes(): void {
		$container = ServiceRegistry::container();

		$this->assertTrue( $container->has( EventListShortcode::class ) );
		$this->assertTrue( $container->has( RegularsShortcode::class ) );
		$this->assertTrue( $container->has( CarouselShortcode::class ) );
		$this->assertTrue( $container->has( CalendarShortcode::class ) );
		$this->assertTrue( $container->has( RSVPFormShortcode::class ) );
	}

	/**
	 * Test FrontendServiceProvider registers page handlers.
	 *
	 * @return void
	 */
	public function test_frontend_provider_registers_page_handlers(): void {
		$container = ServiceRegistry::container();

		$this->assertTrue( $container->has( PaletteResolver::class ) );
	}

	// =========================================================================
	// Integration Provider Tests
	// =========================================================================

	/**
	 * Test IntegrationServiceProvider registers WooCommerce services.
	 *
	 * @return void
	 */
	public function test_integration_provider_registers_woocommerce(): void {
		$container = ServiceRegistry::container();

		$this->assertTrue( $container->has( ProductManager::class ) );
	}

	// =========================================================================
	// Admin Provider Tests
	// =========================================================================

	/**
	 * Test AdminServiceProvider registers admin hooks.
	 *
	 * @return void
	 */
	public function test_admin_provider_registers_hooks(): void {
		$container = ServiceRegistry::container();

		$this->assertTrue( $container->has( ActivityLogHooks::class ) );
	}

	// =========================================================================
	// Backward Compatibility Tests
	// =========================================================================

	/**
	 * Test ServiceRegistry public API unchanged after decomposition.
	 *
	 * @return void
	 */
	public function test_service_registry_public_api_unchanged(): void {
		// Typed accessors still work.
		$this->assertInstanceOf(
			OccurrenceRepositoryInterface::class,
			ServiceRegistry::occurrence_repository()
		);
		$this->assertInstanceOf(
			EventRepositoryInterface::class,
			ServiceRegistry::event_repository()
		);
		$this->assertInstanceOf(
			CapacityServiceInterface::class,
			ServiceRegistry::capacity_service()
		);

		// Override/reset still work.
		$mock = $this->createMock( OccurrenceRepositoryInterface::class );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $mock );
		$this->assertSame( $mock, ServiceRegistry::occurrence_repository() );
		$this->assertTrue( ServiceRegistry::has_override( OccurrenceRepositoryInterface::class ) );

		ServiceRegistry::reset();
		$this->assertFalse( ServiceRegistry::has_override( OccurrenceRepositoryInterface::class ) );
	}
}
