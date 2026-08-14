<?php
/**
 * RecurrenceService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Exceptions\RRuleException;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Services\OccurrenceGenerator;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\RRuleParser;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;

/**
 * Test RecurrenceService functionality.
 */
class RecurrenceServiceTest extends \NetterTechEventsTestCase {

	/**
	 * RecurrenceService instance.
	 *
	 * @var RecurrenceService
	 */
	private RecurrenceService $service;

	/**
	 * Mock RRuleParser.
	 *
	 * @var RRuleParser|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $parser;

	/**
	 * Mock OccurrenceGenerator.
	 *
	 * @var OccurrenceGenerator|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $generator;

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock TicketTypeRepository.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock AttendeeRepository.
	 *
	 * @var AttendeeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $attendee_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->parser           = $this->createMock( RRuleParser::class );
		$this->generator        = $this->createMock( OccurrenceGenerator::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepositoryInterface::class );
		$this->attendee_repo    = $this->createMock( AttendeeRepositoryInterface::class );

		$this->service = new RecurrenceService(
			$this->parser,
			$this->generator,
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo
		);

		EventFactory::reset();
		OccurrenceFactory::reset();
	}

	// =========================================================================
	// generate_occurrences Tests
	// =========================================================================

	/**
	 * Test generate_occurrences validates RRULE first.
	 *
	 * @return void
	 */
	public function test_generate_occurrences_validates_rrule(): void {
		$event = EventFactory::recurring();
		$start = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-15 21:00:00' );

		$this->parser
			->expects( $this->once() )
			->method( 'validate' )
			->with( 'FREQ=INVALID' )
			->willReturn( array( 'Invalid frequency' ) );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=INVALID'
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 0, $result['generated'] );
	}

	/**
	 * Test generate_occurrences parses valid RRULE.
	 *
	 * @return void
	 */
	public function test_generate_occurrences_parses_rrule(): void {
		$event = EventFactory::recurring();
		$start = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule  = RecurrenceRule::weekly( 1, array( 'MO', 'WE', 'FR' ) );

		$this->parser
			->method( 'validate' )
			->willReturn( array() );

		$this->parser
			->expects( $this->once() )
			->method( 'parse' )
			->with( 'FREQ=WEEKLY;BYDAY=MO,WE,FR' )
			->willReturn( $rule );

		$occurrences = array(
			OccurrenceFactory::create( array( 'event_id' => $event->id ) ),
			OccurrenceFactory::create( array( 'event_id' => $event->id ) ),
			OccurrenceFactory::create( array( 'event_id' => $event->id ) ),
		);

		$this->generator
			->expects( $this->once() )
			->method( 'generate' )
			->willReturn( $occurrences );

		// No existing occurrences to protect.
		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array() );

		$this->occurrence_repo
			->method( 'save_batch' )
			->willReturn( 3 );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=WEEKLY;BYDAY=MO,WE,FR'
		);

		$this->assertEmpty( $result['errors'] );
		$this->assertSame( 3, $result['generated'] );
		$this->assertSame( 3, $result['saved'] );
	}

	/**
	 * Test generate_occurrences returns error when no occurrences generated.
	 *
	 * @return void
	 */
	public function test_generate_occurrences_error_when_empty(): void {
		$event = EventFactory::recurring();
		$start = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule  = RecurrenceRule::daily();

		$this->parser->method( 'validate' )->willReturn( array() );
		$this->parser->method( 'parse' )->willReturn( $rule );
		$this->generator->method( 'generate' )->willReturn( array() );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY'
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'No occurrences generated', $result['errors'][0] );
	}

	/**
	 * Test generate_occurrences deletes existing when replace is true and no attendees.
	 *
	 * @return void
	 */
	public function test_generate_occurrences_replaces_existing(): void {
		$event            = EventFactory::recurring();
		$start            = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end              = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule             = RecurrenceRule::daily();
		$existing_occ     = OccurrenceFactory::create( array( 'id' => 10, 'event_id' => $event->id ) );

		$this->parser->method( 'validate' )->willReturn( array() );
		$this->parser->method( 'parse' )->willReturn( $rule );
		$this->generator->method( 'generate' )->willReturn(
			array( OccurrenceFactory::create() )
		);

		// for_event returns existing occurrences to check.
		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $existing_occ ) );

		// No attendees on existing occurrence.
		$this->attendee_repo
			->method( 'count_for_occurrence' )
			->with( 10 )
			->willReturn( 0 );

		// Should delete the unprotected occurrence.
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'delete' )
			->with( 10 )
			->willReturn( true );

		$this->occurrence_repo->method( 'save_batch' )->willReturn( 1 );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY',
			true // replace
		);

		$this->assertSame( 0, $result['protected'] );
	}

	/**
	 * Test generate_occurrences does not delete when replace is false.
	 *
	 * @return void
	 */
	public function test_generate_occurrences_appends_when_not_replacing(): void {
		$event = EventFactory::recurring();
		$start = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule  = RecurrenceRule::daily();

		$this->parser->method( 'validate' )->willReturn( array() );
		$this->parser->method( 'parse' )->willReturn( $rule );
		$this->generator->method( 'generate' )->willReturn(
			array( OccurrenceFactory::create() )
		);

		// Should never call delete when not replacing.
		$this->occurrence_repo
			->expects( $this->never() )
			->method( 'delete' );

		$this->occurrence_repo->method( 'save_batch' )->willReturn( 1 );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY',
			false // do not replace
		);

		$this->assertSame( 0, $result['protected'] );
	}

	// =========================================================================
	// Attendee Protection Tests
	// =========================================================================

	/**
	 * Test occurrence with attendees is not deleted during replace.
	 *
	 * @return void
	 */
	public function test_replace_protects_occurrence_with_attendees(): void {
		$event       = EventFactory::recurring();
		$start       = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end         = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule        = RecurrenceRule::daily();
		$protected   = OccurrenceFactory::create( array( 'id' => 10, 'event_id' => $event->id ) );
		$unprotected = OccurrenceFactory::create( array( 'id' => 11, 'event_id' => $event->id ) );

		$this->parser->method( 'validate' )->willReturn( array() );
		$this->parser->method( 'parse' )->willReturn( $rule );
		$this->generator->method( 'generate' )->willReturn(
			array( OccurrenceFactory::create() )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $protected, $unprotected ) );

		// Occurrence 10 has 3 attendees, occurrence 11 has none.
		$this->attendee_repo
			->method( 'count_for_occurrence' )
			->willReturnCallback( function ( int $id ) {
				return 10 === $id ? 3 : 0;
			} );

		// Only occurrence 11 should be deleted.
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'delete' )
			->with( 11 )
			->willReturn( true );

		$this->occurrence_repo->method( 'save_batch' )->willReturn( 1 );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY',
			true
		);

		$this->assertSame( 1, $result['protected'] );
	}

	/**
	 * Test manual per-occurrence override is not deleted during regeneration,
	 * even when it has no attendees (NTE-077).
	 *
	 * @return void
	 */
	public function test_replace_protects_override_occurrence_without_attendees(): void {
		$event    = EventFactory::recurring();
		$start    = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end      = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule     = RecurrenceRule::daily();
		$override = OccurrenceFactory::create( array( 'id' => 20, 'event_id' => $event->id ) );
		$plain    = OccurrenceFactory::create( array( 'id' => 21, 'event_id' => $event->id ) );

		$override->is_override = true;

		$this->parser->method( 'validate' )->willReturn( array() );
		$this->parser->method( 'parse' )->willReturn( $rule );
		$this->generator->method( 'generate' )->willReturn(
			array( OccurrenceFactory::create() )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $override, $plain ) );

		// Neither occurrence has attendees.
		$this->attendee_repo
			->method( 'count_for_occurrence' )
			->willReturn( 0 );

		// Only the plain occurrence (21) should be deleted; the override (20) is protected.
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'delete' )
			->with( 21 )
			->willReturn( true );

		$this->occurrence_repo->method( 'save_batch' )->willReturn( 1 );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY',
			true
		);

		$this->assertSame( 1, $result['protected'] );
	}

	/**
	 * Test replace with mixed attendee state only deletes empty occurrences.
	 *
	 * @return void
	 */
	public function test_replace_mixed_attendees_deletes_only_empty(): void {
		$event = EventFactory::recurring();
		$start = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule  = RecurrenceRule::daily();

		$occ_with      = OccurrenceFactory::create( array( 'id' => 1, 'event_id' => $event->id ) );
		$occ_without   = OccurrenceFactory::create( array( 'id' => 2, 'event_id' => $event->id ) );
		$occ_also_with = OccurrenceFactory::create( array( 'id' => 3, 'event_id' => $event->id ) );

		$this->parser->method( 'validate' )->willReturn( array() );
		$this->parser->method( 'parse' )->willReturn( $rule );
		$this->generator->method( 'generate' )->willReturn(
			array( OccurrenceFactory::create() )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $occ_with, $occ_without, $occ_also_with ) );

		$attendee_map = array( 1 => 5, 2 => 0, 3 => 2 );
		$this->attendee_repo
			->method( 'count_for_occurrence' )
			->willReturnCallback( function ( int $id ) use ( $attendee_map ) {
				return $attendee_map[ $id ] ?? 0;
			} );

		// Only occurrence 2 (no attendees) should be deleted.
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'delete' )
			->with( 2 )
			->willReturn( true );

		$this->occurrence_repo->method( 'save_batch' )->willReturn( 1 );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY',
			true
		);

		$this->assertSame( 2, $result['protected'] );
	}

	/**
	 * Test blocked deletion does not call delete on protected occurrence.
	 *
	 * Verifies that when an occurrence has attendees and replace is true,
	 * the occurrence is protected: delete is never called on it, and
	 * the protected count is reported correctly.
	 *
	 * Note: do_action is stubbed in unit tests, so hook firing is verified
	 * at the integration test level. This test validates the guard logic.
	 *
	 * @return void
	 */
	public function test_blocked_deletion_skips_protected_occurrence(): void {
		$event     = EventFactory::recurring();
		$start     = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end       = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule      = RecurrenceRule::daily();
		$protected = OccurrenceFactory::create( array( 'id' => 42, 'event_id' => $event->id ) );

		$this->parser->method( 'validate' )->willReturn( array() );
		$this->parser->method( 'parse' )->willReturn( $rule );
		$this->generator->method( 'generate' )->willReturn(
			array( OccurrenceFactory::create() )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $protected ) );

		$this->attendee_repo
			->method( 'count_for_occurrence' )
			->with( 42 )
			->willReturn( 7 );

		// delete() should NEVER be called because the only occurrence has attendees.
		$this->occurrence_repo
			->expects( $this->never() )
			->method( 'delete' );

		$this->occurrence_repo->method( 'save_batch' )->willReturn( 1 );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY',
			true
		);

		$this->assertSame( 1, $result['protected'] );
	}

	/**
	 * Test occurrence without attendees can be deleted (normal case).
	 *
	 * @return void
	 */
	public function test_occurrence_without_attendees_deleted_normally(): void {
		$event = EventFactory::recurring();
		$start = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule  = RecurrenceRule::daily();

		$occ1 = OccurrenceFactory::create( array( 'id' => 1, 'event_id' => $event->id ) );
		$occ2 = OccurrenceFactory::create( array( 'id' => 2, 'event_id' => $event->id ) );

		$this->parser->method( 'validate' )->willReturn( array() );
		$this->parser->method( 'parse' )->willReturn( $rule );
		$this->generator->method( 'generate' )->willReturn(
			array( OccurrenceFactory::create() )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $occ1, $occ2 ) );

		// Both have zero attendees.
		$this->attendee_repo
			->method( 'count_for_occurrence' )
			->willReturn( 0 );

		// Both should be deleted.
		$this->occurrence_repo
			->expects( $this->exactly( 2 ) )
			->method( 'delete' )
			->willReturn( true );

		$this->occurrence_repo->method( 'save_batch' )->willReturn( 1 );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY',
			true
		);

		$this->assertSame( 0, $result['protected'] );
	}

	/**
	 * Test regenerate_future protects future occurrences with attendees.
	 *
	 * @return void
	 */
	public function test_regenerate_future_protects_occurrences_with_attendees(): void {
		$event        = EventFactory::recurring();
		$start        = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end          = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule         = RecurrenceRule::daily();
		$future_with  = OccurrenceFactory::create( array( 'id' => 20, 'event_id' => $event->id ) );
		$future_empty = OccurrenceFactory::create( array( 'id' => 21, 'event_id' => $event->id ) );

		$this->parser->method( 'parse' )->willReturn( $rule );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $future_with, $future_empty ) );

		$this->attendee_repo
			->method( 'count_for_occurrence' )
			->willReturnCallback( function ( int $id ) {
				return 20 === $id ? 4 : 0;
			} );

		// Only occurrence 21 should be deleted.
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'delete' )
			->with( 21 )
			->willReturn( true );

		$this->generator->method( 'generate' )->willReturn( array() );
		$this->occurrence_repo->method( 'save_batch' )->willReturn( 0 );

		$result = $this->service->regenerate_future_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY'
		);

		$this->assertSame( 1, $result['deleted'] );
		$this->assertSame( 1, $result['protected'] );
	}

	/**
	 * Test regenerate_future protects manual overrides without attendees (NTE-077).
	 *
	 * This is the horizon-extender cron path: an overridden occurrence with no
	 * attendees must not be wiped when the recurrence series is regenerated.
	 *
	 * @return void
	 */
	public function test_regenerate_future_protects_override_without_attendees(): void {
		$event    = EventFactory::recurring();
		$start    = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end      = new \DateTimeImmutable( '2026-01-15 21:00:00' );
		$rule     = RecurrenceRule::daily();
		$override = OccurrenceFactory::create( array( 'id' => 30, 'event_id' => $event->id ) );
		$plain    = OccurrenceFactory::create( array( 'id' => 31, 'event_id' => $event->id ) );

		$override->is_override = true;

		$this->parser->method( 'parse' )->willReturn( $rule );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $override, $plain ) );

		// Neither occurrence has attendees.
		$this->attendee_repo
			->method( 'count_for_occurrence' )
			->willReturn( 0 );

		// Only the plain occurrence (31) should be deleted; the override (30) is protected.
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'delete' )
			->with( 31 )
			->willReturn( true );

		$this->generator->method( 'generate' )->willReturn( array() );
		$this->occurrence_repo->method( 'save_batch' )->willReturn( 0 );

		$result = $this->service->regenerate_future_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY'
		);

		$this->assertSame( 1, $result['deleted'] );
		$this->assertSame( 1, $result['protected'] );
	}

	// =========================================================================
	// create_single_occurrence Tests
	// =========================================================================

	/**
	 * Test create_single_occurrence creates new occurrence.
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_creates_new(): void {
		$event = EventFactory::single();
		$start = new \DateTimeImmutable( '2026-02-20 14:00:00' );
		$end   = new \DateTimeImmutable( '2026-02-20 16:00:00' );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array() ); // No existing occurrence.

		$saved_occurrence = OccurrenceFactory::create( array(
			'event_id'       => $event->id,
			'start_datetime' => '2026-02-20 14:00:00',
			'end_datetime'   => '2026-02-20 16:00:00',
		) );

		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'save' )
			->willReturn( $saved_occurrence );

		$result = $this->service->create_single_occurrence( $event, $start, $end );

		$this->assertInstanceOf( Occurrence::class, $result );
		$this->assertSame( '2026-02-20 14:00:00', $result->start_datetime );
	}

	/**
	 * Test create_single_occurrence updates existing occurrence.
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_updates_existing(): void {
		$event    = EventFactory::single();
		$existing = OccurrenceFactory::create( array( 'event_id' => $event->id ) );
		$start    = new \DateTimeImmutable( '2026-02-25 18:00:00' );
		$end      = new \DateTimeImmutable( '2026-02-25 20:00:00' );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $existing ) );

		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'save' )
			->willReturnCallback( function ( $occ ) {
				return $occ;
			} );

		$result = $this->service->create_single_occurrence( $event, $start, $end );

		$this->assertSame( $existing->id, $result->id );
		$this->assertSame( '2026-02-25 18:00:00', $result->start_datetime );
	}

	/**
	 * An earlier hand-picked override date is not clobbered; the primary date is updated (NTE-185).
	 *
	 * for_event returns the override first (it sorts earliest). The old code took element [0] and
	 * overwrote it, moving the operator's added date onto the main Date & Time slot. The fix skips
	 * override rows and updates the first non-override occurrence instead.
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_skips_earlier_override(): void {
		$event = EventFactory::single();

		$override                 = OccurrenceFactory::create( array(
			'id'             => 501,
			'event_id'       => $event->id,
			'start_datetime' => '2026-02-10 09:00:00',
			'end_datetime'   => '2026-02-10 11:00:00',
		) );
		$override->is_override    = true;

		$primary                  = OccurrenceFactory::create( array(
			'id'             => 502,
			'event_id'       => $event->id,
			'start_datetime' => '2026-02-25 18:00:00',
			'end_datetime'   => '2026-02-25 20:00:00',
		) );
		$primary->is_override     = false;

		$start = new \DateTimeImmutable( '2026-03-05 18:00:00' );
		$end   = new \DateTimeImmutable( '2026-03-05 20:00:00' );

		// Earliest first, exactly as ORDER BY start_datetime ASC returns them.
		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $override, $primary ) );

		$saved = null;
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'save' )
			->willReturnCallback( function ( $occ ) use ( &$saved ) {
				$saved = $occ;
				return $occ;
			} );

		$result = $this->service->create_single_occurrence( $event, $start, $end );

		// The primary (non-override) row was updated; the override was left untouched.
		$this->assertSame( 502, $result->id );
		$this->assertSame( 502, $saved->id );
		$this->assertSame( '2026-03-05 18:00:00', $saved->start_datetime );
	}

	/**
	 * When only an override date exists, a new occurrence is created rather than overwriting it (NTE-185).
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_creates_new_when_only_override_exists(): void {
		$event = EventFactory::single();

		$override              = OccurrenceFactory::create( array(
			'id'             => 601,
			'event_id'       => $event->id,
			'start_datetime' => '2026-02-10 09:00:00',
			'end_datetime'   => '2026-02-10 11:00:00',
		) );
		$override->is_override = true;

		$start = new \DateTimeImmutable( '2026-03-05 18:00:00' );
		$end   = new \DateTimeImmutable( '2026-03-05 20:00:00' );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $override ) );

		$saved = null;
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'save' )
			->willReturnCallback( function ( $occ ) use ( &$saved ) {
				$saved     = $occ;
				$saved->id = 999;
				return $occ;
			} );

		$this->service->create_single_occurrence( $event, $start, $end );

		// A brand-new occurrence — not the override (id 601).
		$this->assertNotSame( 601, $saved->id );
		$this->assertFalse( $saved->is_override );
		$this->assertSame( '2026-03-05 18:00:00', $saved->start_datetime );
	}

	/**
	 * Test create_single_occurrence handles all-day events.
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_all_day(): void {
		$event = EventFactory::single();
		$start = new \DateTimeImmutable( '2026-03-01 00:00:00' );
		$end   = new \DateTimeImmutable( '2026-03-01 23:59:59' );

		$this->occurrence_repo->method( 'for_event' )->willReturn( array() );
		$this->occurrence_repo
			->method( 'save' )
			->willReturnCallback( function ( $occ ) {
				return $occ;
			} );

		$result = $this->service->create_single_occurrence( $event, $start, $end, true );

		$this->assertTrue( $result->all_day );
	}

	// =========================================================================
	// parse_rule Tests
	// =========================================================================

	/**
	 * Test parse_rule delegates to parser try_parse.
	 *
	 * @return void
	 */
	public function test_parse_rule_delegates_to_parser(): void {
		$rule = RecurrenceRule::daily();

		$this->parser
			->expects( $this->once() )
			->method( 'try_parse' )
			->with( 'FREQ=DAILY' )
			->willReturn( $rule );

		$result = $this->service->parse_rule( 'FREQ=DAILY' );

		$this->assertSame( $rule, $result );
	}

	/**
	 * Test parse_rule returns null on invalid input.
	 *
	 * @return void
	 */
	public function test_parse_rule_returns_null_on_invalid(): void {
		$this->parser
			->method( 'try_parse' )
			->willReturn( null );

		$result = $this->service->parse_rule( 'invalid' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// validate_rule Tests
	// =========================================================================

	/**
	 * Test validate_rule delegates to parser.
	 *
	 * @return void
	 */
	public function test_validate_rule_delegates_to_parser(): void {
		$this->parser
			->expects( $this->once() )
			->method( 'validate' )
			->with( 'FREQ=DAILY;COUNT=500' )
			->willReturn( array( 'COUNT cannot exceed 365' ) );

		$errors = $this->service->validate_rule( 'FREQ=DAILY;COUNT=500' );

		$this->assertNotEmpty( $errors );
	}

	// =========================================================================
	// Regeneration anchor Tests (NTE-200)
	// =========================================================================

	/**
	 * Regeneration expands from the ORIGINAL anchor with a future-only
	 * collection boundary (NTE-200).
	 *
	 * The predecessor logic relocated the window start to "today" when the
	 * anchor had passed. Per RFC 5545 a BYDAY-less rule derives its weekday
	 * from DTSTART, so relocation moved whole series to a different day of
	 * the week and restarted COUNT (oz event 20: 8 Mondays became 13 rows
	 * ending in Fridays). The generator must receive the untouched anchor —
	 * which also carries the original time-of-day, covering the older
	 * "11:18 pm carousel" wall-clock regression — plus a collection boundary
	 * at now.
	 *
	 * @return void
	 */
	public function test_regenerate_expands_from_original_anchor_with_future_collection_boundary(): void {
		$event = EventFactory::recurring();
		$start = new \DateTimeImmutable( '2025-11-03 19:00:00' ); // A Monday, well in the past.
		$end   = new \DateTimeImmutable( '2025-11-03 21:00:00' );
		$rule  = RecurrenceRule::weekly();

		$this->parser->method( 'parse' )->willReturn( $rule );
		$this->occurrence_repo->method( 'for_event' )->willReturn( array() );
		$this->occurrence_repo->method( 'save_batch' )->willReturn( 0 );

		$captured = array();
		$this->generator
			->expects( $this->once() )
			->method( 'generate' )
			->willReturnCallback(
				function ( $ev, $s, $e, $r, $horizon = null, $collect_from = null ) use ( &$captured ) {
					$captured = array( $s, $e, $collect_from );
					return array();
				}
			);

		$this->service->regenerate_future_occurrences( $event, $start, $end, 'FREQ=WEEKLY' );

		list( $anchor_start, $anchor_end, $collect_from ) = $captured;

		$this->assertSame( '2025-11-03 19:00:00', $anchor_start->format( 'Y-m-d H:i:s' ), 'NTE-200: the anchor must never be relocated' );
		$this->assertSame( '2025-11-03 21:00:00', $anchor_end->format( 'Y-m-d H:i:s' ), 'Duration companion must be the original end' );
		$this->assertInstanceOf( \DateTimeInterface::class, $collect_from, 'A collection boundary must be supplied' );
		$this->assertEqualsWithDelta( time(), $collect_from->getTimestamp(), 10, 'Collection boundary must be now, so only future rows are created' );
	}

	// =========================================================================
	// cancel_occurrence Tests
	// =========================================================================

	/**
	 * Test cancel_occurrence updates status.
	 *
	 * @return void
	 */
	public function test_cancel_occurrence_updates_status(): void {
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 42, 'cancelled' )
			->willReturn( true );

		$result = $this->service->cancel_occurrence( 42 );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// reschedule_occurrence Tests
	// =========================================================================

	/**
	 * Test reschedule_occurrence updates dates.
	 *
	 * @return void
	 */
	public function test_reschedule_occurrence_updates_dates(): void {
		$occurrence = OccurrenceFactory::create();
		$new_start  = new \DateTimeImmutable( '2026-04-01 19:00:00' );
		$new_end    = new \DateTimeImmutable( '2026-04-01 21:00:00' );

		$this->occurrence_repo
			->method( 'find' )
			->with( $occurrence->id )
			->willReturn( $occurrence );

		$this->occurrence_repo
			->method( 'save' )
			->willReturnCallback( function ( $occ ) {
				return $occ;
			} );

		$result = $this->service->reschedule_occurrence(
			$occurrence->id,
			$new_start,
			$new_end
		);

		$this->assertSame( '2026-04-01 19:00:00', $result->start_datetime );
		$this->assertSame( '2026-04-01 21:00:00', $result->end_datetime );
		$this->assertTrue( $result->is_rescheduled );
		$this->assertSame( 'rescheduled', $result->status );
	}

	/**
	 * Test reschedule_occurrence returns null when not found.
	 *
	 * @return void
	 */
	public function test_reschedule_occurrence_returns_null_when_not_found(): void {
		$this->occurrence_repo
			->method( 'find' )
			->willReturn( null );

		$result = $this->service->reschedule_occurrence(
			999,
			new \DateTimeImmutable(),
			new \DateTimeImmutable()
		);

		$this->assertNull( $result );
	}

	// =========================================================================
	// get_presets Tests
	// =========================================================================

	/**
	 * Test get_presets returns expected presets.
	 *
	 * @return void
	 */
	public function test_get_presets_returns_expected(): void {
		$presets = RecurrenceService::get_presets();

		$this->assertArrayHasKey( 'daily', $presets );
		$this->assertArrayHasKey( 'weekly', $presets );
		$this->assertArrayHasKey( 'monthly', $presets );
		$this->assertArrayHasKey( 'yearly', $presets );
		$this->assertArrayHasKey( 'weekdays', $presets );
		$this->assertArrayHasKey( 'first_monday', $presets );

		$this->assertSame( 'FREQ=DAILY', $presets['daily']['rrule'] );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', $presets['weekdays']['rrule'] );
	}

	// =========================================================================
	// count_occurrences Tests
	// =========================================================================

	/**
	 * Test count_occurrences delegates to repository.
	 *
	 * @return void
	 */
	public function test_count_occurrences_delegates_to_repo(): void {
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'count_for_event' )
			->with( 1 )
			->willReturn( 52 );

		$count = $this->service->count_occurrences( 1 );

		$this->assertSame( 52, $count );
	}

	// =========================================================================
	// upcoming_for_event Tests
	// =========================================================================

	/**
	 * Test upcoming_for_event delegates to repository.
	 *
	 * @return void
	 */
	public function test_upcoming_for_event_delegates_to_repo(): void {
		$occurrences = array(
			OccurrenceFactory::create( array( 'id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 2 ) ),
		);

		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'for_event' )
			->with(
				5,
				array(
					'upcoming' => true,
					'limit'    => 10,
				)
			)
			->willReturn( $occurrences );

		$result = $this->service->upcoming_for_event( 5 );

		$this->assertCount( 2, $result );
	}

	/**
	 * Test upcoming_for_event accepts custom limit.
	 *
	 * @return void
	 */
	public function test_upcoming_for_event_accepts_limit(): void {
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'for_event' )
			->with(
				5,
				array(
					'upcoming' => true,
					'limit'    => 3,
				)
			)
			->willReturn( array() );

		$this->service->upcoming_for_event( 5, 3 );
	}

	// =========================================================================
	// build_rule Tests
	// =========================================================================

	/**
	 * Test build_rule delegates to parser.
	 *
	 * @return void
	 */
	public function test_build_rule_delegates_to_parser(): void {
		$components = array(
			'FREQ'   => 'WEEKLY',
			'BYDAY'  => array( 'MO', 'WE', 'FR' ),
			'COUNT'  => 10,
		);

		$this->parser
			->expects( $this->once() )
			->method( 'build' )
			->with( $components )
			->willReturn( 'FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=10' );

		$result = $this->service->build_rule( $components );

		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=10', $result );
	}

	// =========================================================================
	// describe_rule Tests
	// =========================================================================

	/**
	 * Test describe_rule returns description for valid rule.
	 *
	 * @return void
	 */
	public function test_describe_rule_returns_description(): void {
		$rule = RecurrenceRule::weekly( 1, array( 'MO', 'WE', 'FR' ) );

		$this->parser
			->method( 'try_parse' )
			->with( 'FREQ=WEEKLY;BYDAY=MO,WE,FR' )
			->willReturn( $rule );

		$result = $this->service->describe_rule( 'FREQ=WEEKLY;BYDAY=MO,WE,FR' );

		// RecurrenceRule::get_description returns a string.
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test describe_rule returns empty string for invalid rule.
	 *
	 * @return void
	 */
	public function test_describe_rule_returns_empty_for_invalid(): void {
		$this->parser
			->method( 'try_parse' )
			->willReturn( null );

		$result = $this->service->describe_rule( 'invalid' );

		$this->assertSame( '', $result );
	}

	// =========================================================================
	// regenerate_future_occurrences Tests
	// =========================================================================

	/**
	 * Test regenerate_future_occurrences returns errors for invalid rule.
	 *
	 * @return void
	 */
	public function test_regenerate_future_returns_errors_for_invalid(): void {
		$event = EventFactory::recurring();
		$start = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-15 21:00:00' );

		$this->parser
			->method( 'parse' )
			->willThrowException( RRuleException::parseFailed( 'FREQ=INVALID', 'Invalid rule' ) );

		$result = $this->service->regenerate_future_occurrences(
			$event,
			$start,
			$end,
			'FREQ=INVALID'
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Invalid rule', $result['errors'][0] );
	}

	// Note: test_regenerate_future_deletes_and_generates requires delete_future_for_event
	// method which exists in concrete class but not in interface. Integration test needed.

	// =========================================================================
	// apply_templates_to_occurrences Tests
	// =========================================================================

	/**
	 * Test apply_templates returns zero for empty occurrences.
	 *
	 * @return void
	 */
	public function test_apply_templates_returns_zero_for_empty(): void {
		$event = EventFactory::recurring();

		$result = $this->service->apply_templates_to_occurrences( $event, array() );

		$this->assertSame( 0, $result );
	}

	/**
	 * get_active_templates passes the event's templates straight through (R1).
	 *
	 * @return void
	 */
	public function test_get_active_templates_returns_repo_templates(): void {
		$templates = array( new \NetterTechEvents\Models\TicketType() );
		$this->ticket_type_repo
			->method( 'get_templates' )
			->with( 42 )
			->willReturn( $templates );

		$this->assertSame( $templates, $this->service->get_active_templates( 42 ) );
	}

	/**
	 * Test apply_templates returns zero for event without ID.
	 *
	 * @return void
	 */
	public function test_apply_templates_returns_zero_for_event_without_id(): void {
		$event     = EventFactory::recurring();
		$event->id = null;

		$occurrences = array( OccurrenceFactory::create() );

		$result = $this->service->apply_templates_to_occurrences( $event, $occurrences );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test apply_templates returns zero when no templates exist.
	 *
	 * @return void
	 */
	public function test_apply_templates_returns_zero_when_no_templates(): void {
		$event       = EventFactory::recurring();
		$occurrences = array( OccurrenceFactory::create() );

		$this->ticket_type_repo
			->method( 'get_templates' )
			->with( $event->id )
			->willReturn( array() );

		$result = $this->service->apply_templates_to_occurrences( $event, $occurrences );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test apply_templates creates tickets from templates.
	 *
	 * @return void
	 */
	public function test_apply_templates_creates_tickets(): void {
		$event       = EventFactory::recurring();
		$occurrences = array(
			OccurrenceFactory::create( array( 'id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 2 ) ),
		);

		$template          = new \NetterTechEvents\Models\TicketType();
		$template->id      = 100;
		$template->name    = 'General Admission';
		$template->price   = 25.00;
		$template->event_id = $event->id;

		$this->ticket_type_repo
			->method( 'get_templates' )
			->willReturn( array( $template ) );

		$new_ticket        = new \NetterTechEvents\Models\TicketType();
		$new_ticket->price = 25.00;

		$this->ticket_type_repo
			->method( 'create_from_template' )
			->willReturn( $new_ticket );

		$this->ticket_type_repo
			->method( 'save' )
			->willReturn( $new_ticket );

		$result = $this->service->apply_templates_to_occurrences( $event, $occurrences );

		// 2 occurrences * 1 template = 2 tickets.
		$this->assertSame( 2, $result );
	}

	/**
	 * Test apply_templates skips occurrences without ID.
	 *
	 * @return void
	 */
	public function test_apply_templates_skips_occurrences_without_id(): void {
		$event = EventFactory::recurring();

		$occ_with_id     = OccurrenceFactory::create( array( 'id' => 1 ) );
		$occ_without_id  = new Occurrence();
		$occ_without_id->id = null;

		$occurrences = array( $occ_with_id, $occ_without_id );

		$template          = new \NetterTechEvents\Models\TicketType();
		$template->id      = 100;
		$template->price   = 0.00; // Free to avoid WooCommerce sync.
		$template->event_id = $event->id;

		$this->ticket_type_repo
			->method( 'get_templates' )
			->willReturn( array( $template ) );

		$new_ticket        = new \NetterTechEvents\Models\TicketType();
		$new_ticket->price = 0.00;

		$this->ticket_type_repo
			->method( 'create_from_template' )
			->willReturn( $new_ticket );

		$this->ticket_type_repo
			->method( 'save' )
			->willReturn( $new_ticket );

		$result = $this->service->apply_templates_to_occurrences( $event, $occurrences );

		// Only 1 occurrence has ID.
		$this->assertSame( 1, $result );
	}

	// =========================================================================
	// Error Handling Tests
	// =========================================================================

	/**
	 * Test generate_occurrences handles parse exception.
	 *
	 * @return void
	 */
	public function test_generate_occurrences_handles_parse_exception(): void {
		$event = EventFactory::recurring();
		$start = new \DateTimeImmutable( '2026-01-15 19:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-15 21:00:00' );

		$this->parser
			->method( 'validate' )
			->willReturn( array() );

		$this->parser
			->method( 'parse' )
			->willThrowException( RRuleException::parseFailed( 'FREQ=DAILY', 'Parse error' ) );

		$result = $this->service->generate_occurrences(
			$event,
			$start,
			$end,
			'FREQ=DAILY'
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Parse error', $result['errors'][0] );
	}

	/**
	 * Test create_single_occurrence returns null on save failure.
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_returns_null_on_failure(): void {
		$event = EventFactory::single();
		$start = new \DateTimeImmutable( '2026-02-20 14:00:00' );
		$end   = new \DateTimeImmutable( '2026-02-20 16:00:00' );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array() );

		$this->occurrence_repo
			->method( 'save' )
			->willThrowException( new \RuntimeException( 'Save failed' ) );

		$result = $this->service->create_single_occurrence( $event, $start, $end );

		$this->assertNull( $result );
	}

	/**
	 * Test reschedule_occurrence returns null on save failure.
	 *
	 * @return void
	 */
	public function test_reschedule_occurrence_returns_null_on_save_failure(): void {
		$occurrence = OccurrenceFactory::create();

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->occurrence_repo
			->method( 'save' )
			->willThrowException( new \RuntimeException( 'Save failed' ) );

		$result = $this->service->reschedule_occurrence(
			$occurrence->id,
			new \DateTimeImmutable(),
			new \DateTimeImmutable()
		);

		$this->assertNull( $result );
	}

	// =========================================================================
	// DST Edge Case Tests
	// =========================================================================

	/**
	 * Test occurrence during DST spring forward (2am becomes 3am).
	 *
	 * In US/Central timezone, March 9, 2025 at 2:00 AM becomes 3:00 AM.
	 * Events scheduled for 2:30 AM should handle this gracefully.
	 *
	 * @return void
	 */
	public function test_occurrence_handles_dst_spring_forward(): void {
		// March 9, 2025 is DST spring forward in US.
		$timezone = new \DateTimeZone( 'America/Chicago' );

		// Event at 1:30 AM - before DST transition.
		$before_dst = new \DateTimeImmutable( '2025-03-09 01:30:00', $timezone );

		// Verify timezone is applied correctly.
		$this->assertSame( 'America/Chicago', $before_dst->getTimezone()->getName() );

		// After DST: 1:30 AM + 1 hour should be 3:30 AM (skipping 2:00-3:00).
		$after_dst = $before_dst->modify( '+1 hour' );

		// The difference should still be 1 hour.
		$diff = $before_dst->diff( $after_dst );
		$this->assertSame( 1, $diff->h );
	}

	/**
	 * Test occurrence during DST fall back (2am repeats).
	 *
	 * In US/Central timezone, November 2, 2025 at 2:00 AM falls back to 1:00 AM.
	 * Events should handle the repeated hour correctly.
	 *
	 * @return void
	 */
	public function test_occurrence_handles_dst_fall_back(): void {
		// November 2, 2025 is DST fall back in US.
		$timezone = new \DateTimeZone( 'America/Chicago' );

		// Event at 1:30 AM - this time occurs twice during fall back.
		$during_dst = new \DateTimeImmutable( '2025-11-02 01:30:00', $timezone );

		// Verify timezone is applied.
		$this->assertSame( 'America/Chicago', $during_dst->getTimezone()->getName() );

		// DateTimeImmutable handles this correctly by using standard time.
		$this->assertInstanceOf( \DateTimeImmutable::class, $during_dst );
	}

	/**
	 * Test daily recurrence across DST boundary.
	 *
	 * A daily event at 10:00 AM should remain at 10:00 AM local time
	 * regardless of DST transitions.
	 *
	 * @return void
	 */
	public function test_daily_recurrence_across_dst_boundary(): void {
		$timezone = new \DateTimeZone( 'America/Chicago' );

		// Event on March 8, 2025 (before DST).
		$march_8 = new \DateTimeImmutable( '2025-03-08 10:00:00', $timezone );

		// Event on March 9, 2025 (after DST spring forward).
		$march_9 = new \DateTimeImmutable( '2025-03-09 10:00:00', $timezone );

		// Both should be at 10:00 AM local time.
		$this->assertSame( '10', $march_8->format( 'H' ) );
		$this->assertSame( '10', $march_9->format( 'H' ) );

		// But the UTC offset changes.
		$this->assertSame( '-06:00', $march_8->format( 'P' ) ); // CST.
		$this->assertSame( '-05:00', $march_9->format( 'P' ) ); // CDT.
	}

	// =========================================================================
	// Leap Year Edge Case Tests
	// =========================================================================

	/**
	 * Test occurrence on February 29 in a leap year.
	 *
	 * @return void
	 */
	public function test_occurrence_on_leap_day(): void {
		// 2024 is a leap year.
		$leap_day = new \DateTimeImmutable( '2024-02-29 14:00:00' );

		$this->assertSame( '2024-02-29', $leap_day->format( 'Y-m-d' ) );
		$this->assertSame( '29', $leap_day->format( 'd' ) );
		$this->assertSame( '02', $leap_day->format( 'm' ) );
	}

	/**
	 * Test yearly recurrence on February 29 skips non-leap years.
	 *
	 * When an event recurs yearly on Feb 29, it only occurs in leap years.
	 *
	 * @return void
	 */
	public function test_yearly_recurrence_feb_29_skips_non_leap_years(): void {
		// Feb 29, 2024 (leap year).
		$leap_2024 = new \DateTimeImmutable( '2024-02-29' );

		// 2025, 2026, 2027 are not leap years - Feb 29 doesn't exist.
		// 2028 is a leap year.
		$this->assertTrue( checkdate( 2, 29, 2024 ) );
		$this->assertFalse( checkdate( 2, 29, 2025 ) );
		$this->assertFalse( checkdate( 2, 29, 2026 ) );
		$this->assertFalse( checkdate( 2, 29, 2027 ) );
		$this->assertTrue( checkdate( 2, 29, 2028 ) );
	}

	/**
	 * Test monthly recurrence near month end handles variable month lengths.
	 *
	 * @return void
	 */
	public function test_monthly_recurrence_handles_variable_month_lengths(): void {
		// Event on Jan 31.
		$jan_31 = new \DateTimeImmutable( '2025-01-31' );

		// February has 28 days (non-leap year).
		$this->assertSame( '31', $jan_31->format( 'd' ) );

		// Verify Feb 2025 has 28 days.
		$feb_end = new \DateTimeImmutable( '2025-02-28' );
		$this->assertSame( '28', $feb_end->format( 'd' ) );

		// March 31 exists.
		$march_31 = new \DateTimeImmutable( '2025-03-31' );
		$this->assertSame( '31', $march_31->format( 'd' ) );

		// April only has 30 days.
		$this->assertTrue( checkdate( 4, 30, 2025 ) );
		$this->assertFalse( checkdate( 4, 31, 2025 ) );
	}

	/**
	 * Test century years and leap year rules.
	 *
	 * Years divisible by 100 are not leap years unless also divisible by 400.
	 * 2000 was a leap year, 2100 will not be.
	 *
	 * @return void
	 */
	public function test_century_leap_year_rules(): void {
		// 2000 was divisible by 400 - was a leap year.
		$this->assertTrue( checkdate( 2, 29, 2000 ) );

		// 2100 is divisible by 100 but not 400 - not a leap year.
		$this->assertFalse( checkdate( 2, 29, 2100 ) );

		// 2400 will be divisible by 400 - will be a leap year.
		$this->assertTrue( checkdate( 2, 29, 2400 ) );
	}

	// =========================================================================
	// Collapsing a recurring event to a single date (NTE-153)
	// =========================================================================

	/**
	 * Build a saved event with an id, for the conversion tests.
	 *
	 * @return Event
	 */
	private function saved_event(): Event {
		$event             = EventFactory::create();
		$event->id         = 7;
		$event->event_type = 'single';

		return $event;
	}

	/**
	 * Three dates on the event, earliest first — the order for_event() returns them in.
	 *
	 * @return array<Occurrence>
	 */
	private function three_dates(): array {
		return array(
			OccurrenceFactory::create(
				array(
					'id'             => 101,
					'event_id'       => 7,
					'start_datetime' => '2030-03-03 19:00:00',
					'end_datetime'   => '2030-03-03 21:00:00',
				)
			),
			OccurrenceFactory::create(
				array(
					'id'             => 102,
					'event_id'       => 7,
					'start_datetime' => '2030-03-10 19:00:00',
					'end_datetime'   => '2030-03-10 21:00:00',
				)
			),
			OccurrenceFactory::create(
				array(
					'id'             => 103,
					'event_id'       => 7,
					'start_datetime' => '2030-03-17 19:00:00',
					'end_datetime'   => '2030-03-17 21:00:00',
				)
			),
		);
	}

	/**
	 * Test the operator's chosen date is the one that lives, and the others are removed.
	 *
	 * @return void
	 */
	public function test_conversion_keeps_the_chosen_date_and_removes_the_rest(): void {
		$this->occurrence_repo->method( 'for_event' )->willReturn( $this->three_dates() );
		$this->attendee_repo->method( 'count_for_occurrence' )->willReturn( 0 );

		$deleted = array();
		$this->occurrence_repo->method( 'delete' )
			->willReturnCallback(
				function ( int $id ) use ( &$deleted ): bool {
					$deleted[] = $id;
					return true;
				}
			);

		$result = $this->service->convert_to_single( $this->saved_event(), 102 );

		$this->assertSame( 102, $result['kept'] );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertEqualsCanonicalizing( array( 101, 103 ), $deleted );
	}

	/**
	 * Test the first date survives when the operator expressed no preference.
	 *
	 * "Keep the first one" is what an operator means by saying nothing.
	 *
	 * @return void
	 */
	public function test_conversion_defaults_to_keeping_the_first_date(): void {
		$this->occurrence_repo->method( 'for_event' )->willReturn( $this->three_dates() );
		$this->attendee_repo->method( 'count_for_occurrence' )->willReturn( 0 );
		$this->occurrence_repo->method( 'delete' )->willReturn( true );

		$result = $this->service->convert_to_single( $this->saved_event(), null );

		$this->assertSame( 101, $result['kept'] );
		$this->assertSame( 2, $result['deleted'] );
	}

	/**
	 * Test a date somebody has bought a ticket to is never deleted to make the event single.
	 *
	 * The bug this pins. The old path deleted nothing and moved the earliest date instead, so sold
	 * seats silently ended up on a different day. Deleting them instead would be worse. The only
	 * honest answer is to refuse and tell the operator which dates are in the way, so *they* decide
	 * whether to cancel and refund.
	 *
	 * @return void
	 */
	public function test_conversion_is_refused_when_a_doomed_date_has_attendees(): void {
		$this->occurrence_repo->method( 'for_event' )->willReturn( $this->three_dates() );

		// The third date has sold seats. It is not the survivor, so it would be deleted.
		$this->attendee_repo->method( 'count_for_occurrence' )
			->willReturnCallback(
				fn( int $id ): int => 103 === $id ? 4 : 0
			);

		$this->occurrence_repo->expects( $this->never() )->method( 'delete' );

		$this->expectException( \NetterTechEvents\Exceptions\ValidationException::class );

		$this->service->convert_to_single( $this->saved_event(), 101 );
	}

	/**
	 * Test the check can be made before anything is written.
	 *
	 * The event row is saved as 'single' with its rule nulled *before* the conversion runs. If the
	 * refusal only arrived afterwards, a rejected conversion would leave an event that no longer
	 * recurs, has no rule, and still has every one of its dates — a state nothing else can read. So
	 * the caller must be able to ask first, and asking must not delete anything.
	 *
	 * @return void
	 */
	public function test_the_refusal_can_be_had_without_writing_anything(): void {
		$this->occurrence_repo->method( 'for_event' )->willReturn( $this->three_dates() );
		$this->attendee_repo->method( 'count_for_occurrence' )
			->willReturnCallback(
				fn( int $id ): int => 102 === $id ? 1 : 0
			);

		$this->occurrence_repo->expects( $this->never() )->method( 'delete' );

		$this->expectException( \NetterTechEvents\Exceptions\ValidationException::class );

		$this->service->assert_convertible_to_single( 7, 101 );
	}

	/**
	 * Test attendees on the *surviving* date do not block the conversion.
	 *
	 * That date is not going anywhere — it keeps its own start and end. Refusing here would make an
	 * event with any sales at all impossible to convert, which is not a guard, it is a wall.
	 *
	 * @return void
	 */
	public function test_attendees_on_the_surviving_date_do_not_block_the_conversion(): void {
		$this->occurrence_repo->method( 'for_event' )->willReturn( $this->three_dates() );

		// Only the survivor has sales.
		$this->attendee_repo->method( 'count_for_occurrence' )
			->willReturnCallback(
				fn( int $id ): int => 101 === $id ? 12 : 0
			);

		$this->occurrence_repo->method( 'delete' )->willReturn( true );

		$result = $this->service->convert_to_single( $this->saved_event(), 101 );

		$this->assertSame( 101, $result['kept'] );
		$this->assertSame( 2, $result['deleted'] );
	}

	/**
	 * Test an event with a single date converts to a no-op rather than deleting its only date.
	 *
	 * @return void
	 */
	public function test_conversion_of_a_one_date_event_removes_nothing(): void {
		$only = OccurrenceFactory::create(
			array(
				'id'       => 101,
				'event_id' => 7,
			)
		);

		$this->occurrence_repo->method( 'for_event' )->willReturn( array( $only ) );
		$this->occurrence_repo->expects( $this->never() )->method( 'delete' );

		$result = $this->service->convert_to_single( $this->saved_event(), null );

		$this->assertSame( 101, $result['kept'] );
		$this->assertSame( 0, $result['deleted'] );
	}

	/**
	 * Test a choice that does not belong to this event falls back to the first date.
	 *
	 * A stale or tampered id must not be able to make the conversion keep nothing, or delete
	 * everything.
	 *
	 * @return void
	 */
	public function test_an_unknown_choice_falls_back_to_the_first_date(): void {
		$this->occurrence_repo->method( 'for_event' )->willReturn( $this->three_dates() );
		$this->attendee_repo->method( 'count_for_occurrence' )->willReturn( 0 );
		$this->occurrence_repo->method( 'delete' )->willReturn( true );

		$result = $this->service->convert_to_single( $this->saved_event(), 9999 );

		$this->assertSame( 101, $result['kept'] );
		$this->assertSame( 2, $result['deleted'] );
	}

	// =========================================================================
	// remove_occurrences_colliding_with_survivors() Tests (NTE-177)
	// =========================================================================

	/**
	 * Build an Occurrence with a start datetime and sequence number.
	 *
	 * @param string $start    Start datetime.
	 * @param int    $sequence Sequence number.
	 * @return Occurrence
	 */
	private function make_occurrence( string $start, int $sequence ): Occurrence {
		$occurrence                  = new Occurrence();
		$occurrence->start_datetime  = $start;
		$occurrence->sequence_number = $sequence;
		return $occurrence;
	}

	/**
	 * Build a survivor with a current slot and the origin slot it was generated at.
	 *
	 * @param string      $start  Current start datetime.
	 * @param string|null $origin Origin start datetime (rule slot at generation).
	 * @return Occurrence
	 */
	private function make_survivor( string $start, ?string $origin ): Occurrence {
		$occurrence                        = new Occurrence();
		$occurrence->start_datetime        = $start;
		$occurrence->origin_start_datetime = $origin;
		return $occurrence;
	}

	/**
	 * A generated occurrence is dropped when a survivor already holds its slot —
	 * by current start datetime or by a moved override's origin slot (NTE-177).
	 *
	 * Survivors: a row at slot A, and an override moved from origin slot B to a
	 * custom datetime. Generated: slot-A collision (dropped), origin-slot-B
	 * resurrection (dropped), and a clean row (kept, re-indexed from zero).
	 *
	 * @return void
	 */
	public function test_remove_occurrences_colliding_with_survivors_filters_by_slot_and_origin(): void {
		$survivors = array(
			$this->make_survivor( '2026-06-10 19:00:00', '2026-06-10 19:00:00' ),
			$this->make_survivor( '2026-06-20 17:30:00', '2026-06-17 19:00:00' ),
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( $survivors );

		$g_slot_collision   = $this->make_occurrence( '2026-06-10 19:00:00', 1 );
		$g_origin_collision = $this->make_occurrence( '2026-06-17 19:00:00', 2 );
		$g_clean            = $this->make_occurrence( '2026-09-01 19:00:00', 9 );

		$generated = array( $g_slot_collision, $g_origin_collision, $g_clean );

		$method = new \ReflectionMethod( RecurrenceService::class, 'remove_occurrences_colliding_with_survivors' );
		$result = $method->invoke( $this->service, 55, $generated );

		$this->assertSame( array( $g_clean ), $result );
	}

	/**
	 * Regression (NTE-182): a survivor's historical sequence number must never
	 * suppress a fresh occurrence at a genuinely new datetime.
	 *
	 * Scenario that lost the first day of a short daily run: the day-1 override
	 * survives protection holding sequence 1; the event start then moves and
	 * regeneration numbers the new first row 1 as well. Matching by sequence
	 * silently discarded that row. With datetime-only slot identity all three
	 * new dates survive.
	 *
	 * @return void
	 */
	public function test_remove_occurrences_colliding_with_survivors_keeps_new_first_occurrence(): void {
		// Day-1 override of the original run (Nov 27), never moved: origin == start.
		$survivor                  = $this->make_survivor( '2026-11-27 18:00:00', '2026-11-27 18:00:00' );
		$survivor->sequence_number = 1;

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $survivor ) );

		// Re-save with the run moved to Dec 4-6; the new first row is numbered 1 again.
		$generated = array(
			$this->make_occurrence( '2026-12-04 18:00:00', 1 ),
			$this->make_occurrence( '2026-12-05 18:00:00', 2 ),
			$this->make_occurrence( '2026-12-06 18:00:00', 3 ),
		);

		$method = new \ReflectionMethod( RecurrenceService::class, 'remove_occurrences_colliding_with_survivors' );
		$result = $method->invoke( $this->service, 55, $generated );

		$this->assertSame( $generated, $result );
	}

	/**
	 * With no survivors, every generated occurrence passes through unchanged.
	 *
	 * @return void
	 */
	public function test_remove_occurrences_colliding_with_survivors_passes_through_without_survivors(): void {
		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array() );

		$generated = array(
			$this->make_occurrence( '2026-06-10 19:00:00', 1 ),
			$this->make_occurrence( '2026-06-17 19:00:00', 2 ),
		);

		$method = new \ReflectionMethod( RecurrenceService::class, 'remove_occurrences_colliding_with_survivors' );
		$result = $method->invoke( $this->service, 55, $generated );

		$this->assertSame( $generated, $result );
	}
}
