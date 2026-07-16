<?php
/**
 * Tests for HouseCapacityRepository.
 *
 * @package NetterTechEvents\Tests
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\HouseCapacityRepository;

/**
 * Test the assembly of a ticket type's house context.
 *
 * @covers \NetterTechEvents\Repositories\HouseCapacityRepository
 */
class HouseCapacityRepositoryTest extends \NetterTechEventsTestCase {

	/**
	 * Build a mock wpdb and a repository around it.
	 *
	 * @return array{0: \PHPUnit\Framework\MockObject\MockObject, 1: HouseCapacityRepository, 2: mixed}
	 */
	private function create_repo(): array {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'get_row', 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';
		$mock_wpdb->method( 'prepare' )->willReturnCallback( fn( $sql ) => $sql );

		$wpdb = $mock_wpdb;

		return array( $mock_wpdb, new HouseCapacityRepository( $mock_wpdb ), $original_wpdb );
	}

	/**
	 * Build a peer row.
	 *
	 * @param int      $id       Ticket type ID.
	 * @param int|null $capacity Tier capacity.
	 * @param int      $sold     Seats sold.
	 * @param int      $reserved Seats held.
	 * @param string   $type     Capacity type.
	 * @param string   $status   Tier status.
	 * @return \stdClass
	 */
	private function peer( int $id, ?int $capacity, int $sold, int $reserved = 0, string $type = 'fixed', string $status = 'active' ): \stdClass {
		$row                = new \stdClass();
		$row->id            = $id;
		$row->capacity      = $capacity;
		$row->capacity_type = $type;
		$row->sold_count    = $sold;
		$row->reserved      = $reserved;
		$row->status        = $status;

		return $row;
	}

	/**
	 * Build the "self" row the repository reads first.
	 *
	 * @param int      $id            Ticket type ID.
	 * @param int|null $capacity      Tier capacity.
	 * @param int      $sold          Seats sold.
	 * @param int|null $ceiling       Occurrence capacity.
	 * @param string   $type          Capacity type.
	 * @param int|null $occurrence_id Occurrence ID.
	 * @return \stdClass
	 */
	private function self_row( int $id, ?int $capacity, int $sold, ?int $ceiling, string $type = 'fixed', ?int $occurrence_id = 7 ): \stdClass {
		$row                = new \stdClass();
		$row->id            = $id;
		$row->capacity      = $capacity;
		$row->capacity_type = $type;
		$row->sold_count    = $sold;
		$row->occurrence_id = $occurrence_id;
		$row->event_id      = null;
		$row->ceiling       = $ceiling;

		return $row;
	}

	/**
	 * Build an occurrence row for house_context_for_occurrence().
	 *
	 * @param int      $id       Occurrence ID.
	 * @param int|null $capacity Occurrence capacity (ceiling).
	 * @return \stdClass
	 */
	private function occ_row( int $id, ?int $capacity ): \stdClass {
		$row           = new \stdClass();
		$row->id       = $id;
		$row->capacity = $capacity;

		return $row;
	}

	/**
	 * Restore the global wpdb.
	 *
	 * @param mixed $original_wpdb Original wpdb.
	 * @return void
	 */
	private function restore( $original_wpdb ): void {
		global $wpdb;
		$wpdb = $original_wpdb;
	}

	/**
	 * Test the house of three tiers listing one hall is that one hall.
	 *
	 * @return void
	 */
	public function test_context_collapses_tiers_onto_one_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->self_row( 1, 250, 101, null ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array(
					$this->peer( 1, 250, 101 ),
					$this->peer( 2, 250, 5 ),
					$this->peer( 3, 250, 6 ),
				),
				array()
			);

			$context = $repo->context_for_ticket_type( 1 );

			$this->assertSame( 250, $context['house'] );
			$this->assertSame( 112, $context['house_sold'] );
			$this->assertSame( 250, $context['own_capacity'] );
			$this->assertSame( 101, $context['own_sold'] );
			$this->assertSame( array( 1, 2, 3 ), $context['peer_ids'] );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test the occurrence ceiling becomes the house.
	 *
	 * @return void
	 */
	public function test_context_prefers_the_occurrence_ceiling(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->self_row( 1, null, 12, 300, 'shared' ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array(
					$this->peer( 1, null, 12, 0, 'shared' ),
					$this->peer( 2, 50, 4 ),
				),
				array()
			);

			$context = $repo->context_for_ticket_type( 1 );

			$this->assertSame( 300, $context['house'] );
			$this->assertSame( 16, $context['house_sold'] );
			$this->assertNull( $context['own_capacity'] );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test held seats are aggregated across the whole house.
	 *
	 * @return void
	 */
	public function test_context_sums_holds_across_the_house(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->self_row( 1, 100, 10, null ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array(
					$this->peer( 1, 100, 10, 3 ),
					$this->peer( 2, 100, 5, 7 ),
				),
				array()
			);

			$context = $repo->context_for_ticket_type( 1 );

			$this->assertSame( 10, $context['house_reserved'] );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test a retired tier keeps its sold seats but cannot enlarge the room.
	 *
	 * @return void
	 */
	public function test_context_excludes_retired_tiers_from_sizing_but_not_from_sales(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->self_row( 1, 100, 60, null ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array(
					$this->peer( 1, 100, 60 ),
					$this->peer( 2, 500, 40, 0, 'fixed', 'inactive' ),
				),
				array()
			);

			$context = $repo->context_for_ticket_type( 1 );

			// The retired 500-seat tier does not make the room 500 seats...
			$this->assertSame( 100, $context['house'] );
			// ...but the 40 seats it sold are still occupied.
			$this->assertSame( 100, $context['house_sold'] );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test a missing ticket type yields no context.
	 *
	 * @return void
	 */
	public function test_context_is_null_for_an_unknown_ticket_type(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( null );

			$this->assertNull( $repo->context_for_ticket_type( 999 ) );
			$this->assertSame( array(), $repo->house_peer_ids( 999 ) );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test peer IDs name every tier sharing the room.
	 *
	 * @return void
	 */
	public function test_house_peer_ids_lists_the_room(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->self_row( 1, 100, 0, null ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array(
					$this->peer( 1, 100, 0 ),
					$this->peer( 2, 100, 0 ),
				),
				array()
			);

			$this->assertSame( array( 1, 2 ), $repo->house_peer_ids( 1 ) );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test an occurrence tier's context counts the event's pass sales but is not
	 * sized by them (NTE-156).
	 *
	 * The pass occupies a seat on every date, so its sold seats join the house's
	 * sold count — yet a pass's own allotment never enlarges any single room, so the
	 * house stays the occurrence tier's 100.
	 *
	 * @return void
	 */
	public function test_context_counts_pass_sales_but_is_not_sized_by_them(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->self_row( 1, 100, 10, null ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array( $this->peer( 1, 100, 10 ) ),
				array( $this->peer( 50, 50, 7 ) ),
			);

			$context = $repo->context_for_ticket_type( 1 );

			// The 50-seat pass does not size the room; the occurrence tier's 100 does.
			$this->assertSame( 100, $context['house'] );
			// The pass's 7 sold seats occupy this date's room alongside the tier's 10.
			$this->assertSame( 17, $context['house_sold'] );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test house_context_for_occurrence resolves one date's room, seats, and holds.
	 *
	 * @return void
	 */
	public function test_house_context_for_occurrence_resolves_the_room(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->occ_row( 5, 200 ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array( $this->peer( 1, 200, 20, 3 ) ),
				array( $this->peer( 50, 50, 7, 2 ) ),
			);

			$context = $repo->house_context_for_occurrence( 5 );

			// The occurrence ceiling of 200 is the room; the pass does not enlarge it.
			$this->assertSame( 200, $context['house'] );
			// Both the tier's 20 and the pass's 7 sold seats are occupied here.
			$this->assertSame( 27, $context['house_sold'] );
			// Holds are summed across the tier (3) and the pass (2).
			$this->assertSame( 5, $context['house_reserved'] );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test an occurrence with no ceiling and no tiers is unbounded, not zero.
	 *
	 * An empty peer set on a date must read as "no room to run out of" (null), not
	 * as a zero-seat room — the peer set may legitimately be passes only, or empty.
	 *
	 * @return void
	 */
	public function test_house_context_for_occurrence_is_unbounded_when_empty(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->occ_row( 5, null ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array(),
				array(),
			);

			$context = $repo->house_context_for_occurrence( 5 );

			$this->assertNull( $context['house'] );
			$this->assertSame( 0, $context['house_sold'] );
			$this->assertSame( 0, $context['house_reserved'] );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test house_context_for_occurrence yields null for an unknown occurrence.
	 *
	 * @return void
	 */
	public function test_house_context_for_occurrence_is_null_for_unknown_occurrence(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( null );

			$this->assertNull( $repo->house_context_for_occurrence( 999 ) );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test a ceiling-less date is still sized by its tiers, not read as unbounded.
	 *
	 * With no occurrence ceiling but a real sizing tier present, the room is that
	 * tier's size — the "no room to run out of" null is reserved for the case where
	 * BOTH the ceiling and the sizing set are absent. Kills the LogicalAnd→LogicalOr
	 * mutant on that guard (`null === $ceiling || empty( $sizing )`), which would
	 * null out the house the moment either half held.
	 *
	 * @return void
	 */
	public function test_house_context_for_occurrence_sizes_from_tiers_without_a_ceiling(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->occ_row( 5, null ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array( $this->peer( 1, 120, 10 ) ),
				array(),
			);

			$context = $repo->house_context_for_occurrence( 5 );

			// No ceiling, but a 120-seat tier: the room is 120, not unbounded.
			$this->assertSame( 120, $context['house'] );
			$this->assertSame( 10, $context['house_sold'] );
		} finally {
			$this->restore( $original_wpdb );
		}
	}

	/**
	 * Test a pass tier never enlarges the room it sits in, even when it is larger.
	 *
	 * A pass row is flagged is_pass so sizing_tiers() drops it from the room's size:
	 * its allotment counts passes across the whole event, not chairs on one date.
	 * With a ceiling-less date, a 500-pass and a 100-tier, the room must stay 100.
	 * Kills the TrueValue mutant (`$pass_row->is_pass = false`), which would let the
	 * pass through the sizing filter and inflate the house to 500.
	 *
	 * @return void
	 */
	public function test_house_context_for_occurrence_pass_does_not_enlarge_the_room(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )->willReturn( $this->occ_row( 5, null ) );
			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array( $this->peer( 1, 100, 10 ) ),
				array( $this->peer( 50, 500, 7 ) ),
			);

			$context = $repo->house_context_for_occurrence( 5 );

			// The 500-seat pass is excluded from sizing; the 100-seat tier is the room.
			$this->assertSame( 100, $context['house'] );
			// Its 7 sold seats still occupy this date alongside the tier's 10.
			$this->assertSame( 17, $context['house_sold'] );
		} finally {
			$this->restore( $original_wpdb );
		}
	}
}
