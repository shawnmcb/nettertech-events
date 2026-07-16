<?php
/**
 * CsvColumnMapper unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\CsvColumnMapper;

/**
 * Test CsvColumnMapper functionality.
 *
 * Covers auto-detection, alias resolution, manual override merging,
 * and required field validation.
 */
class CsvColumnMapperTest extends \NetterTechEventsTestCase {

	/**
	 * CsvColumnMapper instance.
	 *
	 * @var CsvColumnMapper
	 */
	private CsvColumnMapper $mapper;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new CsvColumnMapper();
	}

	// =========================================================================
	// auto_detect() Tests
	// =========================================================================

	/**
	 * Test auto-detection of standard header names.
	 *
	 * @return void
	 */
	public function test_auto_detect_standard_headers(): void {
		$headers = array( 'title', 'start_date', 'end_date', 'description' );
		$mapping = $this->mapper->auto_detect( $headers );

		$this->assertEquals( 'title', $mapping['title'] );
		$this->assertEquals( 'start_date', $mapping['start_date'] );
		$this->assertEquals( 'end_date', $mapping['end_date'] );
		$this->assertEquals( 'description', $mapping['description'] );
	}

	/**
	 * Test auto-detection of alias header names.
	 *
	 * @return void
	 */
	public function test_auto_detect_alias_headers(): void {
		$headers = array( 'event_name', 'date', 'location', 'host' );
		$mapping = $this->mapper->auto_detect( $headers );

		$this->assertEquals( 'title', $mapping['event_name'] );
		$this->assertEquals( 'start_date', $mapping['date'] );
		$this->assertEquals( 'venue_name', $mapping['location'] );
		$this->assertEquals( 'organizer_name', $mapping['host'] );
	}

	/**
	 * Test auto-detection with all recognized aliases.
	 *
	 * @return void
	 */
	public function test_auto_detect_various_aliases(): void {
		$test_cases = array(
			'subject'        => 'title',
			'summary'        => 'title',
			'details'        => 'description',
			'body'           => 'description',
			'notes'          => 'description',
			'begin_date'     => 'start_date',
			'begin_time'     => 'start_time',
			'finish_date'    => 'end_date',
			'finish_time'    => 'end_time',
			'allday'         => 'all_day',
			'venue'          => 'venue_name',
			'place'          => 'venue_name',
			'address'        => 'venue_address',
			'presenter'      => 'organizer_name',
			'categories'     => 'category',
			'tag'            => 'tags',
			'keywords'       => 'tags',
			'rrule'          => 'recurrence_rule',
			'repeat'         => 'recurrence_rule',
			'event_status'   => 'status',
			'image'          => 'image_url',
			'featured_image' => 'image_url',
			'photo_url'      => 'image_url',
		);

		foreach ( $test_cases as $alias => $expected_field ) {
			$mapping = $this->mapper->auto_detect( array( $alias ) );
			$this->assertEquals(
				$expected_field,
				$mapping[ $alias ],
				sprintf( 'Alias "%s" should map to "%s"', $alias, $expected_field )
			);
		}
	}

	/**
	 * Test unrecognized headers map to empty string.
	 *
	 * @return void
	 */
	public function test_unrecognized_headers_map_to_empty(): void {
		$headers = array( 'title', 'custom_field', 'unknown_column' );
		$mapping = $this->mapper->auto_detect( $headers );

		$this->assertEquals( 'title', $mapping['title'] );
		$this->assertEquals( '', $mapping['custom_field'] );
		$this->assertEquals( '', $mapping['unknown_column'] );
	}

	/**
	 * Test auto-detection with empty headers array.
	 *
	 * @return void
	 */
	public function test_auto_detect_empty_headers(): void {
		$mapping = $this->mapper->auto_detect( array() );

		$this->assertEmpty( $mapping );
	}

	// =========================================================================
	// apply() Tests
	// =========================================================================

	/**
	 * Test applying a mapping to a row.
	 *
	 * @return void
	 */
	public function test_apply_mapping_to_row(): void {
		$row     = array(
			'event_name' => 'Concert',
			'date'       => '2026-01-15',
			'location'   => 'Main Hall',
		);
		$mapping = array(
			'event_name' => 'title',
			'date'       => 'start_date',
			'location'   => 'venue_name',
		);

		$result = $this->mapper->apply( $row, $mapping );

		$this->assertEquals( 'Concert', $result['title'] );
		$this->assertEquals( '2026-01-15', $result['start_date'] );
		$this->assertEquals( 'Main Hall', $result['venue_name'] );
	}

	/**
	 * Test unmapped fields are excluded from result.
	 *
	 * @return void
	 */
	public function test_apply_skips_unmapped_fields(): void {
		$row     = array(
			'title'        => 'Concert',
			'custom_field' => 'some value',
		);
		$mapping = array(
			'title'        => 'title',
			'custom_field' => '',
		);

		$result = $this->mapper->apply( $row, $mapping );

		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayNotHasKey( 'custom_field', $result );
		$this->assertCount( 1, $result );
	}

	/**
	 * Test apply trims values.
	 *
	 * @return void
	 */
	public function test_apply_trims_values(): void {
		$row     = array( 'title' => '  Trimmed Title  ' );
		$mapping = array( 'title' => 'title' );

		$result = $this->mapper->apply( $row, $mapping );

		$this->assertEquals( 'Trimmed Title', $result['title'] );
	}

	/**
	 * Test apply skips keys not in row.
	 *
	 * @return void
	 */
	public function test_apply_skips_missing_row_keys(): void {
		$row     = array( 'title' => 'Event' );
		$mapping = array(
			'title' => 'title',
			'date'  => 'start_date',
		);

		$result = $this->mapper->apply( $row, $mapping );

		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayNotHasKey( 'start_date', $result );
	}

	// =========================================================================
	// merge_override() Tests
	// =========================================================================

	/**
	 * Test manual override replaces auto-detected mapping.
	 *
	 * @return void
	 */
	public function test_merge_override_replaces_auto_mapping(): void {
		$auto   = array(
			'name' => 'title',
			'date' => 'start_date',
		);
		$manual = array(
			'name' => 'organizer_name',
		);

		$result = $this->mapper->merge_override( $auto, $manual );

		$this->assertEquals( 'organizer_name', $result['name'] );
		$this->assertEquals( 'start_date', $result['date'] );
	}

	/**
	 * Test manual override for non-existent key is ignored.
	 *
	 * @return void
	 */
	public function test_merge_override_ignores_nonexistent_keys(): void {
		$auto   = array(
			'title' => 'title',
		);
		$manual = array(
			'nonexistent' => 'description',
		);

		$result = $this->mapper->merge_override( $auto, $manual );

		$this->assertEquals( array( 'title' => 'title' ), $result );
	}

	// =========================================================================
	// missing_required() Tests
	// =========================================================================

	/**
	 * Test no missing required fields when all present.
	 *
	 * @return void
	 */
	public function test_no_missing_required_when_all_present(): void {
		$mapping = array(
			'event_name' => 'title',
			'date'       => 'start_date',
			'location'   => 'venue_name',
		);

		$missing = $this->mapper->missing_required( $mapping );

		$this->assertEmpty( $missing );
	}

	/**
	 * Test missing title field is detected.
	 *
	 * @return void
	 */
	public function test_missing_title_detected(): void {
		$mapping = array(
			'date' => 'start_date',
		);

		$missing = $this->mapper->missing_required( $mapping );

		$this->assertContains( 'title', $missing );
		$this->assertNotContains( 'start_date', $missing );
	}

	/**
	 * Test missing start_date field is detected.
	 *
	 * @return void
	 */
	public function test_missing_start_date_detected(): void {
		$mapping = array(
			'name' => 'title',
		);

		$missing = $this->mapper->missing_required( $mapping );

		$this->assertContains( 'start_date', $missing );
		$this->assertNotContains( 'title', $missing );
	}

	/**
	 * Test all required fields missing when mapping is empty.
	 *
	 * @return void
	 */
	public function test_all_required_missing_when_empty_mapping(): void {
		$missing = $this->mapper->missing_required( array() );

		$this->assertContains( 'title', $missing );
		$this->assertContains( 'start_date', $missing );
		$this->assertCount( 2, $missing );
	}

	/**
	 * Test unmapped columns (empty string values) are not counted as mapped.
	 *
	 * @return void
	 */
	public function test_unmapped_columns_not_counted(): void {
		$mapping = array(
			'col1' => '',
			'col2' => '',
		);

		$missing = $this->mapper->missing_required( $mapping );

		$this->assertCount( 2, $missing );
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test FIELDS constant contains expected field definitions.
	 *
	 * @return void
	 */
	public function test_fields_constant_contains_expected_keys(): void {
		$this->assertArrayHasKey( 'title', CsvColumnMapper::FIELDS );
		$this->assertArrayHasKey( 'start_date', CsvColumnMapper::FIELDS );
		$this->assertArrayHasKey( 'end_date', CsvColumnMapper::FIELDS );
		$this->assertArrayHasKey( 'venue_name', CsvColumnMapper::FIELDS );
		$this->assertArrayHasKey( 'status', CsvColumnMapper::FIELDS );
		$this->assertArrayHasKey( 'image_url', CsvColumnMapper::FIELDS );
	}

	/**
	 * Test REQUIRED_FIELDS contains title and start_date.
	 *
	 * @return void
	 */
	public function test_required_fields_contains_title_and_start_date(): void {
		$this->assertContains( 'title', CsvColumnMapper::REQUIRED_FIELDS );
		$this->assertContains( 'start_date', CsvColumnMapper::REQUIRED_FIELDS );
		$this->assertCount( 2, CsvColumnMapper::REQUIRED_FIELDS );
	}
}
