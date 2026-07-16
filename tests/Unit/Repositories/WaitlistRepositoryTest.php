<?php
/**
 * WaitlistRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\WaitlistEntry;
use NetterTechEvents\Repositories\WaitlistRepository;

/**
 * Test WaitlistRepository database operations.
 */
class WaitlistRepositoryTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $mock_wpdb;

	/**
	 * Original wpdb instance.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->original_wpdb = $wpdb;

		$this->mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'get_results', 'get_var', 'prepare', 'insert', 'update', 'delete' ) )
			->getMock();

		$this->mock_wpdb->prefix    = 'wp_';
		$this->mock_wpdb->insert_id = 1;

		$this->mock_wpdb->method( 'prepare' )
			->willReturnCallback(
				function ( $sql, ...$args ) {
					return $sql;
				}
			);

		$wpdb = $this->mock_wpdb;

		Functions\when( 'current_time' )->justReturn( '2026-02-18 10:00:00' );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;

		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a repository instance.
	 *
	 * @return WaitlistRepository
	 */
	private function make_repo(): WaitlistRepository {
		global $wpdb;
		return new WaitlistRepository( $wpdb );
	}

	/**
	 * Create a valid WaitlistEntry.
	 *
	 * @param array<string, mixed> $overrides Override properties.
	 * @return WaitlistEntry
	 */
	private function make_entry( array $overrides = array() ): WaitlistEntry {
		$entry                = new WaitlistEntry();
		$entry->id            = $overrides['id'] ?? null;
		$entry->occurrence_id = $overrides['occurrence_id'] ?? 1;
		$entry->email         = $overrides['email'] ?? 'test@example.com';
		$entry->name          = $overrides['name'] ?? 'Test User';
		$entry->phone         = $overrides['phone'] ?? null;
		$entry->position      = $overrides['position'] ?? 1;
		$entry->status        = $overrides['status'] ?? 'waiting';
		$entry->notified_at   = $overrides['notified_at'] ?? null;

		return $entry;
	}

	/**
	 * Create a mock database row.
	 *
	 * @param array<string, mixed> $overrides Override fields.
	 * @return \stdClass
	 */
	private function make_row( array $overrides = array() ): \stdClass {
		$row                  = new \stdClass();
		$row->id              = $overrides['id'] ?? 1;
		$row->occurrence_id   = $overrides['occurrence_id'] ?? 1;
		$row->ticket_type_id  = $overrides['ticket_type_id'] ?? null;
		$row->email           = $overrides['email'] ?? 'test@example.com';
		$row->name            = $overrides['name'] ?? 'Test User';
		$row->phone           = $overrides['phone'] ?? null;
		$row->position        = $overrides['position'] ?? 1;
		$row->status          = $overrides['status'] ?? 'waiting';
		$row->notified_at     = $overrides['notified_at'] ?? null;
		$row->created_at      = $overrides['created_at'] ?? '2026-02-18 09:00:00';
		$row->updated_at      = $overrides['updated_at'] ?? '2026-02-18 09:00:00';

		return $row;
	}

	// =========================================================================
	// find() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_find_returns_entry(): void {
		$this->mock_wpdb->method( 'get_row' )->willReturn( $this->make_row( array( 'id' => 5 ) ) );

		$entry = $this->make_repo()->find( 5 );

		$this->assertInstanceOf( WaitlistEntry::class, $entry );
		$this->assertSame( 5, $entry->id );
	}

	/**
	 * @return void
	 */
	public function test_find_returns_null_when_not_found(): void {
		$this->mock_wpdb->method( 'get_row' )->willReturn( null );

		$this->assertNull( $this->make_repo()->find( 999 ) );
	}

	// =========================================================================
	// find_by_email() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_find_by_email_returns_match(): void {
		$this->mock_wpdb->method( 'get_row' )
			->willReturn( $this->make_row( array( 'email' => 'alice@test.com' ) ) );

		$entry = $this->make_repo()->find_by_email( 'alice@test.com', 1 );

		$this->assertInstanceOf( WaitlistEntry::class, $entry );
		$this->assertSame( 'alice@test.com', $entry->email );
	}

	/**
	 * @return void
	 */
	public function test_find_by_email_returns_null_when_not_found(): void {
		$this->mock_wpdb->method( 'get_row' )->willReturn( null );

		$this->assertNull( $this->make_repo()->find_by_email( 'nobody@test.com', 1 ) );
	}

	// =========================================================================
	// for_occurrence() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_for_occurrence_returns_entries(): void {
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array(
				$this->make_row( array( 'position' => 1 ) ),
				$this->make_row( array( 'position' => 2, 'id' => 2 ) ),
			)
		);

		$entries = $this->make_repo()->for_occurrence( 1 );

		$this->assertCount( 2, $entries );
		$this->assertInstanceOf( WaitlistEntry::class, $entries[0] );
	}

	/**
	 * @return void
	 */
	public function test_for_occurrence_with_status_filter(): void {
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array( $this->make_row( array( 'status' => 'notified' ) ) )
		);

		$entries = $this->make_repo()->for_occurrence( 1, 'notified' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'notified', $entries[0]->status );
	}

	/**
	 * @return void
	 */
	public function test_for_occurrence_returns_empty_array(): void {
		$this->mock_wpdb->method( 'get_results' )->willReturn( array() );

		$this->assertSame( array(), $this->make_repo()->for_occurrence( 999 ) );
	}

	// =========================================================================
	// count_for_occurrence() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_count_for_occurrence_returns_count(): void {
		$this->mock_wpdb->method( 'get_var' )->willReturn( '5' );

		$this->assertSame( 5, $this->make_repo()->count_for_occurrence( 1 ) );
	}

	/**
	 * @return void
	 */
	public function test_count_for_occurrence_returns_zero_when_empty(): void {
		$this->mock_wpdb->method( 'get_var' )->willReturn( '0' );

		$this->assertSame( 0, $this->make_repo()->count_for_occurrence( 1, 'waiting' ) );
	}

	/**
	 * Test count_for_occurrence without status filter queries all entries.
	 *
	 * @return void
	 */
	public function test_count_for_occurrence_without_status_filter(): void {
		$this->mock_wpdb->method( 'get_var' )->willReturn( '12' );

		$this->assertSame( 12, $this->make_repo()->count_for_occurrence( 1, '' ) );
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_save_inserts_new_entry(): void {
		$this->mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturn( 1 );

		$entry = $this->make_entry();
		$saved = $this->make_repo()->save( $entry );

		$this->assertSame( 1, $saved->id );
	}

	/**
	 * @return void
	 */
	public function test_save_updates_existing_entry(): void {
		$this->mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->willReturn( 1 );

		$entry = $this->make_entry( array( 'id' => 5 ) );
		$saved = $this->make_repo()->save( $entry );

		$this->assertSame( 5, $saved->id );
	}

	/**
	 * @return void
	 */
	public function test_save_throws_validation_exception_on_invalid(): void {
		$this->expectException( ValidationException::class );

		$entry = $this->make_entry( array( 'email' => 'invalid', 'name' => '' ) );

		$this->make_repo()->save( $entry );
	}

	/**
	 * @return void
	 */
	public function test_save_throws_database_exception_on_insert_failure(): void {
		$this->expectException( DatabaseException::class );

		$this->mock_wpdb->method( 'insert' )->willReturn( false );
		$this->mock_wpdb->last_error = 'Duplicate entry';

		$entry = $this->make_entry();

		$this->make_repo()->save( $entry );
	}

	/**
	 * @return void
	 */
	public function test_save_throws_database_exception_on_update_failure(): void {
		$this->expectException( DatabaseException::class );

		$this->mock_wpdb->method( 'update' )->willReturn( false );
		$this->mock_wpdb->last_error = 'Update failed';

		$entry = $this->make_entry( array( 'id' => 5 ) );

		$this->make_repo()->save( $entry );
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_delete_returns_true_on_success(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( 1 );

		$this->assertTrue( $this->make_repo()->delete( 1 ) );
	}

	/**
	 * @return void
	 */
	public function test_delete_returns_false_on_failure(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( false );

		$this->assertFalse( $this->make_repo()->delete( 999 ) );
	}

	// =========================================================================
	// delete_for_occurrence() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_delete_for_occurrence_returns_row_count(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( 3 );

		$this->assertSame( 3, $this->make_repo()->delete_for_occurrence( 1 ) );
	}

	/**
	 * @return void
	 */
	public function test_delete_for_occurrence_returns_zero_on_failure(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( false );

		$this->assertSame( 0, $this->make_repo()->delete_for_occurrence( 1 ) );
	}

	// =========================================================================
	// get_next_position() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_get_next_position_returns_one_for_empty(): void {
		$this->mock_wpdb->method( 'get_var' )->willReturn( null );

		$this->assertSame( 1, $this->make_repo()->get_next_position( 1 ) );
	}

	/**
	 * @return void
	 */
	public function test_get_next_position_returns_max_plus_one(): void {
		$this->mock_wpdb->method( 'get_var' )->willReturn( '7' );

		$this->assertSame( 8, $this->make_repo()->get_next_position( 1 ) );
	}

	// =========================================================================
	// get_next_in_queue() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_get_next_in_queue_returns_first_waiting(): void {
		$this->mock_wpdb->method( 'get_row' )
			->willReturn( $this->make_row( array( 'position' => 1, 'status' => 'waiting' ) ) );

		$entry = $this->make_repo()->get_next_in_queue( 1 );

		$this->assertInstanceOf( WaitlistEntry::class, $entry );
		$this->assertSame( 1, $entry->position );
		$this->assertSame( 'waiting', $entry->status );
	}

	/**
	 * @return void
	 */
	public function test_get_next_in_queue_returns_null_when_empty(): void {
		$this->mock_wpdb->method( 'get_row' )->willReturn( null );

		$this->assertNull( $this->make_repo()->get_next_in_queue( 999 ) );
	}

	// =========================================================================
	// update_status() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_update_status_returns_true_on_success(): void {
		$this->mock_wpdb->method( 'update' )->willReturn( 1 );

		$this->assertTrue( $this->make_repo()->update_status( 1, 'converted' ) );
	}

	/**
	 * @return void
	 */
	public function test_update_status_returns_false_on_failure(): void {
		$this->mock_wpdb->method( 'update' )->willReturn( false );

		$this->assertFalse( $this->make_repo()->update_status( 1, 'expired' ) );
	}

	/**
	 * @return void
	 */
	public function test_update_status_sets_notified_at_for_notified_status(): void {
		$captured_data = null;

		$this->mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->willReturnCallback(
				function ( $table, $data ) use ( &$captured_data ) {
					$captured_data = $data;
					return 1;
				}
			);

		$this->make_repo()->update_status( 1, 'notified' );

		$this->assertArrayHasKey( 'notified_at', $captured_data );
		$this->assertSame( '2026-02-18 10:00:00', $captured_data['notified_at'] );
	}

	/**
	 * @return void
	 */
	public function test_update_status_does_not_set_notified_at_for_other_statuses(): void {
		$captured_data = null;

		$this->mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->willReturnCallback(
				function ( $table, $data ) use ( &$captured_data ) {
					$captured_data = $data;
					return 1;
				}
			);

		$this->make_repo()->update_status( 1, 'converted' );

		$this->assertArrayNotHasKey( 'notified_at', $captured_data );
	}
}
