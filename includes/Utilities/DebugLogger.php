<?php
/**
 * Debug Logger utility.
 *
 * Centralizes debug logging with WP_DEBUG gating to prevent
 * information disclosure in production environments.
 *
 * @package NetterTechEvents\Utilities
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Debug Logger — gates all debug output on WP_DEBUG.
 *
 * Replaces scattered error_log() calls with a centralized, production-safe
 * logging utility. When WP_DEBUG is false, all logging is suppressed.
 *
 * @since 1.0.0
 */
final class DebugLogger {

	/**
	 * Log a message if WP_DEBUG is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The message to log.
	 * @param string $context Optional context identifier (e.g., 'RecurrenceService').
	 */
	public static function log( string $message, string $context = '' ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		$prefix = $context ? "[NTE:{$context}] " : '[NTE] ';
		error_log( $prefix . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging, gated on WP_DEBUG.
	}

	/**
	 * Log an exception message if WP_DEBUG is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param \Throwable $exception The exception to log.
	 * @param string     $context   Optional context identifier.
	 */
	public static function exception( \Throwable $exception, string $context = '' ): void {
		self::log(
			$exception->getMessage() . "\n" . $exception->getTraceAsString(),
			$context
		);
	}
}
