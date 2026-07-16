<?php
/**
 * PathConflictDetector unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\PathConflictDetector;
use Brain\Monkey\Functions;

/**
 * Test PathConflictDetector functionality.
 */
class PathConflictDetectorTest extends \NetterTechEventsTestCase {

	/**
	 * PathConflictDetector instance.
	 *
	 * @var PathConflictDetector
	 */
	private PathConflictDetector $detector;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock get_option for PathHelper::get_base_path.
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					return array();
				}
				return $default;
			}
		);

		// Mock trailingslashit for PathHelper.
		Functions\when( 'trailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/' ) . '/';
			}
		);

		try {
			$this->detector = new PathConflictDetector();
		} catch ( \Throwable $e ) {
			$this->fail( 'Failed to create PathConflictDetector: ' . $e->getMessage() );
		}
	}

	// =========================================================================
	// Instantiation tests
	// =========================================================================

	/**
	 * Test class can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_be_instantiated(): void {
		$this->assertInstanceOf( PathConflictDetector::class, $this->detector );
	}

	// =========================================================================
	// register tests
	// =========================================================================

	/**
	 * Test register adds action hooks.
	 *
	 * @return void
	 */
	public function test_register_adds_admin_notices_hook(): void {
		$hooks_added = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$hooks_added ) {
				$hooks_added[] = array( 'hook' => $hook, 'callback' => $callback );
				return true;
			}
		);

		$this->detector->register();

		// Find the admin_notices hook.
		$admin_notices_hook = array_filter(
			$hooks_added,
			fn( $h ) => $h['hook'] === 'admin_notices'
		);

		$this->assertNotEmpty( $admin_notices_hook, 'admin_notices hook should be registered' );
	}

	/**
	 * Test register adds cache clearing hooks.
	 *
	 * @return void
	 */
	public function test_register_adds_cache_clearing_hooks(): void {
		$hooks_added = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$hooks_added ) {
				$hooks_added[] = $hook;
				return true;
			}
		);

		$this->detector->register();

		$expected_hooks = array(
			'update_option_nettertech_events_settings',
			'update_option_rewrite_rules',
			'activated_plugin',
			'deactivated_plugin',
		);

		foreach ( $expected_hooks as $expected ) {
			$this->assertContains( $expected, $hooks_added, "Hook '$expected' should be registered" );
		}
	}

	// =========================================================================
	// clear_cache tests
	// =========================================================================

	/**
	 * Test clear_cache deletes transient.
	 *
	 * @return void
	 */
	public function test_clear_cache_deletes_transient(): void {
		$deleted_keys = array();

		Functions\when( 'delete_transient' )->alias(
			function ( $key ) use ( &$deleted_keys ) {
				$deleted_keys[] = $key;
				return true;
			}
		);

		$this->detector->clear_cache();

		// Per-scope cache: one key per scope (events + spaces).
		$this->assertContains( 'nettertech_events_path_conflicts_events', $deleted_keys );
		$this->assertContains( 'nettertech_events_path_conflicts_spaces', $deleted_keys );
	}

	// =========================================================================
	// get_conflicts tests
	// =========================================================================

	/**
	 * Test get_conflicts returns cached results.
	 *
	 * @return void
	 */
	public function test_get_conflicts_returns_cached_results(): void {
		$cached_conflicts = array(
			array(
				'type'        => 'post_type',
				'source'      => 'tribe_events',
				'description' => 'The Events Calendar uses the same URL path.',
				'scope'       => 'events',
				'scope_path'  => 'events',
			),
		);

		// Scope-aware cache: only the events scope has cached data; spaces scope is empty.
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( $cached_conflicts ) {
				if ( 'nettertech_events_path_conflicts_events' === $key ) {
					return $cached_conflicts;
				}
				return array();
			}
		);

		$conflicts = $this->detector->get_conflicts();

		$this->assertSame( $cached_conflicts, $conflicts );
	}

	/**
	 * Test get_conflicts detects no conflicts when none exist.
	 *
	 * @return void
	 */
	public function test_get_conflicts_returns_empty_when_no_conflicts(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertIsArray( $conflicts );
		$this->assertEmpty( $conflicts );
	}

	/**
	 * Test get_conflicts force refresh bypasses cache.
	 *
	 * @return void
	 */
	public function test_get_conflicts_force_refresh_bypasses_cache(): void {
		$transient_checked = false;

		// With force_refresh=true, get_transient should NOT be called.
		Functions\when( 'get_transient' )->alias(
			function () use ( &$transient_checked ) {
				$transient_checked = true;
				return array( 'cached_data' );
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		$conflicts = $this->detector->get_conflicts( true );

		// Should return fresh results (empty in this case).
		$this->assertIsArray( $conflicts );
		$this->assertFalse( $transient_checked, 'get_transient should not be called with force_refresh=true' );
	}

	/**
	 * Test get_conflicts caches results.
	 *
	 * @return void
	 */
	public function test_get_conflicts_caches_results(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		$set_calls = array();

		Functions\when( 'set_transient' )->alias(
			function ( $key, $data, $expiration ) use ( &$set_calls ) {
				$set_calls[] = array(
					'key'        => $key,
					'data'       => $data,
					'expiration' => $expiration,
				);
				return true;
			}
		);

		$this->detector->get_conflicts( true );

		// Per-scope caching: one set_transient call per scope (events + spaces).
		$keys = array_column( $set_calls, 'key' );
		$this->assertContains( 'nettertech_events_path_conflicts_events', $keys );
		$this->assertContains( 'nettertech_events_path_conflicts_spaces', $keys );
		foreach ( $set_calls as $call ) {
			$this->assertIsArray( $call['data'] );
			$this->assertSame( 3600, $call['expiration'] );
		}
	}

	// =========================================================================
	// has_conflicts tests
	// =========================================================================

	/**
	 * Test has_conflicts returns false when no conflicts.
	 *
	 * @return void
	 */
	public function test_has_conflicts_returns_false_when_no_conflicts(): void {
		Functions\when( 'get_transient' )->justReturn( array() );

		$this->assertFalse( $this->detector->has_conflicts() );
	}

	/**
	 * Test has_conflicts returns true when conflicts exist.
	 *
	 * @return void
	 */
	public function test_has_conflicts_returns_true_when_conflicts_exist(): void {
		$conflicts = array(
			array(
				'type'        => 'post_type',
				'source'      => 'tribe_events',
				'description' => 'Conflict',
			),
		);

		Functions\when( 'get_transient' )->justReturn( $conflicts );

		$this->assertTrue( $this->detector->has_conflicts() );
	}

	// =========================================================================
	// detect_post_type_conflicts tests (indirect via get_conflicts)
	// =========================================================================

	/**
	 * Test detects conflict with tribe_events post type.
	 *
	 * @return void
	 */
	public function test_detects_tribe_events_conflict(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		// Create mock post type object.
		$tribe_events          = new \stdClass();
		$tribe_events->name    = 'tribe_events';
		$tribe_events->label   = 'Events';
		$tribe_events->rewrite = array( 'slug' => 'events' );

		Functions\when( 'get_post_types' )->justReturn(
			array( 'tribe_events' => $tribe_events )
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertSame( 'post_type', $conflicts[0]['type'] );
		$this->assertSame( 'tribe_events', $conflicts[0]['source'] );
		$this->assertStringContainsString( 'The Events Calendar', $conflicts[0]['description'] );
	}

	/**
	 * Test ignores post types without rewrite rules.
	 *
	 * @return void
	 */
	public function test_ignores_post_types_without_rewrite(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		// Create mock post type without rewrite.
		$custom_post          = new \stdClass();
		$custom_post->name    = 'custom_post';
		$custom_post->label   = 'Custom';
		$custom_post->rewrite = false;

		Functions\when( 'get_post_types' )->justReturn(
			array( 'custom_post' => $custom_post )
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertEmpty( $conflicts );
	}

	/**
	 * Test ignores post types with different slug.
	 *
	 * @return void
	 */
	public function test_ignores_post_types_with_different_slug(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		// Create mock post type with different slug.
		$product          = new \stdClass();
		$product->name    = 'product';
		$product->label   = 'Products';
		$product->rewrite = array( 'slug' => 'products' );

		Functions\when( 'get_post_types' )->justReturn(
			array( 'product' => $product )
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertEmpty( $conflicts );
	}

	// =========================================================================
	// detect_page_conflicts tests (indirect via get_conflicts)
	// =========================================================================

	/**
	 * Test detects conflict with published page.
	 *
	 * @return void
	 */
	public function test_detects_page_conflict(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );

		// Create mock page.
		$page              = new \stdClass();
		$page->ID          = 42;
		$page->post_title  = 'Events';
		$page->post_status = 'publish';

		Functions\when( 'get_page_by_path' )->justReturn( $page );

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertSame( 'page', $conflicts[0]['type'] );
		$this->assertSame( 'page_42', $conflicts[0]['source'] );
		$this->assertStringContainsString( 'Events', $conflicts[0]['description'] );
	}

	/**
	 * Test ignores draft pages.
	 *
	 * @return void
	 */
	public function test_ignores_draft_pages(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );

		// Create mock draft page.
		$page              = new \stdClass();
		$page->ID          = 42;
		$page->post_title  = 'Events';
		$page->post_status = 'draft';

		Functions\when( 'get_page_by_path' )->justReturn( $page );

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertEmpty( $conflicts );
	}

	/**
	 * Test handles no matching page.
	 *
	 * @return void
	 */
	public function test_handles_no_matching_page(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertEmpty( $conflicts );
	}

	// =========================================================================
	// get_status_for_settings tests
	// =========================================================================

	/**
	 * Test get_status_for_settings returns no conflicts message.
	 *
	 * @return void
	 */
	public function test_get_status_for_settings_no_conflicts(): void {
		Functions\when( 'get_transient' )->justReturn( array() );

		$status = $this->detector->get_status_for_settings();

		$this->assertFalse( $status['has_conflicts'] );
		$this->assertEmpty( $status['conflicts'] );
		$this->assertStringContainsString( 'No conflicts', $status['message'] );
	}

	/**
	 * Test get_status_for_settings returns conflicts info.
	 *
	 * @return void
	 */
	public function test_get_status_for_settings_with_conflicts(): void {
		$conflicts = array(
			array(
				'type'        => 'post_type',
				'source'      => 'The Events Calendar',
				'description' => 'Conflict description',
				'scope'       => 'events',
				'scope_path'  => 'events',
			),
		);

		// Scope-aware: only events scope has conflicts.
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( $conflicts ) {
				if ( 'nettertech_events_path_conflicts_events' === $key ) {
					return $conflicts;
				}
				return array();
			}
		);

		$status = $this->detector->get_status_for_settings( 'events' );

		$this->assertTrue( $status['has_conflicts'] );
		$this->assertSame( $conflicts, $status['conflicts'] );
		$this->assertStringContainsString( 'The Events Calendar', $status['message'] );
	}

	/**
	 * Test get_status_for_settings deduplicates sources.
	 *
	 * @return void
	 */
	public function test_get_status_for_settings_deduplicates_sources(): void {
		$conflicts = array(
			array(
				'type'        => 'post_type',
				'source'      => 'The Events Calendar',
				'description' => 'First conflict',
			),
			array(
				'type'        => 'rewrite_rule',
				'source'      => 'The Events Calendar',
				'description' => 'Second conflict',
			),
		);

		Functions\when( 'get_transient' )->justReturn( $conflicts );

		$status = $this->detector->get_status_for_settings();

		// Should only mention TEC once in message.
		$this->assertSame( 1, substr_count( $status['message'], 'The Events Calendar' ) );
	}

	// =========================================================================
	// handle_dismiss tests
	// =========================================================================

	/**
	 * Test handle_dismiss does nothing without query param.
	 *
	 * @return void
	 */
	public function test_handle_dismiss_does_nothing_without_param(): void {
		$_GET = array();

		$meta_updated   = false;
		$redirect_called = false;

		Functions\when( 'update_user_meta' )->alias(
			function () use ( &$meta_updated ) {
				$meta_updated = true;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirect_called ) {
				$redirect_called = true;
			}
		);

		$this->detector->handle_dismiss();

		$this->assertFalse( $meta_updated, 'update_user_meta should not be called without query param' );
		$this->assertFalse( $redirect_called, 'wp_safe_redirect should not be called without query param' );
	}

	/**
	 * Test handle_dismiss verifies nonce.
	 *
	 * @return void
	 */
	public function test_handle_dismiss_verifies_nonce(): void {
		$_GET = array(
			'nettertech_events_dismiss_conflict' => '1',
			'_wpnonce'                      => 'invalid_nonce',
		);

		$meta_updated   = false;
		$redirect_called = false;

		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_unslash' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'update_user_meta' )->alias(
			function () use ( &$meta_updated ) {
				$meta_updated = true;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirect_called ) {
				$redirect_called = true;
			}
		);

		$this->detector->handle_dismiss();

		$this->assertFalse( $meta_updated, 'update_user_meta should not be called with invalid nonce' );
		$this->assertFalse( $redirect_called, 'wp_safe_redirect should not be called with invalid nonce' );
	}

	/**
	 * Test handle_dismiss checks capability.
	 *
	 * @return void
	 */
	public function test_handle_dismiss_checks_capability(): void {
		$_GET = array(
			'nettertech_events_dismiss_conflict' => '1',
			'_wpnonce'                      => 'valid_nonce',
		);

		$meta_updated   = false;
		$redirect_called = false;

		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_unslash' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'update_user_meta' )->alias(
			function () use ( &$meta_updated ) {
				$meta_updated = true;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirect_called ) {
				$redirect_called = true;
			}
		);

		$this->detector->handle_dismiss();

		$this->assertFalse( $meta_updated, 'update_user_meta should not be called without capability' );
		$this->assertFalse( $redirect_called, 'wp_safe_redirect should not be called without capability' );
	}

	// =========================================================================
	// maybe_show_conflict_notice tests
	// =========================================================================

	/**
	 * Test maybe_show_conflict_notice returns early without screen.
	 *
	 * @return void
	 */
	public function test_maybe_show_conflict_notice_returns_without_screen(): void {
		$transient_checked = false;

		Functions\when( 'get_current_screen' )->justReturn( null );
		Functions\when( 'get_transient' )->alias(
			function () use ( &$transient_checked ) {
				$transient_checked = true;
				return array();
			}
		);

		$this->detector->maybe_show_conflict_notice();

		$this->assertFalse( $transient_checked, 'get_transient should not be called without screen' );
	}

	/**
	 * Test maybe_show_conflict_notice shows on nettertech-events pages.
	 *
	 * @return void
	 */
	public function test_maybe_show_conflict_notice_shows_on_nte_pages(): void {
		$screen     = new \stdClass();
		$screen->id = 'toplevel_page_nettertech-events';

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'get_transient' )->justReturn( array() );

		// No output expected when no conflicts.
		$this->expectOutputString( '' );

		$this->detector->maybe_show_conflict_notice();
	}

	/**
	 * Test maybe_show_conflict_notice ignores unrelated screens.
	 *
	 * @return void
	 */
	public function test_maybe_show_conflict_notice_ignores_unrelated_screens(): void {
		$transient_checked = false;

		$screen     = new \stdClass();
		$screen->id = 'dashboard';

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'get_transient' )->alias(
			function () use ( &$transient_checked ) {
				$transient_checked = true;
				return array();
			}
		);

		$this->detector->maybe_show_conflict_notice();

		$this->assertFalse( $transient_checked, 'get_transient should not be called on dashboard' );
	}

	// =========================================================================
	// detect_rewrite_rule_conflicts tests
	// =========================================================================

	/**
	 * Test detects rewrite rule conflict from another plugin.
	 *
	 * @return void
	 */
	public function test_detects_rewrite_rule_conflict(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'get_post_type_object' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		// Override get_option to return conflicting rewrite rules.
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					return array(
						'^events/([^/]+)/?$' => 'index.php?post_type=other_event&slug=$matches[1]',
					);
				}
				return $default;
			}
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertSame( 'rewrite_rule', $conflicts[0]['type'] );
	}

	/**
	 * Test ignores own rewrite rules.
	 *
	 * @return void
	 */
	public function test_ignores_own_rewrite_rules(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		// Override get_option to return our own rewrite rules.
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					return array(
						'^events/([^/]+)/?$' => 'index.php?nettertech_events_event=$matches[1]',
					);
				}
				return $default;
			}
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertEmpty( $conflicts );
	}

	/**
	 * Test handles non-array rewrite rules gracefully.
	 *
	 * @return void
	 */
	public function test_handles_non_array_rewrite_rules(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					return ''; // Non-array.
				}
				return $default;
			}
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertIsArray( $conflicts );
	}

	/**
	 * Test identifies known event post type in rewrite rule.
	 *
	 * @return void
	 */
	public function test_identifies_known_event_post_type_in_rule(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					return array(
						'^events/([^/]+)/?$' => 'index.php?post_type=tribe_events&slug=$matches[1]',
					);
				}
				return $default;
			}
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertStringContainsString( 'The Events Calendar', $conflicts[0]['source'] );
	}

	/**
	 * Test identifies known event post type by query param name.
	 *
	 * @return void
	 */
	public function test_identifies_known_post_type_by_param_name(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					return array(
						'^events/([^/]+)/?$' => 'index.php?mec-events=$matches[1]',
					);
				}
				return $default;
			}
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertStringContainsString( 'Modern Events Calendar', $conflicts[0]['source'] );
	}

	/**
	 * Test extracts post type label from registered post type.
	 *
	 * @return void
	 */
	public function test_extracts_registered_post_type_label(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		$custom_type        = new \stdClass();
		$custom_type->label = 'Custom Events';

		Functions\when( 'get_post_type_object' )->justReturn( $custom_type );

		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					return array(
						'^events/([^/]+)/?$' => 'index.php?post_type=custom_events&slug=$matches[1]',
					);
				}
				return $default;
			}
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertSame( 'Custom Events', $conflicts[0]['source'] );
	}

	/**
	 * Test avoids duplicate conflict entries for same source.
	 *
	 * @return void
	 */
	public function test_avoids_duplicate_rewrite_rule_conflicts(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		$custom_type        = new \stdClass();
		$custom_type->label = 'Other Plugin';

		Functions\when( 'get_post_type_object' )->justReturn( $custom_type );

		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					// Multiple rules from same source.
					return array(
						'^events/([^/]+)/?$'      => 'index.php?post_type=other_plugin&slug=$matches[1]',
						'^events/([^/]+)/page/?$' => 'index.php?post_type=other_plugin&slug=$matches[1]&paged=1',
					);
				}
				return $default;
			}
		);

		$conflicts = $this->detector->get_conflicts( true );

		// Should only have one conflict entry, not two.
		$this->assertCount( 1, $conflicts );
	}

	// =========================================================================
	// detect_post_type_conflicts edge cases
	// =========================================================================

	/**
	 * Test detects post type with prefix match (our path starts with slug).
	 *
	 * @return void
	 */
	public function test_detects_post_type_prefix_conflict(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text, $domain = '' ) => $text );

		// Our path is 'events/calendar' and post type uses 'events'.
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events/calendar' );
				}
				return $default;
			}
		);

		$other_events          = new \stdClass();
		$other_events->name    = 'other_events';
		$other_events->label   = 'Other Events';
		$other_events->rewrite = array( 'slug' => 'events' );

		Functions\when( 'get_post_types' )->justReturn(
			array( 'other_events' => $other_events )
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
	}

	/**
	 * Test uses post type name as fallback slug.
	 *
	 * @return void
	 */
	public function test_uses_post_type_name_as_fallback_slug(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text, $domain = '' ) => $text );

		// Post type with rewrite array but no slug key.
		$events_pt          = new \stdClass();
		$events_pt->name    = 'events'; // Name matches our base path.
		$events_pt->label   = 'Events CPT';
		$events_pt->rewrite = array( 'with_front' => false ); // No 'slug' key.

		Functions\when( 'get_post_types' )->justReturn(
			array( 'events' => $events_pt )
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertSame( 'events', $conflicts[0]['source'] );
	}

	/**
	 * Test uses label for unknown post type.
	 *
	 * @return void
	 */
	public function test_uses_label_for_unknown_post_type(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text, $domain = '' ) => $text );

		// Unknown post type (not in KNOWN_EVENT_POST_TYPES).
		$custom_pt          = new \stdClass();
		$custom_pt->name    = 'my_custom_type';
		$custom_pt->label   = 'My Custom Events';
		$custom_pt->rewrite = array( 'slug' => 'events' );

		Functions\when( 'get_post_types' )->justReturn(
			array( 'my_custom_type' => $custom_pt )
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertStringContainsString( 'My Custom Events', $conflicts[0]['description'] );
	}

	// =========================================================================
	// maybe_show_conflict_notice advanced tests
	// =========================================================================

	/**
	 * Test maybe_show_conflict_notice respects dismissed state.
	 *
	 * @return void
	 */
	public function test_maybe_show_conflict_notice_respects_dismissed(): void {
		$output_started = false;

		$screen     = new \stdClass();
		$screen->id = 'toplevel_page_nettertech-events';

		$conflicts = array(
			array(
				'type'        => 'post_type',
				'source'      => 'tribe_events',
				'description' => 'Conflict',
			),
		);

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'get_transient' )->justReturn( $conflicts );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// Dismissal key is "events_path|spaces_path" so the notice re-appears when either changes.
		Functions\when( 'get_user_meta' )->justReturn( 'events|spaces' );

		$this->expectOutputString( '' );

		$this->detector->maybe_show_conflict_notice();
	}

	/**
	 * Test maybe_show_conflict_notice resets dismissed when path changes.
	 *
	 * @return void
	 */
	public function test_maybe_show_conflict_notice_resets_dismissed_on_path_change(): void {
		$meta_deleted = false;

		$screen     = new \stdClass();
		$screen->id = 'toplevel_page_nettertech-events';

		$conflicts = array(
			array(
				'type'        => 'post_type',
				'source'      => 'tribe_events',
				'description' => 'Conflict',
			),
		);

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'get_transient' )->justReturn( $conflicts );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 'old-path' ); // Dismissed for different path.
		Functions\when( 'delete_user_meta' )->alias(
			function () use ( &$meta_deleted ) {
				$meta_deleted = true;
				return true;
			}
		);
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/' );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url ) => $url . '&_wpnonce=123' );
		Functions\when( 'add_query_arg' )->alias( fn( $k, $v ) => "?$k=$v" );
		Functions\when( 'esc_html__' )->alias( fn( $text ) => $text );
		Functions\when( 'esc_html' )->alias( fn( $text ) => $text );
		Functions\when( 'esc_url' )->alias( fn( $url ) => $url );

		ob_start();
		$this->detector->maybe_show_conflict_notice();
		$output = ob_get_clean();

		$this->assertTrue( $meta_deleted, 'User meta should be deleted when path changes' );
		$this->assertStringContainsString( 'notice-warning', $output );
	}

	/**
	 * Test maybe_show_conflict_notice shows on plugins page.
	 *
	 * @return void
	 */
	public function test_maybe_show_conflict_notice_shows_on_plugins_page(): void {
		$screen     = new \stdClass();
		$screen->id = 'plugins';

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'get_transient' )->justReturn( array() ); // No conflicts.

		$this->expectOutputString( '' );

		$this->detector->maybe_show_conflict_notice();
	}

	/**
	 * Test maybe_show_conflict_notice renders notice with conflicts.
	 *
	 * @return void
	 */
	public function test_maybe_show_conflict_notice_renders_notice(): void {
		$screen     = new \stdClass();
		$screen->id = 'toplevel_page_nettertech-events';

		$conflicts = array(
			array(
				'type'        => 'post_type',
				'source'      => 'tribe_events',
				'description' => 'The Events Calendar uses the same URL path.',
			),
		);

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'get_transient' )->justReturn( $conflicts );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '' ); // Not dismissed.
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/' );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url ) => $url . '&_wpnonce=123' );
		Functions\when( 'add_query_arg' )->alias( fn( $k, $v ) => "?$k=$v" );
		Functions\when( 'esc_html__' )->alias( fn( $text ) => $text );
		Functions\when( 'esc_html' )->alias( fn( $text ) => $text );
		Functions\when( 'esc_url' )->alias( fn( $url ) => $url );

		ob_start();
		$this->detector->maybe_show_conflict_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'URL Path Conflict Detected', $output );
		$this->assertStringContainsString( 'The Events Calendar uses the same URL path', $output );
		$this->assertStringContainsString( 'Change Base Path', $output );
		$this->assertStringContainsString( 'Dismiss', $output );
	}

	// =========================================================================
	// identify_rule_source fallback test
	// =========================================================================

	/**
	 * Test identify_rule_source returns generic fallback.
	 *
	 * @return void
	 */
	public function test_identify_rule_source_returns_generic_fallback(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'get_post_type_object' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					// Rule with no identifiable source.
					return array(
						'^events/special/?$' => 'index.php?special_page=1',
					);
				}
				return $default;
			}
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertSame( 'Another plugin', $conflicts[0]['source'] );
	}

	/**
	 * Test extracts raw post type name when not registered.
	 *
	 * @return void
	 */
	public function test_extracts_raw_post_type_name_when_not_registered(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'get_post_type_object' )->justReturn( null ); // Not registered.
		Functions\when( '__' )->alias( fn( $text ) => $text );

		Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				if ( 'rewrite_rules' === $option ) {
					return array(
						'^events/([^/]+)/?$' => 'index.php?post_type=unregistered_type&slug=$matches[1]',
					);
				}
				return $default;
			}
		);

		$conflicts = $this->detector->get_conflicts( true );

		$this->assertNotEmpty( $conflicts );
		$this->assertSame( 'unregistered_type', $conflicts[0]['source'] );
	}
}
