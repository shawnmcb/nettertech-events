<?php
/**
 * ActivityLogRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use NetterTechEvents\Repositories\ActivityLogRepository;
use NetterTechEvents\Models\ActivityLog;

/**
 * Test ActivityLogRepository functionality.
 *
 * Tests OWASP A09 security logging repository operations.
 *
 * @coversDefaultClass \NetterTechEvents\Repositories\ActivityLogRepository
 */
class ActivityLogRepositoryTest extends \NetterTechEventsTestCase {

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
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->original_wpdb = $wpdb;

		$this->mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'get_row', 'get_var', 'get_results', 'get_col', 'query', 'prepare' ) )
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

		Functions\when( 'current_time' )->justReturn( '2024-01-15 10:30:00' );
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;

		unset( $_SERVER['HTTP_USER_AGENT'] );
		unset( $_SERVER['REMOTE_ADDR'] );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );

		parent::tearDown();
	}

	/**
	 * Create a mock database row for ActivityLog.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return object
	 */
	private function create_mock_row( array $overrides = array() ): object {
		return (object) array_merge(
			array(
				'id'          => 1,
				'user_id'     => 1,
				'action'      => 'create',
				'object_type' => 'event',
				'object_id'   => 100,
				'object_name' => 'Test Event',
				'details'     => '{"key":"value"}',
				'ip_address'  => '192.168.1.1',
				'user_agent'  => 'Mozilla/5.0',
				'created_at'  => '2024-01-15 10:30:00',
			),
			$overrides
		);
	}

	// =========================================================================
	// Instantiation Tests
	// =========================================================================

	/**
	 * Test repository can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate_repository(): void {
		global $wpdb;
		$repo = new ActivityLogRepository( $wpdb );

		$this->assertInstanceOf( ActivityLogRepository::class, $repo );
	}

	// =========================================================================
	// log() Tests
	// =========================================================================

	/**
	 * Test log creates entry with all parameters.
	 *
	 * @covers ::log
	 * @return void
	 */
	public function test_log_creates_entry_with_all_parameters(): void {
		global $wpdb;
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$_SERVER['REMOTE_ADDR']      = '192.168.1.100';
		$_SERVER['HTTP_USER_AGENT']  = 'Test Browser';

		$this->mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->stringContains( 'activity_log' ),
				$this->callback(
					function ( $data ) {
						return $data['user_id'] === 1
							&& $data['action'] === 'create'
							&& $data['object_type'] === 'event'
							&& $data['object_id'] === 100
							&& $data['object_name'] === 'Test Event'
							&& str_contains( $data['details'], 'key' );
					}
				),
				$this->anything()
			)
			->willReturn( 1 );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->log( 'create', 'event', 100, 'Test Event', array( 'key' => 'value' ) );

		$this->assertSame( 1, $result );
	}

	/**
	 * Test log handles null user ID.
	 *
	 * @covers ::log
	 * @return void
	 */
	public function test_log_handles_null_user_id(): void {
		global $wpdb;
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';

		$this->mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $data ) {
						return $data['user_id'] === null;
					}
				),
				$this->anything()
			)
			->willReturn( 1 );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->log( 'create', 'event' );

		$this->assertSame( 1, $result );
	}

	/**
	 * Test log handles null object ID.
	 *
	 * @covers ::log
	 * @return void
	 */
	public function test_log_handles_null_object_id(): void {
		global $wpdb;
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';

		$this->mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $data ) {
						return $data['object_id'] === null;
					}
				),
				$this->anything()
			)
			->willReturn( 1 );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->log( 'settings_update', 'settings' );

		$this->assertSame( 1, $result );
	}

	/**
	 * Test log returns false on insert failure.
	 *
	 * @covers ::log
	 * @return void
	 */
	public function test_log_returns_false_on_insert_failure(): void {
		global $wpdb;
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';

		$this->mock_wpdb->method( 'insert' )->willReturn( false );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->log( 'create', 'event' );

		$this->assertFalse( $result );
	}

	/**
	 * Test log captures IP from REMOTE_ADDR.
	 *
	 * @covers ::log
	 * @return void
	 */
	public function test_log_captures_ip_from_remote_addr(): void {
		global $wpdb;
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';

		$this->mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $data ) {
						return $data['ip_address'] === '10.0.0.1';
					}
				),
				$this->anything()
			)
			->willReturn( 1 );

		$repo = new ActivityLogRepository( $wpdb );
		$repo->log( 'create', 'event' );
	}

	/**
	 * Test log captures IP from X-Forwarded-For header.
	 *
	 * @covers ::log
	 * @return void
	 */
	public function test_log_captures_ip_from_x_forwarded_for(): void {
		global $wpdb;
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 70.41.3.18';

		$this->mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $data ) {
						return $data['ip_address'] === '203.0.113.50';
					}
				),
				$this->anything()
			)
			->willReturn( 1 );

		$repo = new ActivityLogRepository( $wpdb );
		$repo->log( 'create', 'event' );
	}

	/**
	 * Test log captures IP from Cloudflare header.
	 *
	 * @covers ::log
	 * @return void
	 */
	public function test_log_captures_ip_from_cloudflare(): void {
		global $wpdb;
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.25';
		$_SERVER['REMOTE_ADDR']           = '127.0.0.1';

		$this->mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $data ) {
						return $data['ip_address'] === '198.51.100.25';
					}
				),
				$this->anything()
			)
			->willReturn( 1 );

		$repo = new ActivityLogRepository( $wpdb );
		$repo->log( 'create', 'event' );
	}

	/**
	 * Test log handles missing user agent.
	 *
	 * @covers ::log
	 * @return void
	 */
	public function test_log_handles_missing_user_agent(): void {
		global $wpdb;
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
		unset( $_SERVER['HTTP_USER_AGENT'] );

		$this->mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $data ) {
						return $data['user_agent'] === null;
					}
				),
				$this->anything()
			)
			->willReturn( 1 );

		$repo = new ActivityLogRepository( $wpdb );
		$repo->log( 'create', 'event' );
	}

	// =========================================================================
	// find() Tests
	// =========================================================================

	/**
	 * Test find returns ActivityLog when found.
	 *
	 * @covers ::find
	 * @return void
	 */
	public function test_find_returns_activity_log_when_found(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_row' )
			->willReturn( $this->create_mock_row() );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->find( 1 );

		$this->assertInstanceOf( ActivityLog::class, $result );
		$this->assertSame( 1, $result->id );
		$this->assertSame( 'create', $result->action );
		$this->assertSame( 'event', $result->object_type );
	}

	/**
	 * Test find returns null when not found.
	 *
	 * @covers ::find
	 * @return void
	 */
	public function test_find_returns_null_when_not_found(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_row' )->willReturn( null );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->find( 999 );

		$this->assertNull( $result );
	}

	/**
	 * Test find parses JSON details.
	 *
	 * @covers ::find
	 * @return void
	 */
	public function test_find_parses_json_details(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_row' )
			->willReturn(
				$this->create_mock_row(
					array( 'details' => '{"reason":"Testing","count":5}' )
				)
			);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->find( 1 );

		$this->assertIsArray( $result->details );
		$this->assertSame( 'Testing', $result->details['reason'] );
		$this->assertSame( 5, $result->details['count'] );
	}

	// =========================================================================
	// paginate() Tests
	// =========================================================================

	/**
	 * Test paginate returns correct structure.
	 *
	 * @covers ::paginate
	 * @return void
	 */
	public function test_paginate_returns_correct_structure(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '25' );
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array(
				$this->create_mock_row( array( 'id' => 1 ) ),
				$this->create_mock_row( array( 'id' => 2 ) ),
			)
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->paginate( 1, 20 );

		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'pages', $result );
		$this->assertSame( 25, $result['total'] );
		$this->assertSame( 2, $result['pages'] );
		$this->assertCount( 2, $result['items'] );
	}

	/**
	 * Test paginate with user filter.
	 *
	 * @covers ::paginate
	 * @return void
	 */
	public function test_paginate_filters_by_user(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '5' );
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array( $this->create_mock_row() )
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->paginate( 1, 20, array( 'user_id' => 5 ) );

		$this->assertSame( 5, $result['total'] );
	}

	/**
	 * Test paginate with action filter.
	 *
	 * @covers ::paginate
	 * @return void
	 */
	public function test_paginate_filters_by_action(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '10' );
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array( $this->create_mock_row( array( 'action' => 'delete' ) ) )
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->paginate( 1, 20, array( 'action' => 'delete' ) );

		$this->assertSame( 10, $result['total'] );
		$this->assertSame( 'delete', $result['items'][0]->action );
	}

	/**
	 * Test paginate with object type filter.
	 *
	 * @covers ::paginate
	 * @return void
	 */
	public function test_paginate_filters_by_object_type(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '15' );
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array( $this->create_mock_row( array( 'object_type' => 'attendee' ) ) )
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->paginate( 1, 20, array( 'object_type' => 'attendee' ) );

		$this->assertSame( 15, $result['total'] );
	}

	/**
	 * Test paginate with date range filter.
	 *
	 * @covers ::paginate
	 * @return void
	 */
	public function test_paginate_filters_by_date_range(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '8' );
		$this->mock_wpdb->method( 'get_results' )->willReturn( array() );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->paginate(
			1,
			20,
			array(
				'date_from' => '2024-01-01',
				'date_to'   => '2024-01-31',
			)
		);

		$this->assertSame( 8, $result['total'] );
	}

	/**
	 * Test paginate with search filter.
	 *
	 * @covers ::paginate
	 * @return void
	 */
	public function test_paginate_filters_by_search(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '3' );
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array( $this->create_mock_row( array( 'object_name' => 'Concert Event' ) ) )
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->paginate( 1, 20, array( 'search' => 'Concert' ) );

		$this->assertSame( 3, $result['total'] );
	}

	/**
	 * Test paginate calculates pages correctly.
	 *
	 * @covers ::paginate
	 * @return void
	 */
	public function test_paginate_calculates_pages_correctly(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '100' );
		$this->mock_wpdb->method( 'get_results' )->willReturn( array() );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->paginate( 1, 25 );

		$this->assertSame( 4, $result['pages'] );
	}

	/**
	 * Test paginate with empty results.
	 *
	 * @covers ::paginate
	 * @return void
	 */
	public function test_paginate_handles_empty_results(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '0' );
		$this->mock_wpdb->method( 'get_results' )->willReturn( array() );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->paginate( 1, 20 );

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( 0, $result['pages'] );
		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// get_for_object() Tests
	// =========================================================================

	/**
	 * Test get_for_object returns entries for object.
	 *
	 * @covers ::get_for_object
	 * @return void
	 */
	public function test_get_for_object_returns_entries(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array(
				$this->create_mock_row( array( 'id' => 1, 'action' => 'create' ) ),
				$this->create_mock_row( array( 'id' => 2, 'action' => 'update' ) ),
			)
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_for_object( 'event', 100 );

		$this->assertCount( 2, $result );
		$this->assertInstanceOf( ActivityLog::class, $result[0] );
		$this->assertSame( 'create', $result[0]->action );
		$this->assertSame( 'update', $result[1]->action );
	}

	/**
	 * Test get_for_object respects limit.
	 *
	 * @covers ::get_for_object
	 * @return void
	 */
	public function test_get_for_object_respects_limit(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array( $this->create_mock_row() )
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_for_object( 'event', 100, 10 );

		$this->assertCount( 1, $result );
	}

	/**
	 * Test get_for_object returns empty array when no entries.
	 *
	 * @covers ::get_for_object
	 * @return void
	 */
	public function test_get_for_object_returns_empty_array(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_results' )->willReturn( array() );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_for_object( 'event', 999 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// =========================================================================
	// get_for_user() Tests
	// =========================================================================

	/**
	 * Test get_for_user returns entries for user.
	 *
	 * @covers ::get_for_user
	 * @return void
	 */
	public function test_get_for_user_returns_entries(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array(
				$this->create_mock_row( array( 'id' => 1, 'user_id' => 5 ) ),
				$this->create_mock_row( array( 'id' => 2, 'user_id' => 5 ) ),
			)
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_for_user( 5 );

		$this->assertCount( 2, $result );
		$this->assertSame( 5, $result[0]->user_id );
	}

	/**
	 * Test get_for_user respects limit.
	 *
	 * @covers ::get_for_user
	 * @return void
	 */
	public function test_get_for_user_respects_limit(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array( $this->create_mock_row() )
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_for_user( 1, 25 );

		$this->assertCount( 1, $result );
	}

	/**
	 * Test get_for_user returns empty array when no entries.
	 *
	 * @covers ::get_for_user
	 * @return void
	 */
	public function test_get_for_user_returns_empty_array(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_results' )->willReturn( array() );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_for_user( 999 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// =========================================================================
	// cleanup() Tests
	// =========================================================================

	/**
	 * Test cleanup deletes old entries.
	 *
	 * @covers ::cleanup
	 * @return void
	 */
	public function test_cleanup_deletes_old_entries(): void {
		global $wpdb;
		Functions\when( 'apply_filters' )->returnArg();

		$this->mock_wpdb->expects( $this->once() )
			->method( 'query' )
			->willReturn( 50 );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->cleanup( 90 );

		$this->assertSame( 50, $result );
	}

	/**
	 * Test cleanup uses custom retention period.
	 *
	 * @covers ::cleanup
	 * @return void
	 */
	public function test_cleanup_uses_custom_retention(): void {
		global $wpdb;
		Functions\when( 'apply_filters' )->returnArg();

		$this->mock_wpdb->method( 'query' )->willReturn( 25 );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->cleanup( 30 );

		$this->assertSame( 25, $result );
	}

	/**
	 * Test cleanup applies filter.
	 *
	 * @covers ::cleanup
	 * @return void
	 */
	public function test_cleanup_applies_retention_filter(): void {
		global $wpdb;
		$filter_called = false;
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value, $days ) use ( &$filter_called ) {
				if ( 'nettertech_events_activity_retention' === $tag ) {
					$filter_called = true;
				}
				return $value;
			}
		);

		$this->mock_wpdb->method( 'query' )->willReturn( 0 );

		$repo = new ActivityLogRepository( $wpdb );
		$repo->cleanup( 90 );

		$this->assertTrue( $filter_called );
	}

	/**
	 * Test cleanup returns zero when no entries deleted.
	 *
	 * @covers ::cleanup
	 * @return void
	 */
	public function test_cleanup_returns_zero_when_no_deletions(): void {
		global $wpdb;
		Functions\when( 'apply_filters' )->returnArg();

		$this->mock_wpdb->method( 'query' )->willReturn( 0 );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->cleanup( 90 );

		$this->assertSame( 0, $result );
	}

	// =========================================================================
	// get_action_types() Tests
	// =========================================================================

	/**
	 * Test get_action_types returns distinct actions.
	 *
	 * @covers ::get_action_types
	 * @return void
	 */
	public function test_get_action_types_returns_distinct_actions(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_col' )
			->willReturn( array( 'create', 'delete', 'update' ) );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_action_types();

		$this->assertCount( 3, $result );
		$this->assertContains( 'create', $result );
		$this->assertContains( 'delete', $result );
		$this->assertContains( 'update', $result );
	}

	/**
	 * Test get_action_types returns empty array when no entries.
	 *
	 * @covers ::get_action_types
	 * @return void
	 */
	public function test_get_action_types_returns_empty_array(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_col' )->willReturn( array() );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_action_types();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// =========================================================================
	// get_object_types() Tests
	// =========================================================================

	/**
	 * Test get_object_types returns distinct types.
	 *
	 * @covers ::get_object_types
	 * @return void
	 */
	public function test_get_object_types_returns_distinct_types(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_col' )
			->willReturn( array( 'attendee', 'event', 'occurrence' ) );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_object_types();

		$this->assertCount( 3, $result );
		$this->assertContains( 'event', $result );
		$this->assertContains( 'occurrence', $result );
		$this->assertContains( 'attendee', $result );
	}

	/**
	 * Test get_object_types returns empty array when no entries.
	 *
	 * @covers ::get_object_types
	 * @return void
	 */
	public function test_get_object_types_returns_empty_array(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_col' )->willReturn( array() );

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->get_object_types();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// =========================================================================
	// Integration Tests
	// =========================================================================

	/**
	 * Test log and find integration.
	 *
	 * @covers ::log
	 * @covers ::find
	 * @return void
	 */
	public function test_log_and_find_integration(): void {
		global $wpdb;
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';

		$this->mock_wpdb->method( 'insert' )->willReturn( 1 );
		$this->mock_wpdb->insert_id = 42;

		$repo     = new ActivityLogRepository( $wpdb );
		$log_id   = $repo->log( 'create', 'event', 100, 'Test Event' );

		$this->assertSame( 42, $log_id );

		// Now test find.
		$this->mock_wpdb->method( 'get_row' )
			->willReturn(
				$this->create_mock_row(
					array(
						'id'          => 42,
						'action'      => 'create',
						'object_type' => 'event',
						'object_id'   => 100,
						'object_name' => 'Test Event',
					)
				)
			);

		$found = $repo->find( 42 );

		$this->assertInstanceOf( ActivityLog::class, $found );
		$this->assertSame( 42, $found->id );
		$this->assertSame( 'create', $found->action );
	}

	/**
	 * Test paginate with multiple filters.
	 *
	 * @covers ::paginate
	 * @return void
	 */
	public function test_paginate_with_multiple_filters(): void {
		global $wpdb;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '5' );
		$this->mock_wpdb->method( 'get_results' )->willReturn(
			array(
				$this->create_mock_row(
					array(
						'user_id'     => 1,
						'action'      => 'create',
						'object_type' => 'event',
					)
				),
			)
		);

		$repo   = new ActivityLogRepository( $wpdb );
		$result = $repo->paginate(
			1,
			20,
			array(
				'user_id'     => 1,
				'action'      => 'create',
				'object_type' => 'event',
			)
		);

		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 1, $result['items'] );
	}
}
