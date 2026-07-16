<?php
/**
 * Tests for DebugLogger utility class.
 *
 * @package NetterTechEvents\Tests\Unit\Utilities
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Utilities;

use Brain\Monkey\Functions;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Unit tests for DebugLogger.
 *
 * @coversDefaultClass \NetterTechEvents\Utilities\DebugLogger
 */
class DebugLoggerTest extends \NetterTechEventsTestCase {

	/**
	 * @covers ::log
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_log_does_not_call_error_log_when_wp_debug_is_false(): void {
		// phpunit.xml.dist defines WP_DEBUG=true as an immutable constant.
		// Even @runInSeparateProcess cannot override it — PHPUnit processes
		// <const> elements before the test method executes.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->markTestSkipped( 'Cannot test WP_DEBUG=false path: phpunit.xml.dist defines WP_DEBUG=true (immutable constant).' );
		}

		$was_called = false;
		Functions\when( 'error_log' )->alias(
			function () use ( &$was_called ) {
				$was_called = true;
			}
		);

		DebugLogger::log( 'should be suppressed' );

		$this->assertFalse( $was_called, 'error_log should not be called when WP_DEBUG is false' );
	}

	/**
	 * @covers ::log
	 */
	public function test_log_calls_error_log_when_wp_debug_is_true(): void {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		DebugLogger::log( 'test message' );

		$this->assertNotNull( $logged_message, 'error_log should be called when WP_DEBUG is true' );
	}

	/**
	 * @covers ::log
	 */
	public function test_log_prefixes_with_context_when_context_provided(): void {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		DebugLogger::log( 'something failed', 'RecurrenceService' );

		$this->assertStringContainsString( '[NTE:RecurrenceService]', $logged_message );
		$this->assertStringContainsString( 'something failed', $logged_message );
	}

	/**
	 * @covers ::log
	 */
	public function test_log_prefixes_with_nte_when_no_context(): void {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		DebugLogger::log( 'bare message' );

		$this->assertStringStartsWith( '[NTE] ', $logged_message );
		$this->assertStringContainsString( 'bare message', $logged_message );
	}

	/**
	 * @covers ::exception
	 */
	public function test_exception_delegates_to_log_with_exception_message(): void {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		$exception = new \RuntimeException( 'something broke badly' );
		DebugLogger::exception( $exception, 'TicketTypeSaver' );

		$this->assertStringContainsString( '[NTE:TicketTypeSaver]', $logged_message );
		$this->assertStringContainsString( 'something broke badly', $logged_message );
	}

	/**
	 * @covers ::exception
	 */
	public function test_exception_without_context_uses_nte_prefix(): void {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		$exception = new \InvalidArgumentException( 'invalid input' );
		DebugLogger::exception( $exception );

		$this->assertStringStartsWith( '[NTE] ', $logged_message );
		$this->assertStringContainsString( 'invalid input', $logged_message );
	}

	/**
	 * @covers ::exception
	 */
	public function test_exception_includes_stack_trace(): void {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		$logged_message = null;
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		$exception = new \RuntimeException( 'trace test' );
		DebugLogger::exception( $exception, 'TraceContext' );

		$this->assertStringContainsString( 'trace test', $logged_message );
		$this->assertStringContainsString( '#0 ', $logged_message, 'Stack trace should be included in the logged message' );
	}
}
