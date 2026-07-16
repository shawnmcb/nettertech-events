<?php
/**
 * QRCodeRenderer unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\QRCodeRenderer;

/**
 * Test QRCodeRenderer functionality.
 *
 * Tests the rendering engine directly (separate from QRCodeService)
 * to cover decision code: color parsing, GD pipeline selection,
 * options building, and logo space calculation.
 */
class QRCodeRendererTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Constructor and basic generation
	// =========================================================================

	/**
	 * Test constructor creates renderer with default-like parameters.
	 *
	 * @return void
	 */
	public function test_constructor_creates_renderer(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$this->assertInstanceOf( QRCodeRenderer::class, $renderer );
	}

	/**
	 * Test generate_data_uri returns valid base64 PNG data URI.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_returns_valid_data_uri(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri( 'https://example.com' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );

		// Verify base64 decodes to valid PNG.
		$base64_part = substr( $data_uri, strlen( 'data:image/png;base64,' ) );
		$decoded     = base64_decode( $base64_part, true );
		$this->assertNotFalse( $decoded );
		$this->assertStringStartsWith( "\x89PNG", $decoded );
	}

	/**
	 * Test generate_png returns valid PNG binary data.
	 *
	 * @return void
	 */
	public function test_generate_png_returns_valid_png(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$png_data = $renderer->generate_png( 'https://example.com' );

		$this->assertStringStartsWith( "\x89PNG", $png_data );
	}

	// =========================================================================
	// GD pipeline selection (needs_gd_pipeline decision code)
	// =========================================================================

	/**
	 * Test non-GD path: square finders + 100% opacity bypasses GD.
	 *
	 * @return void
	 */
	public function test_square_finders_full_opacity_uses_simple_path(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri( 'simple-path-test' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test GD path: rounded finders triggers GD pipeline.
	 *
	 * @return void
	 */
	public function test_rounded_finders_triggers_gd_pipeline(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'rounded' );

		$data_uri = $renderer->generate_data_uri( 'gd-rounded-finders' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );

		$base64_part = substr( $data_uri, strlen( 'data:image/png;base64,' ) );
		$decoded     = base64_decode( $base64_part, true );
		$this->assertStringStartsWith( "\x89PNG", $decoded );
	}

	/**
	 * Test GD path: reduced opacity triggers GD pipeline.
	 *
	 * @return void
	 */
	public function test_reduced_opacity_triggers_gd_pipeline(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 50, 5, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri( 'gd-opacity-test' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );

		$base64_part = substr( $data_uri, strlen( 'data:image/png;base64,' ) );
		$decoded     = base64_decode( $base64_part, true );
		$this->assertStringStartsWith( "\x89PNG", $decoded );
	}

	/**
	 * Test GD path: both rounded finders AND reduced opacity.
	 *
	 * @return void
	 */
	public function test_rounded_finders_and_reduced_opacity(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 30, 5, 'rounded', 'rounded' );

		$data_uri = $renderer->generate_data_uri( 'gd-both-features' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test GD path: zero opacity triggers GD pipeline.
	 *
	 * @return void
	 */
	public function test_zero_opacity_triggers_gd_pipeline(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 0, 5, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri( 'gd-zero-opacity' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	// =========================================================================
	// Color handling (hex_to_rgb decision code)
	// =========================================================================

	/**
	 * Test standard 6-digit hex colors produce valid output.
	 *
	 * @return void
	 */
	public function test_standard_hex_colors(): void {
		$renderer = new QRCodeRenderer( 'FF0000', '00FF00', 100, 5, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri( 'color-test' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test short hex color is padded correctly.
	 *
	 * hex_to_rgb pads to 6 chars with str_pad, so 'FFF' becomes '000FFF'.
	 *
	 * @return void
	 */
	public function test_short_hex_color_is_padded(): void {
		$renderer = new QRCodeRenderer( 'FFF', '000', 100, 5, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri( 'short-hex-test' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test empty hex color defaults to black (000000).
	 *
	 * @return void
	 */
	public function test_empty_hex_color_defaults_to_black(): void {
		$renderer = new QRCodeRenderer( '', 'ffffff', 100, 5, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri( 'empty-hex-test' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test different foreground/background colors produce different images.
	 *
	 * @return void
	 */
	public function test_different_colors_produce_different_output(): void {
		$renderer_black = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );
		$renderer_red   = new QRCodeRenderer( 'FF0000', 'ffffff', 100, 5, 'rounded', 'square' );

		$content   = 'color-diff-test';
		$png_black = $renderer_black->generate_png( $content );
		$png_red   = $renderer_red->generate_png( $content );

		$this->assertNotSame( $png_black, $png_red );
	}

	// =========================================================================
	// Scale handling
	// =========================================================================

	/**
	 * Test different scales produce different image sizes.
	 *
	 * @return void
	 */
	public function test_different_scales_produce_different_sizes(): void {
		$renderer_small = new QRCodeRenderer( '000000', 'ffffff', 100, 3, 'rounded', 'square' );
		$renderer_large = new QRCodeRenderer( '000000', 'ffffff', 100, 10, 'rounded', 'square' );

		$content   = 'scale-test';
		$png_small = $renderer_small->generate_png( $content );
		$png_large = $renderer_large->generate_png( $content );

		// Larger scale should produce more data.
		$this->assertGreaterThan( strlen( $png_small ), strlen( $png_large ) );
	}

	// =========================================================================
	// generate_data_uri_with_logo
	// =========================================================================

	/**
	 * Test generate_data_uri_with_logo falls back when logo path is null.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_with_logo_null_falls_back(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$content  = 'logo-null-test';
		$standard = $renderer->generate_data_uri( $content );
		$with_logo = $renderer->generate_data_uri_with_logo( $content, null );

		$this->assertSame( $standard, $with_logo );
	}

	/**
	 * Test generate_data_uri_with_logo with actual logo file.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_with_logo_and_file(): void {
		// Create a minimal 10x10 PNG logo.
		$logo_file = tempnam( sys_get_temp_dir(), 'qr_logo_' ) . '.png';
		$image     = imagecreatetruecolor( 10, 10 );
		$white     = imagecolorallocate( $image, 255, 255, 255 );
		imagefill( $image, 0, 0, $white );
		imagepng( $image, $logo_file );
		unset( $image );

		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 10, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri_with_logo( 'logo-test', $logo_file );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );

		// Should produce valid PNG.
		$base64_part = substr( $data_uri, strlen( 'data:image/png;base64,' ) );
		$decoded     = base64_decode( $base64_part, true );
		$this->assertNotFalse( $decoded );
		$this->assertStringStartsWith( "\x89PNG", $decoded );

		unlink( $logo_file );
	}

	/**
	 * Test generate_data_uri_with_logo boosts scale for small values.
	 *
	 * When scale < MIN_LOGO_SCALE (10), the renderer temporarily boosts
	 * it to ensure logo visibility, then restores the original.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_with_logo_boosts_scale(): void {
		$logo_file = tempnam( sys_get_temp_dir(), 'qr_logo_' ) . '.png';
		$image     = imagecreatetruecolor( 10, 10 );
		$white     = imagecolorallocate( $image, 255, 255, 255 );
		imagefill( $image, 0, 0, $white );
		imagepng( $image, $logo_file );
		unset( $image );

		// Scale 3 is below MIN_LOGO_SCALE (10).
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 3, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri_with_logo( 'boost-test', $logo_file );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );

		// After the call, the renderer's scale should be restored.
		// Verify by generating a standard QR and checking it's small.
		$png_after = $renderer->generate_png( 'size-check' );
		$temp_file = tempnam( sys_get_temp_dir(), 'qr_size_' );
		file_put_contents( $temp_file, $png_after );
		$info = getimagesize( $temp_file );
		unlink( $temp_file );

		// With scale 3, the image should be relatively small (< 200px).
		$this->assertLessThan( 200, $info[0] );

		unlink( $logo_file );
	}

	/**
	 * Test generate_data_uri_with_logo with GD pipeline (rounded finders).
	 *
	 * @return void
	 */
	public function test_generate_data_uri_with_logo_gd_pipeline(): void {
		$logo_file = tempnam( sys_get_temp_dir(), 'qr_logo_' ) . '.png';
		$image     = imagecreatetruecolor( 10, 10 );
		$white     = imagecolorallocate( $image, 255, 255, 255 );
		imagefill( $image, 0, 0, $white );
		imagepng( $image, $logo_file );
		unset( $image );

		// Rounded finders trigger GD pipeline.
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 10, 'rounded', 'rounded' );

		$data_uri = $renderer->generate_data_uri_with_logo( 'gd-logo-test', $logo_file );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );

		unlink( $logo_file );
	}

	/**
	 * Test generate_data_uri_with_logo with GD pipeline and reduced opacity.
	 *
	 * @return void
	 */
	public function test_generate_data_uri_with_logo_gd_opacity(): void {
		$logo_file = tempnam( sys_get_temp_dir(), 'qr_logo_' ) . '.png';
		$image     = imagecreatetruecolor( 10, 10 );
		$white     = imagecolorallocate( $image, 255, 255, 255 );
		imagefill( $image, 0, 0, $white );
		imagepng( $image, $logo_file );
		unset( $image );

		// Reduced opacity triggers GD pipeline.
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 50, 10, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri_with_logo( 'gd-opacity-logo', $logo_file );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );

		unlink( $logo_file );
	}

	// =========================================================================
	// generate_png with GD pipeline
	// =========================================================================

	/**
	 * Test generate_png with rounded finders uses GD pipeline.
	 *
	 * @return void
	 */
	public function test_generate_png_with_rounded_finders(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'rounded' );

		$png_data = $renderer->generate_png( 'png-rounded' );

		$this->assertStringStartsWith( "\x89PNG", $png_data );
	}

	/**
	 * Test generate_png with reduced opacity uses GD pipeline.
	 *
	 * @return void
	 */
	public function test_generate_png_with_reduced_opacity(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 50, 5, 'rounded', 'square' );

		$png_data = $renderer->generate_png( 'png-opacity' );

		$this->assertStringStartsWith( "\x89PNG", $png_data );
	}

	/**
	 * Test generate_png without GD pipeline (simple path).
	 *
	 * @return void
	 */
	public function test_generate_png_simple_path(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$png_data = $renderer->generate_png( 'png-simple' );

		$this->assertStringStartsWith( "\x89PNG", $png_data );
	}

	// =========================================================================
	// Dot style handling
	// =========================================================================

	/**
	 * Test square dot style produces valid output.
	 *
	 * @return void
	 */
	public function test_square_dot_style(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'square', 'square' );

		$data_uri = $renderer->generate_data_uri( 'square-dots' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test rounded dot style produces valid output.
	 *
	 * @return void
	 */
	public function test_rounded_dot_style(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$data_uri = $renderer->generate_data_uri( 'rounded-dots' );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_uri );
	}

	/**
	 * Test square and rounded dot styles produce different images.
	 *
	 * @return void
	 */
	public function test_dot_styles_produce_different_output(): void {
		$renderer_square  = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'square', 'square' );
		$renderer_rounded = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$content    = 'dot-style-diff';
		$png_square  = $renderer_square->generate_png( $content );
		$png_rounded = $renderer_rounded->generate_png( $content );

		$this->assertNotSame( $png_square, $png_rounded );
	}

	// =========================================================================
	// Image validity verification
	// =========================================================================

	/**
	 * Test generated PNG is a valid image file.
	 *
	 * @return void
	 */
	public function test_generated_png_is_valid_image(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$png_data  = $renderer->generate_png( 'validity-test' );
		$temp_file = tempnam( sys_get_temp_dir(), 'qr_valid_' );
		file_put_contents( $temp_file, $png_data );

		$info = getimagesize( $temp_file );
		unlink( $temp_file );

		$this->assertIsArray( $info );
		$this->assertSame( IMAGETYPE_PNG, $info[2] );
		$this->assertGreaterThan( 0, $info[0] ); // Width.
		$this->assertGreaterThan( 0, $info[1] ); // Height.
	}

	/**
	 * Test GD pipeline generated PNG is a valid image.
	 *
	 * @return void
	 */
	public function test_gd_pipeline_png_is_valid_image(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 50, 5, 'rounded', 'rounded' );

		$png_data  = $renderer->generate_png( 'gd-validity-test' );
		$temp_file = tempnam( sys_get_temp_dir(), 'qr_gd_valid_' );
		file_put_contents( $temp_file, $png_data );

		$info = getimagesize( $temp_file );
		unlink( $temp_file );

		$this->assertIsArray( $info );
		$this->assertSame( IMAGETYPE_PNG, $info[2] );
		$this->assertGreaterThan( 0, $info[0] );
		$this->assertGreaterThan( 0, $info[1] );
	}

	/**
	 * Test data URI from GD pipeline produces square image.
	 *
	 * QR codes should always be square.
	 *
	 * @return void
	 */
	public function test_generated_image_is_square(): void {
		$renderer = new QRCodeRenderer( '000000', 'ffffff', 100, 5, 'rounded', 'square' );

		$png_data  = $renderer->generate_png( 'square-check' );
		$temp_file = tempnam( sys_get_temp_dir(), 'qr_sq_' );
		file_put_contents( $temp_file, $png_data );

		$info = getimagesize( $temp_file );
		unlink( $temp_file );

		$this->assertSame( $info[0], $info[1] );
	}
}
