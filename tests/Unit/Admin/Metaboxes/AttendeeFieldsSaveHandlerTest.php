<?php
/**
 * AttendeeFieldsSaveHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Models\AttendeeField;

/**
 * Test AttendeeFieldsSaveHandler functionality.
 *
 * Tests saving, updating, and deleting custom attendee field definitions.
 */
class AttendeeFieldsSaveHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Handler under test.
	 *
	 * @var AttendeeFieldsSaveHandler
	 */
	private AttendeeFieldsSaveHandler $handler;

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

		$this->handler = new AttendeeFieldsSaveHandler(
			$this->field_repo,
			$this->value_repo
		);

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save does nothing when no attendee_fields in POST.
	 *
	 * @return void
	 */
	public function test_save_does_nothing_when_no_post_data(): void {
		$_POST = [];

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [] );

		$this->field_repo->shouldNotReceive( 'save' );
		$this->field_repo->shouldNotReceive( 'delete' );

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );
	}

	/**
	 * Test save creates new field.
	 *
	 * @return void
	 */
	public function test_save_creates_new_field(): void {
		$_POST['attendee_fields'] = [
			[
				'label'       => 'Full Name',
				'field_type'  => 'text',
				'is_required' => '1',
			],
		];

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [] );

		$saved_field     = new AttendeeField();
		$saved_field->id = 10;

		$this->field_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $field ) {
				return $field->label === 'Full Name'
					&& $field->field_type === 'text'
					&& $field->is_required === true
					&& $field->event_id === 1
					&& $field->sort_order === 0;
			} ) )
			->andReturn( $saved_field );

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );
	}

	/**
	 * Test save updates existing field.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_field(): void {
		$existing        = new AttendeeField();
		$existing->id    = 5;
		$existing->label = 'Old Label';

		$_POST['attendee_fields'] = [
			[
				'id'         => '5',
				'label'      => 'New Label',
				'field_type' => 'email',
			],
		];

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [ $existing ] );

		$this->field_repo->shouldReceive( 'find' )
			->with( 5 )
			->andReturn( $existing );

		$saved        = new AttendeeField();
		$saved->id    = 5;
		$saved->label = 'New Label';

		$this->field_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $field ) {
				return $field->label === 'New Label' && $field->field_type === 'email';
			} ) )
			->andReturn( $saved );

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );
	}

	/**
	 * Test save deletes removed fields and their values.
	 *
	 * @return void
	 */
	public function test_save_deletes_removed_fields(): void {
		$existing1     = new AttendeeField();
		$existing1->id = 5;

		$existing2     = new AttendeeField();
		$existing2->id = 6;

		$_POST['attendee_fields'] = []; // No fields submitted.

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [ $existing1, $existing2 ] );

		// Both should be deleted with cascade value deletion.
		$this->value_repo->shouldReceive( 'delete_for_field' )->with( 5 )->once();
		$this->field_repo->shouldReceive( 'delete' )->with( 5 )->once();
		$this->value_repo->shouldReceive( 'delete_for_field' )->with( 6 )->once();
		$this->field_repo->shouldReceive( 'delete' )->with( 6 )->once();

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );
	}

	/**
	 * Test save skips entries without label.
	 *
	 * @return void
	 */
	public function test_save_skips_entries_without_label(): void {
		$_POST['attendee_fields'] = [
			[ 'label' => '', 'field_type' => 'text' ],
			[ 'field_type' => 'email' ], // No label key at all.
		];

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [] );

		$this->field_repo->shouldNotReceive( 'save' );

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );
	}

	/**
	 * Test save skips non-array entries.
	 *
	 * @return void
	 */
	public function test_save_skips_non_array_entries(): void {
		$_POST['attendee_fields'] = [
			'not-an-array',
		];

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [] );

		$this->field_repo->shouldNotReceive( 'save' );

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );
	}

	/**
	 * Test save handles options for select fields.
	 *
	 * @return void
	 */
	public function test_save_handles_options_for_select_fields(): void {
		$_POST['attendee_fields'] = [
			[
				'label'      => 'Shirt Size',
				'field_type' => 'select',
				'options'    => "Small\nMedium\nLarge",
			],
		];

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [] );

		$saved_field     = new AttendeeField();
		$saved_field->id = 10;

		$this->field_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $field ) {
				$options = $field->get_options();
				return count( $options ) === 3
					&& $options[0] === 'Small'
					&& $options[1] === 'Medium'
					&& $options[2] === 'Large';
			} ) )
			->andReturn( $saved_field );

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );
	}

	/**
	 * Test save assigns incremental sort order.
	 *
	 * @return void
	 */
	public function test_save_assigns_incremental_sort_order(): void {
		$_POST['attendee_fields'] = [
			[ 'label' => 'First', 'field_type' => 'text' ],
			[ 'label' => 'Second', 'field_type' => 'text' ],
			[ 'label' => 'Third', 'field_type' => 'text' ],
		];

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [] );

		$orders = [];
		$this->field_repo->shouldReceive( 'save' )
			->times( 3 )
			->with( Mockery::on( function ( $field ) use ( &$orders ) {
				$orders[] = $field->sort_order;
				return true;
			} ) )
			->andReturnUsing( function ( $field ) {
				$saved     = clone $field;
				$saved->id = rand( 10, 99 );
				return $saved;
			} );

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );

		$this->assertSame( [ 0, 1, 2 ], $orders );
	}

	/**
	 * Test save creates new field when existing field_id not found.
	 *
	 * @return void
	 */
	public function test_save_creates_new_field_when_existing_not_found(): void {
		$_POST['attendee_fields'] = [
			[
				'id'         => '999',
				'label'      => 'Orphan Field',
				'field_type' => 'text',
			],
		];

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [] );

		$this->field_repo->shouldReceive( 'find' )
			->with( 999 )
			->andReturn( null );

		$saved     = new AttendeeField();
		$saved->id = 50;

		$this->field_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $field ) {
				return $field->label === 'Orphan Field';
			} ) )
			->andReturn( $saved );

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );
	}

	/**
	 * Test save sets placeholder when provided.
	 *
	 * @return void
	 */
	public function test_save_sets_placeholder(): void {
		$_POST['attendee_fields'] = [
			[
				'label'       => 'Email',
				'field_type'  => 'email',
				'placeholder' => 'Enter your email',
			],
		];

		$this->field_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( [] );

		$saved     = new AttendeeField();
		$saved->id = 10;

		$this->field_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $field ) {
				return $field->placeholder === 'Enter your email';
			} ) )
			->andReturn( $saved );

		$this->handler->save( 1, is_array( $_POST['attendee_fields'] ?? null ) ? $_POST['attendee_fields'] : [] );
	}
}
