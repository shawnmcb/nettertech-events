<?php
/**
 * EventsBulkActionHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\ListTables
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\ListTables;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\ListTables\EventsBulkActionHandler;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Services\EventDuplicationService;

/**
 * Tests mutating events bulk-action decisions outside the list table renderer.
 */
class EventsBulkActionHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Duplication service.
	 *
	 * @var EventDuplicationService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $duplication_service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->event_repo          = $this->createMock( EventRepositoryInterface::class );
		$this->duplication_service = $this->createMock( EventDuplicationService::class );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET     = array();
		$_REQUEST = array();

		parent::tearDown();
	}

	/**
	 * Test missing capability prevents mutations.
	 *
	 * @return void
	 */
	public function test_missing_capability_prevents_mutations(): void {
		$_REQUEST['_wpnonce'] = 'valid';
		$_REQUEST['event']    = array( '7' );

		Functions\when( 'current_user_can' )->justReturn( false );

		$this->event_repo->expects( $this->never() )->method( 'delete' );

		$this->create_handler()->process( 'delete', 'events' );
	}

	/**
	 * Test invalid nonce prevents mutations.
	 *
	 * @return void
	 */
	public function test_invalid_nonce_prevents_mutations(): void {
		$_REQUEST['_wpnonce'] = 'invalid';
		$_REQUEST['event']    = array( '7' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->event_repo->expects( $this->never() )->method( 'delete' );

		$this->create_handler()->process( 'delete', 'events' );
	}

	/**
	 * Test delete bulk action sanitizes IDs and deletes valid events.
	 *
	 * @return void
	 */
	public function test_delete_sanitizes_ids_and_deletes_valid_events(): void {
		$_REQUEST['_wpnonce'] = 'valid';
		$_REQUEST['event']    = array( '7', '0', '-9' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$deleted_ids = array();
		$this->event_repo
			->expects( $this->exactly( 2 ) )
			->method( 'delete' )
			->willReturnCallback(
				function ( int $event_id ) use ( &$deleted_ids ): bool {
					$deleted_ids[] = $event_id;
					return true;
				}
			);

		$this->create_handler()->process( 'delete', 'events' );

		$this->assertSame( array( 7, 9 ), $deleted_ids );
	}

	/**
	 * Test publish saves found events and skips missing events.
	 *
	 * @return void
	 */
	public function test_publish_saves_found_events_and_skips_missing(): void {
		$_REQUEST['_wpnonce'] = 'valid';
		$_REQUEST['event']    = array( '11', '12' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$event         = new Event();
		$event->id     = 11;
		$event->status = EventStatus::DRAFT;

		$this->event_repo
			->expects( $this->exactly( 2 ) )
			->method( 'find' )
			->willReturnMap(
				array(
					array( 11, $event ),
					array( 12, null ),
				)
			);

		$this->event_repo
			->expects( $this->once() )
			->method( 'save' )
			->with( $this->callback(
				function ( Event $saved_event ): bool {
					return EventStatus::PUBLISHED === $saved_event->status;
				}
			) );

		$this->create_handler()->process( 'publish', 'events' );
	}

	/**
	 * Test category add attaches every selected category to every selected event.
	 *
	 * @return void
	 */
	public function test_category_add_attaches_selected_categories(): void {
		$_REQUEST['_wpnonce']         = 'valid';
		$_REQUEST['event']            = array( '3', '4' );
		$_REQUEST['bulk_category_ids'] = array( '8', '0', '9' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$attachments   = array();
		$category_repo = $this->createMock( CategoryRepositoryInterface::class );
		$category_repo
			->expects( $this->exactly( 4 ) )
			->method( 'attach_to_event' )
			->willReturnCallback(
				function ( int $event_id, int $category_id ) use ( &$attachments ): bool {
					$attachments[] = array( $event_id, $category_id );
					return true;
				}
			);

		$this->create_handler( $category_repo )->process( 'bulk_category_add', 'events' );

		$this->assertSame(
			array(
				array( 3, 8 ),
				array( 3, 9 ),
				array( 4, 8 ),
				array( 4, 9 ),
			),
			$attachments
		);
	}

	/**
	 * Test delete skips events with order-linked sales and redirects with a notice.
	 *
	 * @return void
	 */
	public function test_delete_skips_events_with_sales_and_redirects(): void {
		$_REQUEST['_wpnonce'] = 'valid';
		$_REQUEST['event']    = array( '7', '9' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);

		$ticket_repo = $this->createMock( TicketRepositoryInterface::class );
		$ticket_repo->method( 'has_paid_attendance' )->willReturnMap(
			array(
				array( 7, true ),
				array( 9, false ),
			)
		);

		// Only the no-sales event (9) is deleted; the sold event (7) is skipped.
		$deleted = array();
		$this->event_repo
			->expects( $this->once() )
			->method( 'delete' )
			->willReturnCallback(
				function ( int $id ) use ( &$deleted ): bool {
					$deleted[] = $id;
					return true;
				}
			);

		$redirect_url = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \Exception( 'redirect' );
			}
		);

		try {
			$this->create_handler( null, $ticket_repo )->process( 'delete', 'events' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertSame( array( 9 ), $deleted );
		$this->assertStringContainsString( 'message=bulk_skipped', $redirect_url );
		$this->assertStringContainsString( 'skipped=1', $redirect_url );
	}

	/**
	 * Test delete does not redirect when no event has sales.
	 *
	 * @return void
	 */
	public function test_delete_no_redirect_when_none_skipped(): void {
		$_REQUEST['_wpnonce'] = 'valid';
		$_REQUEST['event']    = array( '7', '9' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$ticket_repo = $this->createMock( TicketRepositoryInterface::class );
		$ticket_repo->method( 'has_paid_attendance' )->willReturn( false );

		$this->event_repo
			->expects( $this->exactly( 2 ) )
			->method( 'delete' )
			->willReturn( true );

		$redirected = false;
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirected ) {
				$redirected = true;
			}
		);

		$this->create_handler( null, $ticket_repo )->process( 'delete', 'events' );

		$this->assertFalse( $redirected );
	}

	/**
	 * Create handler.
	 *
	 * @param CategoryRepositoryInterface|null $category_repo Category repository.
	 * @param TicketRepositoryInterface|null   $ticket_repo   Ticket repository.
	 * @return EventsBulkActionHandler
	 */
	private function create_handler( ?CategoryRepositoryInterface $category_repo = null, ?TicketRepositoryInterface $ticket_repo = null ): EventsBulkActionHandler {
		return new EventsBulkActionHandler( $this->event_repo, $this->duplication_service, $category_repo, $ticket_repo );
	}
}
