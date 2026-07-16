<?php
/**
 * DateTimeMetaboxHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Metaboxes\DateTimeMetaboxHandler;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\RecurrenceService;

/**
 * Test DateTimeMetaboxHandler rendering logic.
 *
 * Focuses on testable rendering: recurrence description, occurrence count,
 * date/time field population, and settings-based conditional rendering.
 * Pure HTML structure and inline JS are not the focus here.
 */
class DateTimeMetaboxHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock recurrence service.
	 *
	 * @var RecurrenceService|Mockery\MockInterface
	 */
	private $recurrence_service;

	/**
	 * Test event.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->recurrence_service = Mockery::mock( RecurrenceService::class );
		$this->recurrence_service->shouldReceive( 'count_occurrences' )->andReturn( 0 )->byDefault();
		$this->recurrence_service->shouldReceive( 'describe_rule' )->andReturn( '' )->byDefault();

		$this->event             = new Event();
		$this->event->id         = 1;
		$this->event->title      = 'Test Event';
		$this->event->event_type = 'single';

		// Mock WordPress functions used during render.
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( null );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_option' )->justReturn( array() );
	}

	/**
	 * Create handler instance.
	 *
	 * @param Event $event Optional event override.
	 * @return DateTimeMetaboxHandler
	 */
	private function create_handler( ?Event $event = null ): DateTimeMetaboxHandler {
		return new DateTimeMetaboxHandler(
			$event ?? $this->event,
			$this->recurrence_service
		);
	}

	// =========================================================================
	// render() Tests
	// =========================================================================

	/**
	 * Test render outputs date inputs when no occurrence provided.
	 *
	 * @return void
	 */
	public function test_render_outputs_date_inputs_for_new_event(): void {
		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="start_date"', $output );
		$this->assertStringContainsString( 'name="start_time"', $output );
	}

	/**
	 * Test render populates start date from occurrence.
	 *
	 * @return void
	 */
	public function test_render_populates_start_date_from_occurrence(): void {
		$occurrence                   = new Occurrence();
		$occurrence->start_datetime   = '2026-06-15 14:30:00';
		$occurrence->end_datetime     = '2026-06-15 16:00:00';
		$occurrence->all_day          = false;
		$occurrence->capacity         = null;

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( $occurrence, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( '2026-06-15', $output );
		$this->assertStringContainsString( '14:30', $output );
	}

	/**
	 * Test render populates end date from occurrence.
	 *
	 * @return void
	 */
	public function test_render_populates_end_date_from_occurrence(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 14:30:00';
		$occurrence->end_datetime   = '2026-06-15 16:00:00';
		$occurrence->all_day        = false;
		$occurrence->capacity       = null;

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( $occurrence, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( '16:00', $output );
	}

	/**
	 * Test render shows capacity field.
	 *
	 * @return void
	 */
	public function test_render_shows_capacity_field(): void {
		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="occurrence_capacity"', $output );
	}

	/**
	 * Test render populates capacity from occurrence.
	 *
	 * @return void
	 */
	public function test_render_populates_capacity_from_occurrence(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 14:30:00';
		$occurrence->end_datetime   = '2026-06-15 16:00:00';
		$occurrence->all_day        = false;
		$occurrence->capacity       = 75;

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( $occurrence, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="75"', $output );
	}

	/**
	 * Test render shows end time toggle.
	 *
	 * @return void
	 */
	public function test_render_shows_end_time_toggle(): void {
		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-end-time-toggle', $output );
	}

	/**
	 * Test render expands end time when require_end_time is true.
	 *
	 * @return void
	 */
	public function test_render_expands_end_time_when_required(): void {
		Functions\when( 'get_option' )->justReturn( array( 'require_end_time' => '1' ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'aria-expanded="true"', $output );
	}

	/**
	 * Test render collapses end time when require_end_time is false.
	 *
	 * @return void
	 */
	public function test_render_collapses_end_time_by_default(): void {
		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'aria-expanded="false"', $output );
	}

	/**
	 * Test render shows required attribute on end fields when require_end_time is true.
	 *
	 * @return void
	 */
	public function test_render_marks_end_fields_required_when_setting_on(): void {
		Functions\when( 'get_option' )->justReturn( array( 'require_end_time' => '1' ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'required="required"', $output );
	}

	// =========================================================================
	// render_recurrence() Tests
	// =========================================================================

	/**
	 * Test render_recurrence invokes describe_rule for an existing rule.
	 *
	 * @return void
	 */
	public function test_render_recurrence_calls_describe_rule_when_rule_set(): void {
		$this->event->event_type      = 'recurring';
		$this->event->recurrence_rule = 'FREQ=WEEKLY;BYDAY=MO,WE,FR';

		$this->recurrence_service
			->shouldReceive( 'describe_rule' )
			->once()
			->with( 'FREQ=WEEKLY;BYDAY=MO,WE,FR' )
			->andReturn( 'Every week on Monday, Wednesday, Friday' );

		$this->recurrence_service
			->shouldReceive( 'count_occurrences' )
			->once()
			->with( 1 )
			->andReturn( 12 );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Every week on Monday, Wednesday, Friday', $output );
	}

	/**
	 * Test render_recurrence shows occurrence count.
	 *
	 * @return void
	 */
	public function test_render_recurrence_shows_occurrence_count(): void {
		$this->event->event_type      = 'recurring';
		$this->event->recurrence_rule = 'FREQ=DAILY';

		$this->recurrence_service
			->shouldReceive( 'describe_rule' )
			->andReturn( 'Every day' );

		$this->recurrence_service
			->shouldReceive( 'count_occurrences' )
			->andReturn( 30 );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( '30', $output );
		$this->assertStringContainsString( 'occurrence', $output );
	}

	/**
	 * Test render_recurrence does NOT call describe_rule when no rule set.
	 *
	 * @return void
	 */
	public function test_render_recurrence_skips_describe_rule_when_no_rule(): void {
		$this->event->event_type      = 'recurring';
		$this->event->recurrence_rule = '';

		$this->recurrence_service
			->shouldNotReceive( 'describe_rule' );

		$this->recurrence_service
			->shouldReceive( 'count_occurrences' )
			->andReturn( 0 );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		// No rule description block rendered.
		$this->assertStringNotContainsString( 'Current pattern:', $output );
	}

	/**
	 * Test render_recurrence contains preset selector.
	 *
	 * @return void
	 */
	public function test_render_recurrence_contains_preset_selector(): void {
		$this->event->event_type      = 'recurring';
		$this->event->recurrence_rule = '';

		$this->recurrence_service
			->shouldReceive( 'count_occurrences' )
			->andReturn( 0 );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="recurrence_preset"', $output );
	}

	/**
	 * Test render_recurrence shows recurrence hidden input.
	 *
	 * @return void
	 */
	public function test_render_recurrence_has_hidden_rule_input(): void {
		$this->event->event_type      = 'recurring';
		$this->event->recurrence_rule = 'FREQ=WEEKLY';

		$this->recurrence_service
			->shouldReceive( 'describe_rule' )
			->andReturn( 'Every week' );

		$this->recurrence_service
			->shouldReceive( 'count_occurrences' )
			->andReturn( 0 );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="recurrence_rule"', $output );
		$this->assertStringContainsString( 'FREQ=WEEKLY', $output );
	}

	/**
	 * Test render_recurrence shows zero occurrences count without the occurrence block.
	 *
	 * Count of 0 means the count span should not be rendered.
	 *
	 * @return void
	 */
	public function test_render_recurrence_hides_count_when_zero(): void {
		$this->event->event_type      = 'recurring';
		$this->event->recurrence_rule = 'FREQ=DAILY';

		$this->recurrence_service
			->shouldReceive( 'describe_rule' )
			->andReturn( 'Every day' );

		$this->recurrence_service
			->shouldReceive( 'count_occurrences' )
			->andReturn( 0 );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'occurrences generated', $output );
	}

	// =========================================================================
	// render_upcoming_dates() Tests
	// =========================================================================

	/**
	 * The Dates box must not open a form of its own.
	 *
	 * It renders *inside* the event editor's form. A nested `<form>` is not valid HTML: the parser
	 * drops the inner tag and hands its controls to the outer form, so a second `action` field would
	 * ride along on every event save and steal the submission — every save routed to the wrong
	 * handler, and the event never saved at all. The add-a-date fields are plain fields on the event
	 * form for exactly this reason, and this test is the tripwire.
	 *
	 * @return void
	 */
	public function test_upcoming_dates_box_opens_no_form_of_its_own(): void {
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/admin.php' );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 1 );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<form', (string) $output );
		$this->assertStringNotContainsString( 'name="action"', (string) $output );
	}

	/**
	 * The add-a-date fields ride the event form, so they must actually be there.
	 *
	 * @return void
	 */
	public function test_upcoming_dates_box_offers_add_date_fields(): void {
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/admin.php' );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="nettertech_events_new_date"', (string) $output );
		$this->assertStringContainsString( 'name="nettertech_events_new_start_time"', (string) $output );
		$this->assertStringContainsString( 'name="nettertech_events_new_end_time"', (string) $output );
	}

	/**
	 * An unsaved event has nothing to add a date to.
	 *
	 * @return void
	 */
	public function test_upcoming_dates_box_hides_add_date_for_unsaved_event(): void {
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/admin.php' );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'name="nettertech_events_new_date"', (string) $output );
	}

	// =========================================================================
	// Mutation-hardening: rendered-text and default-argument tests.
	//
	// Each test pins a specific behaviour a surviving Infection mutant would
	// otherwise slip past (FunctionCallRemoval of esc_html_e / wp_add_inline_style,
	// and the default $event_id literal).
	// =========================================================================

	/**
	 * Render the upcoming-dates box for a saved event and return its markup.
	 *
	 * @param array<int, Occurrence> $occurrences Occurrences to list.
	 * @param int                    $event_id    Event id (defaults to a saved event).
	 * @return string Buffered output.
	 */
	private function render_dates_box( array $occurrences = array(), int $event_id = 1 ): string {
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/admin.php' );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, $occurrences, $event_id );
		return (string) ob_get_clean();
	}

	/**
	 * Line 111: the default $event_id is 0, so add-a-date is hidden by default.
	 *
	 * Kills IncrementInteger 0 -> 1 on the default argument: with a default of 1
	 * the add-a-date fields would render for a caller that passed no id at all.
	 * (The DecrementInteger 0 -> -1 mutant is equivalent — both 0 and -1 fail the
	 * `> 0` gate identically — so it is left as an accepted survivor.)
	 *
	 * @return void
	 */
	public function test_upcoming_dates_default_event_id_hides_add_date(): void {
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/admin.php' );

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 0 );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'name="nettertech_events_new_date"', $output );
	}

	/**
	 * Line 116: the box heading is rendered.
	 *
	 * Kills FunctionCallRemoval of `esc_html_e( 'Dates', ... )` in the header.
	 *
	 * @return void
	 */
	public function test_upcoming_dates_renders_heading(): void {
		$this->assertStringContainsString( 'Dates', $this->render_dates_box() );
	}

	/**
	 * Line 120: the empty-state message is rendered when there are no dates.
	 *
	 * Kills FunctionCallRemoval of `esc_html_e( 'No upcoming dates yet.', ... )`.
	 *
	 * @return void
	 */
	public function test_upcoming_dates_renders_empty_state_message(): void {
		$this->assertStringContainsString( 'No upcoming dates yet.', $this->render_dates_box() );
	}

	/**
	 * Line 197: the add-a-date summary label is rendered.
	 *
	 * Kills FunctionCallRemoval of `esc_html_e( 'Add a date', ... )`.
	 *
	 * @return void
	 */
	public function test_add_date_renders_summary_label(): void {
		$this->assertStringContainsString( 'Add a date', $this->render_dates_box() );
	}

	/**
	 * Line 201: the add-a-date hint text is rendered.
	 *
	 * Kills FunctionCallRemoval of the `esc_html_e( 'A date added here ...' )` hint.
	 *
	 * @return void
	 */
	public function test_add_date_renders_hint_text(): void {
		$this->assertStringContainsString( 'sits outside the recurrence pattern', $this->render_dates_box() );
	}

	/**
	 * Line 207: the Date field label is rendered.
	 *
	 * Kills FunctionCallRemoval of `esc_html_e( 'Date', ... )`. The regex isolates
	 * the standalone label text so it cannot be satisfied by 'Dates' or 'dates'.
	 *
	 * @return void
	 */
	public function test_add_date_renders_date_label(): void {
		$this->assertMatchesRegularExpression( '/>\s*Date\s*</', $this->render_dates_box() );
	}

	/**
	 * Line 220: the Start-time field label is rendered.
	 *
	 * Kills FunctionCallRemoval of `esc_html_e( 'Start', ... )`.
	 *
	 * @return void
	 */
	public function test_add_date_renders_start_label(): void {
		$this->assertMatchesRegularExpression( '/>\s*Start\s*</', $this->render_dates_box() );
	}

	/**
	 * Line 230: the End-time field label is rendered.
	 *
	 * Kills FunctionCallRemoval of `esc_html_e( 'End', ... )`.
	 *
	 * @return void
	 */
	public function test_add_date_renders_end_label(): void {
		$this->assertMatchesRegularExpression( '/>\s*End\s*</', $this->render_dates_box() );
	}

	/**
	 * Line 241: the add-a-date help paragraph is rendered.
	 *
	 * Kills FunctionCallRemoval of `esc_html_e( 'The date is added when you update the event.' )`.
	 *
	 * @return void
	 */
	public function test_add_date_renders_help_text(): void {
		$this->assertStringContainsString( 'The date is added when you update the event.', $this->render_dates_box() );
	}

	/**
	 * Line 258: the box registers its inline stylesheet.
	 *
	 * Kills FunctionCallRemoval of `wp_add_inline_style( ... )`: the CSS is
	 * registered, not echoed, so we capture the call rather than assert on output.
	 *
	 * @return void
	 */
	public function test_upcoming_dates_registers_inline_style(): void {
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/admin.php' );

		$captured = array();
		Functions\when( 'wp_add_inline_style' )->alias(
			function ( $handle, $css = '' ) use ( &$captured ) {
				$captured[] = (string) $css;
				return true;
			}
		);

		$handler = $this->create_handler();

		ob_start();
		$handler->render_schedule( null, array(), 1 );
		ob_get_clean();

		$this->assertNotEmpty( $captured );
		$this->assertStringContainsString( '.nte-upcoming-dates', implode( '', $captured ) );
	}
}
