<?php
/**
 * OrderFailureHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Integrations\WooCommerce\OrderFailureHandler;

/**
 * Test OrderFailureHandler failure-response orchestration.
 *
 * Verifies that when attendee creation fails, the handler:
 * - Adds admin and customer order notes
 * - Sets order status to on-hold
 * - Sends admin email
 * - Logs to activity log
 * - Fires extensibility hook
 * - Never throws exceptions
 */
class OrderFailureHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Activity log mock.
	 *
	 * @var ActivityLogServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $activity_log;

	/**
	 * System under test.
	 *
	 * @var OrderFailureHandler
	 */
	private OrderFailureHandler $sut;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->activity_log = $this->createMock( ActivityLogServiceInterface::class );
		$this->sut          = new OrderFailureHandler( $this->activity_log );
	}

	// =========================================================================
	// register()
	// =========================================================================

	/**
	 * Test register hooks into nettertech_events_attendee_creation_failed.
	 *
	 * @return void
	 */
	public function test_register_adds_action_hook(): void {
		$registered = array();

		Functions\when( 'add_action' )->alias(
			function ( string $tag, $callback, int $priority = 10, int $args = 1 ) use ( &$registered ): bool {
				$registered[] = array(
					'tag'      => $tag,
					'callback' => $callback,
					'priority' => $priority,
					'args'     => $args,
				);
				return true;
			}
		);

		$this->sut->register();

		$found = false;
		foreach ( $registered as $hook ) {
			if (
				Hooks::ATTENDEE_CREATION_FAILED === $hook['tag']
				&& array( $this->sut, 'handle_attendee_failure' ) === $hook['callback']
				&& 10 === $hook['priority']
				&& 3 === $hook['args']
			) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'register() should add handle_attendee_failure to nettertech_events_attendee_creation_failed' );
	}

	// =========================================================================
	// handle_attendee_failure() — Happy Path
	// =========================================================================

	/**
	 * Test handle_attendee_failure adds admin and customer order notes.
	 *
	 * @return void
	 */
	public function test_handle_failure_adds_order_notes(): void {
		$notes     = array();
		$order     = $this->create_mock_order( 42, 'processing', $notes );
		$item      = $this->create_mock_item( 'VIP Ticket', 2 );
		$exception = new \RuntimeException( 'DB insert failed' );

		$this->stub_wp_functions( $order );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		// Verify admin note was added.
		$this->assertCount( 2, $notes );

		// First note: admin (is_customer_note = 0).
		$this->assertSame( 0, $notes[0]['is_customer'] );
		$this->assertStringContainsString( 'ALERT: Attendee creation failed', $notes[0]['note'] );
		$this->assertStringContainsString( 'VIP Ticket', $notes[0]['note'] );
		$this->assertStringContainsString( 'DB insert failed', $notes[0]['note'] );
		$this->assertStringContainsString( 'manual intervention required', $notes[0]['note'] );

		// Second note: customer (is_customer_note = 1).
		$this->assertSame( 1, $notes[1]['is_customer'] );
		$this->assertStringContainsString( 'VIP Ticket', $notes[1]['note'] );
		$this->assertStringContainsString( 'Our team has been notified', $notes[1]['note'] );
	}

	/**
	 * Test handle_attendee_failure changes order status to on-hold.
	 *
	 * @return void
	 */
	public function test_handle_failure_sets_order_on_hold(): void {
		$notes          = array();
		$status_changes = array();
		$order          = $this->create_mock_order( 42, 'processing', $notes, $status_changes );
		$item           = $this->create_mock_item( 'Ticket', 1 );
		$exception      = new \RuntimeException( 'Error' );

		$this->stub_wp_functions( $order );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertCount( 1, $status_changes );
		$this->assertSame( 'on-hold', $status_changes[0]['status'] );
		$this->assertStringContainsString( 'Attendee creation failed', $status_changes[0]['note'] );
	}

	/**
	 * Test handle_attendee_failure sends admin email.
	 *
	 * @return void
	 */
	public function test_handle_failure_sends_admin_email(): void {
		$notes     = array();
		$emails    = array();
		$order     = $this->create_mock_order( 42, 'processing', $notes );
		$item      = $this->create_mock_item( 'VIP Ticket', 3 );
		$exception = new \RuntimeException( 'Insert failed' );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_option' )->alias( function ( string $key, $default = false ) {
			return match ( $key ) {
				'admin_email' => 'admin@example.com',
				'blogname'    => 'Test Site',
				default       => $default,
			};
		} );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_mail' )->alias( function ( string $to, string $subject, string $body ) use ( &$emails ) {
			$emails[] = array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
			);
			return true;
		} );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertCount( 1, $emails );
		$this->assertSame( 'admin@example.com', $emails[0]['to'] );
		$this->assertStringContainsString( 'Attendee creation failed', $emails[0]['subject'] );
		$this->assertStringContainsString( '#42', $emails[0]['body'] );
		$this->assertStringContainsString( 'VIP Ticket', $emails[0]['body'] );
		$this->assertStringContainsString( 'Insert failed', $emails[0]['body'] );
		$this->assertStringContainsString( 'Manual intervention', $emails[0]['body'] );
	}

	/**
	 * Test handle_attendee_failure logs to activity log.
	 *
	 * @return void
	 */
	public function test_handle_failure_logs_to_activity_log(): void {
		$notes     = array();
		$order     = $this->create_mock_order( 42, 'processing', $notes );
		$item      = $this->create_mock_item( 'Ticket', 2, 50, array(
			MetaKeys::TICKET_TYPE_ID => '7',
			MetaKeys::OCCURRENCE_ID  => '15',
		) );
		$exception = new \RuntimeException( 'DB error' );

		$logged_action      = null;
		$logged_object_type = null;
		$logged_object_id   = null;
		$logged_name        = null;
		$logged_details     = null;

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->willReturnCallback( function (
				string $action,
				string $object_type,
				int $object_id,
				string $name,
				?array $details
			) use ( &$logged_action, &$logged_object_type, &$logged_object_id, &$logged_name, &$logged_details ): void {
				$logged_action      = $action;
				$logged_object_type = $object_type;
				$logged_object_id   = $object_id;
				$logged_name        = $name;
				$logged_details     = $details;
			} );

		$this->stub_wp_functions( $order );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertSame( 'attendee_creation_failed', $logged_action );
		$this->assertSame( 'attendee', $logged_object_type );
		$this->assertSame( 42, $logged_object_id );
		$this->assertStringContainsString( 'Ticket', $logged_name );
		$this->assertSame( 42, $logged_details['order_id'] );
		$this->assertSame( 2, $logged_details['quantity'] );
		$this->assertSame( 'DB error', $logged_details['error_message'] );
	}

	/**
	 * Test handle_attendee_failure fires extensibility hook.
	 *
	 * @return void
	 */
	public function test_handle_failure_fires_extensibility_hook(): void {
		$notes      = array();
		$order      = $this->create_mock_order( 42, 'processing', $notes );
		$item       = $this->create_mock_item( 'Ticket', 1 );
		$exception  = new \RuntimeException( 'Error' );
		$hook_fired = false;
		$hook_args  = array();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_option' )->alias( function ( string $key, $default = false ) {
			return match ( $key ) {
				'admin_email' => 'admin@example.com',
				'blogname'    => 'Test Site',
				default       => $default,
			};
		} );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'do_action' )->alias( function ( string $tag, ...$args ) use ( &$hook_fired, &$hook_args ): void {
			if ( Hooks::ATTENDEE_FAILURE_HANDLED === $tag ) {
				$hook_fired = true;
				$hook_args  = $args;
			}
		} );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertTrue( $hook_fired, 'nettertech_events_attendee_failure_handled hook should have been fired' );
		$this->assertSame( 42, $hook_args[0] );
		$this->assertSame( $item, $hook_args[1] );
		$this->assertSame( $exception, $hook_args[2] );

		// Verify actions_taken array contains all expected actions.
		$actions_taken = $hook_args[3];
		$this->assertContains( 'order_note', $actions_taken );
		$this->assertContains( 'customer_note', $actions_taken );
		$this->assertContains( 'status_changed', $actions_taken );
		$this->assertContains( 'admin_email', $actions_taken );
		$this->assertContains( 'activity_log', $actions_taken );
	}

	// =========================================================================
	// handle_attendee_failure() — Order Not Found
	// =========================================================================

	/**
	 * Test handle_attendee_failure returns early when order not found.
	 *
	 * @return void
	 */
	public function test_handle_failure_returns_early_when_order_not_found(): void {
		$item      = $this->create_mock_item( 'Ticket', 1 );
		$exception = new \RuntimeException( 'Error' );

		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->activity_log->expects( $this->never() )
			->method( 'log' );

		$this->sut->handle_attendee_failure( $exception, 999, $item );
	}

	// =========================================================================
	// handle_attendee_failure() — Terminal Status Preservation
	// =========================================================================

	/**
	 * Test handle_attendee_failure does not change status when already on-hold.
	 *
	 * @return void
	 */
	public function test_handle_failure_skips_status_change_when_on_hold(): void {
		$notes          = array();
		$status_changes = array();
		$order          = $this->create_mock_order( 42, 'on-hold', $notes, $status_changes );
		$item           = $this->create_mock_item( 'Ticket', 1 );
		$exception      = new \RuntimeException( 'Error' );

		$this->stub_wp_functions( $order );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertCount( 0, $status_changes );
	}

	/**
	 * Test handle_attendee_failure does not change status when cancelled.
	 *
	 * @return void
	 */
	public function test_handle_failure_skips_status_change_when_cancelled(): void {
		$notes          = array();
		$status_changes = array();
		$order          = $this->create_mock_order( 42, 'cancelled', $notes, $status_changes );
		$item           = $this->create_mock_item( 'Ticket', 1 );
		$exception      = new \RuntimeException( 'Error' );

		$this->stub_wp_functions( $order );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertCount( 0, $status_changes );
	}

	/**
	 * Test handle_attendee_failure does not change status when refunded.
	 *
	 * @return void
	 */
	public function test_handle_failure_skips_status_change_when_refunded(): void {
		$notes          = array();
		$status_changes = array();
		$order          = $this->create_mock_order( 42, 'refunded', $notes, $status_changes );
		$item           = $this->create_mock_item( 'Ticket', 1 );
		$exception      = new \RuntimeException( 'Error' );

		$this->stub_wp_functions( $order );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertCount( 0, $status_changes );
	}

	/**
	 * Test handle_attendee_failure does not change status when failed.
	 *
	 * @return void
	 */
	public function test_handle_failure_skips_status_change_when_failed(): void {
		$notes          = array();
		$status_changes = array();
		$order          = $this->create_mock_order( 42, 'failed', $notes, $status_changes );
		$item           = $this->create_mock_item( 'Ticket', 1 );
		$exception      = new \RuntimeException( 'Error' );

		$this->stub_wp_functions( $order );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertCount( 0, $status_changes );
	}

	// =========================================================================
	// handle_attendee_failure() — Admin Email Edge Cases
	// =========================================================================

	/**
	 * Test handle_attendee_failure skips email when no admin email configured.
	 *
	 * @return void
	 */
	public function test_handle_failure_skips_email_when_no_admin_email(): void {
		$notes  = array();
		$emails = array();
		$order  = $this->create_mock_order( 42, 'processing', $notes );
		$item   = $this->create_mock_item( 'Ticket', 1 );

		$exception = new \RuntimeException( 'Error' );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_option' )->alias( function ( string $key, $default = false ) {
			return match ( $key ) {
				'admin_email' => '',
				'blogname'    => 'Test Site',
				default       => $default,
			};
		} );
		Functions\when( 'wp_mail' )->alias( function () use ( &$emails ) {
			$emails[] = true;
			return true;
		} );

		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertCount( 0, $emails, 'wp_mail should not be called when admin_email is empty' );
	}

	// =========================================================================
	// handle_attendee_failure() — Fault Tolerance
	// =========================================================================

	/**
	 * Test handle_attendee_failure does not throw when activity log throws.
	 *
	 * @return void
	 */
	public function test_handle_failure_survives_activity_log_exception(): void {
		$notes = array();
		$order = $this->create_mock_order( 42, 'processing', $notes );
		$item  = $this->create_mock_item( 'Ticket', 1 );

		$exception = new \RuntimeException( 'Error' );

		$this->activity_log->method( 'log' )
			->willThrowException( new \RuntimeException( 'Log service down' ) );

		$this->stub_wp_functions( $order );

		// Should not throw — handler is fault-tolerant.
		$this->sut->handle_attendee_failure( $exception, 42, $item );

		// If we reach here, the handler did not throw.
		$this->assertTrue( true );
	}

	/**
	 * Test handle_attendee_failure continues when order note throws.
	 *
	 * @return void
	 */
	public function test_handle_failure_survives_order_note_exception(): void {
		$order     = $this->create_throwing_order( 42, 'processing' );
		$item      = $this->create_mock_item( 'Ticket', 1 );
		$exception = new \RuntimeException( 'Error' );

		$this->stub_wp_functions( $order );

		// Should not throw — handler catches Throwable per-step.
		$this->sut->handle_attendee_failure( $exception, 42, $item );

		$this->assertTrue( true );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Create a PHPUnit mock of \WC_Order that captures calls.
	 *
	 * Uses willReturnCallback to capture arguments instead of expectations,
	 * because the handler's try/catch swallows PHPUnit assertion failures.
	 *
	 * @param int              $order_id       Order ID.
	 * @param string           $status         Order status.
	 * @param array<int,mixed> $notes          Reference to capture order notes.
	 * @param array<int,mixed> $status_changes Reference to capture status changes.
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_order(
		int $order_id,
		string $status,
		array &$notes = array(),
		array &$status_changes = array()
	) {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );
		$order->method( 'get_status' )->willReturn( $status );
		$order->method( 'get_edit_order_url' )->willReturn(
			'https://example.com/wp-admin/post.php?post=' . $order_id . '&action=edit'
		);

		$order->method( 'add_order_note' )
			->willReturnCallback( function ( string $note, int $is_customer = 0 ) use ( &$notes ): int {
				$notes[] = array(
					'note'        => $note,
					'is_customer' => $is_customer,
				);
				return count( $notes );
			} );

		$order->method( 'set_status' )
			->willReturnCallback( function ( string $new_status, string $note = '' ) use ( &$status_changes ): void {
				$status_changes[] = array(
					'status' => $new_status,
					'note'   => $note,
				);
			} );

		return $order;
	}

	/**
	 * Create a WC_Order mock where add_order_note throws.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Order status.
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_throwing_order( int $order_id, string $status ) {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );
		$order->method( 'get_status' )->willReturn( $status );
		$order->method( 'get_edit_order_url' )->willReturn( '' );
		$order->method( 'add_order_note' )
			->willThrowException( new \RuntimeException( 'Note failed' ) );

		return $order;
	}

	/**
	 * Create a PHPUnit mock of \WC_Order_Item_Product.
	 *
	 * @param string               $name     Item name.
	 * @param int                  $quantity Quantity.
	 * @param int                  $item_id  Item ID.
	 * @param array<string, mixed> $meta     Meta key-value pairs.
	 * @return \WC_Order_Item_Product|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_item(
		string $name = 'Test Ticket',
		int $quantity = 1,
		int $item_id = 50,
		array $meta = array()
	) {
		$default_meta = array(
			MetaKeys::TICKET_TYPE_ID => '5',
			MetaKeys::OCCURRENCE_ID  => '10',
		);
		$meta = array_merge( $default_meta, $meta );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_id' )->willReturn( $item_id );
		$item->method( 'get_name' )->willReturn( $name );
		$item->method( 'get_quantity' )->willReturn( $quantity );
		$item->method( 'get_total' )->willReturn( '25.00' );
		$item->method( 'get_meta' )->willReturnCallback( function ( string $key ) use ( $meta ) {
			return $meta[ $key ] ?? '';
		} );

		return $item;
	}

	/**
	 * Stub common WordPress functions for the happy path.
	 *
	 * @param \WC_Order|\PHPUnit\Framework\MockObject\MockObject $order Mock order.
	 * @return void
	 */
	private function stub_wp_functions( $order ): void {
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_option' )->alias( function ( string $key, $default = false ) {
			return match ( $key ) {
				'admin_email' => 'admin@example.com',
				'blogname'    => 'Test Site',
				default       => $default,
			};
		} );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_mail' )->justReturn( true );
	}
}
