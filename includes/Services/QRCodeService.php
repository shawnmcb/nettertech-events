<?php
/**
 * QR Code Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\QRCodeServiceInterface;
use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Models\Ticket;

/**
 * Handles QR code generation for tickets.
 *
 * Generates unique ticket codes and creates QR code images
 * locally using chillerlan/php-qrcode library.
 *
 * @since 0.8.0
 * @api
 */
class QRCodeService implements QRCodeServiceInterface {

	/**
	 * Length of generated ticket codes.
	 *
	 * @var int
	 */
	private const CODE_LENGTH = 16;

	/**
	 * QR code foreground color (hex without #).
	 *
	 * @var string
	 */
	private string $fg_color = '000000';

	/**
	 * QR code background color (hex without #).
	 *
	 * @var string
	 */
	private string $bg_color = 'ffffff';

	/**
	 * QR code background opacity (0-100%).
	 *
	 * @var int
	 */
	private int $bg_opacity = 100;

	/**
	 * QR code scale (pixels per module).
	 *
	 * @var int
	 */
	private int $scale = 5;

	/**
	 * QR code dot style: 'rounded' or 'square'.
	 *
	 * @var string
	 */
	private string $dot_style = 'rounded';

	/**
	 * QR code finder pattern style: 'square' or 'rounded'.
	 *
	 * Controls whether the three finder patterns (large corner squares)
	 * and alignment patterns remain square or follow the circular module style.
	 *
	 * @var string
	 */
	private string $finder_style = 'square';

	/**
	 * Logo file path for embedding in QR code.
	 *
	 * @var string|null
	 */
	private ?string $logo_path = null;

	/**
	 * Logo mode: none, default, site, or custom.
	 *
	 * - 'none': No logo embedded
	 * - 'default': Use plugin default settings (for per-event override)
	 * - 'site': Dynamically use site logo from theme customizer
	 * - 'custom': Use a specific custom logo
	 *
	 * @var string
	 */
	private string $logo_mode = 'none';

	/**
	 * Uploads subdirectory for QR codes.
	 *
	 * @var string
	 */
	private const UPLOAD_SUBDIR = 'nettertech-events/qr';

	/**
	 * Cached renderer instance (invalidated on style changes).
	 *
	 * @var QRCodeRenderer|null
	 */
	private ?QRCodeRenderer $renderer = null;

	/**
	 * Constructor.
	 *
	 * Loads QR code styling from plugin settings DTO.
	 *
	 * @param NetterTechEventsSettings $settings Settings DTO.
	 */
	public function __construct( NetterTechEventsSettings $settings ) {

		$qr = $settings->qr;

		if ( ! empty( $qr->qr_foreground_color ) ) {
			$this->fg_color = ltrim( $qr->qr_foreground_color, '#' );
		}

		if ( ! empty( $qr->qr_background_color ) ) {
			$this->bg_color = ltrim( $qr->qr_background_color, '#' );
		}

		if ( ! empty( $qr->qr_scale ) ) {
			$this->scale = $qr->qr_scale;
		}

		if ( ! empty( $qr->qr_dot_style ) ) {
			$this->dot_style = 'square' === $qr->qr_dot_style ? 'square' : 'rounded';
		}

		if ( ! empty( $qr->qr_finder_style ) ) {
			$this->finder_style = 'rounded' === $qr->qr_finder_style ? 'rounded' : 'square';
		}

		$this->bg_opacity = max( 0, min( 100, $qr->qr_bg_opacity ) );
	}

	/**
	 * Set the foreground color.
	 *
	 * @param string $color Hex color without #.
	 * @return self
	 */
	public function set_foreground_color( string $color ): self {
		$this->fg_color = preg_replace( '/[^a-fA-F0-9]/', '', $color ) ?? '';
		$this->renderer = null;
		return $this;
	}

	/**
	 * Set the background color.
	 *
	 * @param string $color Hex color without #.
	 * @return self
	 */
	public function set_background_color( string $color ): self {
		$this->bg_color = preg_replace( '/[^a-fA-F0-9]/', '', $color ) ?? '';
		$this->renderer = null;
		return $this;
	}

	/**
	 * Set the background opacity.
	 *
	 * @param int $opacity Opacity percentage (0-100).
	 * @return self
	 */
	public function set_background_opacity( int $opacity ): self {
		$this->bg_opacity = max( 0, min( 100, $opacity ) );
		$this->renderer   = null;
		return $this;
	}

	/**
	 * Set the QR code scale.
	 *
	 * @param int $scale Scale factor (pixels per module).
	 * @return self
	 */
	public function set_size( int $scale ): self {
		$this->scale    = max( 3, min( 20, $scale ) );
		$this->renderer = null;
		return $this;
	}

	/**
	 * Set the QR code dot style.
	 *
	 * @param string $style Dot style: 'rounded' or 'square'.
	 * @return self
	 */
	public function set_dot_style( string $style ): self {
		$this->dot_style = 'square' === $style ? 'square' : 'rounded';
		$this->renderer  = null;
		return $this;
	}

	/**
	 * Set the QR code finder pattern style.
	 *
	 * @param string $style Finder style: 'square' or 'rounded'.
	 * @return self
	 */
	public function set_finder_style( string $style ): self {
		$this->finder_style = 'rounded' === $style ? 'rounded' : 'square';
		$this->renderer     = null;
		return $this;
	}

	/**
	 * Set the logo file path.
	 *
	 * @param string|null $path Path to the logo file, or null to clear.
	 * @return self
	 */
	public function set_logo_path( ?string $path ): self {
		$this->logo_path = $path;
		return $this;
	}

	/**
	 * Set the logo mode.
	 *
	 * @param string $mode Logo mode: 'none', 'default', 'site', or 'custom'.
	 * @return self
	 */
	public function set_logo_mode( string $mode ): self {
		$valid_modes     = array( 'none', 'default', 'site', 'custom' );
		$this->logo_mode = in_array( $mode, $valid_modes, true ) ? $mode : 'none';
		return $this;
	}

	/**
	 * Get the current logo mode.
	 *
	 * @return string The logo mode.
	 */
	public function get_logo_mode(): string {
		return $this->logo_mode;
	}

	/**
	 * Resolve the site logo file path.
	 *
	 * Checks the theme customizer (custom_logo) first, then falls back
	 * to the site_logo option used by the Site Logo block (WordPress 5.9+).
	 * Does not fall back to site_icon (favicon). Returns null if no site logo is configured.
	 *
	 * @return string|null Path to the site logo file, or null if not set.
	 */
	public function resolve_site_logo(): ?string {
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( empty( $custom_logo_id ) ) {
			$custom_logo_id = get_option( 'site_logo' );
		}
		if ( empty( $custom_logo_id ) ) {
			$custom_logo_id = get_option( 'site_icon' );
		}

		if ( empty( $custom_logo_id ) ) {
			return null;
		}

		$logo_path = get_attached_file( $custom_logo_id );

		if ( empty( $logo_path ) || ! file_exists( $logo_path ) ) {
			return null;
		}

		return $logo_path;
	}

	/**
	 * Resolve the effective logo path based on current mode.
	 *
	 * @return string|null Resolved logo path, or null if no logo.
	 */
	public function resolve_logo_path(): ?string {
		return match ( $this->logo_mode ) {
			'site'   => $this->resolve_site_logo(),
			'custom' => $this->logo_path,
			default  => null,
		};
	}

	/**
	 * Check if a logo should be embedded.
	 *
	 * @return bool True if a logo should be embedded.
	 */
	public function has_logo(): bool {
		return null !== $this->resolve_logo_path();
	}

	/**
	 * Generate a unique ticket code.
	 *
	 * Uses cryptographically secure random bytes for unpredictability.
	 *
	 * @return string Unique ticket code.
	 */
	public function generate_ticket_code(): string {
		// Generate cryptographically secure random bytes.
		$bytes = random_bytes( self::CODE_LENGTH );

		// Convert to uppercase alphanumeric for readability.
		$code = strtoupper( bin2hex( $bytes ) );

		// Truncate to desired length and format as XXXX-XXXX-XXXX-XXXX.
		$code = substr( $code, 0, 16 );

		return sprintf(
			'%s-%s-%s-%s',
			substr( $code, 0, 4 ),
			substr( $code, 4, 4 ),
			substr( $code, 8, 4 ),
			substr( $code, 12, 4 )
		);
	}

	/**
	 * Get the QR code renderer, creating it if needed.
	 *
	 * The renderer is invalidated when style properties change,
	 * ensuring render calls always use the current configuration.
	 *
	 * @return QRCodeRenderer The renderer instance.
	 */
	private function get_renderer(): QRCodeRenderer {
		if ( null === $this->renderer ) {
			$this->renderer = new QRCodeRenderer(
				$this->fg_color,
				$this->bg_color,
				$this->bg_opacity,
				$this->scale,
				$this->dot_style,
				$this->finder_style
			);
		}

		return $this->renderer;
	}

	/**
	 * Get the uploads directory path for QR codes.
	 *
	 * Creates the directory if it doesn't exist.
	 *
	 * @return array{path: string, url: string}|false Directory info or false on failure.
	 */
	private function get_upload_dir(): array|false {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return false;
		}

		$qr_path = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;
		$qr_url  = trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR;

		// Create directory if it doesn't exist.
		if ( ! file_exists( $qr_path ) ) {
			wp_mkdir_p( $qr_path );

			// Add index.php for security.
			$index_file = trailingslashit( $qr_path ) . 'index.php';
			if ( ! file_exists( $index_file ) ) {
				file_put_contents( $index_file, '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem unavailable in service constructor context; target is a fresh file under wp-content/uploads.
			}
		}

		return array(
			'path' => $qr_path,
			'url'  => $qr_url,
		);
	}

	/**
	 * Generate a QR code file for a ticket.
	 *
	 * Creates a local PNG file and returns its URL.
	 *
	 * @param Ticket $ticket Ticket model.
	 * @return string QR code image URL.
	 */
	public function generate_for_ticket( Ticket $ticket ): string {
		$upload_dir = $this->get_upload_dir();

		if ( false === $upload_dir ) {
			// Fallback: return data URI if uploads unavailable.
			$check_in_url        = $this->get_check_in_url( $ticket->ticket_code );
			$ticket->qr_code_url = $this->generate_data_uri( $check_in_url );
			return $ticket->qr_code_url;
		}

		$check_in_url = $this->get_check_in_url( $ticket->ticket_code );

		// Use ticket code (without dashes) as filename for cleaner URLs.
		$filename  = str_replace( '-', '', $ticket->ticket_code ) . '.png';
		$file_path = trailingslashit( $upload_dir['path'] ) . $filename;
		$file_url  = trailingslashit( $upload_dir['url'] ) . $filename;

		// Generate if file doesn't exist or is outdated.
		if ( ! file_exists( $file_path ) ) {
			$png_data = $this->generate_png( $check_in_url );

			// Write to file.
			file_put_contents( $file_path, $png_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- QR code PNG write; WP_Filesystem is not initialized in service layer and the path is fully controlled by the plugin.
		}

		// Store the URL on the ticket.
		$ticket->qr_code_url = $file_url;

		return $file_url;
	}

	/**
	 * Get the check-in URL for a ticket code.
	 *
	 * Returns a friendly URL that works with both:
	 * - Volunteer cookie workflow (native camera scan → auto check-in)
	 * - Attendee view (shows "present at door" message)
	 *
	 * @param string $code Ticket code.
	 * @return string Check-in URL.
	 */
	public function get_check_in_url( string $code ): string {
		return home_url( 'ticket/' . $code . '/' );
	}

	/**
	 * Generate a QR code as base64 data URI.
	 *
	 * @param string $content Content to encode.
	 * @return string Base64 data URI.
	 */
	public function generate_data_uri( string $content ): string {
		return $this->get_renderer()->generate_data_uri( $content );
	}

	/**
	 * Generate a QR code as base64 data URI with optional logo embedding.
	 *
	 * Uses the current logo mode to determine whether to embed a logo.
	 *
	 * @param string $content Content to encode.
	 * @return string Base64 data URI.
	 */
	public function generate_data_uri_with_logo( string $content ): string {
		return $this->get_renderer()->generate_data_uri_with_logo( $content, $this->resolve_logo_path() );
	}

	/**
	 * Generate a QR code as raw PNG binary data.
	 *
	 * @param string $content Content to encode in the QR code.
	 * @return string Raw PNG binary data.
	 */
	public function generate_png( string $content ): string {
		return $this->get_renderer()->generate_png( $content );
	}

	/**
	 * Validate a ticket code format.
	 *
	 * @param string $code Ticket code to validate.
	 * @return bool True if valid format.
	 */
	public function validate_code_format( string $code ): bool {
		// Expected format: XXXX-XXXX-XXXX-XXXX (uppercase alphanumeric).
		return (bool) preg_match( '/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', $code );
	}

	/**
	 * Delete a QR code file for a ticket.
	 *
	 * @param string $ticket_code Ticket code.
	 * @return bool True if deleted or didn't exist.
	 */
	public function delete_qr_file( string $ticket_code ): bool {
		$upload_dir = $this->get_upload_dir();

		if ( false === $upload_dir ) {
			return true;
		}

		$filename  = str_replace( '-', '', $ticket_code ) . '.png';
		$file_path = trailingslashit( $upload_dir['path'] ) . $filename;

		if ( file_exists( $file_path ) ) {
			return wp_delete_file( $file_path );
		}

		return true;
	}
}
