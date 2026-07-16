<?php
/**
 * PerAttendeeCheckoutHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Integrations\WooCommerce\CartHandler;
use NetterTechEvents\Integrations\WooCommerce\PerAttendeeCheckoutHandler;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;

/**
 * Test PerAttendeeCheckoutHandler functionality.
 *
 * Tests per-attendee form validation and data saving.
 */
class PerAttendeeCheckoutHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Handler under test.
	 *
	 * @var PerAttendeeCheckoutHandler
	 */
	private PerAttendeeCheckoutHandler $handler;

	/**
	 * Mock cart handler.
	 *
	 * @var CartHandler|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $cart_handler;

	/**
	 * Mock product manager.
	 *
	 * @var ProductManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $product_manager;

	/**
	 * Mock event repo.
	 *
	 * @var EventRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Mock field repo.
	 *
	 * @var AttendeeFieldRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $field_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cart_handler    = $this->createMock( CartHandler::class );
		$this->product_manager = $this->createMock( ProductManager::class );
		$this->event_repo      = $this->createMock( EventRepositoryInterface::class );
		$this->field_repo      = $this->createMock( AttendeeFieldRepositoryInterface::class );

		$this->handler = new PerAttendeeCheckoutHandler(
			$this->cart_handler,
			$this->product_manager,
			$this->event_repo,
			$this->field_repo,
		);
	}

	/**
	 * Create a mock WP_Error with add() and get_error_codes().
	 *
	 * @return \WP_Error
	 */
	private function create_mock_wp_error(): \WP_Error {
		return new \WP_Error();
	}

	// =========================================================================
	// validate_forms() Tests
	// =========================================================================

	/**
	 * Test validate_forms does nothing when no submitted data.
	 *
	 * @return void
	 */
	public function test_validate_forms_does_nothing_without_submitted_data(): void {
		$_POST = [];
		$errors = $this->create_mock_wp_error();

		$this->handler->validate_forms( [], $errors );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	/**
	 * Test validate_forms adds error for missing name.
	 *
	 * @return void
	 */
	public function test_validate_forms_adds_error_for_missing_name(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'cart-key-1' => [
				0 => [ 'name' => '', 'email' => 'test@example.com' ],
			],
		];

		Functions\when( 'is_email' )->justReturn( true );

		$errors = $this->create_mock_wp_error();
		$this->handler->validate_forms( [], $errors );

		$this->assertContains( 'nettertech_events_attendee_name', $errors->get_error_codes() );
	}

	/**
	 * Test validate_forms adds error for missing email.
	 *
	 * @return void
	 */
	public function test_validate_forms_adds_error_for_missing_email(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'cart-key-1' => [
				0 => [ 'name' => 'John', 'email' => '' ],
			],
		];

		$errors = $this->create_mock_wp_error();
		$this->handler->validate_forms( [], $errors );

		$this->assertContains( 'nettertech_events_attendee_email', $errors->get_error_codes() );
	}

	/**
	 * Test validate_forms adds error for invalid email.
	 *
	 * @return void
	 */
	public function test_validate_forms_adds_error_for_invalid_email(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'cart-key-1' => [
				0 => [ 'name' => 'John', 'email' => 'not-an-email' ],
			],
		];

		Functions\when( 'is_email' )->justReturn( false );

		$errors = $this->create_mock_wp_error();
		$this->handler->validate_forms( [], $errors );

		$this->assertContains( 'nettertech_events_attendee_email', $errors->get_error_codes() );
	}

	/**
	 * Test validate_forms passes for valid data.
	 *
	 * @return void
	 */
	public function test_validate_forms_passes_for_valid_data(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'cart-key-1' => [
				0 => [ 'name' => 'John Doe', 'email' => 'john@example.com' ],
				1 => [ 'name' => 'Jane Doe', 'email' => 'jane@example.com' ],
			],
		];

		Functions\when( 'is_email' )->justReturn( true );

		$errors = $this->create_mock_wp_error();
		$this->handler->validate_forms( [], $errors );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	/**
	 * Test validate_forms skips non-array attendee entries.
	 *
	 * @return void
	 */
	public function test_validate_forms_skips_non_array_entries(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'cart-key-1' => 'not-an-array',
		];

		$errors = $this->create_mock_wp_error();
		$this->handler->validate_forms( [], $errors );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	/**
	 * Test validate_forms skips non-array individual attendee data.
	 *
	 * @return void
	 */
	public function test_validate_forms_skips_non_array_individual_attendee(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'cart-key-1' => [
				0 => 'not-an-array',
			],
		];

		$errors = $this->create_mock_wp_error();
		$this->handler->validate_forms( [], $errors );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	// =========================================================================
	// save_order_item_data() Tests
	// =========================================================================

	/**
	 * Test save_order_item_data does nothing when no submitted data.
	 *
	 * @return void
	 */
	public function test_save_order_item_data_does_nothing_without_data(): void {
		$_POST = [];

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->expects( $this->never() )->method( 'add_meta_data' );

		$this->handler->save_order_item_data( $item, 'cart-key-1', [] );
	}

	/**
	 * Test save_order_item_data does nothing when cart key not in submitted data.
	 *
	 * @return void
	 */
	public function test_save_order_item_data_does_nothing_for_missing_cart_key(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'other-key' => [
				0 => [ 'name' => 'John', 'email' => 'john@example.com' ],
			],
		];

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->expects( $this->never() )->method( 'add_meta_data' );

		$this->handler->save_order_item_data( $item, 'cart-key-1', [] );
	}

	/**
	 * Test save_order_item_data saves attendee data as JSON meta.
	 *
	 * @return void
	 */
	public function test_save_order_item_data_saves_attendee_json(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'cart-key-1' => [
				0 => [ 'name' => 'John Doe', 'email' => 'john@example.com', 'phone' => '555-1234' ],
			],
		];

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->expects( $this->once() )
			->method( 'add_meta_data' )
			->with(
				'_nettertech_events_attendee_data',
				$this->callback( function ( $json ) {
					$data = json_decode( $json, true );
					return is_array( $data ) && count( $data ) === 1 && $data[0]['name'] === 'John Doe';
				} ),
				true
			);

		$this->handler->save_order_item_data( $item, 'cart-key-1', [] );
	}

	/**
	 * Test save_order_item_data includes custom fields when present.
	 *
	 * @return void
	 */
	public function test_save_order_item_data_includes_custom_fields(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'cart-key-1' => [
				0 => [
					'name'          => 'John',
					'email'         => 'john@example.com',
					'phone'         => '',
					'custom_fields' => [ 'diet' => 'Vegan' ],
				],
			],
		];

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->expects( $this->once() )
			->method( 'add_meta_data' )
			->with(
				'_nettertech_events_attendee_data',
				$this->callback( function ( $json ) {
					$data = json_decode( $json, true );
					return isset( $data[0]['custom_fields']['diet'] ) && $data[0]['custom_fields']['diet'] === 'Vegan';
				} ),
				true
			);

		$this->handler->save_order_item_data( $item, 'cart-key-1', [] );
	}

	/**
	 * Test save_order_item_data skips non-array entries.
	 *
	 * @return void
	 */
	public function test_save_order_item_data_skips_non_array_entries(): void {
		$_POST['nettertech_events_attendee_data'] = [
			'cart-key-1' => [
				0 => 'not-an-array',
			],
		];

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->expects( $this->never() )->method( 'add_meta_data' );

		$this->handler->save_order_item_data( $item, 'cart-key-1', [] );
	}
}
