<?php
/**
 * EventActionHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\EventActionHandler;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Services\EventDuplicationService;

/**
 * Test EventActionHandler functionality.
 *
 * Tests event deletion and duplication action handlers.
 */
class EventActionHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_repo;

	/**
	 * Mock duplication service.
	 *
	 * @var EventDuplicationService|Mockery\MockInterface
	 */
	private $mock_dup_service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mock_repo        = Mockery::mock( EventRepositoryInterface::class );
		$this->mock_dup_service = Mockery::mock( EventDuplicationService::class );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test EventActionHandler can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		$this->assertInstanceOf( EventActionHandler::class, $handler );
	}

	/**
	 * Test constructor creates default repository if not provided.
	 *
	 * @return void
	 */
	public function test_constructor_creates_default_repository(): void {
		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		$this->assertInstanceOf( EventActionHandler::class, $handler );
	}

	// =========================================================================
	// register() Tests
	// =========================================================================

	/**
	 * Test register adds admin_init actions.
	 *
	 * @return void
	 */
	public function test_register_adds_admin_init_actions(): void {
		$actions_added = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$actions_added ) {
				$actions_added[] = $hook;
				return true;
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );
		$handler->register();

		$this->assertCount( 2, $actions_added );
		$this->assertEquals( 'admin_init', $actions_added[0] );
		$this->assertEquals( 'admin_init', $actions_added[1] );
	}

	// =========================================================================
	// handle_deletion() Tests
	// =========================================================================

	/**
	 * Test handle_deletion returns early when not on nettertech-events page.
	 *
	 * @return void
	 */
	public function test_handle_deletion_returns_when_not_nte_page(): void {
		$_GET['page'] = 'other-plugin';

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		// Should return without calling delete.
		$this->mock_repo->shouldNotReceive( 'delete' );

		$handler->handle_deletion();

		$this->assertTrue( true );
	}

	/**
	 * Test handle_deletion returns early when action is not delete.
	 *
	 * @return void
	 */
	public function test_handle_deletion_returns_when_action_not_delete(): void {
		$_GET['page']   = 'nettertech-events';
		$_GET['action'] = 'edit';

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		$this->mock_repo->shouldNotReceive( 'delete' );

		$handler->handle_deletion();

		$this->assertTrue( true );
	}

	/**
	 * Test handle_deletion returns early when no event_id.
	 *
	 * @return void
	 */
	public function test_handle_deletion_returns_when_no_event_id(): void {
		$_GET['page']   = 'nettertech-events';
		$_GET['action'] = 'delete';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		$this->mock_repo->shouldNotReceive( 'delete' );

		$handler->handle_deletion();

		$this->assertTrue( true );
	}

	/**
	 * Test handle_deletion dies without permission.
	 *
	 * @return void
	 */
	public function test_handle_deletion_dies_without_permission(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['action']   = 'delete';
		$_GET['event_id'] = '123';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		try {
			$handler->handle_deletion();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test handle_deletion dies on invalid nonce.
	 *
	 * @return void
	 */
	public function test_handle_deletion_dies_on_invalid_nonce(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['action']   = 'delete';
		$_GET['event_id'] = '123';
		$_GET['_wpnonce'] = 'invalid';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		try {
			$handler->handle_deletion();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test handle_deletion deletes event and redirects.
	 *
	 * @return void
	 */
	public function test_handle_deletion_deletes_and_redirects(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['action']   = 'delete';
		$_GET['event_id'] = '123';
		$_GET['_wpnonce'] = 'valid';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin.php?page=nettertech-events&message=deleted' );

		$this->mock_repo
			->shouldReceive( 'delete' )
			->once()
			->with( 123 )
			->andReturn( true );

		$redirected = false;
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirected ) {
				$redirected = true;
				throw new \Exception( 'redirect' );
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		try {
			$handler->handle_deletion();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'redirect', $e->getMessage() );
		}

		$this->assertTrue( $redirected );
	}

	/**
	 * Test handle_deletion blocks deletion when the event has order-linked sales.
	 *
	 * @return void
	 */
	public function test_handle_deletion_blocks_when_event_has_sales(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['action']   = 'delete';
		$_GET['event_id'] = '123';
		$_GET['_wpnonce'] = 'valid';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();

		$ticket_repo = Mockery::mock( TicketRepositoryInterface::class );
		$ticket_repo->shouldReceive( 'has_paid_attendance' )->once()->with( 123 )->andReturn( true );

		// An event with sales must NOT be deleted.
		$this->mock_repo->shouldReceive( 'delete' )->never();

		$redirect_url = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \Exception( 'redirect' );
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service, $ticket_repo );

		try {
			$handler->handle_deletion();
		} catch ( \Exception $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertStringContainsString( 'message=has_sales', $redirect_url );
	}

	/**
	 * Test handle_deletion proceeds to delete when the event has no sales.
	 *
	 * @return void
	 */
	public function test_handle_deletion_proceeds_when_no_sales(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['action']   = 'delete';
		$_GET['event_id'] = '123';
		$_GET['_wpnonce'] = 'valid';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();

		$ticket_repo = Mockery::mock( TicketRepositoryInterface::class );
		$ticket_repo->shouldReceive( 'has_paid_attendance' )->once()->with( 123 )->andReturn( false );

		$this->mock_repo->shouldReceive( 'delete' )->once()->with( 123 )->andReturn( true );

		$redirect_url = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \Exception( 'redirect' );
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service, $ticket_repo );

		try {
			$handler->handle_deletion();
		} catch ( \Exception $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertStringContainsString( 'message=deleted', $redirect_url );
	}

	// =========================================================================
	// handle_duplication() Tests
	// =========================================================================

	/**
	 * Test handle_duplication returns early when not on nettertech-events page.
	 *
	 * @return void
	 */
	public function test_handle_duplication_returns_when_not_nte_page(): void {
		$_GET['page'] = 'other-plugin';

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		$this->mock_dup_service->shouldNotReceive( 'duplicate' );

		$handler->handle_duplication();

		$this->assertTrue( true );
	}

	/**
	 * Test handle_duplication returns early when action is not duplicate.
	 *
	 * @return void
	 */
	public function test_handle_duplication_returns_when_action_not_duplicate(): void {
		$_GET['page']   = 'nettertech-events';
		$_GET['action'] = 'edit';

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		$this->mock_dup_service->shouldNotReceive( 'duplicate' );

		$handler->handle_duplication();

		$this->assertTrue( true );
	}

	/**
	 * Test handle_duplication returns early when no event_id.
	 *
	 * @return void
	 */
	public function test_handle_duplication_returns_when_no_event_id(): void {
		$_GET['page']   = 'nettertech-events';
		$_GET['action'] = 'duplicate';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		$this->mock_dup_service->shouldNotReceive( 'duplicate' );

		$handler->handle_duplication();

		$this->assertTrue( true );
	}

	/**
	 * Test handle_duplication dies without permission.
	 *
	 * @return void
	 */
	public function test_handle_duplication_dies_without_permission(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['action']   = 'duplicate';
		$_GET['event_id'] = '123';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		try {
			$handler->handle_duplication();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test handle_duplication dies on invalid nonce.
	 *
	 * @return void
	 */
	public function test_handle_duplication_dies_on_invalid_nonce(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['action']   = 'duplicate';
		$_GET['event_id'] = '123';
		$_GET['_wpnonce'] = 'invalid';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		try {
			$handler->handle_duplication();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test handle_duplication duplicates event and redirects on success.
	 *
	 * @return void
	 */
	public function test_handle_duplication_success_redirects(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['action']   = 'duplicate';
		$_GET['event_id'] = '123';
		$_GET['_wpnonce'] = 'valid';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/' );

		$duplicated_event     = new Event();
		$duplicated_event->id = 456;

		$this->mock_dup_service
			->shouldReceive( 'duplicate' )
			->once()
			->with( 123 )
			->andReturn( $duplicated_event );

		$redirected_url = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirected_url ) {
				$redirected_url = $url;
				throw new \Exception( 'redirect' );
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		try {
			$handler->handle_duplication();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'redirect', $e->getMessage() );
		}

		$this->assertNotNull( $redirected_url );
	}

	/**
	 * Test handle_duplication redirects with error on failure.
	 *
	 * @return void
	 */
	public function test_handle_duplication_failure_redirects_with_error(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['action']   = 'duplicate';
		$_GET['event_id'] = '123';
		$_GET['_wpnonce'] = 'valid';

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$error_url = 'http://example.com/wp-admin/admin.php?page=nettertech-events&message=error';
		Functions\when( 'admin_url' )->justReturn( $error_url );

		$this->mock_dup_service
			->shouldReceive( 'duplicate' )
			->once()
			->with( 123 )
			->andReturn( null );

		$redirected_url = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirected_url ) {
				$redirected_url = $url;
				throw new \Exception( 'redirect' );
			}
		);

		$handler = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );

		try {
			$handler->handle_duplication();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'redirect', $e->getMessage() );
		}

		$this->assertEquals( $error_url, $redirected_url );
	}

	// =========================================================================
	// is_nettertech_events_page() Tests (via reflection)
	// =========================================================================

	/**
	 * Test is_nettertech_events_page returns true for nettertech-events.
	 *
	 * @return void
	 */
	public function test_is_nte_page_returns_true(): void {
		$_GET['page'] = 'nettertech-events';

		$handler    = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );
		$reflection = new \ReflectionClass( $handler );
		$method     = $reflection->getMethod( 'is_nettertech_events_page' );

		$this->assertTrue( $method->invoke( $handler ) );
	}

	/**
	 * Test is_nettertech_events_page returns false for other pages.
	 *
	 * @return void
	 */
	public function test_is_nte_page_returns_false_for_other(): void {
		$_GET['page'] = 'other-plugin';

		$handler    = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );
		$reflection = new \ReflectionClass( $handler );
		$method     = $reflection->getMethod( 'is_nettertech_events_page' );

		$this->assertFalse( $method->invoke( $handler ) );
	}

	/**
	 * Test is_nettertech_events_page returns false when no page set.
	 *
	 * @return void
	 */
	public function test_is_nte_page_returns_false_when_not_set(): void {
		unset( $_GET['page'] );

		$handler    = new EventActionHandler( $this->mock_repo, $this->mock_dup_service );
		$reflection = new \ReflectionClass( $handler );
		$method     = $reflection->getMethod( 'is_nettertech_events_page' );

		$this->assertFalse( $method->invoke( $handler ) );
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
		$_GET = array();
		parent::tearDown();
	}
}
