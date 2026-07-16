<?php
/**
 * QR Image With Logo Output Class.
 *
 * Extends the standard GD PNG output to embed a logo in the center
 * of the QR code. Requires ECC Level H for optimal scannability.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

// Guard: Only define this class if the parent class exists.
// This prevents fatal errors when PHP autoloads this file before
// the Composer autoloader has loaded the vendor classes.
if ( ! class_exists( \chillerlan\QRCode\Output\QRGdImagePNG::class ) ) {
	return;
}

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRCodeOutputException;

/**
 * QR code output class that embeds a logo in the center.
 *
 * This class extends QRGdImagePNG to add logo embedding capability.
 * The logo is scaled to fit within the reserved logo space while
 * preserving aspect ratio, then centered in the QR code.
 *
 * Usage:
 * ```php
 * $options = new QROptions([
 *     'eccLevel'        => EccLevel::H,
 *     'addLogoSpace'    => true,
 *     'logoSpaceWidth'  => 13,
 *     'logoSpaceHeight' => 13,
 * ]);
 * $qrcode = new QRCode($options);
 * $matrix = $qrcode->getQRMatrix();
 * $output = new QRImageWithLogo($options, $matrix);
 * $image  = $output->dump(null, '/path/to/logo.png');
 * ```
 *
 * @since 0.9.0
 */
class QRImageWithLogo extends QRGdImagePNG {

	/**
	 * Generate the QR code image with an optional embedded logo.
	 *
	 * @param string|null $file Optional file path to save the image.
	 * @param string|null $logo Optional path to the logo image file.
	 * @return string The image data (raw or base64 depending on options).
	 * @throws QRCodeOutputException If logo file is invalid or unreadable.
	 */
	public function dump( string|null $file = null, string|null $logo = null ): string {
		// If no logo provided, use standard output.
		if ( null === $logo || '' === $logo ) {
			$output = parent::dump( $file );
			// chillerlan's dump() returns GdImage only when returnResource is set; this class never sets it.
			return is_string( $output ) ? $output : '';
		}

		// Validate logo file exists and is readable.
		if ( ! is_file( $logo ) || ! is_readable( $logo ) ) {
			throw new QRCodeOutputException(
				sprintf(
					/* translators: %s: logo file path */
					esc_html__( 'Logo file not found or not readable: %s', 'nettertech-events' ),
					esc_html( $logo )
				)
			);
		}

		// Set returnResource to get GD resource for manipulation.
		$this->options->returnResource = true; // @phpstan-ignore property.notFound (chillerlan QROptions uses __get/__set magic for dynamic properties; PHPStan cannot see them statically)

		// Generate the base QR code image.
		parent::dump( $file );

		// Load and embed the logo.
		$this->embed_logo( $logo );

		// Generate final image data.
		$image_data = $this->dumpImage();

		// Save to file if path provided.
		if ( null !== $file ) {
			$this->saveToFile( $image_data, $file );
		}

		// Return as base64 data URI if configured.
		if ( $this->options->outputBase64 ) { // @phpstan-ignore property.notFound (chillerlan QROptions uses __get/__set magic for dynamic properties; PHPStan cannot see them statically)
			return $this->toBase64DataURI( $image_data );
		}

		return $image_data;
	}

	/**
	 * Load a logo image from file.
	 *
	 * Supports PNG, JPEG, GIF, and WebP formats.
	 *
	 * @param string $logo_path Path to the logo file.
	 * @return \GdImage|false The loaded image resource or false on failure.
	 */
	private function load_logo_image( string $logo_path ): \GdImage|false {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- getimagesize may warn on invalid images, we handle failure gracefully.
		$image_info = @getimagesize( $logo_path );

		if ( false === $image_info ) {
			return false;
		}

		$mime_type = $image_info['mime'] ?? ''; // @phpstan-ignore nullCoalesce.offset (getimagesize returns either array with 'mime' key or false; false path is handled above)

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- GD functions may warn on corrupt images, we handle failure gracefully.
		return match ( $mime_type ) {
			'image/png'  => @imagecreatefrompng( $logo_path ),
			'image/jpeg' => @imagecreatefromjpeg( $logo_path ),
			'image/gif'  => @imagecreatefromgif( $logo_path ),
			'image/webp' => @imagecreatefromwebp( $logo_path ),
			default      => false,
		};
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Embed the logo into the QR code image.
	 *
	 * The logo is scaled to fit within the reserved logo space while
	 * preserving its aspect ratio, then centered in the QR code.
	 *
	 * @param string $logo_path Path to the logo file.
	 * @return void
	 * @throws QRCodeOutputException If the logo cannot be loaded.
	 */
	private function embed_logo( string $logo_path ): void {
		$logo_image = $this->load_logo_image( $logo_path );

		if ( false === $logo_image ) {
			throw new QRCodeOutputException(
				sprintf(
					/* translators: %s: logo file path */
					esc_html__( 'Failed to load logo image: %s', 'nettertech-events' ),
					esc_html( $logo_path )
				)
			);
		}

		// Get logo dimensions.
		$logo_width  = imagesx( $logo_image );
		$logo_height = imagesy( $logo_image );

		if ( $logo_width <= 0 || $logo_height <= 0 ) { // @phpstan-ignore booleanOr.alwaysFalse, smallerOrEqual.alwaysFalse (imagesx/imagesy return int per GD stub but runtime can return 0 on corrupt images; defensive guard)
			unset( $logo_image ); // GdImage cleanup in PHP 8+.
			throw new QRCodeOutputException( esc_html__( 'Logo image has invalid dimensions', 'nettertech-events' ) );
		}

		// Calculate the available space for the logo.
		// Leave 1 module border around the logo for better scannability.
		$space_width  = ( $this->options->logoSpaceWidth - 2 ) * $this->options->scale; // @phpstan-ignore property.notFound (chillerlan QROptions uses __get/__set magic for dynamic properties; PHPStan cannot see them statically)
		$space_height = ( $this->options->logoSpaceHeight - 2 ) * $this->options->scale; // @phpstan-ignore property.notFound (chillerlan QROptions uses __get/__set magic for dynamic properties; PHPStan cannot see them statically)

		// Calculate scaled dimensions preserving aspect ratio.
		list( $scaled_width, $scaled_height ) = $this->calculate_scaled_dimensions(
			$logo_width,
			$logo_height,
			$space_width,
			$space_height
		);

		// Get QR code dimensions.
		$qr_size = $this->matrix->getSize() * $this->options->scale; // @phpstan-ignore property.notFound (chillerlan QROptions uses __get/__set magic for dynamic properties; PHPStan cannot see them statically)

		// Calculate position to center the logo.
		$dest_x = (int) ( ( $qr_size - $scaled_width ) / 2 );
		$dest_y = (int) ( ( $qr_size - $scaled_height ) / 2 );

		// Enable alpha blending so transparent logo pixels show the QR background color.
		imagealphablending( $this->image, true );

		// Copy and scale logo onto QR code.
		imagecopyresampled(
			$this->image,
			$logo_image,
			$dest_x,
			$dest_y,
			0,
			0,
			$scaled_width,
			$scaled_height,
			$logo_width,
			$logo_height
		);

		// Clean up logo image resource (unset sufficient in PHP 8+).
		unset( $logo_image );
	}

	/**
	 * Generate the QR code image with logo and return the GD resource.
	 *
	 * Used by QRCodeService when opacity post-processing is needed.
	 * Returns the GdImage directly instead of encoding to string.
	 *
	 * @param string|null $file Optional file path (unused, kept for API consistency).
	 * @param string|null $logo Optional path to the logo image file.
	 * @return \GdImage The GD image resource with logo embedded.
	 * @throws QRCodeOutputException If logo file is invalid or unreadable.
	 */
	public function dump_gd_image( string|null $file = null, string|null $logo = null ): \GdImage {
		// Validate logo file exists and is readable.
		if ( null !== $logo && '' !== $logo ) {
			if ( ! is_file( $logo ) || ! is_readable( $logo ) ) {
				throw new QRCodeOutputException(
					sprintf(
						/* translators: %s: logo file path */
						esc_html__( 'Logo file not found or not readable: %s', 'nettertech-events' ),
						esc_html( $logo )
					)
				);
			}
		}

		// Set returnResource to get GD resource for manipulation.
		$this->options->returnResource = true; // @phpstan-ignore property.notFound (chillerlan QROptions uses __get/__set magic for dynamic properties; PHPStan cannot see them statically)

		// Generate the base QR code image.
		parent::dump( $file );

		// Embed logo if provided.
		if ( null !== $logo && '' !== $logo ) {
			$this->embed_logo( $logo );
		}

		return $this->image;
	}

	/**
	 * Calculate scaled dimensions while preserving aspect ratio.
	 *
	 * Scales the logo to fit within the maximum width and height
	 * while maintaining its original aspect ratio.
	 *
	 * @param int $original_width  Original logo width in pixels.
	 * @param int $original_height Original logo height in pixels.
	 * @param int $max_width       Maximum allowed width in pixels.
	 * @param int $max_height      Maximum allowed height in pixels.
	 * @return array{0: int, 1: int} Scaled [width, height].
	 */
	private function calculate_scaled_dimensions(
		int $original_width,
		int $original_height,
		int $max_width,
		int $max_height
	): array {
		// Prevent division by zero.
		if ( $original_width <= 0 || $original_height <= 0 ) {
			return array( 0, 0 );
		}

		$width_ratio  = $max_width / $original_width;
		$height_ratio = $max_height / $original_height;

		// Use the smaller ratio to ensure the logo fits in both dimensions.
		$scale_ratio = min( $width_ratio, $height_ratio );

		$scaled_width  = (int) round( $original_width * $scale_ratio );
		$scaled_height = (int) round( $original_height * $scale_ratio );

		return array( $scaled_width, $scaled_height );
	}
}
