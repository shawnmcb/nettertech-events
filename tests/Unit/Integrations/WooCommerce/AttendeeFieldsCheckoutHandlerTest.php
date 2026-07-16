<?php
/**
 * AttendeeFieldsCheckoutHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Integrations\WooCommerce\AttendeeFieldsCheckoutHandler;
use NetterTechEvents\Integrations\WooCommerce\CartHandler;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Services\AttendeeFieldService;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;
use NetterTechEvents\Tests\Factories\WooCommerceFactory;

/**
 * Test AttendeeFieldsCheckoutHandler functionality.
 *
 * Tests custom field validation and saving at classic WC checkout.
 */
class AttendeeFieldsCheckoutHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Handler under test.
	 *
	 * @var AttendeeFieldsCheckoutHandler
	 */
	private AttendeeFieldsCheckoutHandler $handler;

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
	 * Mock field service.
	 *
	 * @var AttendeeFieldService|Mockery\MockInterface
	 */
	private $field_service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cart_handler    = $this->createMock( CartHandler::class );
		$this->product_manager = $this->createMock( ProductManager::class );
		$this->field_service   = Mockery::mock( AttendeeFieldService::class );

		$this->handler = new AttendeeFieldsCheckoutHandler(
			$this->cart_handler,
			$this->product_manager,
			$this->field_service
		);

		TicketTypeFactory::reset();
		WooCommerceFactory::reset();
	}

	// =========================================================================
	// validate_fields() Tests
	// =========================================================================

	/**
	 * Test validate_fields does nothing when no submitted data.
	 *
	 * @return void
	 */
	public function test_validate_fields_does_nothing_without_submitted_data(): void {
		$_POST = [];
		$errors = new \WP_Error();

		$this->field_service->shouldNotReceive( 'validate_field_values' );

		$this->handler->validate_fields( [], $errors );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	/**
	 * Test validate_fields adds errors from field service.
	 *
	 * @return void
	 */
	public function test_validate_fields_adds_errors_from_field_service(): void {
		$field            = new AttendeeField();
		$field->field_key = 'name';
		$field->label     = 'Name';

		$_POST['nettertech_events_custom_fields'] = [
			'10_name' => '',
		];

		$this->field_service->shouldReceive( 'get_fields_for_events' )
			->andReturn( [ 10 => [ $field ] ] );

		$this->field_service->shouldReceive( 'validate_field_values' )
			->once()
			->with( 10, Mockery::type( 'array' ) )
			->andReturn( [ 'Name is required.' ] );

		$errors = new \WP_Error();
		$this->handler->validate_fields( [], $errors );

		$this->assertNotEmpty( $errors->get_error_codes() );
		$this->assertContains( 'nettertech_events_custom_field', $errors->get_error_codes() );
	}

	/**
	 * Test validate_fields passes for valid data.
	 *
	 * @return void
	 */
	public function test_validate_fields_passes_for_valid_data(): void {
		$field            = new AttendeeField();
		$field->field_key = 'name';
		$field->label     = 'Name';

		$_POST['nettertech_events_custom_fields'] = [
			'10_name' => 'John Doe',
		];

		$this->field_service->shouldReceive( 'get_fields_for_events' )
			->andReturn( [ 10 => [ $field ] ] );

		$this->field_service->shouldReceive( 'validate_field_values' )
			->once()
			->andReturn( [] );

		$errors = new \WP_Error();
		$this->handler->validate_fields( [], $errors );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	// =========================================================================
	// save_fields() Tests
	// =========================================================================

	/**
	 * Test save_fields does nothing when no submitted data.
	 *
	 * @return void
	 */
	public function test_save_fields_does_nothing_without_submitted_data(): void {
		$_POST = [];

		Functions\when( 'wc_get_order' )->justReturn( null );

		$this->handler->save_fields( 1 );

		// No assertions needed - just verifying no errors/exceptions.
		$this->assertTrue( true );
	}

	/**
	 * Test save_fields stores JSON data on order.
	 *
	 * @return void
	 */
	public function test_save_fields_stores_json_on_order(): void {
		$field            = new AttendeeField();
		$field->field_key = 'diet';
		$field->label     = 'Dietary';

		$_POST['nettertech_events_custom_fields'] = [
			'10_diet' => 'Vegan',
		];

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->field_service->shouldReceive( 'get_fields_for_events' )
			->andReturn( [ 10 => [ $field ] ] );

		$order = $this->createMock( \WC_Order::class );
		$order->expects( $this->once() )
			->method( 'update_meta_data' )
			->with(
				MetaKeys::CUSTOM_FIELD_DATA,
				$this->callback( function ( $json ) {
					$data = json_decode( $json, true );
					return isset( $data[10]['diet'] ) && $data[10]['diet'] === 'Vegan';
				} )
			);
		$order->expects( $this->once() )->method( 'save' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->handler->save_fields( 1 );
	}

	/**
	 * Test save_fields skips empty values.
	 *
	 * @return void
	 */
	public function test_save_fields_skips_empty_values(): void {
		$field            = new AttendeeField();
		$field->field_key = 'notes';
		$field->label     = 'Notes';

		$_POST['nettertech_events_custom_fields'] = [
			'10_notes' => '',
		];

		$this->field_service->shouldReceive( 'get_fields_for_events' )
			->andReturn( [ 10 => [ $field ] ] );

		// No values to save, so wc_get_order should not be needed.
		$this->handler->save_fields( 1 );

		$this->assertTrue( true );
	}
}
