<?php
/**
 * ReminderEmailService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\ReminderLogRepository;
use NetterTechEvents\Services\EmailTemplateRenderer;
use NetterTechEvents\Services\IcsGenerator;
use NetterTechEvents\Services\ReminderEmailService;
use Brain\Monkey\Functions;

/**
 * Test ReminderEmailService functionality.
 */
class ReminderEmailServiceTest extends \NetterTechEventsTestCase {

	/**
	 * ReminderEmailService instance.
	 *
	 * @var ReminderEmailService
	 */
	private ReminderEmailService $service;

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock AttendeeRepository.
	 *
	 * @var AttendeeRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $attendee_repo;

	/**
	 * Mock ReminderLogRepository.
	 *
	 * @var ReminderLogRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $log_repo;

	/**
	 * Mock EventRepository.
	 *
	 * @var EventRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Mock EmailTemplateRenderer.
	 *
	 * @var EmailTemplateRenderer|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $renderer;

	/**
	 * Mock IcsGenerator.
	 *
	 * @var IcsGenerator|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ics_generator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$this->attendee_repo   = $this->createMock( AttendeeRepository::class );
		$this->log_repo        = $this->createMock( ReminderLogRepository::class );
		$this->event_repo      = $this->createMock( EventRepository::class );
		$this->renderer        = $this->createMock( EmailTemplateRenderer::class );
		$this->ics_generator   = $this->createMock( IcsGenerator::class );

		$this->service = new ReminderEmailService(
			$this->occurrence_repo,
			$this->attendee_repo,
			$this->log_repo,
			$this->event_repo,
			$this->renderer,
			$this->ics_generator
		);
	}

	// =========================================================================
	// Constructor tests
	// =========================================================================

	/**
	 * Test service can be instantiated with injected dependencies.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$this->assertInstanceOf( ReminderEmailService::class, $this->service );
	}

	// =========================================================================
	// get_occurrences_needing_reminders tests
	// =========================================================================

	/**
	 * Test get_occurrences_needing_reminders calls in_range with correct params.
	 *
	 * @return void
	 */
	public function test_get_occurrences_needing_reminders_calls_in_range(): void {
		Functions\when( 'current_time' )->justReturn( '2026-02-04 10:00:00' );
		Functions\when( 'wp_date' )->justReturn( '2026-02-05 11:00:00' );

		$expected_occurrence = $this->create_occurrence( 1, 100 );

		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'in_range' )
			->with(
				'2026-02-04 10:00:00',
				'2026-02-05 11:00:00',
				array(
					'status'         => 'scheduled',
					'event_status'   => 'published',
					'include_events' => false,
				)
			)
			->willReturn( array( $expected_occurrence ) );

		$result = $this->service->get_occurrences_needing_reminders();

		$this->assertCount( 1, $result );
		$this->assertSame( $expected_occurrence, $result[0] );
	}

	// =========================================================================
	// get_unsent_attendees tests
	// =========================================================================

	/**
	 * Test get_unsent_attendees returns all attendees when none have been sent.
	 *
	 * @return void
	 */
	public function test_get_unsent_attendees_returns_all_when_none_sent(): void {
		$attendees = array(
			$this->create_attendee( 1, 'a@example.com' ),
			$this->create_attendee( 2, 'b@example.com' ),
			$this->create_attendee( 3, 'c@example.com' ),
		);

		$this->attendee_repo
			->expects( $this->once() )
			->method( 'for_occurrence' )
			->with( 10, array( 'status' => 'confirmed' ) )
			->willReturn( $attendees );

		$this->log_repo
			->expects( $this->once() )
			->method( 'get_sent_attendee_ids' )
			->with( 10, ReminderEmailService::REMINDER_TYPE )
			->willReturn( array() );

		$result = $this->service->get_unsent_attendees( 10 );

		$this->assertCount( 3, $result );
	}

	/**
	 * Test get_unsent_attendees excludes attendees who already received reminders.
	 *
	 * @return void
	 */
	public function test_get_unsent_attendees_excludes_already_sent(): void {
		$attendees = array(
			$this->create_attendee( 1, 'a@example.com' ),
			$this->create_attendee( 2, 'b@example.com' ),
			$this->create_attendee( 3, 'c@example.com' ),
		);

		$this->attendee_repo
			->method( 'for_occurrence' )
			->willReturn( $attendees );

		$this->log_repo
			->method( 'get_sent_attendee_ids' )
			->willReturn( array( 2 ) );

		$result = $this->service->get_unsent_attendees( 10 );

		$this->assertCount( 2, $result );

		$result_ids = array_map(
			static function ( Attendee $a ): int {
				return $a->id;
			},
			$result
		);
		$this->assertContains( 1, $result_ids );
		$this->assertContains( 3, $result_ids );
		$this->assertNotContains( 2, $result_ids );
	}

	// =========================================================================
	// send_reminder tests
	// =========================================================================

	/**
	 * Test send_reminder calls wp_mail and returns true on success.
	 *
	 * @return void
	 */
	public function test_send_reminder_calls_wp_mail(): void {
		$occurrence = $this->create_occurrence( 1, 100 );
		$event      = $this->create_event( 100 );
		$attendee   = $this->create_attendee( 5, 'reminder@example.com' );

		$this->renderer->method( 'get_reminder_subject' )->willReturn( 'Reminder: Test Event' );
		$this->renderer->method( 'render_reminder_email' )->willReturn( '<p>Reminder body</p>' );
		$this->renderer->method( 'get_email_headers' )->willReturn( array( 'Content-Type: text/html' ) );
		$this->ics_generator->method( 'generate_occurrence_ics' )->willReturn( false );

		$mail_called = false;
		Functions\when( 'wp_mail' )->alias(
			function () use ( &$mail_called ) {
				$mail_called = true;
				return true;
			}
		);

		$this->log_repo
			->expects( $this->once() )
			->method( 'log_sent' );

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$result = $this->service->send_reminder( $occurrence, $event, $attendee );

		$this->assertTrue( $result );
		$this->assertTrue( $mail_called, 'wp_mail should have been called' );
	}

	/**
	 * Test send_reminder logs the result with correct parameters.
	 *
	 * @return void
	 */
	public function test_send_reminder_logs_result(): void {
		$occurrence = $this->create_occurrence( 1, 100 );
		$event      = $this->create_event( 100 );
		$attendee   = $this->create_attendee( 5, 'log@example.com' );

		$this->renderer->method( 'get_reminder_subject' )->willReturn( 'Subject' );
		$this->renderer->method( 'render_reminder_email' )->willReturn( 'Body' );
		$this->renderer->method( 'get_email_headers' )->willReturn( array() );
		$this->ics_generator->method( 'generate_occurrence_ics' )->willReturn( false );

		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$this->log_repo
			->expects( $this->once() )
			->method( 'log_sent' )
			->with( 1, 5, ReminderEmailService::REMINDER_TYPE, 'sent' );

		$this->service->send_reminder( $occurrence, $event, $attendee );
	}

	/**
	 * Test send_reminder logs failed status when wp_mail fails.
	 *
	 * @return void
	 */
	public function test_send_reminder_logs_failed_on_mail_failure(): void {
		$occurrence = $this->create_occurrence( 1, 100 );
		$event      = $this->create_event( 100 );
		$attendee   = $this->create_attendee( 5, 'fail@example.com' );

		$this->renderer->method( 'get_reminder_subject' )->willReturn( 'Subject' );
		$this->renderer->method( 'render_reminder_email' )->willReturn( 'Body' );
		$this->renderer->method( 'get_email_headers' )->willReturn( array() );
		$this->ics_generator->method( 'generate_occurrence_ics' )->willReturn( false );

		Functions\when( 'wp_mail' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$this->log_repo
			->expects( $this->once() )
			->method( 'log_sent' )
			->with( 1, 5, ReminderEmailService::REMINDER_TYPE, 'failed' );

		$result = $this->service->send_reminder( $occurrence, $event, $attendee );

		$this->assertFalse( $result );
	}

	/**
	 * Test send_reminder skips sending when attendee has an invalid email.
	 *
	 * Note: send_reminder itself does not validate email -- that happens
	 * in process_reminders. This test verifies wp_mail is still called
	 * with the empty email (the guard is upstream in process_reminders).
	 * However, wp_mail will return false for an invalid email.
	 *
	 * @return void
	 */
	public function test_send_reminder_skips_invalid_email(): void {
		$occurrence = $this->create_occurrence( 1, 100 );
		$event      = $this->create_event( 100 );
		$attendee   = $this->create_attendee( 5, '' );

		$this->renderer->method( 'get_reminder_subject' )->willReturn( 'Subject' );
		$this->renderer->method( 'render_reminder_email' )->willReturn( 'Body' );
		$this->renderer->method( 'get_email_headers' )->willReturn( array() );
		$this->ics_generator->method( 'generate_occurrence_ics' )->willReturn( false );

		Functions\when( 'wp_mail' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$this->log_repo
			->expects( $this->once() )
			->method( 'log_sent' )
			->with( 1, 5, ReminderEmailService::REMINDER_TYPE, 'failed' );

		$result = $this->service->send_reminder( $occurrence, $event, $attendee );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// is_reminder_enabled_for_event tests
	// =========================================================================

	/**
	 * Test is_reminder_enabled returns true when event override is true.
	 *
	 * @return void
	 */
	public function test_is_reminder_enabled_uses_event_override_true(): void {
		$event = $this->create_event( 1, true );

		$result = $this->service->is_reminder_enabled_for_event( $event );

		$this->assertTrue( $result );
	}

	/**
	 * Test is_reminder_enabled returns false when event override is false.
	 *
	 * @return void
	 */
	public function test_is_reminder_enabled_uses_event_override_false(): void {
		$event = $this->create_event( 1, false );

		$result = $this->service->is_reminder_enabled_for_event( $event );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_reminder_enabled falls back to site default when event is null.
	 *
	 * @return void
	 */
	public function test_is_reminder_enabled_uses_site_default_when_null(): void {
		$event = $this->create_event( 1, null );

		Functions\when( 'get_option' )->justReturn( array( 'enable_reminders' => true ) );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		// Fresh service to avoid cached settings from other tests.
		$service = new ReminderEmailService(
			$this->occurrence_repo,
			$this->attendee_repo,
			$this->log_repo,
			$this->event_repo,
			$this->renderer,
			$this->ics_generator
		);

		$result = $service->is_reminder_enabled_for_event( $event );

		$this->assertTrue( $result );
	}

	/**
	 * Test is_reminder_enabled returns false when site default is disabled.
	 *
	 * @return void
	 */
	public function test_is_reminder_enabled_uses_site_default_disabled(): void {
		$event = $this->create_event( 1, null );

		Functions\when( 'get_option' )->justReturn( array( 'enable_reminders' => false ) );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		// Fresh service to avoid cached settings.
		$service = new ReminderEmailService(
			$this->occurrence_repo,
			$this->attendee_repo,
			$this->log_repo,
			$this->event_repo,
			$this->renderer,
			$this->ics_generator
		);

		$result = $service->is_reminder_enabled_for_event( $event );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// process_reminders tests
	// =========================================================================

	/**
	 * Test process_reminders sends to attendees and returns correct stats.
	 *
	 * @return void
	 */
	public function test_process_reminders_sends_to_attendees(): void {
		Functions\when( 'current_time' )->justReturn( '2026-02-04 10:00:00' );
		Functions\when( 'wp_date' )->justReturn( '2026-02-05 11:00:00' );
		Functions\when( 'get_option' )->justReturn( array( 'enable_reminders' => true ) );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( null );

		$occurrence = $this->create_occurrence( 1, 100 );
		$event      = $this->create_event( 100, null );
		$attendees  = array(
			$this->create_attendee( 10, 'one@example.com' ),
			$this->create_attendee( 11, 'two@example.com' ),
		);

		$this->occurrence_repo
			->method( 'in_range' )
			->willReturn( array( $occurrence ) );

		$this->event_repo
			->method( 'find' )
			->with( 100 )
			->willReturn( $event );

		$this->attendee_repo
			->method( 'for_occurrence' )
			->willReturn( $attendees );

		$this->log_repo
			->method( 'get_sent_attendee_ids' )
			->willReturn( array() );

		$this->renderer->method( 'get_reminder_subject' )->willReturn( 'Subject' );
		$this->renderer->method( 'render_reminder_email' )->willReturn( 'Body' );
		$this->renderer->method( 'get_email_headers' )->willReturn( array() );
		$this->ics_generator->method( 'generate_occurrence_ics' )->willReturn( false );

		$this->log_repo
			->expects( $this->exactly( 2 ) )
			->method( 'log_sent' );

		// Fresh service to use clean settings cache.
		$service = new ReminderEmailService(
			$this->occurrence_repo,
			$this->attendee_repo,
			$this->log_repo,
			$this->event_repo,
			$this->renderer,
			$this->ics_generator
		);

		$stats = $service->process_reminders();

		$this->assertSame( 2, $stats['sent'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertSame( 0, $stats['skipped'] );
	}

	/**
	 * Test process_reminders skips events with reminders disabled.
	 *
	 * @return void
	 */
	public function test_process_reminders_skips_disabled_events(): void {
		Functions\when( 'current_time' )->justReturn( '2026-02-04 10:00:00' );
		Functions\when( 'wp_date' )->justReturn( '2026-02-05 11:00:00' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$occurrence = $this->create_occurrence( 1, 100 );
		$event      = $this->create_event( 100, false );

		$this->occurrence_repo
			->method( 'in_range' )
			->willReturn( array( $occurrence ) );

		$this->event_repo
			->method( 'find' )
			->with( 100 )
			->willReturn( $event );

		// Attendee repo should never be called because event is disabled.
		$this->attendee_repo
			->expects( $this->never() )
			->method( 'for_occurrence' );

		$this->log_repo
			->expects( $this->never() )
			->method( 'log_sent' );

		// Fresh service.
		$service = new ReminderEmailService(
			$this->occurrence_repo,
			$this->attendee_repo,
			$this->log_repo,
			$this->event_repo,
			$this->renderer,
			$this->ics_generator
		);

		$stats = $service->process_reminders();

		$this->assertSame( 0, $stats['sent'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertSame( 0, $stats['skipped'] );
	}

	/**
	 * Test process_reminders respects the batch limit.
	 *
	 * @return void
	 */
	public function test_process_reminders_respects_batch_limit(): void {
		Functions\when( 'current_time' )->justReturn( '2026-02-04 10:00:00' );
		Functions\when( 'wp_date' )->justReturn( '2026-02-05 11:00:00' );
		Functions\when( 'get_option' )->justReturn( array( 'enable_reminders' => true ) );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( null );

		$occurrence = $this->create_occurrence( 1, 100 );
		$event      = $this->create_event( 100, null );

		// Create more attendees than the batch limit.
		$attendees = array();
		for ( $i = 1; $i <= ReminderEmailService::BATCH_LIMIT + 10; $i++ ) {
			$attendees[] = $this->create_attendee( $i, "attendee{$i}@example.com" );
		}

		$this->occurrence_repo
			->method( 'in_range' )
			->willReturn( array( $occurrence ) );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		$this->attendee_repo
			->method( 'for_occurrence' )
			->willReturn( $attendees );

		$this->log_repo
			->method( 'get_sent_attendee_ids' )
			->willReturn( array() );

		$this->renderer->method( 'get_reminder_subject' )->willReturn( 'Subject' );
		$this->renderer->method( 'render_reminder_email' )->willReturn( 'Body' );
		$this->renderer->method( 'get_email_headers' )->willReturn( array() );
		$this->ics_generator->method( 'generate_occurrence_ics' )->willReturn( false );

		// log_sent should be called exactly BATCH_LIMIT times.
		$this->log_repo
			->expects( $this->exactly( ReminderEmailService::BATCH_LIMIT ) )
			->method( 'log_sent' );

		// Fresh service.
		$service = new ReminderEmailService(
			$this->occurrence_repo,
			$this->attendee_repo,
			$this->log_repo,
			$this->event_repo,
			$this->renderer,
			$this->ics_generator
		);

		$stats = $service->process_reminders();

		$this->assertSame( ReminderEmailService::BATCH_LIMIT, $stats['sent'] );
	}

	// =========================================================================
	// Test helpers
	// =========================================================================

	/**
	 * Create a test Occurrence model.
	 *
	 * @param int $id       Occurrence ID.
	 * @param int $event_id Event ID.
	 * @return Occurrence
	 */
	private function create_occurrence( int $id, int $event_id ): Occurrence {
		$occ                 = new Occurrence();
		$occ->id             = $id;
		$occ->event_id       = $event_id;
		$occ->start_datetime = '2026-02-05 19:00:00';
		$occ->end_datetime   = '2026-02-05 21:00:00';
		$occ->status         = 'scheduled';
		return $occ;
	}

	/**
	 * Create a test Event model.
	 *
	 * @param int       $id        Event ID.
	 * @param bool|null $reminders Whether reminders are enabled.
	 * @return Event
	 */
	private function create_event( int $id, ?bool $reminders = null ): Event {
		$event                    = new Event();
		$event->id                = $id;
		$event->title             = 'Test Event';
		$event->slug              = 'test-event';
		$event->status            = EventStatus::PUBLISHED;
		$event->reminders_enabled = $reminders;
		return $event;
	}

	/**
	 * Create a test Attendee model.
	 *
	 * @param int    $id    Attendee ID.
	 * @param string $email Email address.
	 * @return Attendee
	 */
	private function create_attendee( int $id, string $email = 'test@example.com' ): Attendee {
		$attendee         = new Attendee();
		$attendee->id     = $id;
		$attendee->email  = $email;
		$attendee->name   = 'Test User';
		$attendee->status = 'confirmed';
		return $attendee;
	}
}
