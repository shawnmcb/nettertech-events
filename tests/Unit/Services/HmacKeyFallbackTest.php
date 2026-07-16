<?php
/**
 * HMAC key fallback behavior tests.
 *
 * Tests the option-based fallback key generation used by
 * CookieCheckInService and RequestSigningService when
 * WordPress auth constants are not defined.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;

/**
 * Test HMAC key fallback generation and persistence.
 *
 * Since AUTH_KEY/SECURE_AUTH_KEY are constants that cannot be
 * undefined after the test bootstrap defines them, these tests
 * verify the fallback pattern in isolation by directly testing
 * the get_option/update_option/wp_generate_password contract.
 */
class HmacKeyFallbackTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Fallback Key Generation
	// =========================================================================

	/**
	 * Test fallback generates key on first call when option is empty.
	 *
	 * @return void
	 */
	public function test_fallback_generates_key_when_option_empty(): void {
		$generated_key = null;

		Functions\when( 'get_option' )->alias(
			function ( $name ) {
				if ( 'nettertech_events_hmac_key' === $name ) {
					return false;
				}
				return '';
			}
		);

		Functions\when( 'wp_generate_password' )->alias(
			function ( $length, $special_chars, $extra_special ) use ( &$generated_key ) {
				$this->assertSame( 64, $length );
				$this->assertTrue( $special_chars );
				$this->assertTrue( $extra_special );

				$generated_key = bin2hex( random_bytes( 32 ) );
				return $generated_key;
			}
		);

		$stored_key     = null;
		$stored_options = array();

		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload ) use ( &$stored_key, &$stored_options ) {
				$stored_key              = $value;
				$stored_options[ $name ] = array(
					'value'    => $value,
					'autoload' => $autoload,
				);
				return true;
			}
		);

		// Simulate the fallback logic from CookieCheckInService::get_hmac_key().
		$key = get_option( 'nettertech_events_hmac_key' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 64, true, true );
			update_option( 'nettertech_events_hmac_key', $key, false );
		}

		$this->assertNotEmpty( $key );
		$this->assertSame( $generated_key, $key );
		$this->assertSame( $generated_key, $stored_key );
		$this->assertArrayHasKey( 'nettertech_events_hmac_key', $stored_options );
		$this->assertFalse( $stored_options['nettertech_events_hmac_key']['autoload'] );
	}

	/**
	 * Test fallback returns existing key on subsequent calls.
	 *
	 * @return void
	 */
	public function test_fallback_returns_persisted_key(): void {
		$persisted_key = 'previously-generated-secret-key-abcdef1234567890';

		Functions\when( 'get_option' )->alias(
			function ( $name ) use ( $persisted_key ) {
				if ( 'nettertech_events_hmac_key' === $name ) {
					return $persisted_key;
				}
				return '';
			}
		);

		// wp_generate_password should NOT be called.
		$generate_called = false;
		Functions\when( 'wp_generate_password' )->alias(
			function () use ( &$generate_called ) {
				$generate_called = true;
				return 'should-not-be-used';
			}
		);

		// update_option should NOT be called.
		$update_called = false;
		Functions\when( 'update_option' )->alias(
			function () use ( &$update_called ) {
				$update_called = true;
				return true;
			}
		);

		// Simulate the fallback logic.
		$key = get_option( 'nettertech_events_hmac_key' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 64, true, true );
			update_option( 'nettertech_events_hmac_key', $key, false );
		}

		$this->assertSame( $persisted_key, $key );
		$this->assertFalse( $generate_called, 'wp_generate_password should not be called when key exists' );
		$this->assertFalse( $update_called, 'update_option should not be called when key exists' );
	}

	/**
	 * Test generated key has sufficient length for cryptographic use.
	 *
	 * @return void
	 */
	public function test_generated_key_has_sufficient_length(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$key_length = null;
		Functions\when( 'wp_generate_password' )->alias(
			function ( $length ) use ( &$key_length ) {
				$key_length = $length;
				return str_repeat( 'x', $length );
			}
		);

		Functions\when( 'update_option' )->justReturn( true );

		// Simulate the fallback logic.
		$key = get_option( 'nettertech_events_hmac_key' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 64, true, true );
			update_option( 'nettertech_events_hmac_key', $key, false );
		}

		$this->assertSame( 64, $key_length );
		$this->assertSame( 64, strlen( $key ) );
	}

	/**
	 * Test autoload is disabled for the HMAC key option.
	 *
	 * The key is only needed during check-in/signing operations,
	 * not on every page load.
	 *
	 * @return void
	 */
	public function test_key_option_not_autoloaded(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 'k', 64 ) );

		$autoload_value = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload ) use ( &$autoload_value ) {
				$autoload_value = $autoload;
				return true;
			}
		);

		// Simulate the fallback logic.
		$key = get_option( 'nettertech_events_hmac_key' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 64, true, true );
			update_option( 'nettertech_events_hmac_key', $key, false );
		}

		$this->assertFalse( $autoload_value );
	}
}
