<?php
/**
 * SpacesPage unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Spaces\SpacesBulkActions;
use NetterTechEvents\Admin\SpacesPage;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Models\Space;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SpacesPage class.
 *
 * Covers permission enforcement, action routing, and delete-action guards.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\SpacesPage
 */
class SpacesPageTest extends TestCase {

	/**
	 * Mock repository.
	 *
	 * @var SpaceRepositoryInterface|Mockery\MockInterface
	 */
	private $repo;

	/**
	 * Bulk actions handler (final class, real instance with mock repo).
	 *
	 * @var SpacesBulkActions
	 */
	private SpacesBulkActions $bulk_actions;

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

		if ( ! defined( 'NETTERTECH_EVENTS_VERSION' ) ) {
			define( 'NETTERTECH_EVENTS_VERSION', '1.0.0' );
		}

		$this->repo         = Mockery::mock( SpaceRepositoryInterface::class );
		$this->bulk_actions = new SpacesBulkActions( $this->repo );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
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
			static fn( $a, $d ) => array_merge( (array) $d, (array) $a )
		);
		Functions\when( 'plugins_url' )->alias(
			static fn( $path = '', $plugin = '' ) => 'http://example.test/wp-content/plugins/nettertech-events/' . $path
		);
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'checked' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'wp_get_attachment_image' )->justReturn( '' );
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
	 * Test PAGE_SLUG constant.
	 *
	 * @return void
	 */
	public function test_page_slug_constant(): void {
		$this->assertSame( 'nettertech-events-spaces', SpacesPage::PAGE_SLUG );
	}

	/**
	 * Test can instantiate.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$page = new SpacesPage( $this->repo, $this->bulk_actions );
		$this->assertInstanceOf( SpacesPage::class, $page );
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

		$page = new SpacesPage( $this->repo, $this->bulk_actions );
		$this->expectException( \RuntimeException::class );
		$page->render();
	}

	/**
	 * Test render routes to list view by default.
	 *
	 * @return void
	 */
	public function test_render_lists_by_default(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'paginate' )->andReturn( array() );
		$this->repo->shouldReceive( 'count' )->andReturn( 0 );

		$page = new SpacesPage( $this->repo, $this->bulk_actions );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Spaces', $output );
		$this->assertStringContainsString( 'Add New', $output );
	}

	/**
	 * Test render routes to form for add.
	 *
	 * @return void
	 */
	public function test_render_routes_to_form_for_add(): void {
		$_GET['action'] = 'add';

		Functions\when( 'current_user_can' )->justReturn( true );

		$page = new SpacesPage( $this->repo, $this->bulk_actions );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Add New Space', $output );
		$this->assertStringContainsString( '<form method="post"', $output );
	}

	/**
	 * Test render routes to edit form for valid space.
	 *
	 * @return void
	 */
	public function test_render_routes_to_edit_for_valid_space(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '7';

		$space           = new Space();
		$space->id       = 7;
		$space->name     = 'Main Hall';
		$space->capacity = 200;
		$space->status   = 'active';

		Functions\when( 'current_user_can' )->justReturn( true );
		$this->repo->shouldReceive( 'find' )->with( 7 )->andReturn( $space );

		$page = new SpacesPage( $this->repo, $this->bulk_actions );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Edit Space', $output );
		$this->assertStringContainsString( 'Main Hall', $output );
	}

	/**
	 * Test render wp_dies when edit target missing.
	 *
	 * @return void
	 */
	public function test_render_wp_dies_for_missing_space(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '777';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		$this->repo->shouldReceive( 'find' )->with( 777 )->andReturn( null );

		$page = new SpacesPage( $this->repo, $this->bulk_actions );

		$this->expectException( \RuntimeException::class );
		ob_start();
		try {
			$page->render();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Test single-delete with missing nonce dies.
	 *
	 * @return void
	 */
	public function test_delete_without_nonce_dies(): void {
		$_GET['action'] = 'delete';
		$_GET['id']     = '4';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new SpacesPage( $this->repo, $this->bulk_actions );

		$this->expectException( \RuntimeException::class );
		$page->render();
	}

	/**
	 * Test single-delete with bad nonce dies.
	 *
	 * @return void
	 */
	public function test_delete_with_bad_nonce_dies(): void {
		$_GET['action']   = 'delete';
		$_GET['id']       = '4';
		$_GET['_wpnonce'] = 'bad';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new SpacesPage( $this->repo, $this->bulk_actions );

		$this->expectException( \RuntimeException::class );
		$page->render();
	}

	/**
	 * Test bulk-style space[] param routes through list view (skipped delete branch).
	 *
	 * @return void
	 */
	public function test_delete_action_with_space_array_skips_single_path(): void {
		$_GET['action'] = 'delete';
		$_GET['id']     = '4';
		$_GET['space']  = array( 4 );

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'paginate' )->andReturn( array() );
		$this->repo->shouldReceive( 'count' )->andReturn( 0 );

		$page = new SpacesPage( $this->repo, $this->bulk_actions );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Spaces', $output );
	}
}
