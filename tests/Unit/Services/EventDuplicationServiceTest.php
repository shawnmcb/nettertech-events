<?php
/**
 * EventDuplicationService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Services\EventDuplicationService;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Test EventDuplicationService functionality.
 */
class EventDuplicationServiceTest extends \NetterTechEventsTestCase {

	/**
	 * EventDuplicationService instance.
	 *
	 * @var EventDuplicationService
	 */
	private EventDuplicationService $service;

	/**
	 * Mock EventRepositoryInterface.
	 *
	 * @var EventRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Mock OccurrenceRepositoryInterface.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock TicketTypeRepositoryInterface.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->event_repo       = $this->createMock( EventRepositoryInterface::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepositoryInterface::class );

		$this->service = new EventDuplicationService(
			$this->event_repo,
			$this->occurrence_repo,
			$this->ticket_type_repo
		);
	}

	// =========================================================================
	// duplicate() Tests
	// =========================================================================

	/**
	 * Test duplicate returns null when source event not found.
	 *
	 * @return void
	 */
	public function test_duplicate_returns_null_when_source_not_found(): void {
		$this->event_repo->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$result = $this->service->duplicate( 999 );

		$this->assertNull( $result );
	}

	/**
	 * Test duplicate copies content fields and sets draft status.
	 *
	 * @return void
	 */
	public function test_duplicate_copies_content_fields(): void {
		$source = EventFactory::create( [
			'id'              => 1,
			'title'           => 'Original Event',
			'slug'            => 'original-event',
			'description'     => 'A full description',
			'event_type'      => 'single',
			'venue_name'      => 'Test Venue',
			'venue_address'   => '123 Main St',
			'status'          => 'published',
		] );
		$source->excerpt           = 'A short excerpt';
		$source->featured_image_id = 42;
		$source->category_ids      = array( 1, 2, 3 );
		$source->tag_ids           = array( 4, 5 );

		$this->event_repo->method( 'find' )
			->with( 1 )
			->willReturn( $source );

		$this->event_repo->method( 'generate_unique_slug' )
			->willReturn( 'original-event-copy' );

		$captured_event = null;
		$this->event_repo->method( 'save' )
			->willReturnCallback( function ( Event $event ) use ( &$captured_event ) {
				$captured_event     = $event;
				$captured_event->id = 50;
				return $captured_event;
			} );

		$this->occurrence_repo->method( 'for_event' )
			->willReturn( array() );

		$result = $this->service->duplicate( 1 );

		$this->assertInstanceOf( Event::class, $result );
		$this->assertEquals( 'Original Event (Copy)', $captured_event->title );
		$this->assertEquals( 'A full description', $captured_event->description );
		$this->assertEquals( 'A short excerpt', $captured_event->excerpt );
		$this->assertEquals( 42, $captured_event->featured_image_id );
		$this->assertEquals( 'single', $captured_event->event_type );
		$this->assertEquals( 'Test Venue', $captured_event->venue_name );
		$this->assertEquals( '123 Main St', $captured_event->venue_address );
		$this->assertEquals( array( 1, 2, 3 ), $captured_event->category_ids );
		$this->assertEquals( array( 4, 5 ), $captured_event->tag_ids );
		$this->assertSame( EventStatus::DRAFT, $captured_event->status );
		$this->assertNull( $captured_event->recurrence_rule );
		$this->assertNull( $captured_event->recurrence_end_date );
	}

	/**
	 * Test duplicate fires before and after action hooks.
	 *
	 * @return void
	 */
	public function test_duplicate_fires_action_hooks(): void {
		$source = EventFactory::create( [
			'id'    => 1,
			'title' => 'Hook Test Event',
			'slug'  => 'hook-test-event',
		] );

		$this->event_repo->method( 'find' )
			->willReturn( $source );

		$this->event_repo->method( 'generate_unique_slug' )
			->willReturn( 'hook-test-event-copy' );

		$this->event_repo->method( 'save' )
			->willReturnCallback( function ( Event $event ) {
				$event->id = 53;
				return $event;
			} );

		$this->occurrence_repo->method( 'for_event' )
			->willReturn( array() );

		// Capture do_action calls.
		$captured_actions = array();
		\Brain\Monkey\Functions\when( 'do_action' )->alias(
			function () use ( &$captured_actions ) {
				$captured_actions[] = func_get_args();
			}
		);

		$this->service->duplicate( 1 );

		$hook_names = array_column( $captured_actions, 0 );

		$this->assertContains( 'nettertech_events_before_duplicate_event', $hook_names );
		$this->assertContains( 'nettertech_events_after_duplicate_event', $hook_names );

		// Verify before fires before after.
		$before_idx = array_search( 'nettertech_events_before_duplicate_event', $hook_names, true );
		$after_idx  = array_search( 'nettertech_events_after_duplicate_event', $hook_names, true );
		$this->assertLessThan( $after_idx, $before_idx );
	}

	/**
	 * Test duplicate creates occurrence from source template.
	 *
	 * @return void
	 */
	public function test_duplicate_creates_occurrence_from_source(): void {
		$source = EventFactory::create( [
			'id'    => 1,
			'title' => 'Event With Occurrence',
			'slug'  => 'event-with-occurrence',
		] );

		$source_occurrence = OccurrenceFactory::create( [
			'id'             => 10,
			'event_id'       => 1,
			'start_datetime' => '2026-04-01 19:00:00',
			'end_datetime'   => '2026-04-01 21:00:00',
		] );
		$source_occurrence->all_day  = false;
		$source_occurrence->timezone = 'America/Chicago';
		$source_occurrence->capacity = 200;

		$this->event_repo->method( 'find' )
			->willReturn( $source );

		$this->event_repo->method( 'generate_unique_slug' )
			->willReturn( 'event-with-occurrence-copy' );

		$this->event_repo->method( 'save' )
			->willReturnCallback( function ( Event $event ) {
				$event->id = 50;
				return $event;
			} );

		$this->occurrence_repo->method( 'for_event' )
			->with( 1 )
			->willReturn( array( $source_occurrence ) );

		$captured_occurrence = null;
		$this->occurrence_repo->method( 'save' )
			->willReturnCallback( function ( Occurrence $occ ) use ( &$captured_occurrence ) {
				$captured_occurrence     = $occ;
				$captured_occurrence->id = 100;
				return $captured_occurrence;
			} );

		$this->ticket_type_repo->method( 'for_multiple_occurrences' )
			->willReturn( array() );

		$this->service->duplicate( 1 );

		$this->assertNotNull( $captured_occurrence );
		$this->assertEquals( 50, $captured_occurrence->event_id );
		$this->assertFalse( $captured_occurrence->all_day );
		$this->assertEquals( 'America/Chicago', $captured_occurrence->timezone );
		$this->assertEquals( 'scheduled', $captured_occurrence->status );
		$this->assertEquals( 200, $captured_occurrence->capacity );
		$this->assertEquals( 1, $captured_occurrence->sequence_number );
	}

	/**
	 * Test duplicate copies unique ticket types to new occurrence.
	 *
	 * @return void
	 */
	public function test_duplicate_copies_unique_ticket_types(): void {
		$source = EventFactory::create( [
			'id'    => 1,
			'title' => 'Event With Tickets',
			'slug'  => 'event-with-tickets',
		] );

		$source_occurrence = OccurrenceFactory::create( [
			'id'             => 10,
			'event_id'       => 1,
			'start_datetime' => '2026-04-01 19:00:00',
			'end_datetime'   => '2026-04-01 21:00:00',
		] );
		$source_occurrence->all_day  = false;
		$source_occurrence->timezone = 'America/Chicago';
		$source_occurrence->capacity = 200;

		$ga_ticket  = TicketTypeFactory::create( [
			'occurrence_id' => 10,
			'name'          => 'General Admission',
			'price'         => 25.00,
			'capacity'      => 100,
			'sold_count'    => 50,
			'min_per_order' => 1,
			'max_per_order' => 10,
		] );
		$vip_ticket = TicketTypeFactory::vip( [
			'occurrence_id' => 10,
			'sold_count'    => 5,
		] );

		$this->event_repo->method( 'find' )
			->willReturn( $source );

		$this->event_repo->method( 'generate_unique_slug' )
			->willReturn( 'event-with-tickets-copy' );

		$this->event_repo->method( 'save' )
			->willReturnCallback( function ( Event $event ) {
				$event->id = 50;
				return $event;
			} );

		$this->occurrence_repo->method( 'for_event' )
			->willReturn( array( $source_occurrence ) );

		$saved_occurrence     = new Occurrence();
		$saved_occurrence->id = 100;
		$this->occurrence_repo->method( 'save' )
			->willReturn( $saved_occurrence );

		$this->ticket_type_repo->method( 'for_multiple_occurrences' )
			->with( array( 10 ) )
			->willReturn( array( 10 => array( $ga_ticket, $vip_ticket ) ) );

		$saved_tickets = array();
		$this->ticket_type_repo->method( 'save' )
			->willReturnCallback( function ( TicketType $tt ) use ( &$saved_tickets ) {
				$saved_tickets[] = $tt;
				return $tt;
			} );

		$this->service->duplicate( 1 );

		$this->assertCount( 2, $saved_tickets );

		// First ticket: General Admission.
		$this->assertEquals( 100, $saved_tickets[0]->occurrence_id );
		$this->assertEquals( 'General Admission', $saved_tickets[0]->name );
		$this->assertEquals( 25.00, $saved_tickets[0]->price );
		$this->assertEquals( 100, $saved_tickets[0]->capacity );
		$this->assertEquals( 0, $saved_tickets[0]->sold_count );
		$this->assertEquals( 'in_stock', $saved_tickets[0]->stock_status );
		$this->assertNull( $saved_tickets[0]->sale_start );
		$this->assertNull( $saved_tickets[0]->sale_end );
		$this->assertEquals( 0, $saved_tickets[0]->sort_order );
		$this->assertEquals( 'active', $saved_tickets[0]->status );
		$this->assertNull( $saved_tickets[0]->wc_product_id );
		$this->assertNull( $saved_tickets[0]->wc_variation_id );

		// Second ticket: VIP.
		$this->assertEquals( 100, $saved_tickets[1]->occurrence_id );
		$this->assertEquals( 'VIP', $saved_tickets[1]->name );
		$this->assertEquals( 100.00, $saved_tickets[1]->price );
		$this->assertEquals( 0, $saved_tickets[1]->sold_count );
		$this->assertEquals( 1, $saved_tickets[1]->sort_order );
	}

	/**
	 * Test duplicate deduplicates ticket types across occurrences.
	 *
	 * @return void
	 */
	public function test_duplicate_deduplicates_ticket_types_across_occurrences(): void {
		$source = EventFactory::create( [
			'id'    => 1,
			'title' => 'Multi-Occurrence Event',
			'slug'  => 'multi-occurrence-event',
		] );

		$occ1 = OccurrenceFactory::create( [
			'id'             => 10,
			'event_id'       => 1,
			'start_datetime' => '2026-04-01 19:00:00',
			'end_datetime'   => '2026-04-01 21:00:00',
		] );
		$occ1->all_day  = false;
		$occ1->timezone = 'America/Chicago';
		$occ1->capacity = 100;

		$occ2 = OccurrenceFactory::create( [
			'id'             => 11,
			'event_id'       => 1,
			'start_datetime' => '2026-04-08 19:00:00',
			'end_datetime'   => '2026-04-08 21:00:00',
		] );
		$occ2->all_day  = false;
		$occ2->timezone = 'America/Chicago';
		$occ2->capacity = 100;

		// Same ticket type name+price on both occurrences.
		$ga_ticket_occ1 = TicketTypeFactory::create( [
			'occurrence_id' => 10,
			'name'          => 'General Admission',
			'price'         => 25.00,
		] );
		$ga_ticket_occ2 = TicketTypeFactory::create( [
			'occurrence_id' => 11,
			'name'          => 'General Admission',
			'price'         => 25.00,
		] );

		$this->event_repo->method( 'find' )->willReturn( $source );
		$this->event_repo->method( 'generate_unique_slug' )->willReturn( 'multi-occurrence-event-copy' );
		$this->event_repo->method( 'save' )->willReturnCallback( function ( Event $event ) {
			$event->id = 50;
			return $event;
		} );

		$this->occurrence_repo->method( 'for_event' )
			->willReturn( array( $occ1, $occ2 ) );

		$saved_occurrence     = new Occurrence();
		$saved_occurrence->id = 100;
		$this->occurrence_repo->method( 'save' )
			->willReturn( $saved_occurrence );

		$this->ticket_type_repo->method( 'for_multiple_occurrences' )
			->with( array( 10, 11 ) )
			->willReturn( array(
				10 => array( $ga_ticket_occ1 ),
				11 => array( $ga_ticket_occ2 ),
			) );

		$saved_tickets = array();
		$this->ticket_type_repo->method( 'save' )
			->willReturnCallback( function ( TicketType $tt ) use ( &$saved_tickets ) {
				$saved_tickets[] = $tt;
				return $tt;
			} );

		$this->service->duplicate( 1 );

		// Only one ticket type should be created (deduplicated by name+price).
		$this->assertCount( 1, $saved_tickets );
		$this->assertEquals( 'General Admission', $saved_tickets[0]->name );
	}

	/**
	 * Test duplicate skips occurrence creation when source has no occurrences.
	 *
	 * @return void
	 */
	public function test_duplicate_skips_occurrence_when_source_has_none(): void {
		$source = EventFactory::create( [
			'id'    => 1,
			'title' => 'No Occurrence Event',
			'slug'  => 'no-occurrence-event',
		] );

		$this->event_repo->method( 'find' )->willReturn( $source );
		$this->event_repo->method( 'generate_unique_slug' )->willReturn( 'no-occurrence-event-copy' );
		$this->event_repo->method( 'save' )->willReturnCallback( function ( Event $event ) {
			$event->id = 50;
			return $event;
		} );

		$this->occurrence_repo->method( 'for_event' )
			->willReturn( array() );

		// Occurrence save should never be called.
		$this->occurrence_repo->expects( $this->never() )
			->method( 'save' );

		// Ticket type save should never be called.
		$this->ticket_type_repo->expects( $this->never() )
			->method( 'save' );

		$result = $this->service->duplicate( 1 );

		$this->assertInstanceOf( Event::class, $result );
		$this->assertEquals( 50, $result->id );
	}
}
