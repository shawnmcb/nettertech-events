<?php
/**
 * QR Code Renderer.
 *
 * Handles QR code image generation including styling, GD image processing,
 * and output format conversion. Extracted from QRCodeService to enforce SRP.
 *
 * @package NetterTechEvents\Services
 * @since   1.5.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QRGdImagePNG;

/**
 * QR code rendering engine.
 *
 * Generates QR code images with configurable styling, GD post-processing
 * (rounded corners, background opacity), and logo embedding support.
 *
 * @since 1.5.0
 */
class QRCodeRenderer {

	/**
	 * Maximum logo space size in modules.
	 *
	 * @var int
	 */
	private const MAX_LOGO_SPACE_WIDTH = 13;

	/**
	 * Logo space percentage of matrix size.
	 *
	 * With ECC Level H (30% correction), we can safely use ~25% for logo.
	 *
	 * @var float
	 */
	private const LOGO_SPACE_RATIO = 0.25;

	/**
	 * Minimum logo space width in modules.
	 *
	 * Must be at least 7 to ensure logo is visible after scaling.
	 *
	 * @var int
	 */
	private const MIN_LOGO_SPACE_WIDTH = 7;

	/**
	 * Minimum scale when generating QR codes with logos.
	 *
	 * @var int
	 */
	private const MIN_LOGO_SCALE = 10;

	/**
	 * QR code foreground color (hex without #).
	 *
	 * @var string
	 */
	private string $fg_color;

	/**
	 * QR code background color (hex without #).
	 *
	 * @var string
	 */
	private string $bg_color;

	/**
	 * QR code background opacity (0-100%).
	 *
	 * @var int
	 */
	private int $bg_opacity;

	/**
	 * QR code scale (pixels per module).
	 *
	 * @var int
	 */
	private int $scale;

	/**
	 * QR code dot style: 'rounded' or 'square'.
	 *
	 * @var string
	 */
	private string $dot_style;

	/**
	 * QR code finder pattern style: 'square' or 'rounded'.
	 *
	 * @var string
	 */
	private string $finder_style;

	/**
	 * Constructor.
	 *
	 * @param string $fg_color     Foreground color (hex without #).
	 * @param string $bg_color     Background color (hex without #).
	 * @param int    $bg_opacity   Background opacity (0-100).
	 * @param int    $scale        Scale factor (pixels per module).
	 * @param string $dot_style    Dot style: 'rounded' or 'square'.
	 * @param string $finder_style Finder style: 'square' or 'rounded'.
	 */
	public function __construct(
		string $fg_color,
		string $bg_color,
		int $bg_opacity,
		int $scale,
		string $dot_style,
		string $finder_style
	) {
		$this->fg_color     = $fg_color;
		$this->bg_color     = $bg_color;
		$this->bg_opacity   = $bg_opacity;
		$this->scale        = $scale;
		$this->dot_style    = $dot_style;
		$this->finder_style = $finder_style;
	}

	/**
	 * Generate a QR code as base64 data URI.
	 *
	 * @param string $content Content to encode.
	 * @return string Base64 data URI.
	 */
	public function generate_data_uri( string $content ): string {
		if ( $this->needs_gd_pipeline() ) {
			$options = $this->get_qr_options( false, false );
			$qrcode  = new QRCode( $options );
			$qrcode->addByteSegment( $content );
			$matrix = $qrcode->getQRMatrix();
			$output = new QRImageWithLogo( $options, $matrix );

			$gd_image = $output->dump_gd_image();

			if ( 'rounded' === $this->finder_style ) {
				$gd_image = $this->round_finder_corners( $gd_image, $matrix );
			}

			if ( $this->bg_opacity < 100 ) {
				$gd_image = $this->apply_background_opacity( $gd_image );
			}

			return $this->gd_image_to_data_uri( $gd_image );
		}

		$qrcode = new QRCode( $this->get_qr_options( true ) );
		return $qrcode->render( $content );
	}

	/**
	 * Generate a QR code as base64 data URI with logo embedding.
	 *
	 * The logo space is dynamically calculated based on the QR matrix size
	 * to ensure it doesn't exceed the error correction capacity.
	 *
	 * @param string      $content   Content to encode.
	 * @param string|null $logo_path Path to logo file, or null for no logo.
	 * @return string Base64 data URI.
	 */
	public function generate_data_uri_with_logo( string $content, ?string $logo_path ): string {
		if ( null === $logo_path ) {
			return $this->generate_data_uri( $content );
		}

		// Temporarily boost scale for logo visibility.
		$original_scale = $this->scale;
		if ( $this->scale < self::MIN_LOGO_SCALE ) {
			$this->scale = self::MIN_LOGO_SCALE;
		}

		try {
			// Generate matrix without logo to determine its size.
			$base_options = $this->get_qr_options( true, false );
			$base_qrcode  = new QRCode( $base_options );
			$base_qrcode->addByteSegment( $content );
			$base_matrix = $base_qrcode->getQRMatrix();
			$matrix_size = $base_matrix->getSize();

			// Calculate dynamic logo space based on matrix size.
			$calculated_space = (int) floor( $matrix_size * self::LOGO_SPACE_RATIO );
			$logo_space_size  = max( self::MIN_LOGO_SPACE_WIDTH, min( $calculated_space, self::MAX_LOGO_SPACE_WIDTH ) );

			// Ensure logo space is odd for proper centering.
			if ( 0 === $logo_space_size % 2 ) {
				--$logo_space_size;
			}

			// Generate with calculated logo space reserved.
			$as_data_uri = ! $this->needs_gd_pipeline();
			$options     = $this->get_qr_options( $as_data_uri, true, $logo_space_size );
			$qrcode      = new QRCode( $options );
			$qrcode->addByteSegment( $content );
			$matrix = $qrcode->getQRMatrix();

			$output = new QRImageWithLogo( $options, $matrix );

			if ( $this->needs_gd_pipeline() ) {
				$gd_image = $output->dump_gd_image( null, $logo_path );

				if ( 'rounded' === $this->finder_style ) {
					$gd_image = $this->round_finder_corners( $gd_image, $matrix, $logo_space_size );
				}

				if ( $this->bg_opacity < 100 ) {
					$gd_image = $this->apply_background_opacity( $gd_image );
				}

				return $this->gd_image_to_data_uri( $gd_image );
			}

			return $output->dump( null, $logo_path );
		} finally {
			$this->scale = $original_scale;
		}
	}

	/**
	 * Generate a QR code as raw PNG binary data.
	 *
	 * @param string $content Content to encode in the QR code.
	 * @return string Raw PNG binary data.
	 */
	public function generate_png( string $content ): string {
		if ( $this->needs_gd_pipeline() ) {
			$options = $this->get_qr_options( false, false );
			$qrcode  = new QRCode( $options );
			$qrcode->addByteSegment( $content );
			$matrix = $qrcode->getQRMatrix();
			$output = new QRImageWithLogo( $options, $matrix );

			$gd_image = $output->dump_gd_image();

			if ( 'rounded' === $this->finder_style ) {
				$gd_image = $this->round_finder_corners( $gd_image, $matrix );
			}

			if ( $this->bg_opacity < 100 ) {
				$gd_image = $this->apply_background_opacity( $gd_image );
			}

			return $this->gd_image_to_png( $gd_image );
		}

		$qrcode = new QRCode( $this->get_qr_options( false ) );
		return $qrcode->render( $content );
	}

	/**
	 * Get QR code options configured for the renderer.
	 *
	 * Uses ECC Level H (30% error correction) for maximum reliability
	 * and to support logo embedding. Finder and alignment patterns are
	 * always kept as solid squares; corner rounding is applied via GD
	 * post-processing when the finder style is set to 'rounded'.
	 *
	 * @param bool     $as_data_uri     Whether to output as base64 data URI.
	 * @param bool     $with_logo       Whether to reserve space for a logo.
	 * @param int|null $logo_space_size Optional logo space size in modules.
	 * @return QROptions Configured options.
	 */
	private function get_qr_options( bool $as_data_uri = false, bool $with_logo = false, ?int $logo_space_size = null ): QROptions {
		$fg_rgb = $this->hex_to_rgb( $this->fg_color );
		$bg_rgb = $this->hex_to_rgb( $this->bg_color );

		$use_circular = 'rounded' === $this->dot_style;

		$options = array(
			'outputInterface'     => QRGdImagePNG::class,
			'scale'               => $this->scale,
			'outputBase64'        => $as_data_uri,
			'imageTransparent'    => false,
			'bgColor'             => $bg_rgb,
			'eccLevel'            => EccLevel::H,
			'drawCircularModules' => $use_circular,
			'circleRadius'        => 0.4,
			'connectPaths'        => true,
			'drawLightModules'    => true,
			'keepAsSquare'        => array(
				QRMatrix::M_FINDER_DARK,
				QRMatrix::M_FINDER_DOT,
				QRMatrix::M_FINDER,
				QRMatrix::M_ALIGNMENT_DARK,
				QRMatrix::M_ALIGNMENT,
			),
			'moduleValues'        => array(
				QRMatrix::M_DATA_DARK      => $fg_rgb,
				QRMatrix::M_FINDER_DARK    => $fg_rgb,
				QRMatrix::M_FINDER_DOT     => $fg_rgb,
				QRMatrix::M_ALIGNMENT_DARK => $fg_rgb,
				QRMatrix::M_TIMING_DARK    => $fg_rgb,
				QRMatrix::M_FORMAT_DARK    => $fg_rgb,
				QRMatrix::M_VERSION_DARK   => $fg_rgb,
				QRMatrix::M_DARKMODULE     => $fg_rgb,
				QRMatrix::M_DATA           => $bg_rgb,
				QRMatrix::M_FINDER         => $bg_rgb,
				QRMatrix::M_ALIGNMENT      => $bg_rgb,
				QRMatrix::M_TIMING         => $bg_rgb,
				QRMatrix::M_FORMAT         => $bg_rgb,
				QRMatrix::M_VERSION        => $bg_rgb,
				QRMatrix::M_SEPARATOR      => $bg_rgb,
				QRMatrix::M_QUIETZONE      => $bg_rgb,
				QRMatrix::M_LOGO           => $bg_rgb,
				QRMatrix::M_LOGO_DARK      => $fg_rgb,
			),
		);

		if ( $with_logo ) {
			$space_size                 = $logo_space_size ?? self::MAX_LOGO_SPACE_WIDTH;
			$options['addLogoSpace']    = true;
			$options['logoSpaceWidth']  = $space_size;
			$options['logoSpaceHeight'] = $space_size;
		}

		return new QROptions( $options );
	}

	/**
	 * Convert hex color to RGB array.
	 *
	 * @param string $hex Hex color without #.
	 * @return array{0: int, 1: int, 2: int} RGB array [r, g, b].
	 */
	private function hex_to_rgb( string $hex ): array {
		$hex = str_pad( $hex, 6, '0', STR_PAD_LEFT );
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Apply background opacity to a GD image via alpha compositing.
	 *
	 * @param \GdImage $image The source QR code GD image.
	 * @return \GdImage The processed image with alpha background.
	 */
	private function apply_background_opacity( \GdImage $image ): \GdImage {
		$width  = imagesx( $image );
		$height = imagesy( $image );
		$bg_rgb = $this->hex_to_rgb( $this->bg_color );

		$bg_index = imagecolorexact( $image, $bg_rgb[0], $bg_rgb[1], $bg_rgb[2] );
		if ( -1 !== $bg_index ) {
			imagecolortransparent( $image, $bg_index );
		}

		$new_image = imagecreatetruecolor( $width, $height );
		imagealphablending( $new_image, false );
		imagesavealpha( $new_image, true );

		$gd_alpha = (int) round( 127 * ( 1 - $this->bg_opacity / 100 ) );
		$bg_alpha = imagecolorallocatealpha( $new_image, $bg_rgb[0], $bg_rgb[1], $bg_rgb[2], $gd_alpha );
		imagefill( $new_image, 0, 0, $bg_alpha );

		imagealphablending( $new_image, true );
		imagecopy( $new_image, $image, 0, 0, 0, 0, $width, $height );

		imagealphablending( $new_image, false );

		unset( $image );

		return $new_image;
	}

	/**
	 * Check whether GD image post-processing pipeline is required.
	 *
	 * @return bool True if GD image manipulation is required.
	 */
	private function needs_gd_pipeline(): bool {
		return $this->bg_opacity < 100 || 'rounded' === $this->finder_style;
	}

	/**
	 * Round the corners of finder and alignment patterns in a QR code image.
	 *
	 * @param \GdImage $image           The QR code GD image.
	 * @param QRMatrix $matrix          The QR matrix.
	 * @param int      $logo_space_size Logo space width/height in modules (0 = no logo).
	 * @return \GdImage The modified image with rounded pattern corners.
	 */
	private function round_finder_corners( \GdImage $image, QRMatrix $matrix, int $logo_space_size = 0 ): \GdImage {
		$version = $matrix->getVersion();
		if ( null === $version ) {
			return $image;
		}

		$total_size = $matrix->getSize();
		$core_size  = $version->getDimension();
		$qz_offset  = (int) ( ( $total_size - $core_size ) / 2 );
		$scale      = $this->scale;

		$fg_rgb = $this->hex_to_rgb( $this->fg_color );
		$bg_rgb = $this->hex_to_rgb( $this->bg_color );
		$fg     = imagecolorallocate( $image, $fg_rgb[0], $fg_rgb[1], $fg_rgb[2] );
		$bg     = imagecolorallocate( $image, $bg_rgb[0], $bg_rgb[1], $bg_rgb[2] );

		$outer_radius = max( 1, (int) round( $scale * 1.3 ) );
		$mid_radius   = max( 1, (int) round( $scale * 1.0 ) );
		$inner_radius = max( 1, (int) round( $scale * 0.7 ) );

		$finders = array(
			array( $qz_offset, $qz_offset ),
			array( $qz_offset + $core_size - 7, $qz_offset ),
			array( $qz_offset, $qz_offset + $core_size - 7 ),
		);

		foreach ( $finders as list( $fx, $fy ) ) {
			$ox1 = $fx * $scale;
			$oy1 = $fy * $scale;
			$ox2 = ( $fx + 7 ) * $scale;
			$oy2 = ( $fy + 7 ) * $scale;

			$mx1 = ( $fx + 1 ) * $scale;
			$my1 = ( $fy + 1 ) * $scale;
			$mx2 = ( $fx + 6 ) * $scale;
			$my2 = ( $fy + 6 ) * $scale;

			$cx1 = ( $fx + 2 ) * $scale;
			$cy1 = ( $fy + 2 ) * $scale;
			$cx2 = ( $fx + 5 ) * $scale;
			$cy2 = ( $fy + 5 ) * $scale;

			imagefilledrectangle( $image, $ox1, $oy1, $ox2, $oy2, $bg );

			$this->draw_filled_rounded_rect( $image, $ox1, $oy1, $ox2, $oy2, $outer_radius, $fg );
			$this->draw_filled_rounded_rect( $image, $mx1, $my1, $mx2, $my2, $mid_radius, $bg );
			$this->draw_filled_rounded_rect( $image, $cx1, $cy1, $cx2, $cy2, $inner_radius, $fg );
		}

		$ap_coords = $version->getAlignmentPattern();

		if ( ! empty( $ap_coords ) ) {
			$ap_outer_radius = max( 1, (int) round( $scale * 1.0 ) );
			$ap_mid_radius   = max( 1, (int) round( $scale * 0.7 ) );
			$ap_inner_radius = max( 1, (int) round( $scale * 0.4 ) );

			$logo_start = 0;
			$logo_end   = 0;
			if ( $logo_space_size > 0 ) {
				$logo_start = (int) floor( ( $core_size - $logo_space_size ) / 2 );
				$logo_end   = $logo_start + $logo_space_size - 1;
			}

			foreach ( $ap_coords as $ay ) {
				foreach ( $ap_coords as $ax ) {
					if ( ( $ax <= 6 && $ay <= 6 ) ||
						( $ax >= $core_size - 7 && $ay <= 6 ) ||
						( $ax <= 6 && $ay >= $core_size - 7 ) ) {
						continue;
					}

					if ( $logo_space_size > 0 &&
						$ax + 2 >= $logo_start && $ax - 2 <= $logo_end &&
						$ay + 2 >= $logo_start && $ay - 2 <= $logo_end ) {
						continue;
					}

					$px = $ax + $qz_offset;
					$py = $ay + $qz_offset;

					$ox1 = ( $px - 2 ) * $scale;
					$oy1 = ( $py - 2 ) * $scale;
					$ox2 = ( $px + 3 ) * $scale;
					$oy2 = ( $py + 3 ) * $scale;

					$amx1 = ( $px - 1 ) * $scale;
					$amy1 = ( $py - 1 ) * $scale;
					$amx2 = ( $px + 2 ) * $scale;
					$amy2 = ( $py + 2 ) * $scale;

					$acx1 = $px * $scale;
					$acy1 = $py * $scale;
					$acx2 = ( $px + 1 ) * $scale;
					$acy2 = ( $py + 1 ) * $scale;

					imagefilledrectangle( $image, $ox1, $oy1, $ox2, $oy2, $bg );
					$this->draw_filled_rounded_rect( $image, $ox1, $oy1, $ox2, $oy2, $ap_outer_radius, $fg );
					$this->draw_filled_rounded_rect( $image, $amx1, $amy1, $amx2, $amy2, $ap_mid_radius, $bg );
					$this->draw_filled_rounded_rect( $image, $acx1, $acy1, $acx2, $acy2, $ap_inner_radius, $fg );
				}
			}
		}

		return $image;
	}

	/**
	 * Draw a filled rectangle with rounded corners on a GD image.
	 *
	 * @param \GdImage $image  The GD image to draw on.
	 * @param int      $x1     Left edge X coordinate.
	 * @param int      $y1     Top edge Y coordinate.
	 * @param int      $x2     Right edge X coordinate.
	 * @param int      $y2     Bottom edge Y coordinate.
	 * @param int      $radius Corner radius in pixels.
	 * @param int      $color  GD color identifier.
	 */
	private function draw_filled_rounded_rect( \GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color ): void {
		$width  = $x2 - $x1;
		$height = $y2 - $y1;
		$radius = min( $radius, (int) ( $width / 2 ), (int) ( $height / 2 ) );

		if ( $radius <= 0 ) {
			imagefilledrectangle( $image, $x1, $y1, $x2, $y2, $color );
			return;
		}

		$diameter = $radius * 2;

		imagefilledrectangle( $image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color );
		imagefilledrectangle( $image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color );

		imagefilledellipse( $image, $x1 + $radius, $y1 + $radius, $diameter, $diameter, $color );
		imagefilledellipse( $image, $x2 - $radius, $y1 + $radius, $diameter, $diameter, $color );
		imagefilledellipse( $image, $x1 + $radius, $y2 - $radius, $diameter, $diameter, $color );
		imagefilledellipse( $image, $x2 - $radius, $y2 - $radius, $diameter, $diameter, $color );
	}

	/**
	 * Convert a GD image to a base64 PNG data URI.
	 *
	 * @param \GdImage $image The GD image resource.
	 * @return string Base64 data URI string.
	 */
	private function gd_image_to_data_uri( \GdImage $image ): string {
		ob_start();
		imagesavealpha( $image, true );
		imagepng( $image );
		$png_data = (string) ob_get_clean();
		unset( $image );

		return 'data:image/png;base64,' . base64_encode( $png_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI encoding for inline PNG; not obfuscation.
	}

	/**
	 * Convert a GD image to raw PNG binary data.
	 *
	 * @param \GdImage $image The GD image resource.
	 * @return string Raw PNG binary data.
	 */
	private function gd_image_to_png( \GdImage $image ): string {
		ob_start();
		imagesavealpha( $image, true );
		imagepng( $image );
		$png_data = ob_get_clean();
		unset( $image );

		return (string) $png_data;
	}
}
