<?php
/**
 * Tests for DatabaseLogger utility class.
 *
 * @package NetterTechEvents\Tests\Unit\Utilities
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Utilities;

use Brain\Monkey\Functions;
use NetterTechEvents\Utilities\DatabaseLogger;

/**
 * Unit tests for DatabaseLogger.
 *
 * @coversDefaultClass \NetterTechEvents\Utilities\DatabaseLogger
 */
class DatabaseLoggerTest extends \NetterTechEventsTestCase {

	/**
	 * @covers ::log_error
	 */
	public function test_log_error_in_debug_mode_logs_full_details(): void {
		// Define WP_DEBUG as true for this test.
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		// Capture the error log call.
		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		DatabaseLogger::log_error( 'insert', 'event', 'Duplicate entry for key' );

		// In debug mode, should log full error details.
		$this->assertStringContainsString( '[NTE:DatabaseLogger] DB Error (insert event):', $logged_message );
		$this->assertStringContainsString( 'Duplicate entry for key', $logged_message );
	}

	/**
	 * @covers ::log_error
	 */
	public function test_log_error_includes_operation_and_entity(): void {
		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		DatabaseLogger::log_error( 'delete', 'attendee', 'Foreign key constraint fails' );

		$this->assertStringContainsString( 'delete', $logged_message );
		$this->assertStringContainsString( 'attendee', $logged_message );
	}

	/**
	 * @covers ::get_user_message
	 */
	public function test_get_user_message_returns_safe_message(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'error_log' )->justReturn( true );

		$message = DatabaseLogger::get_user_message( 'save', 'event', 'SQL syntax error near SELECT' );

		// Message should not contain raw error details.
		$this->assertStringNotContainsString( 'SQL syntax', $message );
		$this->assertStringNotContainsString( 'SELECT', $message );
	}

	/**
	 * @covers ::get_user_message
	 */
	public function test_get_user_message_includes_operation_and_entity(): void {
		Functions\when( '__' )->alias(
			function ( $text ) {
				return $text;
			}
		);
		Functions\when( 'error_log' )->justReturn( true );

		$message = DatabaseLogger::get_user_message( 'update', 'ticket', 'Unknown column' );

		// Message should include operation and entity in user-friendly format.
		$this->assertStringContainsString( 'update', $message );
		$this->assertStringContainsString( 'ticket', $message );
		$this->assertStringContainsString( 'contact support', $message );
	}

	/**
	 * @covers ::get_user_message
	 */
	public function test_get_user_message_calls_log_error(): void {
		Functions\when( '__' )->returnArg();

		$log_called = false;
		Functions\when( 'error_log' )->alias(
			function () use ( &$log_called ) {
				$log_called = true;
			}
		);

		DatabaseLogger::get_user_message( 'create', 'occurrence', 'Constraint violation' );

		$this->assertTrue( $log_called, 'log_error should be called from get_user_message' );
	}

	/**
	 * @covers ::log_error
	 */
	public function test_log_error_handles_empty_error_string(): void {
		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		DatabaseLogger::log_error( 'insert', 'event', '' );

		$this->assertStringContainsString( 'DB Error', $logged_message );
	}

	/**
	 * @covers ::log_error
	 */
	public function test_log_error_handles_special_characters(): void {
		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		DatabaseLogger::log_error( 'insert', 'event', "Error with 'quotes' and \"double quotes\"" );

		$this->assertStringContainsString( 'DB Error', $logged_message );
	}

	/**
	 * @covers ::get_user_message
	 */
	public function test_get_user_message_format_matches_expected_pattern(): void {
		Functions\when( '__' )->alias(
			function ( $text, $domain = null ) {
				// Simulate sprintf placeholder replacement.
				return $text;
			}
		);
		Functions\when( 'error_log' )->justReturn( true );

		$message = DatabaseLogger::get_user_message( 'delete', 'venue', 'Foreign key error' );

		// Verify message contains expected format pattern.
		$this->assertStringContainsString( 'Unable to', $message );
		$this->assertStringContainsString( 'Please try again', $message );
	}
}
