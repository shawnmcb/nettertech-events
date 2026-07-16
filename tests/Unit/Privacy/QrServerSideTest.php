<?php
/**
 * QR code server-side generation static analysis tests.
 *
 * Verifies the readme.txt claim that QR codes are generated entirely on
 * the server using the bundled chillerlan/php-qrcode library with no
 * external API calls.
 *
 * @package NetterTechEvents\Tests\Unit\Privacy
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Privacy;

/**
 * Test that QR code generation files make no outbound HTTP calls.
 */
class QrServerSideTest extends \NetterTechEventsTestCase {

	/**
	 * QR-related source files to audit, relative to plugin includes/.
	 *
	 * @return array<array{string, string}>
	 */
	public static function qr_file_provider(): array {
		$includes = dirname( __DIR__, 3 ) . '/includes';
		return array(
			array( $includes . '/Services/QRCodeService.php', 'QRCodeService' ),
			array( $includes . '/Services/QRCodeRenderer.php', 'QRCodeRenderer' ),
			array( $includes . '/Services/QRImageWithLogo.php', 'QRImageWithLogo' ),
		);
	}

	/**
	 * @dataProvider qr_file_provider
	 * @param string $file     Absolute path to the QR code source file.
	 * @param string $label    Human-readable label for failure messages.
	 */
	public function test_qr_file_exists( string $file, string $label ): void {
		$this->assertFileExists( $file, "Expected QR code file not found: {$label}" );
	}

	/**
	 * @dataProvider qr_file_provider
	 * @param string $file  Absolute path to the QR code source file.
	 * @param string $label Human-readable label for failure messages.
	 */
	public function test_qr_file_makes_no_outbound_http_calls( string $file, string $label ): void {
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( "QR file not found: {$label}" );
		}

		$content = file_get_contents( $file );
		$this->assertNotFalse( $content, "Could not read: {$label}" );

		$http_patterns = array(
			'wp_remote_get',
			'wp_remote_post',
			'wp_remote_request',
			'wp_safe_remote_get',
			'wp_safe_remote_post',
			"file_get_contents('http",
			'file_get_contents("http',
			"fopen('http",
			'fopen("http',
			'curl_init',
			'Requests::',
			'GuzzleHttp',
		);

		foreach ( $http_patterns as $pattern ) {
			$this->assertStringNotContainsString(
				$pattern,
				$content,
				sprintf(
					'%s contains outbound HTTP pattern "%s". ' .
					'QR codes must be generated server-side with no external API calls.',
					$label,
					$pattern
				)
			);
		}
	}
}
