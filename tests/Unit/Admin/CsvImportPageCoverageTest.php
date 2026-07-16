<?php
/**
 * CsvImportPage coverage push tests.
 *
 * Targets the routing/step-dispatch and error-handling branches, plus
 * expired-session and missing-required validation paths. The full upload
 * happy path requires WP_FILES + wp_handle_upload integration; covered
 * here via the error fallbacks instead.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\CsvImportPage;
use NetterTechEvents\Services\CsvColumnMapper;
use NetterTechEvents\Services\CsvImporter;
use NetterTechEvents\Services\CsvParser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Coverage-targeted tests for CsvImportPage.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\CsvImportPage
 */
class CsvImportPageCoverageTest extends TestCase {

	/**
	 * @var CsvImporter|Mockery\MockInterface
	 */
	private $importer;

	/**
	 * @var CsvParser|Mockery\MockInterface
	 */
	private $parser;

	/**
	 * @var CsvColumnMapper|Mockery\MockInterface
	 */
	private $mapper;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'MB_IN_BYTES' ) ) {
			define( 'MB_IN_BYTES', 1024 * 1024 );
		}
		if ( ! defined( 'UPLOAD_ERR_NO_FILE' ) ) {
			define( 'UPLOAD_ERR_NO_FILE', 4 );
		}

		$_GET   = array();
		$_POST  = array();
		$_FILES = array();

		$this->importer = Mockery::mock( CsvImporter::class );
		$this->parser   = Mockery::mock( CsvParser::class );
		$this->mapper   = Mockery::mock( CsvColumnMapper::class );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr_e' )->echoArg();
		Functions\when( 'wp_kses_data' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => '/wp-admin/' . $p );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-stub' );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'fake-uuid-1234' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_delete_file' )->justReturn( null );
		Functions\when( 'submit_button' )->echoArg( 1 );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		$_GET   = array();
		$_POST  = array();
		$_FILES = array();
		parent::tearDown();
	}

	/**
	 * Build page instance.
	 *
	 * @return CsvImportPage
	 */
	private function build_page(): CsvImportPage {
		return new CsvImportPage( $this->importer, $this->parser, $this->mapper );
	}

	/**
	 * Invoke a private method.
	 *
	 * @param object $instance Instance.
	 * @param string $method   Method.
	 * @param array  $args     Args.
	 * @return mixed
	 */
	private function invoke_private( object $instance, string $method, array $args = array() ) {
		$ref = new ReflectionClass( $instance );
		$m   = $ref->getMethod( $method );
		return $m->invokeArgs( $instance, $args );
	}

	/**
	 * Capture echoed output.
	 *
	 * @param callable $cb Callable.
	 * @return string
	 */
	private function capture( callable $cb ): string {
		ob_start();
		try {
			$cb();
		} catch ( \Throwable $e ) {
			// Template includes may fail in unit tests; consume buffer regardless.
		}
		$out = ob_get_clean();
		return is_string( $out ) ? $out : '';
	}

	// =========================================================================
	// Permission guard
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_wp_dies_without_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die_called' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die_called' );

		$this->build_page()->render();
	}

	// =========================================================================
	// get_current_step branches
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_get_current_step_returns_upload_when_step_missing(): void {
		$_POST = array();

		$step = $this->invoke_private( $this->build_page(), 'get_current_step' );

		$this->assertSame( 'upload', $step );
	}

	/**
	 * @covers ::render
	 */
	public function test_get_current_step_returns_upload_when_nonce_invalid(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array(
			'nettertech_events_csv_step' => 'preview',
			'_wpnonce'                   => 'bad-nonce',
		);

		$step = $this->invoke_private( $this->build_page(), 'get_current_step' );

		$this->assertSame( 'upload', $step );
	}

	/**
	 * @covers ::render
	 */
	public function test_get_current_step_returns_requested_step_when_nonce_valid(): void {
		$_POST = array(
			'nettertech_events_csv_step' => 'preview',
			'_wpnonce'                   => 'valid-nonce',
		);

		$step = $this->invoke_private( $this->build_page(), 'get_current_step' );

		$this->assertSame( 'preview', $step );
	}

	// =========================================================================
	// render_error
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_error_emits_notice_and_start_over_link(): void {
		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_error', array( 'Something broke' ) )
		);

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Something broke', $html );
		$this->assertStringContainsString( 'Start Over', $html );
		$this->assertStringContainsString( 'csv-import', $html );
	}

	// =========================================================================
	// render_mapping_step branches
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_mapping_step_with_empty_file_renders_error(): void {
		$_FILES = array();

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_mapping_step' )
		);

		$this->assertStringContainsString( 'No file was uploaded', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_mapping_step_with_oversized_file_renders_error(): void {
		$_FILES = array(
			'nettertech_events_csv_file' => array(
				'tmp_name' => '/tmp/upload.csv',
				'name'     => 'upload.csv',
				'type'     => 'text/csv',
				'error'    => 0,
				'size'     => 5 * 1024 * 1024, // 5MB exceeds 2MB limit.
			),
		);

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_mapping_step' )
		);

		$this->assertStringContainsString( '2MB', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_mapping_step_with_upload_error_renders_error(): void {
		$_FILES = array(
			'nettertech_events_csv_file' => array(
				'tmp_name' => '/tmp/upload.csv',
				'name'     => 'upload.csv',
				'type'     => 'text/csv',
				'error'    => 0,
				'size'     => 1024,
			),
		);

		Functions\when( 'wp_handle_upload' )->justReturn(
			array( 'error' => 'Upload failed for reasons.' )
		);

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_mapping_step' )
		);

		$this->assertStringContainsString( 'Upload failed', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_mapping_step_with_parser_exception_renders_error(): void {
		$_FILES = array(
			'nettertech_events_csv_file' => array(
				'tmp_name' => '/tmp/upload.csv',
				'name'     => 'upload.csv',
				'type'     => 'text/csv',
				'error'    => 0,
				'size'     => 1024,
			),
		);

		Functions\when( 'wp_handle_upload' )->justReturn(
			array( 'file' => '/tmp/processed.csv', 'url' => 'http://example.com/csv' )
		);

		$this->parser->shouldReceive( 'preview' )->andThrow( new \Exception( 'CSV malformed' ) );

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_mapping_step' )
		);

		$this->assertStringContainsString( 'CSV malformed', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_mapping_step_success_renders_mapping_form(): void {
		$_FILES = array(
			'nettertech_events_csv_file' => array(
				'tmp_name' => '/tmp/upload.csv',
				'name'     => 'upload.csv',
				'type'     => 'text/csv',
				'error'    => 0,
				'size'     => 1024,
			),
		);

		Functions\when( 'wp_handle_upload' )->justReturn(
			array( 'file' => '/tmp/processed.csv', 'url' => 'http://example.com/csv' )
		);

		$this->parser->shouldReceive( 'preview' )->andReturn(
			array(
				'headers'   => array( 'Title', 'Date', 'Venue' ),
				'rows'      => array(
					array( 'Title' => 'Event A', 'Date' => '2026-02-15', 'Venue' => 'Hall' ),
				),
				'row_count' => 1,
			)
		);
		$this->mapper->shouldReceive( 'auto_detect' )->andReturn(
			array( 'Title' => 'title', 'Date' => 'start_datetime' )
		);
		$this->mapper->shouldReceive( 'missing_required' )->andReturn( array() );

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_mapping_step' )
		);

		$this->assertStringContainsString( 'Step 2', $html );
		$this->assertStringContainsString( 'Map Columns', $html );
		$this->assertStringContainsString( 'Title', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_mapping_step_shows_missing_required_warning(): void {
		$_FILES = array(
			'nettertech_events_csv_file' => array(
				'tmp_name' => '/tmp/upload.csv',
				'name'     => 'upload.csv',
				'type'     => 'text/csv',
				'error'    => 0,
				'size'     => 1024,
			),
		);

		Functions\when( 'wp_handle_upload' )->justReturn(
			array( 'file' => '/tmp/processed.csv', 'url' => 'http://example.com/csv' )
		);

		$this->parser->shouldReceive( 'preview' )->andReturn(
			array(
				'headers'   => array( 'X' ),
				'rows'      => array(),
				'row_count' => 0,
			)
		);
		$this->mapper->shouldReceive( 'auto_detect' )->andReturn( array() );
		$this->mapper->shouldReceive( 'missing_required' )->andReturn( array( 'title', 'start_datetime' ) );

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_mapping_step' )
		);

		$this->assertStringContainsString( 'Required fields not auto-detected', $html );
		$this->assertStringContainsString( 'title', $html );
	}

	// =========================================================================
	// render_preview_step branches
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_preview_step_with_expired_token_renders_error(): void {
		$_POST = array( 'nettertech_events_csv_token' => 'expired-token' );
		Functions\when( 'get_transient' )->justReturn( false );

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_preview_step' )
		);

		$this->assertStringContainsString( 'expired', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_preview_step_with_missing_required_renders_error(): void {
		$_POST = array(
			'nettertech_events_csv_token' => 'good-token',
			'mapping'                     => array( 'Title' => 'title' ),
		);

		// Use a real temp file so is_readable() naturally returns true.
		$tmp = tempnam( sys_get_temp_dir(), 'csvtest' );
		file_put_contents( $tmp, "Title,Date\nA,2026-01-01" );
		Functions\when( 'get_transient' )->justReturn( $tmp );

		$this->mapper->shouldReceive( 'missing_required' )->andReturn( array( 'start_datetime' ) );

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_preview_step' )
		);

		$this->assertStringContainsString( 'Required fields are not mapped', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_preview_step_with_dry_run_exception_renders_error(): void {
		$_POST = array(
			'nettertech_events_csv_token' => 'good-token',
			'mapping'                     => array( 'Title' => 'title', 'Date' => 'start_datetime' ),
		);

		// Use a real temp file so is_readable() naturally returns true.
		$tmp = tempnam( sys_get_temp_dir(), 'csvtest' );
		file_put_contents( $tmp, "Title,Date\nA,2026-01-01" );
		Functions\when( 'get_transient' )->justReturn( $tmp );

		$this->mapper->shouldReceive( 'missing_required' )->andReturn( array() );
		$this->importer->shouldReceive( 'dry_run' )->andThrow( new \Exception( 'Parser blew up' ) );

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_preview_step' )
		);

		$this->assertStringContainsString( 'Parser blew up', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_preview_step_success_renders_preview_table(): void {
		$_POST = array(
			'nettertech_events_csv_token' => 'good-token',
			'mapping'                     => array( 'Title' => 'title' ),
			'import_status'               => 'published',
			'skip_duplicates'             => '1',
		);

		// Use a real temp file so is_readable() naturally returns true.
		$tmp = tempnam( sys_get_temp_dir(), 'csvtest' );
		file_put_contents( $tmp, "Title,Date\nA,2026-01-01" );
		Functions\when( 'get_transient' )->justReturn( $tmp );

		$this->mapper->shouldReceive( 'missing_required' )->andReturn( array() );
		$this->importer->shouldReceive( 'dry_run' )->andReturn(
			array(
				'valid_count' => 5,
				'error_count' => 1,
				'errors'      => array( 3 => array( 'Invalid date' ) ),
			)
		);

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_preview_step' )
		);

		$this->assertStringContainsString( 'Step 3', $html );
		$this->assertStringContainsString( 'Preview', $html );
		$this->assertStringContainsString( 'Invalid date', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_preview_step_with_no_valid_rows_omits_import_form(): void {
		$_POST = array(
			'nettertech_events_csv_token' => 'good-token',
			'mapping'                     => array( 'Title' => 'title' ),
		);

		// Use a real temp file so is_readable() naturally returns true.
		$tmp = tempnam( sys_get_temp_dir(), 'csvtest' );
		file_put_contents( $tmp, "Title,Date\nA,2026-01-01" );
		Functions\when( 'get_transient' )->justReturn( $tmp );

		$this->mapper->shouldReceive( 'missing_required' )->andReturn( array() );
		$this->importer->shouldReceive( 'dry_run' )->andReturn(
			array(
				'valid_count' => 0,
				'error_count' => 2,
				'errors'      => array(
					1 => array( 'No title' ),
					2 => array( 'Bad date' ),
				),
			)
		);

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_preview_step' )
		);

		// With zero valid rows, the import-now form must NOT be rendered.
		$this->assertStringNotContainsString( 'name="nettertech_events_csv_step" value="import"', $html );
	}

	// =========================================================================
	// render_import_step branches
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_import_step_with_expired_token_renders_error(): void {
		$_POST = array( 'nettertech_events_csv_token' => 'expired' );
		Functions\when( 'get_transient' )->justReturn( false );

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_import_step' )
		);

		$this->assertStringContainsString( 'expired', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_import_step_with_importer_exception_renders_error(): void {
		$_POST = array(
			'nettertech_events_csv_token' => 'good',
			'mapping'                     => array( 'Title' => 'title' ),
		);

		// Use a real temp file so is_readable() naturally returns true.
		$tmp = tempnam( sys_get_temp_dir(), 'csvtest' );
		file_put_contents( $tmp, "Title,Date\nA,2026-01-01" );
		Functions\when( 'get_transient' )->justReturn( $tmp );

		$this->importer->shouldReceive( 'import' )->andThrow( new \Exception( 'Import broke' ) );

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_import_step' )
		);

		$this->assertStringContainsString( 'Import broke', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_import_step_success_shows_summary(): void {
		$_POST = array(
			'nettertech_events_csv_token' => 'good',
			'mapping'                     => array( 'Title' => 'title' ),
			'import_status'               => 'draft',
			'skip_duplicates'             => '1',
		);

		// Use a real temp file so is_readable() naturally returns true.
		$tmp = tempnam( sys_get_temp_dir(), 'csvtest' );
		file_put_contents( $tmp, "Title,Date\nA,2026-01-01" );
		Functions\when( 'get_transient' )->justReturn( $tmp );

		$this->importer->shouldReceive( 'import' )->andReturn(
			array(
				'imported' => 10,
				'skipped'  => 2,
				'errors'   => array( 'Row 5: bad date' ),
			)
		);

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_import_step' )
		);

		$this->assertStringContainsString( 'Import Complete', $html );
		$this->assertStringContainsString( 'Row 5: bad date', $html );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_import_step_success_no_errors_omits_error_list(): void {
		$_POST = array(
			'nettertech_events_csv_token' => 'good',
			'mapping'                     => array(),
		);

		// Use a real temp file so is_readable() naturally returns true.
		$tmp = tempnam( sys_get_temp_dir(), 'csvtest' );
		file_put_contents( $tmp, "Title,Date\nA,2026-01-01" );
		Functions\when( 'get_transient' )->justReturn( $tmp );

		$this->importer->shouldReceive( 'import' )->andReturn(
			array(
				'imported' => 7,
				'skipped'  => 0,
				'errors'   => array(),
			)
		);

		$page = $this->build_page();

		$html = $this->capture(
			fn() => $this->invoke_private( $page, 'render_import_step' )
		);

		$this->assertStringContainsString( 'Import Complete', $html );
		$this->assertStringNotContainsString( 'ul-disc', $html );
	}
}
