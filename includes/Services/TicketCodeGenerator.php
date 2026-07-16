<?php
/**
 * Ticket Code Generator.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\TicketCodeGeneratorInterface;

/**
 * Generates unique, cryptographically secure ticket codes.
 *
 * Ticket codes are base plugin concern — a ticket always needs a unique
 * identifier even when Pro (QR rendering) is not installed. This service
 * was extracted from QRCodeService during the Pro extraction so that the
 * base plugin has no dependency on Pro for the core ticket creation path.
 *
 * Output format: XXXX-XXXX-XXXX-XXXX (16 hex chars, uppercase, grouped).
 *
 * @since 1.0.0
 */
class TicketCodeGenerator implements TicketCodeGeneratorInterface {

	/**
	 * Number of random bytes to generate. Produces 32 hex chars, truncated to 16.
	 *
	 * @var int
	 */
	private const CODE_BYTES = 16;

	/**
	 * Generate a unique ticket code.
	 *
	 * Format: XXXX-XXXX-XXXX-XXXX (uppercase hex, 16 chars + 3 dashes).
	 * Matches QRCodeService::validate_code_format() regex
	 * `/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/`.
	 *
	 * @since 1.0.0
	 *
	 * @return string Unique ticket code.
	 */
	public function generate(): string {
		$bytes = random_bytes( self::CODE_BYTES );
		$code  = substr( strtoupper( bin2hex( $bytes ) ), 0, 16 );

		return sprintf(
			'%s-%s-%s-%s',
			substr( $code, 0, 4 ),
			substr( $code, 4, 4 ),
			substr( $code, 8, 4 ),
			substr( $code, 12, 4 )
		);
	}
}
