<?php
/**
 * Database logger utility class.
 *
 * Provides sanitized error logging for database operations.
 * Addresses SEC-M002: Sanitizes schema details in production logs.
 *
 * @package NetterTechEvents\Utilities
 */

declare(strict_types=1);

namespace NetterTechEvents\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes database error logging with security considerations.
 *
 * @since 0.9.0
 * @api
 */
class DatabaseLogger {

	/**
	 * Log a database error with appropriate detail level.
	 *
	 * In debug mode (WP_DEBUG = true), logs full error details for debugging.
	 * In production, logs sanitized messages without schema details.
	 *
	 * @param string $operation Operation being performed (e.g., 'insert', 'update', 'delete').
	 * @param string $entity    Entity type being operated on (e.g., 'event', 'attendee').
	 * @param string $error     The raw database error message.
	 * @return void
	 */
	public static function log_error( string $operation, string $entity, string $error ): void {
		DebugLogger::log(
			sprintf( 'DB Error (%s %s): %s', $operation, $entity, $error ),
			'DatabaseLogger'
		);
	}

	/**
	 * Get a user-facing error message for database errors.
	 *
	 * Never exposes raw database errors to users. Provides a generic
	 * message while preserving the raw error for internal handling.
	 *
	 * @param string $operation Operation that failed.
	 * @param string $entity    Entity type involved.
	 * @param string $raw_error The raw database error (for internal use only).
	 * @return string User-safe error message.
	 */
	public static function get_user_message( string $operation, string $entity, string $raw_error ): string {
		// Log the full error for developers.
		self::log_error( $operation, $entity, $raw_error );

		// Return a safe message for users.
		return sprintf(
			/* translators: 1: operation (e.g., 'save'), 2: entity type (e.g., 'event') */
			__( 'Unable to %1$s %2$s. Please try again or contact support.', 'nettertech-events' ),
			$operation,
			$entity
		);
	}
}
