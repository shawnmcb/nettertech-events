<?php
/**
 * SaleWindowInput unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\SaleWindowInput;

/**
 * Tests the shared sale-window boundary composer used by every ticket save
 * path (TicketSaveHandler and both TicketTypeSaver paths — NTE-190).
 */
class SaleWindowInputTest extends \NetterTechEventsTestCase {

	/**
	 * Split fields recombine to the exact legacy datetime-local wire format.
	 *
	 * @return void
	 */
	public function test_composes_split_fields_to_legacy_format(): void {
		$data = array(
			'sale_start_date' => '2026-03-01',
			'sale_start_time' => '09:00',
		);

		$this->assertSame(
			'2026-03-01T09:00',
			SaleWindowInput::compose( $data, 'sale_start', SaleWindowInput::DEFAULT_START_TIME )
		);
	}

	/**
	 * Date-only boundaries take the explicit per-boundary default time.
	 *
	 * @return void
	 */
	public function test_date_only_uses_boundary_default_time(): void {
		$data = array(
			'sale_start_date' => '2026-03-01',
			'sale_end_date'   => '2026-03-15',
			'sale_end_time'   => '',
		);

		$this->assertSame( '2026-03-01T00:00', SaleWindowInput::compose( $data, 'sale_start', SaleWindowInput::DEFAULT_START_TIME ) );
		$this->assertSame( '2026-03-15T23:59', SaleWindowInput::compose( $data, 'sale_end', SaleWindowInput::DEFAULT_END_TIME ) );
	}

	/**
	 * A time without a date is not a boundary; blank everything is unset.
	 *
	 * @return void
	 */
	public function test_time_without_date_and_blanks_yield_unset(): void {
		$this->assertSame( '', SaleWindowInput::compose( array( 'sale_end_time' => '18:00' ), 'sale_end', SaleWindowInput::DEFAULT_END_TIME ) );
		$this->assertSame( '', SaleWindowInput::compose( array(), 'sale_start', SaleWindowInput::DEFAULT_START_TIME ) );
	}

	/**
	 * Combined legacy keys stay honored (REST clients, extensions) and split
	 * fields win over a combined key when both are present.
	 *
	 * @return void
	 */
	public function test_combined_key_compatibility_and_precedence(): void {
		$this->assertSame(
			'2026-03-01 09:00:00',
			SaleWindowInput::compose( array( 'sale_start' => '2026-03-01 09:00:00' ), 'sale_start', SaleWindowInput::DEFAULT_START_TIME )
		);

		$both = array(
			'sale_start'      => '2020-01-01 00:00:00',
			'sale_start_date' => '2026-03-01',
			'sale_start_time' => '09:00',
		);
		$this->assertSame( '2026-03-01T09:00', SaleWindowInput::compose( $both, 'sale_start', SaleWindowInput::DEFAULT_START_TIME ) );
	}

	/**
	 * Malformed parts never compose garbage.
	 *
	 * @return void
	 */
	public function test_malformed_parts_rejected(): void {
		$this->assertSame( '', SaleWindowInput::compose( array( 'sale_start_date' => 'banana' ), 'sale_start', SaleWindowInput::DEFAULT_START_TIME ) );
		$this->assertSame(
			'2026-03-01T00:00',
			SaleWindowInput::compose(
				array(
					'sale_start_date' => '2026-03-01',
					'sale_start_time' => '25:99x',
				),
				'sale_start',
				SaleWindowInput::DEFAULT_START_TIME
			)
		);
	}
}
