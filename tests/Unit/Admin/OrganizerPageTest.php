<?php
/**
 * OrganizerPage unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\OrganizerPage;
use NetterTechEvents\Admin\Organizers\OrganizerBulkActions;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Models\Organizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OrganizerPage class.
 *
 * Covers permission enforcement, routing (list/add/edit), and the
 * delete-action guards.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\OrganizerPage
 */
class OrganizerPageTest extends TestCase {

	/**
	 * Mock repository.
	 *
	 * @var OrganizerRepositoryInterface|Mockery\MockInterface
	 */
	private $repo;

	/**
	 * Mock bulk actions handler.
	 *
	 * @var OrganizerBulkActions|Mockery\MockInterface
	 */
	private $bulk_actions;

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

		$this->repo         = Mockery::mock( OrganizerRepositoryInterface::class );
		// OrganizerBulkActions is final; use the real instance with the mock repo.
		$this->bulk_actions = new OrganizerBulkActions( $this->repo );

		if ( ! defined( 'NETTERTECH_EVENTS_VERSION' ) ) {
			define( 'NETTERTECH_EVENTS_VERSION', '1.0.0' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => '/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ) {
				if ( is_array( $args[0] ) ) {
					$url = $args[1] ?? '';
					return $url . '?' . http_build_query( $args[0] );
				}
				$url = $args[2] ?? '';
				return $url . '?' . $args[0] . '=' . $args[1];
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->echoArg();
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_add_inline_script' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'plugins_url' )->alias(
			static fn( $path = '', $plugin = '' ) => 'http://example.test/wp-content/plugins/nettertech-events/' . $path
		);
		Functions\when( 'esc_attr__' )->returnArg();
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
	 * Capture rendered HTML output.
	 *
	 * @param OrganizerPage $page Page instance.
	 * @return string
	 */
	private function capture_render( OrganizerPage $page ): string {
		$initial_level = ob_get_level();
		ob_start();
		try {
			$page->render();
		} catch ( \RuntimeException $e ) {
			// Swallow exit-style escape exceptions.
		}
		$content = (string) ob_get_clean();
		// Ensure no extra buffers left dangling.
		while ( ob_get_level() > $initial_level ) {
			ob_end_clean();
		}
		return $content;
	}

	/**
	 * Test PAGE_SLUG constant value.
	 *
	 * @return void
	 */
	public function test_page_slug_constant(): void {
		$this->assertSame( 'nettertech-events-organizers', OrganizerPage::PAGE_SLUG );
	}

	/**
	 * Test instantiation.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$page = new OrganizerPage( $this->repo, $this->bulk_actions );
		$this->assertInstanceOf( OrganizerPage::class, $page );
	}

	/**
	 * Test render wp_dies when user lacks capability.
	 *
	 * @return void
	 */
	public function test_render_calls_wp_die_when_user_lacks_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new OrganizerPage( $this->repo, $this->bulk_actions );

		$this->expectException( \RuntimeException::class );
		$page->render();
	}

	/**
	 * Test render routes to list view by default.
	 *
	 * @return void
	 */
	public function test_render_routes_to_list_by_default(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		// OrganizerListTable internally calls these.
		$this->repo->shouldReceive( 'get_all' )->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->andReturn( array() );

		$page   = new OrganizerPage( $this->repo, $this->bulk_actions );
		$output = $this->capture_render( $page );

		$this->assertStringContainsString( 'Organizers', $output );
		$this->assertStringContainsString( 'Add New', $output );
	}

	/**
	 * Test render routes to form for add action.
	 *
	 * @return void
	 */
	public function test_render_routes_to_form_for_add(): void {
		$_GET['action'] = 'add';

		Functions\when( 'current_user_can' )->justReturn( true );

		$page   = new OrganizerPage( $this->repo, $this->bulk_actions );
		$output = $this->capture_render( $page );

		$this->assertStringContainsString( 'Add New Organizer', $output );
		$this->assertStringContainsString( '<form method="post"', $output );
		$this->assertStringContainsString( 'organizer_name', $output );
	}

	/**
	 * Test render routes to form for edit action with valid organizer.
	 *
	 * @return void
	 */
	public function test_render_routes_to_form_for_valid_edit(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '42';

		$organizer              = new Organizer();
		$organizer->id          = 42;
		$organizer->name        = 'Alice';
		$organizer->slug        = 'alice';
		$organizer->email       = 'alice@example.test';
		$organizer->phone       = '555-1234';
		$organizer->website     = 'https://example.test';
		$organizer->description = 'Bio';

		Functions\when( 'current_user_can' )->justReturn( true );
		$this->repo->shouldReceive( 'find' )->with( 42 )->andReturn( $organizer );

		$page   = new OrganizerPage( $this->repo, $this->bulk_actions );
		$output = $this->capture_render( $page );

		$this->assertStringContainsString( 'Edit Organizer', $output );
		$this->assertStringContainsString( 'Alice', $output );
		$this->assertStringContainsString( 'alice@example.test', $output );
	}

	/**
	 * Test render dies for edit action with missing organizer.
	 *
	 * @return void
	 */
	public function test_render_wp_dies_for_missing_organizer(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '999';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		$this->repo->shouldReceive( 'find' )->with( 999 )->andReturn( null );

		$page = new OrganizerPage( $this->repo, $this->bulk_actions );

		$this->expectException( \RuntimeException::class );
		ob_start();
		try {
			$page->render();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Test delete action with missing nonce dies.
	 *
	 * @return void
	 */
	public function test_delete_action_without_nonce_dies(): void {
		$_GET['action'] = 'delete';
		$_GET['id']     = '5';
		// _wpnonce omitted.

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new OrganizerPage( $this->repo, $this->bulk_actions );

		$this->expectException( \RuntimeException::class );
		$page->render();
	}

	/**
	 * Test delete action with bad nonce dies.
	 *
	 * @return void
	 */
	public function test_delete_action_with_bad_nonce_dies(): void {
		$_GET['action']   = 'delete';
		$_GET['id']       = '5';
		$_GET['_wpnonce'] = 'bad';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new OrganizerPage( $this->repo, $this->bulk_actions );

		$this->expectException( \RuntimeException::class );
		$page->render();
	}

	/**
	 * Test delete action with bulk-style organizer[] param is treated as bulk, not single.
	 *
	 * @return void
	 */
	public function test_delete_action_skipped_when_organizer_array_present(): void {
		$_GET['action']    = 'delete';
		$_GET['id']        = '5';
		$_GET['organizer'] = array( 5 );

		Functions\when( 'current_user_can' )->justReturn( true );
		// Bulk actions handle would run; we mocked handle() to do nothing.
		// After the delete branch is skipped, render falls through to list view.
		$this->repo->shouldReceive( 'get_all' )->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->andReturn( array() );

		$page   = new OrganizerPage( $this->repo, $this->bulk_actions );
		$output = $this->capture_render( $page );

		$this->assertStringContainsString( 'Organizers', $output );
	}
}
