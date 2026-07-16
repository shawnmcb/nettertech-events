<?php
/**
 * TicketTypeValidator unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\TicketTypeValidator;
use NetterTechEvents\Models\TicketType;
use WP_Error;
use WP_REST_Request;

/**
 * Test TicketTypeValidator validation rules, boundary conditions, and error reporting.
 */
class TicketTypeValidatorTest extends \NetterTechEventsTestCase {

	/**
	 * Validator under test.
	 *
	 * @var TicketTypeValidator
	 */
	private TicketTypeValidator $validator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);

		$this->validator = new TicketTypeValidator();
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

	// =========================================================================
	// Happy Path — Valid Inputs
	// =========================================================================

	/**
	 * Test valid create request passes validation.
	 *
	 * @return void
	 */
	public function test_valid_create_request_passes(): void {
		$request = $this->create_request(
			array(
				'name'          => 'General Admission',
				'occurrence_id' => 100,
				'scope'         => 'occurrence',
				'price'         => 25.00,
				'capacity_type' => 'fixed',
				'status'        => 'active',
				'min_per_order' => 1,
				'max_per_order' => 10,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	/**
	 * Test valid update request passes validation.
	 *
	 * @return void
	 */
	public function test_valid_update_request_passes(): void {
		$request = $this->create_request(
			array(
				'name'  => 'Updated Name',
				'price' => 50.00,
			)
		);

		$result = $this->validator->validate( $request, true );

		$this->assertTrue( $result );
	}

	/**
	 * Test minimal create request with just name and occurrence_id passes.
	 *
	 * @return void
	 */
	public function test_minimal_create_request_passes(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test Ticket',
				'occurrence_id' => 1,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	/**
	 * Test empty update request passes (no fields to validate).
	 *
	 * @return void
	 */
	public function test_empty_update_request_passes(): void {
		$request = $this->create_request( array() );

		$result = $this->validator->validate( $request, true );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// Required Fields — Create Only
	// =========================================================================

	/**
	 * Test missing name fails on create.
	 *
	 * @return void
	 */
	public function test_missing_name_fails_create(): void {
		$request = $this->create_request(
			array(
				'occurrence_id' => 100,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	/**
	 * Test empty name fails on create.
	 *
	 * @return void
	 */
	public function test_empty_name_fails_create(): void {
		$request = $this->create_request(
			array(
				'name'          => '',
				'occurrence_id' => 100,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	/**
	 * Test missing name is NOT checked on update.
	 *
	 * @return void
	 */
	public function test_missing_name_allowed_on_update(): void {
		$request = $this->create_request(
			array(
				'price' => 10.00,
			)
		);

		$result = $this->validator->validate( $request, true );

		$this->assertTrue( $result );
	}

	/**
	 * Test missing occurrence_id fails for occurrence scope on create.
	 *
	 * @return void
	 */
	public function test_missing_occurrence_id_fails_occurrence_scope(): void {
		$request = $this->create_request(
			array(
				'name'  => 'Test',
				'scope' => 'occurrence',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_occurrence_id', $result->get_error_code() );
	}

	/**
	 * Test default scope is occurrence when not specified.
	 *
	 * @return void
	 */
	public function test_default_scope_is_occurrence(): void {
		$request = $this->create_request(
			array(
				'name' => 'Test',
				// No scope, no occurrence_id -> defaults to 'occurrence' which requires occurrence_id.
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_occurrence_id', $result->get_error_code() );
	}

	/**
	 * Test missing event_id fails for event scope on create.
	 *
	 * @return void
	 */
	public function test_missing_event_id_fails_event_scope(): void {
		$request = $this->create_request(
			array(
				'name'  => 'Test',
				'scope' => 'event',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_event_id', $result->get_error_code() );
	}

	/**
	 * Test missing event_id fails for template scope on create.
	 *
	 * @return void
	 */
	public function test_missing_event_id_fails_template_scope(): void {
		$request = $this->create_request(
			array(
				'name'  => 'Test',
				'scope' => 'template',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_event_id', $result->get_error_code() );
	}

	/**
	 * Test event scope with event_id passes.
	 *
	 * @return void
	 */
	public function test_event_scope_with_event_id_passes(): void {
		$request = $this->create_request(
			array(
				'name'     => 'Season Pass',
				'scope'    => 'event',
				'event_id' => 50,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	/**
	 * Test template scope with event_id passes.
	 *
	 * @return void
	 */
	public function test_template_scope_with_event_id_passes(): void {
		$request = $this->create_request(
			array(
				'name'     => 'Template Ticket',
				'scope'    => 'template',
				'event_id' => 50,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// Enum Validation — scope
	// =========================================================================

	/**
	 * Test valid scope values are accepted.
	 *
	 * @return void
	 */
	public function test_valid_scopes_accepted(): void {
		$valid_scopes = array(
			'occurrence' => array( 'occurrence_id' => 100 ),
			'event'      => array( 'event_id' => 50 ),
			'template'   => array( 'event_id' => 50 ),
		);

		foreach ( $valid_scopes as $scope => $extra_params ) {
			$params = array_merge(
				array(
					'name'  => 'Test',
					'scope' => $scope,
				),
				$extra_params
			);

			$request = $this->create_request( $params );
			$result  = $this->validator->validate( $request, false );

			$this->assertTrue( $result, "Scope '{$scope}' should be valid" );
		}
	}

	/**
	 * Test invalid scope fails validation.
	 *
	 * @return void
	 */
	public function test_invalid_scope_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'scope'         => 'nonexistent',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_scope', $result->get_error_code() );
	}

	/**
	 * Test scope validation runs on update too.
	 *
	 * @return void
	 */
	public function test_invalid_scope_fails_on_update(): void {
		$request = $this->create_request(
			array(
				'scope' => 'bad_scope',
			)
		);

		$result = $this->validator->validate( $request, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_scope', $result->get_error_code() );
	}

	// =========================================================================
	// Enum Validation — capacity_type
	// =========================================================================

	/**
	 * Test valid capacity types are accepted.
	 *
	 * @return void
	 */
	public function test_valid_capacity_types_accepted(): void {
		foreach ( array( 'fixed', 'unlimited', 'shared' ) as $type ) {
			$request = $this->create_request(
				array(
					'name'          => 'Test',
					'occurrence_id' => 100,
					'capacity_type' => $type,
				)
			);

			$result = $this->validator->validate( $request, false );

			$this->assertTrue( $result, "Capacity type '{$type}' should be valid" );
		}
	}

	/**
	 * Test invalid capacity_type fails validation.
	 *
	 * @return void
	 */
	public function test_invalid_capacity_type_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'capacity_type' => 'elastic',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_capacity_type', $result->get_error_code() );
	}

	// =========================================================================
	// Enum Validation — status
	// =========================================================================

	/**
	 * Test valid statuses are accepted.
	 *
	 * @return void
	 */
	public function test_valid_statuses_accepted(): void {
		foreach ( TicketType::STATUSES as $status ) {
			$request = $this->create_request(
				array(
					'name'          => 'Test',
					'occurrence_id' => 100,
					'status'        => $status,
				)
			);

			$result = $this->validator->validate( $request, false );

			$this->assertTrue( $result, "Status '{$status}' should be valid" );
		}
	}

	/**
	 * Test invalid status fails validation.
	 *
	 * @return void
	 */
	public function test_invalid_status_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'status'        => 'deleted',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
	}

	/**
	 * Test enum field not present in request is skipped.
	 *
	 * @return void
	 */
	public function test_absent_enum_field_skipped(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				// No scope, capacity_type, or status.
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// Numeric Validation — price
	// =========================================================================

	/**
	 * Test price zero is valid (free ticket).
	 *
	 * @return void
	 */
	public function test_price_zero_valid(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Free Ticket',
				'occurrence_id' => 100,
				'price'         => 0,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	/**
	 * Test positive price is valid.
	 *
	 * @return void
	 */
	public function test_positive_price_valid(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'price'         => 99.99,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	/**
	 * Test negative price fails.
	 *
	 * @return void
	 */
	public function test_negative_price_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'price'         => -1,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_price', $result->get_error_code() );
	}

	/**
	 * Test non-numeric price fails.
	 *
	 * @return void
	 */
	public function test_non_numeric_price_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'price'         => 'free',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_price', $result->get_error_code() );
	}

	/**
	 * Test very large price is accepted.
	 *
	 * @return void
	 */
	public function test_very_large_price_accepted(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Platinum',
				'occurrence_id' => 100,
				'price'         => 999999.99,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	/**
	 * Test price string numeric value is accepted.
	 *
	 * @return void
	 */
	public function test_price_string_numeric_accepted(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'price'         => '25.50',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// Numeric Validation — min_per_order
	// =========================================================================

	/**
	 * Test min_per_order of 1 is valid.
	 *
	 * @return void
	 */
	public function test_min_per_order_one_valid(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'min_per_order' => 1,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	/**
	 * Test min_per_order of 0 fails.
	 *
	 * @return void
	 */
	public function test_min_per_order_zero_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'min_per_order' => 0,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_min_per_order', $result->get_error_code() );
	}

	/**
	 * Test min_per_order negative fails.
	 *
	 * @return void
	 */
	public function test_min_per_order_negative_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'min_per_order' => -3,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_min_per_order', $result->get_error_code() );
	}

	/**
	 * Test non-numeric min_per_order fails.
	 *
	 * @return void
	 */
	public function test_min_per_order_non_numeric_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'min_per_order' => 'abc',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_min_per_order', $result->get_error_code() );
	}

	// =========================================================================
	// Numeric Validation — max_per_order
	// =========================================================================

	/**
	 * Test max_per_order of 1 is valid.
	 *
	 * @return void
	 */
	public function test_max_per_order_one_valid(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'max_per_order' => 1,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	/**
	 * Test max_per_order of 0 fails.
	 *
	 * @return void
	 */
	public function test_max_per_order_zero_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'max_per_order' => 0,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_max_per_order', $result->get_error_code() );
	}

	/**
	 * Test max_per_order negative fails.
	 *
	 * @return void
	 */
	public function test_max_per_order_negative_fails(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'max_per_order' => -10,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_max_per_order', $result->get_error_code() );
	}

	/**
	 * Test large max_per_order is accepted.
	 *
	 * @return void
	 */
	public function test_large_max_per_order_accepted(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Bulk',
				'occurrence_id' => 100,
				'max_per_order' => 1000,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// Validation Order — First Error Returned
	// =========================================================================

	/**
	 * Test required field errors returned before enum errors.
	 *
	 * @return void
	 */
	public function test_required_errors_before_enum_errors(): void {
		$request = $this->create_request(
			array(
				// Missing name AND invalid scope.
				'scope'         => 'invalid',
				'occurrence_id' => 100,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		// Required field check happens before enum validation.
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	/**
	 * Test enum errors returned before numeric errors.
	 *
	 * @return void
	 */
	public function test_enum_errors_before_numeric_errors(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'scope'         => 'invalid_scope',
				'price'         => -10,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		// Enum validation runs before numeric validation.
		$this->assertSame( 'invalid_scope', $result->get_error_code() );
	}

	/**
	 * Test update skips required field checks but still validates enums.
	 *
	 * @return void
	 */
	public function test_update_skips_required_validates_enums(): void {
		$request = $this->create_request(
			array(
				'capacity_type' => 'nonexistent',
			)
		);

		$result = $this->validator->validate( $request, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_capacity_type', $result->get_error_code() );
	}

	/**
	 * Test update skips required but validates numeric.
	 *
	 * @return void
	 */
	public function test_update_validates_numeric_fields(): void {
		$request = $this->create_request(
			array(
				'price' => -5,
			)
		);

		$result = $this->validator->validate( $request, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_price', $result->get_error_code() );
	}

	// =========================================================================
	// WP_Error Response Format
	// =========================================================================

	/**
	 * Test WP_Error includes status 400 in error data.
	 *
	 * @return void
	 */
	public function test_wp_error_includes_status_400(): void {
		$request = $this->create_request(
			array(
				'occurrence_id' => 100,
				// Missing name.
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->error_data['missing_name']['status'] );
	}

	/**
	 * Test WP_Error message is readable.
	 *
	 * @return void
	 */
	public function test_wp_error_message_readable(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'price'         => -1,
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Price must be a non-negative number.', $result->get_error_message() );
	}

	/**
	 * Test enum WP_Error message includes valid values.
	 *
	 * @return void
	 */
	public function test_enum_error_message_includes_valid_values(): void {
		$request = $this->create_request(
			array(
				'name'          => 'Test',
				'occurrence_id' => 100,
				'capacity_type' => 'bogus',
			)
		);

		$result = $this->validator->validate( $request, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'fixed', $message );
		$this->assertStringContainsString( 'unlimited', $message );
		$this->assertStringContainsString( 'shared', $message );
	}
}
