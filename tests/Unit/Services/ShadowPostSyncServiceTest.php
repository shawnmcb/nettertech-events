<?php
/**
 * ShadowPostSyncService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Services\ShadowPostSyncService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test ShadowPostSyncService functionality.
 */
class ShadowPostSyncServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Mock wpdb instance.
	 *
	 * @var \wpdb|Mockery\MockInterface
	 */
	private $db;

	/**
	 * Service under test.
	 *
	 * @var ShadowPostSyncService
	 */
	private ShadowPostSyncService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->event_repo = Mockery::mock( EventRepositoryInterface::class );
		$this->db         = Mockery::mock( \wpdb::class );
		$this->db->postmeta = 'wp_postmeta';
		$this->db->posts    = 'wp_posts';
		$this->service    = new ShadowPostSyncService( $this->event_repo, $this->db );
	}

	/**
	 * Create a test event model.
	 *
	 * @param int    $id     Event ID.
	 * @param string $title  Event title.
	 * @param string $slug   Event slug.
	 * @param string $status Event status.
	 * @return Event
	 */
	private function make_event( int $id = 1, string $title = 'Test Event', string $slug = 'test-event', EventStatus $status = EventStatus::PUBLISHED ): Event {
		$event         = new Event();
		$event->id     = $id;
		$event->title  = $title;
		$event->slug   = $slug;
		$event->status = $status;
		return $event;
	}

	/**
	 * Stub the find_shadow_post wpdb query.
	 *
	 * @param int|null $return_post_id Post ID to return, or null for not found.
	 * @return void
	 */
	private function stub_find_shadow_post( ?int $return_post_id ): void {
		$this->db->shouldReceive( 'prepare' )
			->andReturn( 'PREPARED_SQL' );
		$this->db->shouldReceive( 'get_var' )
			->andReturn( $return_post_id ? (string) $return_post_id : null );
	}

	/**
	 * @test
	 */
	public function test_register_hooks_event_lifecycle(): void {
		$hooks_added = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks_added ) {
				$hooks_added[] = $hook;
				return true;
			}
		);

		$this->service->register();

		$this->assertContains( 'nettertech_events_after_save_event', $hooks_added );
		$this->assertContains( 'nettertech_events_after_delete_event', $hooks_added );
	}

	/**
	 * @test
	 */
	public function test_sync_creates_shadow_post_for_new_event(): void {
		$event = $this->make_event();

		$this->stub_find_shadow_post( null );

		$inserted_data = null;

		Functions\when( 'wp_insert_post' )->alias(
			function ( $data ) use ( &$inserted_data ) {
				$inserted_data = $data;
				return 42;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->service->sync( $event );

		$this->assertSame( 42, $result );
		$this->assertNotNull( $inserted_data );
		$this->assertSame( 'nettertech_event', $inserted_data['post_type'] );
		$this->assertSame( 'Test Event', $inserted_data['post_title'] );
		$this->assertSame( 'test-event', $inserted_data['post_name'] );
		$this->assertSame( 'publish', $inserted_data['post_status'] );
	}

	/**
	 * @test
	 */
	public function test_sync_updates_existing_shadow_post(): void {
		$event = $this->make_event( 1, 'Updated Title', 'updated-title' );

		$this->stub_find_shadow_post( 42 );

		$updated_data = null;

		Functions\when( 'wp_update_post' )->alias(
			function ( $data ) use ( &$updated_data ) {
				$updated_data = $data;
				return 42;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->service->sync( $event );

		$this->assertSame( 42, $result );
		$this->assertNotNull( $updated_data );
		$this->assertSame( 42, $updated_data['ID'] );
		$this->assertSame( 'Updated Title', $updated_data['post_title'] );
		$this->assertSame( 'updated-title', $updated_data['post_name'] );
	}

	/**
	 * @test
	 */
	public function test_sync_returns_false_for_event_without_id(): void {
		$event     = new Event();
		$event->id = null;

		$result = $this->service->sync( $event );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 */
	public function test_sync_maps_draft_status(): void {
		$event = $this->make_event( 1, 'Draft Event', 'draft-event', EventStatus::DRAFT );

		$this->stub_find_shadow_post( null );

		$inserted_data = null;

		Functions\when( 'wp_insert_post' )->alias(
			function ( $data ) use ( &$inserted_data ) {
				$inserted_data = $data;
				return 43;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->service->sync( $event );

		$this->assertSame( 'draft', $inserted_data['post_status'] );
	}

	/**
	 * @test
	 */
	public function test_sync_maps_cancelled_to_private(): void {
		$event = $this->make_event( 1, 'Cancelled Event', 'cancelled-event', EventStatus::CANCELLED );

		$this->stub_find_shadow_post( null );

		$inserted_data = null;

		Functions\when( 'wp_insert_post' )->alias(
			function ( $data ) use ( &$inserted_data ) {
				$inserted_data = $data;
				return 44;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->service->sync( $event );

		$this->assertSame( 'private', $inserted_data['post_status'] );
	}

	/**
	 * @test
	 */
	public function test_sync_returns_false_on_wp_error(): void {
		$event    = $this->make_event();
		$wp_error = Mockery::mock( 'WP_Error' );

		$this->stub_find_shadow_post( null );

		Functions\when( 'wp_insert_post' )->justReturn( $wp_error );

		Functions\when( 'is_wp_error' )->alias(
			function ( $value ) use ( $wp_error ) {
				return $value === $wp_error;
			}
		);

		$result = $this->service->sync( $event );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 */
	public function test_on_delete_removes_shadow_post(): void {
		$event = $this->make_event();

		$this->stub_find_shadow_post( 42 );

		$deleted_id = null;

		Functions\when( 'wp_delete_post' )->alias(
			function ( $id, $force ) use ( &$deleted_id ) {
				$deleted_id = $id;
				$post       = Mockery::mock( 'WP_Post' );
				$post->ID   = $id;
				return $post;
			}
		);

		$result = $this->service->on_delete( 1, $event );

		$this->assertTrue( $result );
		$this->assertSame( 42, $deleted_id );
	}

	/**
	 * @test
	 */
	public function test_on_delete_returns_false_when_no_shadow_post(): void {
		$event = $this->make_event();

		$this->stub_find_shadow_post( null );

		$result = $this->service->on_delete( 1, $event );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 */
	public function test_find_shadow_post_returns_post_id(): void {
		$this->stub_find_shadow_post( 42 );

		$result = $this->service->find_shadow_post( 1 );

		$this->assertSame( 42, $result );
	}

	/**
	 * @test
	 */
	public function test_find_shadow_post_returns_false_when_not_found(): void {
		$this->stub_find_shadow_post( null );

		$result = $this->service->find_shadow_post( 999 );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 */
	public function test_sync_all_creates_shadow_posts_for_published_events(): void {
		$event1 = $this->make_event( 1, 'Event One', 'event-one' );
		$event2 = $this->make_event( 2, 'Event Two', 'event-two' );

		$this->event_repo->shouldReceive( 'paginate' )
			->once()
			->with(
				Mockery::on(
					function ( array $args ) {
						return 'published' === $args['status'] && 9999 === $args['per_page'];
					}
				)
			)
			->andReturn( array( 'items' => array( $event1, $event2 ), 'total' => 2, 'pages' => 1 ) );

		// Both events have no existing shadow posts.
		$this->db->shouldReceive( 'prepare' )->andReturn( 'PREPARED_SQL' );
		$this->db->shouldReceive( 'get_var' )->andReturn( null );

		Functions\when( 'wp_insert_post' )->justReturn( 100 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$results = $this->service->sync_all();

		$this->assertSame( 2, $results['synced'] );
		$this->assertSame( 0, $results['skipped'] );
		$this->assertSame( 0, $results['failed'] );
	}

	/**
	 * @test
	 */
	public function test_sync_all_skips_events_with_existing_shadow_posts(): void {
		$event1 = $this->make_event( 1, 'Existing Event', 'existing-event' );

		$this->event_repo->shouldReceive( 'paginate' )
			->once()
			->andReturn( array( 'items' => array( $event1 ), 'total' => 1, 'pages' => 1 ) );

		$this->db->shouldReceive( 'prepare' )->andReturn( 'PREPARED_SQL' );
		$this->db->shouldReceive( 'get_var' )->andReturn( '42' );

		$results = $this->service->sync_all();

		$this->assertSame( 0, $results['synced'] );
		$this->assertSame( 1, $results['skipped'] );
		$this->assertSame( 0, $results['failed'] );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_prevents_reentrant_calls(): void {
		$event = $this->make_event();

		$this->stub_find_shadow_post( null );

		// On first call, wp_insert_post triggers a re-entrant sync().
		$service = $this->service;
		Functions\when( 'wp_insert_post' )->alias(
			function ( $data ) use ( $service, $event ) {
				// Simulate re-entrant call while syncing is true.
				$reentrant_result = $service->sync( $event );
				$this->assertFalse( $reentrant_result, 'Re-entrant sync should return false' );
				return 50;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->service->sync( $event );

		$this->assertSame( 50, $result );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_maps_postponed_to_private(): void {
		$event = $this->make_event( 1, 'Postponed Event', 'postponed-event', EventStatus::POSTPONED );

		$this->stub_find_shadow_post( null );

		$inserted_data = null;

		Functions\when( 'wp_insert_post' )->alias(
			function ( $data ) use ( &$inserted_data ) {
				$inserted_data = $data;
				return 45;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->service->sync( $event );

		$this->assertSame( 'private', $inserted_data['post_status'] );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_maps_draft_status_to_draft_post_status(): void {
		$event = $this->make_event( 1, 'Draft Status', 'draft-status', EventStatus::DRAFT );

		$this->stub_find_shadow_post( null );

		$inserted_data = null;

		Functions\when( 'wp_insert_post' )->alias(
			function ( $data ) use ( &$inserted_data ) {
				$inserted_data = $data;
				return 46;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->service->sync( $event );

		$this->assertSame( 'draft', $inserted_data['post_status'] );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_calls_update_post_meta_on_new_insert(): void {
		$event = $this->make_event();

		$this->stub_find_shadow_post( null );

		Functions\when( 'wp_insert_post' )->justReturn( 55 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$meta_calls = array();
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$meta_calls ) {
				$meta_calls[] = array(
					'post_id' => $post_id,
					'key'     => $key,
					'value'   => $value,
				);
			}
		);

		$this->service->sync( $event );

		$this->assertCount( 1, $meta_calls );
		$this->assertSame( 55, $meta_calls[0]['post_id'] );
		$this->assertSame( '_nettertech_events_event_id', $meta_calls[0]['key'] );
		$this->assertSame( 1, $meta_calls[0]['value'] );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_returns_false_when_update_post_returns_wp_error(): void {
		$event    = $this->make_event();
		$wp_error = Mockery::mock( 'WP_Error' );

		$this->stub_find_shadow_post( 42 );

		Functions\when( 'wp_update_post' )->justReturn( $wp_error );

		Functions\when( 'is_wp_error' )->alias(
			function ( $value ) use ( $wp_error ) {
				return $value === $wp_error;
			}
		);

		$result = $this->service->sync( $event );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_resets_syncing_flag_after_wp_error(): void {
		$event    = $this->make_event();
		$wp_error = Mockery::mock( 'WP_Error' );

		$this->stub_find_shadow_post( null );

		Functions\when( 'wp_insert_post' )->justReturn( $wp_error );

		Functions\when( 'is_wp_error' )->alias(
			function ( $value ) use ( $wp_error ) {
				return $value === $wp_error;
			}
		);

		// First call fails.
		$this->assertFalse( $this->service->sync( $event ) );

		// Second call should NOT be blocked by syncing flag.
		Functions\when( 'wp_insert_post' )->justReturn( 60 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->stub_find_shadow_post( null );

		$result = $this->service->sync( $event );

		$this->assertSame( 60, $result );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_on_delete_returns_false_when_wp_delete_post_returns_false(): void {
		$event = $this->make_event();

		$this->stub_find_shadow_post( 42 );

		Functions\when( 'wp_delete_post' )->justReturn( false );

		$result = $this->service->on_delete( 1, $event );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_on_delete_returns_false_when_wp_delete_post_returns_null(): void {
		$event = $this->make_event();

		$this->stub_find_shadow_post( 42 );

		Functions\when( 'wp_delete_post' )->justReturn( null );

		$result = $this->service->on_delete( 1, $event );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_with_event_id_zero_returns_false(): void {
		$event     = new Event();
		$event->id = 0;

		$result = $this->service->sync( $event );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_all_counts_failed_events(): void {
		$event1 = $this->make_event( 1, 'Event One', 'event-one' );
		$event2 = $this->make_event( 2, 'Event Two', 'event-two' );

		$this->event_repo->shouldReceive( 'paginate' )
			->once()
			->andReturn( array( 'items' => array( $event1, $event2 ), 'total' => 2, 'pages' => 1 ) );

		$this->db->shouldReceive( 'prepare' )->andReturn( 'PREPARED_SQL' );
		// No existing shadow posts.
		$this->db->shouldReceive( 'get_var' )->andReturn( null );

		$wp_error = Mockery::mock( 'WP_Error' );

		Functions\when( 'wp_insert_post' )->justReturn( $wp_error );

		Functions\when( 'is_wp_error' )->alias(
			function ( $value ) use ( $wp_error ) {
				return $value === $wp_error;
			}
		);

		$results = $this->service->sync_all();

		$this->assertSame( 0, $results['synced'] );
		$this->assertSame( 0, $results['skipped'] );
		$this->assertSame( 2, $results['failed'] );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_all_with_empty_event_list(): void {
		$this->event_repo->shouldReceive( 'paginate' )
			->once()
			->andReturn( array( 'items' => array(), 'total' => 0, 'pages' => 0 ) );

		$results = $this->service->sync_all();

		$this->assertSame( 0, $results['synced'] );
		$this->assertSame( 0, $results['skipped'] );
		$this->assertSame( 0, $results['failed'] );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_all_mixed_skipped_and_synced(): void {
		$event1 = $this->make_event( 1, 'Existing Event', 'existing-event' );
		$event2 = $this->make_event( 2, 'New Event', 'new-event' );
		$event3 = $this->make_event( 3, 'Another New', 'another-new' );

		$this->event_repo->shouldReceive( 'paginate' )
			->once()
			->andReturn( array( 'items' => array( $event1, $event2, $event3 ), 'total' => 3, 'pages' => 1 ) );

		$this->db->shouldReceive( 'prepare' )->andReturn( 'PREPARED_SQL' );
		// First event has existing shadow post; second and third do not.
		$this->db->shouldReceive( 'get_var' )
			->andReturn( '99', null, null, null, null );

		Functions\when( 'wp_insert_post' )->justReturn( 100 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$results = $this->service->sync_all();

		$this->assertSame( 2, $results['synced'] );
		$this->assertSame( 1, $results['skipped'] );
		$this->assertSame( 0, $results['failed'] );
	}

	/**
	 * @test
	 * @return void
	 */
	public function test_sync_does_not_call_update_post_meta_on_update(): void {
		$event = $this->make_event();

		$this->stub_find_shadow_post( 42 );

		Functions\when( 'wp_update_post' )->justReturn( 42 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$meta_called = false;
		Functions\when( 'update_post_meta' )->alias(
			function () use ( &$meta_called ) {
				$meta_called = true;
			}
		);

		$this->service->sync( $event );

		$this->assertFalse( $meta_called, 'update_post_meta should not be called on update path' );
	}
}
