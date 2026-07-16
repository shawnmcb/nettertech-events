<?php
/**
 * PageContextDetector unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Core\PageContextDetector;

/**
 * Test PageContextDetector functionality.
 *
 * Covers event page detection, archive detection, shortcode/block detection,
 * admin page detection, and caching behavior.
 */
class PageContextDetectorTest extends \NetterTechEventsTestCase {

	/**
	 * PageContextDetector instance.
	 *
	 * @var PageContextDetector
	 */
	private PageContextDetector $detector;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->detector = new PageContextDetector();
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['post'] );
		parent::tearDown();
	}

	/**
	 * Set up query var stubs that return empty by default.
	 *
	 * @param array<string, string> $overrides Query var => value overrides.
	 * @return void
	 */
	private function stub_query_vars( array $overrides = array() ): void {
		Functions\when( 'get_query_var' )->alias(
			function ( string $var ) use ( $overrides ) {
				return $overrides[ $var ] ?? '';
			}
		);
	}

	/**
	 * Set up WordPress conditional stubs.
	 *
	 * @param bool $is_singular          Return for is_singular().
	 * @param bool $is_post_type_archive Return for is_post_type_archive().
	 * @return void
	 */
	private function stub_conditionals( bool $is_singular = false, bool $is_post_type_archive = false ): void {
		Functions\when( 'is_singular' )->justReturn( $is_singular );
		Functions\when( 'is_post_type_archive' )->justReturn( $is_post_type_archive );
	}

	/**
	 * Set up shortcode and block stubs.
	 *
	 * @param array<string> $shortcodes Active shortcodes.
	 * @param array<string> $blocks     Active blocks.
	 * @return void
	 */
	private function stub_content_checks( array $shortcodes = array(), array $blocks = array() ): void {
		Functions\when( 'has_shortcode' )->alias(
			function ( string $content, string $tag ) use ( $shortcodes ) {
				return in_array( $tag, $shortcodes, true );
			}
		);

		Functions\when( 'has_block' )->alias(
			function ( string $block_name ) use ( $blocks ) {
				return in_array( $block_name, $blocks, true );
			}
		);
	}

	/**
	 * Create a mock WP_Post with post_content.
	 *
	 * @param string $content Post content.
	 * @return \WP_Post
	 */
	private function make_post( string $content = '' ): object {
		$post               = Mockery::mock( 'WP_Post' );
		$post->post_content = $content;
		return $post;
	}

	// =========================================================================
	// Single Event Page Detection
	// =========================================================================

	/**
	 * Test event slug query var detected as single view.
	 *
	 * @return void
	 */
	public function test_event_slug_query_var_detected(): void {
		$this->stub_query_vars( array( 'nettertech_events_event_slug' => 'my-event' ) );
		$this->stub_conditionals();
		$this->stub_content_checks();

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'single', $this->detector->get_detected_views() );
	}

	/**
	 * Test occurrence datetime query var detected as single view.
	 *
	 * @return void
	 */
	public function test_occurrence_datetime_query_var_detected(): void {
		$this->stub_query_vars( array( 'nettertech_events_occurrence_datetime' => '2026-01-15T10:00' ) );
		$this->stub_conditionals();
		$this->stub_content_checks();

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'single', $this->detector->get_detected_views() );
	}

	/**
	 * Test space slug query var detected as single view.
	 *
	 * @return void
	 */
	public function test_space_slug_query_var_detected(): void {
		$this->stub_query_vars( array( 'nettertech_events_space_slug' => 'main-hall' ) );
		$this->stub_conditionals();
		$this->stub_content_checks();

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'single', $this->detector->get_detected_views() );
	}

	/**
	 * Test singular nettertech_event CPT detected.
	 *
	 * @return void
	 */
	public function test_singular_cpt_detected(): void {
		$this->stub_query_vars();
		$this->stub_conditionals( true, false );
		$this->stub_content_checks();

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'single', $this->detector->get_detected_views() );
	}

	// =========================================================================
	// Archive Page Detection
	// =========================================================================

	/**
	 * Test post type archive detected as grid view.
	 *
	 * @return void
	 */
	public function test_post_type_archive_detected(): void {
		$this->stub_query_vars();
		$this->stub_conditionals( false, true );
		$this->stub_content_checks();

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'grid', $this->detector->get_detected_views() );
	}

	/**
	 * Test nettertech_events_archive query var detected as grid view.
	 *
	 * @return void
	 */
	public function test_nte_archive_query_var_detected(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );
		$this->stub_conditionals();
		$this->stub_content_checks();

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'grid', $this->detector->get_detected_views() );
	}

	/**
	 * Test nettertech_events_past_archive query var detected as grid view.
	 *
	 * @return void
	 */
	public function test_past_archive_query_var_detected(): void {
		$this->stub_query_vars( array( 'nettertech_events_past_archive' => '1' ) );
		$this->stub_conditionals();
		$this->stub_content_checks();

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'grid', $this->detector->get_detected_views() );
	}

	// =========================================================================
	// Shortcode Detection
	// =========================================================================

	/**
	 * Test nettertech_events_calendar shortcode detected.
	 *
	 * @return void
	 */
	public function test_calendar_shortcode_detected(): void {
		$GLOBALS['post'] = $this->make_post( '[nettertech_events_calendar]' );

		$this->stub_query_vars();
		$this->stub_conditionals();
		$this->stub_content_checks( array( 'nettertech_events_calendar' ) );

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'calendar', $this->detector->get_detected_views() );
	}

	/**
	 * Test nettertech_events_carousel shortcode detected.
	 *
	 * @return void
	 */
	public function test_carousel_shortcode_detected(): void {
		$GLOBALS['post'] = $this->make_post( '[nettertech_events_carousel]' );

		$this->stub_query_vars();
		$this->stub_conditionals();
		$this->stub_content_checks( array( 'nettertech_events_carousel' ) );

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'carousel', $this->detector->get_detected_views() );
	}

	/**
	 * Test nettertech_events_grid shortcode detected.
	 *
	 * @return void
	 */
	public function test_grid_shortcode_detected(): void {
		$GLOBALS['post'] = $this->make_post( '[nettertech_events_grid]' );

		$this->stub_query_vars();
		$this->stub_conditionals();
		$this->stub_content_checks( array( 'nettertech_events_grid' ) );

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'grid', $this->detector->get_detected_views() );
	}

	/**
	 * Test nettertech_events default shortcode detected.
	 *
	 * @return void
	 */
	public function test_default_shortcode_detected(): void {
		$GLOBALS['post'] = $this->make_post( '[nettertech_events]' );

		$this->stub_query_vars();
		$this->stub_conditionals();
		$this->stub_content_checks( array( 'nettertech_events' ) );

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'grid', $this->detector->get_detected_views() );
	}

	// =========================================================================
	// Block Detection
	// =========================================================================

	/**
	 * Test calendar block detected.
	 *
	 * @return void
	 */
	public function test_calendar_block_detected(): void {
		$GLOBALS['post'] = $this->make_post( '<!-- wp:nettertech-events/calendar /-->' );

		$this->stub_query_vars();
		$this->stub_conditionals();
		$this->stub_content_checks( array(), array( 'nettertech-events/calendar' ) );

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$this->assertContains( 'calendar', $this->detector->get_detected_views() );
	}

	// =========================================================================
	// Non-Event Page
	// =========================================================================

	/**
	 * Test non-event page returns false.
	 *
	 * @return void
	 */
	public function test_non_event_page_returns_false(): void {
		$this->stub_query_vars();
		$this->stub_conditionals();
		$this->stub_content_checks();

		$this->assertFalse( $this->detector->page_has_nettertech_events_content() );
		$this->assertEmpty( $this->detector->get_detected_views() );
	}

	/**
	 * Test non-event page with post but no NTE content.
	 *
	 * @return void
	 */
	public function test_non_event_page_with_post(): void {
		$GLOBALS['post'] = $this->make_post( 'Just a regular page with no events.' );

		$this->stub_query_vars();
		$this->stub_conditionals();
		$this->stub_content_checks();

		$this->assertFalse( $this->detector->page_has_nettertech_events_content() );
	}

	// =========================================================================
	// Caching Behavior
	// =========================================================================

	/**
	 * Test result is cached on second call.
	 *
	 * @return void
	 */
	public function test_result_is_cached(): void {
		$this->stub_query_vars( array( 'nettertech_events_event_slug' => 'my-event' ) );
		$this->stub_conditionals();
		$this->stub_content_checks();

		$result1 = $this->detector->page_has_nettertech_events_content();
		$result2 = $this->detector->page_has_nettertech_events_content();

		$this->assertTrue( $result1 );
		$this->assertTrue( $result2 );
	}

	// =========================================================================
	// get_primary_view() Tests
	// =========================================================================

	/**
	 * Test get_primary_view returns first detected view.
	 *
	 * @return void
	 */
	public function test_get_primary_view_returns_first(): void {
		$this->stub_query_vars( array( 'nettertech_events_event_slug' => 'my-event' ) );
		$this->stub_conditionals();
		$this->stub_content_checks();

		$this->detector->page_has_nettertech_events_content();

		$this->assertEquals( 'single', $this->detector->get_primary_view() );
	}

	/**
	 * Test get_primary_view returns null when no content detected.
	 *
	 * @return void
	 */
	public function test_get_primary_view_returns_null_when_empty(): void {
		$this->assertNull( $this->detector->get_primary_view() );
	}

	// =========================================================================
	// Multiple Views Detection
	// =========================================================================

	/**
	 * Test multiple view types detected on same page.
	 *
	 * @return void
	 */
	public function test_multiple_views_detected(): void {
		$GLOBALS['post'] = $this->make_post( '[nettertech_events_calendar][nettertech_events_carousel]' );

		$this->stub_query_vars();
		$this->stub_conditionals();
		$this->stub_content_checks( array( 'nettertech_events_calendar', 'nettertech_events_carousel' ) );

		$this->assertTrue( $this->detector->page_has_nettertech_events_content() );
		$views = $this->detector->get_detected_views();
		$this->assertContains( 'calendar', $views );
		$this->assertContains( 'carousel', $views );
	}

	/**
	 * Test duplicate view types are deduplicated.
	 *
	 * @return void
	 */
	public function test_duplicate_views_deduplicated(): void {
		$GLOBALS['post'] = $this->make_post( '[nettertech_events_grid][nettertech_events_list]' );

		$this->stub_query_vars();
		$this->stub_conditionals();
		// Both nettertech_events_grid and nettertech_events_list map to 'grid'.
		$this->stub_content_checks( array( 'nettertech_events_grid', 'nettertech_events_list' ) );

		$this->detector->page_has_nettertech_events_content();
		$views = $this->detector->get_detected_views();

		// Should be deduplicated - 'grid' appears only once.
		$grid_count = count( array_filter( $views, function ( $v ) {
			return 'grid' === $v;
		} ) );
		$this->assertEquals( 1, $grid_count );
	}

	// =========================================================================
	// is_nettertech_events_admin_page() Tests
	// =========================================================================

	/**
	 * Test recognized admin page hook suffixes.
	 *
	 * @return void
	 */
	public function test_recognized_admin_page_suffixes(): void {
		$valid_suffixes = array(
			'toplevel_page_nettertech-events',
			'events_page_nettertech-events-new',
			'events_page_nettertech-events-settings',
			'events_page_nettertech-events-check-in',
			'events_page_nettertech-events-qr-generator',
			'nettertech-events_page_nettertech-events-settings',
			'nettertech-events_page_nettertech-events-tickets',
			'nettertech-events_page_nettertech-events-attendees',
		);

		foreach ( $valid_suffixes as $suffix ) {
			$detector = new PageContextDetector();
			$this->assertTrue(
				$detector->is_nettertech_events_admin_page( $suffix ),
				sprintf( 'Hook suffix "%s" should be recognized as NTE admin page', $suffix )
			);
		}
	}

	/**
	 * Test non-NTE admin page returns false when no screen.
	 *
	 * @return void
	 */
	public function test_non_nte_admin_page_returns_false(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );

		$this->assertFalse( $this->detector->is_nettertech_events_admin_page( 'options-general.php' ) );
	}

	/**
	 * Test admin page with nettertech_event screen post type detected.
	 *
	 * @return void
	 */
	public function test_nte_event_screen_post_type_detected(): void {
		$screen            = new \stdClass();
		$screen->post_type = 'nettertech_event';

		Functions\when( 'get_current_screen' )->justReturn( $screen );

		$this->assertTrue( $this->detector->is_nettertech_events_admin_page( 'post.php' ) );
	}

	/**
	 * Test admin page with non-NTE screen post type not detected.
	 *
	 * @return void
	 */
	public function test_non_nte_screen_post_type_not_detected(): void {
		$screen            = new \stdClass();
		$screen->post_type = 'post';

		Functions\when( 'get_current_screen' )->justReturn( $screen );

		$this->assertFalse( $this->detector->is_nettertech_events_admin_page( 'post.php' ) );
	}
}
