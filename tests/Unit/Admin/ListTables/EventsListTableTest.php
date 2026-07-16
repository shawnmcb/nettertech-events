<?php
/**
 * EventsListTable unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\ListTables
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\ListTables;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\ListTables\EventsListTable;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\EventDuplicationService;

// Note: WP_List_Table mock is defined in tests/Mocks/AdminMocks.php
// and loaded before the autoloader in bootstrap.php.

/**
 * Test EventsListTable functionality.
 *
 * Tests the events admin list table columns, actions, and rendering.
 */
class EventsListTableTest extends \NetterTechEventsTestCase {

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock event duplication service.
	 *
	 * @var EventDuplicationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $duplication_service;

	/**
	 * EventsListTable instance.
	 *
	 * @var EventsListTable
	 */
	private EventsListTable $list_table;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create mocks.
		$this->event_repo          = $this->createMock( EventRepositoryInterface::class );
		$this->occurrence_repo     = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->duplication_service = $this->createMock( EventDuplicationService::class );

		// Set up common WordPress function mocks.
		$this->setup_wp_functions();

		// Create list table.
		$this->list_table = new EventsListTable( $this->event_repo, $this->occurrence_repo, $this->duplication_service );
	}

	/**
	 * Set up common WordPress function mocks.
	 *
	 * @return void
	 */
	private function setup_wp_functions(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			function ( $url, $action = '' ) {
				return $url . '&_wpnonce=test123';
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url = '' ) {
				$query = http_build_query( $args );
				return $url . '?' . $query;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults = array() ) {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, $echo = true ) {
				$result = ( $selected === $current ) ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( 'date_format' === $option ) {
					return 'F j, Y';
				}
				if ( 'time_format' === $option ) {
					return 'g:i a';
				}
				return $default;
			}
		);
		Functions\when( 'date_i18n' )->alias(
			function ( $format, $timestamp = false ) {
				if ( false === $timestamp ) {
					$timestamp = time();
				}
				return date( $format, $timestamp );
			}
		);
		// Faithful get_date_from_gmt stub: interprets the input as UTC and converts
		// to a FIXED site timezone (America/Chicago) for deterministic assertions.
		Functions\when( 'get_date_from_gmt' )->alias(
			function ( $string, $format = 'Y-m-d H:i:s' ) {
				$utc = new \DateTime( $string, new \DateTimeZone( 'UTC' ) );
				$utc->setTimezone( new \DateTimeZone( 'America/Chicago' ) );
				return $utc->format( $format );
			}
		);
	}

	// =========================================================================
	// get_columns() Tests
	// =========================================================================

	/**
	 * Test get_columns returns expected columns.
	 *
	 * @return void
	 */
	public function test_get_columns_returns_expected_columns(): void {
		$columns = $this->list_table->get_columns();

		$this->assertIsArray( $columns );
		$this->assertArrayHasKey( 'cb', $columns );
		$this->assertArrayHasKey( 'title', $columns );
		$this->assertArrayHasKey( 'next_date', $columns );
		$this->assertArrayHasKey( 'type', $columns );
		$this->assertArrayHasKey( 'status', $columns );
		$this->assertArrayHasKey( 'created_at', $columns );
	}

	/**
	 * Test get_columns checkbox column.
	 *
	 * @return void
	 */
	public function test_get_columns_includes_checkbox(): void {
		$columns = $this->list_table->get_columns();

		$this->assertStringContainsString( 'checkbox', $columns['cb'] );
	}

	/**
	 * Test get_columns title column.
	 *
	 * @return void
	 */
	public function test_get_columns_title_column(): void {
		$columns = $this->list_table->get_columns();

		$this->assertEquals( 'Event', $columns['title'] );
	}

	// =========================================================================
	// get_sortable_columns() Tests
	// =========================================================================

	/**
	 * Test get_sortable_columns returns expected columns.
	 *
	 * @return void
	 */
	public function test_get_sortable_columns_returns_expected_columns(): void {
		$columns = $this->list_table->get_sortable_columns();

		$this->assertIsArray( $columns );
		$this->assertArrayHasKey( 'title', $columns );
		$this->assertArrayHasKey( 'date', $columns );
		$this->assertArrayHasKey( 'next_date', $columns );
		$this->assertArrayHasKey( 'status', $columns );
		$this->assertArrayHasKey( 'created_at', $columns );
	}

	/**
	 * Test the Date column is the default-sorted (desc) sortable column.
	 *
	 * The 5th tuple element 'desc' marks Date as the initially-sorted column so
	 * WP renders the active sort indicator on first load; the 2nd element 'true'
	 * makes its first click sort descending.
	 *
	 * @return void
	 */
	public function test_get_sortable_columns_includes_date(): void {
		$columns = $this->list_table->get_sortable_columns();

		$this->assertArrayHasKey( 'date', $columns );
		$this->assertSame( array( 'date', true, '', '', 'desc' ), $columns['date'] );
	}

	/**
	 * Test get_sortable_columns created_at default descending.
	 *
	 * @return void
	 */
	public function test_get_sortable_columns_created_at_default_desc(): void {
		$columns = $this->list_table->get_sortable_columns();

		// created_at should sort DESC by default (true in second position).
		$this->assertEquals( array( 'created_at', true ), $columns['created_at'] );
	}

	// =========================================================================
	// get_bulk_actions() Tests
	// =========================================================================

	/**
	 * Test get_bulk_actions returns expected actions.
	 *
	 * @return void
	 */
	public function test_get_bulk_actions_returns_expected_actions(): void {
		$actions = $this->list_table->get_bulk_actions();

		$this->assertIsArray( $actions );
		$this->assertArrayHasKey( 'delete', $actions );
		$this->assertArrayHasKey( 'publish', $actions );
		$this->assertArrayHasKey( 'draft', $actions );
		$this->assertArrayHasKey( 'bulk_duplicate', $actions );
	}

	/**
	 * Test get_bulk_actions includes category actions when category repo is provided.
	 *
	 * @return void
	 */
	public function test_get_bulk_actions_includes_category_actions_with_category_repo(): void {
		$category_repo = $this->createMock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$table         = new EventsListTable( $this->event_repo, $this->occurrence_repo, $this->duplication_service, $category_repo );

		$actions = $table->get_bulk_actions();

		$this->assertArrayHasKey( 'bulk_category_add', $actions );
		$this->assertArrayHasKey( 'bulk_category_remove', $actions );
	}

	/**
	 * Test get_bulk_actions excludes category actions without category repo.
	 *
	 * @return void
	 */
	public function test_get_bulk_actions_excludes_category_actions_without_category_repo(): void {
		$actions = $this->list_table->get_bulk_actions();

		$this->assertArrayNotHasKey( 'bulk_category_add', $actions );
		$this->assertArrayNotHasKey( 'bulk_category_remove', $actions );
	}

	/**
	 * Test get_bulk_actions delete label.
	 *
	 * @return void
	 */
	public function test_get_bulk_actions_delete_label(): void {
		$actions = $this->list_table->get_bulk_actions();

		$this->assertEquals( 'Delete', $actions['delete'] );
	}

	// =========================================================================
	// column_cb() Tests
	// =========================================================================

	/**
	 * Test column_cb renders checkbox.
	 *
	 * @return void
	 */
	public function test_column_cb_renders_checkbox(): void {
		$event = new Event();
		$event->id = 123;

		$output = $this->list_table->column_cb( $event );

		$this->assertStringContainsString( '<input type="checkbox"', $output );
		$this->assertStringContainsString( 'name="event[]"', $output );
		$this->assertStringContainsString( 'value="123"', $output );
	}

	// =========================================================================
	// column_title() Tests
	// =========================================================================

	/**
	 * Test column_title renders event title.
	 *
	 * @return void
	 */
	public function test_column_title_renders_event_title(): void {
		$event = new Event();
		$event->id = 1;
		$event->title = 'Test Event';
		$event->status = EventStatus::DRAFT;

		$output = $this->list_table->column_title( $event );

		$this->assertStringContainsString( 'Test Event', $output );
		$this->assertStringContainsString( 'row-title', $output );
	}

	/**
	 * Test column_title includes row actions.
	 *
	 * @return void
	 */
	public function test_column_title_includes_row_actions(): void {
		$event = new Event();
		$event->id = 1;
		$event->title = 'Test Event';
		$event->status = EventStatus::DRAFT;

		$output = $this->list_table->column_title( $event );

		$this->assertStringContainsString( 'Edit', $output );
		$this->assertStringContainsString( 'Quick Edit', $output );
		$this->assertStringContainsString( 'Duplicate', $output );
		$this->assertStringContainsString( 'Delete', $output );
	}

	/**
	 * Test column_title Quick Edit trigger has correct data attribute.
	 *
	 * @return void
	 */
	public function test_column_title_quick_edit_trigger_has_data_attribute(): void {
		$event         = new Event();
		$event->id     = 42;
		$event->title  = 'Quick Edit Test';
		$event->status = EventStatus::DRAFT;

		$output = $this->list_table->column_title( $event );

		$this->assertStringContainsString( 'nte-quick-edit-trigger', $output );
		$this->assertStringContainsString( 'data-event-id="42"', $output );
	}

	/**
	 * Test column_title includes view link for published events.
	 *
	 * @return void
	 */
	public function test_column_title_includes_view_link_for_published(): void {
		$event = $this->getMockBuilder( Event::class )
			->onlyMethods( array( 'is_published', 'get_permalink' ) )
			->getMock();

		$event->id = 1;
		$event->title = 'Published Event';
		$event->status = EventStatus::PUBLISHED;

		$event->method( 'is_published' )->willReturn( true );
		$event->method( 'get_permalink' )->willReturn( 'http://example.com/event/test' );

		$output = $this->list_table->column_title( $event );

		$this->assertStringContainsString( 'View', $output );
	}

	// =========================================================================
	// column_next_date() Tests
	// =========================================================================

	/**
	 * Test column_next_date shows dash for single event without occurrence.
	 *
	 * @return void
	 */
	public function test_column_next_date_shows_dash_for_single_without_occurrence(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$this->occurrence_repo->method( 'next_for_event' )->willReturn( null );

		$output = $this->list_table->column_next_date( $event );

		$this->assertStringContainsString( '—', $output );
	}

	/**
	 * Test column_next_date shows "no upcoming" for recurring without dates.
	 *
	 * @return void
	 */
	public function test_column_next_date_shows_no_upcoming_for_recurring(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'recurring';

		$this->occurrence_repo->method( 'next_for_event' )->willReturn( null );

		$output = $this->list_table->column_next_date( $event );

		$this->assertStringContainsString( 'No upcoming', $output );
	}

	/**
	 * Test column_next_date shows date and time for event with occurrence.
	 *
	 * @return void
	 */
	public function test_column_next_date_shows_date_and_time(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->start_datetime = '2026-01-15 19:00:00';
		$occurrence->all_day = false;

		$this->occurrence_repo->method( 'next_for_event' )->willReturn( $occurrence );

		$output = $this->list_table->column_next_date( $event );

		// Should contain formatted date.
		$this->assertNotEmpty( $output );
		$this->assertStringNotContainsString( '—', $output );
	}

	/**
	 * Test column_next_date shows "All day" for all-day events.
	 *
	 * @return void
	 */
	public function test_column_next_date_shows_all_day(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->start_datetime = '2026-01-15 00:00:00';
		$occurrence->all_day = true;

		$this->occurrence_repo->method( 'next_for_event' )->willReturn( $occurrence );

		$output = $this->list_table->column_next_date( $event );

		$this->assertStringContainsString( 'All day', $output );
	}

	// =========================================================================
	// column_type() Tests
	// =========================================================================

	/**
	 * Test column_type renders single event type.
	 *
	 * @return void
	 */
	public function test_column_type_renders_single(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$output = $this->list_table->column_type( $event );

		$this->assertStringContainsString( 'Single', $output );
		$this->assertStringContainsString( '<a href=', $output );
	}

	/**
	 * Test column_type renders recurring event type.
	 *
	 * @return void
	 */
	public function test_column_type_renders_recurring(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'recurring';

		$output = $this->list_table->column_type( $event );

		$this->assertStringContainsString( 'Recurring', $output );
	}

	/**
	 * Test column_type files a multi-date event under Recurring (NTE-159).
	 *
	 * An event with more than one scheduled date cannot logically be a single
	 * event, however its stored type reads: label and filter link both derive
	 * Recurring, matching where_type()'s boundary.
	 *
	 * @return void
	 */
	public function test_column_type_labels_multi_date_single(): void {
		$event             = new Event();
		$event->id         = 7;
		$event->event_type = 'single';

		$bounds = new \ReflectionProperty( $this->list_table, 'date_bounds' );
		$bounds->setValue(
			$this->list_table,
			array(
				7 => array(
					'first'        => '2026-11-06 17:00:00',
					'last'         => '2026-11-07 10:00:00',
					'next'         => '2026-11-06 17:00:00',
					'next_all_day' => false,
					'count'        => 2,
				),
			)
		);

		$output = $this->list_table->column_type( $event );

		// NTE-159 ruling: more than one date IS recurring, however the type is stored.
		$this->assertStringContainsString( 'Recurring (2 dates)', $output );
		$this->assertStringContainsString( 'event_type=recurring', $output );
	}

	/**
	 * Test column_type leaves a genuine one-off unlabelled (NTE-155 boundary).
	 *
	 * A single event with exactly one date is a plain one-off: the count suffix
	 * appears only above one date. Pins the `> 1` boundary at count == 1.
	 *
	 * @return void
	 */
	public function test_column_type_single_with_one_date_is_not_labelled_multi(): void {
		$event             = new Event();
		$event->id         = 7;
		$event->event_type = 'single';

		$bounds = new \ReflectionProperty( $this->list_table, 'date_bounds' );
		$bounds->setValue(
			$this->list_table,
			array(
				7 => array(
					'count' => 1,
				),
			)
		);

		$output = $this->list_table->column_type( $event );

		$this->assertStringContainsString( '>Single<', $output );
		$this->assertStringNotContainsString( 'dates', $output );
	}

	/**
	 * Test column_type never applies the multi-date suffix to a recurring event.
	 *
	 * The suffix is gated on event_type === 'single'; a recurring event carrying
	 * many occurrences must still read as Recurring. Pins the `&&` guard.
	 *
	 * @return void
	 */
	public function test_column_type_recurring_with_many_dates_stays_recurring(): void {
		$event             = new Event();
		$event->id         = 8;
		$event->event_type = 'recurring';

		$bounds = new \ReflectionProperty( $this->list_table, 'date_bounds' );
		$bounds->setValue(
			$this->list_table,
			array(
				8 => array(
					'count' => 5,
				),
			)
		);

		$output = $this->list_table->column_type( $event );

		$this->assertStringContainsString( '>Recurring<', $output );
		$this->assertStringNotContainsString( 'dates', $output );
	}

	/**
	 * Test column_type renders series event type.
	 *
	 * @return void
	 */
	public function test_column_type_renders_series(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'series_parent';

		$output = $this->list_table->column_type( $event );

		$this->assertStringContainsString( 'Series', $output );
	}

	// =========================================================================
	// column_status() Tests
	// =========================================================================

	/**
	 * Test column_status renders draft status.
	 *
	 * @return void
	 */
	public function test_column_status_renders_draft(): void {
		$event = new Event();
		$event->id = 1;
		$event->status = EventStatus::DRAFT;

		$output = $this->list_table->column_status( $event );

		$this->assertStringContainsString( 'Draft', $output );
	}

	/**
	 * Test column_status renders published status with color.
	 *
	 * @return void
	 */
	public function test_column_status_renders_published(): void {
		$event = new Event();
		$event->id = 1;
		$event->status = EventStatus::PUBLISHED;

		$output = $this->list_table->column_status( $event );

		$this->assertStringContainsString( 'Published', $output );
		$this->assertStringContainsString( '#00a32a', $output ); // Green color.
	}

	/**
	 * Test column_status renders cancelled status.
	 *
	 * @return void
	 */
	public function test_column_status_renders_cancelled(): void {
		$event = new Event();
		$event->id = 1;
		$event->status = EventStatus::CANCELLED;

		$output = $this->list_table->column_status( $event );

		$this->assertStringContainsString( 'Cancelled', $output );
		$this->assertStringContainsString( '#d63638', $output ); // Red color.
	}

	/**
	 * Test column_status renders postponed status.
	 *
	 * @return void
	 */
	public function test_column_status_renders_postponed(): void {
		$event = new Event();
		$event->id = 1;
		$event->status = EventStatus::POSTPONED;

		$output = $this->list_table->column_status( $event );

		$this->assertStringContainsString( 'Postponed', $output );
		$this->assertStringContainsString( '#dba617', $output ); // Yellow color.
	}

	// =========================================================================
	// column_created_at() Tests
	// =========================================================================

	/**
	 * Test column_created_at renders date.
	 *
	 * @return void
	 */
	public function test_column_created_at_renders_date(): void {
		$event = new Event();
		$event->id = 1;
		$event->created_at = '2026-01-10 14:30:00';

		$output = $this->list_table->column_created_at( $event );

		// Should contain formatted output.
		$this->assertNotEmpty( $output );
		$this->assertNotEquals( '—', $output );
	}

	/**
	 * Test column_created_at converts a UTC created_at to the site-local string (NTE-131).
	 *
	 * A known UTC value must render in the fixed site timezone (America/Chicago),
	 * proving the renderer routes through get_date_from_gmt rather than treating
	 * the stored value as already-local.
	 *
	 * @return void
	 */
	public function test_column_created_at_renders_utc_as_site_local(): void {
		$event             = new Event();
		$event->id         = 1;
		// 2026-01-15 18:00:00 UTC === 2026-01-15 12:00:00 America/Chicago (CST, UTC-6).
		$event->created_at = '2026-01-15 18:00:00';

		$output = $this->list_table->column_created_at( $event );

		// Formats come from the get_option stub: 'F j, Y' and 'g:i a'.
		$this->assertStringContainsString( 'January 15, 2026', $output );
		$this->assertStringContainsString( '12:00 pm', $output );
	}

	/**
	 * Test column_created_at returns dash for empty date.
	 *
	 * @return void
	 */
	public function test_column_created_at_returns_dash_for_empty(): void {
		$event = new Event();
		$event->id = 1;
		$event->created_at = '';

		$output = $this->list_table->column_created_at( $event );

		$this->assertEquals( '—', $output );
	}

	// =========================================================================
	// column_default() Tests
	// =========================================================================

	/**
	 * Test column_default returns property value.
	 *
	 * @return void
	 */
	public function test_column_default_returns_property_value(): void {
		$event = new Event();
		$event->id = 1;
		$event->venue_name = 'Test Venue';

		$output = $this->list_table->column_default( $event, 'venue_name' );

		$this->assertEquals( 'Test Venue', $output );
	}

	/**
	 * Test column_default returns empty for non-existent property.
	 *
	 * @return void
	 */
	public function test_column_default_returns_empty_for_nonexistent(): void {
		$event = new Event();
		$event->id = 1;

		$output = $this->list_table->column_default( $event, 'nonexistent_column' );

		$this->assertEquals( '', $output );
	}

	// =========================================================================
	// no_items() Tests
	// =========================================================================

	/**
	 * Test no_items outputs message.
	 *
	 * @return void
	 */
	public function test_no_items_outputs_message(): void {
		ob_start();
		$this->list_table->no_items();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No events found', $output );
	}

	// =========================================================================
	// extra_tablenav() Tests
	// =========================================================================

	/**
	 * Test extra_tablenav renders status filter.
	 *
	 * @return void
	 */
	public function test_extra_tablenav_renders_status_filter(): void {
		// Use reflection to call protected method.
		$reflection = new \ReflectionClass( $this->list_table );
		$method     = $reflection->getMethod( 'extra_tablenav' );
		
		ob_start();
		$method->invoke( $this->list_table, 'top' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="status"', $output );
		$this->assertStringContainsString( 'All Statuses', $output );
		$this->assertStringContainsString( 'Draft', $output );
		$this->assertStringContainsString( 'Published', $output );
	}

	/**
	 * Test extra_tablenav renders event type filter.
	 *
	 * @return void
	 */
	public function test_extra_tablenav_renders_type_filter(): void {
		$reflection = new \ReflectionClass( $this->list_table );
		$method     = $reflection->getMethod( 'extra_tablenav' );
		
		ob_start();
		$method->invoke( $this->list_table, 'top' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="event_type"', $output );
		$this->assertStringContainsString( 'All Types', $output );
		$this->assertStringContainsString( 'Single', $output );
		$this->assertStringContainsString( 'Recurring', $output );
	}

	/**
	 * Test extra_tablenav renders the Ticketed type-filter option (NTE-111).
	 *
	 * @return void
	 */
	public function test_extra_tablenav_renders_ticketed_option(): void {
		$reflection = new \ReflectionClass( $this->list_table );
		$method     = $reflection->getMethod( 'extra_tablenav' );

		ob_start();
		$method->invoke( $this->list_table, 'top' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="ticketed"', $output );
		$this->assertStringContainsString( 'Ticketed', $output );
	}

	/**
	 * Test extra_tablenav marks the Ticketed option selected when active (NTE-111).
	 *
	 * @return void
	 */
	public function test_extra_tablenav_marks_ticketed_selected(): void {
		$_GET['event_type'] = 'ticketed';

		$reflection = new \ReflectionClass( $this->list_table );
		$method     = $reflection->getMethod( 'extra_tablenav' );

		ob_start();
		$method->invoke( $this->list_table, 'top' );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/value="ticketed"[^>]*selected="selected"/',
			$output
		);

		unset( $_GET['event_type'] );
	}

	/**
	 * Test extra_tablenav renders filter button.
	 *
	 * @return void
	 */
	public function test_extra_tablenav_renders_filter_button(): void {
		$reflection = new \ReflectionClass( $this->list_table );
		$method     = $reflection->getMethod( 'extra_tablenav' );
		
		ob_start();
		$method->invoke( $this->list_table, 'top' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="submit"', $output );
		$this->assertStringContainsString( 'Filter', $output );
	}

	/**
	 * Test extra_tablenav does not render for bottom position.
	 *
	 * @return void
	 */
	public function test_extra_tablenav_bottom_does_not_render(): void {
		$reflection = new \ReflectionClass( $this->list_table );
		$method     = $reflection->getMethod( 'extra_tablenav' );
		
		ob_start();
		$method->invoke( $this->list_table, 'bottom' );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	// =========================================================================
	// display() Tests
	// =========================================================================

	/**
	 * Test display renders table structure.
	 *
	 * @return void
	 */
	public function test_display_renders_table_structure(): void {
		// Create a mock screen with render_screen_reader_content method.
		$this->list_table->screen = new class {
			public function render_screen_reader_content( $key ) {}
		};

		ob_start();
		$this->list_table->display();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<table', $output );
		$this->assertStringContainsString( 'wp-list-table', $output );
		$this->assertStringContainsString( '<thead>', $output );
		$this->assertStringContainsString( '<tbody', $output );
		$this->assertStringContainsString( '<tfoot>', $output );
	}

	/**
	 * Test display renders filter notice.
	 *
	 * @return void
	 */
	public function test_display_omits_filter_notice_by_default(): void {
		$this->list_table->screen = new class {
			public function render_screen_reader_content( $key ) {}
		};

		ob_start();
		$this->list_table->display();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'nte-filter-notice', $output );
	}

	/**
	 * Test display renders filter notice when sorting by next_date ascending.
	 *
	 * @return void
	 */
	public function test_display_renders_filter_notice_for_upcoming_sort(): void {
		$_GET['orderby'] = 'next_date';
		$_GET['order']   = 'asc';

		$this->list_table->screen = new class {
			public function render_screen_reader_content( $key ) {}
		};

		ob_start();
		$this->list_table->display();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-filter-notice', $output );
		$this->assertStringContainsString( 'Showing events with upcoming dates only.', $output );

		unset( $_GET['orderby'], $_GET['order'] );
	}

	/**
	 * Test display renders with table classes.
	 *
	 * @return void
	 */
	public function test_display_renders_table_classes(): void {
		$this->list_table->screen = new class {
			public function render_screen_reader_content( $key ) {}
		};

		ob_start();
		$this->list_table->display();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'widefat', $output );
		$this->assertStringContainsString( 'fixed', $output );
		$this->assertStringContainsString( 'striped', $output );
	}

	// =========================================================================
	// Integration Tests
	// =========================================================================

	/**
	 * Test list table can be instantiated multiple times.
	 *
	 * @return void
	 */
	public function test_multiple_instantiation(): void {
		$table1 = new EventsListTable( $this->event_repo, $this->occurrence_repo, $this->duplication_service );
		$table2 = new EventsListTable( $this->event_repo, $this->occurrence_repo, $this->duplication_service );

		$this->assertNotSame( $table1, $table2 );
		$this->assertEquals( $table1->get_columns(), $table2->get_columns() );
	}

	/**
	 * Test columns have expected labels.
	 *
	 * @return void
	 */
	public function test_columns_have_labels(): void {
		$columns = $this->list_table->get_columns();

		foreach ( $columns as $key => $label ) {
			$this->assertIsString( $label, "Column {$key} should have a string label" );
		}
	}

	// =========================================================================
	// single_row() Tests
	// =========================================================================

	/**
	 * Test single_row outputs data attributes for quick edit.
	 *
	 * @return void
	 */
	public function test_single_row_emits_data_attributes(): void {
		$event             = new Event();
		$event->id         = 7;
		$event->title      = 'Data Attr Event';
		$event->status     = EventStatus::PUBLISHED;
		$event->venue_name = 'The Venue';
		$event->event_type = 'single';
		$event->created_at = '2025-01-01 12:00:00';

		$this->occurrence_repo->method( 'next_for_event' )->willReturn( null );

		ob_start();
		$this->list_table->single_row( $event );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-event-id="7"', $output );
		$this->assertStringContainsString( 'data-title="Data Attr Event"', $output );
		$this->assertStringContainsString( 'data-status="published"', $output );
		$this->assertStringContainsString( 'data-venue-name="The Venue"', $output );
	}

	/**
	 * Test single_row handles null venue_name gracefully.
	 *
	 * @return void
	 */
	public function test_single_row_handles_null_venue_name(): void {
		$event             = new Event();
		$event->id         = 8;
		$event->title      = 'No Venue Event';
		$event->status     = EventStatus::DRAFT;
		$event->venue_name = null;
		$event->event_type = 'single';
		$event->created_at = '2025-01-01 12:00:00';

		$this->occurrence_repo->method( 'next_for_event' )->willReturn( null );

		ob_start();
		$this->list_table->single_row( $event );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-venue-name=""', $output );
	}

	// =========================================================================
	// inline_edit() Tests
	// =========================================================================

	/**
	 * Test inline_edit renders hidden template row.
	 *
	 * @return void
	 */
	public function test_inline_edit_renders_template(): void {
		ob_start();
		$this->list_table->inline_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-inline-edit-template', $output );
		$this->assertStringContainsString( 'name="title"', $output );
		$this->assertStringContainsString( 'name="status"', $output );
		$this->assertStringContainsString( 'name="venue_name"', $output );
		$this->assertStringContainsString( 'nte-inline-edit-save', $output );
		$this->assertStringContainsString( 'nte-inline-edit-cancel', $output );
	}

	/**
	 * Test inline_edit includes all status options.
	 *
	 * @return void
	 */
	public function test_inline_edit_includes_status_options(): void {
		ob_start();
		$this->list_table->inline_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="draft"', $output );
		$this->assertStringContainsString( 'value="published"', $output );
		$this->assertStringContainsString( 'value="cancelled"', $output );
		$this->assertStringContainsString( 'value="postponed"', $output );
	}

	// =========================================================================
	// render_category_modal() Tests
	// =========================================================================

	/**
	 * Test render_category_modal outputs nothing without category repo.
	 *
	 * @return void
	 */
	public function test_render_category_modal_empty_without_repo(): void {
		ob_start();
		$this->list_table->render_category_modal();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_category_modal renders with category repo.
	 *
	 * @return void
	 */
	public function test_render_category_modal_renders_with_categories(): void {
		$category       = new \NetterTechEvents\Models\Category();
		$category->id   = 1;
		$category->name = 'Music';

		$category_repo = $this->createMock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$category_repo->method( 'get_all' )->willReturn( array( $category ) );

		$table = new EventsListTable( $this->event_repo, $this->occurrence_repo, $this->duplication_service, $category_repo );

		ob_start();
		$table->render_category_modal();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-category-modal', $output );
		$this->assertStringContainsString( 'Music', $output );
		$this->assertStringContainsString( 'bulk_category_ids[]', $output );
	}

	// =========================================================================
	// Bulk duplicate action Tests
	// =========================================================================

	/**
	 * Test get_bulk_actions includes duplicate.
	 *
	 * @return void
	 */
	public function test_get_bulk_actions_includes_duplicate(): void {
		$actions = $this->list_table->get_bulk_actions();

		$this->assertArrayHasKey( 'bulk_duplicate', $actions );
		$this->assertEquals( 'Duplicate', $actions['bulk_duplicate'] );
	}

	// =========================================================================
	// New columns (NTE-079 / NTE-112) — get_columns()
	// =========================================================================

	/**
	 * Test get_columns includes the new Date and Tickets Sold columns in order.
	 *
	 * @return void
	 */
	public function test_get_columns_includes_date_and_tickets_sold(): void {
		$columns = $this->list_table->get_columns();

		$this->assertArrayHasKey( 'date', $columns );
		$this->assertArrayHasKey( 'tickets_sold', $columns );

		$keys      = array_keys( $columns );
		$date_pos  = array_search( 'date', $keys, true );
		$next_pos  = array_search( 'next_date', $keys, true );
		$this->assertLessThan( $next_pos, $date_pos, 'Date column must be left of Next Date.' );
	}

	/**
	 * Test the column order places Tickets Sold between Event and Date (NTE-111 follow-up).
	 *
	 * @return void
	 */
	public function test_get_columns_order(): void {
		$columns = $this->list_table->get_columns();

		$this->assertSame(
			array( 'cb', 'title', 'tickets_sold', 'date', 'next_date', 'type', 'status', 'created_at' ),
			array_keys( $columns )
		);
	}

	/**
	 * Test get_columns is extensible via the nettertech_events_list_columns filter.
	 *
	 * A throwaway add_filter (simulated through the apply_filters stub) appends a
	 * column and it appears in the returned set — proving add-ons can extend it.
	 *
	 * @return void
	 */
	public function test_get_columns_filter_is_extensible(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( 'nettertech_events_list_columns' === $tag && is_array( $value ) ) {
					$value['revenue'] = 'Revenue';
				}
				return $value;
			}
		);

		$columns = $this->list_table->get_columns();

		$this->assertArrayHasKey( 'revenue', $columns );
		$this->assertSame( 'Revenue', $columns['revenue'] );
	}

	/**
	 * Prime a private aggregate map on the list table for render tests.
	 *
	 * @param string               $property Property name.
	 * @param array<int, mixed>    $value    Map value.
	 * @return void
	 */
	private function prime_map( string $property, array $value ): void {
		$ref  = new \ReflectionProperty( EventsListTable::class, $property );
		$ref->setValue( $this->list_table, $value );
	}

	/**
	 * Test prepare_items() defaults to Date descending when no orderby is requested.
	 *
	 * With no `orderby` in the request, the list must sort by the event's first
	 * occurrence date descending (order_by_first_occurrence('desc')), so the
	 * paginated SELECT carries `ORDER BY (SELECT MIN(o.start_datetime) ...) DESC`.
	 *
	 * @return void
	 */
	public function test_prepare_items_defaults_to_date_desc(): void {
		unset( $_GET['orderby'], $_GET['order'] );

		// Capturing wpdb: records the SELECT SQL the paginate path executes.
		$capturing_db = new class() extends \wpdb {
			/**
			 * Captured get_results queries.
			 *
			 * @var array<int, string>
			 */
			public array $captured = array();

			/**
			 * Capture and return no rows.
			 *
			 * @param string $query  SQL query.
			 * @param string $output Output type.
			 * @return array<mixed>
			 */
			public function get_results( string $query, string $output = OBJECT ): array {
				$this->captured[] = $query;
				return array();
			}
		};

		$previous_db     = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $capturing_db;

		try {
			$this->list_table->prepare_items();
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}

		$select = '';
		foreach ( $capturing_db->captured as $sql ) {
			if ( false !== strpos( $sql, 'ORDER BY' ) ) {
				$select = $sql;
				break;
			}
		}

		$this->assertNotSame( '', $select, 'Expected a SELECT with an ORDER BY clause.' );
		$this->assertStringContainsString( 'SELECT MIN(o.start_datetime)', $select );
		$this->assertMatchesRegularExpression( '/\)\s+DESC/', $select );
	}

	// =========================================================================
	// column_date() Tests
	// =========================================================================

	/**
	 * Test column_date renders a single date when first === last.
	 *
	 * @return void
	 */
	public function test_column_date_single_date(): void {
		$event             = new Event();
		$event->id         = 10;
		$event->event_type = 'single';

		$this->prime_map(
			'date_bounds',
			array(
				10 => array(
					'first'        => '2026-06-15 19:00:00',
					'last'         => '2026-06-15 19:00:00',
					'next'         => null,
					'next_all_day' => false,
				),
			)
		);

		$output = $this->list_table->column_date( $event );

		$this->assertStringNotContainsString( '&ndash;', $output );
		$this->assertStringNotContainsString( '—', $output );
	}

	/**
	 * Test column_date renders a first–last span for recurring events.
	 *
	 * @return void
	 */
	public function test_column_date_renders_span(): void {
		$event             = new Event();
		$event->id         = 11;
		$event->event_type = 'recurring';

		$this->prime_map(
			'date_bounds',
			array(
				11 => array(
					'first'        => '2026-01-05 18:00:00',
					'last'         => '2026-12-20 18:00:00',
					'next'         => '2026-09-01 18:00:00',
					'next_all_day' => false,
				),
			)
		);

		$output = $this->list_table->column_date( $event );

		$this->assertStringContainsString( '&ndash;', $output );
	}

	/**
	 * Test column_date renders a dash when there are no occurrences.
	 *
	 * @return void
	 */
	public function test_column_date_no_occurrences(): void {
		$event             = new Event();
		$event->id         = 12;
		$event->event_type = 'single';

		// No entry in the date_bounds map.
		$output = $this->list_table->column_date( $event );

		$this->assertStringContainsString( '—', $output );
	}

	// =========================================================================
	// column_tickets_sold() Tests
	// =========================================================================

	/**
	 * Test column_tickets_sold renders count and percentage for a configured event.
	 *
	 * @return void
	 */
	public function test_column_tickets_sold_renders_count_and_percent(): void {
		$event     = new Event();
		$event->id = 5;

		$this->prime_map( 'sold_counts', array( 5 => 71 ) );
		$this->prime_map(
			'capacity_map',
			array(
				5 => array(
					'capacity'      => 100,
					'has_unlimited' => false,
					'configured'    => true,
				),
			)
		);

		$output = $this->list_table->column_tickets_sold( $event );

		$this->assertStringContainsString( '71', $output );
		$this->assertStringContainsString( '71% of capacity', $output );
	}

	/**
	 * Test column_tickets_sold renders "— of capacity" for unlimited capacity.
	 *
	 * @return void
	 */
	public function test_column_tickets_sold_unlimited_capacity(): void {
		$event     = new Event();
		$event->id = 9;

		$this->prime_map( 'sold_counts', array( 9 => 12 ) );
		$this->prime_map(
			'capacity_map',
			array(
				9 => array(
					'capacity'      => null,
					'has_unlimited' => true,
					'configured'    => true,
				),
			)
		);

		$output = $this->list_table->column_tickets_sold( $event );

		$this->assertStringContainsString( '12', $output );
		$this->assertStringContainsString( '— of capacity', $output );
		$this->assertStringNotContainsString( '%', $output );
	}

	/**
	 * Test column_tickets_sold renders a single dash for events with no ticketing.
	 *
	 * @return void
	 */
	public function test_column_tickets_sold_no_ticketing(): void {
		$event     = new Event();
		$event->id = 3;

		$this->prime_map(
			'capacity_map',
			array(
				3 => array(
					'capacity'      => 0,
					'has_unlimited' => false,
					'configured'    => false,
				),
			)
		);

		$output = $this->list_table->column_tickets_sold( $event );

		$this->assertStringContainsString( '—', $output );
		$this->assertStringNotContainsString( 'of capacity', $output );
	}

	/**
	 * Test column_tickets_sold guards divide-by-zero (zero capacity, tickets sold).
	 *
	 * @return void
	 */
	public function test_column_tickets_sold_zero_capacity_anomaly(): void {
		$event     = new Event();
		$event->id = 8;

		$this->prime_map( 'sold_counts', array( 8 => 5 ) );
		$this->prime_map(
			'capacity_map',
			array(
				8 => array(
					'capacity'      => 0,
					'has_unlimited' => false,
					'configured'    => true,
				),
			)
		);

		$output = $this->list_table->column_tickets_sold( $event );

		$this->assertStringContainsString( '5', $output );
		$this->assertStringContainsString( '>100% of capacity', $output );
	}
}
