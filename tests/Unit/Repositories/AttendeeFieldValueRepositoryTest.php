<?php
/**
 * AttendeeFieldValueRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Models\AttendeeFieldValue;
use NetterTechEvents\Repositories\AttendeeFieldValueRepository;

/**
 * Test AttendeeFieldValueRepository functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Repositories\AttendeeFieldValueRepository
 */
class AttendeeFieldValueRepositoryTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \PHPUnit\Framework\MockObject\MockObject|\wpdb
	 */
	private $mock_wpdb;

	/**
	 * Original wpdb.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->original_wpdb = $wpdb;

		$this->mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'delete', 'get_results', 'prepare' ) )
			->getMock();

		$this->mock_wpdb->prefix = 'wp_';

		$this->mock_wpdb->method( 'prepare' )
			->willReturnCallback(
				static function ( $sql, ...$args ) {
					return $sql;
				}
			);

		$wpdb = $this->mock_wpdb;
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * Test instantiation.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$this->assertInstanceOf( AttendeeFieldValueRepository::class, $repo );
	}

	/**
	 * Test for_attendee returns hydrated values.
	 *
	 * @return void
	 */
	public function test_for_attendee_returns_hydrated_values(): void {
		$row1              = new \stdClass();
		$row1->id          = 1;
		$row1->attendee_id = 5;
		$row1->field_id    = 10;
		$row1->field_value = 'value-1';

		$row2              = new \stdClass();
		$row2->id          = 2;
		$row2->attendee_id = 5;
		$row2->field_id    = 11;
		$row2->field_value = 'value-2';

		$this->mock_wpdb->method( 'get_results' )->willReturn( array( $row1, $row2 ) );

		$repo   = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$values = $repo->for_attendee( 5 );

		$this->assertCount( 2, $values );
		$this->assertContainsOnlyInstancesOf( AttendeeFieldValue::class, $values );
		$this->assertSame( 'value-1', $values[0]->field_value );
		$this->assertSame( 11, $values[1]->field_id );
	}

	/**
	 * Test for_attendee returns empty array when no rows.
	 *
	 * @return void
	 */
	public function test_for_attendee_returns_empty_when_no_rows(): void {
		$this->mock_wpdb->method( 'get_results' )->willReturn( array() );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$this->assertSame( array(), $repo->for_attendee( 5 ) );
	}

	/**
	 * Test save_values issues one query per field value.
	 *
	 * @return void
	 */
	public function test_save_values_issues_query_per_field(): void {
		$this->mock_wpdb->expects( $this->exactly( 3 ) )
			->method( 'query' )
			->willReturn( 1 );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$repo->save_values(
			7,
			array(
				1 => 'a',
				2 => 'b',
				3 => 'c',
			)
		);
	}

	/**
	 * Test save_values is a no-op with empty array.
	 *
	 * @return void
	 */
	public function test_save_values_is_noop_with_empty_array(): void {
		$this->mock_wpdb->expects( $this->never() )->method( 'query' );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$repo->save_values( 7, array() );
	}

	/**
	 * Test save_values accepts null values (optional fields).
	 *
	 * @return void
	 */
	public function test_save_values_accepts_null_values(): void {
		$this->mock_wpdb->expects( $this->exactly( 2 ) )
			->method( 'query' )
			->willReturn( 1 );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$repo->save_values(
			7,
			array(
				1 => 'value',
				2 => null,
			)
		);
	}

	/**
	 * Test delete_for_attendee returns affected rows.
	 *
	 * @return void
	 */
	public function test_delete_for_attendee_returns_row_count(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( 4 );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$this->assertSame( 4, $repo->delete_for_attendee( 5 ) );
	}

	/**
	 * Test delete_for_attendee returns 0 on failure.
	 *
	 * @return void
	 */
	public function test_delete_for_attendee_returns_zero_on_failure(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( false );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$this->assertSame( 0, $repo->delete_for_attendee( 5 ) );
	}

	/**
	 * Test delete_for_field returns affected rows.
	 *
	 * @return void
	 */
	public function test_delete_for_field_returns_row_count(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( 2 );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$this->assertSame( 2, $repo->delete_for_field( 99 ) );
	}

	/**
	 * Test delete_for_field returns 0 on failure.
	 *
	 * @return void
	 */
	public function test_delete_for_field_returns_zero_on_failure(): void {
		$this->mock_wpdb->method( 'delete' )->willReturn( false );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$this->assertSame( 0, $repo->delete_for_field( 99 ) );
	}

	/**
	 * Test get_values_map returns field_id => value map.
	 *
	 * @return void
	 */
	public function test_get_values_map_returns_field_keyed_map(): void {
		$row1              = new \stdClass();
		$row1->id          = 1;
		$row1->attendee_id = 5;
		$row1->field_id    = 10;
		$row1->field_value = 'A';

		$row2              = new \stdClass();
		$row2->id          = 2;
		$row2->attendee_id = 5;
		$row2->field_id    = 11;
		$row2->field_value = 'B';

		$this->mock_wpdb->method( 'get_results' )->willReturn( array( $row1, $row2 ) );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$map  = $repo->get_values_map( 5 );

		$this->assertSame(
			array(
				10 => 'A',
				11 => 'B',
			),
			$map
		);
	}

	/**
	 * Test get_values_map returns empty when attendee has no values.
	 *
	 * @return void
	 */
	public function test_get_values_map_returns_empty_for_no_values(): void {
		$this->mock_wpdb->method( 'get_results' )->willReturn( array() );

		$repo = new AttendeeFieldValueRepository( $this->mock_wpdb );
		$this->assertSame( array(), $repo->get_values_map( 5 ) );
	}
}
