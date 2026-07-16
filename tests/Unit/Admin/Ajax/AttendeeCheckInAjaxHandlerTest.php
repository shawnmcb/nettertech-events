<?php
/**
 * Tests for AttendeeCheckInAjaxHandler (NTE-144).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Ajax
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Ajax;

use NetterTechEvents\Admin\Ajax\AttendeeCheckInAjaxHandler;
use NetterTechEvents\Contracts\AttendeeCheckInInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Tests\Factories\AttendeeFactory;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @coversDefaultClass \NetterTechEvents\Admin\Ajax\AttendeeCheckInAjaxHandler
 */
class AttendeeCheckInAjaxHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock attendee repository.
	 *
	 * @var AttendeeRepositoryInterface|Mockery\MockInterface
	 */
	private $attendee_repo;

	/**
	 * Mock check-in repository.
	 *
	 * @var AttendeeCheckInInterface|Mockery\MockInterface
	 */
	private $check_in_repo;

	/**
	 * Mock activity log service.
	 *
	 * @var ActivityLogServiceInterface|Mockery\MockInterface
	 */
	private $activity_log;

	/**
	 * Handler under test.
	 *
	 * @var AttendeeCheckInAjaxHandler
	 */
	private AttendeeCheckInAjaxHandler $handler;

	/**
	 * Captured wp_send_json_success payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $success_payload = array();

	/**
	 * Captured wp_send_json_error payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $error_payload = array();

	/**
	 * Captured wp_send_json_error HTTP status.
	 *
	 * @var int|null
	 */
	private ?int $error_status = null;

	protected function setUp(): void {
		parent::setUp();

		$this->attendee_repo = Mockery::mock( AttendeeRepositoryInterface::class );
		$this->check_in_repo = Mockery::mock( AttendeeCheckInInterface::class );
		$this->activity_log  = Mockery::mock( ActivityLogServiceInterface::class );

		$this->handler = new AttendeeCheckInAjaxHandler(
			$this->attendee_repo,
			$this->check_in_repo,
			$this->activity_log
		);

		Functions\when( '__' )->alias( fn( $text ) => $text );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => $value );
		Functions\when( 'sanitize_key' )->alias( fn( $value ) => strtolower( (string) $value ) );
		Functions\when( 'wp_unslash' )->alias( fn( $value ) => $value );
		Functions\when( 'absint' )->alias( fn( $value ) => abs( (int) $value ) );

		// Both JSON responders halt in production (wp_die); model that with throws.
		// The payload and status are captured, not discarded: the error contract
		// (which status, which message) is the assertion surface for every refusal
		// path, so a wrong code or a blanked message fails the test.
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status = null ) {
				$this->error_payload = is_array( $data ) ? $data : array();
				$this->error_status  = null === $status ? null : (int) $status;
				throw new \RuntimeException( 'wp_send_json_error' );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) {
				$this->success_payload = $data;
				throw new \RuntimeException( 'wp_send_json_success' );
			}
		);

		AttendeeFactory::reset();
	}

	protected function tearDown(): void {
		unset( $_POST['attendee_id'], $_POST['nonce'], $_POST['state'] );
		parent::tearDown();
	}

	/**
	 * Capability must be checked before anything else, including the nonce.
	 *
	 * The asserted message distinguishes this refusal from the nonce refusal —
	 * both are 403, so message is the only thing that proves ordering.
	 *
	 * @covers ::handle
	 */
	public function test_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertRefusal( 403, 'Permission denied.' );
	}

	/**
	 * @covers ::handle
	 */
	public function test_denies_on_invalid_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$_POST['nonce'] = 'bad';

		$this->assertRefusal( 403, 'Security check failed.' );
	}

	/**
	 * A wholly absent nonce must be refused exactly like a forged one.
	 *
	 * @covers ::handle
	 */
	public function test_denies_on_missing_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		unset( $_POST['nonce'] );

		$this->assertRefusal( 403, 'Security check failed.' );
	}

	/**
	 * Zero, negative and absent IDs are all "no attendee named".
	 *
	 * @dataProvider non_positive_attendee_ids
	 * @covers ::handle
	 * @param string|null $raw_id Raw POSTed attendee_id, or null to omit it.
	 */
	public function test_rejects_non_positive_attendee_id( ?string $raw_id ): void {
		$this->authorize();

		if ( null === $raw_id ) {
			unset( $_POST['attendee_id'] );
		} else {
			$_POST['attendee_id'] = $raw_id;
		}
		$_POST['state'] = 'in';

		// Strict mock: reaching the repository at all means the guard let a bad ID through.
		$this->attendee_repo->shouldNotReceive( 'find' );

		$this->assertRefusal( 400, 'Invalid attendee.' );
	}

	/**
	 * Attendee IDs that must never reach the repository.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function non_positive_attendee_ids(): array {
		return array(
			'zero'    => array( '0' ),
			'absent'  => array( null ),
			'empty'   => array( '' ),
		);
	}

	/**
	 * @covers ::handle
	 */
	public function test_rejects_unknown_state(): void {
		$this->authorize();

		$_POST['attendee_id'] = '5';
		$_POST['state']       = 'sideways';

		$this->assertRefusal( 400, 'Invalid check-in state.' );
	}

	/**
	 * @covers ::handle
	 */
	public function test_rejects_missing_attendee(): void {
		$this->authorize();

		$_POST['attendee_id'] = '99';
		$_POST['state']       = 'in';

		$this->attendee_repo->shouldReceive( 'find' )->once()->with( 99 )->andReturn( null );

		$this->assertRefusal( 404, 'Attendee not found.' );
	}

	/**
	 * The endpoint is the authority: a voided, refunded, cancelled or pending
	 * attendee must be refused even if the request reaches the handler, and the
	 * check-in repository must never be touched.
	 *
	 * @dataProvider non_confirmed_statuses
	 * @covers ::handle
	 * @param string $status Non-confirmed attendee status.
	 */
	public function test_refuses_check_in_for_non_confirmed_attendee( string $status ): void {
		$this->authorize();

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 12,
				'name'          => 'Ada Lovelace',
				'quantity'      => 1,
				'occurrence_id' => 42,
				'status'        => $status,
			)
		);

		$_POST['attendee_id'] = '12';
		$_POST['state']       = 'in';

		$this->attendee_repo->shouldReceive( 'find' )->once()->with( 12 )->andReturn( $attendee );
		// Strict mocks: no mark_checked_in / log expectation means any call fails.
		$this->check_in_repo->shouldNotReceive( 'mark_checked_in' );
		$this->activity_log->shouldNotReceive( 'log_attendee' );

		$this->assertRefusal( 409, 'Only confirmed attendees can be checked in.' );
	}

	/**
	 * Statuses that hold no valid ticket and must be refused check-in.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function non_confirmed_statuses(): array {
		return array(
			'voided'    => array( 'voided' ),
			'refunded'  => array( 'refunded' ),
			'cancelled' => array( 'cancelled' ),
			'pending'   => array( 'pending' ),
		);
	}

	/**
	 * @covers ::handle
	 */
	public function test_checks_attendee_in_and_logs_activity(): void {
		$this->authorize();

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 7,
				'name'          => 'Ada Lovelace',
				'quantity'      => 2,
				'occurrence_id' => 42,
			)
		);

		$_POST['attendee_id'] = '7';
		$_POST['state']       = 'in';

		$this->attendee_repo->shouldReceive( 'find' )->once()->with( 7 )->andReturn( $attendee );
		$this->check_in_repo->shouldReceive( 'mark_checked_in' )->once()->with( 7 )->andReturn( true );
		$this->check_in_repo->shouldReceive( 'get_check_in_stats' )->once()->with( 42 )->andReturn(
			array( 'total_registrations' => 3 )
		);
		$this->activity_log->shouldReceive( 'log_attendee' )
			->once()
			->with( 'check_in', 7, 'Ada Lovelace', array( 'occurrence_id' => 42 ) );

		$this->expectException( \RuntimeException::class );
		try {
			$this->handler->handle();
		} finally {
			$this->assertSame(
				array(
					'attendeeId'     => 7,
					'checkedIn'      => true,
					'checkedInCount' => 2,
					'quantity'       => 2,
					'label'          => 'Yes',
					'stats'          => array( 'total_registrations' => 3 ),
				),
				$this->success_payload
			);
		}
	}

	/**
	 * Check-in fires ATTENDEE_CHECKED_IN with the exact context payload.
	 *
	 * @covers ::handle
	 */
	public function test_check_in_fires_attendee_checked_in_hook(): void {
		$this->authorize();

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 7,
				'name'          => 'Ada Lovelace',
				'quantity'      => 2,
				'occurrence_id' => 42,
			)
		);

		$_POST['attendee_id'] = '7';
		$_POST['state']       = 'in';

		$this->attendee_repo->shouldReceive( 'find' )->once()->with( 7 )->andReturn( $attendee );
		$this->check_in_repo->shouldReceive( 'mark_checked_in' )->once()->with( 7 )->andReturn( true );
		$this->check_in_repo->shouldReceive( 'get_check_in_stats' )->andReturn( array() );
		$this->activity_log->shouldReceive( 'log_attendee' );

		\Brain\Monkey\Actions\expectDone( 'nettertech_events_attendee_checked_in' )
			->once()
			->with(
				7,
				array(
					'occurrence_id' => 42,
					'name'          => 'Ada Lovelace',
					'quantity'      => 2,
				)
			);

		$this->expectException( \RuntimeException::class );
		$this->handler->handle();
	}

	/**
	 * Undo (check-out) must NOT fire ATTENDEE_CHECKED_IN.
	 *
	 * @covers ::handle
	 */
	public function test_undo_does_not_fire_attendee_checked_in_hook(): void {
		$this->authorize();

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 8,
				'name'          => 'Grace Hopper',
				'quantity'      => 1,
				'occurrence_id' => 42,
			)
		);

		$_POST['attendee_id'] = '8';
		$_POST['state']       = 'out';

		$this->attendee_repo->shouldReceive( 'find' )->once()->with( 8 )->andReturn( $attendee );
		$this->check_in_repo->shouldReceive( 'mark_not_checked_in' )->once()->with( 8 )->andReturn( true );
		$this->check_in_repo->shouldReceive( 'get_check_in_stats' )->andReturn( array() );
		$this->activity_log->shouldReceive( 'log_attendee' );

		\Brain\Monkey\Actions\expectDone( 'nettertech_events_attendee_checked_in' )->never();

		$this->expectException( \RuntimeException::class );
		$this->handler->handle();
	}

	/**
	 * The hook's quantity is clamped to at least one, matching the response.
	 *
	 * @covers ::handle
	 */
	public function test_hook_quantity_clamped_to_at_least_one(): void {
		$this->authorize();

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 11,
				'name'          => 'Zero Qty',
				'quantity'      => 0,
				'occurrence_id' => 42,
			)
		);

		$_POST['attendee_id'] = '11';
		$_POST['state']       = 'in';

		$this->attendee_repo->shouldReceive( 'find' )->once()->with( 11 )->andReturn( $attendee );
		$this->check_in_repo->shouldReceive( 'mark_checked_in' )->once()->with( 11 )->andReturn( true );
		$this->check_in_repo->shouldReceive( 'get_check_in_stats' )->andReturn( array() );
		$this->activity_log->shouldReceive( 'log_attendee' );

		\Brain\Monkey\Actions\expectDone( 'nettertech_events_attendee_checked_in' )
			->once()
			->with( 11, \Mockery::on( static fn( $data ) => 1 === $data['quantity'] ) );

		$this->expectException( \RuntimeException::class );
		$this->handler->handle();
	}

	/**
	 * A partially checked-in party renders "n/total", not a bare Yes/No.
	 *
	 * @covers ::handle
	 * @covers ::format_check_in_label
	 */
	public function test_quantity_is_clamped_to_at_least_one(): void {
		$this->authorize();

		// quantity 0 is a degenerate row; the handler floors it at 1 so the label
		// and the count never report a party of nobody.
		$attendee = AttendeeFactory::create(
			array(
				'id'            => 11,
				'name'          => 'Ada Lovelace',
				'quantity'      => 0,
				'occurrence_id' => 42,
			)
		);

		$_POST['attendee_id'] = '11';
		$_POST['state']       = 'in';

		$this->attendee_repo->shouldReceive( 'find' )->once()->with( 11 )->andReturn( $attendee );
		$this->check_in_repo->shouldReceive( 'mark_checked_in' )->once()->with( 11 )->andReturn( true );
		$this->check_in_repo->shouldReceive( 'get_check_in_stats' )->once()->with( 42 )->andReturn( array() );
		$this->activity_log->shouldReceive( 'log_attendee' )->once();

		$this->expectException( \RuntimeException::class );
		try {
			$this->handler->handle();
		} finally {
			$this->assertSame( 1, $this->success_payload['quantity'] );
			$this->assertSame( 1, $this->success_payload['checkedInCount'] );
			$this->assertSame( 'Yes', $this->success_payload['label'] );
		}
	}

	/**
	 * @covers ::handle
	 */
	public function test_undo_resets_check_in(): void {
		$this->authorize();

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 8,
				'name'          => 'Grace Hopper',
				'quantity'      => 2,
				'occurrence_id' => 42,
			)
		);

		$_POST['attendee_id'] = '8';
		$_POST['state']       = 'out';

		$this->attendee_repo->shouldReceive( 'find' )->once()->with( 8 )->andReturn( $attendee );
		$this->check_in_repo->shouldReceive( 'mark_not_checked_in' )->once()->with( 8 )->andReturn( true );
		$this->check_in_repo->shouldReceive( 'get_check_in_stats' )->once()->with( 42 )->andReturn( array() );
		$this->activity_log->shouldReceive( 'log_attendee' )
			->once()
			->with( 'undo_check_in', 8, 'Grace Hopper', array( 'occurrence_id' => 42 ) );

		$this->expectException( \RuntimeException::class );
		try {
			$this->handler->handle();
		} finally {
			$this->assertSame(
				array(
					'attendeeId'     => 8,
					'checkedIn'      => false,
					'checkedInCount' => 0,
					'quantity'       => 2,
					'label'          => 'No',
					'stats'          => array(),
				),
				$this->success_payload
			);
		}
	}

	/**
	 * A failed repository write must surface as an error, not a false success.
	 *
	 * @covers ::handle
	 */
	public function test_reports_repository_failure(): void {
		$this->authorize();

		$attendee = AttendeeFactory::create( array( 'id' => 9, 'occurrence_id' => 1 ) );

		$_POST['attendee_id'] = '9';
		$_POST['state']       = 'in';

		$this->attendee_repo->shouldReceive( 'find' )->once()->with( 9 )->andReturn( $attendee );
		$this->check_in_repo->shouldReceive( 'mark_checked_in' )->once()->with( 9 )->andReturn( false );
		$this->activity_log->shouldNotReceive( 'log_attendee' );

		$this->assertRefusal( 500, 'Could not update check-in status.' );
	}

	/**
	 * Grant capability and a valid nonce.
	 */
	private function authorize(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_POST['nonce'] = 'good';
	}

	/**
	 * Run the handler and assert it refused with an exact status and message.
	 *
	 * @param int    $status  Expected HTTP status.
	 * @param string $message Expected user-facing message.
	 */
	private function assertRefusal( int $status, string $message ): void {
		try {
			$this->handler->handle();
			$this->fail( 'Expected the handler to refuse, but it returned normally.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage(), 'Handler succeeded instead of refusing.' );
		}

		$this->assertSame( $status, $this->error_status );
		$this->assertSame( array( 'message' => $message ), $this->error_payload );
	}
}
