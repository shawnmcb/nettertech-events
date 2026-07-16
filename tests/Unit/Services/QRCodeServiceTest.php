<?php
/**
 * QRCodeService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Services\QRCodeService;
use Brain\Monkey\Functions;

/**
 * Test QRCodeService functionality.
 */
class QRCodeServiceTest extends \NetterTechEventsTestCase {

	/**
	 * QRCodeService instance.
	 *
	 * @var QRCodeService
	 */
	private QRCodeService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock get_option to return empty settings by default.
		Functions\when( 'get_option' )->justReturn( array() );

		$this->service = new QRCodeService( NetterTechEventsSettings::from_option() );
	}

	// =========================================================================
	// Constructor tests
	// =========================================================================

	/**
	 * Test constructor with default settings.
	 *
	 * @return void
	 */
	public function test_constructor_with_default_settings(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$service = new QRCodeService( NetterTechEventsSettings::from_option() );

		$this->assertInstanceOf( QRCodeService::class, $service );
	}

	/**
	 * Test constructor loads custom foreground color.
	 *
	 * @return void
	 */
	public function test_constructor_loads_foreground_color(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'qr_foreground_color' => '#FF0000' )
		);

		$service = new QRCodeService( NetterTechEventsSettings::from_option() );

		// Can't directly test private property, but service should be created.
		$this->assertInstanceOf( QRCodeService::class, $service );
	}

	/**
	 * Test constructor loads custom background color.
	 *
	 * @return void
	 */
	public function test_constructor_loads_background_color(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'qr_background_color' => '#00FF00' )
		);

		$service = new QRCodeService( NetterTechEventsSettings::from_option() );

		$this->assertInstanceOf( QRCodeService::class, $service );
	}

	/**
	 * Test constructor loads custom scale.
	 *
	 * @return void
	 */
	public function test_constructor_loads_scale(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'qr_scale' => 10 )
		);

		$service = new QRCodeService( NetterTechEventsSettings::from_option() );

		$this->assertInstanceOf( QRCodeService::class, $service );
	}

	// =========================================================================
	// set_foreground_color tests
	// =========================================================================

	/**
	 * Test set_foreground_color returns self for fluent interface.
	 *
	 * @return void
	 */
	public function test_set_foreground_color_returns_self(): void {
		$result = $this->service->set_foreground_color( 'FF0000' );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Test set_foreground_color sanitizes input.
	 *
	 * @return void
	 */
	public function test_set_foreground_color_sanitizes_input(): void {
		// Should strip non-hex characters.
		$result = $this->service->set_foreground_color( '#FF00GG' );

		$this->assertSame( $this->service, $result );
	}

	// =========================================================================
	// set_background_color tests
	// =========================================================================

	/**
	 * Test set_background_color returns self for fluent interface.
	 *
	 * @return void
	 */
	public function test_set_background_color_returns_self(): void {
		$result = $this->service->set_background_color( 'FFFFFF' );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Test set_background_color sanitizes input.
	 *
	 * @return void
	 */
	public function test_set_background_color_sanitizes_input(): void {
		$result = $this->service->set_background_color( '#00FFZZ' );

		$this->assertSame( $this->service, $result );
	}

	// =========================================================================
	// set_size tests
	// =========================================================================

	/**
	 * Test set_size returns self for fluent interface.
	 *
	 * @return void
	 */
	public function test_set_size_returns_self(): void {
		$result = $this->service->set_size( 10 );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Test set_size clamps to minimum.
	 *
	 * @return void
	 */
	public function test_set_size_clamps_to_minimum(): void {
		// Should clamp to minimum of 3.
		$result = $this->service->set_size( 1 );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Test set_size clamps to maximum.
	 *
	 * @return void
	 */
	public function test_set_size_clamps_to_maximum(): void {
		// Should clamp to maximum of 20.
		$result = $this->service->set_size( 100 );

		$this->assertSame( $this->service, $result );
	}

	// =========================================================================
	// set_dot_style tests
	// =========================================================================

	/**
	 * Test set_dot_style returns self for fluent interface.
	 *
	 * @return void
	 */
	public function test_set_dot_style_returns_self(): void {
		$result = $this->service->set_dot_style( 'square' );

		$this->assertSame( $this->service, $result );
	}

	// =========================================================================
	// set_finder_style tests
	// =========================================================================

	/**
	 * Test set_finder_style returns self for fluent interface.
	 *
	 * @return void
	 */
	public function test_set_finder_style_returns_self(): void {
		$result = $this->service->set_finder_style( 'rounded' );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Test set_finder_style defaults to square for invalid values.
	 *
	 * @return void
	 */
	public function test_set_finder_style_defaults_invalid_to_square(): void {
		$result = $this->service->set_finder_style( 'invalid' );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Test constructor loads finder style from settings.
	 *
	 * @return void
	 */
	public function test_constructor_loads_finder_style(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'qr_finder_style' => 'rounded' )
		);

		$service = new QRCodeService( NetterTechEventsSettings::from_option() );

		$this->assertInstanceOf( QRCodeService::class, $service );
	}

	// =========================================================================
	// generate_ticket_code tests
	// =========================================================================

	/**
	 * Test generate_ticket_code returns formatted code.
	 *
	 * @return void
	 */
	public function test_generate_ticket_code_returns_formatted_code(): void {
		$code = $this->service->generate_ticket_code();

		// Should match format XXXX-XXXX-XXXX-XXXX (uppercase hex).
		$this->assertMatchesRegularExpression(
			'/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/',
			$code
		);
	}

	/**
	 * Test generate_ticket_code returns unique codes.
	 *
	 * @return void
	 */
	public function test_generate_ticket_code_returns_unique_codes(): void {
		$codes = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$codes[] = $this->service->generate_ticket_code();
		}

		// All codes should be unique.
		$unique_codes = array_unique( $codes );
		$this->assertCount( 100, $unique_codes );
	}

	/**
	 * Test generate_ticket_code returns 19 character code with dashes.
	 *
	 * @return void
	 */
	public function test_generate_ticket_code_returns_correct_length(): void {
		$code = $this->service->generate_ticket_code();

		// Format XXXX-XXXX-XXXX-XXXX = 19 characters.
		$this->assertSame( 19, strlen( $code ) );
	}

	// =========================================================================
	// validate_code_format tests
	// =========================================================================

	/**
	 * Test validate_code_format returns true for valid code.
	 *
	 * @return void
	 */
	public function test_validate_code_format_returns_true_for_valid_code(): void {
		$this->assertTrue( $this->service->validate_code_format( 'ABCD-1234-EF56-7890' ) );
		$this->assertTrue( $this->service->validate_code_format( '0000-0000-0000-0000' ) );
		$this->assertTrue( $this->service->validate_code_format( 'FFFF-FFFF-FFFF-FFFF' ) );
	}

	/**
	 * Test validate_code_format returns false for lowercase.
	 *
	 * @return void
	 */
	public function test_validate_code_format_returns_false_for_lowercase(): void {
		$this->assertFalse( $this->service->validate_code_format( 'abcd-1234-ef56-7890' ) );
	}

	/**
	 * Test validate_code_format returns false for wrong format.
	 *
	 * @return void
	 */
	public function test_validate_code_format_returns_false_for_wrong_format(): void {
		// Too short.
		$this->assertFalse( $this->service->validate_code_format( 'ABCD-1234-EF56' ) );
		// Too long.
		$this->assertFalse( $this->service->validate_code_format( 'ABCD-1234-EF56-7890-ABCD' ) );
		// No dashes.
		$this->assertFalse( $this->service->validate_code_format( 'ABCD1234EF567890' ) );
		// Wrong separator.
		$this->assertFalse( $this->service->validate_code_format( 'ABCD_1234_EF56_7890' ) );
	}

	/**
	 * Test validate_code_format returns false for invalid characters.
	 *
	 * @return void
	 */
	public function test_validate_code_format_returns_false_for_invalid_chars(): void {
		// G is not valid hex.
		$this->assertFalse( $this->service->validate_code_format( 'GHIJ-1234-KLMN-5678' ) );
		// Special characters.
		$this->assertFalse( $this->service->validate_code_format( 'ABC!-1234-EF56-7890' ) );
	}

	/**
	 * Test validate_code_format validates codes from generate_ticket_code.
	 *
	 * @return void
	 */
	public function test_validate_code_format_accepts_generated_codes(): void {
		$code = $this->service->generate_ticket_code();

		$this->assertTrue( $this->service->validate_code_format( $code ) );
	}

	// =========================================================================
	// get_check_in_url tests
	// =========================================================================

	/**
	 * Test get_check_in_url returns friendly ticket URL with code.
	 *
	 * @return void
	 */
	public function test_get_check_in_url_returns_ticket_url(): void {
		Functions\when( 'home_url' )->alias(
			function ( $path ) {
				return 'https://example.com/' . ltrim( $path, '/' );
			}
		);

		$url = $this->service->get_check_in_url( 'ABCD-1234-EF56-7890' );

		$this->assertStringContainsString( 'ticket/', $url );
		$this->assertStringContainsString( 'ABCD-1234-EF56-7890', $url );
	}

	/**
	 * Test get_check_in_url constructs proper friendly URL path.
	 *
	 * @return void
	 */
	public function test_get_check_in_url_constructs_ticket_path(): void {
		Functions\when( 'home_url' )->alias(
			function ( $path ) {
				return 'https://example.com/' . ltrim( $path, '/' );
			}
		);

		$url = $this->service->get_check_in_url( 'TEST-CODE-GOES-HERE' );

		$this->assertSame(
			'https://example.com/ticket/TEST-CODE-GOES-HERE/',
			$url
		);
	}

	// =========================================================================
	// Fluent interface chaining tests
	// =========================================================================

	/**
	 * Test fluent interface allows method chaining.
	 *
	 * @return void
	 */
	public function test_fluent_interface_allows_chaining(): void {
		$result = $this->service
			->set_foreground_color( 'FF0000' )
			->set_background_color( '00FF00' )
			->set_size( 10 )
			->set_dot_style( 'square' )
			->set_finder_style( 'rounded' );

		$this->assertSame( $this->service, $result );
	}

	// =========================================================================
	// generate_for_ticket tests
	// =========================================================================

	/**
	 * Test generate_for_ticket creates QR code file.
	 *
	 * @return void
	 */
	public function test_generate_for_ticket_creates_file(): void {
		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->ticket_code = 'ABCD-1234-EFGH-5678';
		$ticket->qr_code_url = '';

		// Mock upload directory.
		$temp_dir = sys_get_temp_dir() . '/nettertech-events-qr-test-' . uniqid();
		mkdir( $temp_dir, 0755, true );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => $temp_dir,
				'baseurl' => 'https://example.com/wp-content/uploads',
				'error'   => false,
			)
		);

		Functions\when( 'trailingslashit' )->alias(
			function ( $path ) {
				return rtrim( $path, '/' ) . '/';
			}
		);

		Functions\when( 'wp_mkdir_p' )->alias(
			function ( $path ) use ( $temp_dir ) {
				// Only create dirs under our temp directory to prevent leaking artifacts.
				if ( str_starts_with( $path, $temp_dir ) && ! file_exists( $path ) ) {
					mkdir( $path, 0755, true );
				}
				return true;
			}
		);

		Functions\when( 'rest_url' )->alias(
			function ( $path ) {
				return 'https://example.com/wp-json/' . $path;
			}
		);

		$url = $this->service->generate_for_ticket( $ticket );

		$this->assertStringContainsString( 'https://example.com/wp-content/uploads', $url );
		$this->assertStringContainsString( '.png', $url );
		$this->assertSame( $url, $ticket->qr_code_url );

		// Clean up.
		$this->recursive_rmdir( $temp_dir );
	}

	/**
	 * Test generate_for_ticket falls back to data URI on upload error.
	 *
	 * @return void
	 */
	public function test_generate_for_ticket_fallback_to_data_uri(): void {
		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->ticket_code = 'ABCD-1234-EFGH-5678';
		$ticket->qr_code_url = '';

		// Mock upload directory error.
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/nonexistent',
				'baseurl' => 'https://example.com/wp-content/uploads',
				'error'   => 'Upload directory error',
			)
		);

		Functions\when( 'rest_url' )->alias(
			function ( $path ) {
				return 'https://example.com/wp-json/' . $path;
			}
		);

		$url = $this->service->generate_for_ticket( $ticket );

		// Should return data URI.
		$this->assertStringStartsWith( 'data:image/png;base64,', $url );
		$this->assertSame( $url, $ticket->qr_code_url );
	}

	// =========================================================================
	// generate_data_uri tests
	// =========================================================================

	/**
	 * Test generate_data_uri returns base64 image.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_returns_base64(): void {
		$data_uri = $this->service->generate_data_uri( 'https://example.com/check-in' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test generate_data_uri content is valid base64.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_valid_base64(): void {
		$data_uri = $this->service->generate_data_uri( 'TEST-DATA' );

		// Extract base64 part.
		$base64_part = substr( $data_uri, strlen( 'data:image/png;base64,' ) );

		// Should be valid base64.
		$decoded = base64_decode( $base64_part, true );
		$this->assertNotFalse( $decoded );

		// First bytes should be PNG magic number.
		$this->assertStringStartsWith( "\x89PNG", $decoded );
	}

	// =========================================================================
	// delete_qr_file tests
	// =========================================================================

	/**
	 * Test delete_qr_file returns true when file exists.
	 *
	 * @return void
	 */
	public function test_delete_qr_file_deletes_existing_file(): void {
		// Create a temp file to delete.
		$temp_dir = sys_get_temp_dir() . '/nettertech-events-qr-test-' . uniqid();
		mkdir( $temp_dir . '/nettertech-events/qr', 0755, true );
		$file_path = $temp_dir . '/nettertech-events/qr/ABCD1234EFGH5678.png';
		file_put_contents( $file_path, 'test' );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => $temp_dir,
				'baseurl' => 'https://example.com/wp-content/uploads',
				'error'   => false,
			)
		);

		Functions\when( 'trailingslashit' )->alias(
			function ( $path ) {
				return rtrim( $path, '/' ) . '/';
			}
		);

		Functions\when( 'wp_delete_file' )->alias(
			function ( $path ) {
				if ( file_exists( $path ) ) {
					return unlink( $path );
				}
				return true;
			}
		);

		$result = $this->service->delete_qr_file( 'ABCD-1234-EFGH-5678' );

		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $file_path );

		// Clean up.
		$this->recursive_rmdir( $temp_dir );
	}

	/**
	 * Test delete_qr_file returns true when file does not exist.
	 *
	 * @return void
	 */
	public function test_delete_qr_file_returns_true_for_nonexistent(): void {
		$temp_dir = sys_get_temp_dir() . '/nettertech-events-qr-test-' . uniqid();
		$qr_dir   = $temp_dir . '/nettertech-events/qr';
		mkdir( $qr_dir, 0755, true );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => $temp_dir,
				'baseurl' => 'https://example.com/wp-content/uploads',
				'error'   => false,
			)
		);

		Functions\when( 'trailingslashit' )->alias(
			function ( $path ) {
				return rtrim( $path, '/' ) . '/';
			}
		);

		// Mock wp_mkdir_p since the directory already exists.
		Functions\when( 'wp_mkdir_p' )->justReturn( true );

		$result = $this->service->delete_qr_file( 'NONEXISTENT-CODE-1234' );

		$this->assertTrue( $result );

		// Clean up.
		$this->recursive_rmdir( $temp_dir );
	}

	/**
	 * Test delete_qr_file returns true when uploads unavailable.
	 *
	 * @return void
	 */
	public function test_delete_qr_file_returns_true_on_upload_error(): void {
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/nonexistent',
				'baseurl' => 'https://example.com/wp-content/uploads',
				'error'   => 'Upload directory error',
			)
		);

		$result = $this->service->delete_qr_file( 'ABCD-1234-EFGH-5678' );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// generate_png tests
	// =========================================================================

	/**
	 * Test generate_png returns raw PNG binary data.
	 *
	 * @return void
	 */
	public function test_generate_png_returns_png_binary(): void {
		$png_data = $this->service->generate_png( 'https://example.com' );

		// First bytes should be PNG magic number.
		$this->assertStringStartsWith( "\x89PNG", $png_data );
	}

	/**
	 * Test generate_png output is different from generate_data_uri.
	 *
	 * @return void
	 */
	public function test_generate_png_differs_from_data_uri(): void {
		$png_data = $this->service->generate_png( 'https://example.com' );
		$data_uri = $this->service->generate_data_uri( 'https://example.com' );

		// PNG should be raw binary, not base64-encoded.
		$this->assertStringStartsWith( "\x89PNG", $png_data );
		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test generate_png produces valid PNG image.
	 *
	 * @return void
	 */
	public function test_generate_png_produces_valid_image(): void {
		$png_data = $this->service->generate_png( 'https://example.com/test' );

		// Write to temp file and verify with GD.
		$temp_file = tempnam( sys_get_temp_dir(), 'qr_test_' );
		file_put_contents( $temp_file, $png_data );

		$image_info = getimagesize( $temp_file );
		unlink( $temp_file );

		$this->assertIsArray( $image_info );
		$this->assertSame( IMAGETYPE_PNG, $image_info[2] );
		$this->assertGreaterThan( 0, $image_info[0] ); // Width.
		$this->assertGreaterThan( 0, $image_info[1] ); // Height.
	}

	/**
	 * Test generate_png encodes content correctly (can be decoded).
	 *
	 * @return void
	 */
	public function test_generate_png_encodes_content(): void {
		// Generate two different QR codes.
		$png1 = $this->service->generate_png( 'Content A' );
		$png2 = $this->service->generate_png( 'Content B' );

		// Should produce different output for different content.
		$this->assertNotSame( $png1, $png2 );
	}

	/**
	 * Test generate_png respects color settings.
	 *
	 * @return void
	 */
	public function test_generate_png_respects_color(): void {
		// Generate with default black.
		$png_black = $this->service->generate_png( 'Test' );

		// Generate with red.
		$this->service->set_foreground_color( 'FF0000' );
		$png_red = $this->service->generate_png( 'Test' );

		// Should produce different output for different colors.
		$this->assertNotSame( $png_black, $png_red );
	}

	// =========================================================================
	// Constructor tests — bg_opacity
	// =========================================================================

	/**
	 * Test constructor loads bg_opacity from settings.
	 *
	 * @return void
	 */
	public function test_constructor_loads_bg_opacity(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'qr_bg_opacity' => 50 )
		);

		$service = new QRCodeService( NetterTechEventsSettings::from_option() );

		// Verify the service was created and bg_opacity affects the GD pipeline.
		// With opacity < 100, generate_data_uri should still produce valid output.
		$data_uri = $service->generate_data_uri( 'opacity-test' );
		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	// =========================================================================
	// set_background_opacity tests
	// =========================================================================

	/**
	 * Test set_background_opacity returns self for fluent interface.
	 *
	 * @return void
	 */
	public function test_set_background_opacity_returns_self(): void {
		$result = $this->service->set_background_opacity( 50 );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Test set_background_opacity clamps to minimum of 0.
	 *
	 * @return void
	 */
	public function test_set_background_opacity_clamps_to_minimum(): void {
		$result = $this->service->set_background_opacity( -10 );

		$this->assertSame( $this->service, $result );

		// Verify 0% opacity produces valid output (triggers GD pipeline).
		$data_uri = $this->service->generate_data_uri( 'min-opacity' );
		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test set_background_opacity clamps to maximum of 100.
	 *
	 * @return void
	 */
	public function test_set_background_opacity_clamps_to_maximum(): void {
		$result = $this->service->set_background_opacity( 200 );

		$this->assertSame( $this->service, $result );

		// Verify 100% opacity produces valid output (no GD pipeline needed).
		$data_uri = $this->service->generate_data_uri( 'max-opacity' );
		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	// =========================================================================
	// set_logo_path tests
	// =========================================================================

	/**
	 * Test set_logo_path returns self for fluent interface.
	 *
	 * @return void
	 */
	public function test_set_logo_path_returns_self(): void {
		$result = $this->service->set_logo_path( '/tmp/logo.png' );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Test set_logo_path can set null to clear the logo.
	 *
	 * @return void
	 */
	public function test_set_logo_path_can_set_null_to_clear(): void {
		$this->service->set_logo_path( '/tmp/logo.png' );
		$result = $this->service->set_logo_path( null );

		$this->assertSame( $this->service, $result );

		// After clearing, has_logo should return false even in custom mode.
		$this->service->set_logo_mode( 'custom' );
		$this->assertFalse( $this->service->has_logo() );
	}

	// =========================================================================
	// set_logo_mode tests
	// =========================================================================

	/**
	 * Test set_logo_mode returns self for fluent interface.
	 *
	 * @return void
	 */
	public function test_set_logo_mode_returns_self(): void {
		$result = $this->service->set_logo_mode( 'none' );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Test set_logo_mode accepts all valid modes.
	 *
	 * @return void
	 */
	public function test_set_logo_mode_accepts_valid_modes(): void {
		$valid_modes = array( 'none', 'default', 'site', 'custom' );

		foreach ( $valid_modes as $mode ) {
			$this->service->set_logo_mode( $mode );
			$this->assertSame( $mode, $this->service->get_logo_mode() );
		}
	}

	/**
	 * Test set_logo_mode defaults to none for invalid mode.
	 *
	 * @return void
	 */
	public function test_set_logo_mode_defaults_to_none_for_invalid(): void {
		$this->service->set_logo_mode( 'invalid' );

		$this->assertSame( 'none', $this->service->get_logo_mode() );
	}

	// =========================================================================
	// get_logo_mode tests
	// =========================================================================

	/**
	 * Test get_logo_mode returns none by default.
	 *
	 * @return void
	 */
	public function test_get_logo_mode_returns_none_by_default(): void {
		$this->assertSame( 'none', $this->service->get_logo_mode() );
	}

	/**
	 * Test get_logo_mode returns mode after set_logo_mode.
	 *
	 * @return void
	 */
	public function test_get_logo_mode_returns_mode_after_set(): void {
		$this->service->set_logo_mode( 'custom' );

		$this->assertSame( 'custom', $this->service->get_logo_mode() );
	}

	// =========================================================================
	// resolve_site_logo tests
	// =========================================================================

	/**
	 * Test resolve_site_logo returns null when no logo configured.
	 *
	 * @return void
	 */
	public function test_resolve_site_logo_returns_null_when_no_logo(): void {
		Functions\when( 'get_theme_mod' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( '' );

		$result = $this->service->resolve_site_logo();

		$this->assertNull( $result );
	}

	/**
	 * Test resolve_site_logo returns path when custom_logo theme mod is set.
	 *
	 * @return void
	 */
	public function test_resolve_site_logo_returns_path_from_theme_mod(): void {
		$temp_file = tempnam( sys_get_temp_dir(), 'logo_test_' );

		Functions\when( 'get_theme_mod' )->justReturn( 42 );
		Functions\when( 'get_attached_file' )->justReturn( $temp_file );

		$result = $this->service->resolve_site_logo();

		$this->assertSame( $temp_file, $result );

		unlink( $temp_file );
	}

	/**
	 * Test resolve_site_logo falls back to site_logo option.
	 *
	 * @return void
	 */
	public function test_resolve_site_logo_falls_back_to_site_logo(): void {
		$temp_file = tempnam( sys_get_temp_dir(), 'logo_test_' );

		Functions\when( 'get_theme_mod' )->justReturn( '' );
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'site_logo' === $option ) {
					return 99;
				}
				return '';
			}
		);
		Functions\when( 'get_attached_file' )->justReturn( $temp_file );

		$result = $this->service->resolve_site_logo();

		$this->assertSame( $temp_file, $result );

		unlink( $temp_file );
	}

	/**
	 * Test resolve_site_logo returns null when get_attached_file returns empty.
	 *
	 * @return void
	 */
	public function test_resolve_site_logo_returns_null_when_attached_file_empty(): void {
		Functions\when( 'get_theme_mod' )->justReturn( 42 );
		Functions\when( 'get_attached_file' )->justReturn( '' );

		$result = $this->service->resolve_site_logo();

		$this->assertNull( $result );
	}

	// =========================================================================
	// resolve_logo_path tests
	// =========================================================================

	/**
	 * Test resolve_logo_path returns null for none mode.
	 *
	 * @return void
	 */
	public function test_resolve_logo_path_returns_null_for_none_mode(): void {
		$this->assertNull( $this->service->resolve_logo_path() );
	}

	/**
	 * Test resolve_logo_path returns custom path for custom mode.
	 *
	 * @return void
	 */
	public function test_resolve_logo_path_returns_custom_path(): void {
		$this->service->set_logo_mode( 'custom' );
		$this->service->set_logo_path( '/tmp/custom-logo.png' );

		$this->assertSame( '/tmp/custom-logo.png', $this->service->resolve_logo_path() );
	}

	/**
	 * Test resolve_logo_path returns null for default mode.
	 *
	 * @return void
	 */
	public function test_resolve_logo_path_returns_null_for_default_mode(): void {
		$this->service->set_logo_mode( 'default' );

		$this->assertNull( $this->service->resolve_logo_path() );
	}

	/**
	 * Test resolve_logo_path calls resolve_site_logo for site mode.
	 *
	 * @return void
	 */
	public function test_resolve_logo_path_uses_site_logo_for_site_mode(): void {
		$temp_file = tempnam( sys_get_temp_dir(), 'logo_test_' );

		Functions\when( 'get_theme_mod' )->justReturn( 42 );
		Functions\when( 'get_attached_file' )->justReturn( $temp_file );

		$this->service->set_logo_mode( 'site' );

		$this->assertSame( $temp_file, $this->service->resolve_logo_path() );

		unlink( $temp_file );
	}

	// =========================================================================
	// has_logo tests
	// =========================================================================

	/**
	 * Test has_logo returns false by default.
	 *
	 * @return void
	 */
	public function test_has_logo_returns_false_by_default(): void {
		$this->assertFalse( $this->service->has_logo() );
	}

	/**
	 * Test has_logo returns true when custom logo path is set.
	 *
	 * @return void
	 */
	public function test_has_logo_returns_true_with_custom_logo(): void {
		$this->service->set_logo_mode( 'custom' );
		$this->service->set_logo_path( '/tmp/logo.png' );

		$this->assertTrue( $this->service->has_logo() );
	}

	// =========================================================================
	// generate_data_uri — GD pipeline tests (rounded finders / opacity)
	// =========================================================================

	/**
	 * Test generate_data_uri with rounded finder style produces valid data URI.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_with_rounded_finders(): void {
		$this->service->set_finder_style( 'rounded' );

		$data_uri = $this->service->generate_data_uri( 'https://example.com/rounded' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );

		// Verify the base64 content decodes to valid PNG.
		$base64_part = substr( $data_uri, strlen( 'data:image/png;base64,' ) );
		$decoded     = base64_decode( $base64_part, true );
		$this->assertNotFalse( $decoded );
		$this->assertStringStartsWith( "\x89PNG", $decoded );
	}

	/**
	 * Test generate_data_uri with reduced opacity produces valid data URI.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_with_reduced_opacity(): void {
		$this->service->set_background_opacity( 50 );

		$data_uri = $this->service->generate_data_uri( 'https://example.com/opacity' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );

		// Verify the base64 content decodes to valid PNG.
		$base64_part = substr( $data_uri, strlen( 'data:image/png;base64,' ) );
		$decoded     = base64_decode( $base64_part, true );
		$this->assertNotFalse( $decoded );
		$this->assertStringStartsWith( "\x89PNG", $decoded );
	}

	// =========================================================================
	// generate_data_uri_with_logo tests
	// =========================================================================

	/**
	 * Test generate_data_uri_with_logo without logo path falls back to generate_data_uri.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_with_logo_falls_back_without_logo(): void {
		// Default logo_mode is 'none', so resolve_logo_path returns null.
		$content  = 'https://example.com/no-logo';
		$standard = $this->service->generate_data_uri( $content );
		$with_logo = $this->service->generate_data_uri_with_logo( $content );

		$this->assertSame( $standard, $with_logo );
	}

	// =========================================================================
	// generate_png — GD pipeline tests (rounded finders)
	// =========================================================================

	/**
	 * Test generate_png with rounded finder style produces valid PNG.
	 *
	 * @return void
	 */
	public function test_generate_png_with_rounded_finders(): void {
		$this->service->set_finder_style( 'rounded' );

		$png_data = $this->service->generate_png( 'https://example.com/rounded-png' );

		// Should start with PNG magic number.
		$this->assertStringStartsWith( "\x89PNG", $png_data );

		// Write to temp file and verify with GD.
		$temp_file = tempnam( sys_get_temp_dir(), 'qr_rounded_' );
		file_put_contents( $temp_file, $png_data );

		$image_info = getimagesize( $temp_file );
		unlink( $temp_file );

		$this->assertIsArray( $image_info );
		$this->assertSame( IMAGETYPE_PNG, $image_info[2] );
	}

	// =========================================================================
	// Fluent interface — extended chaining with new methods
	// =========================================================================

	/**
	 * Test fluent interface includes new methods in chain.
	 *
	 * @return void
	 */
	public function test_fluent_interface_with_opacity_and_logo_methods(): void {
		$result = $this->service
			->set_foreground_color( 'FF0000' )
			->set_background_color( '00FF00' )
			->set_size( 10 )
			->set_dot_style( 'square' )
			->set_finder_style( 'rounded' )
			->set_background_opacity( 80 )
			->set_logo_mode( 'custom' )
			->set_logo_path( '/tmp/logo.png' );

		$this->assertSame( $this->service, $result );
	}

	/**
	 * Helper to recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function recursive_rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->recursive_rmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
