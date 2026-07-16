<?php
/**
 * LayoutAssignments unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer;

use Brain\Monkey\Functions;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\Context;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\LayoutAssignments;

/**
 * Tests for the assignments option storage.
 *
 * @coversDefaultClass \NetterTechEvents\Integrations\BeaverBuilder\Themer\LayoutAssignments
 */
class LayoutAssignmentsTest extends \NetterTechEventsTestCase {

	/**
	 * all() returns a fully populated canonical map when the option is empty.
	 *
	 * @return void
	 */
	public function test_all_returns_canonical_shape_when_option_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$result = LayoutAssignments::all();

		foreach ( Context::ALL_KEYS as $context_key ) {
			$this->assertArrayHasKey( $context_key, $result );
			$this->assertArrayHasKey( LayoutAssignments::SLOT_HEADER, $result[ $context_key ] );
			$this->assertArrayHasKey( LayoutAssignments::SLOT_FOOTER, $result[ $context_key ] );
			$this->assertSame( 0, $result[ $context_key ][ LayoutAssignments::SLOT_HEADER ] );
			$this->assertSame( 0, $result[ $context_key ][ LayoutAssignments::SLOT_FOOTER ] );
		}
	}

	/**
	 * all() coerces a non-array stored value to the canonical empty shape.
	 *
	 * Real WordPress installations have been known to land non-array values
	 * in option storage after migrations or third-party tampering; the
	 * accessor must not warn under that condition.
	 *
	 * @return void
	 */
	public function test_all_coerces_non_array_option_value(): void {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );

		$result = LayoutAssignments::all();

		$this->assertSame( 0, $result[ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_HEADER ] );
	}

	/**
	 * all() reads values from the option when present.
	 *
	 * @return void
	 */
	public function test_all_reads_stored_values(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				Context::EVENTS_ARCHIVE => array(
					LayoutAssignments::SLOT_HEADER => 42,
					LayoutAssignments::SLOT_FOOTER => 99,
				),
			)
		);

		$result = LayoutAssignments::all();

		$this->assertSame( 42, $result[ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_HEADER ] );
		$this->assertSame( 99, $result[ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_FOOTER ] );
		// Untouched contexts default to 0.
		$this->assertSame( 0, $result[ Context::SINGLE_SPACE ][ LayoutAssignments::SLOT_HEADER ] );
	}

	/**
	 * sanitize() strips unknown context keys.
	 *
	 * @return void
	 */
	public function test_sanitize_strips_unknown_context_keys(): void {
		$result = LayoutAssignments::sanitize(
			array(
				'rogue_context'         => array( LayoutAssignments::SLOT_HEADER => 1 ),
				Context::EVENTS_ARCHIVE => array( LayoutAssignments::SLOT_HEADER => 7 ),
			)
		);

		$this->assertArrayNotHasKey( 'rogue_context', $result );
		$this->assertSame( 7, $result[ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_HEADER ] );
	}

	/**
	 * sanitize() strips unknown slot keys but keeps the context.
	 *
	 * @return void
	 */
	public function test_sanitize_strips_unknown_slot_keys(): void {
		$result = LayoutAssignments::sanitize(
			array(
				Context::EVENTS_ARCHIVE => array(
					'rogue_slot'                    => 42,
					LayoutAssignments::SLOT_HEADER => 11,
				),
			)
		);

		$this->assertArrayNotHasKey( 'rogue_slot', $result[ Context::EVENTS_ARCHIVE ] );
		$this->assertSame( 11, $result[ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_HEADER ] );
	}

	/**
	 * sanitize() coerces values to non-negative integers.
	 *
	 * String "42" => 42, "abc" => 0, -5 => 0, null => 0.
	 *
	 * @return void
	 */
	public function test_sanitize_coerces_values_to_non_negative_int(): void {
		$result = LayoutAssignments::sanitize(
			array(
				Context::EVENTS_ARCHIVE      => array( LayoutAssignments::SLOT_HEADER => '42' ),
				Context::PAST_EVENTS_ARCHIVE => array( LayoutAssignments::SLOT_HEADER => 'abc' ),
				Context::SINGLE_EVENT        => array( LayoutAssignments::SLOT_HEADER => -5 ),
				Context::SINGLE_OCCURRENCE   => array( LayoutAssignments::SLOT_HEADER => null ),
			)
		);

		$this->assertSame( 42, $result[ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_HEADER ] );
		$this->assertSame( 0, $result[ Context::PAST_EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_HEADER ] );
		$this->assertSame( 0, $result[ Context::SINGLE_EVENT ][ LayoutAssignments::SLOT_HEADER ] );
		$this->assertSame( 0, $result[ Context::SINGLE_OCCURRENCE ][ LayoutAssignments::SLOT_HEADER ] );
	}

	/**
	 * save() round-trips through sanitize() and calls update_option.
	 *
	 * @return void
	 */
	public function test_save_persists_sanitized_array(): void {
		$captured = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload ) use ( &$captured ) {
				$captured = array(
					'key'      => $key,
					'value'    => $value,
					'autoload' => $autoload,
				);
				return true;
			}
		);

		$ok = LayoutAssignments::save(
			array(
				Context::EVENTS_ARCHIVE => array(
					LayoutAssignments::SLOT_HEADER => '17',
				),
				'rogue_key'             => array( 'anything' => 1 ),
			)
		);

		$this->assertTrue( $ok );
		$this->assertSame( LayoutAssignments::OPTION_KEY, $captured['key'] );
		$this->assertSame( 17, $captured['value'][ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_HEADER ] );
		$this->assertArrayNotHasKey( 'rogue_key', $captured['value'] );
		$this->assertFalse( $captured['autoload'], 'Option should not be autoloaded.' );
	}

	/**
	 * SLOTS contains exactly header and footer in declaration order.
	 *
	 * @return void
	 */
	public function test_slots_constant_lists_header_and_footer(): void {
		$this->assertSame(
			array( LayoutAssignments::SLOT_HEADER, LayoutAssignments::SLOT_FOOTER ),
			LayoutAssignments::SLOTS
		);
	}
}
