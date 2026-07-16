<?php
/**
 * StoreApiExtension unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce\Blocks
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce\Blocks;

use Brain\Monkey\Functions;
use NetterTechEvents\Integrations\WooCommerce\Blocks\StoreApiExtension;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Services\AttendeeFieldService;

/**
 * Test Store API extension data callbacks and schema.
 *
 * @covers \NetterTechEvents\Integrations\WooCommerce\Blocks\StoreApiExtension
 */
class StoreApiExtensionTest extends \NetterTechEventsTestCase {

	/**
	 * @var ProductManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $product_manager;

	/**
	 * @var StoreApiExtension
	 */
	private StoreApiExtension $extension;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->product_manager = $this->createMock( ProductManager::class );
		$this->extension       = new StoreApiExtension( $this->product_manager );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	// =========================================================================
	// Schema Tests
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_schema_contains_all_required_fields(): void {
		$schema = $this->extension->get_cart_item_schema();

		$expected_fields = array(
			'is_event_ticket',
			'event_date',
			'event_time',
			'venue_name',
			'ticket_type',
				'event_permalink',
				'event_title',
				'attendee_fields',
				'collect_individual_attendees',
			);

		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey( $field, $schema, "Schema should have '{$field}' field" );
			$this->assertTrue( $schema[ $field ]['readonly'], "'{$field}' should be readonly" );
		}
	}

	/**
	 * @return void
	 */
	public function test_schema_types_are_correct(): void {
		$schema = $this->extension->get_cart_item_schema();

		$this->assertSame( 'boolean', $schema['is_event_ticket']['type'] );
		$this->assertSame( 'string', $schema['event_date']['type'] );
		$this->assertSame( 'string', $schema['event_time']['type'] );
		$this->assertSame( 'string', $schema['venue_name']['type'] );
			$this->assertSame( 'string', $schema['ticket_type']['type'] );
			$this->assertSame( 'string', $schema['event_permalink']['type'] );
			$this->assertSame( 'string', $schema['event_title']['type'] );
			$this->assertSame( 'array', $schema['attendee_fields']['type'] );
			$this->assertSame( 'boolean', $schema['collect_individual_attendees']['type'] );
		}

	/**
	 * @return void
	 */
	public function test_attendee_fields_schema_documents_public_contract(): void {
		$schema     = $this->extension->get_cart_item_schema();
		$properties = $schema['attendee_fields']['items']['properties'];

		$this->assertSame(
			array( 'field_key', 'field_type', 'label', 'placeholder', 'is_required', 'options' ),
			array_keys( $properties )
		);
		$this->assertArrayNotHasKey( 'id', $properties );
		$this->assertArrayNotHasKey( 'validation_rules', $properties );
		$this->assertArrayNotHasKey( 'description', $properties );
	}

	// =========================================================================
	// Data Callback Tests — Non-Ticket Items
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_non_ticket_product_returns_empty_data(): void {
		$product = $this->createMock( \WC_Product::class );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

		$data = $this->extension->get_cart_item_data( array( 'data' => $product ) );

		$this->assertFalse( $data['is_event_ticket'] );
		$this->assertSame( '', $data['event_date'] );
		$this->assertSame( '', $data['event_time'] );
		$this->assertSame( '', $data['venue_name'] );
		$this->assertSame( '', $data['ticket_type'] );
	}

	/**
	 * @return void
	 */
	public function test_missing_product_returns_empty_data(): void {
		$data = $this->extension->get_cart_item_data( array() );

		$this->assertFalse( $data['is_event_ticket'] );
	}

	/**
	 * @return void
	 */
	public function test_non_wc_product_returns_empty_data(): void {
		$data = $this->extension->get_cart_item_data( array( 'data' => 'not-a-product' ) );

		$this->assertFalse( $data['is_event_ticket'] );
	}

	// =========================================================================
	// Data Callback Tests — Ticket Items
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_ticket_product_returns_event_data(): void {
		$product = $this->createMock( \WC_Product::class );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		$event_mock = $this->createMock( \NetterTechEvents\Models\Event::class );
		$event_mock->method( 'get_permalink' )->willReturn( 'https://example.com/concert' );
		$event_mock->venue_name = 'The Bellwright';
		$event_mock->title      = 'Concert';

		$occurrence = $this->createMock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->method( 'get_formatted_date' )->willReturn( 'March 15, 2026' );
		$occurrence->method( 'get_formatted_time' )->willReturn( '7:00 PM' );
		$occurrence->method( 'get_event' )->willReturn( $event_mock );

		$ticket_type       = $this->createMock( \NetterTechEvents\Models\TicketType::class );
		$ticket_type->name = 'General Admission';

		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		$data = $this->extension->get_cart_item_data( array( 'data' => $product ) );

		$this->assertTrue( $data['is_event_ticket'] );
		$this->assertSame( 'March 15, 2026', $data['event_date'] );
		$this->assertSame( '7:00 PM', $data['event_time'] );
		$this->assertSame( 'The Bellwright', $data['venue_name'] );
		$this->assertSame( 'General Admission', $data['ticket_type'] );
		$this->assertSame( 'https://example.com/concert', $data['event_permalink'] );
		$this->assertSame( 'Concert', $data['event_title'] );
	}

	/**
	 * @return void
	 */
	public function test_ticket_without_occurrence_returns_partial_data(): void {
		$product = $this->createMock( \WC_Product::class );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

		$data = $this->extension->get_cart_item_data( array( 'data' => $product ) );

		$this->assertTrue( $data['is_event_ticket'] );
		$this->assertSame( '', $data['event_date'] );
		$this->assertSame( '', $data['event_time'] );
		$this->assertSame( '', $data['venue_name'] );
		$this->assertSame( '', $data['ticket_type'] );
	}

	/**
	 * @return void
	 */
	public function test_ticket_with_occurrence_without_event_returns_date_time_only(): void {
		$product = $this->createMock( \WC_Product::class );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		$occurrence = $this->createMock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->method( 'get_formatted_date' )->willReturn( 'March 15, 2026' );
		$occurrence->method( 'get_formatted_time' )->willReturn( '7:00 PM' );
		$occurrence->method( 'get_event' )->willReturn( null );

		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

		$data = $this->extension->get_cart_item_data( array( 'data' => $product ) );

		$this->assertTrue( $data['is_event_ticket'] );
		$this->assertSame( 'March 15, 2026', $data['event_date'] );
		$this->assertSame( '7:00 PM', $data['event_time'] );
		$this->assertSame( '', $data['venue_name'] );
		$this->assertSame( '', $data['event_permalink'] );
	}

	/**
	 * @return void
	 */
	public function test_attendee_fields_output_uses_public_contract_only(): void {
		$product = $this->createMock( \WC_Product::class );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );

		$ticket_type           = $this->createMock( \NetterTechEvents\Models\TicketType::class );
		$ticket_type->name     = 'General Admission';
		$ticket_type->event_id = 99;

		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		$field                   = new AttendeeField();
		$field->id               = 123;
		$field->event_id         = 99;
		$field->field_key        = 'meal_preference';
		$field->field_type       = 'select';
		$field->label            = 'Meal Preference';
		$field->placeholder      = 'Choose a meal';
		$field->description      = 'Internal planning note';
		$field->is_required      = true;
		$field->validation_rules = '{"internal":"rule"}';
		$field->set_options( array( 'Vegetarian', 'Standard' ) );

		$field_service = $this->createMock( AttendeeFieldService::class );
		$field_service->expects( $this->once() )
			->method( 'get_fields_for_events' )
			->with( array( 99 ) )
			->willReturn( array( 99 => array( $field ) ) );

		$extension = new StoreApiExtension( $this->product_manager, $field_service );
		$data      = $extension->get_cart_item_data( array( 'data' => $product ) );

		$this->assertCount( 1, $data['attendee_fields'] );
		$this->assertSame(
			array( 'field_key', 'field_type', 'label', 'placeholder', 'is_required', 'options' ),
			array_keys( $data['attendee_fields'][0] )
		);
		$this->assertSame( 'meal_preference', $data['attendee_fields'][0]['field_key'] );
		$this->assertSame( 'select', $data['attendee_fields'][0]['field_type'] );
		$this->assertSame( 'Meal Preference', $data['attendee_fields'][0]['label'] );
		$this->assertSame( 'Choose a meal', $data['attendee_fields'][0]['placeholder'] );
		$this->assertTrue( $data['attendee_fields'][0]['is_required'] );
		$this->assertSame( array( 'Vegetarian', 'Standard' ), $data['attendee_fields'][0]['options'] );
		$this->assertArrayNotHasKey( 'id', $data['attendee_fields'][0] );
		$this->assertArrayNotHasKey( 'description', $data['attendee_fields'][0] );
		$this->assertArrayNotHasKey( 'validation_rules', $data['attendee_fields'][0] );
	}

	// =========================================================================
	// Namespace Constant
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_namespace_constant(): void {
		$this->assertSame( 'nettertech-events', StoreApiExtension::NAMESPACE );
	}
}
