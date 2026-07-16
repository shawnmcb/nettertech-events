<?php
/**
 * TicketTypeFieldHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\TicketTypeFieldHandler;
use NetterTechEvents\Models\TicketType;
use WP_REST_Request;

/**
 * Test TicketTypeFieldHandler field extraction, type coercion, and change tracking.
 */
class TicketTypeFieldHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Handler under test.
	 *
	 * @var TicketTypeFieldHandler
	 */
	private TicketTypeFieldHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );

		$this->handler = new TicketTypeFieldHandler();
	}

	/**
	 * Create a WP_REST_Request with parameters.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @return WP_REST_Request
	 */
	private function create_request( array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Create a TicketType with optional property overrides.
	 *
	 * @param array<string, mixed> $props Property overrides.
	 * @return TicketType
	 */
	private function create_ticket_type( array $props = array() ): TicketType {
		$ticket_type                = new TicketType();
		$ticket_type->id            = $props['id'] ?? 1;
		$ticket_type->name          = $props['name'] ?? 'General Admission';
		$ticket_type->description   = $props['description'] ?? null;
		$ticket_type->price         = $props['price'] ?? 25.00;
		$ticket_type->capacity_type = $props['capacity_type'] ?? 'fixed';
		$ticket_type->capacity      = $props['capacity'] ?? 100;
		$ticket_type->sale_start    = $props['sale_start'] ?? null;
		$ticket_type->sale_end      = $props['sale_end'] ?? null;
		$ticket_type->min_per_order = $props['min_per_order'] ?? 1;
		$ticket_type->max_per_order = $props['max_per_order'] ?? 10;
		$ticket_type->sort_order    = $props['sort_order'] ?? 0;
		$ticket_type->status        = $props['status'] ?? 'active';
		return $ticket_type;
	}

	// =========================================================================
	// apply_create_fields — Field Extraction
	// =========================================================================

	/**
	 * Test apply_create_fields sets name from request.
	 *
	 * @return void
	 */
	public function test_apply_create_fields_sets_name(): void {
		$request     = $this->create_request( array( 'name' => 'VIP Pass' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 'VIP Pass', $ticket_type->name );
	}

	/**
	 * Test apply_create_fields sets description from request.
	 *
	 * @return void
	 */
	public function test_apply_create_fields_sets_description(): void {
		$request     = $this->create_request( array( 'description' => 'Premium seating area' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 'Premium seating area', $ticket_type->description );
	}

	/**
	 * Test apply_create_fields sets all fields from a complete request.
	 *
	 * @return void
	 */
	public function test_apply_create_fields_sets_all_fields(): void {
		$request = $this->create_request(
			array(
				'name'          => 'VIP',
				'description'   => 'Front row',
				'price'         => 99.99,
				'capacity_type' => 'fixed',
				'capacity'      => 50,
				'sale_start'    => '2026-01-01 00:00:00',
				'sale_end'      => '2026-12-31 23:59:59',
				'min_per_order' => 2,
				'max_per_order' => 8,
				'sort_order'    => 5,
				'status'        => 'active',
			)
		);

		$ticket_type = new TicketType();
		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 'VIP', $ticket_type->name );
		$this->assertSame( 'Front row', $ticket_type->description );
		$this->assertSame( 99.99, $ticket_type->price );
		$this->assertSame( 'fixed', $ticket_type->capacity_type );
		$this->assertSame( 50, $ticket_type->capacity );
		$this->assertSame( '2026-01-01 00:00:00', $ticket_type->sale_start );
		$this->assertSame( '2026-12-31 23:59:59', $ticket_type->sale_end );
		$this->assertSame( 2, $ticket_type->min_per_order );
		$this->assertSame( 8, $ticket_type->max_per_order );
		$this->assertSame( 5, $ticket_type->sort_order );
		$this->assertSame( 'active', $ticket_type->status );
	}

	/**
	 * Test apply_create_fields skips fields not present in request.
	 *
	 * @return void
	 */
	public function test_apply_create_fields_skips_absent_fields(): void {
		$request     = $this->create_request( array( 'name' => 'Test' ) );
		$ticket_type = new TicketType();

		// Defaults from the model.
		$original_price       = $ticket_type->price;
		$original_sort_order  = $ticket_type->sort_order;
		$original_min_per     = $ticket_type->min_per_order;

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 'Test', $ticket_type->name );
		$this->assertSame( $original_price, $ticket_type->price );
		$this->assertSame( $original_sort_order, $ticket_type->sort_order );
		$this->assertSame( $original_min_per, $ticket_type->min_per_order );
	}

	// =========================================================================
	// apply_create_fields — Type Coercion
	// =========================================================================

	/**
	 * Test price string is coerced to float.
	 *
	 * @return void
	 */
	public function test_price_string_coerced_to_float(): void {
		$request     = $this->create_request( array( 'price' => '49.99' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 49.99, $ticket_type->price );
	}

	/**
	 * Test capacity string is coerced to nullable int.
	 *
	 * @return void
	 */
	public function test_capacity_string_coerced_to_int(): void {
		$request     = $this->create_request( array( 'capacity' => '200' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 200, $ticket_type->capacity );
	}

	/**
	 * Test capacity null stays null (nullable_int type).
	 *
	 * @return void
	 */
	public function test_capacity_null_stays_null(): void {
		$request     = $this->create_request( array( 'capacity' => null ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertNull( $ticket_type->capacity );
	}

	/**
	 * Test sort_order string is coerced to int.
	 *
	 * @return void
	 */
	public function test_sort_order_string_coerced_to_int(): void {
		$request     = $this->create_request( array( 'sort_order' => '3' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 3, $ticket_type->sort_order );
	}

	/**
	 * Test min_per_order is coerced to positive int (minimum 1).
	 *
	 * @return void
	 */
	public function test_min_per_order_coerced_to_positive_int(): void {
		$request     = $this->create_request( array( 'min_per_order' => '5' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 5, $ticket_type->min_per_order );
	}

	/**
	 * Test max_per_order is coerced to positive int (minimum 1).
	 *
	 * @return void
	 */
	public function test_max_per_order_coerced_to_positive_int(): void {
		$request     = $this->create_request( array( 'max_per_order' => '20' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 20, $ticket_type->max_per_order );
	}

	/**
	 * Test positive_int type clamps zero to 1.
	 *
	 * @return void
	 */
	public function test_positive_int_clamps_zero_to_one(): void {
		$request     = $this->create_request( array( 'min_per_order' => 0 ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 1, $ticket_type->min_per_order );
	}

	/**
	 * Test positive_int type clamps negative values to 1.
	 *
	 * @return void
	 */
	public function test_positive_int_clamps_negative_to_one(): void {
		$request     = $this->create_request( array( 'max_per_order' => -5 ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 1, $ticket_type->max_per_order );
	}

	/**
	 * Test price zero is accepted (free ticket).
	 *
	 * @return void
	 */
	public function test_price_zero_accepted_as_free(): void {
		$request     = $this->create_request( array( 'price' => 0 ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 0.0, $ticket_type->price );
	}

	/**
	 * Test price with many decimal places is cast to float.
	 *
	 * @return void
	 */
	public function test_price_decimal_precision(): void {
		$request     = $this->create_request( array( 'price' => '19.999' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 19.999, $ticket_type->price );
	}

	/**
	 * Test negative sort_order is accepted (int type, not positive_int).
	 *
	 * @return void
	 */
	public function test_negative_sort_order_accepted(): void {
		$request     = $this->create_request( array( 'sort_order' => -1 ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( -1, $ticket_type->sort_order );
	}

	// =========================================================================
	// apply_create_fields — Enum Validation
	// =========================================================================

	/**
	 * Test valid capacity_type values are accepted.
	 *
	 * @return void
	 */
	public function test_valid_capacity_type_accepted(): void {
		foreach ( array( 'fixed', 'unlimited', 'shared' ) as $type ) {
			$request     = $this->create_request( array( 'capacity_type' => $type ) );
			$ticket_type = new TicketType();

			$this->handler->apply_create_fields( $request, $ticket_type );

			$this->assertSame( $type, $ticket_type->capacity_type );
		}
	}

	/**
	 * Test invalid capacity_type is silently skipped.
	 *
	 * @return void
	 */
	public function test_invalid_capacity_type_skipped(): void {
		$request     = $this->create_request( array( 'capacity_type' => 'bogus' ) );
		$ticket_type = new TicketType();
		$original    = $ticket_type->capacity_type;

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( $original, $ticket_type->capacity_type );
	}

	/**
	 * Test valid status values are accepted.
	 *
	 * @return void
	 */
	public function test_valid_status_accepted(): void {
		foreach ( TicketType::STATUSES as $status ) {
			$request     = $this->create_request( array( 'status' => $status ) );
			$ticket_type = new TicketType();

			$this->handler->apply_create_fields( $request, $ticket_type );

			$this->assertSame( $status, $ticket_type->status );
		}
	}

	/**
	 * Test invalid status is silently skipped.
	 *
	 * @return void
	 */
	public function test_invalid_status_skipped(): void {
		$request     = $this->create_request( array( 'status' => 'deleted' ) );
		$ticket_type = new TicketType();
		$original    = $ticket_type->status;

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( $original, $ticket_type->status );
	}

	// =========================================================================
	// apply_update_fields — Change Tracking
	// =========================================================================

	/**
	 * Test apply_update_fields returns changes when values differ.
	 *
	 * @return void
	 */
	public function test_apply_update_fields_returns_changes(): void {
		$request     = $this->create_request( array( 'name' => 'New Name' ) );
		$ticket_type = $this->create_ticket_type( array( 'name' => 'Old Name' ) );

		$changes = $this->handler->apply_update_fields( $request, $ticket_type );

		$this->assertArrayHasKey( 'name', $changes );
		$this->assertSame( 'Old Name', $changes['name']['old'] );
		$this->assertSame( 'New Name', $changes['name']['new'] );
		$this->assertSame( 'New Name', $ticket_type->name );
	}

	/**
	 * Test apply_update_fields returns empty when no changes.
	 *
	 * @return void
	 */
	public function test_apply_update_fields_returns_empty_when_unchanged(): void {
		$request     = $this->create_request( array( 'name' => 'Same Name' ) );
		$ticket_type = $this->create_ticket_type( array( 'name' => 'Same Name' ) );

		$changes = $this->handler->apply_update_fields( $request, $ticket_type );

		$this->assertEmpty( $changes );
	}

	/**
	 * Test apply_update_fields tracks multiple field changes.
	 *
	 * @return void
	 */
	public function test_apply_update_fields_tracks_multiple_changes(): void {
		$request = $this->create_request(
			array(
				'name'  => 'Updated',
				'price' => 50.00,
			)
		);

		$ticket_type = $this->create_ticket_type(
			array(
				'name'  => 'Original',
				'price' => 25.00,
			)
		);

		$changes = $this->handler->apply_update_fields( $request, $ticket_type );

		$this->assertCount( 2, $changes );
		$this->assertArrayHasKey( 'name', $changes );
		$this->assertArrayHasKey( 'price', $changes );
	}

	/**
	 * Test apply_update_fields does not change model for absent fields.
	 *
	 * @return void
	 */
	public function test_apply_update_fields_ignores_absent_params(): void {
		$request     = $this->create_request( array( 'name' => 'New' ) );
		$ticket_type = $this->create_ticket_type(
			array(
				'name'  => 'Old',
				'price' => 25.00,
			)
		);

		$changes = $this->handler->apply_update_fields( $request, $ticket_type );

		$this->assertCount( 1, $changes );
		$this->assertArrayNotHasKey( 'price', $changes );
		$this->assertSame( 25.00, $ticket_type->price );
	}

	/**
	 * Test apply_update_fields uses loose comparison for change detection.
	 *
	 * The handler intentionally uses loose comparison (!=) so string "25" matches int 25.
	 *
	 * @return void
	 */
	public function test_apply_update_fields_loose_comparison_no_change(): void {
		$request     = $this->create_request( array( 'price' => 25.00 ) );
		$ticket_type = $this->create_ticket_type( array( 'price' => 25.00 ) );

		$changes = $this->handler->apply_update_fields( $request, $ticket_type );

		$this->assertEmpty( $changes );
	}

	/**
	 * Test apply_update_fields skips invalid enum on update.
	 *
	 * @return void
	 */
	public function test_apply_update_fields_skips_invalid_capacity_type(): void {
		$request     = $this->create_request( array( 'capacity_type' => 'invalid' ) );
		$ticket_type = $this->create_ticket_type( array( 'capacity_type' => 'fixed' ) );

		$changes = $this->handler->apply_update_fields( $request, $ticket_type );

		$this->assertEmpty( $changes );
		$this->assertSame( 'fixed', $ticket_type->capacity_type );
	}

	/**
	 * Test apply_update_fields skips invalid status on update.
	 *
	 * @return void
	 */
	public function test_apply_update_fields_skips_invalid_status(): void {
		$request     = $this->create_request( array( 'status' => 'nonexistent' ) );
		$ticket_type = $this->create_ticket_type( array( 'status' => 'active' ) );

		$changes = $this->handler->apply_update_fields( $request, $ticket_type );

		$this->assertEmpty( $changes );
		$this->assertSame( 'active', $ticket_type->status );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test empty string name is applied (validation is handled by Validator).
	 *
	 * @return void
	 */
	public function test_empty_string_name_applied(): void {
		$request     = $this->create_request( array( 'name' => '' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( '', $ticket_type->name );
	}

	/**
	 * Test very large price value.
	 *
	 * @return void
	 */
	public function test_very_large_price(): void {
		$request     = $this->create_request( array( 'price' => '999999.99' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		$this->assertSame( 999999.99, $ticket_type->price );
	}

	/**
	 * Test special characters in name are passed through sanitizer.
	 *
	 * @return void
	 */
	public function test_special_characters_in_name(): void {
		$request     = $this->create_request( array( 'name' => 'VIP & Early Bird <Special>' ) );
		$ticket_type = new TicketType();

		$this->handler->apply_create_fields( $request, $ticket_type );

		// sanitize_text_field is stubbed as returnArg(1), so passes through.
		$this->assertSame( 'VIP & Early Bird <Special>', $ticket_type->name );
	}

	/**
	 * Test empty request produces no changes on update.
	 *
	 * @return void
	 */
	public function test_empty_request_no_changes(): void {
		$request     = $this->create_request( array() );
		$ticket_type = $this->create_ticket_type();

		$changes = $this->handler->apply_update_fields( $request, $ticket_type );

		$this->assertEmpty( $changes );
	}
}
