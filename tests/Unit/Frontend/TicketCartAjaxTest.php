<?php
/**
 * TicketCartAjax unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use NetterTechEvents\Frontend\TicketCartAjax;
use NetterTechEvents\Integrations\WooCommerce\CartHandler;
use NetterTechEvents\Services\RateLimitService;
use Mockery;

/**
 * Test TicketCartAjax class.
 *
 * @since 2.1.0
 * @coversDefaultClass \NetterTechEvents\Frontend\TicketCartAjax
 */
class TicketCartAjaxTest extends \NetterTechEventsTestCase {

	/**
	 * Mock CartHandler.
	 *
	 * @var CartHandler|Mockery\MockInterface
	 */
	private $cart_handler;

	/**
	 * Mock RateLimitService.
	 *
	 * @var RateLimitService|Mockery\MockInterface
	 */
	private $rate_limit_service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cart_handler       = Mockery::mock( CartHandler::class );
		$this->rate_limit_service = Mockery::mock( RateLimitService::class );
		$this->rate_limit_service->shouldReceive( 'should_bypass' )->byDefault()->andReturn( true );
	}

	/**
	 * @covers ::handle_add_batch
	 */
	public function test_handle_add_batch_rate_limited(): void {
		$rate_limit = Mockery::mock( RateLimitService::class );
		$rate_limit->shouldReceive( 'should_bypass' )->once()->andReturn( false );
		$rate_limit->shouldReceive( 'check_and_increment' )->once()->andReturn(
			new \WP_REST_Response(
				array(
					'code'    => 'rate_limit_exceeded',
					'message' => 'Too many requests.',
				),
				429
			)
		);

		$error_data   = null;
		$error_status = null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data, $status = null ) use ( &$error_data, &$error_status ) {
				$error_data   = $data;
				$error_status = $status;
				throw new \Exception( 'json_error' );
			}
		);

		$ajax = new TicketCartAjax( $this->cart_handler, $rate_limit );

		try {
			$ajax->handle_add_batch();
			$this->fail( 'Expected exception from wp_send_json_error' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
		}

		$this->assertSame( 429, $error_status );
		$this->assertIsArray( $error_data );
		$this->assertStringContainsString( 'Too many requests', $error_data['message'] );
	}
}
