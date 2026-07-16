<?php
/**
 * ActivityLogPage unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\ActivityLogPage;
use NetterTechEvents\Models\ActivityLog;
use NetterTechEvents\Services\ActivityLogService;

/**
 * Test ActivityLogPage functionality.
 *
 * Tests OWASP A09 activity log admin page.
 */
class ActivityLogPageTest extends \NetterTechEventsTestCase {

	/**
	 * Mock activity log service.
	 *
	 * @var ActivityLogService|Mockery\MockInterface
	 */
	private $mock_service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mock_service = Mockery::mock( ActivityLogService::class );

		// Set up common WordPress function mocks.
		$this->mock_common_wp_functions();
	}

	/**
	 * Mock common WordPress functions.
	 *
	 * @return void
	 */
	private function mock_common_wp_functions(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->returnArg( 2 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin.php?page=nettertech-events-activity-log' );
		Functions\when( 'add_query_arg' )->justReturn( 'http://example.com/wp-admin/admin.php?page=nettertech-events-activity-log&paged=2' );
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, $echo = true ) {
				$result = $selected == $current ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'paginate_links' )->justReturn( '<a href="#">1</a><a href="#">2</a>' );
		Functions\when( 'get_edit_user_link' )->justReturn( 'http://example.com/wp-admin/user-edit.php?user_id=1' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'wp_date' )->justReturn( '2026-01-11 12:00:00' );
		Functions\when( 'get_userdata' )->alias(
			function ( $user_id ) {
				if ( $user_id > 0 ) {
					return (object) array( 'display_name' => 'Test User' );
				}
				return false;
			}
		);
	}

	/**
	 * Create a mock ActivityLog object.
	 *
	 * @param int    $id          Log ID.
	 * @param string $action      Action type.
	 * @param string $object_type Object type.
	 * @param int    $user_id     User ID.
	 * @return ActivityLog
	 */
	private function create_mock_activity_log(
		int $id = 1,
		string $action = 'create',
		string $object_type = 'event',
		int $user_id = 1
	): ActivityLog {
		return new ActivityLog(
			$id,
			$user_id,
			$action,
			$object_type,
			100,
			'Test Object',
			null,
			'192.168.1.1',
			'Mozilla/5.0 Test',
			gmdate( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Create a mock ActivityLog with custom/null user_id.
	 *
	 * @param int|null $user_id User ID or null.
	 * @return ActivityLog
	 */
	private function create_mock_activity_log_custom( ?int $user_id ): ActivityLog {
		return new ActivityLog(
			1,
			$user_id,
			'create',
			'event',
			100,
			'Test Object',
			null,
			'192.168.1.1',
			'Mozilla/5.0 Test',
			gmdate( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Create a mock ActivityLog with null IP address.
	 *
	 * @return ActivityLog
	 */
	private function create_mock_activity_log_with_null_ip(): ActivityLog {
		return new ActivityLog(
			1,
			1,
			'create',
			'event',
			100,
			'Test Object',
			null,
			null,
			'Mozilla/5.0 Test',
			gmdate( 'Y-m-d H:i:s' )
		);
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test ActivityLogPage can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$this->assertInstanceOf( ActivityLogPage::class, $page );
	}

	/**
	 * Test constructor stores service.
	 *
	 * @return void
	 */
	public function test_constructor_stores_service(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$property   = $reflection->getProperty( 'service' );

		$this->assertSame( $this->mock_service, $property->getValue( $page ) );
	}

	/**
	 * Test per_page property default value.
	 *
	 * @return void
	 */
	public function test_per_page_default_value(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$property   = $reflection->getProperty( 'per_page' );

		$this->assertEquals( 25, $property->getValue( $page ) );
	}

	// =========================================================================
	// render() Tests
	// =========================================================================

	/**
	 * Test render dies without permission.
	 *
	 * @return void
	 */
	public function test_render_dies_without_permission(): void {
		$page = new ActivityLogPage( $this->mock_service );

		Functions\when( 'current_user_can' )->justReturn( false );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$page->render();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test render calls service get_logs with correct parameters.
	 *
	 * @return void
	 */
	public function test_render_calls_service_get_logs(): void {
		$page = new ActivityLogPage( $this->mock_service );

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->mock_service
			->shouldReceive( 'get_logs' )
			->once()
			->with( 1, 25, Mockery::type( 'array' ) )
			->andReturn(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->once()
			->andReturn( array( 'create', 'update', 'delete' ) );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->once()
			->andReturn( array( 'event', 'attendee' ) );

		ob_start();
		$page->render();
		ob_get_clean();

		// Assertions are handled by Mockery expectations.
		$this->assertTrue( true );
	}

	/**
	 * Test render uses page parameter from request.
	 *
	 * @return void
	 */
	public function test_render_uses_page_from_request(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$_GET['paged'] = '3';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->mock_service
			->shouldReceive( 'get_logs' )
			->once()
			->with( 3, 25, Mockery::type( 'array' ) )
			->andReturn(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array() );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array() );

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertTrue( true );
	}

	/**
	 * Test render enforces minimum page of 1.
	 *
	 * @return void
	 */
	public function test_render_enforces_minimum_page(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$_GET['paged'] = '-5';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->mock_service
			->shouldReceive( 'get_logs' )
			->once()
			->with( 1, 25, Mockery::type( 'array' ) )
			->andReturn(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array() );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array() );

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertTrue( true );
	}

	/**
	 * Test render outputs HTML with wrap class.
	 *
	 * @return void
	 */
	public function test_render_outputs_html_with_wrap_class(): void {
		$page = new ActivityLogPage( $this->mock_service );

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->mock_service
			->shouldReceive( 'get_logs' )
			->andReturn(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array() );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array() );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="wrap"', $output );
	}

	/**
	 * Test render outputs Activity Log heading.
	 *
	 * @return void
	 */
	public function test_render_outputs_activity_log_heading(): void {
		$page = new ActivityLogPage( $this->mock_service );

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->mock_service
			->shouldReceive( 'get_logs' )
			->andReturn(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array() );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array() );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Activity Log', $output );
	}

	/**
	 * Test render outputs OWASP A09 description.
	 *
	 * @return void
	 */
	public function test_render_outputs_owasp_description(): void {
		$page = new ActivityLogPage( $this->mock_service );

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->mock_service
			->shouldReceive( 'get_logs' )
			->andReturn(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array() );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array() );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'OWASP A09', $output );
	}

	/**
	 * Test render outputs no entries notice when empty.
	 *
	 * @return void
	 */
	public function test_render_outputs_no_entries_notice_when_empty(): void {
		$page = new ActivityLogPage( $this->mock_service );

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->mock_service
			->shouldReceive( 'get_logs' )
			->andReturn(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array() );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array() );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No activity log entries found', $output );
	}

	/**
	 * Test render outputs table when items exist.
	 *
	 * @return void
	 */
	public function test_render_outputs_table_when_items_exist(): void {
		$page = new ActivityLogPage( $this->mock_service );

		Functions\when( 'current_user_can' )->justReturn( true );

		$log = $this->create_mock_activity_log();

		$this->mock_service
			->shouldReceive( 'get_logs' )
			->andReturn(
				array(
					'items' => array( $log ),
					'total' => 1,
					'pages' => 1,
				)
			);

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array( 'create' ) );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array( 'event' ) );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-activity-log-table', $output );
	}

	// =========================================================================
	// get_filters_from_request() Tests
	// =========================================================================

	/**
	 * Test get_filters_from_request returns empty array with no params.
	 *
	 * @return void
	 */
	public function test_get_filters_from_request_empty_by_default(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_filters_from_request' );

		$_GET = array();

		$result = $method->invoke( $page );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_filters_from_request extracts action_type filter.
	 *
	 * @return void
	 */
	public function test_get_filters_from_request_extracts_action_type(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_filters_from_request' );

		$_GET['action_type'] = 'create';

		$result = $method->invoke( $page );

		$this->assertArrayHasKey( 'action', $result );
		$this->assertEquals( 'create', $result['action'] );
	}

	/**
	 * Test get_filters_from_request extracts object_type filter.
	 *
	 * @return void
	 */
	public function test_get_filters_from_request_extracts_object_type(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_filters_from_request' );

		$_GET['object_type'] = 'event';

		$result = $method->invoke( $page );

		$this->assertArrayHasKey( 'object_type', $result );
		$this->assertEquals( 'event', $result['object_type'] );
	}

	/**
	 * Test get_filters_from_request extracts user_id filter.
	 *
	 * @return void
	 */
	public function test_get_filters_from_request_extracts_user_id(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_filters_from_request' );

		$_GET['user_id'] = '42';

		$result = $method->invoke( $page );

		$this->assertArrayHasKey( 'user_id', $result );
		$this->assertEquals( 42, $result['user_id'] );
	}

	/**
	 * Test get_filters_from_request extracts date_from filter.
	 *
	 * @return void
	 */
	public function test_get_filters_from_request_extracts_date_from(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_filters_from_request' );

		$_GET['date_from'] = '2026-01-01';

		$result = $method->invoke( $page );

		$this->assertArrayHasKey( 'date_from', $result );
		$this->assertEquals( '2026-01-01', $result['date_from'] );
	}

	/**
	 * Test get_filters_from_request extracts date_to filter.
	 *
	 * @return void
	 */
	public function test_get_filters_from_request_extracts_date_to(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_filters_from_request' );

		$_GET['date_to'] = '2026-12-31';

		$result = $method->invoke( $page );

		$this->assertArrayHasKey( 'date_to', $result );
		$this->assertEquals( '2026-12-31', $result['date_to'] );
	}

	/**
	 * Test get_filters_from_request extracts search filter.
	 *
	 * @return void
	 */
	public function test_get_filters_from_request_extracts_search(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_filters_from_request' );

		$_GET['s'] = 'test search';

		$result = $method->invoke( $page );

		$this->assertArrayHasKey( 'search', $result );
		$this->assertEquals( 'test search', $result['search'] );
	}

	/**
	 * Test get_filters_from_request extracts multiple filters.
	 *
	 * @return void
	 */
	public function test_get_filters_from_request_extracts_multiple_filters(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_filters_from_request' );

		$_GET['action_type'] = 'update';
		$_GET['object_type'] = 'attendee';
		$_GET['date_from']   = '2026-01-01';
		$_GET['s']           = 'John';

		$result = $method->invoke( $page );

		$this->assertCount( 4, $result );
		$this->assertEquals( 'update', $result['action'] );
		$this->assertEquals( 'attendee', $result['object_type'] );
		$this->assertEquals( '2026-01-01', $result['date_from'] );
		$this->assertEquals( 'John', $result['search'] );
	}

	/**
	 * Test get_filters_from_request ignores empty values.
	 *
	 * @return void
	 */
	public function test_get_filters_from_request_ignores_empty_values(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_filters_from_request' );

		$_GET['action_type'] = '';
		$_GET['object_type'] = '';
		$_GET['user_id']     = '';

		$result = $method->invoke( $page );

		$this->assertEmpty( $result );
	}

	// =========================================================================
	// render_page() Tests
	// =========================================================================

	/**
	 * Test render_page outputs filter form.
	 *
	 * @return void
	 */
	public function test_render_page_outputs_filter_form(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array() );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array() );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_page' );

		ob_start();
		$method->invoke(
			$page,
			array(
				'items' => array(),
				'total' => 0,
				'pages' => 0,
			),
			array(),
			1
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-activity-log-filters', $output );
	}

	/**
	 * Test render_page outputs inline styles.
	 *
	 * @return void
	 */
	public function test_render_page_outputs_inline_styles(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array() );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array() );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_page' );

		ob_start();
		$method->invoke(
			$page,
			array(
				'items' => array(),
				'total' => 0,
				'pages' => 0,
			),
			array(),
			1
		);
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<style>', $output );
		$this->assertStringContainsString( 'nte-activity-log-filters', $output );
	}

	// =========================================================================
	// render_filters() Tests
	// =========================================================================

	/**
	 * Test render_filters outputs action select.
	 *
	 * @return void
	 */
	public function test_render_filters_outputs_action_select(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_filters' );

		ob_start();
		$method->invoke(
			$page,
			array(),
			array( 'create', 'update', 'delete' ),
			array( 'event' )
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="action_type"', $output );
		$this->assertStringContainsString( 'All Actions', $output );
	}

	/**
	 * Test render_filters outputs object type select.
	 *
	 * @return void
	 */
	public function test_render_filters_outputs_object_type_select(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_filters' );

		ob_start();
		$method->invoke(
			$page,
			array(),
			array( 'create' ),
			array( 'event', 'attendee' )
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="object_type"', $output );
		$this->assertStringContainsString( 'All Types', $output );
	}

	/**
	 * Test render_filters outputs date inputs.
	 *
	 * @return void
	 */
	public function test_render_filters_outputs_date_inputs(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_filters' );

		ob_start();
		$method->invoke(
			$page,
			array(),
			array(),
			array()
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="date_from"', $output );
		$this->assertStringContainsString( 'id="date_to"', $output );
		$this->assertStringContainsString( 'type="date"', $output );
	}

	/**
	 * Test render_filters outputs search input.
	 *
	 * @return void
	 */
	public function test_render_filters_outputs_search_input(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_filters' );

		ob_start();
		$method->invoke(
			$page,
			array(),
			array(),
			array()
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="s"', $output );
		$this->assertStringContainsString( 'type="search"', $output );
	}

	/**
	 * Test render_filters outputs filter and reset buttons.
	 *
	 * @return void
	 */
	public function test_render_filters_outputs_buttons(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_filters' );

		ob_start();
		$method->invoke(
			$page,
			array(),
			array(),
			array()
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Filter', $output );
		$this->assertStringContainsString( 'Reset', $output );
	}

	/**
	 * Test render_filters preserves filter values.
	 *
	 * @return void
	 */
	public function test_render_filters_preserves_values(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_filters' );

		ob_start();
		$method->invoke(
			$page,
			array(
				'date_from' => '2026-01-15',
				'search'    => 'test query',
			),
			array(),
			array()
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( '2026-01-15', $output );
		$this->assertStringContainsString( 'test query', $output );
	}

	// =========================================================================
	// render_table() Tests
	// =========================================================================

	/**
	 * Test render_table outputs table structure.
	 *
	 * @return void
	 */
	public function test_render_table_outputs_table_structure(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_table' );

		$log = $this->create_mock_activity_log();

		ob_start();
		$method->invoke( $page, array( $log ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<table', $output );
		$this->assertStringContainsString( '<thead>', $output );
		$this->assertStringContainsString( '<tbody>', $output );
	}

	/**
	 * Test render_table outputs column headers.
	 *
	 * @return void
	 */
	public function test_render_table_outputs_column_headers(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_table' );

		$log = $this->create_mock_activity_log();

		ob_start();
		$method->invoke( $page, array( $log ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Time', $output );
		$this->assertStringContainsString( 'User', $output );
		$this->assertStringContainsString( 'Action', $output );
		$this->assertStringContainsString( 'Type', $output );
		$this->assertStringContainsString( 'Description', $output );
		$this->assertStringContainsString( 'IP Address', $output );
	}

	/**
	 * Test render_table outputs log data.
	 *
	 * @return void
	 */
	public function test_render_table_outputs_log_data(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_table' );

		$log = $this->create_mock_activity_log( 1, 'create', 'event', 1 );

		ob_start();
		$method->invoke( $page, array( $log ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'action-badge', $output );
		$this->assertStringContainsString( '192.168.1.1', $output );
	}

	/**
	 * Test render_table outputs action badge classes.
	 *
	 * @return void
	 */
	public function test_render_table_outputs_action_badge_classes(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_table' );

		$log = $this->create_mock_activity_log( 1, 'delete', 'attendee' );

		ob_start();
		$method->invoke( $page, array( $log ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'action-delete', $output );
	}

	/**
	 * Test render_table outputs user link when user exists.
	 *
	 * @return void
	 */
	public function test_render_table_outputs_user_link(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_table' );

		$log = $this->create_mock_activity_log( 1, 'create', 'event', 1 );

		ob_start();
		$method->invoke( $page, array( $log ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'user-edit.php', $output );
	}

	/**
	 * Test render_table handles null user.
	 *
	 * @return void
	 */
	public function test_render_table_handles_null_user(): void {
		$page = new ActivityLogPage( $this->mock_service );

		Functions\when( 'get_edit_user_link' )->justReturn( '' );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_table' );

		// Create log with user_id = 0 (null user).
		$log = $this->create_mock_activity_log_custom( null );

		ob_start();
		$method->invoke( $page, array( $log ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<em>', $output );
	}

	/**
	 * Test render_table outputs object ID.
	 *
	 * @return void
	 */
	public function test_render_table_outputs_object_id(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_table' );

		// Default mock creates with object_id 100.
		$log = $this->create_mock_activity_log();

		ob_start();
		$method->invoke( $page, array( $log ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '#100', $output );
	}

	/**
	 * Test render_table handles null IP address.
	 *
	 * @return void
	 */
	public function test_render_table_handles_null_ip(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_table' );

		// Create log with null IP address.
		$log = $this->create_mock_activity_log_with_null_ip();

		ob_start();
		$method->invoke( $page, array( $log ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<code>-</code>', $output );
	}

	/**
	 * Test render_table outputs multiple rows.
	 *
	 * @return void
	 */
	public function test_render_table_outputs_multiple_rows(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_table' );

		$log1 = $this->create_mock_activity_log( 1, 'create', 'event' );
		$log2 = $this->create_mock_activity_log( 2, 'update', 'attendee' );
		$log3 = $this->create_mock_activity_log( 3, 'delete', 'event' );

		ob_start();
		$method->invoke( $page, array( $log1, $log2, $log3 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'action-create', $output );
		$this->assertStringContainsString( 'action-update', $output );
		$this->assertStringContainsString( 'action-delete', $output );
	}

	// =========================================================================
	// render_pagination() Tests
	// =========================================================================

	/**
	 * Test render_pagination returns early when single page.
	 *
	 * @return void
	 */
	public function test_render_pagination_returns_when_single_page(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_pagination' );

		ob_start();
		$method->invoke( $page, 10, 1, 1 );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_pagination outputs pagination structure.
	 *
	 * @return void
	 */
	public function test_render_pagination_outputs_structure(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_pagination' );

		ob_start();
		$method->invoke( $page, 100, 4, 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'tablenav', $output );
		$this->assertStringContainsString( 'tablenav-pages', $output );
	}

	/**
	 * Test render_pagination outputs item count.
	 *
	 * @return void
	 */
	public function test_render_pagination_outputs_item_count(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_pagination' );

		ob_start();
		$method->invoke( $page, 50, 2, 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'displaying-num', $output );
		$this->assertStringContainsString( '50', $output );
	}

	/**
	 * Test render_pagination outputs pagination links.
	 *
	 * @return void
	 */
	public function test_render_pagination_outputs_links(): void {
		$page = new ActivityLogPage( $this->mock_service );

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'render_pagination' );

		ob_start();
		$method->invoke( $page, 75, 3, 1 );
		$output = ob_get_clean();

		// paginate_links mock returns '<a href="#">1</a><a href="#">2</a>'.
		$this->assertStringContainsString( '<a href="#">1</a>', $output );
	}

	// =========================================================================
	// Integration Tests
	// =========================================================================

	/**
	 * Test full render cycle with data.
	 *
	 * @return void
	 */
	public function test_full_render_with_data(): void {
		$page = new ActivityLogPage( $this->mock_service );

		Functions\when( 'current_user_can' )->justReturn( true );

		$log1 = $this->create_mock_activity_log( 1, 'create', 'event' );
		$log2 = $this->create_mock_activity_log( 2, 'check_in', 'attendee' );

		$this->mock_service
			->shouldReceive( 'get_logs' )
			->andReturn(
				array(
					'items' => array( $log1, $log2 ),
					'total' => 50,
					'pages' => 2,
				)
			);

		$this->mock_service
			->shouldReceive( 'get_action_types' )
			->andReturn( array( 'create', 'update', 'delete', 'check_in', 'export' ) );

		$this->mock_service
			->shouldReceive( 'get_object_types' )
			->andReturn( array( 'event', 'attendee', 'occurrence' ) );

		$_GET['action_type'] = 'create';

		ob_start();
		$page->render();
		$output = ob_get_clean();

		// Check all major sections rendered.
		$this->assertStringContainsString( 'Activity Log', $output );
		$this->assertStringContainsString( 'nte-activity-log-filters', $output );
		$this->assertStringContainsString( 'nte-activity-log-table', $output );
		$this->assertStringContainsString( 'tablenav', $output );
	}

	// =========================================================================
	// Cleanup
	// =========================================================================

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}
}
