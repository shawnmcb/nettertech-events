<?php
/**
 * RegularsShortcode unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Frontend\Shortcodes\RegularsShortcode;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\RRuleParser;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Test RegularsShortcode functionality.
 */
class RegularsShortcodeTest extends \NetterTechEventsTestCase {

	/**
	 * Event repository mock.
	 *
	 * @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Occurrence repository mock.
	 *
	 * @var OccurrenceRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Templates mock.
	 *
	 * @var Templates&\PHPUnit\Framework\MockObject\MockObject
	 */
	private Templates $templates;

	/**
	 * Shortcode under test.
	 *
	 * @var RegularsShortcode
	 */
	private RegularsShortcode $shortcode;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->event_repo      = $this->createMock( EventRepositoryInterface::class );
		$this->occurrence_repo = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->templates       = $this->createMock( Templates::class );

		$this->shortcode = new RegularsShortcode(
			$this->event_repo,
			$this->occurrence_repo,
			new RRuleParser(),
			$this->templates
		);

		Functions\when( 'shortcode_atts' )->alias(
			function ( array $defaults, array $atts ): array {
				return array_merge( $defaults, $atts );
			}
		);

		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $val ) {
			return abs( (int) $val );
		} );
	}

	/**
	 * Create a recurring event with a weekly RRULE.
	 *
	 * @param int    $id        Event ID.
	 * @param string $title     Event title.
	 * @param string $rrule     Recurrence rule.
	 * @param string $venue     Venue name.
	 * @return Event
	 */
	private function make_recurring_event(
		int $id = 1,
		string $title = 'Friday Jazz',
		string $rrule = 'FREQ=WEEKLY;BYDAY=FR',
		string $venue = 'The Black Box'
	): Event {
		$event                  = new Event();
		$event->id              = $id;
		$event->title           = $title;
		$event->slug            = sanitize_title( $title );
		$event->status          = EventStatus::PUBLISHED;
		$event->event_type      = 'recurring';
		$event->recurrence_rule = $rrule;
		$event->venue_name      = $venue;
		$event->post_id         = $id + 100;
		return $event;
	}

	/**
	 * Create a test occurrence.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $start    Start datetime.
	 * @param string $end      End datetime.
	 * @return Occurrence
	 */
	private function make_occurrence(
		int $event_id = 1,
		string $start = '2026-06-19 19:00:00',
		string $end = '2026-06-19 21:00:00'
	): Occurrence {
		$occ                 = new Occurrence();
		$occ->id             = $event_id * 100;
		$occ->event_id       = $event_id;
		$occ->start_datetime = $start;
		$occ->end_datetime   = $end;
		$occ->timezone       = 'America/Chicago';
		$occ->status         = 'scheduled';
		return $occ;
	}

	/**
	 * @testdox Renders empty state when no recurring events exist
	 */
	public function test_empty_state(): void {
		$this->event_repo->method( 'all' )->willReturn( array() );

		$this->templates->expects( $this->once() )
			->method( 'get_template_part' )
			->with( 'empty-state', $this->anything() )
			->willReturn( '<p>No weekly regulars.</p>' );

		$result = $this->shortcode->render();
		$this->assertSame( '<p>No weekly regulars.</p>', $result );
	}

	/**
	 * @testdox Renders regulars table with weekly recurring events
	 */
	public function test_renders_table_with_events(): void {
		$event = $this->make_recurring_event();
		$occ   = $this->make_occurrence();

		Functions\when( 'get_permalink' )->justReturn( 'http://example.test/event/friday-jazz/' );
		Functions\when( 'get_option' )->justReturn( 'g:i A' );

		$this->event_repo->method( 'all' )->willReturn( array( $event ) );
		$this->occurrence_repo->method( 'get_upcoming_by_event' )
			->with( 1, 1 )
			->willReturn( array( $occ ) );

		$this->templates->expects( $this->once() )
			->method( 'get_template_part' )
			->with(
				'regulars-table',
				$this->callback( function ( array $args ): bool {
					$this->assertArrayHasKey( 'rows', $args );
					$this->assertCount( 1, $args['rows'] );
					$this->assertSame( 'Friday', $args['rows'][0]['day_name'] );
					$this->assertSame( 'Friday Jazz', $args['rows'][0]['event_title'] );
					$this->assertSame( 'The Black Box', $args['rows'][0]['venue_name'] );
					return true;
				} )
			)
			->willReturn( '<table>rendered</table>' );

		$result = $this->shortcode->render();
		$this->assertSame( '<table>rendered</table>', $result );
	}

	/**
	 * @testdox Multi-day events produce multiple rows sorted by day order
	 */
	public function test_multi_day_event_produces_multiple_rows(): void {
		$event = $this->make_recurring_event( 1, 'MWF Yoga', 'FREQ=WEEKLY;BYDAY=MO,WE,FR', 'Studio A' );
		$occ   = $this->make_occurrence( 1, '2026-06-15 07:00:00', '2026-06-15 08:00:00' );

		Functions\when( 'get_permalink' )->justReturn( 'http://example.test/event/mwf-yoga/' );
		Functions\when( 'get_option' )->justReturn( 'g:i A' );

		$this->event_repo->method( 'all' )->willReturn( array( $event ) );
		$this->occurrence_repo->method( 'get_upcoming_by_event' )->willReturn( array( $occ ) );

		$this->templates->expects( $this->once() )
			->method( 'get_template_part' )
			->with(
				'regulars-table',
				$this->callback( function ( array $args ): bool {
					$this->assertCount( 3, $args['rows'] );
					$days = array_column( $args['rows'], 'day_name' );
					$this->assertSame( array( 'Monday', 'Wednesday', 'Friday' ), $days );
					return true;
				} )
			)
			->willReturn( '<table>3 rows</table>' );

		$this->shortcode->render();
	}

	/**
	 * @testdox Non-weekly events are excluded
	 */
	public function test_non_weekly_events_excluded(): void {
		$daily = $this->make_recurring_event( 1, 'Daily Thing', 'FREQ=DAILY', 'Room 1' );

		$this->event_repo->method( 'all' )->willReturn( array( $daily ) );

		$this->templates->expects( $this->once() )
			->method( 'get_template_part' )
			->with( 'empty-state', $this->anything() )
			->willReturn( '<p>Empty</p>' );

		$this->shortcode->render();
	}

	/**
	 * @testdox Events without recurrence rules are skipped
	 */
	public function test_events_without_rrule_skipped(): void {
		$event                  = $this->make_recurring_event();
		$event->recurrence_rule = '';

		$this->event_repo->method( 'all' )->willReturn( array( $event ) );

		$this->templates->expects( $this->once() )
			->method( 'get_template_part' )
			->with( 'empty-state', $this->anything() )
			->willReturn( '<p>Empty</p>' );

		$this->shortcode->render();
	}

	/**
	 * @testdox Rows are sorted by day order then time
	 */
	public function test_rows_sorted_by_day_then_time(): void {
		$friday = $this->make_recurring_event( 1, 'Friday Show', 'FREQ=WEEKLY;BYDAY=FR', 'Stage' );
		$monday = $this->make_recurring_event( 2, 'Monday Class', 'FREQ=WEEKLY;BYDAY=MO', 'Studio' );

		$friday_occ = $this->make_occurrence( 1, '2026-06-19 20:00:00', '2026-06-19 22:00:00' );
		$monday_occ = $this->make_occurrence( 2, '2026-06-15 10:00:00', '2026-06-15 11:00:00' );

		Functions\when( 'get_permalink' )->justReturn( 'http://example.test/event/test/' );
		Functions\when( 'get_option' )->justReturn( 'g:i A' );

		$this->event_repo->method( 'all' )->willReturn( array( $friday, $monday ) );
		$this->occurrence_repo->method( 'get_upcoming_by_event' )
			->willReturnCallback( function ( int $event_id ) use ( $friday_occ, $monday_occ ): array {
				return 1 === $event_id ? array( $friday_occ ) : array( $monday_occ );
			} );

		$this->templates->expects( $this->once() )
			->method( 'get_template_part' )
			->with(
				'regulars-table',
				$this->callback( function ( array $args ): bool {
					// Monday (order 1) should come before Friday (order 5).
					$this->assertSame( 'Monday', $args['rows'][0]['day_name'] );
					$this->assertSame( 'Friday', $args['rows'][1]['day_name'] );
					return true;
				} )
			)
			->willReturn( '<table>sorted</table>' );

		$this->shortcode->render();
	}
}
