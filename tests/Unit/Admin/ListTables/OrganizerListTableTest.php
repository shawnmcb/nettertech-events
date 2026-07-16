<?php
/**
 * OrganizerListTable unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\ListTables
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\ListTables;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\ListTables\OrganizerListTable;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Models\Organizer;

/**
 * Test OrganizerListTable functionality.
 *
 * Tests the organizer admin list table columns, actions, and rendering.
 */
class OrganizerListTableTest extends \NetterTechEventsTestCase {

	/**
	 * Mock organizer repository.
	 *
	 * @var OrganizerRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $organizer_repo;

	/**
	 * OrganizerListTable instance.
	 *
	 * @var OrganizerListTable
	 */
	private OrganizerListTable $list_table;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->organizer_repo = $this->createMock( OrganizerRepositoryInterface::class );

		$this->setup_wp_functions();

		$this->list_table = new OrganizerListTable( $this->organizer_repo );
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
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
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
		Functions\when( 'checked' )->alias(
			function ( $checked, $current = true, $display = true ) {
				$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
				if ( $display ) {
					echo $result;
				}
				return $result;
			}
		);
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
		$this->assertArrayHasKey( 'email', $columns );
		$this->assertArrayHasKey( 'phone', $columns );
		$this->assertArrayHasKey( 'events', $columns );
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
	 * Test get_columns name column.
	 *
	 * @return void
	 */
	public function test_get_columns_name_column(): void {
		$columns = $this->list_table->get_columns();

		$this->assertEquals( 'Name', $columns['name'] );
	}

	// =========================================================================
	// get_sortable_columns() Tests
	// =========================================================================

	/**
	 * Test get_sortable_columns returns expected columns.
	 *
	 * @return void
	 */
	public function test_get_sortable_columns_returns_name(): void {
		$columns = $this->list_table->get_sortable_columns();

		$this->assertArrayHasKey( 'name', $columns );
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
	 * Test column_cb renders checkbox with organizer ID.
	 *
	 * @return void
	 */
	public function test_column_cb_renders_checkbox(): void {
		$organizer     = new Organizer();
		$organizer->id = 42;

		$output = $this->list_table->column_cb( $organizer );

		$this->assertStringContainsString( 'value="42"', $output );
		$this->assertStringContainsString( 'type="checkbox"', $output );
	}

	/**
	 * Test column_name renders organizer name with edit link.
	 *
	 * @return void
	 */
	public function test_column_name_renders_name_with_link(): void {
		$organizer       = new Organizer();
		$organizer->id   = 1;
		$organizer->name = 'Test Organizer';

		$output = $this->list_table->column_name( $organizer );

		$this->assertStringContainsString( 'Test Organizer', $output );
		$this->assertStringContainsString( 'row-title', $output );
	}

	/**
	 * Test column_name includes edit and delete actions.
	 *
	 * @return void
	 */
	public function test_column_name_includes_actions(): void {
		$organizer       = new Organizer();
		$organizer->id   = 1;
		$organizer->name = 'Test Organizer';

		$output = $this->list_table->column_name( $organizer );

		$this->assertStringContainsString( 'Edit', $output );
		$this->assertStringContainsString( 'Delete', $output );
	}

	/**
	 * Test column_email renders email.
	 *
	 * @return void
	 */
	public function test_column_email_renders_email(): void {
		$organizer        = new Organizer();
		$organizer->id    = 1;
		$organizer->email = 'test@example.com';

		$output = $this->list_table->column_email( $organizer );

		$this->assertStringContainsString( 'test@example.com', $output );
	}

	/**
	 * Test column_email renders empty when no email.
	 *
	 * @return void
	 */
	public function test_column_email_handles_null(): void {
		$organizer        = new Organizer();
		$organizer->id    = 1;
		$organizer->email = null;

		$output = $this->list_table->column_email( $organizer );

		$this->assertEquals( '', $output );
	}

	/**
	 * Test column_phone renders phone.
	 *
	 * @return void
	 */
	public function test_column_phone_renders_phone(): void {
		$organizer        = new Organizer();
		$organizer->id    = 1;
		$organizer->phone = '555-1234';

		$output = $this->list_table->column_phone( $organizer );

		$this->assertStringContainsString( '555-1234', $output );
	}

	/**
	 * Test column_events renders count.
	 *
	 * @return void
	 */
	public function test_column_events_renders_zero_when_no_events(): void {
		$organizer     = new Organizer();
		$organizer->id = 1;

		$output = $this->list_table->column_events( $organizer );

		$this->assertEquals( '0', $output );
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

		$this->assertStringContainsString( 'No organizers found.', $output );
		$this->assertStringContainsString( 'Add New Organizer', $output );
	}
}
