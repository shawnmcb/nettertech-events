<?php
/**
 * Tests for RevisionService.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Repositories\RevisionRepository;
use NetterTechEvents\Services\RevisionService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @coversDefaultClass \NetterTechEvents\Services\RevisionService
 */
class RevisionServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Mock revision repository.
	 *
	 * @var RevisionRepository|Mockery\MockInterface
	 */
	private $revision_repo;

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Mock wpdb instance.
	 *
	 * @var \wpdb|Mockery\MockInterface
	 */
	private $mock_wpdb;

	/**
	 * Service under test.
	 *
	 * @var RevisionService
	 */
	private RevisionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->revision_repo   = Mockery::mock( RevisionRepository::class );
		$this->event_repo      = Mockery::mock( EventRepositoryInterface::class );
		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );

		$this->mock_wpdb = Mockery::mock( 'wpdb' );
		$this->mock_wpdb->prefix = 'wp_';

		$this->service = new RevisionService(
			$this->revision_repo,
			$this->event_repo,
			$this->occurrence_repo,
			$this->mock_wpdb
		);
	}

	// =========================================================================
	// capture_pre_save_snapshot() Tests
	// =========================================================================

	/**
	 * @covers ::capture_pre_save_snapshot
	 */
	public function test_capture_skips_new_events(): void {
		$event     = new Event();
		$event->id = null;

		$this->revision_repo->shouldNotReceive( 'insert' );

		$this->service->capture_pre_save_snapshot( $event, array() );
	}

	/**
	 * @covers ::capture_pre_save_snapshot
	 */
	public function test_capture_skips_zero_id(): void {
		$event     = new Event();
		$event->id = 0;

		$this->revision_repo->shouldNotReceive( 'insert' );

		$this->service->capture_pre_save_snapshot( $event, array() );
	}

	/**
	 * @covers ::capture_pre_save_snapshot
	 */
	public function test_capture_creates_revision_on_change(): void {
		$event        = new Event();
		$event->id    = 5;
		$event->title = 'New Title';
		$event->slug  = 'new-title';

		// Build old row from DB (bypassing identity map).
		$old_row = (object) array(
			'id'                  => 5,
			'post_id'             => null,
			'title'               => 'Old Title',
			'slug'                => 'old-title',
			'description'         => '',
			'excerpt'             => '',
			'featured_image_id'   => null,
			'status'              => 'draft',
			'event_type'          => 'single',
			'series_id'           => null,
			'venue_name'          => null,
			'venue_address'       => null,
			'recurrence_rule'     => null,
			'recurrence_end_date' => null,
			'layout_config'       => null,
			'reminders_enabled'   => null,
			'created_at'          => '2025-01-01 00:00:00',
			'updated_at'          => '2025-01-01 00:00:00',
		);

		$this->mock_wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'SELECT * FROM wp_nettertech_events_events WHERE id = 5' );

		$this->mock_wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( $old_row );

		$this->occurrence_repo->shouldReceive( 'for_event' )
			->andReturn( array() );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->revision_repo->shouldReceive( 'insert' )
			->once()
			->andReturn( 1 );

		$this->revision_repo->shouldReceive( 'prune' )
			->once();

		$this->service->capture_pre_save_snapshot( $event, array() );
	}

	/**
	 * @covers ::capture_pre_save_snapshot
	 */
	public function test_capture_skips_when_no_changes(): void {
		$event        = new Event();
		$event->id    = 5;
		$event->title = 'Same Title';
		$event->slug  = 'same-title';

		$old_row = (object) array(
			'id'                  => 5,
			'post_id'             => null,
			'title'               => 'Same Title',
			'slug'                => 'same-title',
			'description'         => '',
			'excerpt'             => '',
			'featured_image_id'   => null,
			'status'              => 'draft',
			'event_type'          => 'single',
			'series_id'           => null,
			'venue_name'          => null,
			'venue_address'       => null,
			'recurrence_rule'     => null,
			'recurrence_end_date' => null,
			'layout_config'       => null,
			'reminders_enabled'   => null,
			'created_at'          => '2025-01-01 00:00:00',
			'updated_at'          => '2025-01-01 00:00:00',
		);

		$this->mock_wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'query' );

		$this->mock_wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( $old_row );

		$this->occurrence_repo->shouldReceive( 'for_event' )
			->andReturn( array() );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->revision_repo->shouldNotReceive( 'insert' );

		$this->service->capture_pre_save_snapshot( $event, array() );
	}

	/**
	 * @covers ::capture_pre_save_snapshot
	 */
	public function test_capture_skips_when_event_not_in_db(): void {
		$event     = new Event();
		$event->id = 999;

		$this->mock_wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'query' );

		$this->mock_wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturnNull();

		$this->revision_repo->shouldNotReceive( 'insert' );

		$this->service->capture_pre_save_snapshot( $event, array() );
	}

	// =========================================================================
	// generate_change_summary() Tests
	// =========================================================================

	/**
	 * @covers ::generate_change_summary
	 */
	public function test_generate_change_summary_detects_changes(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$old = array( 'title' => 'Old', 'slug' => 'old', 'description' => 'same' );
		$new = array( 'title' => 'New', 'slug' => 'new', 'description' => 'same' );

		$result = $this->service->generate_change_summary( $old, $new );

		$this->assertStringContainsString( 'Title', $result );
		$this->assertStringContainsString( 'Slug', $result );
		$this->assertStringStartsWith( 'Changed ', $result );
	}

	/**
	 * @covers ::generate_change_summary
	 */
	public function test_generate_change_summary_returns_empty_when_no_changes(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$old = array( 'title' => 'Same', 'slug' => 'same' );
		$new = array( 'title' => 'Same', 'slug' => 'same' );

		$result = $this->service->generate_change_summary( $old, $new );

		$this->assertSame( '', $result );
	}

	// =========================================================================
	// restore() Tests
	// =========================================================================

	/**
	 * @covers ::restore
	 */
	public function test_restore_returns_event_id_on_success(): void {
		$revision = (object) array(
			'id'            => 10,
			'event_id'      => 5,
			'revision_data' => json_encode( array(
				'title'  => 'Old Title',
				'slug'   => 'old-title',
				'status' => 'published',
			) ),
			'change_summary' => 'Changed Title',
		);

		$event        = new Event();
		$event->id    = 5;
		$event->title = 'Current Title';

		$this->revision_repo->shouldReceive( 'find' )
			->with( 10 )
			->once()
			->andReturn( $revision );

		$this->event_repo->shouldReceive( 'find' )
			->with( 5 )
			->once()
			->andReturn( $event );

		$this->occurrence_repo->shouldReceive( 'for_event' )
			->andReturn( array() );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->revision_repo->shouldReceive( 'insert' )
			->once()
			->andReturn( 11 );

		$this->event_repo->shouldReceive( 'save' )
			->once()
			->andReturn( $event );

		$this->revision_repo->shouldReceive( 'prune' )
			->once();

		$result = $this->service->restore( 10 );

		$this->assertSame( 5, $result );
		$this->assertSame( 'Old Title', $event->title );
	}

	/**
	 * @covers ::restore
	 */
	public function test_restore_returns_false_when_revision_not_found(): void {
		$this->revision_repo->shouldReceive( 'find' )
			->with( 999 )
			->once()
			->andReturnNull();

		$result = $this->service->restore( 999 );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::restore
	 */
	public function test_restore_returns_false_when_event_not_found(): void {
		$revision = (object) array(
			'id'            => 10,
			'event_id'      => 5,
			'revision_data' => json_encode( array( 'title' => 'Test' ) ),
		);

		$this->revision_repo->shouldReceive( 'find' )
			->with( 10 )
			->once()
			->andReturn( $revision );

		$this->event_repo->shouldReceive( 'find' )
			->with( 5 )
			->once()
			->andReturnNull();

		$result = $this->service->restore( 10 );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// compute_diff() Tests
	// =========================================================================

	/**
	 * @covers ::compute_diff
	 */
	public function test_compute_diff_returns_changed_fields(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$old = array( 'title' => 'Old', 'status' => 'draft', 'slug' => 'same' );
		$new = array( 'title' => 'New', 'status' => 'published', 'slug' => 'same' );

		$diff = $this->service->compute_diff( $old, $new );

		$this->assertCount( 2, $diff );
		$this->assertSame( 'title', $diff[0]['field'] );
		$this->assertSame( 'Old', $diff[0]['old'] );
		$this->assertSame( 'New', $diff[0]['new'] );
	}

	/**
	 * @covers ::compute_diff
	 */
	public function test_compute_diff_skips_metadata_fields(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$old = array( '_occurrences' => 3, 'id' => 5, 'title' => 'Same' );
		$new = array( '_occurrences' => 5, 'id' => 5, 'title' => 'Same' );

		$diff = $this->service->compute_diff( $old, $new );

		$this->assertEmpty( $diff );
	}

	// =========================================================================
	// cleanup_on_event_delete() Tests
	// =========================================================================

	/**
	 * @covers ::cleanup_on_event_delete
	 */
	public function test_cleanup_deletes_all_revisions(): void {
		$event     = new Event();
		$event->id = 5;

		$this->revision_repo->shouldReceive( 'delete_for_event' )
			->with( 5 )
			->once();

		$this->service->cleanup_on_event_delete( 5, $event );
	}
}
