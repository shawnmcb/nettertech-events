<?php
/**
 * TicketTypeRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Repositories\TicketTypeStockRepository;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\TicketTypeScope;

/**
 * Test TicketTypeRepository functionality.
 *
 * Tests all repository methods with wpdb mocking.
 */
class TicketTypeRepositoryTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test repository can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate_repository(): void {
		global $wpdb;
		$repo = new TicketTypeRepository( $wpdb );

		$this->assertInstanceOf( TicketTypeRepository::class, $repo );
	}

	/**
	 * Test repository stores wpdb reference.
	 *
	 * @return void
	 */
	public function test_repository_stores_wpdb_reference(): void {
		global $wpdb;
		$repo = new TicketTypeRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );
		$db_prop    = $reflection->getProperty( 'db' );

		$this->assertSame( $wpdb, $db_prop->getValue( $repo ) );
	}

	/**
	 * Test repository initializes table names.
	 *
	 * @return void
	 */
	public function test_repository_initializes_table_names(): void {
		global $wpdb;
		$repo = new TicketTypeRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );

		$table_prop = $reflection->getProperty( 'table' );

		$this->assertStringContainsString( 'ticket_types', $table_prop->getValue( $repo ) );

		// Stock repository handles attendees table.
		$stock_repo_prop = $reflection->getProperty( 'stock_repo' );
		$stock_repo      = $stock_repo_prop->getValue( $repo );
		$this->assertInstanceOf( TicketTypeStockRepository::class, $stock_repo );
	}

	// =========================================================================
	// find() Tests
	// =========================================================================

	/**
	 * Test find returns ticket type when found.
	 *
	 * @return void
	 */
	public function test_find_returns_ticket_type_when_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                  = new \stdClass();
		$row->id              = 1;
		$row->occurrence_id   = 10;
		$row->event_id        = null;
		$row->scope           = 'occurrence';
		$row->template_id     = null;
		$row->name            = 'General Admission';
		$row->description     = 'Standard ticket';
		$row->price           = '25.00';
		$row->capacity_type   = 'fixed';
		$row->capacity        = 100;
		$row->sold_count      = 25;
		$row->stock_status    = 'in_stock';
		$row->sale_start      = '2026-01-01 00:00:00';
		$row->sale_end        = '2026-12-31 23:59:59';
		$row->min_per_order   = 1;
		$row->max_per_order   = 10;
		$row->sort_order      = 1;
		$row->status          = 'active';
		$row->wc_product_id   = 100;
		$row->wc_variation_id = null;
		$row->created_at      = '2026-01-01 00:00:00';
		$row->updated_at      = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->find( 1 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
			$this->assertInstanceOf( TicketType::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertEquals( 'General Admission', $result->name );
			$this->assertEquals( 25.00, $result->price );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_returns_null_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->find( 999 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertNull( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_occurrence() Tests
	// =========================================================================

	/**
	 * Test for_occurrence returns array of ticket types.
	 *
	 * @return void
	 */
	public function test_for_occurrence_returns_ticket_types(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// Return event_id for the occurrence lookup.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '100' );

		$row1                = new \stdClass();
		$row1->id            = 1;
		$row1->occurrence_id = 10;
		$row1->event_id      = 100;
		$row1->scope         = 'occurrence';
		$row1->template_id   = null;
		$row1->name          = 'General Admission';
		$row1->description   = '';
		$row1->price         = '25.00';
		$row1->capacity_type = 'fixed';
		$row1->capacity      = 100;
		$row1->sold_count    = 0;
		$row1->stock_status  = 'in_stock';
		$row1->sale_start    = null;
		$row1->sale_end      = null;
		$row1->min_per_order = 1;
		$row1->max_per_order = 10;
		$row1->sort_order    = 1;
		$row1->status        = 'active';
		$row1->wc_product_id = null;
		$row1->wc_variation_id = null;
		$row1->created_at    = '2026-01-01 00:00:00';
		$row1->updated_at    = '2026-01-01 00:00:00';

		$row2                = clone $row1;
		$row2->id            = 2;
		$row2->name          = 'VIP';
		$row2->price         = '50.00';
		$row2->sort_order    = 2;

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->for_occurrence( 10 );

			$this->assertGreaterThanOrEqual( 2, count( $captured_sqls ) );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[1] );
			$this->assertIsArray( $result );
			$this->assertCount( 2, $result );
			$this->assertInstanceOf( TicketType::class, $result[0] );
			$this->assertEquals( 'General Admission', $result[0]->name );
			$this->assertEquals( 'VIP', $result[1]->name );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_occurrence returns empty array when none found.
	 *
	 * @return void
	 */
	public function test_for_occurrence_returns_empty_array_when_none(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// Return event_id for the occurrence lookup.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '100' );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->for_occurrence( 10 );

			$this->assertGreaterThanOrEqual( 2, count( $captured_sqls ) );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[1] );
			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_occurrence with status filter.
	 *
	 * @return void
	 */
	public function test_for_occurrence_with_status_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// Return event_id for the occurrence lookup.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '100' );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );
			$repo->for_occurrence( 10, array( 'status' => 'active' ) );

			// Second SQL (ticket query) should contain status filter.
			$this->assertCount( 2, $captured_sqls );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[1] );
			$this->assertStringContainsString( 'status = %s', $captured_sqls[1] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_multiple_occurrences() Tests
	// =========================================================================

	/**
	 * Test for_multiple_occurrences returns empty array for empty input.
	 *
	 * @return void
	 */
	public function test_for_multiple_occurrences_returns_empty_for_empty_input(): void {
		global $wpdb;
		$repo   = new TicketTypeRepository( $wpdb );
		$result = $repo->for_multiple_occurrences( array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test for_multiple_occurrences groups by occurrence_id.
	 *
	 * @return void
	 */
	public function test_for_multiple_occurrences_groups_by_occurrence(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		$row1                = new \stdClass();
		$row1->id            = 1;
		$row1->occurrence_id = 10;
		$row1->event_id      = null;
		$row1->scope         = 'occurrence';
		$row1->template_id   = null;
		$row1->name          = 'GA Occ 10';
		$row1->description   = '';
		$row1->price         = '25.00';
		$row1->capacity_type = 'fixed';
		$row1->capacity      = 100;
		$row1->sold_count    = 0;
		$row1->stock_status  = 'in_stock';
		$row1->sale_start    = null;
		$row1->sale_end      = null;
		$row1->min_per_order = 1;
		$row1->max_per_order = 10;
		$row1->sort_order    = 1;
		$row1->status        = 'active';
		$row1->wc_product_id = null;
		$row1->wc_variation_id = null;
		$row1->created_at    = '2026-01-01 00:00:00';
		$row1->updated_at    = '2026-01-01 00:00:00';

		$row2                = clone $row1;
		$row2->id            = 2;
		$row2->occurrence_id = 20;
		$row2->name          = 'GA Occ 20';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->for_multiple_occurrences( array( 10, 20 ) );

			$this->assertGreaterThanOrEqual( 2, count( $captured_sqls ) );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[1] );
			$this->assertArrayHasKey( 10, $result );
			$this->assertArrayHasKey( 20, $result );
			$this->assertCount( 1, $result[10] );
			$this->assertCount( 1, $result[20] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_multiple_occurrences filters out invalid IDs.
	 *
	 * @return void
	 */
	public function test_for_multiple_occurrences_filters_zero_ids(): void {
		global $wpdb;
		$repo   = new TicketTypeRepository( $wpdb );
		$result = $repo->for_multiple_occurrences( array( 0, 0, 0 ) );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// =========================================================================
	// get_active_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test get_active_for_occurrence filters by active status.
	 *
	 * @return void
	 */
	public function test_get_active_for_occurrence_filters_active(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// Return event_id for the occurrence lookup.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '100' );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );
			$repo->get_active_for_occurrence( 10 );

			// Second SQL (ticket query) should include status filter.
			$this->assertCount( 2, $captured_sqls );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[1] );
			$this->assertStringContainsString( 'status = %s', $captured_sqls[1] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_product() Tests
	// =========================================================================

	/**
	 * Test find_by_product returns ticket type.
	 *
	 * @return void
	 */
	public function test_find_by_product_returns_ticket_type(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                  = new \stdClass();
		$row->id              = 1;
		$row->occurrence_id   = 10;
		$row->event_id        = null;
		$row->scope           = 'occurrence';
		$row->template_id     = null;
		$row->name            = 'General Admission';
		$row->description     = '';
		$row->price           = '25.00';
		$row->capacity_type   = 'fixed';
		$row->capacity        = 100;
		$row->sold_count      = 0;
		$row->stock_status    = 'in_stock';
		$row->sale_start      = null;
		$row->sale_end        = null;
		$row->min_per_order   = 1;
		$row->max_per_order   = 10;
		$row->sort_order      = 1;
		$row->status          = 'active';
		$row->wc_product_id   = 500;
		$row->wc_variation_id = null;
		$row->created_at      = '2026-01-01 00:00:00';
		$row->updated_at      = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->find_by_product( 500 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertStringContainsString( 'wc_product_id = %d', $captured_sql );
			$this->assertInstanceOf( TicketType::class, $result );
			$this->assertEquals( 500, $result->wc_product_id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_product returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_product_returns_null_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->find_by_product( 999 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertNull( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_variation() Tests
	// =========================================================================

	/**
	 * Test find_by_variation returns ticket type.
	 *
	 * @return void
	 */
	public function test_find_by_variation_returns_ticket_type(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                  = new \stdClass();
		$row->id              = 1;
		$row->occurrence_id   = 10;
		$row->event_id        = null;
		$row->scope           = 'occurrence';
		$row->template_id     = null;
		$row->name            = 'General Admission';
		$row->description     = '';
		$row->price           = '25.00';
		$row->capacity_type   = 'fixed';
		$row->capacity        = 100;
		$row->sold_count      = 0;
		$row->stock_status    = 'in_stock';
		$row->sale_start      = null;
		$row->sale_end        = null;
		$row->min_per_order   = 1;
		$row->max_per_order   = 10;
		$row->sort_order      = 1;
		$row->status          = 'active';
		$row->wc_product_id   = 500;
		$row->wc_variation_id = 501;
		$row->created_at      = '2026-01-01 00:00:00';
		$row->updated_at      = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->find_by_variation( 501 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertStringContainsString( 'wc_variation_id = %d', $captured_sql );
			$this->assertInstanceOf( TicketType::class, $result );
			$this->assertEquals( 501, $result->wc_variation_id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save throws on invalid ticket type (missing name).
	 *
	 * @return void
	 */
	public function test_save_throws_on_missing_name(): void {
		global $wpdb;
		$repo = new TicketTypeRepository( $wpdb );

		$ticket_type                = new TicketType();
		$ticket_type->occurrence_id = 1;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Ticket type name is required' );

		$repo->save( $ticket_type );
	}

	/**
	 * Test save throws on missing occurrence_id.
	 *
	 * @return void
	 */
	public function test_save_throws_on_missing_occurrence_id(): void {
		global $wpdb;
		$repo = new TicketTypeRepository( $wpdb );

		$ticket_type       = new TicketType();
		$ticket_type->name = 'General Admission';

		$this->expectException( \RuntimeException::class );

		$repo->save( $ticket_type );
	}

	/**
	 * Test save inserts new ticket type.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_ticket_type(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 123;

		$captured_data  = null;
		$captured_table = '';
		$mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturnCallback( function ( $table, $data, $formats ) use ( &$captured_data, &$captured_table ) {
				$captured_table = $table;
				$captured_data  = $data;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );

			$ticket_type                = new TicketType();
			$ticket_type->name          = 'General Admission';
			$ticket_type->occurrence_id = 10;
			$ticket_type->price         = 25.00;

			$saved = $repo->save( $ticket_type );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_table );
			$this->assertEquals( 'General Admission', $captured_data['name'] );
			$this->assertEquals( 10, $captured_data['occurrence_id'] );
			$this->assertEquals( 25.00, $captured_data['price'] );
			$this->assertEquals( 123, $saved->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save fires TICKET_TYPE_CREATED (and not _UPDATED) on insert.
	 *
	 * @return void
	 */
	public function test_save_fires_created_hook_on_insert(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 321;
		$mock_wpdb->method( 'insert' )->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			\Brain\Monkey\Actions\expectDone( 'nettertech_events_ticket_type_created' )
				->once()
				->with( 321, \Mockery::on( fn( $data ) => is_array( $data ) && 'Hook Tier' === $data['name'] ) );
			\Brain\Monkey\Actions\expectDone( 'nettertech_events_ticket_type_updated' )->never();

			$repo = new TicketTypeRepository( $wpdb );

			$ticket_type                = new TicketType();
			$ticket_type->name          = 'Hook Tier';
			$ticket_type->occurrence_id = 10;
			$ticket_type->price         = 5.00;

			$repo->save( $ticket_type );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save fires TICKET_TYPE_UPDATED (and not _CREATED) on update.
	 *
	 * @return void
	 */
	public function test_save_fires_updated_hook_on_update(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';
		$mock_wpdb->method( 'update' )->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			\Brain\Monkey\Actions\expectDone( 'nettertech_events_ticket_type_updated' )
				->once()
				->with( 77, \Mockery::type( 'array' ) );
			\Brain\Monkey\Actions\expectDone( 'nettertech_events_ticket_type_created' )->never();

			$repo = new TicketTypeRepository( $wpdb );

			$ticket_type                = new TicketType();
			$ticket_type->id            = 77;
			$ticket_type->name          = 'Hook Tier';
			$ticket_type->occurrence_id = 10;
			$ticket_type->price         = 5.00;

			$repo->save( $ticket_type );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save updates existing ticket type.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_ticket_type(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_where = null;
		$captured_table = '';
		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->willReturnCallback( function ( $table, $data, $where, $formats, $where_formats ) use ( &$captured_where, &$captured_table ) {
				$captured_table = $table;
				$captured_where = $where;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );

			$ticket_type                = new TicketType();
			$ticket_type->id            = 5;
			$ticket_type->name          = 'Updated Name';
			$ticket_type->occurrence_id = 10;
			$ticket_type->price         = 30.00;

			$saved = $repo->save( $ticket_type );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_table );
			$this->assertEquals( array( 'id' => 5 ), $captured_where );
			$this->assertEquals( 5, $saved->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save throws on insert failure.
	 *
	 * @return void
	 */
	public function test_save_throws_on_insert_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Duplicate entry';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'insert' )
			->with( $this->stringContains( 'nettertech_events_ticket_types' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );

			$ticket_type                = new TicketType();
			$ticket_type->name          = 'General Admission';
			$ticket_type->occurrence_id = 10;

			$this->expectException( \RuntimeException::class );

			$repo->save( $ticket_type );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete returns true on success.
	 *
	 * @return void
	 */
	public function test_delete_returns_true_on_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete', 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		// delete() now loads the row first (to fire TICKET_TYPE_DELETED before removal).
		$mock_wpdb->method( 'prepare' )->willReturn( 'PREPARED' );
		$mock_wpdb->method( 'get_row' )->willReturn(
			(object) array(
				'id'            => 1,
				'name'          => 'General Admission',
				'occurrence_id' => 10,
			)
		);

		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_ticket_types' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->delete( 1 );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test delete returns false on failure.
	 *
	 * @return void
	 */
	public function test_delete_returns_false_on_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete', 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		// delete() now loads the row first (to fire TICKET_TYPE_DELETED before removal).
		$mock_wpdb->method( 'prepare' )->willReturn( 'PREPARED' );
		$mock_wpdb->method( 'get_row' )->willReturn(
			(object) array(
				'id'            => 1,
				'name'          => 'General Admission',
				'occurrence_id' => 10,
			)
		);

		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_ticket_types' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->delete( 1 );

			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test delete_for_occurrence returns count of deleted.
	 *
	 * @return void
	 */
	public function test_delete_for_occurrence_returns_count(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_ticket_types' ), $this->anything(), $this->anything() )
			->willReturn( 5 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->delete_for_occurrence( 10 );

			$this->assertEquals( 5, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test delete_for_occurrence returns zero on failure.
	 *
	 * @return void
	 */
	public function test_delete_for_occurrence_returns_zero_on_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_ticket_types' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->delete_for_occurrence( 10 );

			$this->assertEquals( 0, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_sold_count() Tests
	// =========================================================================

	/**
	 * Test get_sold_count returns integer.
	 *
	 * @return void
	 */
	public function test_get_sold_count_returns_integer(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '25' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->get_sold_count( 1 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertIsInt( $result );
			$this->assertEquals( 25, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_available_count() Tests
	// =========================================================================

	/**
	 * Test get_available_count returns null for unlimited.
	 *
	 * @return void
	 */
	public function test_get_available_count_returns_null_for_unlimited(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( (object) array(
				'capacity'   => null,
				'sold_count' => 50,
			) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->get_available_count( 1 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertNull( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_available_count returns correct count.
	 *
	 * @return void
	 */
	public function test_get_available_count_returns_correct_count(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( (object) array(
				'capacity'   => 100,
				'sold_count' => 25,
			) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->get_available_count( 1 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertEquals( 75, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_available_count returns zero when sold out.
	 *
	 * @return void
	 */
	public function test_get_available_count_returns_zero_when_sold_out(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( (object) array(
				'capacity'   => 100,
				'sold_count' => 100,
			) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->get_available_count( 1 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertEquals( 0, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// has_availability() Tests
	// =========================================================================

	/**
	 * Test has_availability returns true for unlimited capacity.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_true_for_unlimited(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( (object) array(
				'capacity'   => null,
				'sold_count' => 50,
			) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->has_availability( 1, 100 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test has_availability returns true when quantity available.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_true_when_available(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( (object) array(
				'capacity'   => 100,
				'sold_count' => 90,
			) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->has_availability( 1, 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test has_availability returns false when insufficient.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_false_when_insufficient(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( (object) array(
				'capacity'   => 100,
				'sold_count' => 95,
			) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->has_availability( 1, 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// is_sold_out() Tests
	// =========================================================================

	/**
	 * Test is_sold_out returns true for out_of_stock status.
	 *
	 * @return void
	 */
	public function test_is_sold_out_returns_true_for_out_of_stock(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( 'out_of_stock' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->is_sold_out( 1 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test is_sold_out returns false for in_stock status.
	 *
	 * @return void
	 */
	public function test_is_sold_out_returns_false_for_in_stock(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( 'in_stock' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->is_sold_out( 1 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// increment_sold_count() Tests
	// =========================================================================

	/**
	 * Test increment_sold_count returns true on success.
	 *
	 * @return void
	 */
	public function test_increment_sold_count_returns_true_on_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'get_row', 'get_results', 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// The tier, then the peers it shares a room with, then the room's ceiling.
		$mock_wpdb->method( 'get_row' )
			->willReturn( (object) array(
				'id'            => 1,
				'occurrence_id' => 7,
				'event_id'      => null,
			) );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array(
				(object) array(
					'id'            => 1,
					'capacity'      => 100,
					'capacity_type' => 'fixed',
					'sold_count'    => 50,
					'status'        => 'active',
				),
			) );

		$mock_wpdb->method( 'get_var' )->willReturn( null );

		$mock_wpdb->method( 'query' )
			->willReturn( true );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->increment_sold_count( 1, 5 );

			$this->assertNotEmpty( $captured_sqls );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[0] );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test increment_sold_count returns false when exceeds capacity.
	 *
	 * @return void
	 */
	public function test_increment_sold_count_returns_false_when_exceeds_capacity(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'get_row', 'get_results', 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( (object) array(
				'id'            => 1,
				'occurrence_id' => 7,
				'event_id'      => null,
			) );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array(
				(object) array(
					'id'            => 1,
					'capacity'      => 100,
					'capacity_type' => 'fixed',
					'sold_count'    => 98,
					'status'        => 'active',
				),
			) );

		$mock_wpdb->method( 'get_var' )->willReturn( null );

		$mock_wpdb->method( 'query' )
			->willReturn( true );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->increment_sold_count( 1, 5 );

			$this->assertNotEmpty( $captured_sqls );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[0] );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test increment_sold_count returns false when ticket not found.
	 *
	 * @return void
	 */
	public function test_increment_sold_count_returns_false_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$mock_wpdb->method( 'query' )
			->willReturn( true );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->increment_sold_count( 999, 5 );

			$this->assertNotEmpty( $captured_sqls );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[0] );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// decrement_sold_count() Tests
	// =========================================================================

	/**
	 * Test decrement_sold_count returns true on success.
	 *
	 * @return void
	 */
	public function test_decrement_sold_count_returns_true_on_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( (object) array( 'id' => 1 ) );

		$mock_wpdb->method( 'query' )
			->willReturn( true );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->decrement_sold_count( 1, 5 );

			$this->assertNotEmpty( $captured_sqls );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[0] );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// recalculate_sold_count() Tests
	// =========================================================================

	/**
	 * Test recalculate_sold_count returns true on success.
	 *
	 * @return void
	 */
	public function test_recalculate_sold_count_returns_true_on_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'query' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->recalculate_sold_count( 1 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// update_status() Tests
	// =========================================================================

	/**
	 * Test update_status returns false for invalid status.
	 *
	 * @return void
	 */
	public function test_update_status_returns_false_for_invalid_status(): void {
		global $wpdb;
		$repo   = new TicketTypeRepository( $wpdb );
		$result = $repo->update_status( 1, 'invalid_status' );

		$this->assertFalse( $result );
	}

	/**
	 * Test update_status returns true for valid status.
	 *
	 * @return void
	 */
	public function test_update_status_returns_true_for_valid_status(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_ticket_types' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->update_status( 1, 'active' );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Data provider for valid statuses.
	 *
	 * @return array<array<string>>
	 */
	public static function valid_statuses_provider(): array {
		return array(
			'active'   => array( 'active' ),
			'inactive' => array( 'inactive' ),
			'sold_out' => array( 'sold_out' ),
		);
	}

	/**
	 * Test update_status accepts all valid statuses.
	 *
	 * @dataProvider valid_statuses_provider
	 *
	 * @param string $status Status to test.
	 * @return void
	 */
	public function test_update_status_accepts_valid_statuses( string $status ): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_ticket_types' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->update_status( 1, $status );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// link_to_product() Tests
	// =========================================================================

	/**
	 * Test link_to_product returns true on success.
	 *
	 * @return void
	 */
	public function test_link_to_product_returns_true_on_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_data  = null;
		$captured_table = '';
		$mock_wpdb->method( 'update' )
			->willReturnCallback( function ( $table, $data, $where ) use ( &$captured_data, &$captured_table ) {
				$captured_table = $table;
				$captured_data  = $data;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->link_to_product( 1, 500, 501 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_table );
			$this->assertTrue( $result );
			$this->assertEquals( 500, $captured_data['wc_product_id'] );
			$this->assertEquals( 501, $captured_data['wc_variation_id'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test link_to_product with null variation.
	 *
	 * @return void
	 */
	public function test_link_to_product_with_null_variation(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_data  = null;
		$captured_table = '';
		$mock_wpdb->method( 'update' )
			->willReturnCallback( function ( $table, $data, $where ) use ( &$captured_data, &$captured_table ) {
				$captured_table = $table;
				$captured_data  = $data;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->link_to_product( 1, 500, null );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_table );
			$this->assertTrue( $result );
			$this->assertEquals( 500, $captured_data['wc_product_id'] );
			$this->assertNull( $captured_data['wc_variation_id'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// occurrence_has_free_tickets() Tests
	// =========================================================================

	/**
	 * Test occurrence_has_free_tickets returns true when free tickets exist.
	 *
	 * @return void
	 */
	public function test_occurrence_has_free_tickets_returns_true(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '2' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->occurrence_has_free_tickets( 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test occurrence_has_free_tickets returns false when no free tickets.
	 *
	 * @return void
	 */
	public function test_occurrence_has_free_tickets_returns_false(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->occurrence_has_free_tickets( 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// occurrence_is_free() Tests
	// =========================================================================

	/**
	 * Test occurrence_is_free returns true when all tickets are free.
	 *
	 * @return void
	 */
	public function test_occurrence_is_free_returns_true(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// Returns 0 paid tickets = all are free.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->occurrence_is_free( 10 );

			$this->assertNotEmpty( $captured_sqls );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sqls[0] );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test occurrence_is_free returns false when paid tickets exist.
	 *
	 * @return void
	 */
	public function test_occurrence_is_free_returns_false(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// Returns 1 paid ticket = not all free.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '1' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->occurrence_is_free( 10 );

			$this->assertGreaterThanOrEqual( 2, count( $captured_sqls ) );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[1] );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_event() Tests
	// =========================================================================

	/**
	 * Test for_event returns array of ticket types.
	 *
	 * @return void
	 */
	public function test_for_event_returns_ticket_types(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                 = new \stdClass();
		$row->id             = 1;
		$row->occurrence_id  = null;
		$row->event_id       = 5;
		$row->scope          = 'event';
		$row->template_id    = null;
		$row->name           = 'Event Ticket';
		$row->description    = '';
		$row->price          = '25.00';
		$row->capacity_type  = 'fixed';
		$row->capacity       = 100;
		$row->sold_count     = 0;
		$row->stock_status   = 'in_stock';
		$row->sale_start     = null;
		$row->sale_end       = null;
		$row->min_per_order  = 1;
		$row->max_per_order  = 10;
		$row->sort_order     = 1;
		$row->status         = 'active';
		$row->wc_product_id  = null;
		$row->wc_variation_id = null;
		$row->created_at     = '2026-01-01 00:00:00';
		$row->updated_at     = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->for_event( 5 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertIsArray( $result );
			$this->assertCount( 1, $result );
			$this->assertEquals( 'Event Ticket', $result[0]->name );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_event with scope filter.
	 *
	 * @return void
	 */
	public function test_for_event_with_scope_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );
			$repo->for_event( 5, array( 'scope' => 'template' ) );

			// SQL should include scope filter.
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertStringContainsString( 'scope = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_templates() Tests
	// =========================================================================

	/**
	 * Test get_templates returns template ticket types.
	 *
	 * @return void
	 */
	public function test_get_templates_returns_templates(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );
			$repo->get_templates( 5 );

			// SQL should filter by template scope.
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertStringContainsString( 'scope = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// create_from_template() Tests
	// =========================================================================

	/**
	 * Test create_from_template creates new ticket type from template.
	 *
	 * @return void
	 */
	public function test_create_from_template_creates_ticket(): void {
		global $wpdb;
		$template                = new TicketType();
		$template->id            = 100;
		$template->scope         = TicketTypeScope::TEMPLATE->value;
		$template->event_id      = 5;
		$template->name          = 'Template Ticket';
		$template->description   = 'Template description';
		$template->price         = 25.00;
		$template->capacity_type = 'fixed';
		$template->capacity      = 100;
		$template->min_per_order = 1;
		$template->max_per_order = 10;
		$template->sort_order    = 1;
		$template->status        = 'active';

		$repo   = new TicketTypeRepository( $wpdb );
		$result = $repo->create_from_template( $template, 20 );

		$this->assertInstanceOf( TicketType::class, $result );
		$this->assertNull( $result->id );
		$this->assertEquals( TicketTypeScope::OCCURRENCE->value, $result->scope );
		$this->assertEquals( 20, $result->occurrence_id );
		$this->assertEquals( 100, $result->template_id );
		$this->assertEquals( 'Template Ticket', $result->name );
		$this->assertEquals( 25.00, $result->price );
		$this->assertEquals( 0, $result->sold_count );
		$this->assertEquals( 'in_stock', $result->stock_status );
	}

	/**
	 * Test create_from_template throws on non-template scope.
	 *
	 * @return void
	 */
	public function test_create_from_template_throws_on_non_template(): void {
		global $wpdb;
		$non_template        = new TicketType();
		$non_template->scope = TicketTypeScope::OCCURRENCE->value;

		$repo = new TicketTypeRepository( $wpdb );

		$this->expectException( \NetterTechEvents\Exceptions\ValidationException::class );
		$this->expectExceptionMessage( 'Expected template scope' );

		$repo->create_from_template( $non_template, 20 );
	}

	// =========================================================================
	// Shared Capacity Method Tests
	// =========================================================================

	/**
	 * Test get_fixed_capacity_sum returns correct sum.
	 *
	 * @return void
	 */
	public function test_get_fixed_capacity_sum_returns_sum(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '250' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->get_fixed_capacity_sum( 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertIsInt( $result );
			$this->assertEquals( 250, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_shared_sold_count returns correct sum.
	 *
	 * @return void
	 */
	public function test_get_shared_sold_count_returns_sum(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '75' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->get_shared_sold_count( 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertIsInt( $result );
			$this->assertEquals( 75, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_shared_for_occurrence returns shared ticket types.
	 *
	 * @return void
	 */
	public function test_get_shared_for_occurrence_returns_shared(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                 = new \stdClass();
		$row->id             = 1;
		$row->occurrence_id  = 10;
		$row->event_id       = null;
		$row->scope          = 'occurrence';
		$row->template_id    = null;
		$row->name           = 'Shared Ticket';
		$row->description    = '';
		$row->price          = '25.00';
		$row->capacity_type  = 'shared';
		$row->capacity       = null;
		$row->sold_count     = 0;
		$row->stock_status   = 'in_stock';
		$row->sale_start     = null;
		$row->sale_end       = null;
		$row->min_per_order  = 1;
		$row->max_per_order  = 10;
		$row->sort_order     = 1;
		$row->status         = 'active';
		$row->wc_product_id  = null;
		$row->wc_variation_id = null;
		$row->created_at     = '2026-01-01 00:00:00';
		$row->updated_at     = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->get_shared_for_occurrence( 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertIsArray( $result );
			$this->assertCount( 1, $result );
			$this->assertEquals( 'shared', $result[0]->capacity_type );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test has_unlimited_fixed_tickets returns true when exists.
	 *
	 * @return void
	 */
	public function test_has_unlimited_fixed_tickets_returns_true(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '1' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->has_unlimited_fixed_tickets( 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test has_unlimited_fixed_tickets returns false when none.
	 *
	 * @return void
	 */
	public function test_has_unlimited_fixed_tickets_returns_false(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->has_unlimited_fixed_tickets( 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test has_unlimited_tickets returns true when exists.
	 *
	 * @return void
	 */
	public function test_has_unlimited_tickets_returns_true(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '1' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->has_unlimited_tickets( 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test has_unlimited_tickets returns false when none.
	 *
	 * @return void
	 */
	public function test_has_unlimited_tickets_returns_false(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->has_unlimited_tickets( 10 );

			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// Method Existence Tests (for documentation)
	// =========================================================================

	/**
	 * Data provider for method existence.
	 *
	 * @return array<array{string, array<string>}>
	 */
	public static function method_existence_provider(): array {
		return array(
			'find'                        => array( 'find', array( 'int' ) ),
			'for_occurrence'              => array( 'for_occurrence', array( 'int', 'array' ) ),
			'for_multiple_occurrences'    => array( 'for_multiple_occurrences', array( 'array', '?string' ) ),
			'get_active_for_occurrence'   => array( 'get_active_for_occurrence', array( 'int' ) ),
			'get_on_sale_for_occurrence'  => array( 'get_on_sale_for_occurrence', array( 'int' ) ),
			'find_by_product'             => array( 'find_by_product', array( 'int' ) ),
			'find_by_variation'           => array( 'find_by_variation', array( 'int' ) ),
			'save'                        => array( 'save', array( TicketType::class ) ),
			'delete'                      => array( 'delete', array( 'int' ) ),
			'delete_for_occurrence'       => array( 'delete_for_occurrence', array( 'int' ) ),
			'get_sold_count'              => array( 'get_sold_count', array( 'int' ) ),
			'get_available_count'         => array( 'get_available_count', array( 'int' ) ),
			'has_availability'            => array( 'has_availability', array( 'int', 'int' ) ),
			'is_sold_out'                 => array( 'is_sold_out', array( 'int' ) ),
			'increment_sold_count'        => array( 'increment_sold_count', array( 'int', 'int' ) ),
			'decrement_sold_count'        => array( 'decrement_sold_count', array( 'int', 'int' ) ),
			'recalculate_sold_count'      => array( 'recalculate_sold_count', array( 'int' ) ),
			'update_status'               => array( 'update_status', array( 'int', 'string' ) ),
			'link_to_product'             => array( 'link_to_product', array( 'int', 'int', '?int' ) ),
			'occurrence_has_free_tickets' => array( 'occurrence_has_free_tickets', array( 'int' ) ),
			'occurrence_is_free'          => array( 'occurrence_is_free', array( 'int' ) ),
			'for_event'                   => array( 'for_event', array( 'int', 'array' ) ),
			'get_templates'               => array( 'get_templates', array( 'int', 'array' ) ),
			'create_from_template'        => array( 'create_from_template', array( TicketType::class, 'int' ) ),
			'get_fixed_capacity_sum'      => array( 'get_fixed_capacity_sum', array( 'int' ) ),
			'get_shared_sold_count'       => array( 'get_shared_sold_count', array( 'int' ) ),
			'get_shared_for_occurrence'   => array( 'get_shared_for_occurrence', array( 'int' ) ),
			'has_unlimited_fixed_tickets' => array( 'has_unlimited_fixed_tickets', array( 'int' ) ),
			'has_unlimited_tickets'       => array( 'has_unlimited_tickets', array( 'int' ) ),
		);
	}

	/**
	 * Test repository methods exist.
	 *
	 * @dataProvider method_existence_provider
	 *
	 * @param string        $method Method name.
	 * @param array<string> $params Expected parameter types (unused, for documentation).
	 * @return void
	 */
	public function test_method_exists( string $method, array $params ): void {
		global $wpdb;
		$repo = new TicketTypeRepository( $wpdb );

		$this->assertTrue(
			method_exists( $repo, $method ),
			"Method {$method} should exist on TicketTypeRepository"
		);
	}

	// =========================================================================
	// save() Update Failure Tests
	// =========================================================================

	/**
	 * Test save throws on update failure.
	 *
	 * @return void
	 */
	public function test_save_throws_on_update_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Row not found';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_ticket_types' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );

			$ticket_type                = new TicketType();
			$ticket_type->id            = 5;
			$ticket_type->name          = 'Updated Name';
			$ticket_type->occurrence_id = 10;
			$ticket_type->price         = 30.00;

			$this->expectException( \RuntimeException::class );

			$repo->save( $ticket_type );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// format_db_error() Non-Debug Path Tests
	// =========================================================================

	/**
	 * Test save throws with debug error message when WP_DEBUG is true.
	 *
	 * Exercises the format_db_error debug branch via an update failure with a
	 * non-empty last_error message.
	 *
	 * @return void
	 */
	public function test_save_update_failure_includes_error_detail(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Deadlock found';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_ticket_types' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );

			$ticket_type                = new TicketType();
			$ticket_type->id            = 99;
			$ticket_type->name          = 'General Admission';
			$ticket_type->occurrence_id = 10;

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Failed to update ticket type' );

			$repo->save( $ticket_type );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_occurrence() Cache and Edge Case Tests
	// =========================================================================

	/**
	 * Test for_occurrence returns empty array when event_id not found.
	 *
	 * @return void
	 */
	public function test_for_occurrence_returns_empty_when_no_event(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// Return null event_id for the occurrence lookup.
		$mock_wpdb->method( 'get_var' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->for_occurrence( 999 );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_occurrence uses cache on default args.
	 *
	 * @return void
	 */
	public function test_for_occurrence_returns_cached_results(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		// Return event_id for the occurrence lookup.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '100' );

		$wpdb = $mock_wpdb;

		// Prepare cached row data.
		$row                   = new \stdClass();
		$row->id               = 1;
		$row->occurrence_id    = 10;
		$row->event_id         = 100;
		$row->scope            = 'occurrence';
		$row->template_id      = null;
		$row->name             = 'Cached Ticket';
		$row->description      = '';
		$row->price            = '25.00';
		$row->capacity_type    = 'fixed';
		$row->capacity         = 100;
		$row->sold_count       = 0;
		$row->stock_status     = 'in_stock';
		$row->sale_start       = null;
		$row->sale_end         = null;
		$row->min_per_order    = 1;
		$row->max_per_order    = 10;
		$row->sort_order       = 1;
		$row->status           = 'active';
		$row->wc_product_id    = null;
		$row->wc_variation_id  = null;
		$row->created_at       = '2026-01-01 00:00:00';
		$row->updated_at       = '2026-01-01 00:00:00';

		// Override wp_cache_get to return cached data for the right key.
		\Brain\Monkey\Functions\when( 'wp_cache_get' )
			->alias( function ( $key, $group = '' ) use ( $row ) {
				if ( 'ticket_types_occurrence_10' === $key ) {
					return array( $row );
				}
				return false;
			} );

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->for_occurrence( 10 );

			$this->assertIsArray( $result );
			$this->assertCount( 1, $result );
			$this->assertInstanceOf( TicketType::class, $result[0] );
			$this->assertEquals( 'Cached Ticket', $result[0]->name );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_multiple_occurrences() Additional Tests
	// =========================================================================

	/**
	 * Test for_multiple_occurrences returns empty when no event mapping.
	 *
	 * @return void
	 */
	public function test_for_multiple_occurrences_empty_event_map(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// First call returns empty event mapping; second call won't happen.
		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->for_multiple_occurrences( array( 10, 20 ) );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_multiple_occurrences with status filter.
	 *
	 * @return void
	 */
	public function test_for_multiple_occurrences_with_status_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		$call_count = 0;

		// First call: event mapping, second call: ticket query.
		$occ_row1           = new \stdClass();
		$occ_row1->id       = 10;
		$occ_row1->event_id = '100';

		$ticket_row                   = new \stdClass();
		$ticket_row->id               = 1;
		$ticket_row->occurrence_id    = 10;
		$ticket_row->event_id         = 100;
		$ticket_row->scope            = 'occurrence';
		$ticket_row->template_id      = null;
		$ticket_row->name             = 'Active Ticket';
		$ticket_row->description      = '';
		$ticket_row->price            = '25.00';
		$ticket_row->capacity_type    = 'fixed';
		$ticket_row->capacity         = 100;
		$ticket_row->sold_count       = 0;
		$ticket_row->stock_status     = 'in_stock';
		$ticket_row->sale_start       = null;
		$ticket_row->sale_end         = null;
		$ticket_row->min_per_order    = 1;
		$ticket_row->max_per_order    = 10;
		$ticket_row->sort_order       = 1;
		$ticket_row->status           = 'active';
		$ticket_row->wc_product_id    = null;
		$ticket_row->wc_variation_id  = null;
		$ticket_row->created_at       = '2026-01-01 00:00:00';
		$ticket_row->updated_at       = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturnCallback( function ( $sql, $output = OBJECT ) use ( &$call_count, $occ_row1, $ticket_row ) {
				++$call_count;
				if ( 1 === $call_count ) {
					// OBJECT_K: keyed by first column (id).
					return array( 10 => $occ_row1 );
				}
				return array( $ticket_row );
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->for_multiple_occurrences( array( 10 ), 'active' );

			// Verify status filter was included in second SQL.
			$this->assertCount( 2, $captured_sqls );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[1] );
			$this->assertStringContainsString( 'status = %s', $captured_sqls[1] );

			$this->assertArrayHasKey( 10, $result );
			$this->assertCount( 1, $result[10] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_multiple_occurrences maps event-level tickets to occurrences.
	 *
	 * When a ticket type has occurrence_id = null and event_id set,
	 * it should be distributed to all matching occurrences.
	 *
	 * @return void
	 */
	public function test_for_multiple_occurrences_maps_event_tickets(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		$call_count = 0;

		// Two occurrences sharing the same event (keyed by id for OBJECT_K).
		$occ_row1           = new \stdClass();
		$occ_row1->id       = 10;
		$occ_row1->event_id = '100';

		$occ_row2           = new \stdClass();
		$occ_row2->id       = 20;
		$occ_row2->event_id = '100';

		// Event-level ticket type (occurrence_id is null).
		$event_ticket                   = new \stdClass();
		$event_ticket->id               = 5;
		$event_ticket->occurrence_id    = null;
		$event_ticket->event_id         = 100;
		$event_ticket->scope            = 'event';
		$event_ticket->template_id      = null;
		$event_ticket->name             = 'Event-Level GA';
		$event_ticket->description      = '';
		$event_ticket->price            = '20.00';
		$event_ticket->capacity_type    = 'fixed';
		$event_ticket->capacity         = 200;
		$event_ticket->sold_count       = 0;
		$event_ticket->stock_status     = 'in_stock';
		$event_ticket->sale_start       = null;
		$event_ticket->sale_end         = null;
		$event_ticket->min_per_order    = 1;
		$event_ticket->max_per_order    = 10;
		$event_ticket->sort_order       = 1;
		$event_ticket->status           = 'active';
		$event_ticket->wc_product_id    = null;
		$event_ticket->wc_variation_id  = null;
		$event_ticket->created_at       = '2026-01-01 00:00:00';
		$event_ticket->updated_at       = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturnCallback( function ( $sql, $output = OBJECT ) use ( &$call_count, $occ_row1, $occ_row2, $event_ticket ) {
				++$call_count;
				if ( 1 === $call_count ) {
					// OBJECT_K: keyed by first column (id).
					return array( 10 => $occ_row1, 20 => $occ_row2 );
				}
				return array( $event_ticket );
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->for_multiple_occurrences( array( 10, 20 ) );

			$this->assertGreaterThanOrEqual( 2, count( $captured_sqls ) );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[1] );
			// Event-level ticket should appear under both occurrences.
			$this->assertArrayHasKey( 10, $result );
			$this->assertArrayHasKey( 20, $result );
			$this->assertCount( 1, $result[10] );
			$this->assertCount( 1, $result[20] );
			$this->assertEquals( 'Event-Level GA', $result[10][0]->name );
			$this->assertEquals( 'Event-Level GA', $result[20][0]->name );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_on_sale_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test get_on_sale_for_occurrence filters by on-sale status.
	 *
	 * @return void
	 */
	public function test_get_on_sale_for_occurrence_returns_on_sale(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		// The window is read in the occurrence's zone, falling back to the site's (NTE-148).
		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// Return event_id for the occurrence lookup.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '100' );

		// Active ticket with no sale window restrictions (on sale).
		$row1                   = new \stdClass();
		$row1->id               = 1;
		$row1->occurrence_id    = 10;
		$row1->event_id         = 100;
		$row1->scope            = 'occurrence';
		$row1->template_id      = null;
		$row1->name             = 'On Sale Ticket';
		$row1->description      = '';
		$row1->price            = '25.00';
		$row1->capacity_type    = 'fixed';
		$row1->capacity         = 100;
		$row1->sold_count       = 0;
		$row1->stock_status     = 'in_stock';
		$row1->sale_start       = null;
		$row1->sale_end         = null;
		$row1->min_per_order    = 1;
		$row1->max_per_order    = 10;
		$row1->sort_order       = 1;
		$row1->status           = 'active';
		$row1->wc_product_id    = null;
		$row1->wc_variation_id  = null;
		$row1->created_at       = '2026-01-01 00:00:00';
		$row1->updated_at       = '2026-01-01 00:00:00';

		// Active ticket with sale_end in the past (not on sale).
		$row2                   = clone $row1;
		$row2->id               = 2;
		$row2->name             = 'Expired Ticket';
		$row2->sale_end         = '2020-01-01 00:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->get_on_sale_for_occurrence( 10 );

			$this->assertGreaterThanOrEqual( 2, count( $captured_sqls ) );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sqls[1] );
			$this->assertIsArray( $result );
			// Only the ticket without expired sale_end should be returned.
			$this->assertCount( 1, $result );
			$this->assertEquals( 'On Sale Ticket', $result[0]->name );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_event() Status Filter Tests
	// =========================================================================

	/**
	 * Test for_event with status filter includes status in SQL.
	 *
	 * @return void
	 */
	public function test_for_event_with_status_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );
			$repo->for_event( 5, array( 'status' => 'active' ) );

			// SQL should include status filter.
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// occurrence_is_free() Edge Case Tests
	// =========================================================================

	/**
	 * Test occurrence_is_free returns true when no event found.
	 *
	 * @return void
	 */
	public function test_occurrence_is_free_returns_true_when_no_event(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// No event_id found for the occurrence.
		$mock_wpdb->method( 'get_var' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketTypeRepository( $wpdb );
			$result = $repo->occurrence_is_free( 999 );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// Identity Map Tests (remember/forget)
	// =========================================================================

	/**
	 * Test find uses identity map on second call.
	 *
	 * Kills mutant: removal of $this->remember($id, $ticket_type) in find().
	 *
	 * @return void
	 */
	public function test_find_uses_identity_map_on_second_call(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$row                  = new \stdClass();
		$row->id              = 1;
		$row->occurrence_id   = 10;
		$row->event_id        = null;
		$row->scope           = 'occurrence';
		$row->template_id     = null;
		$row->name            = 'General Admission';
		$row->description     = 'Standard ticket';
		$row->price           = '25.00';
		$row->capacity_type   = 'fixed';
		$row->capacity        = 100;
		$row->sold_count      = 25;
		$row->stock_status    = 'in_stock';
		$row->sale_start      = '2026-01-01 00:00:00';
		$row->sale_end        = '2026-12-31 23:59:59';
		$row->min_per_order   = 1;
		$row->max_per_order   = 10;
		$row->sort_order      = 1;
		$row->status          = 'active';
		$row->wc_product_id   = 100;
		$row->wc_variation_id = null;
		$row->created_at      = '2026-01-01 00:00:00';
		$row->updated_at      = '2026-01-01 00:00:00';

		// get_row should only be called once; second call uses identity map.
		$mock_wpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );

			$tt1 = $repo->find( 1 );
			$tt2 = $repo->find( 1 );

			$this->assertSame( $tt1, $tt2 );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save invalidates identity map via forget().
	 *
	 * Kills mutant: removal of $this->forget($ticket_type->id) in save().
	 *
	 * @return void
	 */
	public function test_save_invalidates_identity_map(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'get_var', 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$row                  = new \stdClass();
		$row->id              = 1;
		$row->occurrence_id   = 10;
		$row->event_id        = null;
		$row->scope           = 'occurrence';
		$row->template_id     = null;
		$row->name            = 'General Admission';
		$row->description     = 'Standard ticket';
		$row->price           = '25.00';
		$row->capacity_type   = 'fixed';
		$row->capacity        = 100;
		$row->sold_count      = 25;
		$row->stock_status    = 'in_stock';
		$row->sale_start      = '2026-01-01 00:00:00';
		$row->sale_end        = '2026-12-31 23:59:59';
		$row->min_per_order   = 1;
		$row->max_per_order   = 10;
		$row->sort_order      = 1;
		$row->status          = 'active';
		$row->wc_product_id   = 100;
		$row->wc_variation_id = null;
		$row->created_at      = '2026-01-01 00:00:00';
		$row->updated_at      = '2026-01-01 00:00:00';

		$db_call_count = 0;
		$mock_wpdb->method( 'get_row' )
			->willReturnCallback( function () use ( $row, &$db_call_count ) {
				++$db_call_count;
				return $row;
			} );

		$mock_wpdb->method( 'update' )->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketTypeRepository( $wpdb );

			// Populate identity map.
			$tt = $repo->find( 1 );
			$this->assertSame( 1, $db_call_count );

			// Verify identity map is used.
			$repo->find( 1 );
			$this->assertSame( 1, $db_call_count );

			// Save (update) should invalidate identity map.
			$tt->name = 'VIP';
			$repo->save( $tt );

			// Next find should hit DB again.
			$repo->find( 1 );
			$this->assertSame( 2, $db_call_count );
		} finally {
			$wpdb = $original_wpdb;
		}
	}
}
