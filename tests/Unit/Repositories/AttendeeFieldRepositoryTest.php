<?php
/**
 * AttendeeFieldRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Repositories\AttendeeFieldRepository;

/**
 * Test AttendeeFieldRepository functionality.
 *
 * Uses dynamic wpdb mocks to verify SQL composition and behavior without
 * requiring a live database connection.
 *
 * @coversDefaultClass \NetterTechEvents\Repositories\AttendeeFieldRepository
 */
class AttendeeFieldRepositoryTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \PHPUnit\Framework\MockObject\MockObject|\wpdb
	 */
	private $mock_wpdb;

	/**
	 * Original wpdb instance.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->original_wpdb = $wpdb;

		$this->mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'update', 'delete', 'get_row', 'get_var', 'get_results', 'prepare' ) )
			->getMock();

		$this->mock_wpdb->prefix     = 'wp_';
		$this->mock_wpdb->insert_id  = 0;
		$this->mock_wpdb->last_error = '';

		$this->mock_wpdb->method( 'prepare' )
			->willReturnCallback(
				static function ( $sql, ...$args ) {
					return $sql;
				}
			);

		$wpdb = $this->mock_wpdb;

		Functions\when( 'sanitize_title' )->alias(
			static function ( $title ) {
				$title = strtolower( (string) $title );
				$title = preg_replace( '/[^a-z0-9 -]/', '', $title );
				$title = preg_replace( '/[\s-]+/', '-', $title );
				return trim( (string) $title, '-' );
			}
		);
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * Test repository can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate_repository(): void {
		$repo = new AttendeeFieldRepository( $this->mock_wpdb );
		$this->assertInstanceOf( AttendeeFieldRepository::class, $repo );
	}

	/**
	 * Test table name composed from Schema prefix.
	 *
	 * @return void
	 */
	public function test_repository_initializes_table_name(): void {
		$repo       = new AttendeeFieldRepository( $this->mock_wpdb );
		$reflection = new \ReflectionClass( $repo );
		$table      = $reflection->getProperty( 'table' )->getValue( $repo );

		$this->assertStringContainsString( 'attendee_fields', $table );
	}

	/**
	 * Test find returns null when no row exists.
	 *
	 * @return void
	 */
	public function test_find_returns_null_when_not_found(): void {
		$this->mock_wpdb->method( 'get_row' )->willReturn( null );

		$repo   = new AttendeeFieldRepository( $this->mock_wpdb );
		$result = $repo->find( 999 );

		$this->assertNull( $result );
	}

	/**
	 * Test find hydrates AttendeeField from row.
	 *
	 * @return void
	 */
	public function test_find_returns_hydrated_field(): void {
		$row             = new \stdClass();
		$row->id         = 42;
		$row->event_id   = 7;
		$row->field_key  = 'dietary';
		$row->field_type = 'text';
		$row->label      = 'Dietary needs';
		$row->sort_order = 3;

		$this->mock_wpdb->method( 'get_row' )->willReturn( $row );

		$repo  = new AttendeeFieldRepository( $this->mock_wpdb );
		$field = $repo->find( 42 );

		$this->assertInstanceOf( AttendeeField::class, $field );
		$this->assertSame( 42, $field->id );
		$this->assertSame( 7, $field->event_id );
		$this->assertSame( 'dietary', $field->field_key );
		$this->assertSame( 'Dietary needs', $field->label );
	}

	/**
	 * Test for_event returns array of fields from rows.
	 *
	 * @return void
	 */
	public function test_for_event_returns_array_of_fields(): void {
		$row1             = new \stdClass();
		$row1->id         = 1;
		$row1->event_id   = 7;
		$row1->field_key  = 'one';
		$row1->field_type = 'text';
		$row1->label      = 'One';

		$row2             = new \stdClass();
		$row2->id         = 2;
		$row2->event_id   = 7;
		$row2->field_key  = 'two';
		$row2->field_type = 'text';
		$row2->label      = 'Two';

		$this->mock_wpdb->method( 'get_results' )->willReturn( array( $row1, $row2 ) );

		$repo   = new AttendeeFieldRepository( $this->mock_wpdb );
		$fields = $repo->for_event( 7 );

		$this->assertCount( 2, $fields );
		$this->assertSame( 1, $fields[0]->id );
		$this->assertSame( 'two', $fields[1]->field_key );
	}

	/**
	 * Test for_event returns empty array when no rows.
	 *
	 * @return void
	 */
	public function test_for_event_returns_empty_when_no_rows(): void {
		$this->mock_wpdb->method( 'get_results' )->willReturn( array() );

		$repo   = new AttendeeFieldRepository( $this->mock_wpdb );
		$fields = $repo->for_event( 7 );

		$this->assertSame( array(), $fields );
	}

	/**
	 * Test save inserts new field and populates id.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_field_and_sets_id(): void {
		$this->mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturn( 1 );
		$this->mock_wpdb->insert_id = 123;

		// get_var is used by generate_field_key check; return 0 (no collision).
		$this->mock_wpdb->method( 'get_var' )->willReturn( '0' );

		$field            = new AttendeeField();
		$field->event_id  = 7;
		$field->label     = 'Some Label';
		$field->field_type = 'text';

		$repo  = new AttendeeFieldRepository( $this->mock_wpdb );
		$saved = $repo->save( $field );

		$this->assertSame( 123, $saved->id );
		$this->assertNotEmpty( $saved->field_key );
	}

	/**
	 * Test save updates existing field when id is set.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_field(): void {
		$this->mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->willReturn( 1 );

		$field             = new AttendeeField();
		$field->id         = 5;
		$field->event_id   = 7;
		$field->field_key  = 'existing';
		$field->label      = 'Existing';
		$field->field_type = 'text';

		$repo  = new AttendeeFieldRepository( $this->mock_wpdb );
		$saved = $repo->save( $field );

		$this->assertSame( 5, $saved->id );
	}

	/**
	 * Test save throws DatabaseException when insert fails.
	 *
	 * @return void
	 */
	public function test_save_throws_when_insert_fails(): void {
		$this->mock_wpdb->method( 'insert' )->willReturn( false );
		$this->mock_wpdb->method( 'get_var' )->willReturn( '0' );

		$field             = new AttendeeField();
		$field->event_id   = 7;
		$field->label      = 'Label';
		$field->field_type = 'text';

		$repo = new AttendeeFieldRepository( $this->mock_wpdb );

		$this->expectException( DatabaseException::class );
		$repo->save( $field );
	}

	/**
	 * Test save throws DatabaseException when update fails.
	 *
	 * @return void
	 */
	public function test_save_throws_when_update_fails(): void {
		$this->mock_wpdb->method( 'update' )->willReturn( false );

		$field             = new AttendeeField();
		$field->id         = 5;
		$field->event_id   = 7;
		$field->field_key  = 'existing';
		$field->label      = 'Existing';
		$field->field_type = 'text';

		$repo = new AttendeeFieldRepository( $this->mock_wpdb );

		$this->expectException( DatabaseException::class );
		$repo->save( $field );
	}

	/**
	 * Test save throws ValidationException on invalid field (missing label).
	 *
	 * @return void
	 */
	public function test_save_throws_validation_when_invalid(): void {
		$this->mock_wpdb->method( 'get_var' )->willReturn( '0' );

		$field             = new AttendeeField();
		$field->event_id   = 7;
		$field->field_type = 'text';
		// label intentionally missing.

		$repo = new AttendeeFieldRepository( $this->mock_wpdb );

		$this->expectException( ValidationException::class );
		$repo->save( $field );
	}

	/**
	 * Test delete returns true on successful delete.
	 *
	 * @return void
	 */
	public function test_delete_returns_true_on_success(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( 1 );

		$repo = new AttendeeFieldRepository( $this->mock_wpdb );
		$this->assertTrue( $repo->delete( 42 ) );
	}

	/**
	 * Test delete returns false when wpdb returns false.
	 *
	 * @return void
	 */
	public function test_delete_returns_false_on_failure(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( false );

		$repo = new AttendeeFieldRepository( $this->mock_wpdb );
		$this->assertFalse( $repo->delete( 42 ) );
	}

	/**
	 * Test delete_for_event returns deleted row count.
	 *
	 * @return void
	 */
	public function test_delete_for_event_returns_row_count(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( 3 );

		$repo = new AttendeeFieldRepository( $this->mock_wpdb );
		$this->assertSame( 3, $repo->delete_for_event( 7 ) );
	}

	/**
	 * Test delete_for_event returns zero on failure.
	 *
	 * @return void
	 */
	public function test_delete_for_event_returns_zero_on_failure(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( false );

		$repo = new AttendeeFieldRepository( $this->mock_wpdb );
		$this->assertSame( 0, $repo->delete_for_event( 7 ) );
	}

	/**
	 * Test reorder issues an update per id and returns true.
	 *
	 * @return void
	 */
	public function test_reorder_updates_each_field(): void {
		$this->mock_wpdb->expects( $this->exactly( 3 ) )
			->method( 'update' )
			->willReturn( 1 );

		$repo   = new AttendeeFieldRepository( $this->mock_wpdb );
		$result = $repo->reorder( 7, array( 10, 11, 12 ) );

		$this->assertTrue( $result );
	}

	/**
	 * Test reorder handles empty list.
	 *
	 * @return void
	 */
	public function test_reorder_handles_empty_list(): void {
		$this->mock_wpdb->expects( $this->never() )->method( 'update' );

		$repo = new AttendeeFieldRepository( $this->mock_wpdb );
		$this->assertTrue( $repo->reorder( 7, array() ) );
	}

	/**
	 * Test save auto-generates field_key from label when missing.
	 *
	 * @return void
	 */
	public function test_save_generates_field_key_from_label(): void {
		$this->mock_wpdb->method( 'insert' )->willReturn( 1 );
		$this->mock_wpdb->insert_id = 42;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '0' );

		$field             = new AttendeeField();
		$field->event_id   = 7;
		$field->label      = 'My Field Name';
		$field->field_type = 'text';

		$repo  = new AttendeeFieldRepository( $this->mock_wpdb );
		$saved = $repo->save( $field );

		$this->assertSame( 'my-field-name', $saved->field_key );
	}

	/**
	 * Test save appends counter when field_key already exists.
	 *
	 * @return void
	 */
	public function test_save_appends_counter_when_field_key_collides(): void {
		$this->mock_wpdb->method( 'insert' )->willReturn( 1 );
		$this->mock_wpdb->insert_id = 43;

		// First call: exists. Second call: doesn't.
		$this->mock_wpdb->method( 'get_var' )
			->willReturnOnConsecutiveCalls( '1', '0' );

		$field             = new AttendeeField();
		$field->event_id   = 7;
		$field->label      = 'Dup';
		$field->field_type = 'text';

		$repo  = new AttendeeFieldRepository( $this->mock_wpdb );
		$saved = $repo->save( $field );

		$this->assertSame( 'dup-1', $saved->field_key );
	}

	/**
	 * Test save uses fallback 'field' key when label is empty after sanitization.
	 *
	 * @return void
	 */
	public function test_save_uses_fallback_key_when_label_is_empty(): void {
		$this->mock_wpdb->method( 'insert' )->willReturn( 1 );
		$this->mock_wpdb->insert_id = 44;
		$this->mock_wpdb->method( 'get_var' )->willReturn( '0' );

		$field             = new AttendeeField();
		$field->event_id   = 7;
		$field->label      = '!!!@@@';  // sanitize_title strips to empty.
		$field->field_type = 'text';

		$repo  = new AttendeeFieldRepository( $this->mock_wpdb );
		$saved = $repo->save( $field );

		$this->assertSame( 'field', $saved->field_key );
	}
}
