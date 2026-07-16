<?php
/**
 * CsvParser unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\CsvParser;

/**
 * Test CsvParser functionality.
 *
 * Covers header parsing, delimiter detection, BOM handling,
 * line-ending normalization, and edge cases.
 */
class CsvParserTest extends \NetterTechEventsTestCase {

	/**
	 * CsvParser instance.
	 *
	 * @var CsvParser
	 */
	private CsvParser $parser;

	/**
	 * Temporary directory for test CSV files.
	 *
	 * @var string
	 */
	private string $tmp_dir;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser  = new CsvParser();
		$this->tmp_dir = sys_get_temp_dir() . '/nettertech_events_csv_parser_test_' . uniqid();
		mkdir( $this->tmp_dir, 0755, true );
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Clean up temp files.
		$files = glob( $this->tmp_dir . '/*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				unlink( $file );
			}
		}
		rmdir( $this->tmp_dir );

		parent::tearDown();
	}

	/**
	 * Write content to a temp CSV file and return the path.
	 *
	 * @param string $content CSV content.
	 * @param string $name    File name.
	 * @return string Absolute file path.
	 */
	private function write_csv( string $content, string $name = 'test.csv' ): string {
		$path = $this->tmp_dir . '/' . $name;
		file_put_contents( $path, $content );
		return $path;
	}

	// =========================================================================
	// Valid CSV Parsing
	// =========================================================================

	/**
	 * Test basic CSV parsing with comma delimiter.
	 *
	 * @return void
	 */
	public function test_parse_basic_csv(): void {
		$csv  = "Title,Start Date,End Date\n";
		$csv .= "Event One,2026-01-01,2026-01-02\n";
		$csv .= "Event Two,2026-02-01,2026-02-02\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertCount( 3, $result['headers'] );
		$this->assertEquals( array( 'title', 'start_date', 'end_date' ), $result['headers'] );
		$this->assertEquals( 2, $result['row_count'] );
		$this->assertEquals( ',', $result['delimiter'] );
		$this->assertEquals( 'Event One', $result['rows'][0]['title'] );
		$this->assertEquals( '2026-01-01', $result['rows'][0]['start_date'] );
		$this->assertEquals( 'Event Two', $result['rows'][1]['title'] );
	}

	/**
	 * Test CSV parsing with semicolon delimiter.
	 *
	 * @return void
	 */
	public function test_parse_semicolon_delimited_csv(): void {
		$csv  = "Title;Start Date;Status\n";
		$csv .= "My Event;2026-03-01;draft\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( ';', $result['delimiter'] );
		$this->assertEquals( 1, $result['row_count'] );
		$this->assertEquals( 'My Event', $result['rows'][0]['title'] );
	}

	/**
	 * Test CSV parsing with tab delimiter.
	 *
	 * @return void
	 */
	public function test_parse_tab_delimited_csv(): void {
		$csv  = "Title\tStart Date\tStatus\n";
		$csv .= "Tab Event\t2026-04-01\tpublished\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( "\t", $result['delimiter'] );
		$this->assertEquals( 'Tab Event', $result['rows'][0]['title'] );
	}

	// =========================================================================
	// BOM Handling
	// =========================================================================

	/**
	 * Test UTF-8 BOM is stripped.
	 *
	 * @return void
	 */
	public function test_strips_utf8_bom(): void {
		$bom  = "\xEF\xBB\xBF";
		$csv  = $bom . "Title,Date\n";
		$csv .= "BOM Event,2026-01-01\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( array( 'title', 'date' ), $result['headers'] );
		$this->assertEquals( 'BOM Event', $result['rows'][0]['title'] );
	}

	// =========================================================================
	// Line Ending Normalization
	// =========================================================================

	/**
	 * Test CRLF line endings are handled.
	 *
	 * @return void
	 */
	public function test_handles_crlf_line_endings(): void {
		$csv  = "Title,Date\r\n";
		$csv .= "CRLF Event,2026-01-01\r\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 1, $result['row_count'] );
		$this->assertEquals( 'CRLF Event', $result['rows'][0]['title'] );
	}

	/**
	 * Test CR-only line endings are handled.
	 *
	 * @return void
	 */
	public function test_handles_cr_only_line_endings(): void {
		$csv  = "Title,Date\r";
		$csv .= "CR Event,2026-01-01\r";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 1, $result['row_count'] );
		$this->assertEquals( 'CR Event', $result['rows'][0]['title'] );
	}

	/**
	 * Test mixed line endings are handled.
	 *
	 * @return void
	 */
	public function test_handles_mixed_line_endings(): void {
		$csv  = "Title,Date\r\n";
		$csv .= "Row One,2026-01-01\n";
		$csv .= "Row Two,2026-02-01\r";
		$csv .= "Row Three,2026-03-01\r\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 3, $result['row_count'] );
	}

	// =========================================================================
	// Empty Rows
	// =========================================================================

	/**
	 * Test empty rows are skipped.
	 *
	 * @return void
	 */
	public function test_skips_empty_rows(): void {
		$csv  = "Title,Date\n";
		$csv .= "Event A,2026-01-01\n";
		$csv .= ",\n";
		$csv .= "\n";
		$csv .= "Event B,2026-02-01\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 2, $result['row_count'] );
		$this->assertEquals( 'Event A', $result['rows'][0]['title'] );
		$this->assertEquals( 'Event B', $result['rows'][1]['title'] );
	}

	// =========================================================================
	// Quoted Fields
	// =========================================================================

	/**
	 * Test quoted fields with embedded commas.
	 *
	 * @return void
	 */
	public function test_quoted_fields_with_commas(): void {
		$csv  = "Title,Description\n";
		$csv .= '"Concert, Live","A great show, indeed"' . "\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 1, $result['row_count'] );
		$this->assertEquals( 'Concert, Live', $result['rows'][0]['title'] );
		$this->assertEquals( 'A great show, indeed', $result['rows'][0]['description'] );
	}

	/**
	 * Test quoted fields with embedded newlines.
	 *
	 * @return void
	 */
	public function test_quoted_fields_with_newlines(): void {
		$csv  = "Title,Description\n";
		$csv .= "\"Multi-line\",\"Line 1\nLine 2\"\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 1, $result['row_count'] );
		$this->assertStringContainsString( 'Line 1', $result['rows'][0]['description'] );
	}

	// =========================================================================
	// Header Normalization
	// =========================================================================

	/**
	 * Test headers are normalized to lowercase with underscores.
	 *
	 * @return void
	 */
	public function test_headers_are_normalized(): void {
		$csv  = "Event Title,Start Date,Organizer Email\n";
		$csv .= "Test,2026-01-01,test@example.com\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( array( 'event_title', 'start_date', 'organizer_email' ), $result['headers'] );
	}

	/**
	 * Test special characters in headers are replaced with underscores.
	 *
	 * @return void
	 */
	public function test_special_characters_in_headers_normalized(): void {
		$csv  = "Title (Main),Date/Time,Status!\n";
		$csv .= "Test,2026-01-01,draft\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 'title_main', $result['headers'][0] );
		$this->assertEquals( 'date_time', $result['headers'][1] );
		$this->assertEquals( 'status', $result['headers'][2] );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test empty file throws exception.
	 *
	 * @return void
	 */
	public function test_empty_file_throws_exception(): void {
		$path = $this->write_csv( '' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'CSV file is empty.' );

		$this->parser->parse( $path );
	}

	/**
	 * Test whitespace-only file throws exception.
	 *
	 * @return void
	 */
	public function test_whitespace_only_file_throws_exception(): void {
		$path = $this->write_csv( "   \n  \n  " );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'CSV file is empty.' );

		$this->parser->parse( $path );
	}

	/**
	 * Test non-existent file throws exception.
	 *
	 * @return void
	 */
	public function test_nonexistent_file_throws_exception(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'CSV file is not readable' );

		$this->parser->parse( '/tmp/nonexistent_file_xyz.csv' );
	}

	/**
	 * Test single row (header only, no data rows).
	 *
	 * @return void
	 */
	public function test_header_only_file_returns_zero_rows(): void {
		$path = $this->write_csv( "Title,Date,Status\n" );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 0, $result['row_count'] );
		$this->assertCount( 3, $result['headers'] );
		$this->assertEmpty( $result['rows'] );
	}

	/**
	 * Test single data row.
	 *
	 * @return void
	 */
	public function test_single_data_row(): void {
		$csv  = "Title,Date\n";
		$csv .= "Only Event,2026-05-01\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 1, $result['row_count'] );
		$this->assertEquals( 'Only Event', $result['rows'][0]['title'] );
	}

	/**
	 * Test row with fewer columns than headers gets padded.
	 *
	 * @return void
	 */
	public function test_short_row_is_padded(): void {
		$csv  = "Title,Date,Status\n";
		$csv .= "Short Row\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 1, $result['row_count'] );
		$this->assertEquals( 'Short Row', $result['rows'][0]['title'] );
		$this->assertEquals( '', $result['rows'][0]['date'] );
		$this->assertEquals( '', $result['rows'][0]['status'] );
	}

	/**
	 * Test row with more columns than headers gets truncated.
	 *
	 * @return void
	 */
	public function test_long_row_is_truncated(): void {
		$csv  = "Title,Date\n";
		$csv .= "Long Row,2026-01-01,extra,bonus\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->parse( $path );

		$this->assertEquals( 1, $result['row_count'] );
		$this->assertCount( 2, $result['rows'][0] );
		$this->assertEquals( 'Long Row', $result['rows'][0]['title'] );
	}

	// =========================================================================
	// Preview
	// =========================================================================

	/**
	 * Test preview returns limited rows.
	 *
	 * @return void
	 */
	public function test_preview_returns_limited_rows(): void {
		$csv  = "Title,Date\n";
		$csv .= "Event 1,2026-01-01\n";
		$csv .= "Event 2,2026-02-01\n";
		$csv .= "Event 3,2026-03-01\n";
		$csv .= "Event 4,2026-04-01\n";
		$csv .= "Event 5,2026-05-01\n";
		$csv .= "Event 6,2026-06-01\n";
		$path = $this->write_csv( $csv );

		$result = $this->parser->preview( $path, 3 );

		$this->assertCount( 3, $result['rows'] );
		$this->assertEquals( 'Event 1', $result['rows'][0]['title'] );
		$this->assertEquals( 'Event 3', $result['rows'][2]['title'] );
	}

	/**
	 * Test preview with default limit.
	 *
	 * @return void
	 */
	public function test_preview_default_limit_is_five(): void {
		$csv = "Title,Date\n";
		for ( $i = 1; $i <= 10; $i++ ) {
			$csv .= "Event {$i},2026-01-{$i}\n";
		}
		$path = $this->write_csv( $csv );

		$result = $this->parser->preview( $path );

		$this->assertCount( 5, $result['rows'] );
	}
}
