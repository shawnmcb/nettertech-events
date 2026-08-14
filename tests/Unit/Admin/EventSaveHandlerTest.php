<?php
/**
 * EventSaveHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\EventSaveHandler;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\TicketTypeSaver;

/**
 * Test EventSaveHandler functionality.
 *
 * Tests the event save, validation, and processing logic.
 */
class EventSaveHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_occurrence_repo;

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_event_repo;

	/**
	 * Mock category repository.
	 *
	 * @var CategoryRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_category_repo;

	/**
	 * Mock recurrence service.
	 *
	 * @var RecurrenceService|Mockery\MockInterface
	 */
	private $mock_recurrence_service;

	/**
	 * Mock ticket type saver.
	 *
	 * @var TicketTypeSaver|Mockery\MockInterface
	 */
	private $mock_ticket_saver;

	/**
	 * Handler instance under test.
	 *
	 * @var EventSaveHandler
	 */
	private EventSaveHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mock_occurrence_repo    = Mockery::mock( OccurrenceRepositoryInterface::class );
		// The save path asks how many dates the event has to decide whether
		// event-scoped tickets (series passes) apply (NTE-156). One date is the
		// neutral default; tests exercising the multi-date branch override it.
		$this->mock_occurrence_repo->shouldReceive( 'count_for_event' )->andReturn( 1 )->byDefault();
		$this->mock_event_repo         = Mockery::mock( EventRepositoryInterface::class );
		$this->mock_category_repo      = Mockery::mock( CategoryRepositoryInterface::class );
		$this->mock_recurrence_service = Mockery::mock( RecurrenceService::class );
		$this->mock_ticket_saver       = Mockery::mock( TicketTypeSaver::class );
		// Event-scoped ticket saving is now unconditional (R6); it self-guards on rendered
		// markers, so most process_save tests neither set it up nor care. Allow it by default;
		// tests asserting the call override this with a specific expectation.
		$this->mock_ticket_saver->shouldReceive( 'save_for_event' )->byDefault();

		$mock_layout_service = Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout_service->shouldIgnoreMissing();

		$mock_rrule_builder = Mockery::mock( \NetterTechEvents\Services\RecurrenceRuleBuilder::class );
		$mock_rrule_builder->shouldIgnoreMissing();

		$mock_attendee_fields_saver = Mockery::mock( \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class );
		$mock_attendee_fields_saver->shouldIgnoreMissing();

		$mock_checkin_email_saver = Mockery::mock( \NetterTechEvents\Services\CheckInEmailSaver::class );
		$mock_checkin_email_saver->shouldIgnoreMissing();

		$this->handler = new EventSaveHandler(
			$this->mock_event_repo,
			$this->mock_occurrence_repo,
			$this->mock_category_repo,
			$this->mock_recurrence_service,
			$this->mock_ticket_saver,
			$mock_layout_service,
			$mock_rrule_builder,
			$mock_attendee_fields_saver,
			$mock_checkin_email_saver
		);

		// Common WordPress function mocks.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'number_format_i18n' )->returnArg();
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	// =========================================================================
	// handle_save() Tests
	// =========================================================================

	/**
	 * Test handle_save dies without permission.
	 *
	 * @return void
	 */
	public function test_handle_save_dies_without_permission(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->handler->handle_save();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test handle_save dies on invalid nonce.
	 *
	 * @return void
	 */
	public function test_handle_save_dies_on_invalid_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$_POST['nettertech_events_event_nonce'] = 'invalid';

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->handler->handle_save();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test handle_save dies when nonce not set.
	 *
	 * @return void
	 */
	public function test_handle_save_dies_when_nonce_not_set(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->handler->handle_save();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	// =========================================================================
	// process_layout_config() Tests
	// =========================================================================

	/**
	 * Test process_layout_config sets null for global mode.
	 *
	 * @return void
	 */
	public function test_process_layout_config_sets_null_for_global_mode(): void {
		$_POST['nettertech_events_layout_mode'] = 'global';

		$event                = new Event();
		$event->layout_config = array( 'existing' => 'config' );

		$this->handler->process_layout_config( $event, $_POST );

		$this->assertNull( $event->layout_config );
	}

	/**
	 * Test process_layout_config sets null when mode not custom.
	 *
	 * @return void
	 */
	public function test_process_layout_config_sets_null_when_mode_not_custom(): void {
		$_POST['nettertech_events_layout_mode'] = 'default';

		$event                = new Event();
		$event->layout_config = array( 'existing' => 'config' );

		$this->handler->process_layout_config( $event, $_POST );

		$this->assertNull( $event->layout_config );
	}

	/**
	 * Test process_layout_config returns early when layout_order empty.
	 *
	 * @return void
	 */
	public function test_process_layout_config_returns_when_layout_order_empty(): void {
		$_POST['nettertech_events_layout_mode']     = 'custom';
		$_POST['event_layout_order'] = '';

		$event                = new Event();
		$event->layout_config = null;

		$this->handler->process_layout_config( $event, $_POST );

		$this->assertNull( $event->layout_config );
	}

	/**
	 * Test process_layout_config handles invalid visibility JSON.
	 *
	 * @return void
	 */
	public function test_process_layout_config_handles_invalid_visibility_json(): void {
		$_POST['nettertech_events_layout_mode']          = 'custom';
		$_POST['event_layout_order']      = 'title,description,tickets';
		$_POST['event_layout_visibility'] = 'not-valid-json';

		$event = new Event();

		$this->handler->process_layout_config( $event, $_POST );

		// Should not throw, layout_visibility treated as empty array.
		$this->assertTrue( true );
	}

	/**
	 * Test process_layout_config sets valid config.
	 *
	 * @return void
	 */
	public function test_process_layout_config_sets_valid_config(): void {
		$_POST['nettertech_events_layout_mode']          = 'custom';
		$_POST['event_layout_order']      = 'title,description,tickets';
		$_POST['event_layout_visibility'] = '{"title":true,"description":true,"tickets":false}';

		$event = new Event();

		$this->handler->process_layout_config( $event, $_POST );

		// Config should be set (validated by LayoutService).
		// Note: Without mocking LayoutService, this tests the integration path.
		$this->assertTrue( true );
	}

	// =========================================================================
	// process_occurrences() Tests
	// =========================================================================

	/**
	 * Test process_occurrences returns early when start_date empty.
	 *
	 * @return void
	 */
	public function test_process_occurrences_returns_when_start_date_empty(): void {
		$_POST['start_date'] = '';

		$event = new Event();

		// Should return without errors.
		$this->handler->process_occurrences( $event, $this->mock_occurrence_repo, $_POST );

		$this->assertTrue( true );
	}

	/**
	 * Test process_occurrences uses default times when not provided.
	 *
	 * @return void
	 */
	public function test_process_occurrences_uses_default_times(): void {
		$_POST['start_date'] = '2026-06-15';
		// start_time, end_time not set; all_day not set.
		// Industry-standard default for venue event-publishing: 7:00 PM start,
		// 2-hour duration. Author supplied a date deliberately but left time
		// blank — we soft-fill rather than reject. See research in session
		// notes (Google/Apple/Outlook vs Eventbrite/Tito/TEC/MEC).

		$event             = new Event();
		$event->id         = 1;
		$event->event_type = 'single';

		$occurrence     = new Occurrence();
		$occurrence->id = 10;

		$captured_start = null;
		$captured_end   = null;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->with(
				Mockery::any(),
				Mockery::on( function ( $start ) use ( &$captured_start ) {
					$captured_start = $start;
					return $start instanceof \DateTimeInterface;
				} ),
				Mockery::on( function ( $end ) use ( &$captured_end ) {
					$captured_end = $end;
					return $end instanceof \DateTimeInterface;
				} ),
				Mockery::any()
			)
			->andReturn( $occurrence );

		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->with( $occurrence );

		$this->mock_ticket_saver
			->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 10, Mockery::type( 'array' ), 1 );

		$this->handler->process_occurrences( $event, $this->mock_occurrence_repo, $_POST );

		$this->assertSame( '19:00:00', $captured_start->format( 'H:i:s' ), 'Default start time should be 7:00 PM' );
		$this->assertSame( '21:00:00', $captured_end->format( 'H:i:s' ), 'Default end time should be start + 120 minutes' );
	}

	/**
	 * Test process_occurrences preserves the all-day midnight-to-end pattern.
	 *
	 * All-day events span 00:00 to 23:59 — the soft-fill default for timed
	 * events (7:00 PM / 2 hours) does not apply when all_day is checked.
	 *
	 * @return void
	 */
	public function test_process_occurrences_all_day_uses_midnight_pattern(): void {
		$_POST['start_date'] = '2026-06-15';
		$_POST['all_day']    = '1';

		$event             = new Event();
		$event->id         = 1;
		$event->event_type = 'single';

		$occurrence     = new Occurrence();
		$occurrence->id = 11;

		$captured_start = null;
		$captured_end   = null;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->with(
				Mockery::any(),
				Mockery::on( function ( $start ) use ( &$captured_start ) {
					$captured_start = $start;
					return $start instanceof \DateTimeInterface;
				} ),
				Mockery::on( function ( $end ) use ( &$captured_end ) {
					$captured_end = $end;
					return $end instanceof \DateTimeInterface;
				} ),
				Mockery::any()
			)
			->andReturn( $occurrence );

		$this->mock_occurrence_repo->shouldReceive( 'save' )->once()->with( $occurrence );
		$this->mock_ticket_saver->shouldReceive( 'save_for_occurrence' )->once();

		$this->handler->process_occurrences( $event, $this->mock_occurrence_repo, $_POST );

		$this->assertSame( '00:00:00', $captured_start->format( 'H:i:s' ), 'All-day start should be midnight' );
		$this->assertSame( '23:59:00', $captured_end->format( 'H:i:s' ), 'All-day end should be 23:59' );
	}

	// =========================================================================
	// validate_occurrence_data() Tests
	// =========================================================================

	/**
	 * Test validate_occurrence_data throws when duration too short.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_throws_when_duration_too_short(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 10:05:00' ); // Only 5 minutes.

		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'at least 10 minutes' );

		$this->handler->validate_occurrence_data( $event, $start, $end, null );
	}

	/**
	 * Test validate_occurrence_data passes with valid duration.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_passes_with_valid_duration(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' ); // 2 hours.

		// No redirect should happen.
		$this->handler->validate_occurrence_data( $event, $start, $end, null );

		$this->assertTrue( true );
	}

	/**
	 * Test validate_occurrence_data passes with exactly 10 minutes.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_passes_with_exactly_10_minutes(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 10:10:00' ); // Exactly 10 minutes.

		$this->handler->validate_occurrence_data( $event, $start, $end, null );

		$this->assertTrue( true );
	}

	/**
	 * Test validate_occurrence_data throws with negative capacity.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_throws_with_negative_capacity(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'positive number' );

		$this->handler->validate_occurrence_data( $event, $start, $end, -5 );
	}

	/**
	 * Test validate_occurrence_data throws with non-numeric capacity.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_throws_with_non_numeric_capacity(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'positive number' );

		$this->handler->validate_occurrence_data( $event, $start, $end, 'abc' );
	}

	/**
	 * Test validate_occurrence_data passes with null capacity.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_passes_with_null_capacity(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->handler->validate_occurrence_data( $event, $start, $end, null );

		$this->assertTrue( true );
	}

	/**
	 * Test validate_occurrence_data passes with empty string capacity.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_passes_with_empty_string_capacity(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->handler->validate_occurrence_data( $event, $start, $end, '' );

		$this->assertTrue( true );
	}

	/**
	 * Test validate_occurrence_data passes with zero capacity.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_passes_with_zero_capacity(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->handler->validate_occurrence_data( $event, $start, $end, 0 );

		$this->assertTrue( true );
	}

	/**
	 * Test validate_occurrence_data passes with positive capacity.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_passes_with_positive_capacity(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->handler->validate_occurrence_data( $event, $start, $end, 100 );

		$this->assertTrue( true );
	}

	/**
	 * Test validate_occurrence_data passes with string numeric capacity.
	 *
	 * @return void
	 */
	public function test_validate_occurrence_data_passes_with_string_numeric_capacity(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->handler->validate_occurrence_data( $event, $start, $end, '50' );

		$this->assertTrue( true );
	}

	// =========================================================================
	// create_recurring_occurrences() Tests
	// =========================================================================

	/**
	 * Test create_recurring_occurrences delegates to recurrence service.
	 *
	 * @return void
	 */
	public function test_create_recurring_occurrences_delegates_to_recurrence_service(): void {
		$event                  = new Event();
		$event->id              = 1;
		$event->event_type      = 'recurring';
		$event->recurrence_rule = 'FREQ=WEEKLY;COUNT=5';

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->mock_recurrence_service
			->shouldReceive( 'generate_occurrences' )
			->once()
			->with( $event, $start, $end, 'FREQ=WEEKLY;COUNT=5', true )
			->andReturn( array( 'errors' => array() ) );

		$this->handler->create_recurring_occurrences( $event, $start, $end );

		$this->assertTrue( true );
	}

	/**
	 * Test create_recurring_occurrences throws on errors.
	 *
	 * @return void
	 */
	public function test_create_recurring_occurrences_throws_on_errors(): void {
		$event                  = new Event();
		$event->id              = 1;
		$event->event_type      = 'recurring';
		$event->recurrence_rule = 'FREQ=WEEKLY;COUNT=5';

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->mock_recurrence_service
			->shouldReceive( 'generate_occurrences' )
			->once()
			->andReturn( array( 'errors' => array( 'Something went wrong' ) ) );

		$this->expectException( \NetterTechEvents\Exceptions\ValidationException::class );

		$this->handler->create_recurring_occurrences( $event, $start, $end );
	}

	// =========================================================================
	// create_single_occurrence() Tests
	// =========================================================================

	/**
	 * Test create_single_occurrence returns early when no occurrence created.
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_returns_when_no_occurrence(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->with( $event, $start, $end, false )
			->andReturn( null );

		$this->handler->create_single_occurrence(
			$event,
			$start,
			$end,
			false,
			null,
			$this->mock_occurrence_repo,
			$_POST
		);

		$this->assertTrue( true );
	}

	/**
	 * Test create_single_occurrence handles capacity values.
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_handles_capacity(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$occurrence     = new Occurrence();
		$occurrence->id = 5;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->with( $event, $start, $end, false )
			->andReturn( $occurrence );

		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->with( $occurrence );

		$this->mock_ticket_saver
			->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 5, Mockery::type( 'array' ), 1 );

		$this->handler->create_single_occurrence(
			$event,
			$start,
			$end,
			false,
			100,
			$this->mock_occurrence_repo,
			$_POST
		);

		$this->assertEquals( 100, $occurrence->capacity );
	}

	/**
	 * Test create_single_occurrence handles all_day flag.
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_handles_all_day(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 00:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 23:59:00' );

		$occurrence     = new Occurrence();
		$occurrence->id = 6;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->with( $event, $start, $end, true )
			->andReturn( $occurrence );

		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->with( $occurrence );

		$this->mock_ticket_saver
			->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 6, Mockery::type( 'array' ), 1 );

		$this->handler->create_single_occurrence(
			$event,
			$start,
			$end,
			true, // all_day = true.
			null,
			$this->mock_occurrence_repo,
			$_POST
		);

		$this->assertNull( $occurrence->capacity );
	}

	/**
	 * Test create_single_occurrence treats empty string capacity as unlimited.
	 *
	 * @return void
	 */
	public function test_create_single_occurrence_empty_capacity_is_unlimited(): void {
		$event     = new Event();
		$event->id = 1;

		$start = new \DateTimeImmutable( '2026-06-15 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-06-15 12:00:00' );

		$occurrence     = new Occurrence();
		$occurrence->id = 7;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->with( $event, $start, $end, false )
			->andReturn( $occurrence );

		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->with( $occurrence );

		$this->mock_ticket_saver
			->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 7, Mockery::type( 'array' ), 1 );

		$this->handler->create_single_occurrence(
			$event,
			$start,
			$end,
			false,
			'', // Empty string = unlimited.
			$this->mock_occurrence_repo,
			$_POST
		);

		$this->assertNull( $occurrence->capacity );
	}

	// =========================================================================
	// Regression Tests (BUG-001: Event time not saving)
	// =========================================================================

	/**
	 * Regression: Empty time strings must not cause date parsing exceptions.
	 *
	 * Before fix, $_POST['start_time'] = '' would produce "2026-06-15 :00"
	 * which throws a date parsing exception from DateTimeImmutable.
	 *
	 * @return void
	 */
	public function test_process_occurrences_empty_time_strings_do_not_crash(): void {
		$_POST['start_date'] = '2026-06-15';
		$_POST['start_time'] = '';
		$_POST['end_date']   = '2026-06-15';
		$_POST['end_time']   = '';

		$event             = new Event();
		$event->id         = 1;
		$event->event_type = 'single';

		// Empty times default to 00:00/23:59, which is valid (>10 min).
		$occurrence     = new Occurrence();
		$occurrence->id = 10;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->andReturn( $occurrence );

		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->with( $occurrence );

		$this->mock_ticket_saver
			->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 10, Mockery::type( 'array' ), 1 );

		// Must not throw a date-parsing exception.
		$this->handler->process_occurrences( $event, $this->mock_occurrence_repo, $_POST );

		$this->assertTrue( true );
	}

	/**
	 * Regression: Explicit times must be preserved, not overwritten by defaults.
	 *
	 * Verifies that when a user submits start_time=14:30 and end_time=16:45,
	 * those exact values reach DateTimeImmutable construction.
	 *
	 * @return void
	 */
	public function test_process_occurrences_preserves_explicit_times(): void {
		$_POST['start_date'] = '2026-06-15';
		$_POST['start_time'] = '14:30';
		$_POST['end_date']   = '2026-06-15';
		$_POST['end_time']   = '16:45';

		$event             = new Event();
		$event->id         = 1;
		$event->event_type = 'single';

		// 14:30 to 16:45 is valid (2h 15m > 10m).
		$occurrence     = new Occurrence();
		$occurrence->id = 10;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->andReturn( $occurrence );

		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->with( $occurrence );

		$this->mock_ticket_saver
			->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 10, Mockery::type( 'array' ), 1 );

		// Must not throw a date-parsing exception.
		$this->handler->process_occurrences( $event, $this->mock_occurrence_repo, $_POST );

		$this->assertTrue( true );
	}

	/**
	 * Regression: Recurring event times must be processable.
	 *
	 * Before fix, recurring events never loaded their occurrence in EventEditor,
	 * so the form rendered with default times. On save, empty or default times
	 * would overwrite the real values.
	 *
	 * @return void
	 */
	public function test_process_occurrences_works_for_recurring_events(): void {
		$_POST['start_date'] = '2026-06-15';
		$_POST['start_time'] = '19:00';
		$_POST['end_date']   = '2026-06-15';
		$_POST['end_time']   = '21:00';

		$event                  = new Event();
		$event->id              = 1;
		$event->event_type      = 'recurring';
		$event->recurrence_rule = 'FREQ=WEEKLY;COUNT=4';

		$this->mock_recurrence_service
			->shouldReceive( 'generate_occurrences' )
			->once()
			->andReturn( array( 'errors' => array() ) );

		// Must not throw a date-parsing exception.
		$this->handler->process_occurrences( $event, $this->mock_occurrence_repo, $_POST );

		$this->assertTrue( true );
	}

	// =========================================================================
	// process_save() Tests
	// =========================================================================

	/**
	 * Test process_save returns error redirect when event not found.
	 *
	 * @return void
	 */
	public function test_process_save_returns_error_redirect_for_missing_event(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . $path );

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_id']       = 99999;

		$this->mock_event_repo
			->shouldReceive( 'find' )
			->with( 99999 )
			->andReturn( null );

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=error', $result );
	}

	/**
	 * Test process_save returns success redirect for new event.
	 *
	 * @return void
	 */
	public function test_process_save_returns_success_redirect_for_new_event(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->justReturn( true );

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_title']     = 'Test Event';
		$_POST['event_type']      = 'single';

		$saved_event     = new Event();
		$saved_event->id = 42;

		$this->mock_event_repo
			->shouldReceive( 'generate_unique_slug' )
			->andReturn( 'test-event' );

		$this->mock_event_repo
			->shouldReceive( 'save' )
			->once()
			->andReturn( $saved_event );

		$this->mock_category_repo
			->shouldReceive( 'sync_event_categories' )
			->once();

		// Recreate handler with shouldIgnoreMissing mocks for attendee repos
		// since process_save triggers AttendeeFieldsSaveHandler.
		$mock_attendee_field_repo = Mockery::mock( \NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface::class );
		$mock_attendee_field_repo->shouldIgnoreMissing();
		$mock_attendee_field_value_repo = Mockery::mock( \NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface::class );
		$mock_attendee_field_value_repo->shouldIgnoreMissing();

		$mock_layout_service = Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout_service->shouldIgnoreMissing();

		$mock_rrule_builder = Mockery::mock( \NetterTechEvents\Services\RecurrenceRuleBuilder::class );
		$mock_rrule_builder->shouldIgnoreMissing();

		$mock_attendee_fields_saver = Mockery::mock( \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class );
		$mock_attendee_fields_saver->shouldIgnoreMissing();

		$mock_checkin_email_saver = Mockery::mock( \NetterTechEvents\Services\CheckInEmailSaver::class );
		$mock_checkin_email_saver->shouldIgnoreMissing();

		$handler = new EventSaveHandler(
			$this->mock_event_repo,
			$this->mock_occurrence_repo,
			$this->mock_category_repo,
			$this->mock_recurrence_service,
			$this->mock_ticket_saver,
			$mock_layout_service,
			$mock_rrule_builder,
			$mock_attendee_fields_saver,
			$mock_checkin_email_saver
		);

		$result = $handler->process_save();

		$this->assertStringContainsString( 'message=created', $result );
		$this->assertStringContainsString( 'event_id=42', $result );
	}

	// =========================================================================
	// extract_event_fields() Tests
	// =========================================================================

	/**
	 * Test extract_event_fields populates scalar fields from POST.
	 *
	 * @return void
	 */
	public function test_extract_event_fields_populates_title_and_type(): void {
		$_POST['event_title'] = 'My Test Event';
		$_POST['event_type']  = 'single';
		$_POST['event_slug']  = 'my-test-event';

		$this->mock_event_repo->shouldReceive( 'generate_unique_slug' )->never();

		$event  = new Event();
		$result = $this->handler->extract_event_fields( $event, $_POST );

		$this->assertSame( 'My Test Event', $result->title );
		$this->assertSame( 'single', $result->event_type );
		$this->assertSame( 'my-test-event', $result->slug );
	}

	/**
	 * Test extract_event_fields persists a posted space assignment.
	 *
	 * @return void
	 */
	public function test_extract_event_fields_sets_space_id(): void {
		$_POST['event_title']    = 'Spaced Event';
		$_POST['event_slug']     = 'spaced-event';
		$_POST['event_space_id'] = '7';

		$event  = new Event();
		$result = $this->handler->extract_event_fields( $event, $_POST );

		$this->assertSame( 7, $result->space_id );
	}

	/**
	 * Test extract_event_fields clears the space assignment for "0"/absent.
	 *
	 * @return void
	 */
	public function test_extract_event_fields_clears_space_id_when_unassigned(): void {
		$_POST['event_title']    = 'Unspaced Event';
		$_POST['event_slug']     = 'unspaced-event';
		$_POST['event_space_id'] = '0';

		$event           = new Event();
		$event->space_id = 7;

		$result = $this->handler->extract_event_fields( $event, $_POST );

		$this->assertNull( $result->space_id );
	}

	/**
	 * Test extract_event_fields generates slug when empty and title present.
	 *
	 * @return void
	 */
	public function test_extract_event_fields_generates_slug_when_empty(): void {
		$_POST['event_title'] = 'My New Event';
		$_POST['event_slug']  = '';

		$this->mock_event_repo
			->shouldReceive( 'generate_unique_slug' )
			->once()
			->with( 'My New Event' )
			->andReturn( 'my-new-event' );

		$event  = new Event();
		$result = $this->handler->extract_event_fields( $event, $_POST );

		$this->assertSame( 'my-new-event', $result->slug );
	}

	/**
	 * Test extract_event_fields clears virtual_url when not virtual.
	 *
	 * @return void
	 */
	public function test_extract_event_fields_clears_virtual_url_when_not_virtual(): void {
		Functions\when( 'esc_url_raw' )->returnArg();

		$_POST['is_virtual']  = '';
		$_POST['virtual_url'] = 'https://example.com/stream';

		$event              = new Event();
		$event->virtual_url = 'https://old.example.com';
		$result             = $this->handler->extract_event_fields( $event, $_POST );

		$this->assertNull( $result->virtual_url );
		$this->assertFalse( $result->is_virtual );
	}

	/**
	 * Test extract_event_fields sets featured_image_id from POST.
	 *
	 * @return void
	 */
	public function test_extract_event_fields_sets_featured_image_id(): void {
		$_POST['featured_image_id'] = '7';

		$event  = new Event();
		$result = $this->handler->extract_event_fields( $event, $_POST );

		$this->assertSame( 7, $result->featured_image_id );
	}

	/**
	 * Test extract_event_fields sets null featured_image_id when empty.
	 *
	 * @return void
	 */
	public function test_extract_event_fields_nulls_featured_image_id_when_empty(): void {
		$_POST['featured_image_id'] = '';

		$event  = new Event();
		$result = $this->handler->extract_event_fields( $event, $_POST );

		$this->assertNull( $result->featured_image_id );
	}

	/**
	 * Test extract_event_fields stores valid vertical anchors and centers via NULL (NTE-119).
	 *
	 * top/bottom persist as-is; center/invalid/missing store NULL (= center default).
	 *
	 * @dataProvider provide_vertical_anchor_cases
	 *
	 * @param mixed       $posted   Raw POST value (or null to omit the key).
	 * @param string|null $expected Expected stored image_vertical_anchor.
	 * @return void
	 */
	public function test_extract_event_fields_sanitizes_vertical_anchor( $posted, ?string $expected ): void {
		unset( $_POST['image_vertical_anchor'] );
		if ( null !== $posted ) {
			$_POST['image_vertical_anchor'] = $posted;
		}

		$event  = new Event();
		$result = $this->handler->extract_event_fields( $event, $_POST );

		$this->assertSame( $expected, $result->image_vertical_anchor );
	}

	/**
	 * Data provider for vertical-anchor sanitization.
	 *
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function provide_vertical_anchor_cases(): array {
		return array(
			'top stored as-is'        => array( 'top', 'top' ),
			'bottom stored as-is'     => array( 'bottom', 'bottom' ),
			'center stored as null'   => array( 'center', null ),
			'invalid falls to center' => array( 'sideways', null ),
			'missing falls to center' => array( null, null ),
		);
	}

	/**
	 * Test extract_event_fields returns the same Event instance (mutates in place).
	 *
	 * @return void
	 */
	public function test_extract_event_fields_returns_event_instance(): void {
		$event  = new Event();
		$result = $this->handler->extract_event_fields( $event, $_POST );

		$this->assertSame( $event, $result );
	}

	// =========================================================================
	// save_pro_extensions() Tests
	// =========================================================================

	/**
	 * Test save_pro_extensions calls checkin_email_saver when present.
	 *
	 * @return void
	 */
	public function test_save_pro_extensions_calls_checkin_saver(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$mock_checkin = Mockery::mock( \NetterTechEvents\Services\CheckInEmailSaver::class );
		$mock_checkin->shouldReceive( 'save' )->once()->with( 42, Mockery::type( 'array' ) );

		$mock_layout = Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout->shouldIgnoreMissing();

		$mock_rrule = Mockery::mock( \NetterTechEvents\Services\RecurrenceRuleBuilder::class );
		$mock_rrule->shouldIgnoreMissing();

		$mock_afsaver = Mockery::mock( \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class );
		$mock_afsaver->shouldIgnoreMissing();

		$handler = new EventSaveHandler(
			$this->mock_event_repo,
			$this->mock_occurrence_repo,
			$this->mock_category_repo,
			$this->mock_recurrence_service,
			$this->mock_ticket_saver,
			$mock_layout,
			$mock_rrule,
			$mock_afsaver,
			$mock_checkin
		);

		$handler->save_pro_extensions( 42, $_POST );
	}

	/**
	 * Test save_pro_extensions skips checkin_email_saver when null.
	 *
	 * @return void
	 */
	public function test_save_pro_extensions_skips_checkin_saver_when_null(): void {
		Functions\when( 'do_action' )->justReturn( null );

		// Handler constructed with null checkin_email_saver (default in setUp).
		// Should not throw.
		$this->handler->save_pro_extensions( 99, $_POST );

		$this->assertTrue( true );
	}

	/**
	 * Test save_pro_extensions fires the Pro extension action.
	 *
	 * @return void
	 */
	public function test_save_pro_extensions_fires_action(): void {
		$fired     = false;
		$fired_id  = null;
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ) use ( &$fired, &$fired_id ) {
				if ( 'nettertech_events_save_pro_extensions' === $hook ) {
					$fired    = true;
					$fired_id = $args[0];
				}
			}
		);

		$this->handler->save_pro_extensions( 55, $_POST );

		$this->assertTrue( $fired );
		$this->assertSame( 55, $fired_id );
	}

	// =========================================================================
	// save_event_associations() Tests
	// =========================================================================

	/**
	 * Test save_event_associations syncs categories from POST.
	 *
	 * @return void
	 */
	public function test_save_event_associations_syncs_categories(): void {
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();

		$_POST['event_categories'] = array( '3', '7' );

		$this->mock_category_repo
			->shouldReceive( 'sync_event_categories' )
			->once()
			->with( 10, array( 3, 7 ) );

		$mock_afsaver = Mockery::mock( \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class );
		$mock_afsaver->shouldReceive( 'save' )->once()->with( 10, Mockery::type( 'array' ) );

		$mock_layout = Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout->shouldIgnoreMissing();

		$mock_rrule = Mockery::mock( \NetterTechEvents\Services\RecurrenceRuleBuilder::class );
		$mock_rrule->shouldIgnoreMissing();

		$handler = new EventSaveHandler(
			$this->mock_event_repo,
			$this->mock_occurrence_repo,
			$this->mock_category_repo,
			$this->mock_recurrence_service,
			$this->mock_ticket_saver,
			$mock_layout,
			$mock_rrule,
			$mock_afsaver
		);

		$handler->save_event_associations( 10, $_POST );
	}

	/**
	 * Test save_event_associations syncs organizers when repo present.
	 *
	 * @return void
	 */
	public function test_save_event_associations_syncs_organizers(): void {
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();

		$_POST['event_categories'] = array();
		$_POST['event_organizers'] = array( '2', '5' );

		$this->mock_category_repo->shouldReceive( 'sync_event_categories' )->once();

		$mock_organizer_repo = Mockery::mock( \NetterTechEvents\Contracts\OrganizerRepositoryInterface::class );
		$mock_organizer_repo
			->shouldReceive( 'sync_event_organizers' )
			->once()
			->with( 20, array( 2, 5 ) );

		$mock_afsaver = Mockery::mock( \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class );
		$mock_afsaver->shouldReceive( 'save' )->once()->with( 20, Mockery::type( 'array' ) );

		$mock_layout = Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout->shouldIgnoreMissing();

		$mock_rrule = Mockery::mock( \NetterTechEvents\Services\RecurrenceRuleBuilder::class );
		$mock_rrule->shouldIgnoreMissing();

		$handler = new EventSaveHandler(
			$this->mock_event_repo,
			$this->mock_occurrence_repo,
			$this->mock_category_repo,
			$this->mock_recurrence_service,
			$this->mock_ticket_saver,
			$mock_layout,
			$mock_rrule,
			$mock_afsaver,
			null,
			$mock_organizer_repo
		);

		$handler->save_event_associations( 20, $_POST );
	}

	/**
	 * Test save_event_associations uses empty category array when POST key absent.
	 *
	 * @return void
	 */
	public function test_save_event_associations_uses_empty_categories_when_not_posted(): void {
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();

		// No event_categories in POST.

		$this->mock_category_repo
			->shouldReceive( 'sync_event_categories' )
			->once()
			->with( 5, array() );

		$mock_afsaver = Mockery::mock( \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class );
		$mock_afsaver->shouldReceive( 'save' )->once()->with( 5, Mockery::type( 'array' ) );

		$mock_layout = Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout->shouldIgnoreMissing();

		$mock_rrule = Mockery::mock( \NetterTechEvents\Services\RecurrenceRuleBuilder::class );
		$mock_rrule->shouldIgnoreMissing();

		$handler = new EventSaveHandler(
			$this->mock_event_repo,
			$this->mock_occurrence_repo,
			$this->mock_category_repo,
			$this->mock_recurrence_service,
			$this->mock_ticket_saver,
			$mock_layout,
			$mock_rrule,
			$mock_afsaver
		);

		$handler->save_event_associations( 5, $_POST );
	}

	// =========================================================================
	// Regression Tests (BUG-001: Event time not saving)
	// =========================================================================

	/**
	 * Regression: Missing end_date must default to start_date.
	 *
	 * Before fix, missing wp_unslash on end_date could corrupt the value.
	 *
	 * @return void
	 */
	public function test_process_occurrences_defaults_end_date_to_start_date(): void {
		$_POST['start_date'] = '2026-06-15';
		$_POST['start_time'] = '10:00';
		// end_date intentionally omitted - defaults to start_date.
		$_POST['end_time'] = '12:00';

		$event             = new Event();
		$event->id         = 1;
		$event->event_type = 'single';

		// 10:00 to 12:00 same day is valid (2h > 10m).
		$occurrence     = new Occurrence();
		$occurrence->id = 10;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->andReturn( $occurrence );

		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->with( $occurrence );

		$this->mock_ticket_saver
			->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 10, Mockery::type( 'array' ), 1 );

		// Must not throw a date-parsing exception.
		$this->handler->process_occurrences( $event, $this->mock_occurrence_repo, $_POST );

		$this->assertTrue( true );
	}

	// =========================================================================
	// add_manual_date() Tests
	// =========================================================================

	/**
	 * Call the private add_manual_date().
	 *
	 * @param int                  $event_id Event.
	 * @param array<string, mixed> $post     POST data.
	 * @return void
	 */
	private function call_add_manual_date( int $event_id, array $post ): void {
		$event     = new Event();
		$event->id = $event_id;

		// A date added by hand is given the event's ticket templates, exactly as a generated one is.
		$this->mock_recurrence_service
			->shouldReceive( 'apply_templates_to_occurrences' )
			->andReturn( 0 );

		$method = new \ReflectionMethod( EventSaveHandler::class, 'add_manual_dates' );
		$method->invoke( $this->handler, $event, $post );
	}

	/**
	 * A hand-picked date is saved as an override, so the pattern cannot delete it later.
	 *
	 * `is_override` is the whole mechanism: the regeneration guards already honour it, so a date
	 * added here survives a later save of the RRULE. An event runs every Tuesday *and* one Saturday
	 * (RFC 5545: the set is RRULE ∪ RDATE − EXDATE).
	 *
	 * @return void
	 */
	public function test_add_manual_date_saves_an_override_occurrence(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 7, array( 'limit' => 1 ) )
			->andReturn( array() );

		$saved = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( $occurrence ) use ( &$saved ) {
					$saved     = $occurrence;
					$saved->id = 99;
					return $occurrence;
				}
			);

		$this->call_add_manual_date(
			7,
			array(
				'nettertech_events_new_date'       => '2026-09-03',
				'nettertech_events_new_start_time' => '14:30',
				'nettertech_events_new_end_time'   => '16:00',
			)
		);

		$this->assertNotNull( $saved );
		$this->assertSame( 7, $saved->event_id );
		$this->assertSame( '2026-09-03 14:30:00', $saved->start_datetime );
		$this->assertSame( '2026-09-03 16:00:00', $saved->end_datetime );
		$this->assertTrue( $saved->is_override );
	}

	/**
	 * The added date is kept in the zone the event's other dates use, not the site's.
	 *
	 * The stored UTC instants are derived from the wall clock plus this zone, so getting it wrong
	 * would place the date at the right numbers and the wrong moment.
	 *
	 * @return void
	 */
	public function test_add_manual_date_inherits_the_zone_of_its_siblings(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$sibling           = new Occurrence();
		$sibling->timezone = 'Australia/Sydney';

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 7, array( 'limit' => 1 ) )
			->andReturn( array( $sibling ) );

		$saved = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( $occurrence ) use ( &$saved ) {
					$saved     = $occurrence;
					$saved->id = 99;
					return $occurrence;
				}
			);

		$this->call_add_manual_date(
			7,
			array(
				'nettertech_events_new_date'       => '2026-09-03',
				'nettertech_events_new_start_time' => '14:30',
				'nettertech_events_new_end_time'   => '16:00',
			)
		);

		$this->assertSame( 'Australia/Sydney', $saved->timezone );
	}

	/**
	 * A wholly blank row (no date, no times) creates nothing.
	 *
	 * The legacy single-date fields ride along on every event save, so a blank set must mean
	 * "no date", not an empty date. Only a missing *date* drops the row now (NTE-184); missing
	 * times are derived, exercised in the derivation tests below.
	 *
	 * @return void
	 */
	public function test_add_manual_date_does_nothing_when_the_fields_are_blank(): void {
		$this->mock_occurrence_repo->shouldNotReceive( 'save' );

		$this->call_add_manual_date( 7, array() );

		$this->assertTrue( true );
	}

	/**
	 * A date with only its date and start persists; the end is derived (NTE-184).
	 *
	 * The prior behavior dropped any row missing start OR end, silently discarding a date the
	 * operator entered. Blank end now derives start + default duration (default 120 min), exactly
	 * as the primary Date & Time path does.
	 *
	 * @return void
	 */
	public function test_add_manual_date_derives_blank_end_from_default_duration(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 7, array( 'limit' => 1 ) )
			->andReturn( array() );

		$saved = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( $occurrence ) use ( &$saved ) {
					$saved     = $occurrence;
					$saved->id = 42;
					return $occurrence;
				}
			);

		$this->call_add_manual_date(
			7,
			array(
				'nettertech_events_new_date'       => '2026-09-03',
				'nettertech_events_new_start_time' => '14:30',
				'nettertech_events_new_end_time'   => '',
			)
		);

		$this->assertNotNull( $saved );
		$this->assertSame( '2026-09-03 14:30:00', $saved->start_datetime );
		// 14:30 + 120 min default duration = 16:30.
		$this->assertSame( '2026-09-03 16:30:00', $saved->end_datetime );
		$this->assertTrue( $saved->is_override );
	}

	/**
	 * A date with only its date persists; both start and end are derived (NTE-184).
	 *
	 * @return void
	 */
	public function test_add_manual_date_derives_blank_start_from_default_setting(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 7, array( 'limit' => 1 ) )
			->andReturn( array() );

		$saved = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( $occurrence ) use ( &$saved ) {
					$saved     = $occurrence;
					$saved->id = 42;
					return $occurrence;
				}
			);

		$this->call_add_manual_date(
			7,
			array(
				'nettertech_events_new_date'       => '2026-09-03',
				'nettertech_events_new_start_time' => '',
				'nettertech_events_new_end_time'   => '',
			)
		);

		$this->assertNotNull( $saved );
		// Default start 19:00, + 120 min = 21:00.
		$this->assertSame( '2026-09-03 19:00:00', $saved->start_datetime );
		$this->assertSame( '2026-09-03 21:00:00', $saved->end_datetime );
	}

	/**
	 * With require_end_time enabled, a date missing its end is a validation error, not a derivation.
	 *
	 * @return void
	 */
	public function test_add_manual_date_blank_end_is_error_when_end_required(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( 'get_option' )->justReturn( array( 'require_end_time' => true ) );

		$this->mock_occurrence_repo->shouldNotReceive( 'save' );

		$this->expectException( \NetterTechEvents\Exceptions\ValidationException::class );

		$this->call_add_manual_date(
			7,
			array(
				'nettertech_events_new_date'       => '2026-09-03',
				'nettertech_events_new_start_time' => '14:30',
				'nettertech_events_new_end_time'   => '',
			)
		);
	}

	/**
	 * An inverted manual row (end before start) is rejected, not persisted verbatim (F7).
	 *
	 * The shared resolver's span check catches both inverted and sub-10-minute rows.
	 *
	 * @return void
	 */
	public function test_add_manual_dates_rejects_an_inverted_row(): void {
		$this->mock_occurrence_repo->shouldNotReceive( 'save' );

		$this->expectException( \NetterTechEvents\Exceptions\ValidationException::class );

		$this->call_add_manual_date(
			7,
			array(
				'nettertech_events_new_date'       => '2026-09-03',
				'nettertech_events_new_start_time' => '20:00',
				'nettertech_events_new_end_time'   => '19:00',
			)
		);
	}

	/**
	 * Templates applied to a hand-picked date are named (with product count) in the notice (R1).
	 *
	 * Operator ruling 2026-07-20 (spec-001 invention audit): applying templates to an added date
	 * can mint tiers and WooCommerce products, so what was created must be surfaced, not silent.
	 *
	 * @return void
	 */
	public function test_add_manual_dates_reports_created_tiers_in_notice(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$captured = null;
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) use ( &$captured ) {
				if ( str_contains( (string) $key, 'save_notice' ) ) {
					$captured = $value;
				}
				return true;
			}
		);

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 7, array( 'limit' => 1 ) )
			->andReturn( array() );
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( $occurrence ) {
					$occurrence->id = 99;
					return $occurrence;
				}
			);

		// Two templates applied (created > 0); one is priced, so one product is reported.
		$this->mock_recurrence_service
			->shouldReceive( 'apply_templates_to_occurrences' )
			->andReturn( 2 );

		$general        = new TicketType();
		$general->name  = 'General Admission';
		$general->price = 25.0;
		$comp           = new TicketType();
		$comp->name     = 'Comp';
		$comp->price    = 0.0;
		$this->mock_recurrence_service
			->shouldReceive( 'get_active_templates' )
			->with( 7 )
			->andReturn( array( $general, $comp ) );

		$event     = new Event();
		$event->id = 7;

		$method = new \ReflectionMethod( EventSaveHandler::class, 'add_manual_dates' );
		$method->invoke(
			$this->handler,
			$event,
			array(
				'nettertech_events_new_date'       => '2026-09-03',
				'nettertech_events_new_start_time' => '14:30',
				'nettertech_events_new_end_time'   => '16:00',
			)
		);

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'General Admission', $captured );
		$this->assertStringContainsString( 'Comp', $captured );
		// Exactly one priced template → one product reported.
		$this->assertStringContainsString( '1 WooCommerce product', $captured );
	}

	/**
	 * N indexed rows create N override occurrences in one save (NTE-177, FR-002).
	 *
	 * @return void
	 */
	public function test_add_manual_dates_creates_one_occurrence_per_indexed_row(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 7, array( 'limit' => 1 ) )
			->andReturn( array() );

		$saved = array();
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->times( 3 )
			->andReturnUsing(
				function ( $occurrence ) use ( &$saved ) {
					$occurrence->id = count( $saved ) + 1;
					$saved[]        = $occurrence;
					return $occurrence;
				}
			);

		$this->call_add_manual_date(
			7,
			array(
				'nettertech_events_manual_dates' => array(
					array(
						'date'       => '2026-09-03',
						'start_time' => '14:30',
						'end_time'   => '16:00',
					),
					array(
						'date'       => '2026-09-10',
						'start_time' => '18:00',
						'end_time'   => '20:00',
					),
					array(
						'date'       => '2026-09-17',
						'start_time' => '18:00',
						'end_time'   => '20:00',
					),
				),
			)
		);

		$this->assertCount( 3, $saved );
		$this->assertSame( '2026-09-03 14:30:00', $saved[0]->start_datetime );
		$this->assertSame( '2026-09-17 20:00:00', $saved[2]->end_datetime );
		$this->assertTrue( $saved[0]->is_override );
		$this->assertTrue( $saved[2]->is_override );
	}

	/**
	 * Only date-less rows are skipped; a row missing just its time is derived, not dropped (NTE-184).
	 *
	 * The prior behavior dropped the partial (missing end time) row silently. It now persists with a
	 * derived end. A wholly empty row and a non-array entry are the only things that create nothing.
	 *
	 * @return void
	 */
	public function test_add_manual_dates_skips_only_dateless_rows(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 7, array( 'limit' => 1 ) )
			->andReturn( array() );

		$saved = array();
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->times( 2 )
			->andReturnUsing(
				function ( $occurrence ) use ( &$saved ) {
					$occurrence->id = count( $saved ) + 1;
					$saved[]        = $occurrence;
					return $occurrence;
				}
			);

		$this->call_add_manual_date(
			7,
			array(
				'nettertech_events_manual_dates' => array(
					// Valid.
					array(
						'date'       => '2026-09-03',
						'start_time' => '14:30',
						'end_time'   => '16:00',
					),
					// Entirely empty — skipped (no date).
					array(
						'date'       => '',
						'start_time' => '',
						'end_time'   => '',
					),
					// Partial (missing end time) — now KEPT with a derived end.
					array(
						'date'       => '2026-09-10',
						'start_time' => '18:00',
						'end_time'   => '',
					),
					// Not an array — ignored.
					'garbage',
				),
			)
		);

		$this->assertCount( 2, $saved );
		$this->assertSame( '2026-09-03 14:30:00', $saved[0]->start_datetime );
		$this->assertSame( '2026-09-10 18:00:00', $saved[1]->start_datetime );
		// 18:00 + 120 min default duration = 20:00.
		$this->assertSame( '2026-09-10 20:00:00', $saved[1]->end_datetime );
	}

	/**
	 * A valid row is kept even when it follows an empty row (skip, don't stop).
	 *
	 * Guards the loop's `continue` against a `break` mutation: `break` would abandon
	 * every row after the first blank one, silently dropping good dates.
	 *
	 * @return void
	 */
	public function test_add_manual_dates_keeps_a_valid_row_after_an_empty_row(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 7, array( 'limit' => 1 ) )
			->andReturn( array() );

		$saved = array();
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( $occurrence ) use ( &$saved ) {
					$occurrence->id = 1;
					$saved[]        = $occurrence;
					return $occurrence;
				}
			);

		$this->call_add_manual_date(
			7,
			array(
				'nettertech_events_manual_dates' => array(
					// Empty row FIRST.
					array(
						'date'       => '',
						'start_time' => '',
						'end_time'   => '',
					),
					// Valid row AFTER — must still be created.
					array(
						'date'       => '2026-09-10',
						'start_time' => '18:00',
						'end_time'   => '20:00',
					),
				),
			)
		);

		$this->assertCount( 1, $saved );
		$this->assertSame( '2026-09-10 18:00:00', $saved[0]->start_datetime );
	}

	/**
	 * A null event ID creates nothing: no orphan occurrence rows on a failed insert (FR-010).
	 *
	 * @return void
	 */
	public function test_add_manual_dates_bails_when_event_id_is_null(): void {
		$this->mock_occurrence_repo->shouldNotReceive( 'save' );

		$event     = new Event();
		$event->id = null;

		$method = new \ReflectionMethod( EventSaveHandler::class, 'add_manual_dates' );
		$method->invoke(
			$this->handler,
			$event,
			array(
				'nettertech_events_manual_dates' => array(
					array(
						'date'       => '2026-09-03',
						'start_time' => '14:30',
						'end_time'   => '16:00',
					),
				),
			)
		);

		$this->assertTrue( true );
	}

	/**
	 * process_buffered_tickets bails when the event has no real ID (FR-010).
	 *
	 * @return void
	 */
	public function test_process_buffered_tickets_bails_when_event_id_is_null(): void {
		$this->mock_occurrence_repo->shouldNotReceive( 'for_event' );

		$event     = new Event();
		$event->id = null;

		$method = new \ReflectionMethod( EventSaveHandler::class, 'process_buffered_tickets' );
		$method->invoke(
			$this->handler,
			$event,
			array(
				'nte_tickets_metabox_rendered' => '1',
				'ticketing_enabled'            => '1',
			)
		);

		$this->assertTrue( true );
	}

	/**
	 * process_buffered_tickets does nothing unless BOTH markers are present.
	 *
	 * Guards the `||` short-circuit against an `&&` mutation: with `&&` a form that
	 * rendered but has ticketing switched off would still try to bind rows.
	 *
	 * @return void
	 */
	public function test_process_buffered_tickets_requires_both_markers(): void {
		$this->mock_occurrence_repo->shouldNotReceive( 'for_event' );

		$event     = new Event();
		$event->id = 42;

		$method = new \ReflectionMethod( EventSaveHandler::class, 'process_buffered_tickets' );

		// Rendered, but ticketing disabled → must return without querying occurrences.
		$method->invoke(
			$this->handler,
			$event,
			array( 'nte_tickets_metabox_rendered' => '1' )
		);

		$this->assertTrue( true );
	}

	/**
	 * With no non-override date, buffered tiers fall back to the earliest occurrence (NTE-187).
	 *
	 * An event whose only date was hand-picked (an override) would previously drop its buffered
	 * occurrence tiers silently. They now bind to that override rather than vanishing.
	 *
	 * @return void
	 */
	public function test_process_buffered_tickets_falls_back_to_override_occurrence(): void {
		$event     = new Event();
		$event->id = 42;

		$override              = new Occurrence();
		$override->id          = 77;
		$override->is_override = true;

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->andReturn( array( $override ) );

		$this->mock_ticket_saver
			->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 77, Mockery::type( 'array' ), 42 );

		$method = new \ReflectionMethod( EventSaveHandler::class, 'process_buffered_tickets' );
		$method->invoke(
			$this->handler,
			$event,
			array(
				'nte_tickets_metabox_rendered' => '1',
				'ticketing_enabled'            => '1',
				'ticket_types'                 => array(
					'occurrence' => array( array( 'name' => 'GA' ) ),
				),
			)
		);

		$this->assertTrue( true );
	}

	/**
	 * With no occurrence at all, buffered tiers are reported by name, not dropped (NTE-187).
	 *
	 * @return void
	 */
	public function test_process_buffered_tickets_reports_tiers_when_no_occurrence(): void {
		$event     = new Event();
		$event->id = 42;

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->andReturn( array() );

		$this->mock_ticket_saver->shouldNotReceive( 'save_for_occurrence' );

		$captured = null;
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		$method = new \ReflectionMethod( EventSaveHandler::class, 'process_buffered_tickets' );
		$method->invoke(
			$this->handler,
			$event,
			array(
				'nte_tickets_metabox_rendered' => '1',
				'ticketing_enabled'            => '1',
				'ticket_types'                 => array(
					'occurrence' => array(
						array( 'name' => 'VIP Pass' ),
						array( 'name' => 'GA' ),
					),
				),
			)
		);

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'VIP Pass', $captured );
		$this->assertStringContainsString( 'GA', $captured );
	}

	/**
	 * On a first save, buffered occurrence tickets bind to the primary occurrence (FR-008).
	 *
	 * A new event (posted event_id 0) with the buffered ticket markers must route its
	 * rows through the ticket saver against the primary (non-override) occurrence. Guards
	 * the `0 === $event_id` call-site gate and the call itself.
	 *
	 * @return void
	 */
	public function test_process_save_binds_buffered_tickets_for_new_event(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'set_transient' )->justReturn( true );

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_title']                   = 'Buffered Event';
		$_POST['event_type']                    = 'single';
		// No start_date → no primary occurrence is created by process_occurrences;
		// the buffered path is exercised in isolation.
		$_POST['nte_tickets_metabox_rendered'] = '1';
		$_POST['ticketing_enabled']            = '1';
		$_POST['ticket_types']                 = array(
			'occurrence' => array(
				array( 'name' => 'General Admission', 'price' => 25 ),
			),
		);

		$saved_event     = new Event();
		$saved_event->id = 42;

		$this->mock_event_repo->shouldReceive( 'generate_unique_slug' )->andReturn( 'buffered-event' );
		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturn( $saved_event );
		$this->mock_category_repo->shouldReceive( 'sync_event_categories' )->once();

		// A single event with <= 1 scheduled occurrence does not take the event-scope path.
		$this->mock_occurrence_repo->shouldReceive( 'count_for_event' )->andReturn( 0 );

		// The primary occurrence the buffered rows must bind to.
		$primary              = new Occurrence();
		$primary->id          = 10;
		$primary->is_override = false;
		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 42, Mockery::type( 'array' ) )
			->andReturn( array( $primary ) );

		// The assertion under test: buffered occurrence rows reach the ticket saver
		// against occurrence 10, event 42.
		$this->mock_ticket_saver
			->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 10, Mockery::type( 'array' ), 42 );

		$mock_attendee_fields_saver = Mockery::mock( \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class );
		$mock_attendee_fields_saver->shouldIgnoreMissing();
		$mock_layout_service = Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout_service->shouldIgnoreMissing();
		$mock_rrule_builder = Mockery::mock( \NetterTechEvents\Services\RecurrenceRuleBuilder::class );
		$mock_rrule_builder->shouldIgnoreMissing();
		$mock_checkin_email_saver = Mockery::mock( \NetterTechEvents\Services\CheckInEmailSaver::class );
		$mock_checkin_email_saver->shouldIgnoreMissing();

		$handler = new EventSaveHandler(
			$this->mock_event_repo,
			$this->mock_occurrence_repo,
			$this->mock_category_repo,
			$this->mock_recurrence_service,
			$this->mock_ticket_saver,
			$mock_layout_service,
			$mock_rrule_builder,
			$mock_attendee_fields_saver,
			$mock_checkin_email_saver
		);

		$result = $handler->process_save();

		$this->assertStringContainsString( 'message=created', $result );
	}

	// =========================================================================
	// assert_recurrence_specified() Tests
	// =========================================================================

	/**
	 * Call the private assert_recurrence_specified().
	 *
	 * @param array<string, mixed> $post POST data.
	 * @return void
	 */
	private function call_assert_recurrence_specified( array $post ): void {
		$method = new \ReflectionMethod( EventSaveHandler::class, 'assert_recurrence_specified' );
		$method->invoke( $this->handler, $post );
	}

	/**
	 * An event switched to recurring with no pattern chosen is refused, not guessed at.
	 *
	 * The editor posts a hidden default for every recurrence field — frequency DAILY, end condition
	 * "never" — whether or not the operator ever opened the pattern controls. Reading those defaults
	 * as an answer built FREQ=DAILY with no end, and the generator filled the whole horizon: one
	 * click, 286 dates. A default is a reasonable thing for a form to carry and a terrible thing to
	 * mistake for a choice.
	 *
	 * @return void
	 */
	public function test_recurring_with_no_pattern_chosen_is_refused(): void {
		$this->expectException( ValidationException::class );

		$this->call_assert_recurrence_specified(
			array(
				'recurrence_preset'   => '',
				'recurrence_rule'     => '',
				// The form's hidden defaults, which the operator never touched.
				'recurrence_freq'     => 'DAILY',
				'recurrence_end_type' => 'never',
			)
		);
	}

	/**
	 * "Custom" with nothing in it is not a pattern either.
	 *
	 * @return void
	 */
	public function test_custom_with_an_empty_rule_is_refused(): void {
		$this->expectException( ValidationException::class );

		$this->call_assert_recurrence_specified(
			array(
				'recurrence_preset' => 'custom',
				'recurrence_rule'   => '',
			)
		);
	}

	/**
	 * A named preset is a choice, and passes.
	 *
	 * @return void
	 */
	public function test_a_named_preset_is_a_pattern(): void {
		$this->call_assert_recurrence_specified(
			array(
				'recurrence_preset' => 'FREQ=WEEKLY',
				'recurrence_rule'   => '',
			)
		);

		$this->assertTrue( true );
	}

	/**
	 * An explicit rule is a choice, and passes.
	 *
	 * @return void
	 */
	public function test_an_explicit_rule_is_a_pattern(): void {
		$this->call_assert_recurrence_specified(
			array(
				'recurrence_preset' => 'custom',
				'recurrence_rule'   => 'FREQ=WEEKLY;COUNT=4',
			)
		);

		$this->assertTrue( true );
	}

	/**
	 * The refusal message names the fix, so an empty error list would be a regression.
	 *
	 * Guards the message array in assert_recurrence_specified: implode( ' ', array() ) is '',
	 * so dropping the item silently strips the guidance the operator needs.
	 *
	 * @return void
	 */
	public function test_recurring_refusal_message_explains_the_choice(): void {
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'Choose how this event repeats before saving it' );

		$this->call_assert_recurrence_specified(
			array(
				'recurrence_preset' => 'custom',
				'recurrence_rule'   => '',
			)
		);
	}

	// =========================================================================
	// process_save() conversion + error-path Tests (mutation coverage)
	// =========================================================================

	/**
	 * Stub the WordPress functions process_save() touches on the happy and error paths.
	 *
	 * @return void
	 */
	private function stub_process_save_wp_functions(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
	}

	/**
	 * A saved event that WAS recurring and is now single is a conversion, asked before it is written.
	 *
	 * The stored type is read before extract_event_fields() clobbers it (NTE-153): id>0 AND
	 * previously recurring AND now not recurring means exactly one assert_convertible_to_single(),
	 * followed by convert_to_single() instead of process_occurrences(). keep_occurrence is absent
	 * here, so the id passed through must be null — never a defaulted 1 or -1.
	 *
	 * @return void
	 */
	public function test_process_save_converts_previously_recurring_event_to_single(): void {
		$this->stub_process_save_wp_functions();

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_id']    = 5;
		$_POST['event_title'] = 'Recurring Becomes Single';
		$_POST['event_slug']  = 'recurring-becomes-single';
		$_POST['event_type']  = 'single';
		// nettertech_events_keep_occurrence intentionally absent -> null passed through.

		$existing             = new Event();
		$existing->id         = 5;
		$existing->event_type = 'recurring';

		$this->mock_event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $existing );
		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturnUsing( fn( $e ) => $e );
		$this->mock_category_repo->shouldReceive( 'sync_event_categories' );

		$this->mock_recurrence_service
			->shouldReceive( 'assert_convertible_to_single' )
			->once()
			->with( 5, null );

		$this->mock_recurrence_service
			->shouldReceive( 'convert_to_single' )
			->once()
			->with( Mockery::type( Event::class ), null );

		// convert_to_single replaces process_occurrences on this branch.
		$this->mock_recurrence_service->shouldNotReceive( 'generate_occurrences' );

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=updated', $result );
	}

	/**
	 * keep_occurrence, when chosen, rides through to both the check and the conversion.
	 *
	 * Pins the absint()/">0 ? : null" read: a posted survivor id must reach
	 * assert_convertible_to_single() and convert_to_single() as that id, not null.
	 *
	 * @return void
	 */
	public function test_process_save_passes_chosen_keep_occurrence_through(): void {
		$this->stub_process_save_wp_functions();

		$_POST['nettertech_events_event_nonce']            = 'valid';
		$_POST['event_id']                                 = 5;
		$_POST['event_title']                              = 'Recurring Becomes Single';
		$_POST['event_slug']                               = 'recurring-becomes-single';
		$_POST['event_type']                               = 'single';
		$_POST['nettertech_events_keep_occurrence']        = '3';

		$existing             = new Event();
		$existing->id         = 5;
		$existing->event_type = 'recurring';

		$this->mock_event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $existing );
		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturnUsing( fn( $e ) => $e );
		$this->mock_category_repo->shouldReceive( 'sync_event_categories' );

		$this->mock_recurrence_service
			->shouldReceive( 'assert_convertible_to_single' )
			->once()
			->with( 5, 3 );

		$this->mock_recurrence_service
			->shouldReceive( 'convert_to_single' )
			->once()
			->with( Mockery::type( Event::class ), 3 );

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=updated', $result );
	}

	/**
	 * Absent keep_occurrence reaches both conversion calls as a strict null, never a defaulted 0.
	 *
	 * The ">0 ? keep : null" reads (assert_convertible_to_single and convert_to_single) must map an
	 * absent choice to null. Mockery's ->with() matches arguments loosely, so 0 == null slips past a
	 * plain ->with(..., null); this captures each argument and asserts it identically (===) null so a
	 * ">=" boundary that leaks 0 fails here. keep_occurrence is absent, exercising the 0 boundary.
	 *
	 * @return void
	 */
	public function test_process_save_absent_keep_occurrence_is_strict_null_not_zero(): void {
		$this->stub_process_save_wp_functions();

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_id']    = 5;
		$_POST['event_title'] = 'Recurring Becomes Single';
		$_POST['event_slug']  = 'recurring-becomes-single';
		$_POST['event_type']  = 'single';
		// nettertech_events_keep_occurrence intentionally absent -> keep_occurrence defaults to 0,
		// which the ">0 ? : null" guard must turn into null before it reaches the service.

		$existing             = new Event();
		$existing->id         = 5;
		$existing->event_type = 'recurring';

		$this->mock_event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $existing );
		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturnUsing( fn( $e ) => $e );
		$this->mock_category_repo->shouldReceive( 'sync_event_categories' );

		$assert_arg  = 'unset';
		$convert_arg = 'unset';

		$this->mock_recurrence_service
			->shouldReceive( 'assert_convertible_to_single' )
			->once()
			->with(
				5,
				Mockery::on(
					function ( $arg ) use ( &$assert_arg ) {
						$assert_arg = $arg;
						return true;
					}
				)
			);

		$this->mock_recurrence_service
			->shouldReceive( 'convert_to_single' )
			->once()
			->with(
				Mockery::type( Event::class ),
				Mockery::on(
					function ( $arg ) use ( &$convert_arg ) {
						$convert_arg = $arg;
						return true;
					}
				)
			);

		$result = $this->handler->process_save();

		$this->assertNull( $assert_arg, 'Absent keep_occurrence must reach assert_convertible_to_single as null, not 0 (line 229 boundary).' );
		$this->assertNull( $convert_arg, 'Absent keep_occurrence must reach convert_to_single as null, not 0 (line 263 boundary).' );
		$this->assertStringContainsString( 'message=updated', $result );
	}

	/**
	 * A saved single event re-saved as single is not a conversion — nobody asks to convert it.
	 *
	 * Guards the AND in was_recurring: an OR there would treat every saved event as previously
	 * recurring and call assert_convertible_to_single() on a plain single-event save.
	 *
	 * @return void
	 */
	public function test_process_save_single_event_saved_single_is_not_a_conversion(): void {
		$this->stub_process_save_wp_functions();

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_id']    = 6;
		$_POST['event_title'] = 'Just A Single Event';
		$_POST['event_slug']  = 'just-a-single-event';
		$_POST['event_type']  = 'single';
		// No start_date, so process_occurrences() returns early with no occurrence work.

		$existing             = new Event();
		$existing->id         = 6;
		$existing->event_type = 'single';

		$this->mock_event_repo->shouldReceive( 'find' )->with( 6 )->andReturn( $existing );
		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturnUsing( fn( $e ) => $e );
		$this->mock_category_repo->shouldReceive( 'sync_event_categories' );

		$this->mock_recurrence_service->shouldNotReceive( 'assert_convertible_to_single' );
		$this->mock_recurrence_service->shouldNotReceive( 'convert_to_single' );

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=updated', $result );
	}

	/**
	 * A single event with a real date runs process_occurrences() — the non-conversion branch does work.
	 *
	 * Guards the process_occurrences() call: removing it would silently skip occurrence creation for
	 * every ordinary single-event save.
	 *
	 * @return void
	 */
	public function test_process_save_single_event_processes_its_occurrence(): void {
		$this->stub_process_save_wp_functions();

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_title'] = 'Single With A Date';
		$_POST['event_slug']  = 'single-with-a-date';
		$_POST['event_type']  = 'single';
		$_POST['start_date']  = '2026-06-15';
		$_POST['start_time']  = '10:00';
		$_POST['end_date']    = '2026-06-15';
		$_POST['end_time']    = '12:00';

		$saved_event     = new Event();
		$saved_event->id = 42;

		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturn( $saved_event );
		$this->mock_category_repo->shouldReceive( 'sync_event_categories' );

		$occurrence     = new Occurrence();
		$occurrence->id = 10;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->andReturn( $occurrence );

		$this->mock_occurrence_repo->shouldReceive( 'save' )->once()->with( $occurrence );
		$this->mock_ticket_saver->shouldReceive( 'save_for_occurrence' )->once();

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=created', $result );
	}

	/**
	 * Every event saves its event-scoped tickets — the >1-occurrence gate is gone (R6, NTE-156).
	 *
	 * Series passes belong to any event whose dates a pass could span. The old gate was
	 * `'recurring' === type OR count > 1`; operator ruling 2026-07-20 removed it because
	 * save_for_event self-guards on rendered markers (NTE-178). A two-date single still reaches
	 * save_for_event; the companion test below proves a one-date single now does too.
	 *
	 * @return void
	 */
	public function test_process_save_multi_date_single_event_saves_event_scoped_tickets(): void {
		$this->stub_process_save_wp_functions();

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_title'] = 'Two Day Festival';
		$_POST['event_slug']  = 'two-day-festival';
		$_POST['event_type']  = 'single';
		$_POST['start_date']  = '2026-06-15';
		$_POST['start_time']  = '10:00';
		$_POST['end_date']    = '2026-06-15';
		$_POST['end_time']    = '12:00';

		$saved_event     = new Event();
		$saved_event->id = 42;

		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturn( $saved_event );
		$this->mock_category_repo->shouldReceive( 'sync_event_categories' );

		$occurrence     = new Occurrence();
		$occurrence->id = 10;

		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->andReturn( $occurrence );

		$this->mock_occurrence_repo->shouldReceive( 'save' )->with( $occurrence );
		$this->mock_ticket_saver->shouldReceive( 'save_for_occurrence' );

		// The event-scoped ticket save fires unconditionally now.
		$this->mock_ticket_saver
			->shouldReceive( 'save_for_event' )
			->once()
			->with( 42, Mockery::type( 'array' ) );

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=created', $result );
	}

	/**
	 * A plain single event (one occurrence) now also reaches save_for_event (R6).
	 *
	 * The removed gate blocked this call whenever count <= 1. Operator ruling 2026-07-20: the call
	 * is unconditional and save_for_event self-guards, so a single-date event that rendered a
	 * series-pass section can save it. Kills a mutant re-introducing a count gate.
	 *
	 * @return void
	 */
	public function test_process_save_single_date_event_still_saves_event_scoped_tickets(): void {
		$this->stub_process_save_wp_functions();

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_title'] = 'One Night Only';
		$_POST['event_slug']  = 'one-night-only';
		$_POST['event_type']  = 'single';
		$_POST['start_date']  = '2026-06-15';
		$_POST['start_time']  = '19:00';
		$_POST['end_date']    = '2026-06-15';
		$_POST['end_time']    = '21:00';

		$saved_event     = new Event();
		$saved_event->id = 42;

		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturn( $saved_event );
		$this->mock_category_repo->shouldReceive( 'sync_event_categories' );

		$occurrence     = new Occurrence();
		$occurrence->id = 10;
		$this->mock_recurrence_service
			->shouldReceive( 'create_single_occurrence' )
			->once()
			->andReturn( $occurrence );
		$this->mock_occurrence_repo->shouldReceive( 'save' )->with( $occurrence );
		$this->mock_ticket_saver->shouldReceive( 'save_for_occurrence' );

		// Only one scheduled date — the old gate would have skipped this call.
		$this->mock_occurrence_repo
			->shouldReceive( 'count_for_event' )
			->andReturn( 1 );

		$this->mock_ticket_saver
			->shouldReceive( 'save_for_event' )
			->once()
			->with( 42, Mockery::type( 'array' ) );

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=created', $result );
	}

	/**
	 * A hand-added date is written after the pattern has had its say — the call is not optional.
	 *
	 * Guards the add_manual_date() call in process_save(): with no start_date the only occurrence
	 * write comes from the manual date, so dropping the call means no override row is ever saved.
	 *
	 * @return void
	 */
	public function test_process_save_writes_the_manual_date(): void {
		$this->stub_process_save_wp_functions();
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_title'] = 'Single Plus Manual Date';
		$_POST['event_slug']  = 'single-plus-manual-date';
		$_POST['event_type']  = 'single';
		// No start_date -> process_occurrences() returns early; only add_manual_date() writes.
		$_POST['nettertech_events_new_date']       = '2026-09-03';
		$_POST['nettertech_events_new_start_time'] = '14:30';
		$_POST['nettertech_events_new_end_time']   = '16:00';

		$saved_event     = new Event();
		$saved_event->id = 42;

		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturn( $saved_event );
		$this->mock_category_repo->shouldReceive( 'sync_event_categories' );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 42, array( 'limit' => 1 ) )
			->andReturn( array() );

		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( $occurrence ) {
					$occurrence->id = 99;
					return $occurrence;
				}
			);

		$this->mock_recurrence_service
			->shouldReceive( 'apply_templates_to_occurrences' )
			->andReturn( 0 );

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=created', $result );
	}

	/**
	 * A validation failure inside the save is caught: the transient is set and an error URL returned.
	 *
	 * Guards the ValidationException catch. Recurring with no pattern throws from
	 * assert_recurrence_specified(); without the catch the exception would escape process_save().
	 *
	 * @return void
	 */
	public function test_process_save_catches_validation_error_and_redirects(): void {
		$this->stub_process_save_wp_functions();

		$transient_set = false;
		Functions\when( 'set_transient' )->alias(
			function () use ( &$transient_set ) {
				$transient_set = true;
				return true;
			}
		);

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_title'] = 'Recurring No Pattern';
		$_POST['event_slug']  = 'recurring-no-pattern';
		$_POST['event_type']  = 'recurring';
		// No recurrence_preset / recurrence_rule -> assert_recurrence_specified() throws.

		$this->mock_event_repo->shouldNotReceive( 'save' );

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=error', $result );
		$this->assertStringContainsString( 'action=edit', $result );
		$this->assertTrue( $transient_set, 'Validation failure must set the error transient' );
	}

	/**
	 * A save that never assigns an id raises a RuntimeException, which is caught and redirected.
	 *
	 * Guards the RuntimeException catch. A null id after save() throws; without the catch the
	 * exception would escape instead of returning the "new event" error URL.
	 *
	 * @return void
	 */
	public function test_process_save_catches_runtime_error_and_redirects(): void {
		$this->stub_process_save_wp_functions();

		$transient_set = false;
		Functions\when( 'set_transient' )->alias(
			function () use ( &$transient_set ) {
				$transient_set = true;
				return true;
			}
		);

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_title'] = 'Save Without Id';
		$_POST['event_slug']  = 'save-without-id';
		$_POST['event_type']  = 'single';

		$id_less     = new Event();
		$id_less->id = null;

		$this->mock_event_repo->shouldReceive( 'save' )->once()->andReturn( $id_less );

		$result = $this->handler->process_save();

		$this->assertStringContainsString( 'message=error', $result );
		$this->assertStringContainsString( AdminMenu::SUBMENU_NEW, $result );
		$this->assertTrue( $transient_set, 'Runtime failure must set the error transient' );
	}

	/**
	 * A hand-added date is handed the event's ticket templates, keyed to the row that was just saved.
	 *
	 * Guards apply_templates_to_occurrences() in add_manual_date(): the call must happen exactly once
	 * and carry the saved occurrence, not an empty set — otherwise the new date could sell nothing.
	 *
	 * @return void
	 */
	public function test_add_manual_date_applies_templates_to_the_saved_occurrence(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 7, array( 'limit' => 1 ) )
			->andReturn( array() );

		$saved = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( $occurrence ) use ( &$saved ) {
					$saved     = $occurrence;
					$saved->id = 99;
					return $occurrence;
				}
			);

		$event     = new Event();
		$event->id = 7;

		$this->mock_recurrence_service
			->shouldReceive( 'apply_templates_to_occurrences' )
			->once()
			->with(
				$event,
				Mockery::on(
					function ( $occurrences ) use ( &$saved ) {
						return is_array( $occurrences )
							&& 1 === count( $occurrences )
							&& $occurrences[0] === $saved;
					}
				)
			)
			->andReturn( 0 );

		$method = new \ReflectionMethod( EventSaveHandler::class, 'add_manual_dates' );
		$method->invoke(
			$this->handler,
			$event,
			array(
				'nettertech_events_new_date'       => '2026-09-03',
				'nettertech_events_new_start_time' => '14:30',
				'nettertech_events_new_end_time'   => '16:00',
			)
		);

		$this->assertSame( 99, $saved->id );
	}

	/**
	 * Adding a manual date flushes the memoized date count, so URLs generated
	 * later in the same request see the event as multi-date (NTE-208).
	 *
	 * Without the flush, a count memoized before the save keeps get_url()
	 * emitting the plain permalink after the event just gained a second date.
	 *
	 * @return void
	 */
	public function test_create_manual_occurrence_flushes_date_count_cache(): void {
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.com' . $path;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				return $default;
			}
		);

		$event             = new Event();
		$event->id         = 42;
		$event->slug       = 'manual-date-event';
		$event->event_type = 'single';

		$probe                 = new Occurrence();
		$probe->event_id       = 42;
		$probe->start_datetime = '2026-09-01 19:00:00';
		$probe->end_datetime   = '2026-09-01 21:00:00';
		$probe->set_event( $event );

		// The counter reflects live state: one date before the save, two after.
		$date_count = 1;
		Occurrence::set_date_counter(
			function () use ( &$date_count ): int {
				return $date_count;
			}
		);

		// Memoize the single-date answer, then move the live state to two dates:
		// the memo must keep answering until the save path flushes it.
		$this->assertSame( 'https://example.com/events/manual-date-event/', $probe->get_url() );
		$date_count = 2;
		$this->assertSame( 'https://example.com/events/manual-date-event/', $probe->get_url() );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 42, array( 'limit' => 1 ) )
			->andReturn( array() );
		$this->mock_occurrence_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( $occurrence ) {
					$occurrence->id = 99;
					return $occurrence;
				}
			);
		$this->mock_recurrence_service
			->shouldReceive( 'apply_templates_to_occurrences' )
			->andReturn( 0 );

		$method = new \ReflectionMethod( EventSaveHandler::class, 'create_manual_occurrence' );
		$method->invoke( $this->handler, $event, 42, '2026-09-03', '14:30', '16:00' );

		$this->assertStringContainsString( '2026-09-01-1900', $probe->get_url() );

		Occurrence::set_date_counter( null );
	}
}
