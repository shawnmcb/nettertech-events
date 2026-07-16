<?php
/**
 * ServiceRegistry unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;

/**
 * Test ServiceRegistry functionality.
 *
 * @group wiring
 */
class ServiceRegistryTest extends \NetterTechEventsTestCase {

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
	// Repository Tests
	// =========================================================================

	/**
	 * Test occurrence_repository returns correct interface.
	 *
	 * @return void
	 */
	public function test_occurrence_repository_returns_interface(): void {
		$repo = ServiceRegistry::occurrence_repository();

		$this->assertInstanceOf( OccurrenceRepositoryInterface::class, $repo );
	}

	/**
	 * Test occurrence_repository returns singleton.
	 *
	 * @return void
	 */
	public function test_occurrence_repository_returns_singleton(): void {
		$repo1 = ServiceRegistry::occurrence_repository();
		$repo2 = ServiceRegistry::occurrence_repository();

		$this->assertSame( $repo1, $repo2 );
	}

	/**
	 * Test ticket_type_repository returns correct interface.
	 *
	 * @return void
	 */
	public function test_ticket_type_repository_returns_interface(): void {
		$repo = ServiceRegistry::ticket_type_repository();

		$this->assertInstanceOf( TicketTypeRepositoryInterface::class, $repo );
	}

	/**
	 * Test ticket_type_repository returns singleton.
	 *
	 * @return void
	 */
	public function test_ticket_type_repository_returns_singleton(): void {
		$repo1 = ServiceRegistry::ticket_type_repository();
		$repo2 = ServiceRegistry::ticket_type_repository();

		$this->assertSame( $repo1, $repo2 );
	}

	/**
	 * Test event_repository returns correct interface.
	 *
	 * @return void
	 */
	public function test_event_repository_returns_interface(): void {
		$repo = ServiceRegistry::event_repository();

		$this->assertInstanceOf( EventRepositoryInterface::class, $repo );
	}

	/**
	 * Test event_repository returns singleton.
	 *
	 * @return void
	 */
	public function test_event_repository_returns_singleton(): void {
		$repo1 = ServiceRegistry::event_repository();
		$repo2 = ServiceRegistry::event_repository();

		$this->assertSame( $repo1, $repo2 );
	}

	/**
	 * Test attendee_repository returns correct interface.
	 *
	 * @return void
	 */
	public function test_attendee_repository_returns_interface(): void {
		$repo = ServiceRegistry::attendee_repository();

		$this->assertInstanceOf( AttendeeRepositoryInterface::class, $repo );
	}

	/**
	 * Test attendee_repository returns singleton.
	 *
	 * @return void
	 */
	public function test_attendee_repository_returns_singleton(): void {
		$repo1 = ServiceRegistry::attendee_repository();
		$repo2 = ServiceRegistry::attendee_repository();

		$this->assertSame( $repo1, $repo2 );
	}

	/**
	 * Test ticket_repository returns correct interface.
	 *
	 * @return void
	 */
	public function test_ticket_repository_returns_interface(): void {
		$repo = ServiceRegistry::ticket_repository();

		$this->assertInstanceOf( TicketRepositoryInterface::class, $repo );
	}

	/**
	 * Test ticket_repository returns singleton.
	 *
	 * @return void
	 */
	public function test_ticket_repository_returns_singleton(): void {
		$repo1 = ServiceRegistry::ticket_repository();
		$repo2 = ServiceRegistry::ticket_repository();

		$this->assertSame( $repo1, $repo2 );
	}

	/**
	 * Test organizer_repository returns correct interface.
	 *
	 * @return void
	 */
	public function test_organizer_repository_returns_interface(): void {
		$repo = ServiceRegistry::organizer_repository();

		$this->assertInstanceOf( OrganizerRepositoryInterface::class, $repo );
	}

	/**
	 * Test organizer_repository returns singleton.
	 *
	 * @return void
	 */
	public function test_organizer_repository_returns_singleton(): void {
		$repo1 = ServiceRegistry::organizer_repository();
		$repo2 = ServiceRegistry::organizer_repository();

		$this->assertSame( $repo1, $repo2 );
	}

	/**
	 * Test category_repository returns correct interface.
	 *
	 * @return void
	 */
	public function test_category_repository_returns_interface(): void {
		$repo = ServiceRegistry::category_repository();

		$this->assertInstanceOf( CategoryRepositoryInterface::class, $repo );
	}

	/**
	 * Test category_repository returns singleton.
	 *
	 * @return void
	 */
	public function test_category_repository_returns_singleton(): void {
		$repo1 = ServiceRegistry::category_repository();
		$repo2 = ServiceRegistry::category_repository();

		$this->assertSame( $repo1, $repo2 );
	}

	/**
	 * Test tag_repository returns correct interface.
	 *
	 * @return void
	 */
	public function test_tag_repository_returns_interface(): void {
		$repo = ServiceRegistry::tag_repository();

		$this->assertInstanceOf( TagRepositoryInterface::class, $repo );
	}

	/**
	 * Test tag_repository returns singleton.
	 *
	 * @return void
	 */
	public function test_tag_repository_returns_singleton(): void {
		$repo1 = ServiceRegistry::tag_repository();
		$repo2 = ServiceRegistry::tag_repository();

		$this->assertSame( $repo1, $repo2 );
	}

	// =========================================================================
	// Service Tests
	// =========================================================================

	/**
	 * Test capacity_service returns correct interface.
	 *
	 * @return void
	 */
	public function test_capacity_service_returns_interface(): void {
		$service = ServiceRegistry::capacity_service();

		$this->assertInstanceOf( CapacityServiceInterface::class, $service );
	}

	/**
	 * Test capacity_service returns singleton.
	 *
	 * @return void
	 */
	public function test_capacity_service_returns_singleton(): void {
		$service1 = ServiceRegistry::capacity_service();
		$service2 = ServiceRegistry::capacity_service();

		$this->assertSame( $service1, $service2 );
	}

	/**
	 * Test activity_log_service returns correct interface.
	 *
	 * @return void
	 */
	public function test_activity_log_service_returns_interface(): void {
		$service = ServiceRegistry::activity_log_service();

		$this->assertInstanceOf( ActivityLogServiceInterface::class, $service );
	}

	/**
	 * Test activity_log_service returns singleton.
	 *
	 * @return void
	 */
	public function test_activity_log_service_returns_singleton(): void {
		$service1 = ServiceRegistry::activity_log_service();
		$service2 = ServiceRegistry::activity_log_service();

		$this->assertSame( $service1, $service2 );
	}

	// =========================================================================
	// Override Tests
	// =========================================================================

	/**
	 * Test set overrides repository.
	 *
	 * @return void
	 */
	public function test_set_overrides_repository(): void {
		$mock = $this->createMock( OccurrenceRepositoryInterface::class );

		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $mock );

		$this->assertSame( $mock, ServiceRegistry::occurrence_repository() );
	}

	/**
	 * Test set clears cached instance.
	 *
	 * @return void
	 */
	public function test_set_clears_cached_instance(): void {
		// Get the real instance first.
		$original = ServiceRegistry::occurrence_repository();

		// Override with mock.
		$mock = $this->createMock( OccurrenceRepositoryInterface::class );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $mock );

		// Should return mock, not original.
		$this->assertSame( $mock, ServiceRegistry::occurrence_repository() );
		$this->assertNotSame( $original, ServiceRegistry::occurrence_repository() );
	}

	/**
	 * Test has_override returns false when no override.
	 *
	 * @return void
	 */
	public function test_has_override_returns_false_when_no_override(): void {
		$this->assertFalse( ServiceRegistry::has_override( OccurrenceRepositoryInterface::class ) );
	}

	/**
	 * Test has_override returns true when override exists.
	 *
	 * @return void
	 */
	public function test_has_override_returns_true_when_override_exists(): void {
		$mock = $this->createMock( OccurrenceRepositoryInterface::class );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $mock );

		$this->assertTrue( ServiceRegistry::has_override( OccurrenceRepositoryInterface::class ) );
	}

	/**
	 * Test reset clears overrides.
	 *
	 * @return void
	 */
	public function test_reset_clears_overrides(): void {
		$mock = $this->createMock( OccurrenceRepositoryInterface::class );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $mock );

		ServiceRegistry::reset();

		$this->assertFalse( ServiceRegistry::has_override( OccurrenceRepositoryInterface::class ) );
	}

	/**
	 * Test reset clears cached instances.
	 *
	 * @return void
	 */
	public function test_reset_clears_cached_instances(): void {
		// Get and cache an instance.
		$original = ServiceRegistry::occurrence_repository();

		// Reset all.
		ServiceRegistry::reset();

		// New call should create a new instance.
		$new = ServiceRegistry::occurrence_repository();

		$this->assertNotSame( $original, $new );
	}

	/**
	 * Test multiple overrides work independently.
	 *
	 * @return void
	 */
	public function test_multiple_overrides_work_independently(): void {
		$occurrenceMock = $this->createMock( OccurrenceRepositoryInterface::class );
		$eventMock      = $this->createMock( EventRepositoryInterface::class );

		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $occurrenceMock );
		ServiceRegistry::set( EventRepositoryInterface::class, $eventMock );

		$this->assertSame( $occurrenceMock, ServiceRegistry::occurrence_repository() );
		$this->assertSame( $eventMock, ServiceRegistry::event_repository() );
		$this->assertTrue( ServiceRegistry::has_override( OccurrenceRepositoryInterface::class ) );
		$this->assertTrue( ServiceRegistry::has_override( EventRepositoryInterface::class ) );
	}

	/**
	 * Test override does not affect non-overridden services.
	 *
	 * @return void
	 */
	public function test_override_does_not_affect_non_overridden_services(): void {
		$mock = $this->createMock( OccurrenceRepositoryInterface::class );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $mock );

		// Event repository should still return real instance.
		$event = ServiceRegistry::event_repository();

		$this->assertInstanceOf( EventRepositoryInterface::class, $event );
		$this->assertFalse( ServiceRegistry::has_override( EventRepositoryInterface::class ) );
	}
}
