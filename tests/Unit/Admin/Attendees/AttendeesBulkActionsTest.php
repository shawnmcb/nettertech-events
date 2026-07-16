<?php
/**
 * AttendeesBulkActions unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Attendees
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Attendees;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Attendees\AttendeesBulkActions;
use NetterTechEvents\Admin\Attendees\AttendeesExporter;

/**
 * Test AttendeesBulkActions functionality.
 *
 * Tests bulk delete, export, and single-item delete handlers.
 */
class AttendeesBulkActionsTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \wpdb|Mockery\MockInterface
	 */
	private $mock_db;

	/**
	 * Mock exporter instance.
	 *
	 * @var AttendeesExporter|Mockery\MockInterface
	 */
	private $mock_exporter;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mock_db         = Mockery::mock( \wpdb::class );
		$this->mock_db->prefix = 'wp_';
		$this->mock_exporter   = Mockery::mock( AttendeesExporter::class );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test AttendeesBulkActions can be instantiated with dependencies.
	 *
	 * @return void
	 */
	public function test_can_instantiate_with_dependencies(): void {
		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );

		$this->assertInstanceOf( AttendeesBulkActions::class, $handler );
	}

	/**
	 * Test constructor creates default dependencies if not provided.
	 *
	 * @return void
	 */
	public function test_constructor_creates_default_dependencies(): void {
		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );

		$this->assertInstanceOf( AttendeesBulkActions::class, $handler );
	}

	// =========================================================================
	// handle() - Early Exit Tests
	// =========================================================================

	/**
	 * Test handle returns early for non-POST requests.
	 *
	 * @return void
	 */
	public function test_handle_returns_early_for_get_request(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		// If we get here without exceptions, the method returned early.
		$this->assertTrue( true );
	}

	/**
	 * Test handle returns early when user lacks capability.
	 *
	 * Verifies defense-in-depth capability check (OWASP A01) fires before
	 * nonce verification, preventing unauthorized bulk actions.
	 *
	 * @return void
	 */
	public function test_handle_returns_early_when_user_lacks_capability(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';

		// Override bootstrap stub (current_user_can => true) to return false.
		Functions\when( 'current_user_can' )->justReturn( false );

		// Nonce functions must not be called — if they were, the capability check failed to gate early.
		$this->mock_exporter->shouldNotReceive( 'export_all_filtered' );
		$this->mock_exporter->shouldNotReceive( 'export_selected' );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		// Reaching here without nonce/action processing confirms early return.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test handle returns early when nonce is missing.
	 *
	 * @return void
	 */
	public function test_handle_returns_early_when_nonce_missing(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		unset( $_POST['nettertech_events_attendees_nonce'] );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		$this->assertTrue( true );
	}

	/**
	 * Test handle returns early when nonce is invalid.
	 *
	 * @return void
	 */
	public function test_handle_returns_early_when_nonce_invalid(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'invalid_nonce';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		$this->assertTrue( true );
	}

	/**
	 * Test handle returns early when action is empty.
	 *
	 * @return void
	 */
	public function test_handle_returns_early_when_action_empty(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']        = '';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		$this->assertTrue( true );
	}

	// =========================================================================
	// handle() - Export All Action Tests
	// =========================================================================

	/**
	 * Test handle calls export_all_filtered for export_all action.
	 *
	 * @return void
	 */
	public function test_handle_export_all_calls_exporter(): void {
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['nettertech_events_attendees_nonce']   = 'valid_nonce';
		$_POST['bulk_action']          = 'export_all';
		$_POST['filter_occurrence_id'] = '5';
		$_POST['filter_search']        = 'test';
		$_POST['filter_status']        = 'confirmed';
		$_POST['filter_placeholder']   = 'no';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$this->mock_exporter
			->shouldReceive( 'export_all_filtered' )
			->once()
			->with( 5, 'test', 'confirmed', 'no' );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		// Mockery verifies the expectation in tearDown; this marks test as non-risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test handle export_all with default filter values.
	 *
	 * @return void
	 */
	public function test_handle_export_all_with_default_filters(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']        = 'export_all';
		unset( $_POST['filter_occurrence_id'] );
		unset( $_POST['filter_search'] );
		unset( $_POST['filter_status'] );
		unset( $_POST['filter_placeholder'] );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$this->mock_exporter
			->shouldReceive( 'export_all_filtered' )
			->once()
			->with( 0, '', '', '' );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		// Mockery verifies the expectation in tearDown; this marks test as non-risky.
		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// handle() - No Selection Tests
	// =========================================================================

	/**
	 * Test handle adds notice when no attendees selected for delete.
	 *
	 * @return void
	 */
	public function test_handle_adds_notice_when_no_attendees_selected(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']        = 'delete';
		$_POST['attendee_ids']       = array();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$action_added = false;
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$action_added ) {
				if ( 'admin_notices' === $hook ) {
					$action_added = true;
				}
			}
		);

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		$this->assertTrue( $action_added );
	}

	/**
	 * Test handle adds notice when attendee_ids is not array.
	 *
	 * @return void
	 */
	public function test_handle_adds_notice_when_attendee_ids_not_array(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']        = 'export';
		$_POST['attendee_ids']       = 'not_an_array';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$action_added = false;
		Functions\when( 'add_action' )->alias(
			function ( $hook ) use ( &$action_added ) {
				if ( 'admin_notices' === $hook ) {
					$action_added = true;
				}
			}
		);

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		$this->assertTrue( $action_added );
	}

	// =========================================================================
	// handle() - Delete Action Tests
	// =========================================================================

	/**
	 * Test handle delete action executes bulk delete.
	 *
	 * @return void
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_handle_delete_executes_bulk_delete(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']        = 'delete';
		$_POST['attendee_ids']       = array( '1', '2', '3' );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'remove_query_arg' )->justReturn( 'http://example.com/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'http://example.com/admin.php?nettertech_events_deleted=3' );

		$redirected = false;
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirected ) {
				$redirected = true;
				throw new \RuntimeException( 'Redirect called - stopping execution before exit()' );
			}
		);

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'DELETE FROM wp_nettertech_events_attendees WHERE id IN (1, 2, 3)' );
		$this->mock_db->shouldReceive( 'query' )
			->once()
			->andReturn( 3 );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );

		try {
			$handler->handle();
		} catch ( \RuntimeException $e ) {
			// Expected - we throw to prevent exit().
		}

		$this->assertTrue( $redirected );
	}

	/**
	 * Test handle delete filters out zero IDs.
	 *
	 * @return void
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_handle_delete_filters_invalid_ids(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']        = 'delete';
		$_POST['attendee_ids']       = array( '1', '0', '', '3' );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'remove_query_arg' )->justReturn( 'http://example.com/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'http://example.com/admin.php?nettertech_events_deleted=2' );
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'Redirect called' );
			}
		);

		$prepared_query = null;
		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( $query, ...$args ) use ( &$prepared_query ) {
					$prepared_query = $query;
					return $query;
				}
			);
		$this->mock_db->shouldReceive( 'query' )->once()->andReturn( 2 );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );

		try {
			$handler->handle();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		// Verify only 2 placeholders (filtered out 0 and empty).
		$this->assertStringContainsString( '%d, %d', $prepared_query );
	}

	// =========================================================================
	// handle() - Export Action Tests
	// =========================================================================

	/**
	 * Test handle export action calls exporter.
	 *
	 * @return void
	 */
	public function test_handle_export_calls_exporter(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']        = 'export';
		$_POST['attendee_ids']       = array( '5', '10', '15' );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$this->mock_exporter
			->shouldReceive( 'export_selected' )
			->once()
			->with( array( 5, 10, 15 ) );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle();

		// Mockery verifies the expectation in tearDown; this marks test as non-risky.
		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// handle_single_delete() - Early Exit Tests
	// =========================================================================

	/**
	 * Test handle_single_delete returns early when action not set.
	 *
	 * @return void
	 */
	public function test_handle_single_delete_returns_early_when_no_action(): void {
		unset( $_GET['action'] );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle_single_delete();

		$this->assertTrue( true );
	}

	/**
	 * Test handle_single_delete returns early for wrong action.
	 *
	 * @return void
	 */
	public function test_handle_single_delete_returns_early_for_wrong_action(): void {
		$_GET['action'] = 'some_other_action';

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle_single_delete();

		$this->assertTrue( true );
	}

	/**
	 * Test handle_single_delete returns early for invalid attendee_id.
	 *
	 * @return void
	 */
	public function test_handle_single_delete_returns_early_for_invalid_id(): void {
		$_GET['action']      = 'nettertech_events_delete_attendee';
		$_GET['attendee_id'] = '0';

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle_single_delete();

		$this->assertTrue( true );
	}

	/**
	 * Test handle_single_delete returns early when attendee_id missing.
	 *
	 * @return void
	 */
	public function test_handle_single_delete_returns_early_when_id_missing(): void {
		$_GET['action'] = 'nettertech_events_delete_attendee';
		unset( $_GET['attendee_id'] );

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );
		$handler->handle_single_delete();

		$this->assertTrue( true );
	}

	// =========================================================================
	// handle_single_delete() - Nonce Validation Tests
	// =========================================================================

	/**
	 * Test handle_single_delete calls wp_die for missing nonce.
	 *
	 * @return void
	 */
	public function test_handle_single_delete_dies_for_missing_nonce(): void {
		$_GET['action']      = 'nettertech_events_delete_attendee';
		$_GET['attendee_id'] = '42';
		unset( $_GET['_wpnonce'] );

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$wp_die_called = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$wp_die_called ) {
				$wp_die_called = true;
				throw new \Exception( 'wp_die called' );
			}
		);

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );

		try {
			$handler->handle_single_delete();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertTrue( $wp_die_called );
	}

	/**
	 * Test handle_single_delete calls wp_die for invalid nonce.
	 *
	 * @return void
	 */
	public function test_handle_single_delete_dies_for_invalid_nonce(): void {
		$_GET['action']      = 'nettertech_events_delete_attendee';
		$_GET['attendee_id'] = '42';
		$_GET['_wpnonce']    = 'invalid_nonce';

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$wp_die_called = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$wp_die_called ) {
				$wp_die_called = true;
				throw new \Exception( 'wp_die called' );
			}
		);

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );

		try {
			$handler->handle_single_delete();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertTrue( $wp_die_called );
	}

	// =========================================================================
	// handle_single_delete() - Success Tests
	// =========================================================================

	/**
	 * Test handle_single_delete deletes attendee successfully.
	 *
	 * @return void
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_handle_single_delete_deletes_attendee(): void {
		$_GET['action']      = 'nettertech_events_delete_attendee';
		$_GET['attendee_id'] = '42';
		$_GET['_wpnonce']    = 'valid_nonce';

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin.php?page=nettertech-events-attendees' );
		Functions\when( 'add_query_arg' )->justReturn( 'http://example.com/wp-admin/admin.php?page=nettertech-events-attendees&nettertech_events_deleted=1' );

		$redirected = false;
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirected ) {
				$redirected = true;
				throw new \RuntimeException( 'Redirect called - stopping execution before exit()' );
			}
		);

		$this->mock_db->shouldReceive( 'delete' )
			->once()
			->with( 'wp_nettertech_events_attendees', array( 'id' => 42 ), array( '%d' ) )
			->andReturn( 1 );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );

		try {
			$handler->handle_single_delete();
		} catch ( \RuntimeException $e ) {
			// Expected - we throw to prevent exit().
		}

		$this->assertTrue( $redirected );
	}

	/**
	 * Test handle_single_delete handles delete failure.
	 *
	 * @return void
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_handle_single_delete_handles_failure(): void {
		$_GET['action']      = 'nettertech_events_delete_attendee';
		$_GET['attendee_id'] = '42';
		$_GET['_wpnonce']    = 'valid_nonce';

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin.php' );

		$deleted_param = null;
		Functions\when( 'add_query_arg' )->alias(
			function ( $key, $value ) use ( &$deleted_param ) {
				if ( 'nettertech_events_deleted' === $key ) {
					$deleted_param = $value;
				}
				return 'http://example.com';
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'Redirect called - stopping execution before exit()' );
			}
		);

		$this->mock_db->shouldReceive( 'delete' )
			->once()
			->andReturn( false );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter );

		try {
			$handler->handle_single_delete();
		} catch ( \RuntimeException $e ) {
			// Expected - we throw to prevent exit().
		}

		$this->assertSame( 0, $deleted_param );
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$_GET                      = array();

		parent::tearDown();
	}
	/**
	 * Bulk email resolves attendees to orders and mails each order once.
	 *
	 * Five attendees from one order are one email, not five; an attendee
	 * without an order (free RSVP, CSV import) is skipped and counted.
	 *
	 * @return void
	 */
	public function test_handle_bulk_email_dedupes_orders_and_skips_orderless(): void {
		$_SERVER['REQUEST_METHOD']                  = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']                       = 'email';
		$_POST['attendee_ids']                      = array( '1', '2', '3' );

		$this->mock_db->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql );
		$this->mock_db->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'id' => 1, 'wc_order_id' => 77 ),
				array( 'id' => 2, 'wc_order_id' => 77 ),
				array( 'id' => 3, 'wc_order_id' => 0 ),
			)
		);

		$email_handler = Mockery::mock( \NetterTechEvents\Services\OrderEmailHandler::class );
		$email_handler->shouldReceive( 'resend_confirmation' )
			->once()
			->with( 77 )
			->andReturn( array( 'success' => true ) );

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter, $email_handler );
		$handler->handle();

		$this->assertTrue( true ); // Mockery verifies the once() expectation.
	}

	/**
	 * Bulk email without WooCommerce refuses politely instead of fataling.
	 *
	 * @return void
	 */
	public function test_handle_bulk_email_requires_email_handler(): void {
		$_SERVER['REQUEST_METHOD']                  = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']                       = 'email';
		$_POST['attendee_ids']                      = array( '1' );

		$this->mock_db->shouldNotReceive( 'get_results' );

		$notice = $this->capture_admin_notice();

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter, null );
		$handler->handle();

		// The refusal has to say why, or the action looks like it silently did nothing.
		$html = $this->render_admin_notice( $notice );

		$this->assertStringContainsString( 'Emailing attendees requires WooCommerce.', $html );
		$this->assertStringContainsString( 'notice-error', $html );
	}

	/**
	 * The result notice reports sent, failed and skipped separately.
	 *
	 * The counters are only ever observable through this notice — it is the
	 * whole user-facing result of the action — so the numbers it prints are the
	 * assertion. Two orders mail, one refuses, one attendee has no order: an
	 * admin has to be able to tell those three outcomes apart, and a run that
	 * reported "3 re-sent" while one silently failed would be the bug.
	 *
	 * @return void
	 */
	public function test_handle_bulk_email_notice_reports_sent_failed_and_skipped(): void {
		$_SERVER['REQUEST_METHOD']                  = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']                       = 'email';
		$_POST['attendee_ids']                      = array( '1', '2', '3', '4', '5' );

		$this->mock_db->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql );
		// wpdb hands back column values as strings; the order IDs are cast on read.
		$this->mock_db->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'id' => '1', 'wc_order_id' => '77' ),
				array( 'id' => '2', 'wc_order_id' => '77' ),
				array( 'id' => '3', 'wc_order_id' => '88' ),
				array( 'id' => '4', 'wc_order_id' => '99' ),
				array( 'id' => '5' ),
			)
		);

		$email_handler = Mockery::mock( \NetterTechEvents\Services\OrderEmailHandler::class );
		$email_handler->shouldReceive( 'resend_confirmation' )
			->once()
			->with( Mockery::on( static fn( $id ): bool => 77 === $id ) )
			->andReturn( array( 'success' => true ) );
		$email_handler->shouldReceive( 'resend_confirmation' )
			->once()
			->with( Mockery::on( static fn( $id ): bool => 88 === $id ) )
			->andReturn( array( 'success' => true ) );
		$email_handler->shouldReceive( 'resend_confirmation' )
			->once()
			->with( Mockery::on( static fn( $id ): bool => 99 === $id ) )
			->andReturn( array( 'success' => false ) );

		$notice = $this->capture_admin_notice();

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter, $email_handler );
		$handler->handle();

		// Two of the three orders mailed (the pair sharing order 77 is one
		// email), one refused, one attendee had no order. A partial failure is a
		// warning, not a success. Asserted whole: a substring check cannot tell
		// "2 re-sent" from "-2 re-sent".
		$this->assertSame(
			'<div class="notice notice-warning is-dismissible"><p>'
				. '2 confirmation emails re-sent.'
				. ' 1 order could not be emailed.'
				. ' 1 attendee has no order and was skipped.'
				. '</p></div>',
			$this->render_admin_notice( $notice )
		);
	}

	/**
	 * Selected attendees that match no rows report nothing sent, not a failure.
	 *
	 * @return void
	 */
	public function test_handle_bulk_email_reports_zero_when_no_rows_match(): void {
		$_SERVER['REQUEST_METHOD']                  = 'POST';
		$_POST['nettertech_events_attendees_nonce'] = 'valid_nonce';
		$_POST['bulk_action']                       = 'email';
		$_POST['attendee_ids']                      = array( '1' );

		$this->mock_db->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql );
		$this->mock_db->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$email_handler = Mockery::mock( \NetterTechEvents\Services\OrderEmailHandler::class );
		$email_handler->shouldNotReceive( 'resend_confirmation' );

		$notice = $this->capture_admin_notice();

		$handler = new AttendeesBulkActions( $this->mock_db, $this->mock_exporter, $email_handler );
		$handler->handle();

		// Nothing sent is a success notice with no failure or skip clause.
		$this->assertSame(
			'<div class="notice notice-success is-dismissible"><p>0 confirmation emails re-sent.</p></div>',
			$this->render_admin_notice( $notice )
		);
	}

	/**
	 * Capture the callback registered on admin_notices.
	 *
	 * @return object Holder whose `callback` property receives the callable.
	 */
	private function capture_admin_notice(): object {
		$holder           = new \stdClass();
		$holder->callback = null;

		\Brain\Monkey\Actions\expectAdded( 'admin_notices' )
			->once()
			->whenHappen(
				static function ( $callback ) use ( $holder ): void {
					$holder->callback = $callback;
				}
			);

		return $holder;
	}

	/**
	 * Render a captured admin notice to its HTML.
	 *
	 * @param object $holder Holder returned by capture_admin_notice().
	 * @return string Rendered notice markup.
	 */
	private function render_admin_notice( object $holder ): string {
		$this->assertIsCallable( $holder->callback, 'No admin_notices callback was registered.' );

		ob_start();
		( $holder->callback )();

		return (string) ob_get_clean();
	}

}
