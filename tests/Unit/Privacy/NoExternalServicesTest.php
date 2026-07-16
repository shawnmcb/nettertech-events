<?php
/**
 * No-external-services static analysis tests.
 *
 * Verifies the readme.txt claim that no data is sent to external services
 * by asserting zero HTTP-initiating calls in plugin source code.
 *
 * @package NetterTechEvents\Tests\Unit\Privacy
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Privacy;

/**
 * Test that plugin source contains no outbound HTTP calls.
 */
class NoExternalServicesTest extends \NetterTechEventsTestCase {

	/**
	 * Regex patterns that indicate an outbound HTTP call.
	 *
	 * wp_remote_get/post/request are the WordPress HTTP API functions.
	 * file_get_contents/fopen with http/https scheme bypass WordPress.
	 * curl_init is raw cURL.
	 * Requests:: is the legacy WordPress HTTP Requests class.
	 * GuzzleHttp is a third-party HTTP client.
	 */
	private const HTTP_PATTERNS = array(
		'wp_remote_get',
		'wp_remote_post',
		'wp_remote_request',
		'wp_remote_head',
		'wp_safe_remote_get',
		'wp_safe_remote_post',
		'wp_safe_remote_request',
		"file_get_contents('http",
		'file_get_contents("http',
		"fopen('http",
		'fopen("http',
		'curl_init',
		'Requests::',
		'GuzzleHttp',
	);

	/**
	 * PHP source files in includes/ to scan.
	 *
	 * @return array<array{string}>
	 */
	public static function php_source_provider(): array {
		$plugin_root  = dirname( __DIR__, 3 );
		$includes_dir = $plugin_root . '/includes';

		$files = array();
		if ( ! is_dir( $includes_dir ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $includes_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				$files[] = array( $file->getRealPath() );
			}
		}

		return $files;
	}

	/**
	 * @dataProvider php_source_provider
	 * @param string $php_file Absolute path to the PHP source file.
	 */
	public function test_source_file_makes_no_outbound_http_calls( string $php_file ): void {
		$content = file_get_contents( $php_file );
		$this->assertNotFalse( $content, "Could not read file: {$php_file}" );

		foreach ( self::HTTP_PATTERNS as $pattern ) {
			$this->assertStringNotContainsString(
				$pattern,
				$content,
				sprintf(
					'File %s contains outbound HTTP call pattern "%s". ' .
					'Plugin readme.txt claims no data is sent to external services.',
					str_replace( dirname( __DIR__, 3 ) . '/', '', $php_file ),
					$pattern
				)
			);
		}
	}
}
