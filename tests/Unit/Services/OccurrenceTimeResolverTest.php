<?php
/**
 * OccurrenceTimeResolver unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Services\OccurrenceTimeResolver;

/**
 * Test the shared derivation-and-validation path (NTE-189).
 */
class OccurrenceTimeResolverTest extends \NetterTechEventsTestCase {

	/**
	 * Set up common stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	/**
	 * All-day events span midnight to end-of-day; nothing is flagged as derived.
	 *
	 * @return void
	 */
	public function test_all_day_spans_full_day(): void {
		$times = OccurrenceTimeResolver::derive_times( '2026-09-03', '', '', true );

		$this->assertSame( '00:00', $times['start_time'] );
		$this->assertSame( '23:59', $times['end_time'] );
		$this->assertSame( array(), $times['derived'] );
	}

	/**
	 * A blank start uses the default start time and a blank end start + default duration.
	 *
	 * @return void
	 */
	public function test_blank_times_use_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() ); // Ship defaults: 19:00 / 120 min.

		$times = OccurrenceTimeResolver::derive_times( '2026-09-03', '', '', false );

		$this->assertSame( '19:00', $times['start_time'] );
		$this->assertSame( '21:00', $times['end_time'] );
		$this->assertContains( 'start time', $times['derived'] );
		$this->assertContains( 'end time', $times['derived'] );
	}

	/**
	 * A supplied start with a blank end derives end from the configured duration.
	 *
	 * @return void
	 */
	public function test_blank_end_derives_from_duration(): void {
		Functions\when( 'get_option' )->justReturn( array( 'default_event_duration_minutes' => 90 ) );

		$times = OccurrenceTimeResolver::derive_times( '2026-09-03', '14:30', '', false );

		$this->assertSame( '14:30', $times['start_time'] );
		$this->assertSame( '16:00', $times['end_time'] );
		$this->assertSame( array( 'end time' ), $times['derived'] );
	}

	/**
	 * With require_end_time enabled, a blank end is a validation error.
	 *
	 * @return void
	 */
	public function test_require_end_time_blocks_blank_end(): void {
		Functions\when( 'get_option' )->justReturn( array( 'require_end_time' => true ) );

		$this->expectException( ValidationException::class );

		OccurrenceTimeResolver::derive_times( '2026-09-03', '14:30', '', false, '2026-09-03' );
	}

	/**
	 * A valid span passes validation silently.
	 *
	 * @return void
	 */
	public function test_validate_span_accepts_valid_span(): void {
		OccurrenceTimeResolver::validate_span(
			new \DateTimeImmutable( '2026-09-03 14:00:00' ),
			new \DateTimeImmutable( '2026-09-03 16:00:00' )
		);

		$this->assertTrue( true );
	}

	/**
	 * A sub-10-minute span is rejected.
	 *
	 * @return void
	 */
	public function test_validate_span_rejects_short_span(): void {
		$this->expectException( ValidationException::class );

		OccurrenceTimeResolver::validate_span(
			new \DateTimeImmutable( '2026-09-03 14:00:00' ),
			new \DateTimeImmutable( '2026-09-03 14:05:00' )
		);
	}

	/**
	 * An inverted span (end before start) is rejected by the same check.
	 *
	 * @return void
	 */
	public function test_validate_span_rejects_inverted_span(): void {
		$this->expectException( ValidationException::class );

		OccurrenceTimeResolver::validate_span(
			new \DateTimeImmutable( '2026-09-03 20:00:00' ),
			new \DateTimeImmutable( '2026-09-03 19:00:00' )
		);
	}
}
