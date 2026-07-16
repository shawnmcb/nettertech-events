<?php
/**
 * Request signing service for sensitive API data.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Provides HMAC signing for sensitive API responses.
 *
 * Uses WordPress secret keys to generate signatures that verify
 * data integrity and authenticity. Useful for:
 * - Check-in tokens
 * - Sensitive data in API responses
 * - Tamper detection
 *
 * @since 1.0.0
 * @api
 */
class RequestSigningService {

	/**
	 * Algorithm used for HMAC signing.
	 *
	 * @var string
	 */
	private const ALGORITHM = 'sha256';

	/**
	 * Signature header name for API responses.
	 *
	 * @var string
	 */
	public const SIGNATURE_HEADER = 'X-VE-Signature';

	/**
	 * Timestamp header name for replay protection.
	 *
	 * @var string
	 */
	public const TIMESTAMP_HEADER = 'X-VE-Timestamp';

	/**
	 * Maximum age in seconds for signature validity (5 minutes).
	 *
	 * @var int
	 */
	private const MAX_AGE_SECONDS = 300;

	/**
	 * Generate HMAC signature for data.
	 *
	 * @param string $data      Data to sign.
	 * @param int    $timestamp Unix timestamp for replay protection.
	 * @return string Hex-encoded HMAC signature.
	 */
	public function sign( string $data, int $timestamp = 0 ): string {
		if ( 0 === $timestamp ) {
			$timestamp = time();
		}

		// Include timestamp in signed data for replay protection.
		$payload = $timestamp . '.' . $data;

		return hash_hmac( self::ALGORITHM, $payload, $this->get_secret_key() );
	}

	/**
	 * Verify HMAC signature for data.
	 *
	 * @param string $data      Data that was signed.
	 * @param string $signature Signature to verify.
	 * @param int    $timestamp Timestamp when signature was created.
	 * @return bool True if signature is valid and not expired.
	 */
	public function verify( string $data, string $signature, int $timestamp ): bool {
		// Check signature age for replay protection.
		$age = time() - $timestamp;
		if ( $age < 0 || $age > self::MAX_AGE_SECONDS ) {
			return false;
		}

		// Generate expected signature.
		$expected = $this->sign( $data, $timestamp );

		// Timing-safe comparison.
		return hash_equals( $expected, $signature );
	}

	/**
	 * Sign an array of data and return with signature metadata.
	 *
	 * @param array<string, mixed> $data Data to sign.
	 * @return array{data: array<string, mixed>, signature: string, timestamp: int}
	 */
	public function sign_response( array $data ): array {
		$timestamp = time();
		$payload   = wp_json_encode( $data );

		if ( false === $payload ) {
			$payload = '';
		}

		return array(
			'data'      => $data,
			'signature' => $this->sign( $payload, $timestamp ),
			'timestamp' => $timestamp,
		);
	}

	/**
	 * Verify a signed response.
	 *
	 * @param array<string, mixed> $data      Data that was signed.
	 * @param string               $signature Signature to verify.
	 * @param int                  $timestamp Timestamp when signature was created.
	 * @return bool True if signature is valid.
	 */
	public function verify_response( array $data, string $signature, int $timestamp ): bool {
		$payload = wp_json_encode( $data );

		if ( false === $payload ) {
			return false;
		}

		return $this->verify( $payload, $signature, $timestamp );
	}

	/**
	 * Add signature headers to a WP_REST_Response.
	 *
	 * @param \WP_REST_Response    $response Response object.
	 * @param array<string, mixed> $data     Data being returned.
	 * @return \WP_REST_Response Response with signature headers.
	 */
	public function add_signature_headers( \WP_REST_Response $response, array $data ): \WP_REST_Response {
		$timestamp = time();
		$payload   = wp_json_encode( $data );

		if ( false === $payload ) {
			return $response;
		}

		$signature = $this->sign( $payload, $timestamp );

		$response->header( self::SIGNATURE_HEADER, $signature );
		$response->header( self::TIMESTAMP_HEADER, (string) $timestamp );

		return $response;
	}

	/**
	 * Get the secret key for HMAC signing.
	 *
	 * Uses WordPress AUTH_KEY, with fallback to SECURE_AUTH_KEY.
	 * These keys are defined in wp-config.php and should be unique per site.
	 * If neither constant is defined (misconfigured WordPress), generates
	 * and persists a random key in the database.
	 *
	 * @return string Secret key.
	 */
	private function get_secret_key(): string {
		// Use AUTH_KEY as primary secret.
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			return AUTH_KEY;
		}

		// Fallback to SECURE_AUTH_KEY.
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			return SECURE_AUTH_KEY;
		}

		// Last resort: generate and persist a random key.
		$key = get_option( 'nettertech_events_hmac_key' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 64, true, true );
			update_option( 'nettertech_events_hmac_key', $key, false );
		}
		return $key;
	}
}
