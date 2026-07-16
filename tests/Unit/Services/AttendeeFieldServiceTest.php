<?php
/**
 * AttendeeFieldService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Services\AttendeeFieldService;

/**
 * Test AttendeeFieldService functionality.
 *
 * Tests field resolution, validation, and persistence logic.
 */
class AttendeeFieldServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Service under test.
	 *
	 * @var AttendeeFieldService
	 */
	private AttendeeFieldService $service;

	/**
	 * Mock field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface|Mockery\MockInterface
	 */
	private $field_repo;

	/**
	 * Mock value repository.
	 *
	 * @var AttendeeFieldValueRepositoryInterface|Mockery\MockInterface
	 */
	private $value_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->field_repo = Mockery::mock( AttendeeFieldRepositoryInterface::class );
		$this->value_repo = Mockery::mock( AttendeeFieldValueRepositoryInterface::class );

		$this->service = new AttendeeFieldService(
			$this->field_repo,
			$this->value_repo
		);
	}

	// =========================================================================
	// get_fields_for_events() Tests
	// =========================================================================

	/**
	 * Test returns empty array when no events have fields.
	 *
	 * @return void
	 */
	public function test_get_fields_for_events_returns_empty_when_no_fields(): void {
		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [] );

		$result = $this->service->get_fields_for_events( [ 1 ] );

		$this->assertSame( [], $result );
	}

	/**
	 * Test returns fields grouped by event ID.
	 *
	 * @return void
	 */
	public function test_get_fields_for_events_groups_by_event_id(): void {
		$field1 = $this->create_field( 1, 'name', 'text', 'Name' );
		$field2 = $this->create_field( 2, 'diet', 'select', 'Dietary Requirements' );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field1 ] );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 20 )
			->andReturn( [ $field2 ] );

		$result = $this->service->get_fields_for_events( [ 10, 20 ] );

		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 10, $result );
		$this->assertArrayHasKey( 20, $result );
		$this->assertSame( 'name', $result[10][0]->field_key );
		$this->assertSame( 'diet', $result[20][0]->field_key );
	}

	/**
	 * Test deduplicates event IDs.
	 *
	 * @return void
	 */
	public function test_get_fields_for_events_deduplicates_event_ids(): void {
		$field = $this->create_field( 1, 'name', 'text', 'Name' );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->once()
			->andReturn( [ $field ] );

		$result = $this->service->get_fields_for_events( [ 10, 10, 10 ] );

		$this->assertCount( 1, $result );
	}

	/**
	 * Test skips events with empty fields.
	 *
	 * @return void
	 */
	public function test_get_fields_for_events_skips_events_without_fields(): void {
		$field = $this->create_field( 1, 'name', 'text', 'Name' );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 20 )
			->andReturn( [] );

		$result = $this->service->get_fields_for_events( [ 10, 20 ] );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 10, $result );
		$this->assertArrayNotHasKey( 20, $result );
	}

	// =========================================================================
	// validate_field_values() Tests
	// =========================================================================

	/**
	 * Test validation passes when all fields valid.
	 *
	 * @return void
	 */
	public function test_validate_field_values_passes_when_valid(): void {
		$field = $this->create_field( 1, 'name', 'text', 'Name', true );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'name' => 'John Doe' ] );

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validation fails for missing required field.
	 *
	 * @return void
	 */
	public function test_validate_field_values_fails_for_missing_required_field(): void {
		$field = $this->create_field( 1, 'name', 'text', 'Full Name', true );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'name' => '' ] );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Full Name', $errors[0] );
		$this->assertStringContainsString( 'required', $errors[0] );
	}

	/**
	 * Test validation fails for missing required field not in values.
	 *
	 * @return void
	 */
	public function test_validate_field_values_fails_for_absent_required_field(): void {
		$field = $this->create_field( 1, 'name', 'text', 'Full Name', true );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [] );

		$this->assertCount( 1, $errors );
	}

	/**
	 * Test validation passes for empty non-required field.
	 *
	 * @return void
	 */
	public function test_validate_field_values_passes_for_empty_optional_field(): void {
		$field = $this->create_field( 1, 'notes', 'textarea', 'Notes', false );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'notes' => '' ] );

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validation fails for invalid email.
	 *
	 * @return void
	 */
	public function test_validate_field_values_fails_for_invalid_email(): void {
		$field = $this->create_field( 1, 'contact_email', 'email', 'Contact Email', false );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		Functions\when( 'is_email' )->alias( function ( $email ) {
			return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
		} );

		$errors = $this->service->validate_field_values( 10, [ 'contact_email' => 'not-an-email' ] );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'email', strtolower( $errors[0] ) );
	}

	/**
	 * Test validation passes for valid email.
	 *
	 * @return void
	 */
	public function test_validate_field_values_passes_for_valid_email(): void {
		$field = $this->create_field( 1, 'contact_email', 'email', 'Contact Email', false );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		Functions\when( 'is_email' )->justReturn( true );

		$errors = $this->service->validate_field_values( 10, [ 'contact_email' => 'user@example.com' ] );

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validation fails for invalid URL.
	 *
	 * @return void
	 */
	public function test_validate_field_values_fails_for_invalid_url(): void {
		$field = $this->create_field( 1, 'website', 'url', 'Website', false );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'website' => 'not-a-url' ] );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'URL', $errors[0] );
	}

	/**
	 * Test validation passes for valid URL.
	 *
	 * @return void
	 */
	public function test_validate_field_values_passes_for_valid_url(): void {
		$field = $this->create_field( 1, 'website', 'url', 'Website', false );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'website' => 'https://example.com' ] );

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validation fails for non-numeric number field.
	 *
	 * @return void
	 */
	public function test_validate_field_values_fails_for_non_numeric_number(): void {
		$field = $this->create_field( 1, 'age', 'number', 'Age', false );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'age' => 'twenty-five' ] );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'number', strtolower( $errors[0] ) );
	}

	/**
	 * Test validation passes for valid number.
	 *
	 * @return void
	 */
	public function test_validate_field_values_passes_for_valid_number(): void {
		$field = $this->create_field( 1, 'age', 'number', 'Age', false );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'age' => '25' ] );

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validation fails for invalid select option.
	 *
	 * @return void
	 */
	public function test_validate_field_values_fails_for_invalid_select_option(): void {
		$field = $this->create_field( 1, 'size', 'select', 'T-Shirt Size', false );
		$field->options = json_encode( [ 'S', 'M', 'L', 'XL' ] );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'size' => 'XXS' ] );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'invalid selection', strtolower( $errors[0] ) );
	}

	/**
	 * Test validation passes for valid select option.
	 *
	 * @return void
	 */
	public function test_validate_field_values_passes_for_valid_select_option(): void {
		$field = $this->create_field( 1, 'size', 'select', 'T-Shirt Size', false );
		$field->options = json_encode( [ 'S', 'M', 'L', 'XL' ] );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'size' => 'L' ] );

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validation fails for invalid radio option.
	 *
	 * @return void
	 */
	public function test_validate_field_values_fails_for_invalid_radio_option(): void {
		$field = $this->create_field( 1, 'diet', 'radio', 'Dietary Preference', false );
		$field->options = json_encode( [ 'Vegan', 'Vegetarian', 'None' ] );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'diet' => 'Keto' ] );

		$this->assertCount( 1, $errors );
	}

	/**
	 * Test validation passes for unknown field type.
	 *
	 * @return void
	 */
	public function test_validate_field_values_passes_for_unknown_field_type(): void {
		$field = $this->create_field( 1, 'custom', 'custom_type', 'Custom', false );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$errors = $this->service->validate_field_values( 10, [ 'custom' => 'anything' ] );

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validation returns multiple errors for multiple invalid fields.
	 *
	 * @return void
	 */
	public function test_validate_field_values_returns_multiple_errors(): void {
		$field1 = $this->create_field( 1, 'name', 'text', 'Name', true );
		$field2 = $this->create_field( 2, 'email', 'email', 'Email', true );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field1, $field2 ] );

		$errors = $this->service->validate_field_values( 10, [ 'name' => '', 'email' => '' ] );

		$this->assertCount( 2, $errors );
	}

	// =========================================================================
	// save_field_values() Tests
	// =========================================================================

	/**
	 * Test save_field_values persists data.
	 *
	 * @return void
	 */
	public function test_save_field_values_persists_data(): void {
		$field1 = $this->create_field( 1, 'name', 'text', 'Name' );
		$field2 = $this->create_field( 2, 'email', 'email', 'Email' );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field1, $field2 ] );

		$this->value_repo->shouldReceive( 'save_values' )
			->once()
			->with( 100, Mockery::on( function ( $data ) {
				return $data[1] === 'John Doe' && $data[2] === 'john@example.com';
			} ) );

		$this->service->save_field_values(
			100,
			10,
			[ 'name' => 'John Doe', 'email' => 'john@example.com' ]
		);
	}

	/**
	 * Test save_field_values skips fields with null ID.
	 *
	 * @return void
	 */
	public function test_save_field_values_skips_fields_with_null_id(): void {
		$field = $this->create_field( null, 'name', 'text', 'Name' );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$this->value_repo->shouldNotReceive( 'save_values' );

		$this->service->save_field_values( 100, 10, [ 'name' => 'John' ] );
	}

	/**
	 * Test save_field_values skips null values.
	 *
	 * @return void
	 */
	public function test_save_field_values_skips_missing_values(): void {
		$field1 = $this->create_field( 1, 'name', 'text', 'Name' );
		$field2 = $this->create_field( 2, 'notes', 'textarea', 'Notes' );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field1, $field2 ] );

		$this->value_repo->shouldReceive( 'save_values' )
			->once()
			->with( 100, Mockery::on( function ( $data ) {
				return count( $data ) === 1 && isset( $data[1] );
			} ) );

		$this->service->save_field_values( 100, 10, [ 'name' => 'John' ] );
	}

	/**
	 * Test save_field_values does not call repo when no valid data.
	 *
	 * @return void
	 */
	public function test_save_field_values_does_not_save_when_no_matching_values(): void {
		$field = $this->create_field( 1, 'name', 'text', 'Name' );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$this->value_repo->shouldNotReceive( 'save_values' );

		$this->service->save_field_values( 100, 10, [ 'unknown_key' => 'value' ] );
	}

	/**
	 * Test save_field_values fires hook after saving.
	 *
	 * @return void
	 */
	public function test_save_field_values_fires_hook(): void {
		$field = $this->create_field( 1, 'name', 'text', 'Name' );

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 10 )
			->andReturn( [ $field ] );

		$this->value_repo->shouldReceive( 'save_values' )->once();

		$hook_fired = false;
		Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$hook_fired ) {
			if ( 'nettertech_events_custom_field_values_saved' === $hook ) {
				$hook_fired = true;
			}
		} );

		$this->service->save_field_values( 100, 10, [ 'name' => 'John' ] );

		$this->assertTrue( $hook_fired, 'Expected nettertech_events_custom_field_values_saved to fire' );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create an AttendeeField for testing.
	 *
	 * @param int|null $id          Field ID.
	 * @param string   $field_key   Field key.
	 * @param string   $field_type  Field type.
	 * @param string   $label       Display label.
	 * @param bool     $is_required Whether field is required.
	 * @return AttendeeField
	 */
	private function create_field(
		?int $id,
		string $field_key,
		string $field_type,
		string $label,
		bool $is_required = false
	): AttendeeField {
		$field              = new AttendeeField();
		$field->id          = $id;
		$field->event_id    = 10;
		$field->field_key   = $field_key;
		$field->field_type  = $field_type;
		$field->label       = $label;
		$field->is_required = $is_required;

		return $field;
	}
}
