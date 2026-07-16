<?php
/**
 * Tests for EventSkusAjaxHandler (NTE-114).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Ajax
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Ajax;

use NetterTechEvents\Admin\Ajax\EventSkusAjaxHandler;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @coversDefaultClass \NetterTechEvents\Admin\Ajax\EventSkusAjaxHandler
 */
class EventSkusAjaxHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Mock ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Handler under test.
	 *
	 * @var EventSkusAjaxHandler
	 */
	private EventSkusAjaxHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->event_repo       = Mockery::mock( EventRepositoryInterface::class );
		$this->ticket_type_repo = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->handler          = new EventSkusAjaxHandler( $this->event_repo, $this->ticket_type_repo );

		Functions\when( '__' )->alias( fn( $text ) => $text );

		// wp_send_json_error always halts in production (wp_die); throw to model that.
		Functions\when( 'wp_send_json_error' )->alias(
			function () {
				throw new \RuntimeException( 'wp_send_json_error' );
			}
		);

		TicketTypeFactory::reset();
	}

	protected function tearDown(): void {
		unset( $_GET['event_id'], $_GET['nonce'] );
		parent::tearDown();
	}

	/**
	 * @covers ::handle
	 */
	public function test_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->handler->handle();
	}

	/**
	 * @covers ::handle
	 */
	public function test_denies_on_invalid_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$_GET['nonce']    = 'bad';
		$_GET['event_id'] = '5';

		$this->expectException( \RuntimeException::class );
		$this->handler->handle();
	}

	/**
	 * @covers ::handle
	 */
	public function test_returns_skus_and_excludes_empty_from_csv(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$_GET['nonce']    = 'good';
		$_GET['event_id'] = '7';

		$event = EventFactory::create(
			array(
				'id'    => 7,
				'title' => 'Summer Fest',
			)
		);
		$this->event_repo->shouldReceive( 'find' )->with( 7 )->andReturn( $event );

		$this->ticket_type_repo->shouldReceive( 'for_event' )->with( 7 )->andReturn(
			array(
				TicketTypeFactory::create( array( 'name' => 'GA', 'wc_product_id' => 101 ) ),
				TicketTypeFactory::create( array( 'name' => 'VIP', 'wc_product_id' => 102 ) ),
				TicketTypeFactory::create( array( 'name' => 'Comp', 'wc_product_id' => 103 ) ),
			)
		);

		// Product 102 has no SKU yet → present in the per-ticket list, absent from the CSV.
		$skus = array(
			101 => 'summer-fest--ga',
			102 => '',
			103 => 'summer-fest--comp',
		);
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( $skus ) {
				$product = Mockery::mock( \WC_Product::class );
				$product->shouldReceive( 'get_sku' )->andReturn( $skus[ $id ] ?? '' );
				return $product;
			}
		);

		$captured = null;
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) use ( &$captured ) {
				$captured = $data;
			}
		);

		$this->handler->handle();

		$this->assertNotNull( $captured );
		$this->assertSame( 7, $captured['event_id'] );
		$this->assertSame( 'Summer Fest', $captured['event_title'] );
		$this->assertCount( 3, $captured['tickets'] );
		$this->assertSame( 'summer-fest--ga, summer-fest--comp', $captured['skus_csv'] );
	}
}
