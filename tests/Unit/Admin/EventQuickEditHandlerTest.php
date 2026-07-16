<?php
/**
 * EventQuickEditHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\EventQuickEditHandler;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;

/**
 * Test EventQuickEditHandler AJAX functionality.
 *
 * Tests capability checks, nonce verification, input sanitization,
 * and partial update behavior.
 */
class EventQuickEditHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Handler under test.
	 *
	 * @var EventQuickEditHandler
	 */
	private EventQuickEditHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->event_repo = Mockery::mock( EventRepositoryInterface::class );
		$this->handler    = new EventQuickEditHandler( $this->event_repo );
	}

	/**
	 * Clean up superglobals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_POST['event_id'], $_POST['title'], $_POST['status'], $_POST['venue_name'], $_POST['nonce'] );
		parent::tearDown();
	}

	// =========================================================================
	// Security Tests
	// =========================================================================

	/**
	 * Test handle_quick_edit denies access without capability.
	 *
	 * @return void
	 */
	public function test_handle_quick_edit_requires_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->handler->handle_quick_edit();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died, 'wp_die should be called without permission' );
	}

	/**
	 * Test handle_quick_edit verifies nonce after capability check.
	 *
	 * @return void
	 */
	public function test_handle_quick_edit_verifies_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$nonce_checked = false;
		Functions\when( 'check_ajax_referer' )->alias(
			function ( $action, $field ) use ( &$nonce_checked ) {
				$nonce_checked = true;
				$this->assertEquals( EventQuickEditHandler::NONCE_ACTION, $action );
				$this->assertEquals( 'nonce', $field );
				return true;
			}
		);

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

		// Missing event_id will trigger json error.
		Functions\when( 'wp_send_json_error' )->alias(
			function () {
				throw new \Exception( 'json_error' );
			}
		);

		$_POST['event_id'] = 0;

		try {
			$this->handler->handle_quick_edit();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'json_error', $e->getMessage() );
		}

		$this->assertTrue( $nonce_checked, 'Nonce should be checked after capability' );
	}

	// =========================================================================
	// Input Validation Tests
	// =========================================================================

	/**
	 * Test handle_quick_edit rejects invalid event ID.
	 *
	 * @return void
	 */
	public function test_handle_quick_edit_rejects_zero_event_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

		$error_sent = false;
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data ) use ( &$error_sent ) {
				$error_sent = true;
				$this->assertArrayHasKey( 'message', $data );
				throw new \Exception( 'json_error' );
			}
		);

		$_POST['event_id'] = 0;

		try {
			$this->handler->handle_quick_edit();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertTrue( $error_sent );
	}

	/**
	 * Test handle_quick_edit rejects nonexistent event.
	 *
	 * @return void
	 */
	public function test_handle_quick_edit_rejects_nonexistent_event(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

		$this->event_repo
			->shouldReceive( 'find' )
			->with( 999 )
			->andReturn( null );

		$error_sent = false;
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data ) use ( &$error_sent ) {
				$error_sent = true;
				$this->assertStringContainsString( 'not found', $data['message'] );
				throw new \Exception( 'json_error' );
			}
		);

		$_POST['event_id'] = 999;

		try {
			$this->handler->handle_quick_edit();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertTrue( $error_sent );
	}

	// =========================================================================
	// Update Tests
	// =========================================================================

	/**
	 * Test handle_quick_edit updates title.
	 *
	 * @return void
	 */
	public function test_handle_quick_edit_updates_title(): void {
		$this->setup_successful_edit();

		$event        = new Event();
		$event->id    = 5;
		$event->title = 'Old Title';
		$event->slug  = 'old-title';
		$event->status = EventStatus::DRAFT;

		$this->event_repo
			->shouldReceive( 'find' )
			->with( 5 )
			->andReturn( $event );

		$this->event_repo
			->shouldReceive( 'generate_unique_slug' )
			->with( 'New Title' )
			->andReturn( 'new-title' );

		$saved_event = null;
		$this->event_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( Event $e ) use ( &$saved_event ) {
					$saved_event = $e;
					return $e;
				}
			);

		$success_data = null;
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) use ( &$success_data ) {
				$success_data = $data;
				throw new \Exception( 'json_success' );
			}
		);

		$_POST['event_id'] = 5;
		$_POST['title']    = 'New Title';

		try {
			$this->handler->handle_quick_edit();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertNotNull( $success_data );
		$this->assertEquals( 'New Title', $saved_event->title );
		$this->assertEquals( 'new-title', $saved_event->slug );
	}

	/**
	 * Test handle_quick_edit updates status.
	 *
	 * @return void
	 */
	public function test_handle_quick_edit_updates_status(): void {
		$this->setup_successful_edit();

		$event         = new Event();
		$event->id     = 5;
		$event->title  = 'Test Event';
		$event->status = EventStatus::DRAFT;

		$this->event_repo
			->shouldReceive( 'find' )
			->with( 5 )
			->andReturn( $event );

		$this->event_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing( fn( Event $e ) => $e );

		Functions\when( 'wp_send_json_success' )->alias(
			function () {
				throw new \Exception( 'json_success' );
			}
		);

		$_POST['event_id'] = 5;
		$_POST['status']   = 'published';

		try {
			$this->handler->handle_quick_edit();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertSame( EventStatus::PUBLISHED, $event->status );
	}

	/**
	 * Test handle_quick_edit rejects invalid status.
	 *
	 * @return void
	 */
	public function test_handle_quick_edit_rejects_invalid_status(): void {
		$this->setup_successful_edit();

		$event         = new Event();
		$event->id     = 5;
		$event->title  = 'Test Event';
		$event->status = EventStatus::DRAFT;

		$this->event_repo
			->shouldReceive( 'find' )
			->with( 5 )
			->andReturn( $event );

		$this->event_repo->shouldNotReceive( 'save' );

		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) {
				throw new \Exception( 'json_success' );
			}
		);

		$_POST['event_id'] = 5;
		$_POST['status']   = 'hacked_status';

		try {
			$this->handler->handle_quick_edit();
		} catch ( \Exception $e ) {
			// Expected — "no changes" path.
		}

		// Status should remain unchanged.
		$this->assertSame( EventStatus::DRAFT, $event->status );
	}

	/**
	 * Test handle_quick_edit returns no-change response when nothing changed.
	 *
	 * @return void
	 */
	public function test_handle_quick_edit_no_changes_detected(): void {
		$this->setup_successful_edit();

		$event             = new Event();
		$event->id         = 5;
		$event->title      = 'Same Title';
		$event->status     = EventStatus::DRAFT;
		$event->venue_name = 'Same Venue';

		$this->event_repo
			->shouldReceive( 'find' )
			->with( 5 )
			->andReturn( $event );

		$this->event_repo->shouldNotReceive( 'save' );

		$success_data = null;
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) use ( &$success_data ) {
				$success_data = $data;
				throw new \Exception( 'json_success' );
			}
		);

		$_POST['event_id']   = 5;
		$_POST['title']      = 'Same Title';
		$_POST['status']     = 'draft';
		$_POST['venue_name'] = 'Same Venue';

		try {
			$this->handler->handle_quick_edit();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertNotNull( $success_data );
		$this->assertStringContainsString( 'No changes', $success_data['message'] );
	}

	/**
	 * Test handle_quick_edit updates venue_name to null when empty.
	 *
	 * @return void
	 */
	public function test_handle_quick_edit_clears_venue_name(): void {
		$this->setup_successful_edit();

		$event             = new Event();
		$event->id         = 5;
		$event->title      = 'Test Event';
		$event->status     = EventStatus::DRAFT;
		$event->venue_name = 'Old Venue';

		$this->event_repo
			->shouldReceive( 'find' )
			->with( 5 )
			->andReturn( $event );

		$this->event_repo
			->shouldReceive( 'save' )
			->once()
			->andReturnUsing( fn( Event $e ) => $e );

		Functions\when( 'wp_send_json_success' )->alias(
			function () {
				throw new \Exception( 'json_success' );
			}
		);

		$_POST['event_id']   = 5;
		$_POST['venue_name'] = '';

		try {
			$this->handler->handle_quick_edit();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertNull( $event->venue_name );
	}

	// =========================================================================
	// Constant Tests
	// =========================================================================

	/**
	 * Test NONCE_ACTION constant is defined.
	 *
	 * @return void
	 */
	public function test_nonce_action_constant_is_defined(): void {
		$this->assertEquals( 'nettertech_events_quick_edit', EventQuickEditHandler::NONCE_ACTION );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Set up common mocks for a successful edit scenario.
	 *
	 * @return void
	 */
	private function setup_successful_edit(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}
}
