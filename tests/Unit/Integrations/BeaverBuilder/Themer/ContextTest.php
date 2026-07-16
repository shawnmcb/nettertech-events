<?php
/**
 * Themer Context unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer;

use Brain\Monkey\Functions;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\Context;

/**
 * Test Context detection across NTE virtual URL patterns.
 *
 * Covers the resolution-order contract: past archive before plain archive,
 * single occurrence before single event, and the "no NTE context" fallthrough.
 *
 * @coversDefaultClass \NetterTechEvents\Integrations\BeaverBuilder\Themer\Context
 */
class ContextTest extends \NetterTechEventsTestCase {

	/**
	 * Stub get_query_var to return values from a given map (empty string otherwise).
	 *
	 * @param array<string, string> $overrides Query var => value map.
	 * @return void
	 */
	private function stub_query_vars( array $overrides = array() ): void {
		Functions\when( 'get_query_var' )->alias(
			function ( $name, $default = '' ) use ( $overrides ) {
				return $overrides[ $name ] ?? $default;
			}
		);
	}

	/**
	 * No NTE query vars => detect() returns null.
	 *
	 * @return void
	 */
	public function test_detect_returns_null_when_no_nte_query_vars(): void {
		$this->stub_query_vars();

		$this->assertNull( Context::detect() );
	}

	/**
	 * nettertech_events_archive=1 => EVENTS_ARCHIVE.
	 *
	 * @return void
	 */
	public function test_detect_events_archive(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );

		$this->assertSame( Context::EVENTS_ARCHIVE, Context::detect() );
	}

	/**
	 * nettertech_events_past_archive=1 => PAST_EVENTS_ARCHIVE.
	 *
	 * @return void
	 */
	public function test_detect_past_events_archive(): void {
		$this->stub_query_vars( array( 'nettertech_events_past_archive' => '1' ) );

		$this->assertSame( Context::PAST_EVENTS_ARCHIVE, Context::detect() );
	}

	/**
	 * Past-archive precedence: when both archive flags are set, past wins.
	 *
	 * Past archive lives at a sub-path of the events base (/events/archive/),
	 * so a request that matches both should resolve to the more specific one.
	 *
	 * @return void
	 */
	public function test_past_archive_takes_precedence_over_plain_archive(): void {
		$this->stub_query_vars(
			array(
				'nettertech_events_archive'      => '1',
				'nettertech_events_past_archive' => '1',
			)
		);

		$this->assertSame( Context::PAST_EVENTS_ARCHIVE, Context::detect() );
	}

	/**
	 * Event slug only => SINGLE_EVENT.
	 *
	 * @return void
	 */
	public function test_detect_single_event(): void {
		$this->stub_query_vars( array( 'nettertech_events_event_slug' => 'summer-concert' ) );

		$this->assertSame( Context::SINGLE_EVENT, Context::detect() );
	}

	/**
	 * Event slug + occurrence datetime => SINGLE_OCCURRENCE.
	 *
	 * @return void
	 */
	public function test_detect_single_occurrence(): void {
		$this->stub_query_vars(
			array(
				'nettertech_events_event_slug'           => 'weekly-jazz',
				'nettertech_events_occurrence_datetime'  => '2026-06-15-1900',
			)
		);

		$this->assertSame( Context::SINGLE_OCCURRENCE, Context::detect() );
	}

	/**
	 * Space slug => SINGLE_SPACE.
	 *
	 * @return void
	 */
	public function test_detect_single_space(): void {
		$this->stub_query_vars( array( 'nettertech_events_space_slug' => 'main-hall' ) );

		$this->assertSame( Context::SINGLE_SPACE, Context::detect() );
	}

	/**
	 * Empty-string query var values must not count as "set".
	 *
	 * @return void
	 */
	public function test_empty_string_query_vars_do_not_trigger_match(): void {
		$this->stub_query_vars(
			array(
				'nettertech_events_archive'      => '',
				'nettertech_events_past_archive' => '',
				'nettertech_events_event_slug'   => '',
				'nettertech_events_space_slug'   => '',
			)
		);

		$this->assertNull( Context::detect() );
	}

	/**
	 * "0" must not count as "set" for the boolean-ish archive query vars.
	 *
	 * Router writes 0 vs 1 integers; treating "0" as set would invert the gate.
	 *
	 * @return void
	 */
	public function test_zero_string_does_not_trigger_archive_match(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '0' ) );

		$this->assertNull( Context::detect() );
	}

	/**
	 * label() returns a non-empty localized string for every known key.
	 *
	 * @return void
	 */
	public function test_label_resolves_for_every_known_key(): void {
		Functions\when( '__' )->returnArg( 1 );

		foreach ( Context::ALL_KEYS as $key ) {
			$label = Context::label( $key );
			$this->assertIsString( $label );
			$this->assertNotSame( $key, $label, "label({$key}) must return a localized label, not the key." );
			$this->assertNotEmpty( $label );
		}
	}

	/**
	 * label() falls back to the key itself for unknown values.
	 *
	 * @return void
	 */
	public function test_label_falls_back_to_key_for_unknown_value(): void {
		Functions\when( '__' )->returnArg( 1 );

		$this->assertSame( 'totally_made_up', Context::label( 'totally_made_up' ) );
	}

	/**
	 * ALL_KEYS contains exactly the five public constants in declaration order.
	 *
	 * @return void
	 */
	public function test_all_keys_lists_every_context_constant(): void {
		$this->assertSame(
			array(
				Context::EVENTS_ARCHIVE,
				Context::PAST_EVENTS_ARCHIVE,
				Context::SINGLE_EVENT,
				Context::SINGLE_OCCURRENCE,
				Context::SINGLE_SPACE,
			),
			Context::ALL_KEYS
		);
	}
}
