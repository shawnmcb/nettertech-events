<?php
/**
 * Tests for RevisionRepository.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\RevisionRepository;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @coversDefaultClass \NetterTechEvents\Repositories\RevisionRepository
 */
class RevisionRepositoryTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \wpdb|Mockery\MockInterface
	 */
	private $mock_db;

	/**
	 * Repository under test.
	 *
	 * @var RevisionRepository
	 */
	private RevisionRepository $repo;

	protected function setUp(): void {
		parent::setUp();

		$this->mock_db = Mockery::mock( 'wpdb' );
		$this->mock_db->prefix = 'wp_';

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->repo = new RevisionRepository( $this->mock_db );
	}

	/**
	 * @covers ::insert
	 */
	public function test_insert_stores_revision(): void {
		$this->mock_db->insert_id = 42;

		$this->mock_db->shouldReceive( 'insert' )
			->once()
			->withArgs( function ( $table, $data, $format ) {
				return str_contains( $table, 'event_revisions' )
					&& $data['event_id'] === 5
					&& $data['user_id'] === 1
					// created_at written explicitly in UTC (NTE-131).
					&& isset( $data['created_at'] )
					&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['created_at'] )
					&& $format === array( '%d', '%d', '%s', '%s', '%s' );
			} )
			->andReturn( 1 );

		$result = $this->repo->insert( 5, 1, array( 'title' => 'Test' ), 'Changed Title' );

		$this->assertSame( 42, $result );
	}

	/**
	 * @covers ::insert
	 */
	public function test_insert_returns_false_on_failure(): void {
		$this->mock_db->insert_id = 0;

		$this->mock_db->shouldReceive( 'insert' )
			->once()
			->andReturn( false );

		$result = $this->repo->insert( 5, 1, array(), 'test' );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::for_event
	 */
	public function test_for_event_returns_revisions(): void {
		$revisions = array(
			(object) array( 'id' => 2, 'event_id' => 5 ),
			(object) array( 'id' => 1, 'event_id' => 5 ),
		);

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'SELECT * FROM wp_nettertech_events_event_revisions WHERE event_id = 5 ORDER BY created_at DESC LIMIT 10' );

		$this->mock_db->shouldReceive( 'get_results' )
			->once()
			->andReturn( $revisions );

		$result = $this->repo->for_event( 5 );

		$this->assertCount( 2, $result );
	}

	/**
	 * @covers ::find
	 */
	public function test_find_returns_revision(): void {
		$revision = (object) array( 'id' => 1, 'event_id' => 5 );

		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'SELECT * FROM wp_nettertech_events_event_revisions WHERE id = 1' );

		$this->mock_db->shouldReceive( 'get_row' )
			->once()
			->andReturn( $revision );

		$result = $this->repo->find( 1 );

		$this->assertSame( 1, $result->id );
	}

	/**
	 * @covers ::find
	 */
	public function test_find_returns_null_when_not_found(): void {
		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'query' );

		$this->mock_db->shouldReceive( 'get_row' )
			->once()
			->andReturnNull();

		$result = $this->repo->find( 999 );

		$this->assertNull( $result );
	}

	/**
	 * @covers ::prune
	 */
	public function test_prune_deletes_old_revisions(): void {
		$this->mock_db->shouldReceive( 'prepare' )
			->twice()
			->andReturn( 'query1', 'query2' );

		$this->mock_db->shouldReceive( 'get_var' )
			->once()
			->andReturn( '5' );

		$this->mock_db->shouldReceive( 'query' )
			->once()
			->andReturn( 3 );

		$this->repo->prune( 1, 20 );

		// Mockery expectations verify the calls.
		$this->assertTrue( true );
	}

	/**
	 * @covers ::prune
	 */
	public function test_prune_does_nothing_when_under_limit(): void {
		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'query' );

		$this->mock_db->shouldReceive( 'get_var' )
			->once()
			->andReturnNull();

		$this->mock_db->shouldNotReceive( 'query' );

		$this->repo->prune( 1, 20 );
	}

	/**
	 * @covers ::delete_for_event
	 */
	public function test_delete_for_event(): void {
		$this->mock_db->shouldReceive( 'delete' )
			->once()
			->withArgs( function ( $table, $where, $format ) {
				return str_contains( $table, 'event_revisions' )
					&& $where === array( 'event_id' => 5 );
			} )
			->andReturn( 3 );

		$this->repo->delete_for_event( 5 );

		// Mockery verifies the expectation.
		$this->assertTrue( true );
	}

	/**
	 * @covers ::count_for_event
	 */
	public function test_count_for_event(): void {
		$this->mock_db->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'query' );

		$this->mock_db->shouldReceive( 'get_var' )
			->once()
			->andReturn( '7' );

		$result = $this->repo->count_for_event( 5 );

		$this->assertSame( 7, $result );
	}
}
