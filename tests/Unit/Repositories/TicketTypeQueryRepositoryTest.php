<?php
/**
 * TicketTypeQueryRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\TicketTypeQueryRepository;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Enums\TicketTypeScope;

/**
 * Test TicketTypeQueryRepository functionality.
 *
 * Tests all 10 public methods covering occurrence queries, event queries,
 * product lookups, and free/paid checks with wpdb mocking.
 */
class TicketTypeQueryRepositoryTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Create a mock wpdb and instantiate the repository.
	 *
	 * @param array $methods Methods to mock on wpdb.
	 * @return array{0: \wpdb, 1: TicketTypeQueryRepository, 2: \wpdb}
	 */
	private function create_repo( array $methods = array( 'get_var', 'get_row', 'get_results', 'query', 'prepare' ) ): array {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( $methods )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql ) {
				return $sql;
			} );

		$wpdb = $mock_wpdb;
		$repo = new TicketTypeQueryRepository( $mock_wpdb );

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

	/**
	 * Build a stdClass row that TicketType::from_row() can consume.
	 *
	 * @param array $overrides Field overrides.
	 * @return \stdClass
	 */
	private function make_ticket_row( array $overrides = array() ): \stdClass {
		$defaults = array(
			'id'              => 1,
			'occurrence_id'   => 10,
			'event_id'        => 100,
			'scope'           => 'occurrence',
			'template_id'     => null,
			'name'            => 'General Admission',
			'description'     => null,
			'price'           => '25.00',
			'capacity_type'   => 'fixed',
			'capacity'        => 100,
			'sold_count'      => 10,
			'stock_status'    => 'in_stock',
			'sale_start'      => null,
			'sale_end'        => null,
			'min_per_order'   => 1,
			'max_per_order'   => 10,
			'sort_order'      => 0,
			'status'          => 'active',
			'wc_product_id'   => null,
			'wc_variation_id' => null,
			'source'          => 'woocommerce',
			'created_at'      => '2026-01-01 00:00:00',
			'updated_at'      => '2026-01-01 00:00:00',
		);

		return (object) array_merge( $defaults, $overrides );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::__construct
	 */
	public function test_can_instantiate_repository(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$this->assertInstanceOf( TicketTypeQueryRepository::class, $repo );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::__construct
	 */
	public function test_repository_initializes_table_name(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$reflection = new \ReflectionClass( $repo );
			$table_prop = $reflection->getProperty( 'table' );
			$this->assertSame( 'wp_nettertech_events_ticket_types', $table_prop->getValue( $repo ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// for_occurrence() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_occurrence
	 */
	public function test_for_occurrence_returns_cached_results_on_cache_hit(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$cached_row = $this->make_ticket_row();

			\Brain\Monkey\Functions\when( 'wp_cache_get' )
				->justReturn( array( $cached_row ) );

			$result = $repo->for_occurrence( 10 );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( TicketType::class, $result[0] );
			$this->assertSame( 1, $result[0]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_occurrence
	 */
	public function test_for_occurrence_queries_db_on_cache_miss(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// Bootstrap stubs wp_cache_get to return false (cache miss).
			$mock_wpdb->method( 'get_var' )
				->willReturn( '100' );

			$row = $this->make_ticket_row();
			$mock_wpdb->expects( $this->once() )
				->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->for_occurrence( 10 );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( TicketType::class, $result[0] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_occurrence
	 */
	public function test_for_occurrence_returns_empty_when_no_event_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// Bootstrap stubs wp_cache_get to return false (cache miss).
			$mock_wpdb->method( 'get_var' )
				->willReturn( null );

			$result = $repo->for_occurrence( 999 );

			$this->assertSame( array(), $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_occurrence
	 */
	public function test_for_occurrence_skips_cache_when_status_filter_applied(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '100' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $this->make_ticket_row() ) );

			// With a status filter, cache is bypassed; the DB path is taken.
			$result = $repo->for_occurrence( 10, array( 'status' => 'active' ) );

			$this->assertCount( 1, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_occurrence
	 */
	public function test_for_occurrence_returns_empty_array_when_db_returns_empty(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// Bootstrap stubs wp_cache_get to return false (cache miss).
			$mock_wpdb->method( 'get_var' )
				->willReturn( '100' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$result = $repo->for_occurrence( 10 );

			$this->assertSame( array(), $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// for_multiple_occurrences() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_multiple_occurrences
	 */
	public function test_for_multiple_occurrences_returns_empty_for_empty_input(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$result = $repo->for_multiple_occurrences( array() );
			$this->assertSame( array(), $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_multiple_occurrences
	 */
	public function test_for_multiple_occurrences_returns_empty_when_no_occurrences_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )
				->willReturnOnConsecutiveCalls(
					array(),
				);

			$result = $repo->for_multiple_occurrences( array( 10, 20 ) );
			$this->assertSame( array(), $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_multiple_occurrences
	 */
	public function test_for_multiple_occurrences_groups_occurrence_tickets_correctly(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$occ_10        = new \stdClass();
			$occ_10->id    = 10;
			$occ_10->event_id = '100';

			$occ_20        = new \stdClass();
			$occ_20->id    = 20;
			$occ_20->event_id = '100';

			// Occurrence-scoped ticket for occ 10.
			$row1 = $this->make_ticket_row(
				array(
					'id'            => 1,
					'occurrence_id' => 10,
					'event_id'      => 100,
				)
			);

			// Occurrence-scoped ticket for occ 20.
			$row2 = $this->make_ticket_row(
				array(
					'id'            => 2,
					'occurrence_id' => 20,
					'event_id'      => 100,
				)
			);

			$mock_wpdb->method( 'get_results' )
				->willReturnOnConsecutiveCalls(
					array( 10 => $occ_10, 20 => $occ_20 ),
					array( $row1, $row2 ),
				);

			$result = $repo->for_multiple_occurrences( array( 10, 20 ) );

			$this->assertArrayHasKey( 10, $result );
			$this->assertArrayHasKey( 20, $result );
			$this->assertCount( 1, $result[10] );
			$this->assertCount( 1, $result[20] );
			$this->assertSame( 1, $result[10][0]->id );
			$this->assertSame( 2, $result[20][0]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_multiple_occurrences
	 */
	public function test_for_multiple_occurrences_distributes_event_tickets_to_all(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$occ_10           = new \stdClass();
			$occ_10->id       = 10;
			$occ_10->event_id = '100';

			$occ_20           = new \stdClass();
			$occ_20->id       = 20;
			$occ_20->event_id = '100';

			// Event-scoped ticket (occurrence_id IS NULL).
			$event_row = $this->make_ticket_row(
				array(
					'id'            => 5,
					'occurrence_id' => null,
					'event_id'      => 100,
					'scope'         => 'event',
				)
			);

			$mock_wpdb->method( 'get_results' )
				->willReturnOnConsecutiveCalls(
					array( 10 => $occ_10, 20 => $occ_20 ),
					array( $event_row ),
				);

			$result = $repo->for_multiple_occurrences( array( 10, 20 ) );

			// Event-scoped ticket should appear under both occurrences.
			$this->assertArrayHasKey( 10, $result );
			$this->assertArrayHasKey( 20, $result );
			$this->assertCount( 1, $result[10] );
			$this->assertCount( 1, $result[20] );
			$this->assertSame( 5, $result[10][0]->id );
			$this->assertSame( 5, $result[20][0]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_multiple_occurrences
	 */
	public function test_for_multiple_occurrences_filters_zero_ids(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// After absint, 0 is filtered out; no valid IDs remain.
			$result = $repo->for_multiple_occurrences( array( 0, 0 ) );
			$this->assertSame( array(), $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_active_for_occurrence() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_active_for_occurrence
	 */
	public function test_get_active_for_occurrence_filters_to_active(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '100' );

			$active_row = $this->make_ticket_row( array( 'status' => 'active' ) );
			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $active_row ) );

			$result = $repo->get_active_for_occurrence( 10 );

			$this->assertCount( 1, $result );
			$this->assertSame( 'active', $result[0]->status );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_on_sale_for_occurrence() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_get_on_sale_for_occurrence_returns_only_on_sale(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		// The window is read in the occurrence's zone, falling back to the site's (NTE-148).
		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '100' );

			// Active with no sale window restrictions (on sale).
			$on_sale = $this->make_ticket_row(
				array(
					'id'     => 1,
					'status' => 'active',
				)
			);

			// Active but sale hasn't started yet (not on sale).
			$not_on_sale = $this->make_ticket_row(
				array(
					'id'         => 2,
					'status'     => 'active',
					'sale_start' => '2099-01-01 00:00:00',
				)
			);

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $on_sale, $not_on_sale ) );

			$result = $repo->get_on_sale_for_occurrence( 10 );

			$this->assertCount( 1, $result );
			$ticket = reset( $result );
			$this->assertSame( 1, $ticket->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_get_on_sale_for_occurrence_returns_empty_when_all_expired(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '100' );

			$expired = $this->make_ticket_row(
				array(
					'status'   => 'active',
					'sale_end' => '2020-01-01 00:00:00',
				)
			);

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $expired ) );

			$result = $repo->get_on_sale_for_occurrence( 10 );

			$this->assertCount( 0, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test an extension can re-admit a tier the sale window closed.
	 *
	 * The struck-through early-bird case: the window has passed, so the tier is out — but
	 * an add-on may still want it on the page, marked sold out, rather than vanished. That
	 * is only possible if the filter is handed the tiers that were excluded, not just the
	 * survivors (NTE-149).
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_on_sale_ticket_types_filter_can_readmit_an_expired_tier(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '100' );

			$expired = $this->make_ticket_row(
				array(
					'id'       => 7,
					'status'   => 'active',
					'sale_end' => '2020-01-01 00:00:00',
				)
			);

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $expired ) );

			// The window excludes the tier — the filter's first argument is empty — but the
			// tier it excluded is still handed over in $all_active, which is what makes
			// re-admission possible at all.
			\Brain\Monkey\Filters\expectApplied( 'nettertech_events_on_sale_ticket_types' )
				->once()
				->with(
					array(),
					\Mockery::on( fn( $all ) => 1 === count( $all ) && 7 === $all[0]->id ),
					10
				)
				->andReturnUsing( fn( $on_sale, $all_active ) => $all_active );

			$result = $repo->get_on_sale_for_occurrence( 10 );

			// And the subscriber's decision to put it back stands.
			$this->assertCount( 1, $result );
			$this->assertSame( 7, $result[0]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_on_sale_for_event() Tests (NTE-156)
	// =========================================================================

	/**
	 * Event-scope (series pass) tiers on sale are returned; those outside their
	 * window are dropped — the same sale-window semantics as the occurrence path.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_event
	 */
	public function test_get_on_sale_for_event_returns_only_on_sale_event_tiers(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';
			$mock_wpdb->method( 'prepare' )
				->willReturnCallback(
					function ( $sql ) use ( &$captured_sql ) {
						$captured_sql = $sql;
						return $sql;
					}
				);

			$pass_open = $this->make_ticket_row(
				array(
					'id'            => 1,
					'scope'         => 'event',
					'occurrence_id' => null,
					'status'        => 'active',
				)
			);
			$pass_future = $this->make_ticket_row(
				array(
					'id'            => 2,
					'scope'         => 'event',
					'occurrence_id' => null,
					'status'        => 'active',
					'sale_start'    => '2099-01-01 00:00:00',
				)
			);

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $pass_open, $pass_future ) );

			$result = $repo->get_on_sale_for_event( 100, new \DateTimeZone( 'UTC' ) );

			// Scoped to event-level tiers only.
			$this->assertStringContainsString( 'scope = %s', $captured_sql );
			$this->assertCount( 1, $result );
			$this->assertSame( 1, $result[0]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * An event with no event-scope tiers yields an empty list (a plain event with
	 * only per-date tickets has no pass to surface).
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_event
	 */
	public function test_get_on_sale_for_event_returns_empty_without_event_tiers(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )->willReturn( array() );

			$result = $repo->get_on_sale_for_event( 100, new \DateTimeZone( 'UTC' ) );

			$this->assertSame( array(), $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// for_event() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_event
	 */
	public function test_for_event_returns_all_types_with_no_filters(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row1 = $this->make_ticket_row( array( 'id' => 1, 'status' => 'active' ) );
			$row2 = $this->make_ticket_row( array( 'id' => 2, 'status' => 'inactive' ) );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row1, $row2 ) );

			$result = $repo->for_event( 100 );

			$this->assertCount( 2, $result );
			$this->assertInstanceOf( TicketType::class, $result[0] );
			$this->assertInstanceOf( TicketType::class, $result[1] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_event
	 */
	public function test_for_event_applies_status_filter(): void {
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

			$repo->for_event( 100, array( 'status' => 'active' ) );

			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_event
	 */
	public function test_for_event_applies_scope_filter(): void {
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

			$repo->for_event( 100, array( 'scope' => 'event' ) );

			$this->assertStringContainsString( 'scope = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::for_event
	 */
	public function test_for_event_returns_empty_array_when_no_results(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$result = $repo->for_event( 100 );

			$this->assertSame( array(), $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_templates() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_templates
	 */
	public function test_get_templates_filters_by_template_scope(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';
			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$template_row = $this->make_ticket_row(
				array(
					'scope' => TicketTypeScope::TEMPLATE->value,
				)
			);
			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $template_row ) );

			$result = $repo->get_templates( 100 );

			$this->assertCount( 1, $result );
			$this->assertStringContainsString( 'scope = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_templates
	 */
	public function test_get_templates_defaults_to_active_status(): void {
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

			$repo->get_templates( 100 );

			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_templates
	 */
	public function test_get_templates_can_override_status(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			// Should not throw, should still filter by scope.
			$result = $repo->get_templates( 100, array( 'status' => null ) );

			$this->assertSame( array(), $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// find_by_product() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::find_by_product
	 */
	public function test_find_by_product_returns_ticket_type_when_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row = $this->make_ticket_row( array( 'wc_product_id' => 500 ) );
			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$result = $repo->find_by_product( 500 );

			$this->assertInstanceOf( TicketType::class, $result );
			$this->assertSame( 500, $result->wc_product_id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::find_by_product
	 */
	public function test_find_by_product_returns_null_when_not_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )
				->willReturn( null );

			$result = $repo->find_by_product( 999 );

			$this->assertNull( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// find_by_variation() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::find_by_variation
	 */
	public function test_find_by_variation_returns_ticket_type_when_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row = $this->make_ticket_row( array( 'wc_variation_id' => 600 ) );
			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$result = $repo->find_by_variation( 600 );

			$this->assertInstanceOf( TicketType::class, $result );
			$this->assertSame( 600, $result->wc_variation_id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::find_by_variation
	 */
	public function test_find_by_variation_returns_null_when_not_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )
				->willReturn( null );

			$result = $repo->find_by_variation( 999 );

			$this->assertNull( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// occurrence_has_free_tickets() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::occurrence_has_free_tickets
	 */
	public function test_occurrence_has_free_tickets_returns_true_when_free_exist(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '2' );

			$this->assertTrue( $repo->occurrence_has_free_tickets( 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::occurrence_has_free_tickets
	 */
	public function test_occurrence_has_free_tickets_returns_false_when_none(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$this->assertFalse( $repo->occurrence_has_free_tickets( 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::occurrence_has_free_tickets
	 */
	public function test_occurrence_has_free_tickets_sql_checks_price_and_status(): void {
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

			$repo->occurrence_has_free_tickets( 10 );

			$this->assertStringContainsString( 'price <= 0', $captured_sql );
			$this->assertStringContainsString( "status = 'active'", $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// occurrence_is_free() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::occurrence_is_free
	 */
	public function test_occurrence_is_free_returns_true_when_no_paid_tickets(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturnOnConsecutiveCalls(
					'100',
					'0',
				);

			$this->assertTrue( $repo->occurrence_is_free( 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::occurrence_is_free
	 */
	public function test_occurrence_is_free_returns_false_when_paid_tickets_exist(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturnOnConsecutiveCalls(
					'100',
					'3',
				);

			$this->assertFalse( $repo->occurrence_is_free( 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::occurrence_is_free
	 */
	public function test_occurrence_is_free_returns_true_when_no_event_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( null );

			$this->assertTrue( $repo->occurrence_is_free( 999 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::occurrence_is_free
	 */
	public function test_occurrence_is_free_checks_both_occurrence_and_event_tickets(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sqls = array();
			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturnOnConsecutiveCalls(
					'100',
					'0',
				);

			$repo->occurrence_is_free( 10 );

			// The second prepare call builds the paid-ticket count query.
			$count_sql = $captured_sqls[1] ?? '';
			$this->assertStringContainsString( 'occurrence_id = %d', $count_sql );
			$this->assertStringContainsString( 'occurrence_id IS NULL AND event_id = %d', $count_sql );
			$this->assertStringContainsString( 'price > 0', $count_sql );
			$this->assertStringContainsString( "status = 'active'", $count_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::occurrence_is_free
	 */
	public function test_occurrence_is_free_returns_true_when_event_level_free_only(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// First get_var: event_id lookup. Second: count of paid tickets.
			$mock_wpdb->method( 'get_var' )
				->willReturnOnConsecutiveCalls(
					'100',
					'0',
				);

			// No paid tickets at occurrence OR event level => free.
			$this->assertTrue( $repo->occurrence_is_free( 10 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// event_capacity_for_events() Tests
	// =========================================================================

	/**
	 * Build an occurrence-ceiling row.
	 *
	 * @param int      $id       Occurrence ID.
	 * @param int      $event_id Event ID.
	 * @param int|null $capacity Occurrence capacity (null = unlimited ceiling).
	 * @return \stdClass
	 */
	private function occ_row( int $id, int $event_id, ?int $capacity ): \stdClass {
		$row           = new \stdClass();
		$row->id       = $id;
		$row->event_id = $event_id;
		$row->capacity = $capacity;
		return $row;
	}

	/**
	 * Build a ticket-type row for the capacity aggregate.
	 *
	 * @param int|null $occurrence_id Occurrence ID (null = event-scoped).
	 * @param int      $event_id      Event ID (used for both columns where relevant).
	 * @param int|null $capacity      Ticket type capacity (null = unlimited).
	 * @param string   $capacity_type Capacity type string.
	 * @return \stdClass
	 */
	private function tt_row( ?int $occurrence_id, int $event_id, ?int $capacity, string $capacity_type = 'fixed' ): \stdClass {
		$row                = new \stdClass();
		$row->event_id      = $event_id;
		$row->occurrence_id = $occurrence_id;
		$row->capacity      = $capacity;
		$row->capacity_type = $capacity_type;
		$row->occ_event_id  = null !== $occurrence_id ? $event_id : null;
		return $row;
	}

	/**
	 * Test empty input short-circuits.
	 *
	 * @return void
	 */
	public function test_event_capacity_for_events_empty_input(): void {
		list( , $repo, $original_wpdb ) = $this->create_repo();

		try {
			$this->assertSame( array(), $repo->event_capacity_for_events( array() ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test tiers on an uncapped occurrence collapse to the largest tier (shared pool).
	 *
	 * Two fixed tiers (50, 30) sell into one room with no occurrence ceiling. The
	 * tiers share the house, so the denominator is 50 (the largest tier), NOT 80.
	 *
	 * @return void
	 */
	public function test_event_capacity_fixed_only(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// Occurrence 100 of event 5 with NO occurrence ceiling: tiers share one house.
			$occ = array( $this->occ_row( 100, 5, null ) );
			$tt  = array(
				$this->tt_row( 100, 5, 50, 'fixed' ),
				$this->tt_row( 100, 5, 30, 'fixed' ),
			);

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls( $occ, $tt );

			$result = $repo->event_capacity_for_events( array( 5 ) );

			$this->assertSame( 50, $result[5]['capacity'] );
			$this->assertFalse( $result[5]['has_unlimited'] );
			$this->assertTrue( $result[5]['configured'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test multiple event-scoped tiers sharing one house collapse to the largest.
	 *
	 * The dominant real-world case: a single show with several price tiers, all
	 * event-scoped, each capped at the room size, no occurrence ceiling set. The
	 * tiers share one house, so three 250-cap tiers yield 250, NOT 750.
	 *
	 * @return void
	 */
	public function test_event_capacity_event_scoped_tiers_share_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$occ = array( $this->occ_row( 100, 21, null ) ); // No ceiling.
			$tt  = array(
				$this->tt_row( null, 21, 250, 'fixed' ),
				$this->tt_row( null, 21, 250, 'fixed' ),
				$this->tt_row( null, 21, 250, 'fixed' ),
			);

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls( $occ, $tt );

			$result = $repo->event_capacity_for_events( array( 21 ) );

			$this->assertSame( 250, $result[21]['capacity'] );
			$this->assertFalse( $result[21]['has_unlimited'] );
			$this->assertTrue( $result[21]['configured'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test event-scoped tiers are not double-counted against an occurrence ceiling.
	 *
	 * A single-date show with an occurrence ceiling of 250 and event-scoped tiers
	 * (largest 250) describes one room. The denominator is 250 (max of the two
	 * signals), NOT 500 (ceiling + tier).
	 *
	 * @return void
	 */
	public function test_event_capacity_event_scoped_not_added_to_ceiling(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$occ = array( $this->occ_row( 100, 23, 250 ) ); // Ceiling = 250.
			$tt  = array(
				$this->tt_row( null, 23, 250, 'fixed' ),
				$this->tt_row( null, 23, 100, 'fixed' ),
			);

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls( $occ, $tt );

			$result = $repo->event_capacity_for_events( array( 23 ) );

			$this->assertSame( 250, $result[23]['capacity'] );
			$this->assertFalse( $result[23]['has_unlimited'] );
			$this->assertTrue( $result[23]['configured'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test shared pool is counted once via the occurrence ceiling, not added on top.
	 *
	 * A fixed type (40) and a shared type both live on occurrence 100 whose ceiling
	 * is 100. The denominator must be 100 (the ceiling), NOT 140 (fixed + ceiling).
	 *
	 * @return void
	 */
	public function test_event_capacity_shared_pool_counted_once(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$occ = array( $this->occ_row( 100, 7, 100 ) ); // Ceiling = 100.
			$tt  = array(
				$this->tt_row( 100, 7, 40, 'fixed' ),
				$this->tt_row( 100, 7, null, 'shared' ),
			);

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls( $occ, $tt );

			$result = $repo->event_capacity_for_events( array( 7 ) );

			$this->assertSame( 100, $result[7]['capacity'] );
			$this->assertFalse( $result[7]['has_unlimited'] );
			$this->assertTrue( $result[7]['configured'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test recurring event sums ceilings across multiple occurrences.
	 *
	 * @return void
	 */
	public function test_event_capacity_sums_across_occurrences(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$occ = array(
				$this->occ_row( 100, 11, 60 ),
				$this->occ_row( 101, 11, 60 ),
			);
			$tt = array(
				$this->tt_row( 100, 11, 60, 'fixed' ),
				$this->tt_row( 101, 11, 60, 'fixed' ),
			);

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls( $occ, $tt );

			$result = $repo->event_capacity_for_events( array( 11 ) );

			$this->assertSame( 120, $result[11]['capacity'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test any unlimited contribution makes the event capacity null.
	 *
	 * An unlimited sellable type on an uncapped occurrence => unlimited event.
	 *
	 * @return void
	 */
	public function test_event_capacity_unlimited_returns_null(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$occ = array( $this->occ_row( 100, 9, null ) ); // No ceiling.
			$tt  = array( $this->tt_row( 100, 9, null, 'unlimited' ) );

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls( $occ, $tt );

			$result = $repo->event_capacity_for_events( array( 9 ) );

			$this->assertNull( $result[9]['capacity'] );
			$this->assertTrue( $result[9]['has_unlimited'] );
			$this->assertTrue( $result[9]['configured'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test an event with no sellable ticket types reports configured === false.
	 *
	 * The column renders "—" (no second line) for such events.
	 *
	 * @return void
	 */
	public function test_event_capacity_no_ticketing_not_configured(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$occ = array( $this->occ_row( 100, 3, 100 ) );
			$tt  = array(); // No ticket types.

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls( $occ, $tt );

			$result = $repo->event_capacity_for_events( array( 3 ) );

			$this->assertFalse( $result[3]['configured'] );
			$this->assertFalse( $result[3]['has_unlimited'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test an event-scoped (series pass) ticket type is counted once for the event.
	 *
	 * @return void
	 */
	public function test_event_capacity_event_scoped_counted_once(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// Two occurrences but the sellable allocation is a single event-scoped pass.
			$occ = array(
				$this->occ_row( 100, 15, null ),
				$this->occ_row( 101, 15, null ),
			);
			$tt = array( $this->tt_row( null, 15, 200, 'fixed' ) ); // Event-scoped pass, cap 200.

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls( $occ, $tt );

			$result = $repo->event_capacity_for_events( array( 15 ) );

			// Counted once (200), not once per occurrence (400).
			$this->assertSame( 200, $result[15]['capacity'] );
			$this->assertTrue( $result[15]['configured'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Mutation-hardening — sale-window reindexing and filter contract
	// =========================================================================

	/**
	 * Invoke a private/protected repository method under test.
	 *
	 * @param TicketTypeQueryRepository $repo   Repository instance.
	 * @param string                    $method Method name.
	 * @param array                     $args   Positional arguments.
	 * @return mixed
	 */
	private function invoke_private( TicketTypeQueryRepository $repo, string $method, array $args ) {
		$ref = new \ReflectionMethod( $repo, $method );
		return $ref->invokeArgs( $repo, $args );
	}

	/**
	 * The on-sale set handed to the filter must be reindexed from zero.
	 *
	 * When the window excludes an earlier tier, array_filter leaves a hole in the
	 * keys; the code array_values() it back to a clean list before the filter sees
	 * it. Dropping that reindex (line 247) hands subscribers a gappy array.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_get_on_sale_for_occurrence_reindexes_on_sale_before_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		try {
			$mock_wpdb->method( 'get_var' )->willReturn( '100' );

			// Tier 1 not yet on sale (drops out, leaving a key hole); tier 2 on sale.
			$not_yet = $this->make_ticket_row( array( 'id' => 1, 'status' => 'active', 'sale_start' => '2099-01-01 00:00:00' ) );
			$on_sale = $this->make_ticket_row( array( 'id' => 2, 'status' => 'active' ) );

			$mock_wpdb->method( 'get_results' )->willReturn( array( $not_yet, $on_sale ) );

			\Brain\Monkey\Filters\expectApplied( 'nettertech_events_on_sale_ticket_types' )
				->once()
				->with(
					\Mockery::on( fn( $admitted ) => array( 0 ) === array_keys( $admitted ) && 2 === $admitted[0]->id ),
					\Mockery::type( 'array' ),
					10
				)
				->andReturnUsing( fn( $admitted ) => $admitted );

			$result = $repo->get_on_sale_for_occurrence( 10 );

			$this->assertCount( 1, $result );
			$this->assertSame( 2, $result[0]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * filter_on_sale() must reindex $all_active before passing it to the filter.
	 *
	 * Kills the line-315 array_values() removal: the second filter argument is
	 * expected to be a clean 0..n list even when the caller's array is keyed.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_filter_on_sale_reindexes_all_active_argument(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$t1 = TicketType::from_row( $this->make_ticket_row( array( 'id' => 1 ) ) );
			$t2 = TicketType::from_row( $this->make_ticket_row( array( 'id' => 2 ) ) );

			// Deliberately non-sequential keys.
			$all_active = array( 5 => $t1, 9 => $t2 );

			\Brain\Monkey\Filters\expectApplied( 'nettertech_events_on_sale_ticket_types' )
				->once()
				->with(
					\Mockery::type( 'array' ),
					\Mockery::on( fn( $all ) => array( 0, 1 ) === array_keys( $all ) && 1 === $all[0]->id && 2 === $all[1]->id ),
					10
				)
				->andReturnUsing( fn( $admitted ) => $admitted );

			$result = $this->invoke_private( $repo, 'filter_on_sale', array( array( $t1 ), $all_active, 10 ) );

			$this->assertCount( 1, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * filter_on_sale() returns the (reindexed) filter result, not the original set.
	 *
	 * Kills the line-319 array_values() removal (result would keep the filter's
	 * gappy keys) and the ternary swap (which would return $on_sale instead).
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_filter_on_sale_returns_reindexed_filtered_result(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$t1 = TicketType::from_row( $this->make_ticket_row( array( 'id' => 1 ) ) );
			$t2 = TicketType::from_row( $this->make_ticket_row( array( 'id' => 2 ) ) );
			$t3 = TicketType::from_row( $this->make_ticket_row( array( 'id' => 3 ) ) );

			// Filter returns a different, non-sequentially keyed set.
			\Brain\Monkey\Filters\expectApplied( 'nettertech_events_on_sale_ticket_types' )
				->once()
				->andReturn( array( 3 => $t2, 7 => $t3 ) );

			$result = $this->invoke_private( $repo, 'filter_on_sale', array( array( $t1 ), array( $t1 ), 10 ) );

			// array_values kept: keys reindexed; ternary intact: filtered set (2,3) returned, not $on_sale (1).
			$this->assertSame( array( 0, 1 ), array_keys( $result ) );
			$this->assertSame( 2, $result[0]->id );
			$this->assertSame( 3, $result[1]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * filter_on_sale() falls back to the on-sale set when the filter returns a non-array.
	 *
	 * Kills the line-319 ternary swap: with `is_array( $filtered ) ? array_values( $filtered ) : $on_sale`
	 * collapsed to its first branch, a subscriber that returns a non-array would reach
	 * array_values() with a non-array argument (a TypeError) instead of the guarded
	 * fallback that returns the original on-sale set.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_filter_on_sale_falls_back_when_filter_returns_non_array(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$t1      = TicketType::from_row( $this->make_ticket_row( array( 'id' => 1 ) ) );
			$on_sale = array( $t1 );

			// A misbehaving subscriber returns a non-array; the guard must fall back.
			\Brain\Monkey\Filters\expectApplied( 'nettertech_events_on_sale_ticket_types' )
				->once()
				->andReturn( null );

			$result = $this->invoke_private( $repo, 'filter_on_sale', array( $on_sale, $on_sale, 10 ) );

			// Ternary intact: is_array( null ) is false, so the original on-sale set is returned.
			$this->assertSame( $on_sale, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Mutation-hardening — timezones_for_occurrences() id pipeline
	// =========================================================================

	/**
	 * IDs are intval-cast before the query, collapsing fractional duplicates.
	 *
	 * Kills the array_map('intval') removal at line 335: without it, 5.9 and 5.1
	 * would survive as two distinct bound values.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_timezones_query_intval_casts_ids(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured = array();
			$mock_wpdb->method( 'prepare' )->willReturnCallback(
				function ( ...$args ) use ( &$captured ) {
					$captured = $args;
					return $args[0];
				}
			);
			$mock_wpdb->method( 'get_results' )->willReturn( array() );

			$this->invoke_private( $repo, 'timezones_for_occurrences', array( array( 5.9, 5.1 ) ) );

			$this->assertSame( 1, substr_count( $captured[0], '%d' ) );
			$this->assertSame( array( 5 ), array_slice( $captured, 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * IDs are filtered (drop 0) and deduped before the query.
	 *
	 * Kills the array_filter and array_unique removals at line 335: either would
	 * inflate the placeholder count and bound-value list.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_timezones_query_filters_zero_and_dedupes_ids(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured = array();
			$mock_wpdb->method( 'prepare' )->willReturnCallback(
				function ( ...$args ) use ( &$captured ) {
					$captured = $args;
					return $args[0];
				}
			);
			$mock_wpdb->method( 'get_results' )->willReturn( array() );

			$this->invoke_private( $repo, 'timezones_for_occurrences', array( array( 0, 5, 5, 3 ) ) );

			// 0 removed, duplicate 5 collapsed => exactly two placeholders and bound ids.
			$this->assertSame( 2, substr_count( $captured[0], '%d' ) );
			$this->assertSame( array( 5, 3 ), array_slice( $captured, 1 ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Each row with a readable zone is mapped by its occurrence id.
	 *
	 * Kills the empty-foreach mutation (no rows processed), the identical-operator
	 * flip at line 357 (would skip non-empty names), and the array-one-item
	 * truncation at line 368 (would return only the first zone).
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_timezones_maps_each_valid_row_to_its_zone(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )->willReturn(
				array(
					(object) array( 'id' => 10, 'timezone' => 'America/Chicago' ),
					(object) array( 'id' => 20, 'timezone' => 'America/New_York' ),
				)
			);

			$zones = $this->invoke_private( $repo, 'timezones_for_occurrences', array( array( 10, 20 ) ) );

			$this->assertCount( 2, $zones );
			$this->assertInstanceOf( \DateTimeZone::class, $zones[10] );
			$this->assertInstanceOf( \DateTimeZone::class, $zones[20] );
			$this->assertSame( 'America/Chicago', $zones[10]->getName() );
			$this->assertSame( 'America/New_York', $zones[20]->getName() );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * An empty stored zone is skipped and iteration continues to the next row.
	 *
	 * Kills the continue->break mutation at line 358: a break would abandon every
	 * row after the first empty one, dropping the later valid zone.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_timezones_skips_empty_zone_and_continues(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )->willReturn(
				array(
					(object) array( 'id' => 10, 'timezone' => '' ),
					(object) array( 'id' => 20, 'timezone' => 'America/New_York' ),
				)
			);

			$zones = $this->invoke_private( $repo, 'timezones_for_occurrences', array( array( 10, 20 ) ) );

			$this->assertArrayNotHasKey( 10, $zones );
			$this->assertArrayHasKey( 20, $zones );
			$this->assertSame( 'America/New_York', $zones[20]->getName() );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * The stored zone name is cast to string before DateTimeZone construction.
	 *
	 * Kills the (string) removal at line 355: an integer zone value would reach
	 * new DateTimeZone() as an int and raise an uncaught TypeError instead of the
	 * caught Exception the string path produces (yielding an empty map).
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::get_on_sale_for_occurrence
	 */
	public function test_timezones_casts_zone_name_to_string(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )->willReturn(
				array( (object) array( 'id' => 10, 'timezone' => 123 ) )
			);

			$zones = $this->invoke_private( $repo, 'timezones_for_occurrences', array( array( 10 ) ) );

			// '123' is an invalid zone name — caught as an Exception, so the id is omitted.
			$this->assertSame( array(), $zones );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Mutation-hardening — reduce_event_capacity() capacity_type coercion
	// =========================================================================

	/**
	 * Build a Stringable whose string form is a capacity-type keyword.
	 *
	 * The house rule compares capacity_type by strict identity against the enum's
	 * string value, so a non-string that only *stringifies* to the keyword lets us
	 * observe whether the coercion at line 570 actually runs.
	 *
	 * @param string $value The capacity-type keyword to stringify to.
	 * @return \Stringable
	 */
	private function stringable_capacity_type( string $value ): \Stringable {
		return new class( $value ) implements \Stringable {
			/**
			 * @var string
			 */
			private string $value;

			/**
			 * @param string $value Keyword to return from __toString().
			 */
			public function __construct( string $value ) {
				$this->value = $value;
			}

			/**
			 * @return string
			 */
			public function __toString(): string {
				return $this->value;
			}
		};
	}

	/**
	 * reduce_event_capacity() coerces capacity_type to string before the house rule.
	 *
	 * Kills the (string) removal at line 570. HouseRule::house() decides a tier is
	 * unlimited via `CapacityType::UNLIMITED->value === $tier['capacity_type']` — a
	 * strict comparison against the string 'unlimited'. A capacity_type that is a
	 * Stringable('unlimited') only satisfies that identity once cast; without the
	 * cast the object fails the comparison, the tier is treated as a bounded 50-seat
	 * house, and the event reports a finite capacity instead of unlimited.
	 *
	 * @covers \NetterTechEvents\Repositories\TicketTypeQueryRepository::event_capacity_for_events
	 */
	public function test_event_capacity_casts_capacity_type_to_string(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$occ = array( $this->occ_row( 100, 5, null ) ); // Uncapped house.

			$tt_row                = new \stdClass();
			$tt_row->event_id      = 5;
			$tt_row->occurrence_id = 100;
			$tt_row->capacity      = 50;
			$tt_row->capacity_type = $this->stringable_capacity_type( 'unlimited' );
			$tt_row->occ_event_id  = 5;

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls( $occ, array( $tt_row ) );

			$result = $repo->event_capacity_for_events( array( 5 ) );

			// With the cast, capacity_type === 'unlimited' holds, so the house is unbounded.
			$this->assertTrue( $result[5]['has_unlimited'] );
			$this->assertNull( $result[5]['capacity'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}
}
