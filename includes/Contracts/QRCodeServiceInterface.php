<?php
/**
 * QR Code Service Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Ticket;

/**
 * Interface for QRCodeService implementations.
 *
 * @since 0.9.0
 * @api
 */
interface QRCodeServiceInterface {

	/**
	 * Set the foreground color.
	 *
	 * @since 0.9.0
	 *
	 * @param string $color Hex color without #.
	 * @return self
	 */
	public function set_foreground_color( string $color ): self;

	/**
	 * Set the background color.
	 *
	 * @since 0.9.0
	 *
	 * @param string $color Hex color without #.
	 * @return self
	 */
	public function set_background_color( string $color ): self;

	/**
	 * Set the QR code scale.
	 *
	 * @since 0.9.0
	 *
	 * @param int $scale Scale factor (pixels per module).
	 * @return self
	 */
	public function set_size( int $scale ): self;

	/**
	 * Set the logo file path.
	 *
	 * @since 0.9.0
	 *
	 * @param string|null $path Path to the logo file, or null to clear.
	 * @return self
	 */
	public function set_logo_path( ?string $path ): self;

	/**
	 * Set the logo mode.
	 *
	 * @since 0.9.0
	 *
	 * @param string $mode Logo mode: 'none', 'default', 'site', or 'custom'.
	 * @return self
	 */
	public function set_logo_mode( string $mode ): self;

	/**
	 * Get the current logo mode.
	 *
	 * @since 0.9.0
	 *
	 * @return string The logo mode.
	 */
	public function get_logo_mode(): string;

	/**
	 * Resolve the site logo file path.
	 *
	 * @since 0.9.0
	 *
	 * @return string|null Path to the site logo file, or null if not set.
	 */
	public function resolve_site_logo(): ?string;

	/**
	 * Resolve the effective logo path based on current mode.
	 *
	 * @since 0.9.0
	 *
	 * @return string|null Resolved logo path, or null if no logo.
	 */
	public function resolve_logo_path(): ?string;

	/**
	 * Check if a logo should be embedded.
	 *
	 * @since 0.9.0
	 *
	 * @return bool True if a logo should be embedded.
	 */
	public function has_logo(): bool;

	/**
	 * Generate a QR code file for a ticket.
	 *
	 * @since 0.9.0
	 *
	 * @param Ticket $ticket Ticket model.
	 * @return string QR code image URL.
	 */
	public function generate_for_ticket( Ticket $ticket ): string;

	/**
	 * Get the check-in URL for a ticket code.
	 *
	 * @since 0.9.0
	 *
	 * @param string $code Ticket code.
	 * @return string Check-in URL.
	 */
	public function get_check_in_url( string $code ): string;

	/**
	 * Generate a QR code as base64 data URI.
	 *
	 * @since 0.9.0
	 *
	 * @param string $content Content to encode.
	 * @return string Base64 data URI.
	 */
	public function generate_data_uri( string $content ): string;

	/**
	 * Generate a QR code as base64 data URI with optional logo embedding.
	 *
	 * @since 0.9.0
	 *
	 * @param string $content Content to encode.
	 * @return string Base64 data URI.
	 */
	public function generate_data_uri_with_logo( string $content ): string;

	/**
	 * Generate a QR code as raw PNG binary data.
	 *
	 * @since 0.9.0
	 *
	 * @param string $content Content to encode in the QR code.
	 * @return string Raw PNG binary data.
	 */
	public function generate_png( string $content ): string;

	/**
	 * Validate a ticket code format.
	 *
	 * @since 0.9.0
	 *
	 * @param string $code Ticket code to validate.
	 * @return bool True if valid format.
	 */
	public function validate_code_format( string $code ): bool;

	/**
	 * Delete a QR code file for a ticket.
	 *
	 * @since 0.9.0
	 *
	 * @param string $ticket_code Ticket code.
	 * @return bool True if deleted or didn't exist.
	 */
	public function delete_qr_file( string $ticket_code ): bool;
}
