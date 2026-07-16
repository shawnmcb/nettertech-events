<?php
/**
 * Tests for WaitlistService.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Contracts\WaitlistRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\WaitlistEntry;
use NetterTechEvents\Services\WaitlistService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @coversDefaultClass \NetterTechEvents\Services\WaitlistService
 */
class WaitlistServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Mock repository.
	 *
	 * @var WaitlistRepositoryInterface|\Mockery\MockInterface
	 */
	private $repo;

	/**
	 * Service under test.
	 *
	 * @var WaitlistService
	 */
	private WaitlistService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->repo    = Mockery::mock( WaitlistRepositoryInterface::class );
		$this->service = new WaitlistService( $this->repo );
	}

	// =========================================================================
	// join() Tests
	// =========================================================================

	public function test_join_creates_entry(): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$this->repo->shouldReceive( 'find_by_email' )
			->with( 'test@example.com', 42 )
			->once()
			->andReturnNull();

		$this->repo->shouldReceive( 'get_next_position' )
			->with( 42 )
			->once()
			->andReturn( 1 );

		$this->repo->shouldReceive( 'save' )
			->once()
			->andReturnUsing( function ( WaitlistEntry $entry ) {
				$entry->id = 100;
				return $entry;
			} );

		$captured_actions = array();
		Functions\when( 'do_action' )->alias(
			function () use ( &$captured_actions ) {
				$captured_actions[] = func_get_args();
			}
		);

		$entry = $this->service->join( 42, 'test@example.com', 'Jane Doe' );

		$this->assertSame( 100, $entry->id );
		$this->assertSame( 42, $entry->occurrence_id );
		$this->assertSame( 'test@example.com', $entry->email );
		$this->assertSame( 'Jane Doe', $entry->name );
		$this->assertSame( 1, $entry->position );
		$this->assertSame( 'waiting', $entry->status );

		$joined_actions = array_filter(
			$captured_actions,
			fn( $args ) => $args[0] === Hooks::WAITLIST_JOINED
		);
		$this->assertCount( 1, $joined_actions, 'WAITLIST_JOINED action should fire exactly once.' );
		$action_args = array_values( $joined_actions )[0];
		$this->assertInstanceOf( WaitlistEntry::class, $action_args[1] );
		$this->assertSame( 42, $action_args[2] );
	}

	public function test_join_with_phone_and_ticket_type(): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$this->repo->shouldReceive( 'find_by_email' )->andReturnNull();
		$this->repo->shouldReceive( 'get_next_position' )->andReturn( 5 );
		$this->repo->shouldReceive( 'save' )->andReturnUsing( function ( WaitlistEntry $entry ) {
			$entry->id = 101;
			return $entry;
		} );

		$entry = $this->service->join( 42, 'test@example.com', 'Jane', '555-1234', 10 );

		$this->assertSame( '555-1234', $entry->phone );
		$this->assertSame( 10, $entry->ticket_type_id );
		$this->assertSame( 5, $entry->position );
	}

	public function test_join_throws_if_already_on_waitlist(): void {
		Functions\when( 'esc_html__' )->returnArg();

		$existing         = new WaitlistEntry();
		$existing->status = 'waiting';

		$this->repo->shouldReceive( 'find_by_email' )
			->with( 'test@example.com', 42 )
			->andReturn( $existing );

		$this->expectException( \RuntimeException::class );

		$this->service->join( 42, 'test@example.com', 'Jane' );
	}

	public function test_join_allows_reentry_if_previously_removed(): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$existing         = new WaitlistEntry();
		$existing->status = 'removed';

		$this->repo->shouldReceive( 'find_by_email' )->andReturn( $existing );
		$this->repo->shouldReceive( 'get_next_position' )->andReturn( 3 );
		$this->repo->shouldReceive( 'save' )->andReturnUsing( function ( WaitlistEntry $entry ) {
			$entry->id = 102;
			return $entry;
		} );

		$entry = $this->service->join( 42, 'test@example.com', 'Jane' );

		$this->assertSame( 102, $entry->id );
	}

	// =========================================================================
	// leave() Tests
	// =========================================================================

	public function test_leave_removes_entry(): void {
		$existing     = new WaitlistEntry();
		$existing->id = 5;

		$this->repo->shouldReceive( 'find_by_email' )
			->with( 'test@example.com', 42 )
			->andReturn( $existing );

		$this->repo->shouldReceive( 'update_status' )
			->with( 5, 'removed' )
			->once()
			->andReturnTrue();

		$captured_actions = array();
		Functions\when( 'do_action' )->alias(
			function () use ( &$captured_actions ) {
				$captured_actions[] = func_get_args();
			}
		);

		$result = $this->service->leave( 42, 'test@example.com' );

		$this->assertTrue( $result );

		$left_actions = array_filter(
			$captured_actions,
			fn( $args ) => $args[0] === Hooks::WAITLIST_LEFT
		);
		$this->assertCount( 1, $left_actions, 'WAITLIST_LEFT action should fire exactly once.' );
		$action_args = array_values( $left_actions )[0];
		$this->assertInstanceOf( WaitlistEntry::class, $action_args[1] );
		$this->assertSame( 42, $action_args[2] );
	}

	public function test_leave_returns_false_if_not_found(): void {
		$this->repo->shouldReceive( 'find_by_email' )
			->andReturnNull();

		$result = $this->service->leave( 42, 'nonexistent@example.com' );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// get_position() Tests
	// =========================================================================

	public function test_get_position_returns_position(): void {
		$entry           = new WaitlistEntry();
		$entry->position = 3;
		$entry->status   = 'waiting';

		$this->repo->shouldReceive( 'find_by_email' )
			->with( 'test@example.com', 42 )
			->andReturn( $entry );

		$position = $this->service->get_position( 42, 'test@example.com' );

		$this->assertSame( 3, $position );
	}

	public function test_get_position_returns_null_if_not_found(): void {
		$this->repo->shouldReceive( 'find_by_email' )->andReturnNull();

		$position = $this->service->get_position( 42, 'test@example.com' );

		$this->assertNull( $position );
	}

	public function test_get_position_returns_null_if_not_waiting(): void {
		$entry         = new WaitlistEntry();
		$entry->status = 'notified';

		$this->repo->shouldReceive( 'find_by_email' )->andReturn( $entry );

		$position = $this->service->get_position( 42, 'test@example.com' );

		$this->assertNull( $position );
	}

	// =========================================================================
	// promote_next() Tests
	// =========================================================================

	public function test_promote_next_marks_as_notified(): void {
		$entry         = new WaitlistEntry();
		$entry->id     = 10;
		$entry->status = 'waiting';

		$this->repo->shouldReceive( 'get_next_in_queue' )
			->with( 42 )
			->andReturn( $entry );

		$this->repo->shouldReceive( 'update_status' )
			->with( 10, 'notified' )
			->once()
			->andReturnTrue();

		$captured_actions = array();
		Functions\when( 'do_action' )->alias(
			function () use ( &$captured_actions ) {
				$captured_actions[] = func_get_args();
			}
		);

		$promoted = $this->service->promote_next( 42 );

		$this->assertNotNull( $promoted );
		$this->assertSame( 'notified', $promoted->status );

		$promoted_actions = array_filter(
			$captured_actions,
			fn( $args ) => $args[0] === Hooks::WAITLIST_PROMOTED
		);
		$this->assertCount( 1, $promoted_actions, 'WAITLIST_PROMOTED action should fire exactly once.' );
		$action_args = array_values( $promoted_actions )[0];
		$this->assertInstanceOf( WaitlistEntry::class, $action_args[1] );
		$this->assertSame( 42, $action_args[2] );
	}

	public function test_promote_next_returns_null_if_queue_empty(): void {
		$this->repo->shouldReceive( 'get_next_in_queue' )
			->with( 42 )
			->andReturnNull();

		$promoted = $this->service->promote_next( 42 );

		$this->assertNull( $promoted );
	}

	// =========================================================================
	// Other method tests
	// =========================================================================

	public function test_get_queue_delegates_to_repo(): void {
		$entries = array( new WaitlistEntry(), new WaitlistEntry() );

		$this->repo->shouldReceive( 'for_occurrence' )
			->with( 42, 'waiting' )
			->andReturn( $entries );

		$result = $this->service->get_queue( 42 );

		$this->assertCount( 2, $result );
	}

	public function test_get_count_delegates_to_repo(): void {
		$this->repo->shouldReceive( 'count_for_occurrence' )
			->with( 42 )
			->andReturn( 5 );

		$this->assertSame( 5, $this->service->get_count( 42 ) );
	}

	public function test_mark_converted_delegates_to_repo(): void {
		$this->repo->shouldReceive( 'update_status' )
			->with( 10, 'converted' )
			->once()
			->andReturnTrue();

		$this->assertTrue( $this->service->mark_converted( 10 ) );
	}

	public function test_is_on_waitlist_true(): void {
		$entry         = new WaitlistEntry();
		$entry->status = 'waiting';

		$this->repo->shouldReceive( 'find_by_email' )
			->with( 'test@example.com', 42 )
			->andReturn( $entry );

		$this->assertTrue( $this->service->is_on_waitlist( 'test@example.com', 42 ) );
	}

	public function test_is_on_waitlist_false_when_not_found(): void {
		$this->repo->shouldReceive( 'find_by_email' )->andReturnNull();

		$this->assertFalse( $this->service->is_on_waitlist( 'test@example.com', 42 ) );
	}

	public function test_is_on_waitlist_false_when_not_waiting(): void {
		$entry         = new WaitlistEntry();
		$entry->status = 'notified';

		$this->repo->shouldReceive( 'find_by_email' )->andReturn( $entry );

		$this->assertFalse( $this->service->is_on_waitlist( 'test@example.com', 42 ) );
	}
}
