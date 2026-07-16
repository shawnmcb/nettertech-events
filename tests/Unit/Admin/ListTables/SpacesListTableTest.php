<?php
/**
 * SpacesListTable unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\ListTables
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\ListTables;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\ListTables\SpacesListTable;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Models\Space;

/**
 * Test SpacesListTable functionality.
 *
 * Tests the spaces admin list table columns, actions, and rendering.
 */
class SpacesListTableTest extends \NetterTechEventsTestCase {

	/**
	 * Mock space repository.
	 *
	 * @var SpaceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $space_repo;

	/**
	 * SpacesListTable instance.
	 *
	 * @var SpacesListTable
	 */
	private SpacesListTable $list_table;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->space_repo = $this->createMock( SpaceRepositoryInterface::class );

		$this->setup_wp_functions();

		$this->list_table = new SpacesListTable( $this->space_repo );
	}

	/**
	 * Set up common WordPress function mocks.
	 *
	 * @return void
	 */
	private function setup_wp_functions(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) {
			echo $text;
		} );
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			function ( $url, $action = '' ) {
				return $url . '&_wpnonce=test123';
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url = '' ) {
				$query = http_build_query( $args );
				return $url . '?' . $query;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults = array() ) {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
	}

	// =========================================================================
	// get_columns() Tests
	// =========================================================================

	/**
	 * Test get_columns returns expected columns.
	 *
	 * @return void
	 */
	public function test_get_columns_returns_expected_columns(): void {
		$columns = $this->list_table->get_columns();

		$this->assertIsArray( $columns );
		$this->assertArrayHasKey( 'cb', $columns );
		$this->assertArrayHasKey( 'name', $columns );
		$this->assertArrayHasKey( 'capacity', $columns );
		$this->assertArrayHasKey( 'status', $columns );
	}

	/**
	 * Test get_columns includes checkbox.
	 *
	 * @return void
	 */
	public function test_get_columns_includes_checkbox(): void {
		$columns = $this->list_table->get_columns();

		$this->assertStringContainsString( 'checkbox', $columns['cb'] );
	}

	/**
	 * Test get_columns name column label.
	 *
	 * @return void
	 */
	public function test_get_columns_name_column(): void {
		$columns = $this->list_table->get_columns();

		$this->assertEquals( 'Name', $columns['name'] );
	}

	/**
	 * Test get_columns capacity column label.
	 *
	 * @return void
	 */
	public function test_get_columns_capacity_column(): void {
		$columns = $this->list_table->get_columns();

		$this->assertEquals( 'Capacity', $columns['capacity'] );
	}

	// =========================================================================
	// get_sortable_columns() Tests
	// =========================================================================

	/**
	 * Test get_sortable_columns returns name.
	 *
	 * @return void
	 */
	public function test_get_sortable_columns_returns_name(): void {
		$columns = $this->list_table->get_sortable_columns();

		$this->assertArrayHasKey( 'name', $columns );
	}

	/**
	 * Test get_sortable_columns returns capacity.
	 *
	 * @return void
	 */
	public function test_get_sortable_columns_returns_capacity(): void {
		$columns = $this->list_table->get_sortable_columns();

		$this->assertArrayHasKey( 'capacity', $columns );
	}

	// =========================================================================
	// get_bulk_actions() Tests
	// =========================================================================

	/**
	 * Test get_bulk_actions includes delete.
	 *
	 * @return void
	 */
	public function test_get_bulk_actions_includes_delete(): void {
		$actions = $this->list_table->get_bulk_actions();

		$this->assertArrayHasKey( 'delete', $actions );
	}

	// =========================================================================
	// Column Rendering Tests
	// =========================================================================

	/**
	 * Test column_cb renders checkbox with space ID.
	 *
	 * @return void
	 */
	public function test_column_cb_renders_checkbox(): void {
		$space     = new Space();
		$space->id = 42;

		$output = $this->list_table->column_cb( $space );

		$this->assertStringContainsString( 'value="42"', $output );
		$this->assertStringContainsString( 'type="checkbox"', $output );
	}

	/**
	 * Test column_name renders space name with edit link.
	 *
	 * @return void
	 */
	public function test_column_name_renders_name_with_link(): void {
		$space       = new Space();
		$space->id   = 1;
		$space->name = 'Main Hall';

		$output = $this->list_table->column_name( $space );

		$this->assertStringContainsString( 'Main Hall', $output );
		$this->assertStringContainsString( 'row-title', $output );
	}

	/**
	 * Test column_name includes edit and delete actions.
	 *
	 * @return void
	 */
	public function test_column_name_includes_actions(): void {
		$space       = new Space();
		$space->id   = 1;
		$space->name = 'Main Hall';

		$output = $this->list_table->column_name( $space );

		$this->assertStringContainsString( 'Edit', $output );
		$this->assertStringContainsString( 'Delete', $output );
	}

	/**
	 * Test column_capacity renders capacity number.
	 *
	 * @return void
	 */
	public function test_column_capacity_renders_capacity(): void {
		$space           = new Space();
		$space->id       = 1;
		$space->capacity = 200;

		$output = $this->list_table->column_capacity( $space );

		$this->assertEquals( '200', $output );
	}

	/**
	 * Test column_capacity renders zero.
	 *
	 * @return void
	 */
	public function test_column_capacity_renders_zero(): void {
		$space           = new Space();
		$space->id       = 1;
		$space->capacity = 0;

		$output = $this->list_table->column_capacity( $space );

		$this->assertEquals( '0', $output );
	}

	/**
	 * Test column_status renders status.
	 *
	 * @return void
	 */
	public function test_column_status_renders_status(): void {
		$space         = new Space();
		$space->id     = 1;
		$space->status = 'active';

		$output = $this->list_table->column_status( $space );

		$this->assertEquals( 'Active', $output );
	}

	/**
	 * Test column_status renders inactive status.
	 *
	 * @return void
	 */
	public function test_column_status_renders_inactive(): void {
		$space         = new Space();
		$space->id     = 1;
		$space->status = 'inactive';

		$output = $this->list_table->column_status( $space );

		$this->assertEquals( 'Inactive', $output );
	}

	// =========================================================================
	// no_items() Tests
	// =========================================================================

	/**
	 * Test no_items shows empty state message.
	 *
	 * @return void
	 */
	public function test_no_items_shows_empty_state(): void {
		ob_start();
		$this->list_table->no_items();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No spaces found.', $output );
		$this->assertStringContainsString( 'Add New Space', $output );
	}
}
