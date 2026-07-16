<?php
/**
 * Tests for RevisionAjaxHandler.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use NetterTechEvents\Admin\RevisionAjaxHandler;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Repositories\RevisionRepository;
use NetterTechEvents\Services\RevisionService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @coversDefaultClass \NetterTechEvents\Admin\RevisionAjaxHandler
 */
class RevisionAjaxHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock revision repository.
	 *
	 * @var RevisionRepository|Mockery\MockInterface
	 */
	private $revision_repo;

	/**
	 * Mock revision service.
	 *
	 * @var RevisionService|Mockery\MockInterface
	 */
	private $revision_service;

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Handler under test.
	 *
	 * @var RevisionAjaxHandler
	 */
	private RevisionAjaxHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->revision_repo    = Mockery::mock( RevisionRepository::class );
		$this->revision_service = Mockery::mock( RevisionService::class );
		$this->event_repo       = Mockery::mock( EventRepositoryInterface::class );

		$this->handler = new RevisionAjaxHandler( $this->revision_repo, $this->revision_service, $this->event_repo );
	}

	protected function tearDown(): void {
		unset( $_POST['revision_id'], $_POST['event_id'] );
		parent::tearDown();
	}

	/**
	 * @covers ::__construct
	 */
	public function test_constructor_sets_dependencies(): void {
		$this->assertInstanceOf( RevisionAjaxHandler::class, $this->handler );
	}

	/**
	 * @covers ::handle_restore
	 */
	public function test_handle_restore_sends_success_with_redirect(): void {
		$_POST['revision_id'] = '10';

		$this->revision_service->shouldReceive( 'restore' )
			->with( 10 )
			->once()
			->andReturn( 5 );

		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'http://example.com/wp-admin/' . $path;
		} );

		$captured_data = null;
		Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured_data ) {
			$captured_data = $data;
		} );

		$this->handler->handle_restore();

		$this->assertNotNull( $captured_data );
		$this->assertArrayHasKey( 'redirect', $captured_data );
		$this->assertStringContainsString( 'event_id=5', $captured_data['redirect'] );
		$this->assertStringContainsString( 'revision_restored', $captured_data['redirect'] );
	}

	/**
	 * @covers ::handle_restore
	 */
	public function test_handle_restore_sends_error_on_failure(): void {
		$_POST['revision_id'] = '10';

		$this->revision_service->shouldReceive( 'restore' )
			->with( 10 )
			->once()
			->andReturn( false );

		$error_sent = false;
		Functions\when( 'wp_send_json_error' )->alias( function () use ( &$error_sent ) {
			$error_sent = true;
			throw new \RuntimeException( 'wp_send_json_error' );
		} );

		try {
			$this->handler->handle_restore();
		} catch ( \RuntimeException $e ) {
			// Expected - wp_send_json_error halts execution.
		}

		$this->assertTrue( $error_sent );
	}

	/**
	 * @covers ::handle_list
	 */
	public function test_handle_list_returns_formatted_revisions(): void {
		$_POST['event_id'] = '5';

		$revisions = array(
			(object) array(
				'id'             => 1,
				'user_id'        => 1,
				'created_at'     => '2025-01-01 00:00:00',
				'change_summary' => 'Changed Title',
			),
		);

		$this->revision_repo->shouldReceive( 'for_event' )
			->with( 5 )
			->once()
			->andReturn( $revisions );

		$mock_user = (object) array( 'display_name' => 'Admin' );
		Functions\when( 'get_user_by' )->justReturn( $mock_user );

		$captured_data = null;
		Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured_data ) {
			$captured_data = $data;
		} );

		$this->handler->handle_list();

		$this->assertNotNull( $captured_data );
		$this->assertArrayHasKey( 'revisions', $captured_data );
		$this->assertCount( 1, $captured_data['revisions'] );
		$this->assertSame( 'Admin', $captured_data['revisions'][0]['author'] );
	}

	/**
	 * @covers ::handle_diff
	 */
	public function test_handle_diff_sends_error_for_missing_revision(): void {
		$_POST['revision_id'] = '99';

		$this->revision_repo->shouldReceive( 'find' )
			->with( 99 )
			->once()
			->andReturnNull();

		$error_sent = false;
		Functions\when( 'wp_send_json_error' )->alias( function () use ( &$error_sent ) {
			$error_sent = true;
			throw new \RuntimeException( 'wp_send_json_error' );
		} );

		try {
			$this->handler->handle_diff();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertTrue( $error_sent );
	}

	/**
	 * @covers ::handle_restore
	 */
	public function test_handle_restore_sends_error_for_invalid_id(): void {
		$_POST['revision_id'] = '0';

		$error_sent = false;
		Functions\when( 'wp_send_json_error' )->alias( function () use ( &$error_sent ) {
			$error_sent = true;
			throw new \RuntimeException( 'wp_send_json_error' );
		} );

		try {
			$this->handler->handle_restore();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertTrue( $error_sent );
	}
}
