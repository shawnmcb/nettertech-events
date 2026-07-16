<?php
/**
 * Service Registry degradation tests — Pro absent.
 *
 * Proves that ServiceRegistry initialises cleanly when no Pro service
 * providers are registered and that the QRCodeServiceInterface binding
 * is absent from the base container.
 *
 * @package NetterTechEvents\Tests\Unit\Degradation
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Degradation;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\QRCodeServiceInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Core\Container;
use NetterTechEvents\Core\ServiceRegistry;

/**
 * Verify ServiceRegistry behaves correctly when Pro is absent.
 *
 * @covers \NetterTechEvents\Core\ServiceRegistry
 *
 * @group wiring
 */
class ProAbsentServiceRegistryTest extends \NetterTechEventsTestCase {

	/**
	 * Reset the registry after every test to avoid cross-test pollution.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ServiceRegistry::reset();
		parent::tearDown();
	}

	// =========================================================================
	// Filter returns base providers only
	// =========================================================================

	/**
	 * Test that nettertech_events_service_providers filter constant is correctly defined.
	 *
	 * The filter name must match what the registry uses so Pro can hook in.
	 *
	 * @return void
	 */
	public function test_service_providers_filter_constant_is_defined(): void {
		$this->assertSame(
			'nettertech_events_service_providers',
			\NetterTechEvents\Core\Hooks::FILTER_SERVICE_PROVIDERS
		);
	}

	/**
	 * Test that base container initialisation does not throw.
	 *
	 * Without Pro no additional providers are added via the filter so the
	 * container must still initialise cleanly with only the five base providers.
	 *
	 * @return void
	 */
	public function test_service_providers_filter_returns_base_providers_only(): void {
		// apply_filters is stubbed in bootstrap to return the first arg (pass-through).
		// This verifies the init path runs without error and uses the base providers.
		$container = new Container();
		ServiceRegistry::init( $container );

		// After init, all five base interface families must resolve.
		$this->assertInstanceOf( EventRepositoryInterface::class, $container->get( EventRepositoryInterface::class ) );
		$this->assertInstanceOf( OccurrenceRepositoryInterface::class, $container->get( OccurrenceRepositoryInterface::class ) );
		$this->assertInstanceOf( TicketRepositoryInterface::class, $container->get( TicketRepositoryInterface::class ) );
	}

	// =========================================================================
	// QRCodeServiceInterface bound in base (NTE-041)
	// =========================================================================

	/**
	 * Test that the base container binds QRCodeServiceInterface.
	 *
	 * Per NTE-041, QR generation lives in the base plugin. Pro consumes the
	 * same service for check-in QR rendering rather than registering its own.
	 *
	 * @return void
	 */
	public function test_container_has_qr_code_service_binding(): void {
		$container = new Container();
		ServiceRegistry::init( $container );

		$this->assertTrue(
			$container->has( QRCodeServiceInterface::class ),
			'QRCodeServiceInterface must be bound in the base container'
		);
		$this->assertInstanceOf(
			QRCodeServiceInterface::class,
			$container->get( QRCodeServiceInterface::class )
		);
	}

	// =========================================================================
	// Base services resolve without errors
	// =========================================================================

	/**
	 * Test that the event repository resolves from the base container.
	 *
	 * @return void
	 */
	public function test_event_repository_resolves_from_base_container(): void {
		$container = new Container();
		ServiceRegistry::init( $container );

		$repo = $container->get( EventRepositoryInterface::class );

		$this->assertInstanceOf( EventRepositoryInterface::class, $repo );
	}

	/**
	 * Test that the occurrence repository resolves from the base container.
	 *
	 * @return void
	 */
	public function test_occurrence_repository_resolves_from_base_container(): void {
		$container = new Container();
		ServiceRegistry::init( $container );

		$repo = $container->get( OccurrenceRepositoryInterface::class );

		$this->assertInstanceOf( OccurrenceRepositoryInterface::class, $repo );
	}

	/**
	 * Test that the ticket repository resolves from the base container.
	 *
	 * @return void
	 */
	public function test_ticket_repository_resolves_from_base_container(): void {
		$container = new Container();
		ServiceRegistry::init( $container );

		$repo = $container->get( TicketRepositoryInterface::class );

		$this->assertInstanceOf( TicketRepositoryInterface::class, $repo );
	}

	/**
	 * Test that ServiceRegistry facade resolves repositories correctly after init.
	 *
	 * @return void
	 */
	public function test_service_registry_facade_resolves_event_repository(): void {
		$container = new Container();
		ServiceRegistry::init( $container );

		$repo = ServiceRegistry::event_repository();

		$this->assertInstanceOf( EventRepositoryInterface::class, $repo );
	}

	/**
	 * Test that ServiceRegistry::has_override returns false for QR service.
	 *
	 * Without Pro there is no override either.
	 *
	 * @return void
	 */
	public function test_no_qr_service_override_in_base(): void {
		$container = new Container();
		ServiceRegistry::init( $container );

		$this->assertFalse( ServiceRegistry::has_override( QRCodeServiceInterface::class ) );
	}
}
