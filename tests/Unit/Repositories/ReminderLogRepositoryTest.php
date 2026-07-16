<?php
/**
 * ReminderLogRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use NetterTechEvents\Repositories\ReminderLogRepository;
use NetterTechEvents\Models\ReminderLog;

/**
 * Test ReminderLogRepository functionality.
 *
 * Tests reminder log persistence and duplicate-prevention queries.
 *
 * @coversDefaultClass \NetterTechEvents\Repositories\ReminderLogRepository
 */
class ReminderLogRepositoryTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $mock_db;

	/**
	 * Original wpdb instance.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Captured SQL queries from prepare() calls.
	 *
	 * @var array<string>
	 */
	private array $captured_queries = array();

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->original_wpdb = $wpdb;

		$this->mock_db = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare', 'get_var', 'get_col', 'get_results', 'delete' ) )
			->getMock();

		$this->mock_db->prefix    = 'wp_';
		$this->mock_db->insert_id = 0;

		$this->mock_db->method( 'prepare' )
			->willReturnCallback(
				function ( $sql, ...$args ) {
					$this->captured_queries[] = $sql;
					return $sql;
				}
			);

		Functions\when( 'current_time' )->justReturn( '2026-02-04 10:00:00' );
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;

		parent::tearDown();
	}

	// =========================================================================
	// Instantiation Tests
	// =========================================================================

	/**
	 * Test repository can be instantiated.
	 *
	 * @covers ::__construct
	 * @return void
	 */
	public function test_can_instantiate_repository(): void {
		$repo = new ReminderLogRepository( $this->mock_db );

		$this->assertInstanceOf( ReminderLogRepository::class, $repo );
	}

	// =========================================================================
	// log_sent() Tests
	// =========================================================================

	/**
	 * Test log_sent creates a record on successful insert.
	 *
	 * @covers ::log_sent
	 * @return void
	 */
	public function test_log_sent_creates_record(): void {
		$this->mock_db->insert_id = 42;

		$this->mock_db->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->stringContains( 'nettertech_events_reminder_log' ),
				$this->callback(
					function ( $data ) {
						return isset( $data['occurrence_id'], $data['attendee_id'], $data['reminder_type'] );
					}
				),
				$this->anything()
			)
			->willReturn( 1 );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->log_sent( 100, 200 );

		$this->assertInstanceOf( ReminderLog::class, $result );
		$this->assertSame( 42, $result->id );
		$this->assertSame( 100, $result->occurrence_id );
		$this->assertSame( 200, $result->attendee_id );
		$this->assertSame( '24h_before', $result->reminder_type );
		$this->assertSame( 'sent', $result->status );
		$this->assertSame( '2026-02-04 10:00:00', $result->sent_at );
	}

	/**
	 * Test log_sent returns null on insert failure.
	 *
	 * @covers ::log_sent
	 * @return void
	 */
	public function test_log_sent_returns_null_on_failure(): void {
		$this->mock_db->method( 'insert' )
			->willReturn( false );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->log_sent( 100, 200 );

		$this->assertNull( $result );
	}

	/**
	 * Test log_sent passes through a failed status.
	 *
	 * @covers ::log_sent
	 * @return void
	 */
	public function test_log_sent_with_failed_status(): void {
		$this->mock_db->insert_id = 7;

		$this->mock_db->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $data ) {
						return $data['status'] === 'failed';
					}
				),
				$this->anything()
			)
			->willReturn( 1 );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->log_sent( 100, 200, '24h_before', 'failed' );

		$this->assertInstanceOf( ReminderLog::class, $result );
		$this->assertSame( 'failed', $result->status );
	}

	// =========================================================================
	// has_been_sent() Tests
	// =========================================================================

	/**
	 * Test has_been_sent returns true when a record exists.
	 *
	 * @covers ::has_been_sent
	 * @return void
	 */
	public function test_has_been_sent_returns_true_when_exists(): void {
		$this->mock_db->method( 'get_var' )
			->willReturn( '1' );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->has_been_sent( 100, 200 );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->captured_queries );
		$this->assertStringContainsString( 'nettertech_events_reminder_log', $this->captured_queries[0] );
		$this->assertStringContainsString( 'occurrence_id', $this->captured_queries[0] );
		$this->assertStringContainsString( 'attendee_id', $this->captured_queries[0] );
	}

	/**
	 * Test has_been_sent returns false when no record exists.
	 *
	 * @covers ::has_been_sent
	 * @return void
	 */
	public function test_has_been_sent_returns_false_when_not_exists(): void {
		$this->mock_db->method( 'get_var' )
			->willReturn( null );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->has_been_sent( 100, 200 );

		$this->assertFalse( $result );
		$this->assertStringContainsString( 'nettertech_events_reminder_log', $this->captured_queries[0] );
	}

	// =========================================================================
	// get_sent_attendee_ids() Tests
	// =========================================================================

	/**
	 * Test get_sent_attendee_ids returns integer IDs.
	 *
	 * @covers ::get_sent_attendee_ids
	 * @return void
	 */
	public function test_get_sent_attendee_ids_returns_ids(): void {
		$this->mock_db->method( 'get_col' )
			->willReturn( array( '5', '10', '15' ) );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->get_sent_attendee_ids( 100 );

		$this->assertSame( array( 5, 10, 15 ), $result );
		$this->assertStringContainsString( 'nettertech_events_reminder_log', $this->captured_queries[0] );
		$this->assertStringContainsString( 'attendee_id', $this->captured_queries[0] );
		$this->assertStringContainsString( 'occurrence_id', $this->captured_queries[0] );
	}

	/**
	 * Test get_sent_attendee_ids returns empty array when none exist.
	 *
	 * @covers ::get_sent_attendee_ids
	 * @return void
	 */
	public function test_get_sent_attendee_ids_returns_empty_when_none(): void {
		$this->mock_db->method( 'get_col' )
			->willReturn( array() );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->get_sent_attendee_ids( 100 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// =========================================================================
	// for_occurrence() Tests
	// =========================================================================

	/**
	 * Test for_occurrence returns ReminderLog objects.
	 *
	 * @covers ::for_occurrence
	 * @return void
	 */
	public function test_for_occurrence_returns_logs(): void {
		$row1                = new \stdClass();
		$row1->id            = 1;
		$row1->occurrence_id = 100;
		$row1->attendee_id   = 200;
		$row1->reminder_type = '24h_before';
		$row1->status        = 'sent';
		$row1->sent_at       = '2026-02-03 10:00:00';

		$row2                = new \stdClass();
		$row2->id            = 2;
		$row2->occurrence_id = 100;
		$row2->attendee_id   = 300;
		$row2->reminder_type = '24h_before';
		$row2->status        = 'sent';
		$row2->sent_at       = '2026-02-03 10:05:00';

		$this->mock_db->method( 'get_results' )
			->willReturn( array( $row1, $row2 ) );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->for_occurrence( 100 );

		$this->assertCount( 2, $result );
		$this->assertInstanceOf( ReminderLog::class, $result[0] );
		$this->assertInstanceOf( ReminderLog::class, $result[1] );
		$this->assertSame( 1, $result[0]->id );
		$this->assertSame( 200, $result[0]->attendee_id );
		$this->assertSame( 2, $result[1]->id );
		$this->assertSame( 300, $result[1]->attendee_id );
		$this->assertStringContainsString( 'nettertech_events_reminder_log', $this->captured_queries[0] );
		$this->assertStringContainsString( 'occurrence_id', $this->captured_queries[0] );
		$this->assertStringContainsString( 'ORDER BY sent_at', $this->captured_queries[0] );
	}

	/**
	 * Test for_occurrence returns empty array when no logs exist.
	 *
	 * @covers ::for_occurrence
	 * @return void
	 */
	public function test_for_occurrence_returns_empty_when_none(): void {
		$this->mock_db->method( 'get_results' )
			->willReturn( array() );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->for_occurrence( 100 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// =========================================================================
	// delete_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test delete_for_occurrence returns deleted row count.
	 *
	 * @covers ::delete_for_occurrence
	 * @return void
	 */
	public function test_delete_for_occurrence_returns_count(): void {
		$this->mock_db->expects( $this->once() )
			->method( 'delete' )
			->with(
				$this->stringContains( 'nettertech_events_reminder_log' ),
				$this->callback(
					function ( $where ) {
						return isset( $where['occurrence_id'] );
					}
				),
				$this->anything()
			)
			->willReturn( 3 );

		$repo   = new ReminderLogRepository( $this->mock_db );
		$result = $repo->delete_for_occurrence( 100 );

		$this->assertSame( 3, $result );
	}
}
