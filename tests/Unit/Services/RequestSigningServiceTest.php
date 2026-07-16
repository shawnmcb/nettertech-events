<?php
/**
 * RequestSigningService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Services\RequestSigningService;

/**
 * Test RequestSigningService HMAC signing and verification.
 *
 * Note: Tests use real time() since it's a PHP internal that requires
 * patchwork.json config to mock. Replay protection tests use timestamp
 * arithmetic relative to actual time() instead.
 */
class RequestSigningServiceTest extends \NetterTechEventsTestCase {

	/**
	 * @var RequestSigningService
	 */
	private RequestSigningService $service;

	/**
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// AUTH_KEY may be defined by another test or bootstrap.
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test-auth-key-for-unit-tests' );
		}
	}

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = new RequestSigningService();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	// =========================================================================
	// sign() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_sign_returns_hex_string(): void {
		$sig = $this->service->sign( 'test-data', 1700000000 );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $sig );
	}

	/**
	 * @return void
	 */
	public function test_sign_is_deterministic_with_same_inputs(): void {
		$sig1 = $this->service->sign( 'test-data', 1700000000 );
		$sig2 = $this->service->sign( 'test-data', 1700000000 );

		$this->assertSame( $sig1, $sig2 );
	}

	/**
	 * @return void
	 */
	public function test_sign_differs_for_different_data(): void {
		$sig1 = $this->service->sign( 'data-a', 1700000000 );
		$sig2 = $this->service->sign( 'data-b', 1700000000 );

		$this->assertNotSame( $sig1, $sig2 );
	}

	/**
	 * @return void
	 */
	public function test_sign_differs_for_different_timestamps(): void {
		$sig1 = $this->service->sign( 'test-data', 1000 );
		$sig2 = $this->service->sign( 'test-data', 2000 );

		$this->assertNotSame( $sig1, $sig2 );
	}

	/**
	 * @return void
	 */
	public function test_sign_produces_correct_hmac(): void {
		$data      = 'test-data';
		$timestamp = 1700000000;
		$payload   = $timestamp . '.' . $data;
		$expected  = hash_hmac( 'sha256', $payload, AUTH_KEY );

		$this->assertSame( $expected, $this->service->sign( $data, $timestamp ) );
	}

	// =========================================================================
	// verify() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_verify_succeeds_with_valid_signature(): void {
		$now       = time();
		$signature = $this->service->sign( 'test-data', $now );

		$this->assertTrue( $this->service->verify( 'test-data', $signature, $now ) );
	}

	/**
	 * @return void
	 */
	public function test_verify_fails_with_tampered_data(): void {
		$now       = time();
		$signature = $this->service->sign( 'original-data', $now );

		$this->assertFalse( $this->service->verify( 'tampered-data', $signature, $now ) );
	}

	/**
	 * @return void
	 */
	public function test_verify_fails_with_tampered_signature(): void {
		$now = time();

		$this->assertFalse( $this->service->verify( 'test-data', 'bad-signature', $now ) );
	}

	/**
	 * @return void
	 */
	public function test_verify_fails_when_expired(): void {
		// Signed 301 seconds ago — exceeds 300s window.
		$sign_time = time() - 301;
		$signature = $this->service->sign( 'test-data', $sign_time );

		$this->assertFalse( $this->service->verify( 'test-data', $signature, $sign_time ) );
	}

	/**
	 * @return void
	 */
	public function test_verify_succeeds_at_max_age_boundary(): void {
		// Signed exactly 300 seconds ago — at the boundary.
		$sign_time = time() - 300;
		$signature = $this->service->sign( 'test-data', $sign_time );

		$this->assertTrue( $this->service->verify( 'test-data', $signature, $sign_time ) );
	}

	/**
	 * @return void
	 */
	public function test_verify_fails_with_future_timestamp(): void {
		// Timestamp is 1 second in the future.
		$sign_time = time() + 1;
		$signature = $this->service->sign( 'test-data', $sign_time );

		$this->assertFalse( $this->service->verify( 'test-data', $signature, $sign_time ) );
	}

	// =========================================================================
	// sign_response() / verify_response() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_sign_response_returns_three_keys(): void {
		$result = $this->service->sign_response( array( 'foo' => 'bar' ) );

		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'signature', $result );
		$this->assertArrayHasKey( 'timestamp', $result );
		$this->assertSame( array( 'foo' => 'bar' ), $result['data'] );
		$this->assertIsInt( $result['timestamp'] );
	}

	/**
	 * @return void
	 */
	public function test_sign_response_signature_is_valid_hex(): void {
		$result = $this->service->sign_response( array( 'test' => 1 ) );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result['signature'] );
	}

	/**
	 * @return void
	 */
	public function test_verify_response_roundtrip(): void {
		$data   = array( 'attendee_id' => 42, 'status' => 'checked_in' );
		$signed = $this->service->sign_response( $data );

		$this->assertTrue(
			$this->service->verify_response( $signed['data'], $signed['signature'], $signed['timestamp'] )
		);
	}

	/**
	 * @return void
	 */
	public function test_verify_response_fails_with_tampered_data(): void {
		$signed = $this->service->sign_response( array( 'amount' => 100 ) );

		$this->assertFalse(
			$this->service->verify_response( array( 'amount' => 999 ), $signed['signature'], $signed['timestamp'] )
		);
	}

	// =========================================================================
	// add_signature_headers() Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_add_signature_headers_sets_both_headers(): void {
		$response = $this->createMock( \WP_REST_Response::class );
		$headers  = array();

		$response->method( 'header' )->willReturnCallback(
			function ( $name, $value ) use ( &$headers ) {
				$headers[ $name ] = $value;
			}
		);

		$this->service->add_signature_headers( $response, array( 'test' => 1 ) );

		$this->assertArrayHasKey( 'X-VE-Signature', $headers );
		$this->assertArrayHasKey( 'X-VE-Timestamp', $headers );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $headers['X-VE-Signature'] );
		$this->assertMatchesRegularExpression( '/^\d+$/', $headers['X-VE-Timestamp'] );
	}

	// =========================================================================
	// Secret Key Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_get_secret_key_returns_auth_key(): void {
		$reflection = new \ReflectionMethod( $this->service, 'get_secret_key' );

		$this->assertSame( AUTH_KEY, $reflection->invoke( $this->service ) );
	}

	/**
	 * @return void
	 */
	public function test_signature_uses_configured_key(): void {
		$data      = 'test-data';
		$timestamp = 1700000000;

		// Expected HMAC with AUTH_KEY.
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $data, AUTH_KEY );

		$this->assertSame( $expected, $this->service->sign( $data, $timestamp ) );
	}

	// =========================================================================
	// Branch coverage gap-fills
	// =========================================================================

	/**
	 * Test sign() uses current time when no timestamp provided.
	 *
	 * @return void
	 */
	public function test_sign_uses_current_time_when_no_timestamp(): void {
		$sig = $this->service->sign( 'test-data' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $sig );
	}

	/**
	 * Test sign_response handles wp_json_encode failure.
	 *
	 * @return void
	 */
	public function test_sign_response_handles_json_encode_failure(): void {
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$result = $this->service->sign_response( array( 'foo' => 'bar' ) );

		$this->assertArrayHasKey( 'signature', $result );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result['signature'] );
	}

	/**
	 * Test verify_response returns false on wp_json_encode failure.
	 *
	 * @return void
	 */
	public function test_verify_response_returns_false_on_json_encode_failure(): void {
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$this->assertFalse( $this->service->verify_response( array( 'foo' => 'bar' ), 'abc', time() ) );
	}

	/**
	 * Test add_signature_headers returns response unchanged on wp_json_encode failure.
	 *
	 * @return void
	 */
	public function test_add_signature_headers_returns_response_on_json_encode_failure(): void {
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$response = $this->createMock( \WP_REST_Response::class );
		$response->expects( $this->never() )->method( 'header' );

		$result = $this->service->add_signature_headers( $response, array( 'test' => 1 ) );

		$this->assertSame( $response, $result );
	}
}
