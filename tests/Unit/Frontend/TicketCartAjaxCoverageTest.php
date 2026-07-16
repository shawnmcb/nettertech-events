<?php
/**
 * TicketCartAjax coverage-targeted tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Frontend\TicketCartAjax;
use NetterTechEvents\Integrations\WooCommerce\CartHandler;
use NetterTechEvents\Services\RateLimitService;

/**
 * Behavior coverage for TicketCartAjax.
 *
 * @coversDefaultClass \NetterTechEvents\Frontend\TicketCartAjax
 */
class TicketCartAjaxCoverageTest extends \NetterTechEventsTestCase {

	/**
	 * Mock cart handler.
	 *
	 * @var CartHandler|Mockery\MockInterface
	 */
	private $cart_handler;

	/**
	 * Mock rate limit service.
	 *
	 * @var RateLimitService|Mockery\MockInterface
	 */
	private $rate_limit_service;

	/**
	 * Captured wp_send_json_* payloads.
	 *
	 * @var array<int, array{success: bool, data: mixed, status: int|null}>
	 */
	private array $sent;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'add_action' )->justReturn( true );

		$this->sent = array();
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data, $status = null ) {
				$this->sent[] = array(
					'success' => false,
					'data'    => $data,
					'status'  => $status,
				);
				throw new \RuntimeException( 'wp_send_json_error' );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) {
				$this->sent[] = array(
					'success' => true,
					'data'    => $data,
					'status'  => null,
				);
				throw new \RuntimeException( 'wp_send_json_success' );
			}
		);

		$this->cart_handler       = Mockery::mock( CartHandler::class );
		$this->rate_limit_service = Mockery::mock( RateLimitService::class );

		$_POST = array();
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Build instance.
	 *
	 * @return TicketCartAjax
	 */
	private function build(): TicketCartAjax {
		return new TicketCartAjax( $this->cart_handler, $this->rate_limit_service );
	}

	/**
	 * Test register short-circuits when WooCommerce not available.
	 *
	 * @return void
	 */
	public function test_register_noop_without_woocommerce(): void {
		$this->build()->register();
		$this->assertTrue( true );
	}

	/**
	 * Test handle_add_batch returns rate-limited error.
	 *
	 * @return void
	 */
	public function test_rate_limited_returns_429(): void {
		if ( ! class_exists( 'WP_REST_Response' ) ) {
			eval( 'class WP_REST_Response {}' );
		}

		$this->rate_limit_service->shouldReceive( 'should_bypass' )->andReturn( false );
		$this->rate_limit_service->shouldReceive( 'check_and_increment' )->andReturn( new \WP_REST_Response() );

		try {
			$this->build()->handle_add_batch();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 429, $this->sent[0]['status'] );
	}

	/**
	 * Test handle_add_batch rejects invalid nonce.
	 *
	 * @return void
	 */
	public function test_rejects_invalid_nonce(): void {
		$this->rate_limit_service->shouldReceive( 'should_bypass' )->andReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		try {
			$this->build()->handle_add_batch();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertSame( 403, $this->sent[0]['status'] );
	}

	/**
	 * Test handle_add_batch rejects invalid JSON.
	 *
	 * @return void
	 */
	public function test_rejects_invalid_json(): void {
		$_POST['nettertech_events_ticket_nonce'] = 'n';
		$_POST['tickets']                        = '{not-json}';

		$this->rate_limit_service->shouldReceive( 'should_bypass' )->andReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		try {
			$this->build()->handle_add_batch();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertSame( 400, $this->sent[0]['status'] );
		$this->assertStringContainsString( 'Invalid ticket data format', $this->sent[0]['data']['message'] );
	}

	/**
	 * Test handle_add_batch rejects empty tickets array.
	 *
	 * @return void
	 */
	public function test_rejects_empty_tickets(): void {
		$_POST['nettertech_events_ticket_nonce'] = 'n';
		$_POST['tickets']                        = json_encode( array() );

		$this->rate_limit_service->shouldReceive( 'should_bypass' )->andReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		try {
			$this->build()->handle_add_batch();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertSame( 400, $this->sent[0]['status'] );
		$this->assertStringContainsString( 'at least one ticket', $this->sent[0]['data']['message'] );
	}

	/**
	 * Test handle_add_batch rejects when all sanitized to zero.
	 *
	 * @return void
	 */
	public function test_rejects_when_all_zero_after_sanitize(): void {
		$_POST['nettertech_events_ticket_nonce'] = 'n';
		$_POST['tickets']                        = json_encode(
			array(
				array(
					'ticket_type_id' => 0,
					'quantity'       => 0,
				),
			)
		);

		$this->rate_limit_service->shouldReceive( 'should_bypass' )->andReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		try {
			$this->build()->handle_add_batch();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertSame( 400, $this->sent[0]['status'] );
		$this->assertStringContainsString( 'quantity greater than zero', $this->sent[0]['data']['message'] );
	}

	/**
	 * Test validation failure returns errors.
	 *
	 * @return void
	 */
	public function test_validation_failure(): void {
		$_POST['nettertech_events_ticket_nonce'] = 'n';
		$_POST['tickets']                        = json_encode(
			array(
				array(
					'ticket_type_id' => 5,
					'quantity'       => 2,
				),
			)
		);

		$this->rate_limit_service->shouldReceive( 'should_bypass' )->andReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$this->cart_handler->shouldReceive( 'validate_batch' )->andReturn(
			array(
				'valid'  => false,
				'errors' => array( 5 => 'Out of stock' ),
			)
		);

		try {
			$this->build()->handle_add_batch();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertSame( 400, $this->sent[0]['status'] );
		$this->assertArrayHasKey( 'ticket_errors', $this->sent[0]['data'] );
	}
}
