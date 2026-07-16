<?php
/**
 * Ticket Code Generator Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for TicketCodeGenerator implementations.
 *
 * Generates unique, cryptographically secure ticket codes.
 * Base plugin concern extracted from QRCodeService for Pro extensibility.
 *
 * @since 2.0.0
 * @api
 */
interface TicketCodeGeneratorInterface {

	/**
	 * Generate a unique ticket code.
	 *
	 * @since 1.0.0
	 *
	 * @return string Unique ticket code.
	 */
	public function generate(): string;
}
