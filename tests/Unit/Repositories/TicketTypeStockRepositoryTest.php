<?php
/**
 * TicketTypeStockRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\TicketTypeStockRepository;
use NetterTechEvents\Enums\CapacityType;

/**
 * Test TicketTypeStockRepository functionality.
 *
 * Tests all repository methods with wpdb mocking.
 */
class TicketTypeStockRepositoryTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Create a mock wpdb and instantiate the repository.
	 *
	 * Sets the global $wpdb so Schema::table() resolves correctly, then
	 * returns the mock, the repository, and the original $wpdb for cleanup.
	 *
	 * @param array $methods Methods to mock on wpdb.
	 * @return array{0: \wpdb, 1: TicketTypeStockRepository, 2: \wpdb} [$mock_wpdb, $repo, $original_wpdb]
	 */
	private function create_repo( array $methods = array( 'get_var', 'get_row', 'get_results', 'query', 'prepare' ) ): array {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( $methods )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		// Pass-through prepare so SQL is available for assertions.
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$wpdb = $mock_wpdb;
		$repo = new TicketTypeStockRepository( $mock_wpdb );

		return array( $mock_wpdb, $repo, $original_wpdb );
	}

	/**
	 * Restore the original global $wpdb.
	 *
	 * @param \wpdb $original_wpdb The original global wpdb instance.
	 * @return void
	 */
	private function restore_wpdb( \wpdb $original_wpdb ): void {
		global $wpdb;
		$wpdb = $original_wpdb;
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test repository can be instantiated.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::__construct
	 * @return void
	 */
	public function test_can_instantiate_repository(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$this->assertInstanceOf( TicketTypeStockRepository::class, $repo );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test repository initializes table names from prefix.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::__construct
	 * @return void
	 */
	public function test_repository_initializes_table_names(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$reflection = new \ReflectionClass( $repo );

			$table_prop = $reflection->getProperty( 'table' );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $table_prop->getValue( $repo ) );

			$attendees_prop = $reflection->getProperty( 'attendees_table' );
			$this->assertStringContainsString( 'nettertech_events_attendees', $attendees_prop->getValue( $repo ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_sold_count() Tests
	// =========================================================================

	/**
	 * Test get_sold_count returns count from database.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_sold_count
	 * @return void
	 */
	public function test_get_sold_count_returns_count(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '15' );

			$result = $repo->get_sold_count( 1 );

			$this->assertSame( 15, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_sold_count returns 0 when database returns null.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_sold_count
	 * @return void
	 */
	public function test_get_sold_count_returns_zero_when_null(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( null );

			$result = $repo->get_sold_count( 1 );

			$this->assertSame( 0, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_available_count() Tests
	// =========================================================================

	/**
	 * Test get_available_count returns null for unlimited capacity.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_available_count
	 * @return void
	 */
	public function test_get_available_count_returns_null_for_unlimited(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row             = new \stdClass();
			$row->capacity   = null;
			$row->sold_count = 10;

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$result = $repo->get_available_count( 1 );

			$this->assertNull( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_available_count returns null when row not found.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_available_count
	 * @return void
	 */
	public function test_get_available_count_returns_null_when_not_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )
				->willReturn( null );

			$result = $repo->get_available_count( 999 );

			$this->assertNull( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_available_count calculates remaining for limited capacity.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_available_count
	 * @return void
	 */
	public function test_get_available_count_calculates_remaining(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row             = new \stdClass();
			$row->capacity   = 100;
			$row->sold_count = 75;

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$result = $repo->get_available_count( 1 );

			$this->assertSame( 25, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_available_count returns zero when sold equals capacity.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_available_count
	 * @return void
	 */
	public function test_get_available_count_returns_zero_when_sold_out(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row             = new \stdClass();
			$row->capacity   = 50;
			$row->sold_count = 50;

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$result = $repo->get_available_count( 1 );

			$this->assertSame( 0, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_available_count floors at zero when oversold.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_available_count
	 * @return void
	 */
	public function test_get_available_count_floors_at_zero_when_oversold(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row             = new \stdClass();
			$row->capacity   = 50;
			$row->sold_count = 55;

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$result = $repo->get_available_count( 1 );

			$this->assertSame( 0, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// has_availability() Tests
	// =========================================================================

	/**
	 * Test has_availability returns true for unlimited capacity.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_availability
	 * @return void
	 */
	public function test_has_availability_returns_true_for_unlimited(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row             = new \stdClass();
			$row->capacity   = null;
			$row->sold_count = 10000;

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$this->assertTrue( $repo->has_availability( 1, 100 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test has_availability returns true when enough stock.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_availability
	 * @return void
	 */
	public function test_has_availability_returns_true_when_enough_stock(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row             = new \stdClass();
			$row->capacity   = 100;
			$row->sold_count = 95;

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$this->assertTrue( $repo->has_availability( 1, 5 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test has_availability returns false when insufficient stock.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_availability
	 * @return void
	 */
	public function test_has_availability_returns_false_when_insufficient(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row             = new \stdClass();
			$row->capacity   = 100;
			$row->sold_count = 98;

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$this->assertFalse( $repo->has_availability( 1, 5 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test has_availability returns true at exact boundary.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_availability
	 * @return void
	 */
	public function test_has_availability_returns_true_at_exact_boundary(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row             = new \stdClass();
			$row->capacity   = 100;
			$row->sold_count = 97;

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			// Exactly 3 available, requesting 3.
			$this->assertTrue( $repo->has_availability( 1, 3 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test has_availability returns false one over boundary.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_availability
	 * @return void
	 */
	public function test_has_availability_returns_false_one_over_boundary(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row             = new \stdClass();
			$row->capacity   = 100;
			$row->sold_count = 97;

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			// Exactly 3 available, requesting 4.
			$this->assertFalse( $repo->has_availability( 1, 4 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// is_sold_out() Tests
	// =========================================================================

	/**
	 * Test is_sold_out returns true when stock status is out_of_stock.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::is_sold_out
	 * @return void
	 */
	public function test_is_sold_out_returns_true_when_out_of_stock(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( 'out_of_stock' );

			$this->assertTrue( $repo->is_sold_out( 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test is_sold_out returns false when stock status is in_stock.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::is_sold_out
	 * @return void
	 */
	public function test_is_sold_out_returns_false_when_in_stock(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( 'in_stock' );

			$this->assertFalse( $repo->is_sold_out( 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test is_sold_out returns false when stock status is low_stock.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::is_sold_out
	 * @return void
	 */
	public function test_is_sold_out_returns_false_when_low_stock(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( 'low_stock' );

			$this->assertFalse( $repo->is_sold_out( 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test is_sold_out returns false when row not found (null).
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::is_sold_out
	 * @return void
	 */
	public function test_is_sold_out_returns_false_when_not_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( null );

			$this->assertFalse( $repo->is_sold_out( 999 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Build a ticket-type row as the house lock reads it.
	 *
	 * @param int         $id       Ticket type ID.
	 * @param int|null    $capacity Tier capacity.
	 * @param int         $sold     Seats sold on this tier.
	 * @param string      $type     Capacity type.
	 * @param string      $status   Tier status.
	 * @return \stdClass
	 */
	private function tier( int $id, ?int $capacity, int $sold, string $type = 'fixed', string $status = 'active' ): \stdClass {
		$row                = new \stdClass();
		$row->id            = $id;
		$row->capacity      = $capacity;
		$row->capacity_type = $type;
		$row->sold_count    = $sold;
		$row->status        = $status;

		return $row;
	}

	/**
	 * Stub the three reads `increment_sold_count()` makes: the tier, its house peers, the ceiling.
	 *
	 * @param \PHPUnit\Framework\MockObject\MockObject $mock_wpdb     Mock wpdb.
	 * @param array<int, \stdClass>                    $peers         Tiers sharing the house, including the one being sold.
	 * @param int|null                                 $ceiling       Occurrence capacity, or null when unset.
	 * @param int|null                                 $occurrence_id Occurrence the tier belongs to.
	 * @return void
	 */
	private function stub_house( $mock_wpdb, array $peers, ?int $ceiling = null, ?int $occurrence_id = 7 ): void {
		$self                = new \stdClass();
		$self->id            = $peers ? $peers[0]->id : 1;
		$self->occurrence_id = $occurrence_id;
		$self->event_id      = null;

		$mock_wpdb->method( 'get_row' )->willReturn( $self );
		$mock_wpdb->method( 'get_results' )->willReturn( $peers );
		$mock_wpdb->method( 'get_var' )->willReturn( null === $ceiling ? null : (string) $ceiling );
	}

	// =========================================================================
	// increment_sold_count() Tests
	// =========================================================================

	/**
	 * Test increment_sold_count happy path with sufficient capacity.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_succeeds_with_capacity(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$query_sequence = array();

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$query_sequence ) {
					$query_sequence[] = $sql;
					return 1;
				} );

			$this->stub_house( $mock_wpdb, array( $this->tier( 1, 100, 50 ) ) );

			$result = $repo->increment_sold_count( 1, 2 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'START TRANSACTION', $query_sequence[0] );
			$this->assertStringContainsString( 'COMMIT', end( $query_sequence ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test increment_sold_count rolls back when exceeding capacity.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_rolls_back_when_exceeding_capacity(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$query_sequence = array();

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$query_sequence ) {
					$query_sequence[] = $sql;
					return 1;
				} );

			$this->stub_house( $mock_wpdb, array( $this->tier( 1, 100, 99 ) ) );

			// Trying to add 2 when only 1 slot left.
			$result = $repo->increment_sold_count( 1, 2 );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'START TRANSACTION', $query_sequence[0] );
			$this->assertStringContainsString( 'ROLLBACK', end( $query_sequence ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test increment_sold_count succeeds with unlimited capacity (null).
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_succeeds_with_unlimited_capacity(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$query_sequence = array();

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$query_sequence ) {
					$query_sequence[] = $sql;
					return 1;
				} );

			// No capacity of its own and no ceiling: the room is unbounded.
			$this->stub_house( $mock_wpdb, array( $this->tier( 1, null, 5000, 'unlimited' ) ) );

			$result = $repo->increment_sold_count( 1, 100 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'COMMIT', end( $query_sequence ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test increment_sold_count rolls back when ticket type not found.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_rolls_back_when_not_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$query_sequence = array();

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$query_sequence ) {
					$query_sequence[] = $sql;
					return 1;
				} );

			$mock_wpdb->method( 'get_row' )
				->willReturn( null );

			$result = $repo->increment_sold_count( 999 );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'ROLLBACK', end( $query_sequence ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test increment_sold_count returns false when UPDATE query fails.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_returns_false_on_update_failure(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$call_count = 0;

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$call_count ) {
					++$call_count;
					// First call: START TRANSACTION. Second call: UPDATE (fail). Third: COMMIT.
					if ( $call_count === 2 ) {
						return false;
					}
					return 1;
				} );

			$this->stub_house( $mock_wpdb, array( $this->tier( 1, 100, 50 ) ) );

			$result = $repo->increment_sold_count( 1, 2 );

			$this->assertFalse( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test increment_sold_count rolls back on exception.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_rolls_back_on_exception(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$query_sequence = array();

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$query_sequence ) {
					$query_sequence[] = $sql;
					return 1;
				} );

			$mock_wpdb->method( 'get_row' )
				->willThrowException( new \RuntimeException( 'DB failure' ) );

			$result = $repo->increment_sold_count( 1 );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'ROLLBACK', end( $query_sequence ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test increment_sold_count UPDATE SQL contains stock status CASE logic.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_update_contains_stock_status_logic(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$update_sql = '';

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$update_sql ) {
					if ( stripos( $sql, 'UPDATE' ) !== false ) {
						$update_sql = $sql;
					}
					return 1;
				} );

			$this->stub_house( $mock_wpdb, array( $this->tier( 1, 100, 50 ) ) );

			$repo->increment_sold_count( 1, 1 );

			$this->assertStringContainsString( 'stock_status', $update_sql );
			$this->assertStringContainsString( 'out_of_stock', $update_sql );
			$this->assertStringContainsString( 'low_stock', $update_sql );
			$this->assertStringContainsString( 'in_stock', $update_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test the sale is refused when the house is full, though the tier is not.
	 *
	 * The CJAC regression: three tiers each carrying the hall's 250 capacity. Tier 1
	 * has sold only 101 of "its" 250, but its two neighbours have sold 11 more into
	 * the same 250-seat room, and the room is what runs out.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_refuses_to_oversell_the_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$query_sequence = array();

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$query_sequence ) {
					$query_sequence[] = $sql;
					return 1;
				} );

			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, 250, 240 ),
					$this->tier( 2, 250, 5 ),
					$this->tier( 3, 250, 5 ),
				)
			);

			// Tier 1 has 10 left of its own 250, but the house has only 0 left.
			$result = $repo->increment_sold_count( 1, 1 );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'ROLLBACK', end( $query_sequence ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test a sale within both the tier's limit and the house's is allowed.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_allows_a_sale_the_house_can_seat(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$query_sequence = array();

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$query_sequence ) {
					$query_sequence[] = $sql;
					return 1;
				} );

			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, 250, 101 ),
					$this->tier( 2, 250, 5 ),
					$this->tier( 3, 250, 6 ),
				)
			);

			// 112 sold into a 250-seat house: 138 left, so 2 more is fine.
			$result = $repo->increment_sold_count( 1, 2 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'COMMIT', end( $query_sequence ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test the occurrence ceiling is the house when tiers carry no limit of their own.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_honours_the_occurrence_ceiling(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			// Shared tiers hold no capacity of their own; the ceiling of 100 is the room.
			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, null, 60, 'shared' ),
					$this->tier( 2, null, 40, 'shared' ),
				),
				100
			);

			$this->assertFalse( $repo->increment_sold_count( 1, 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test a tier's own capacity still binds below the house.
	 *
	 * A 50-seat balcony in a 250-seat hall stops at 50.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_still_honours_the_tier_capacity(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, 50, 50 ),
					$this->tier( 2, null, 10, 'shared' ),
				),
				250
			);

			// The house has 190 left, but the balcony is full.
			$this->assertFalse( $repo->increment_sold_count( 1, 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test a retired tier keeps its sold seats but no longer sizes the room.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sold_count_counts_seats_sold_by_retired_tiers(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, 100, 60 ),
					$this->tier( 2, 100, 40, 'fixed', 'inactive' ),
				)
			);

			// The house is 100 (the retired tier cannot enlarge it) and 100 seats
			// are already occupied, 40 of them by the tier that no longer sells.
			$this->assertFalse( $repo->increment_sold_count( 1, 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// decrement_sold_count() Tests
	// =========================================================================

	/**
	 * Test decrement_sold_count happy path.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::decrement_sold_count
	 * @return void
	 */
	public function test_decrement_sold_count_succeeds(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$lock_row     = new \stdClass();
			$lock_row->id = 1;

			$query_sequence = array();

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$query_sequence ) {
					$query_sequence[] = $sql;
					return 1;
				} );

			$mock_wpdb->method( 'get_row' )
				->willReturn( $lock_row );

			$result = $repo->decrement_sold_count( 1, 3 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'START TRANSACTION', $query_sequence[0] );
			$this->assertStringContainsString( 'COMMIT', end( $query_sequence ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test decrement_sold_count UPDATE SQL uses GREATEST to prevent underflow.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::decrement_sold_count
	 * @return void
	 */
	public function test_decrement_sold_count_uses_greatest_guard(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$lock_row     = new \stdClass();
			$lock_row->id = 1;

			$update_sql = '';

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$update_sql ) {
					if ( stripos( $sql, 'UPDATE' ) !== false ) {
						$update_sql = $sql;
					}
					return 1;
				} );

			$mock_wpdb->method( 'get_row' )
				->willReturn( $lock_row );

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) {
					return $sql;
				} );

			$repo->decrement_sold_count( 1, 5 );

			$this->assertStringContainsString( 'GREATEST(0, sold_count - %d)', $update_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test decrement_sold_count UPDATE SQL contains stock status recovery logic.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::decrement_sold_count
	 * @return void
	 */
	public function test_decrement_sold_count_contains_stock_status_logic(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$lock_row     = new \stdClass();
			$lock_row->id = 1;

			$update_sql = '';

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$update_sql ) {
					if ( stripos( $sql, 'UPDATE' ) !== false ) {
						$update_sql = $sql;
					}
					return 1;
				} );

			$mock_wpdb->method( 'get_row' )
				->willReturn( $lock_row );

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) {
					return $sql;
				} );

			$repo->decrement_sold_count( 1, 1 );

			$this->assertStringContainsString( 'stock_status', $update_sql );
			$this->assertStringContainsString( 'out_of_stock', $update_sql );
			$this->assertStringContainsString( 'low_stock', $update_sql );
			$this->assertStringContainsString( 'in_stock', $update_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test decrement_sold_count returns false on update failure.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::decrement_sold_count
	 * @return void
	 */
	public function test_decrement_sold_count_returns_false_on_update_failure(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$lock_row     = new \stdClass();
			$lock_row->id = 1;

			$call_count = 0;

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function () use ( &$call_count ) {
					++$call_count;
					// First: START TRANSACTION. Second: UPDATE (fail). Third: COMMIT.
					if ( $call_count === 2 ) {
						return false;
					}
					return 1;
				} );

			$mock_wpdb->method( 'get_row' )
				->willReturn( $lock_row );

			$result = $repo->decrement_sold_count( 1, 1 );

			$this->assertFalse( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test decrement_sold_count rolls back on exception.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::decrement_sold_count
	 * @return void
	 */
	public function test_decrement_sold_count_rolls_back_on_exception(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$query_sequence = array();

			$mock_wpdb->method( 'query' )
				->willReturnCallback( function ( $sql ) use ( &$query_sequence ) {
					$query_sequence[] = $sql;
					return 1;
				} );

			$mock_wpdb->method( 'get_row' )
				->willThrowException( new \RuntimeException( 'DB failure' ) );

			$result = $repo->decrement_sold_count( 1, 1 );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'ROLLBACK', end( $query_sequence ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// recalculate_sold_count() Tests
	// =========================================================================

	/**
	 * Test recalculate_sold_count returns true on success.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::recalculate_sold_count
	 * @return void
	 */
	public function test_recalculate_sold_count_returns_true_on_success(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )
				->willReturn( 1 );

			$result = $repo->recalculate_sold_count( 1 );

			$this->assertTrue( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test recalculate_sold_count returns false on failure.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::recalculate_sold_count
	 * @return void
	 */
	public function test_recalculate_sold_count_returns_false_on_failure(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )
				->willReturn( false );

			$result = $repo->recalculate_sold_count( 1 );

			$this->assertFalse( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test recalculate_sold_count SQL references attendees table.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::recalculate_sold_count
	 * @return void
	 */
	public function test_recalculate_sold_count_sql_references_attendees(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'query' )
				->willReturn( 1 );

			$repo->recalculate_sold_count( 1 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'SUM(a.quantity)', $captured_sql );
			$this->assertStringContainsString( "status = 'confirmed'", $captured_sql );
			$this->assertStringContainsString( 'stock_status', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_fixed_capacity_sum() Tests
	// =========================================================================

	/**
	 * Test get_fixed_capacity_sum returns sum of fixed capacities.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_fixed_capacity_sum
	 * @return void
	 */
	public function test_get_fixed_capacity_sum_returns_sum(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '250' );

			$result = $repo->get_fixed_capacity_sum( 10 );

			$this->assertSame( 250, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_fixed_capacity_sum returns zero when no tickets.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_fixed_capacity_sum
	 * @return void
	 */
	public function test_get_fixed_capacity_sum_returns_zero_when_none(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$result = $repo->get_fixed_capacity_sum( 10 );

			$this->assertSame( 0, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_fixed_capacity_sum SQL filters by fixed capacity type.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_fixed_capacity_sum
	 * @return void
	 */
	public function test_get_fixed_capacity_sum_filters_by_fixed_type(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$repo->get_fixed_capacity_sum( 10 );

			$this->assertStringContainsString( 'capacity_type', $captured_sql );
			$this->assertStringContainsString( 'capacity IS NOT NULL', $captured_sql );
			$this->assertStringContainsString( "status = 'active'", $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_shared_sold_count() Tests
	// =========================================================================

	/**
	 * Test get_shared_sold_count returns sum of shared sold counts.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_shared_sold_count
	 * @return void
	 */
	public function test_get_shared_sold_count_returns_sum(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '42' );

			$result = $repo->get_shared_sold_count( 10 );

			$this->assertSame( 42, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_shared_sold_count returns zero when no shared tickets.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_shared_sold_count
	 * @return void
	 */
	public function test_get_shared_sold_count_returns_zero_when_none(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$result = $repo->get_shared_sold_count( 10 );

			$this->assertSame( 0, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_shared_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test get_shared_for_occurrence returns empty array when no results.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_shared_for_occurrence
	 * @return void
	 */
	public function test_get_shared_for_occurrence_returns_empty_array(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$result = $repo->get_shared_for_occurrence( 10 );

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_shared_for_occurrence returns mapped TicketType objects.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_shared_for_occurrence
	 * @return void
	 */
	public function test_get_shared_for_occurrence_returns_ticket_types(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row1                = new \stdClass();
			$row1->id            = 1;
			$row1->occurrence_id = 10;
			$row1->event_id      = null;
			$row1->scope         = 'occurrence';
			$row1->template_id   = null;
			$row1->name          = 'GA Shared';
			$row1->description   = null;
			$row1->price         = '10.00';
			$row1->capacity_type = 'shared';
			$row1->capacity      = null;
			$row1->sold_count    = 5;
			$row1->stock_status  = 'in_stock';
			$row1->sale_start    = null;
			$row1->sale_end      = null;
			$row1->min_per_order = 1;
			$row1->max_per_order = 10;
			$row1->sort_order    = 0;
			$row1->status        = 'active';
			$row1->wc_product_id = null;
			$row1->wc_variation_id = null;
			$row1->source        = 'woocommerce';
			$row1->created_at    = '2026-01-01 00:00:00';
			$row1->updated_at    = '2026-01-01 00:00:00';

			$row2                = clone $row1;
			$row2->id            = 2;
			$row2->name          = 'VIP Shared';
			$row2->price         = '25.00';
			$row2->sort_order    = 1;

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row1, $row2 ) );

			$result = $repo->get_shared_for_occurrence( 10 );

			$this->assertCount( 2, $result );
			$this->assertInstanceOf( \NetterTechEvents\Models\TicketType::class, $result[0] );
			$this->assertInstanceOf( \NetterTechEvents\Models\TicketType::class, $result[1] );
			$this->assertSame( 1, $result[0]->id );
			$this->assertSame( 2, $result[1]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_shared_for_occurrence SQL filters by shared type and active status.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::get_shared_for_occurrence
	 * @return void
	 */
	public function test_get_shared_for_occurrence_filters_correctly(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_shared_for_occurrence( 10 );

			$this->assertStringContainsString( 'capacity_type', $captured_sql );
			$this->assertStringContainsString( "status = 'active'", $captured_sql );
			$this->assertStringContainsString( 'ORDER BY sort_order ASC', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// has_unlimited_fixed_tickets() Tests
	// =========================================================================

	/**
	 * Test has_unlimited_fixed_tickets returns true when unlimited exist.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_unlimited_fixed_tickets
	 * @return void
	 */
	public function test_has_unlimited_fixed_tickets_returns_true(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '2' );

			$this->assertTrue( $repo->has_unlimited_fixed_tickets( 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test has_unlimited_fixed_tickets returns false when none exist.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_unlimited_fixed_tickets
	 * @return void
	 */
	public function test_has_unlimited_fixed_tickets_returns_false(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$this->assertFalse( $repo->has_unlimited_fixed_tickets( 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test has_unlimited_fixed_tickets SQL checks for NULL capacity with fixed type.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_unlimited_fixed_tickets
	 * @return void
	 */
	public function test_has_unlimited_fixed_tickets_sql_checks_null_capacity(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$repo->has_unlimited_fixed_tickets( 10 );

			$this->assertStringContainsString( 'capacity IS NULL', $captured_sql );
			$this->assertStringContainsString( 'capacity_type', $captured_sql );
			$this->assertStringContainsString( "status = 'active'", $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// has_unlimited_tickets() Tests
	// =========================================================================

	/**
	 * Test has_unlimited_tickets returns true when unlimited exist.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_unlimited_tickets
	 * @return void
	 */
	public function test_has_unlimited_tickets_returns_true(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '1' );

			$this->assertTrue( $repo->has_unlimited_tickets( 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test has_unlimited_tickets returns false when none exist.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_unlimited_tickets
	 * @return void
	 */
	public function test_has_unlimited_tickets_returns_false(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$this->assertFalse( $repo->has_unlimited_tickets( 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test has_unlimited_tickets SQL checks for unlimited capacity type.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::has_unlimited_tickets
	 * @return void
	 */
	public function test_has_unlimited_tickets_sql_checks_unlimited_type(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$repo->has_unlimited_tickets( 10 );

			$this->assertStringContainsString( 'capacity_type', $captured_sql );
			$this->assertStringContainsString( "status = 'active'", $captured_sql );
			// Unlike has_unlimited_fixed_tickets, this does NOT check capacity IS NULL.
			$this->assertStringNotContainsString( 'capacity IS NULL', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Mutation-hardening: boundary and observability tests.
	//
	// Each test below pins a specific behaviour a surviving Infection mutant
	// would otherwise slip past. Grouped by the production line they defend.
	// =========================================================================

	/**
	 * A Stringable that yields the given value, for pinning `(string)` casts.
	 *
	 * The DB always hands back strings, so the casts in lock_house() read as
	 * belt-and-suspenders — but they are load-bearing the moment a non-string
	 * slips through. Passing a Stringable proves the cast actually runs.
	 *
	 * @param string $value The string the object stringifies to.
	 * @return object Stringable object.
	 */
	private function stringable( string $value ): object {
		return new class( $value ) {
			/**
			 * @param string $value Wrapped value.
			 */
			public function __construct( private string $value ) {}

			/**
			 * @return string Wrapped value.
			 */
			public function __toString(): string {
				return $this->value;
			}
		};
	}

	/**
	 * Line 180: selling exactly the tier's own remaining count must succeed.
	 *
	 * Kills GreaterThan `>` -> `>=` on the own-capacity guard: at quantity ==
	 * own_remaining the `>` form lets the sale through, the `>=` form rejects it.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_allows_selling_exactly_the_tier_remaining(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			// Fixed tier of 100, 98 sold: exactly 2 left, buying exactly 2.
			$this->stub_house( $mock_wpdb, array( $this->tier( 1, 100, 98 ) ) );

			$this->assertTrue( $repo->increment_sold_count( 1, 2 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 188: a sale that fills the house to the brim must succeed.
	 *
	 * Kills GreaterThan `>` -> `>=` on the house guard: filling the last seats
	 * (house_sold + quantity == house) is allowed under `>`, rejected under `>=`.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_allows_filling_the_house_to_capacity(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			// Shared tiers (no own limit) under a ceiling of 100; 98 sold, buy 2.
			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, null, 98, 'shared' ),
					$this->tier( 2, null, 0, 'shared' ),
				),
				100
			);

			$this->assertTrue( $repo->increment_sold_count( 1, 2 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 253: an occurrence-scoped tier locks its peers by occurrence_id.
	 *
	 * Kills NotIdentical `!==` -> `===` on the peer-branch selector: the correct
	 * branch emits the occurrence peer-lock SQL (`occurrence_id = %d ... ORDER BY
	 * id ASC`); the mutant falls through to the single-row `WHERE id = %d` lock.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_locks_occurrence_peers_in_order(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback(
					function ( $sql ) use ( &$captured ) {
						$captured[] = $sql;
						return $sql;
					}
				);

			$self                = new \stdClass();
			$self->id            = 1;
			$self->occurrence_id = 7;
			$self->event_id      = null;

			$mock_wpdb->method( 'query' )->willReturn( 1 );
			$mock_wpdb->method( 'get_row' )->willReturn( $self );
			$mock_wpdb->method( 'get_results' )->willReturn( array( $this->tier( 1, 100, 0 ) ) );
			$mock_wpdb->method( 'get_var' )->willReturn( null );

			$repo->increment_sold_count( 1, 1 );

			$all = implode( "\n", $captured );
			$this->assertStringContainsString( 'occurrence_id = %d', $all );
			$this->assertStringContainsString( 'ORDER BY id ASC', $all );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 287: the occurrence ceiling must be read and must bind the house.
	 *
	 * Kills NotIdentical `!==` -> `===` on the ceiling fetch: skipping the read
	 * leaves the ceiling null, so shared tiers with no own limit look unbounded
	 * and a sale that should overflow the room is wrongly accepted.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_reads_ceiling_and_blocks_overflow(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			// Two shared tiers, 100 sold into a ceiling of 100: no room for one more.
			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, null, 60, 'shared' ),
					$this->tier( 2, null, 40, 'shared' ),
				),
				100
			);

			$this->assertFalse( $repo->increment_sold_count( 1, 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 304: every peer's sold count is summed into the house occupancy.
	 *
	 * Kills both the Assignment `+=` -> `=` and the PlusEqual `+=` -> `-=`
	 * mutants: either one mis-sums the house occupancy so a room that is already
	 * full (120 sold into a ceiling of 100) looks like it has seats to spare.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_sums_all_peer_sold_counts_for_the_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			// 60 + 60 = 120 already sold into a 100-seat room: oversold, reject.
			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, null, 60, 'shared' ),
					$this->tier( 2, null, 60, 'shared' ),
				),
				100
			);

			$this->assertFalse( $repo->increment_sold_count( 1, 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 311: a Stringable status is cast before the `'active'` comparison.
	 *
	 * Kills CastString removal of `(string) $peer->status`: without the cast a
	 * Stringable('active') no longer identity-matches 'active', so the tier drops
	 * out of the room-sizing set and the house shrinks to the wrong size.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_casts_peer_status_when_sizing_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			$own         = $this->tier( 1, 40, 0 );
			$big         = $this->tier( 2, 100, 90 );
			$big->status = $this->stringable( 'active' );

			$self                = new \stdClass();
			$self->id            = 1;
			$self->occurrence_id = 7;
			$self->event_id      = null;

			$mock_wpdb->method( 'get_row' )->willReturn( $self );
			$mock_wpdb->method( 'get_results' )->willReturn( array( $own, $big ) );
			$mock_wpdb->method( 'get_var' )->willReturn( null );

			// House = max(40, 100) = 100, 90 already sold: buying 5 fits (95 <= 100).
			// Drop the Stringable tier and the house collapses to 40, wrongly full.
			$this->assertTrue( $repo->increment_sold_count( 1, 5 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 313: a fixed tier's capacity is carried into the room-sizing set.
	 *
	 * Kills the Ternary flip `? (int) capacity : null` -> `? null : (int) capacity`:
	 * mapping present capacities to null makes the house look unbounded, so an
	 * overfull room accepts a sale it should reject.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_carries_tier_capacity_into_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			// Two fixed 100-seat tiers, 95 sold in the room: buying 10 overflows.
			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, 100, 0 ),
					$this->tier( 2, 100, 95 ),
				)
			);

			$this->assertFalse( $repo->increment_sold_count( 1, 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 314: a Stringable capacity_type is cast before the room-sizing check.
	 *
	 * Kills CastString removal of `(string) $peer->capacity_type`: without the
	 * cast a Stringable('unlimited') never matches the UNLIMITED sentinel, so a
	 * room meant to be unbounded is wrongly capped at the stale capacity value.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_casts_capacity_type_when_sizing_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			$peer                = new \stdClass();
			$peer->id            = 1;
			$peer->capacity      = 50; // Stale value the form left behind.
			$peer->capacity_type = $this->stringable( 'unlimited' );
			$peer->sold_count    = 0;
			$peer->status        = 'active';

			$self                = new \stdClass();
			$self->id            = 1;
			$self->occurrence_id = 7;
			$self->event_id      = null;

			$mock_wpdb->method( 'get_row' )->willReturn( $self );
			$mock_wpdb->method( 'get_results' )->willReturn( array( $peer ) );
			$mock_wpdb->method( 'get_var' )->willReturn( null );

			// Cast in place: type is unlimited, room unbounded, 100 sells fine.
			// Cast dropped: type != 'unlimited', room capped at 50, 100 rejected.
			$this->assertTrue( $repo->increment_sold_count( 1, 100 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 326: the tier's own capacity is carried into the own-limit guard.
	 *
	 * Kills the Ternary flip on own_capacity `? (int) capacity : null` ->
	 * `? null : (int) capacity`: nulling a present own capacity removes the tier's
	 * own ceiling, letting it sell past its own limit inside a larger house.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_honours_own_capacity_inside_a_bigger_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			// Own tier caps at 50; the house (a 1000-seat tier) has ample room,
			// so only the tier's own 50 limit can reject a 60-seat sale.
			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, 50, 0 ),
					$this->tier( 2, 1000, 0 ),
				)
			);

			$this->assertFalse( $repo->increment_sold_count( 1, 60 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 327: a Stringable own capacity_type is cast before own_remaining().
	 *
	 * Kills CastString removal of `(string) $own->capacity_type`: HouseRule::
	 * own_remaining() takes a string under strict types, so an uncast Stringable
	 * raises a TypeError, the transaction rolls back, and the sale is lost.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_casts_own_capacity_type_for_own_remaining(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			$peer                = new \stdClass();
			$peer->id            = 1;
			$peer->capacity      = 100;
			$peer->capacity_type = $this->stringable( 'fixed' );
			$peer->sold_count    = 0;
			$peer->status        = 'active';

			$self                = new \stdClass();
			$self->id            = 1;
			$self->occurrence_id = 7;
			$self->event_id      = null;

			$mock_wpdb->method( 'get_row' )->willReturn( $self );
			$mock_wpdb->method( 'get_results' )->willReturn( array( $peer ) );
			$mock_wpdb->method( 'get_var' )->willReturn( null );

			// Cast in place: a fixed 100-seat tier with room for 5. Cast dropped:
			// own_remaining() gets an object, TypeError, rollback, false.
			$this->assertTrue( $repo->increment_sold_count( 1, 5 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 313 (false branch): a null tier capacity leaves the house unbounded.
	 *
	 * The carries-capacity test above pins only the `true` branch of
	 * `null !== $peer->capacity ? (int) $peer->capacity : null`. A Ternary mutant
	 * that collapses the expression to its `then` operand — `(int) $peer->capacity`
	 * — reads a null capacity as 0 and sizes the room at zero seats. This pins the
	 * `false` branch: a shared tier whose capacity column is null must keep the
	 * house unbounded, so even a large sale commits.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_maps_null_tier_capacity_to_unbounded_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			// One shared tier, null capacity column, no ceiling: the room is unbounded.
			$this->stub_house( $mock_wpdb, array( $this->tier( 1, null, 0, 'shared' ) ) );

			// House stays null, so 100 seats sell. Map null -> 0 and the room caps at
			// zero, wrongly rejecting the sale.
			$this->assertTrue( $repo->increment_sold_count( 1, 100 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Line 326 (false branch): a null own capacity leaves the tier bound only by the house.
	 *
	 * The own-capacity test above pins only the `true` branch of
	 * `null !== $own->capacity ? (int) $own->capacity : null`. A Ternary mutant that
	 * collapses it to `(int) $own->capacity` reads a null capacity as 0, so
	 * own_remaining() computes a zero own limit and rejects every sale. This pins
	 * the `false` branch: a fixed tier with a null capacity column carries no own
	 * limit. An unlimited peer keeps the house unbounded independently of how tier
	 * capacity is mapped, so the tier's own limit is the only thing that could
	 * reject — and, being absent, it does not.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeStockRepository::increment_sold_count
	 * @return void
	 */
	public function test_increment_maps_null_own_capacity_to_no_own_limit(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'query' )->willReturn( 1 );

			$this->stub_house(
				$mock_wpdb,
				array(
					$this->tier( 1, null, 0, 'fixed' ),
					$this->tier( 2, null, 0, 'unlimited' ),
				)
			);

			// Null own capacity means no own limit, so the sale commits. Map null ->
			// 0 and own_remaining() becomes 0, wrongly rejecting it.
			$this->assertTrue( $repo->increment_sold_count( 1, 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}
}
