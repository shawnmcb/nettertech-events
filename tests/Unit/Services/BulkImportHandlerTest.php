<?php
/**
 * BulkImportHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Services\BulkImportHandler;

/**
 * Test BulkImportHandler batch completion handling.
 *
 * Verifies that when a bulk import batch completes, the handler:
 * - Invalidates caches for imported entities
 * - Logs the batch import to the activity log
 * - Triggers WC stock sync for ticket types with correct (TicketType, Occurrence) args
 * - Never throws exceptions (fault-tolerant)
 *
 * @covers \NetterTechEvents\Services\BulkImportHandler
 */
class BulkImportHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Activity log mock.
	 *
	 * @var ActivityLogServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $activity_log;

	/**
	 * Ticket type repository mock.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Occurrence repository mock.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * System under test.
	 *
	 * @var BulkImportHandler
	 */
	private BulkImportHandler $sut;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->activity_log     = $this->createMock( ActivityLogServiceInterface::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepositoryInterface::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->sut              = new BulkImportHandler(
			$this->activity_log,
			$this->ticket_type_repo,
			$this->occurrence_repo
		);
	}

	// =========================================================================
	// register()
	// =========================================================================

	/**
	 * Test register hooks into BULK_IMPORT_COMPLETED.
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

		$this->assertCount( 1, $registered );
		$this->assertSame( Hooks::BULK_IMPORT_COMPLETED, $registered[0]['tag'] );
		$this->assertSame( 4, $registered[0]['args'] );
	}

	// =========================================================================
	// handle_batch_complete()
	// =========================================================================

	/**
	 * Test handle_batch_complete logs to activity log.
	 *
	 * @return void
	 */
	public function test_handle_batch_complete_logs_to_activity_log(): void {
		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with(
				'bulk_import',
				'event',
				101,
				$this->stringContains( 'Imported 3 event(s) from tec' ),
				$this->callback(
					function ( $details ) {
						return 3 === $details['count'] && 'tec' === $details['source'];
					}
				)
			);

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn();

		$this->sut->handle_batch_complete( 'event', 3, array( 101, 102, 103 ), 'tec' );
	}

	/**
	 * Test handle_batch_complete invalidates caches.
	 *
	 * @return void
	 */
	public function test_handle_batch_complete_invalidates_caches(): void {
		$deleted = array();

		Functions\when( 'wp_cache_delete' )->alias(
			function ( string $key, string $group = '' ) use ( &$deleted ): bool {
				$deleted[] = array( 'key' => $key, 'group' => $group );
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn();

		$this->activity_log->method( 'log' );

		$this->sut->handle_batch_complete( 'event', 2, array( 10, 20 ), 'tec' );

		// Should delete cache for each entity ID in the nettertech_event group.
		$this->assertCount( 2, $deleted );
		$this->assertSame( '10', $deleted[0]['key'] );
		$this->assertSame( 'nettertech_events_event', $deleted[0]['group'] );
		$this->assertSame( '20', $deleted[1]['key'] );
		$this->assertSame( 'nettertech_events_event', $deleted[1]['group'] );
	}

	/**
	 * Test handle_batch_complete triggers WC sync with correct (TicketType, Occurrence) args.
	 *
	 * NTE-010 regression: BulkImportHandler previously fired the hook with a bare
	 * int ID instead of (TicketType, Occurrence), causing a TypeError in ProductManager.
	 *
	 * @return void
	 */
	public function test_handle_batch_complete_triggers_wc_sync_with_correct_args(): void {
		$ticket_type         = new TicketType();
		$ticket_type->id     = 50;
		$ticket_type->occurrence_id = 99;

		$occurrence     = new Occurrence();
		$occurrence->id = 99;

		$ticket_type2         = new TicketType();
		$ticket_type2->id     = 60;
		$ticket_type2->occurrence_id = 100;

		$occurrence2     = new Occurrence();
		$occurrence2->id = 100;

		$this->ticket_type_repo->method( 'find' )->willReturnMap(
			array(
				array( 50, $ticket_type ),
				array( 60, $ticket_type2 ),
			)
		);

		$this->occurrence_repo->method( 'find' )->willReturnMap(
			array(
				array( 99, $occurrence ),
				array( 100, $occurrence2 ),
			)
		);

		$actions_fired = array();

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'function_exists' )->alias(
			function ( string $name ): bool {
				return 'WC' === $name ? true : \function_exists( $name );
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $tag, ...$args ) use ( &$actions_fired ): void {
				$actions_fired[] = array( 'tag' => $tag, 'args' => $args );
			}
		);

		$this->activity_log->method( 'log' );

		$this->sut->handle_batch_complete( 'ticket_type', 2, array( 50, 60 ), 'tec' );

		$sync_actions = array_values(
			array_filter(
				$actions_fired,
				fn( $a ) => Hooks::TICKET_TYPE_SYNC_PRODUCT === $a['tag']
			)
		);

		$this->assertCount( 2, $sync_actions );

		// Assert both args are the correct object types, not bare int IDs.
		$this->assertInstanceOf( TicketType::class, $sync_actions[0]['args'][0] );
		$this->assertInstanceOf( Occurrence::class, $sync_actions[0]['args'][1] );
		$this->assertSame( 50, $sync_actions[0]['args'][0]->id );
		$this->assertSame( 99, $sync_actions[0]['args'][1]->id );

		$this->assertInstanceOf( TicketType::class, $sync_actions[1]['args'][0] );
		$this->assertInstanceOf( Occurrence::class, $sync_actions[1]['args'][1] );
		$this->assertSame( 60, $sync_actions[1]['args'][0]->id );
		$this->assertSame( 100, $sync_actions[1]['args'][1]->id );
	}

	/**
	 * Test WC sync is skipped for ticket types with no occurrence_id (non-occurrence scope).
	 *
	 * Template and event-scoped ticket types have no associated occurrence and
	 * therefore no WC product to sync.
	 *
	 * @return void
	 */
	public function test_wc_sync_skips_ticket_types_without_occurrence_id(): void {
		$ticket_type              = new TicketType();
		$ticket_type->id          = 50;
		$ticket_type->occurrence_id = null;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->expects( $this->never() )->method( 'find' );

		$actions_fired = array();

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'function_exists' )->alias(
			static function ( string $name ): bool {
				return 'WC' === $name ? true : \function_exists( $name );
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $tag, ...$args ) use ( &$actions_fired ): void {
				$actions_fired[] = array( 'tag' => $tag, 'args' => $args );
			}
		);

		$this->activity_log->method( 'log' );

		$this->sut->handle_batch_complete( 'ticket_type', 1, array( 50 ), 'tec' );

		$sync_actions = array_filter(
			$actions_fired,
			fn( $a ) => Hooks::TICKET_TYPE_SYNC_PRODUCT === $a['tag']
		);

		$this->assertCount( 0, $sync_actions );
	}

	/**
	 * Test WC sync is skipped when the ticket type is not found.
	 *
	 * @return void
	 */
	public function test_wc_sync_skips_missing_ticket_types(): void {
		$this->ticket_type_repo->method( 'find' )->willReturn( null );
		$this->occurrence_repo->expects( $this->never() )->method( 'find' );

		$actions_fired = array();

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'function_exists' )->alias(
			function ( string $name ): bool {
				return 'WC' === $name ? true : \function_exists( $name );
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $tag, ...$args ) use ( &$actions_fired ): void {
				$actions_fired[] = array( 'tag' => $tag, 'args' => $args );
			}
		);

		$this->activity_log->method( 'log' );

		$this->sut->handle_batch_complete( 'ticket_type', 1, array( 999 ), 'tec' );

		$sync_actions = array_filter(
			$actions_fired,
			fn( $a ) => Hooks::TICKET_TYPE_SYNC_PRODUCT === $a['tag']
		);

		$this->assertCount( 0, $sync_actions );
	}

	/**
	 * Test WC sync is skipped when the occurrence cannot be found.
	 *
	 * @return void
	 */
	public function test_wc_sync_skips_when_occurrence_not_found(): void {
		$ticket_type              = new TicketType();
		$ticket_type->id          = 50;
		$ticket_type->occurrence_id = 99;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->method( 'find' )->willReturn( null );

		$actions_fired = array();

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'function_exists' )->alias(
			function ( string $name ): bool {
				return 'WC' === $name ? true : \function_exists( $name );
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $tag, ...$args ) use ( &$actions_fired ): void {
				$actions_fired[] = array( 'tag' => $tag, 'args' => $args );
			}
		);

		$this->activity_log->method( 'log' );

		$this->sut->handle_batch_complete( 'ticket_type', 1, array( 50 ), 'tec' );

		$sync_actions = array_filter(
			$actions_fired,
			fn( $a ) => Hooks::TICKET_TYPE_SYNC_PRODUCT === $a['tag']
		);

		$this->assertCount( 0, $sync_actions );
	}

	/**
	 * Test handle_batch_complete does NOT trigger WC sync for non-ticket-type entities.
	 *
	 * @return void
	 */
	public function test_handle_batch_complete_skips_wc_sync_for_non_ticket_types(): void {
		$this->ticket_type_repo->expects( $this->never() )->method( 'find' );
		$this->occurrence_repo->expects( $this->never() )->method( 'find' );

		$actions_fired = array();

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'do_action' )->alias(
			function ( string $tag, ...$args ) use ( &$actions_fired ): void {
				$actions_fired[] = array( 'tag' => $tag, 'args' => $args );
			}
		);

		$this->activity_log->method( 'log' );

		$this->sut->handle_batch_complete( 'event', 2, array( 10, 20 ), 'tec' );

		$sync_actions = array_filter(
			$actions_fired,
			fn( $a ) => Hooks::TICKET_TYPE_SYNC_PRODUCT === $a['tag']
		);

		$this->assertCount( 0, $sync_actions );
	}

	/**
	 * Test handle_batch_complete does not throw when activity log fails.
	 *
	 * @return void
	 */
	public function test_handle_batch_complete_is_fault_tolerant(): void {
		$this->activity_log->method( 'log' )
			->willThrowException( new \RuntimeException( 'DB down' ) );

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'error_log' )->justReturn();

		// Should not throw.
		$this->sut->handle_batch_complete( 'event', 1, array( 1 ), 'tec' );

		$this->assertTrue( true, 'Handler should not throw when steps fail' );
	}

	/**
	 * Test handle_batch_complete does not throw when cache delete throws.
	 *
	 * @return void
	 */
	public function test_handle_batch_complete_tolerates_cache_failure(): void {
		Functions\when( 'wp_cache_delete' )->alias(
			function (): bool {
				throw new \RuntimeException( 'Cache backend down' );
			}
		);
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'error_log' )->justReturn();

		$this->activity_log->method( 'log' );

		// Should not throw.
		$this->sut->handle_batch_complete( 'event', 1, array( 1 ), 'tec' );

		$this->assertTrue( true, 'Handler should tolerate cache failures' );
	}
}
