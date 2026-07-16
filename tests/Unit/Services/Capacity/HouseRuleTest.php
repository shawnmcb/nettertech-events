<?php
/**
 * Tests for the house rule.
 *
 * @package NetterTechEvents\Tests
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services\Capacity;

use NetterTechEvents\Services\Capacity\HouseRule;

/**
 * Test HouseRule — the single definition of how big a room is.
 *
 * @covers \NetterTechEvents\Services\Capacity\HouseRule
 */
class HouseRuleTest extends \NetterTechEventsTestCase {

	/**
	 * Build a tier descriptor.
	 *
	 * @param int|null $capacity Tier capacity.
	 * @param string   $type     Capacity type.
	 * @return array{capacity: ?int, capacity_type: string}
	 */
	private function tier( ?int $capacity, string $type = 'fixed' ): array {
		return array(
			'capacity'      => $capacity,
			'capacity_type' => $type,
		);
	}

	/**
	 * Three tiers listing the same hall describe one hall, not three.
	 *
	 * @return void
	 */
	public function test_tiers_sharing_a_room_are_never_summed(): void {
		$tiers = array( $this->tier( 250 ), $this->tier( 250 ), $this->tier( 250 ) );

		$this->assertSame( 250, HouseRule::house( $tiers, null ) );
	}

	/**
	 * Without a ceiling, the largest tier stands in for the room.
	 *
	 * @return void
	 */
	public function test_largest_tier_stands_in_for_the_room(): void {
		$tiers = array( $this->tier( 50 ), $this->tier( 250 ), $this->tier( 120 ) );

		$this->assertSame( 250, HouseRule::house( $tiers, null ) );
	}

	/**
	 * An occurrence ceiling states the room's size outright.
	 *
	 * @return void
	 */
	public function test_ceiling_wins_over_the_tiers(): void {
		$tiers = array( $this->tier( 50 ), $this->tier( 999 ) );

		$this->assertSame( 300, HouseRule::house( $tiers, 300 ) );
	}

	/**
	 * Under a ceiling, an unlimited tier means "no limit of its own", not "no limit".
	 *
	 * @return void
	 */
	public function test_unlimited_tier_under_a_ceiling_does_not_unbound_the_room(): void {
		$tiers = array( $this->tier( null, 'unlimited' ), $this->tier( 50 ) );

		$this->assertSame( 300, HouseRule::house( $tiers, 300 ) );
	}

	/**
	 * Without a ceiling, an unlimited tier leaves the room unbounded.
	 *
	 * @return void
	 */
	public function test_unlimited_tier_without_a_ceiling_unbounds_the_room(): void {
		$tiers = array( $this->tier( 50 ), $this->tier( null, 'unlimited' ) );

		$this->assertNull( HouseRule::house( $tiers, null ) );
	}

	/**
	 * A tier carrying no capacity at all is unbounded when nothing else bounds it.
	 *
	 * @return void
	 */
	public function test_tier_without_capacity_unbounds_the_room(): void {
		$this->assertNull( HouseRule::house( array( $this->tier( null, 'shared' ) ), null ) );
	}

	/**
	 * A room with no tiers and no ceiling seats nobody.
	 *
	 * @return void
	 */
	public function test_no_tiers_and_no_ceiling_is_an_empty_room(): void {
		$this->assertSame( 0, HouseRule::house( array(), null ) );
	}

	/**
	 * A negative ceiling never yields negative seats.
	 *
	 * @return void
	 */
	public function test_ceiling_never_goes_negative(): void {
		$this->assertSame( 0, HouseRule::house( array(), -5 ) );
	}

	/**
	 * The lesser of the two bounds wins.
	 *
	 * @return void
	 */
	public function test_bound_takes_the_lesser_limit(): void {
		$this->assertSame( 46, HouseRule::bound( 46, 268 ) );
		$this->assertSame( 138, HouseRule::bound( 149, 138 ) );
	}

	/**
	 * An absent bound does not bind.
	 *
	 * @return void
	 */
	public function test_bound_ignores_absent_limits(): void {
		$this->assertSame( 138, HouseRule::bound( null, 138 ) );
		$this->assertSame( 46, HouseRule::bound( 46, null ) );
		$this->assertNull( HouseRule::bound( null, null ) );
	}

	/**
	 * Only a fixed tier's capacity column means anything.
	 *
	 * A shared or seated tier may carry a stale value there, because the ticket-type
	 * form hides the capacity input rather than clearing it.
	 *
	 * @return void
	 */
	public function test_own_remaining_reads_the_capacity_column_only_for_fixed_tiers(): void {
		$this->assertSame( 40, HouseRule::own_remaining( 'fixed', 50, 10 ) );
		$this->assertNull( HouseRule::own_remaining( 'shared', 999, 10 ) );
		$this->assertNull( HouseRule::own_remaining( 'seated', 999, 10 ) );
		$this->assertNull( HouseRule::own_remaining( 'unlimited', 999, 10 ) );
	}

	/**
	 * An unrecognised capacity type is treated as fixed, matching the column default.
	 *
	 * @return void
	 */
	public function test_own_remaining_treats_an_unknown_type_as_fixed(): void {
		$this->assertSame( 40, HouseRule::own_remaining( 'nonsense', 50, 10 ) );
	}

	/**
	 * A fixed tier with no capacity has no limit of its own.
	 *
	 * @return void
	 */
	public function test_own_remaining_is_absent_without_a_capacity(): void {
		$this->assertNull( HouseRule::own_remaining( 'fixed', null, 10 ) );
	}

	/**
	 * An oversold tier reports nothing left rather than a negative count.
	 *
	 * @return void
	 */
	public function test_own_remaining_never_goes_negative(): void {
		$this->assertSame( 0, HouseRule::own_remaining( 'fixed', 50, 80 ) );
	}
}
