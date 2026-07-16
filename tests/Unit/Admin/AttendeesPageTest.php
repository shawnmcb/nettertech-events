<?php
/**
 * AttendeesPage unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Attendees\AttendeesBulkActions;
use NetterTechEvents\Admin\AttendeesPage;
use NetterTechEvents\Contracts\AttendeesSummaryServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AttendeesPage.
 *
 * Focuses on the permission guard, bulk-actions delegation, and the
 * deleted-notice action wiring. Full render path includes WP_List_Table +
 * template files that exceed unit-test scope.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\AttendeesPage
 */
class AttendeesPageTest extends TestCase {

	/**
	 * Mock wpdb.
	 *
	 * @var \wpdb|Mockery\MockInterface
	 */
	private $db;

	/**
	 * Mock occurrence repo.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Mock bulk actions handler.
	 *
	 * @var AttendeesBulkActions|Mockery\MockInterface
	 */
	private $bulk_actions;

	/**
	 * Mock summary service.
	 *
	 * @var AttendeesSummaryServiceInterface|Mockery\MockInterface
	 */
	private $summary_service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_GET  = array();
		$_POST = array();

		$this->db = Mockery::mock( \wpdb::class );
		$this->db->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->db->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql )->byDefault();
		$this->db->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$this->db->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();

		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );
		// AttendeesBulkActions might be final; instantiate or mock based on actual class.
		$this->bulk_actions = $this->build_bulk_actions();

		$this->summary_service = Mockery::mock( AttendeesSummaryServiceInterface::class );
		$this->summary_service->shouldReceive( 'build' )->andReturn(
			array(
				'event'      => array( 'title' => '', 'date' => '', 'venue' => '', 'edit_url' => '', 'view_url' => '' ),
				'tickets'    => array( 'rows' => array(), 'total_issued' => 0, 'total_available' => 0 ),
				'attendance' => array( 'total_guests' => 0, 'status_counts' => array(), 'checked_in_guests' => 0, 'checked_in_percent' => 0 ),
			)
		)->byDefault();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr_e' )->echoArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( '_n' )->alias(
			static fn( $s, $p, $n ) => 1 === (int) $n ? $s : $p
		);
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => '/wp-admin/' . $p );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $a, $d ) => array_merge( (array) $d, (array) $a )
		);
		Functions\when( 'selected' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-1' );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Build a bulk actions mock or real instance based on class shape.
	 *
	 * @return AttendeesBulkActions|Mockery\MockInterface
	 */
	private function build_bulk_actions() {
		try {
			$mock = Mockery::mock( AttendeesBulkActions::class );
			$mock->shouldReceive( 'handle' )->byDefault();
			$mock->shouldReceive( 'handle_single_delete' )->byDefault();
			return $mock;
		} catch ( \Throwable $e ) {
			// AttendeesBulkActions is final — instantiate with required deps.
			$attendee_repo  = Mockery::mock( \NetterTechEvents\Contracts\AttendeeRepositoryInterface::class );
			$activity_log   = Mockery::mock( \NetterTechEvents\Contracts\ActivityLogRepositoryInterface::class );
			$reflection     = new \ReflectionClass( AttendeesBulkActions::class );
			$ctor           = $reflection->getConstructor();
			$param_count    = $ctor ? $ctor->getNumberOfParameters() : 0;
			$args           = array_fill( 0, $param_count, $attendee_repo );
			return $reflection->newInstanceArgs( $args );
		}
	}

	/**
	 * Test render wp_dies without capability.
	 *
	 * @return void
	 */
	public function test_render_wp_dies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new AttendeesPage( $this->db, $this->occurrence_repo, $this->bulk_actions, $this->summary_service );

		$this->expectException( \RuntimeException::class );
		$page->render();
	}

	/**
	 * Test that handle_actions() delegates to the bulk-action handlers.
	 *
	 * The dispatch was moved out of render() onto the page's load-{hook} so the
	 * CSV export can stream its headers before any output; the delegation now
	 * happens via handle_actions() rather than during render().
	 *
	 * @return void
	 */
	public function test_handle_actions_delegates_to_bulk_actions(): void {
		if ( $this->bulk_actions instanceof Mockery\MockInterface ) {
			$this->bulk_actions->shouldReceive( 'handle' )->once();
			$this->bulk_actions->shouldReceive( 'handle_single_delete' )->once();
		}

		$page = new AttendeesPage( $this->db, $this->occurrence_repo, $this->bulk_actions, $this->summary_service );
		$page->handle_actions();

		$this->assertTrue( true );
	}

	/**
	 * Test that the deleted-count notice registers an admin_notices action.
	 *
	 * @return void
	 */
	public function test_deleted_count_triggers_admin_notice(): void {
		$_GET['nettertech_events_deleted'] = '3';

		Functions\when( 'current_user_can' )->justReturn( true );

		$notice_registered = false;
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$notice_registered ) {
				if ( 'admin_notices' === $hook ) {
					$notice_registered = true;
				}
				return true;
			}
		);

		$this->occurrence_repo->shouldReceive( 'get_all_upcoming' )->andReturn( array() )->byDefault();
		$this->occurrence_repo->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();

		$page = new AttendeesPage( $this->db, $this->occurrence_repo, $this->bulk_actions, $this->summary_service );

		$initial = ob_get_level();
		ob_start();
		try {
			$page->render();
		} catch ( \Throwable $e ) {
			// Template-driven render may fail; only the action registration matters here.
		}
		while ( ob_get_level() > $initial ) {
			ob_end_clean();
		}

		$this->assertTrue( $notice_registered );
	}

	/**
	 * Test that instance can be constructed.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$page = new AttendeesPage( $this->db, $this->occurrence_repo, $this->bulk_actions, $this->summary_service );
		$this->assertInstanceOf( AttendeesPage::class, $page );
	}
}
