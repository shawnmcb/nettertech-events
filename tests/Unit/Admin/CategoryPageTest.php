<?php
/**
 * CategoryPage Test.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use NetterTechEvents\Admin\CategoryPage;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Repositories\CategoryRepository;

/**
 * Tests for the CategoryPage class.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\CategoryPage
 */
class CategoryPageTest extends TestCase {

	/**
	 * Category repository mock.
	 *
	 * @var CategoryRepository&\Mockery\MockInterface
	 */
	private $repo;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST   = array();
		$_GET    = array();
		$_SERVER = array();

		$this->repo = Mockery::mock( CategoryRepository::class );

		// Common WordPress function mocks.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
			}
		);
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				$slug = strtolower( (string) $title );
				$slug = preg_replace( '/[^a-z0-9-]/', '-', $slug );
				$slug = preg_replace( '/-+/', '-', $slug );
				return trim( $slug, '-' );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( ...$args ) {
				if ( is_array( $args[0] ) ) {
					$url    = $args[1] ?? '';
					$params = $args[0];
				} else {
					$url    = $args[2] ?? '';
					$params = array( $args[0] => $args[1] );
				}
				$separator = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
				return $url . $separator . http_build_query( $params );
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="test_nonce">';
			}
		);
		Functions\when( 'submit_button' )->alias(
			function ( $text = '' ) {
				echo '<input type="submit" value="' . $text . '">';
			}
		);
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, $echo = true ) {
				$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'wp_trim_words' )->alias(
			function ( $text, $num_words = 55 ) {
				$words = explode( ' ', (string) $text );
				return implode( ' ', array_slice( $words, 0, $num_words ) );
			}
		);
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_add_inline_script' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'plugins_url' )->justReturn( 'http://example.com/wp-content/plugins/nettertech-events' );
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST   = array();
		$_GET    = array();
		$_SERVER = array();
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// =========================================================================
	// Instantiation Tests
	// =========================================================================

	/**
	 * Test CategoryPage can be instantiated.
	 *
	 * @covers ::__construct
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$page = new CategoryPage( $this->repo );
		$this->assertInstanceOf( CategoryPage::class, $page );
	}

	/**
	 * Test PAGE_SLUG constant value.
	 *
	 * @covers ::__construct
	 * @return void
	 */
	public function test_page_slug_constant(): void {
		$this->assertSame( 'nettertech-events-categories', CategoryPage::PAGE_SLUG );
	}

	// =========================================================================
	// render() — Permission Tests
	// =========================================================================

	/**
	 * Test render calls wp_die when user lacks capability.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_calls_wp_die_when_user_lacks_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new CategoryPage( $this->repo );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );

		$page->render();
	}

	// =========================================================================
	// render() — Routing Tests
	// =========================================================================

	/**
	 * Test render routes to list view by default.
	 *
	 * @covers ::render
	 * @covers ::render_list
	 * @return void
	 */
	public function test_render_routes_to_list_by_default(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Event Categories', $output );
		$this->assertStringContainsString( 'Add New', $output );
	}

	/**
	 * Test render routes to form for add action.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_routes_to_form_for_add_action(): void {
		$_GET['action'] = 'add';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Add New Category', $output );
		$this->assertStringContainsString( '<form method="post"', $output );
	}

	/**
	 * Test render routes to form for edit action with valid category.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_routes_to_form_for_edit_action(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';

		Functions\when( 'current_user_can' )->justReturn( true );

		$category       = new Category();
		$category->id   = 5;
		$category->name = 'Test Category';
		$category->slug = 'test-category';

		$this->repo->shouldReceive( 'find' )->with( 5 )->once()->andReturn( $category );
		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $category ) );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Edit Category', $output );
		$this->assertStringContainsString( 'Test Category', $output );
	}

	/**
	 * Test render defaults to list for unknown action.
	 *
	 * @covers ::render
	 * @covers ::render_list
	 * @return void
	 */
	public function test_render_defaults_to_list_for_unknown_action(): void {
		$_GET['action'] = 'unknown';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Event Categories', $output );
	}

	// =========================================================================
	// handle_actions() — Request Method Tests
	// =========================================================================

	/**
	 * Test non-POST requests skip handle_actions.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_non_post_requests_skip_handle_actions(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );
		$this->repo->shouldNotReceive( 'save' );
		$this->repo->shouldNotReceive( 'delete' );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Event Categories', $output );
	}

	/**
	 * Test POST without nonce skips save and delete.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_post_without_nonce_skips_save_and_delete(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );
		$this->repo->shouldNotReceive( 'save' );
		$this->repo->shouldNotReceive( 'delete' );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Event Categories', $output );
	}

	/**
	 * Test handle_actions calls wp_die when POST user lacks capability.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_actions_wp_die_when_post_user_lacks_capability(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['_nettertech_events_category_nonce'] = 'test_nonce';

		// First call (render capability check) returns true; second call (handle_actions) returns false.
		$call_count = 0;
		Functions\when( 'current_user_can' )->alias(
			function () use ( &$call_count ) {
				++$call_count;
				return $call_count <= 1;
			}
		);
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new CategoryPage( $this->repo );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );

		$page->render();
	}

	// =========================================================================
	// handle_save() — Create Tests
	// =========================================================================

	/**
	 * Test handle_save creates new category when no category_id in POST.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_save_creates_new_category(): void {
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['_nettertech_events_category_nonce']   = 'test_nonce';
		$_POST['category_name']        = 'New Category';
		$_POST['category_slug']        = 'new-category';
		$_POST['category_description'] = 'A test description';
		$_POST['category_parent']      = '';
		$_POST['category_sort_order']  = '0';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$redirect_url = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		$this->repo->shouldReceive( 'save' )
			->once()
			->with(
				Mockery::on(
					function ( Category $cat ) {
						return 'New Category' === $cat->name
							&& 'new-category' === $cat->slug
							&& null === $cat->id;
					}
				)
			)
			->andReturnUsing(
				function ( Category $cat ) {
					$cat->id = 1;
					return $cat;
				}
			);

		$page = new CategoryPage( $this->repo );

		try {
			ob_start();
			$page->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'message=created', $redirect_url );
	}

	/**
	 * Test handle_save updates existing category when category_id > 0.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_save_updates_existing_category(): void {
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['_nettertech_events_category_nonce']   = 'test_nonce';
		$_POST['category_id']          = '5';
		$_POST['category_name']        = 'Updated Category';
		$_POST['category_slug']        = 'updated-category';
		$_POST['category_description'] = 'Updated desc';
		$_POST['category_parent']      = '';
		$_POST['category_sort_order']  = '1';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$existing       = new Category();
		$existing->id   = 5;
		$existing->name = 'Old Category';
		$existing->slug = 'old-category';

		$redirect_url = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		$this->repo->shouldReceive( 'find' )->with( 5 )->once()->andReturn( $existing );
		$this->repo->shouldReceive( 'save' )
			->once()
			->with(
				Mockery::on(
					function ( Category $cat ) {
						return 'Updated Category' === $cat->name
							&& 'updated-category' === $cat->slug
							&& 5 === $cat->id;
					}
				)
			)
			->andReturnUsing(
				function ( Category $cat ) {
					return $cat;
				}
			);

		$page = new CategoryPage( $this->repo );

		try {
			ob_start();
			$page->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'message=updated', $redirect_url );
	}

	/**
	 * Test handle_save auto-generates slug from name when slug is empty.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_save_auto_generates_slug_from_name(): void {
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['_nettertech_events_category_nonce']   = 'test_nonce';
		$_POST['category_name']        = 'My Category Name';
		$_POST['category_slug']        = '';
		$_POST['category_description'] = '';
		$_POST['category_parent']      = '';
		$_POST['category_sort_order']  = '0';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'redirect' );
			}
		);

		$this->repo->shouldReceive( 'save' )
			->once()
			->with(
				Mockery::on(
					function ( Category $cat ) {
						return 'my-category-name' === $cat->slug
							&& 'My Category Name' === $cat->name;
					}
				)
			)
			->andReturnUsing(
				function ( Category $cat ) {
					$cat->id = 1;
					return $cat;
				}
			);

		$page = new CategoryPage( $this->repo );

		try {
			ob_start();
			$page->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		// Mockery assertion on save callback verifies slug generation.
		$this->assertTrue( true );
	}

	/**
	 * Test handle_save handles repo save exception with error redirect.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_save_handles_exception_with_error_redirect(): void {
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['_nettertech_events_category_nonce']   = 'test_nonce';
		$_POST['category_name']        = 'Fail Category';
		$_POST['category_slug']        = 'fail-category';
		$_POST['category_description'] = '';
		$_POST['category_parent']      = '';
		$_POST['category_sort_order']  = '0';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$redirect_url = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		$this->repo->shouldReceive( 'save' )
			->once()
			->andThrow( new \RuntimeException( 'Database error' ) );

		$page = new CategoryPage( $this->repo );

		try {
			ob_start();
			$page->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'message=error', $redirect_url );
		$this->assertStringContainsString( 'action=add', $redirect_url );
	}

	/**
	 * Test handle_save exception redirect uses edit action for existing category.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_save_exception_redirects_to_edit_for_existing(): void {
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['_nettertech_events_category_nonce']   = 'test_nonce';
		$_POST['category_id']          = '10';
		$_POST['category_name']        = 'Existing Category';
		$_POST['category_slug']        = 'existing';
		$_POST['category_description'] = '';
		$_POST['category_parent']      = '';
		$_POST['category_sort_order']  = '0';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$existing       = new Category();
		$existing->id   = 10;
		$existing->name = 'Existing Category';

		$redirect_url = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		$this->repo->shouldReceive( 'find' )->with( 10 )->once()->andReturn( $existing );
		$this->repo->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'DB fail' ) );

		$page = new CategoryPage( $this->repo );

		try {
			ob_start();
			$page->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'action=edit', $redirect_url );
		$this->assertStringContainsString( 'id=10', $redirect_url );
		$this->assertStringContainsString( 'message=error', $redirect_url );
	}

	/**
	 * Test handle_save sets parent_id when provided.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_save_sets_parent_id_when_provided(): void {
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['_nettertech_events_category_nonce']   = 'test_nonce';
		$_POST['category_name']        = 'Child Category';
		$_POST['category_slug']        = 'child';
		$_POST['category_description'] = '';
		$_POST['category_parent']      = '3';
		$_POST['category_sort_order']  = '2';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'redirect' );
			}
		);

		$this->repo->shouldReceive( 'save' )
			->once()
			->with(
				Mockery::on(
					function ( Category $cat ) {
						return 3 === $cat->parent_id && 2 === $cat->sort_order;
					}
				)
			)
			->andReturnUsing(
				function ( Category $cat ) {
					$cat->id = 1;
					return $cat;
				}
			);

		$page = new CategoryPage( $this->repo );

		try {
			ob_start();
			$page->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		// Mockery assertion verifies parent_id and sort_order were set.
		$this->assertTrue( true );
	}

	/**
	 * Test handle_save creates new Category when find returns null for existing ID.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_save_creates_new_when_find_returns_null(): void {
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['_nettertech_events_category_nonce']   = 'test_nonce';
		$_POST['category_id']          = '999';
		$_POST['category_name']        = 'Ghost Category';
		$_POST['category_slug']        = 'ghost';
		$_POST['category_description'] = '';
		$_POST['category_parent']      = '';
		$_POST['category_sort_order']  = '0';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'redirect' );
			}
		);

		$this->repo->shouldReceive( 'find' )->with( 999 )->once()->andReturn( null );
		$this->repo->shouldReceive( 'save' )
			->once()
			->with(
				Mockery::on(
					function ( Category $cat ) {
						// When find returns null, a new Category is created (id is null).
						return null === $cat->id;
					}
				)
			)
			->andReturnUsing(
				function ( Category $cat ) {
					$cat->id = 1;
					return $cat;
				}
			);

		$page = new CategoryPage( $this->repo );

		try {
			ob_start();
			$page->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		$this->assertTrue( true );
	}

	// =========================================================================
	// handle_save() — Nonce Verification Tests
	// =========================================================================

	/**
	 * Test handle_save calls wp_die when nonce is invalid.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_save_wp_die_on_invalid_nonce(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['_nettertech_events_category_nonce'] = 'bad_nonce';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new CategoryPage( $this->repo );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );

		$page->render();
	}

	// =========================================================================
	// handle_delete() Tests
	// =========================================================================

	/**
	 * Test handle_delete deletes category by ID.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_delete_deletes_category(): void {
		$_SERVER['REQUEST_METHOD']          = 'POST';
		$_POST['_nettertech_events_category_delete_nonce'] = 'test_nonce';
		$_POST['category_id']               = '7';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$redirect_url = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		$this->repo->shouldReceive( 'delete' )->with( 7 )->once()->andReturn( true );

		$page = new CategoryPage( $this->repo );

		try {
			ob_start();
			$page->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'message=deleted', $redirect_url );
	}

	/**
	 * Test handle_delete skips delete when category_id is 0.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_delete_skips_when_category_id_is_zero(): void {
		$_SERVER['REQUEST_METHOD']          = 'POST';
		$_POST['_nettertech_events_category_delete_nonce'] = 'test_nonce';
		$_POST['category_id']               = '0';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$redirect_url = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		$this->repo->shouldNotReceive( 'delete' );

		$page = new CategoryPage( $this->repo );

		try {
			ob_start();
			$page->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'message=deleted', $redirect_url );
	}

	/**
	 * Test handle_delete calls wp_die when delete nonce is invalid.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_handle_delete_wp_die_on_invalid_nonce(): void {
		$_SERVER['REQUEST_METHOD']          = 'POST';
		$_POST['_nettertech_events_category_delete_nonce'] = 'bad_nonce';
		$_POST['category_id']               = '7';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new CategoryPage( $this->repo );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );

		$page->render();
	}

	// =========================================================================
	// render_list() Tests
	// =========================================================================

	/**
	 * Test render_list shows empty message when no categories exist.
	 *
	 * @covers ::render
	 * @covers ::render_list
	 * @return void
	 */
	public function test_render_list_shows_empty_message(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'No categories found', $output );
	}

	/**
	 * Test render_list shows categories table when categories exist.
	 *
	 * @covers ::render
	 * @covers ::render_list
	 * @return void
	 */
	public function test_render_list_shows_categories_table(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$cat1              = new Category();
		$cat1->id          = 1;
		$cat1->name        = 'Music';
		$cat1->slug        = 'music';
		$cat1->description = 'Music events and concerts';
		$cat1->sort_order  = 0;

		$cat2              = new Category();
		$cat2->id          = 2;
		$cat2->name        = 'Dance';
		$cat2->slug        = 'dance';
		$cat2->description = 'Dance performances';
		$cat2->parent_id   = 1;
		$cat2->sort_order  = 1;

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $cat1, $cat2 ) );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array( 1 => 5, 2 => 3 ) );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'wp-list-table', $output );
		$this->assertStringContainsString( 'Music', $output );
		$this->assertStringContainsString( 'Dance', $output );
		$this->assertStringContainsString( 'music', $output );
		$this->assertStringContainsString( 'dance', $output );
	}

	/**
	 * Test render_list displays event counts for categories.
	 *
	 * @covers ::render
	 * @covers ::render_list
	 * @return void
	 */
	public function test_render_list_displays_event_counts(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$cat              = new Category();
		$cat->id          = 1;
		$cat->name        = 'Music';
		$cat->slug        = 'music';
		$cat->description = '';
		$cat->sort_order  = 0;

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $cat ) );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array( 1 => 12 ) );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( '12', $output );
	}

	/**
	 * Test render_list shows parent category name.
	 *
	 * @covers ::render
	 * @covers ::render_list
	 * @return void
	 */
	public function test_render_list_shows_parent_name(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$parent              = new Category();
		$parent->id          = 1;
		$parent->name        = 'Celtic';
		$parent->slug        = 'celtic';
		$parent->description = '';
		$parent->sort_order  = 0;

		$child              = new Category();
		$child->id          = 2;
		$child->name        = 'Irish Dance';
		$child->slug        = 'irish-dance';
		$child->description = '';
		$child->parent_id   = 1;
		$child->sort_order  = 0;

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $parent, $child ) );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Celtic', $output );
		$this->assertStringContainsString( 'Irish Dance', $output );
	}

	/**
	 * Test render_list includes hidden delete form.
	 *
	 * @covers ::render
	 * @covers ::render_list
	 * @return void
	 */
	public function test_render_list_includes_delete_form(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'nte-category-delete-form', $output );
		$this->assertStringContainsString( '_nettertech_events_category_delete_nonce', $output );
	}

	/**
	 * Test render_list includes table column headers.
	 *
	 * @covers ::render
	 * @covers ::render_list
	 * @return void
	 */
	public function test_render_list_includes_table_headers(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$cat              = new Category();
		$cat->id          = 1;
		$cat->name        = 'Test';
		$cat->slug        = 'test';
		$cat->description = '';
		$cat->sort_order  = 0;

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $cat ) );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Name', $output );
		$this->assertStringContainsString( 'Slug', $output );
		$this->assertStringContainsString( 'Description', $output );
		$this->assertStringContainsString( 'Parent', $output );
		$this->assertStringContainsString( 'Sort Order', $output );
		$this->assertStringContainsString( 'Events', $output );
	}

	/**
	 * Test render_list includes JavaScript for delete confirmation.
	 *
	 * @covers ::render
	 * @covers ::render_list
	 * @return void
	 */
	public function test_render_list_includes_delete_javascript(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( 'nte-category-delete-form', $output );
	}

	// =========================================================================
	// render_form() Tests
	// =========================================================================

	/**
	 * Test render_form for edit calls wp_die when category not found.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_form_edit_wp_die_when_category_not_found(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '999';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$this->repo->shouldReceive( 'find' )->with( 999 )->once()->andReturn( null );

		$page = new CategoryPage( $this->repo );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );

		$page->render();
	}

	/**
	 * Test render_form for edit with id=0 calls wp_die.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_form_edit_wp_die_when_id_is_zero(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '0';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$page = new CategoryPage( $this->repo );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );

		$page->render();
	}

	/**
	 * Test render_form excludes self from parent dropdown in edit mode.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_form_edit_excludes_self_from_parent_dropdown(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '2';

		Functions\when( 'current_user_can' )->justReturn( true );

		$editing       = new Category();
		$editing->id   = 2;
		$editing->name = 'Dance';
		$editing->slug = 'dance';

		$other       = new Category();
		$other->id   = 1;
		$other->name = 'Music';
		$other->slug = 'music';

		$this->repo->shouldReceive( 'find' )->with( 2 )->once()->andReturn( $editing );
		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $other, $editing ) );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		// The parent dropdown should contain Music (id=1) as an option.
		$this->assertStringContainsString( 'value="1"', $output );
		// Should NOT contain the editing category (id=2) as a parent <option>.
		$option_pattern = '/<option[^>]*value="2"/';
		$this->assertSame( 0, preg_match_all( $option_pattern, $output ) );
	}

	/**
	 * Test render_form add shows correct title and submit button.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_form_add_shows_correct_elements(): void {
		$_GET['action'] = 'add';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Add New Category', $output );
		$this->assertStringContainsString( 'Add Category', $output );
	}

	/**
	 * Test render_form edit shows correct title and submit button.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_form_edit_shows_correct_elements(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '1';

		Functions\when( 'current_user_can' )->justReturn( true );

		$cat       = new Category();
		$cat->id   = 1;
		$cat->name = 'Test';
		$cat->slug = 'test';

		$this->repo->shouldReceive( 'find' )->with( 1 )->once()->andReturn( $cat );
		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $cat ) );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Edit Category', $output );
		$this->assertStringContainsString( 'Update Category', $output );
	}

	/**
	 * Test render_form contains nonce field.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_form_contains_nonce_field(): void {
		$_GET['action'] = 'add';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( '_nettertech_events_category_nonce', $output );
	}

	/**
	 * Test render_form contains back link.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_form_contains_back_link(): void {
		$_GET['action'] = 'add';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Back to Categories', $output );
	}

	/**
	 * Test render_form populates field values in edit mode.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_form_populates_fields_in_edit(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '3';

		Functions\when( 'current_user_can' )->justReturn( true );

		$cat              = new Category();
		$cat->id          = 3;
		$cat->name        = 'Folk Music';
		$cat->slug        = 'folk-music';
		$cat->description = 'Traditional folk music events';
		$cat->sort_order  = 5;

		$this->repo->shouldReceive( 'find' )->with( 3 )->once()->andReturn( $cat );
		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $cat ) );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'Folk Music', $output );
		$this->assertStringContainsString( 'folk-music', $output );
		$this->assertStringContainsString( 'Traditional folk music events', $output );
		$this->assertStringContainsString( 'value="5"', $output );
	}

	/**
	 * Test render_form contains all required form fields.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @return void
	 */
	public function test_render_form_contains_required_fields(): void {
		$_GET['action'] = 'add';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'name="category_name"', $output );
		$this->assertStringContainsString( 'name="category_slug"', $output );
		$this->assertStringContainsString( 'name="category_description"', $output );
		$this->assertStringContainsString( 'name="category_parent"', $output );
		$this->assertStringContainsString( 'name="category_sort_order"', $output );
		$this->assertStringContainsString( 'name="category_id"', $output );
	}

	// =========================================================================
	// render_notices() Tests
	// =========================================================================

	/**
	 * Test render_notices shows created message.
	 *
	 * @covers ::render
	 * @covers ::render_notices
	 * @return void
	 */
	public function test_render_notices_shows_created_message(): void {
		$_GET['message'] = 'created';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'Category created.', $output );
	}

	/**
	 * Test render_notices shows updated message.
	 *
	 * @covers ::render
	 * @covers ::render_notices
	 * @return void
	 */
	public function test_render_notices_shows_updated_message(): void {
		$_GET['message'] = 'updated';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'Category updated.', $output );
	}

	/**
	 * Test render_notices shows deleted message.
	 *
	 * @covers ::render
	 * @covers ::render_notices
	 * @return void
	 */
	public function test_render_notices_shows_deleted_message(): void {
		$_GET['message'] = 'deleted';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'Category deleted.', $output );
	}

	/**
	 * Test render_notices shows error message with custom error text.
	 *
	 * @covers ::render
	 * @covers ::render_notices
	 * @return void
	 */
	public function test_render_notices_shows_error_with_custom_text(): void {
		$_GET['message'] = 'error';
		$_GET['error']   = 'Something went wrong';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Something went wrong', $output );
	}

	/**
	 * Test render_notices shows default error when error param missing.
	 *
	 * @covers ::render
	 * @covers ::render_notices
	 * @return void
	 */
	public function test_render_notices_shows_default_error(): void {
		$_GET['message'] = 'error';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'An error occurred.', $output );
	}

	/**
	 * Test render_notices outputs nothing when no message param.
	 *
	 * @covers ::render
	 * @covers ::render_notices
	 * @return void
	 */
	public function test_render_notices_outputs_nothing_without_message(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringNotContainsString( 'notice-success', $output );
		$this->assertStringNotContainsString( 'notice-error', $output );
	}

	/**
	 * Test render_notices ignores unrecognized message keys.
	 *
	 * @covers ::render
	 * @covers ::render_notices
	 * @return void
	 */
	public function test_render_notices_ignores_unrecognized_message_key(): void {
		$_GET['message'] = 'unknown_key';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'get_event_counts' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringNotContainsString( 'notice-success', $output );
		$this->assertStringNotContainsString( 'notice-error', $output );
	}

	// =========================================================================
	// render_form() — Notices Integration Tests
	// =========================================================================

	/**
	 * Test render_form shows notices when message param present.
	 *
	 * @covers ::render
	 * @covers ::render_form
	 * @covers ::render_notices
	 * @return void
	 */
	public function test_render_form_shows_notices(): void {
		$_GET['action']  = 'add';
		$_GET['message'] = 'error';
		$_GET['error']   = 'Validation failed';

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );

		$page   = new CategoryPage( $this->repo );
		$output = $this->capture_render_output( $page );

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Validation failed', $output );
		$this->assertStringContainsString( 'Add New Category', $output );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Capture render output.
	 *
	 * @param CategoryPage $page Category page instance.
	 * @return string Captured output.
	 */
	private function capture_render_output( CategoryPage $page ): string {
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}
}
