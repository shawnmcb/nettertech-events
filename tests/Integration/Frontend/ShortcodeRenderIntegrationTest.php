<?php
/**
 * Shortcode render integration tests.
 *
 * Regression gate for NTE-017 bug 2 class: every shortcode must produce
 * non-empty output and cause base.css to be enqueued via PageContextDetector.
 * If PageContextDetector's hardcoded shortcode map drifts, the enqueue
 * assertion catches it before it reaches production.
 *
 * @package NetterTechEvents\Tests\Integration\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Frontend;

use NetterTechEvents\Core\Assets;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Services\PaletteResolver;
use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Tests\Integration\Support\FixtureFactory;

/**
 * Integration tests: shortcode render + base.css enqueue gate.
 *
 * Each test:
 * 1. Creates 3 fixture events (1 single, 1 weekly, 1 monthly recurring).
 * 2. Inserts a WP page with the shortcode in post_content.
 * 3. Sets $GLOBALS['post'] so PageContextDetector can read the content.
 * 4. Registers styles and calls maybe_enqueue_frontend().
 * 5. Asserts non-empty output containing a fixture event title.
 * 6. Asserts nettertech-events-base is enqueued (NTE-017 bug 2 class gate).
 */
class ShortcodeRenderIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Saved $GLOBALS['post'] to restore after each test.
	 *
	 * @var \WP_Post|null
	 */
	private ?\WP_Post $saved_post = null;

	/**
	 * Assets instance for triggering enqueue logic.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Save and clear global post to prevent cross-test pollution.
		$this->saved_post   = $GLOBALS['post'] ?? null;
		$GLOBALS['post']    = null;

		// Fresh Assets instance with default-constructed dependencies.
		$this->assets = new Assets( new PaletteResolver(), new NetterTechEventsSettings() );

		// Register style handles so wp_style_is() has something to check.
		$this->assets->register();

		// Ensure we reset WP_Styles queue between tests.
		wp_dequeue_style( 'nettertech-events-base' );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['post'] = $this->saved_post;

		// Clear dequeue to avoid cross-test state.
		wp_dequeue_style( 'nettertech-events-base' );
		wp_dequeue_style( 'nettertech-events-carousel' );
		wp_dequeue_style( 'nettertech-events-grid' );
		wp_dequeue_style( 'nettertech-events-calendar' );

		parent::tearDown();
	}

	/**
	 * Create 3 events (1 single + 1 weekly + 1 monthly) with distinctive
	 * titles and return the title prefix so tests can search for it.
	 *
	 * @param string $prefix Title prefix unique per test method.
	 * @return string The prefix (for assertion use).
	 */
	private function create_fixtures( string $prefix ): string {
		FixtureFactory::create_event( array( 'title' => $prefix . ' Single' ) );
		FixtureFactory::create_recurring_event( 'WEEKLY', 4, array( 'title' => $prefix . ' Weekly' ) );
		FixtureFactory::create_recurring_event( 'MONTHLY', 3, array( 'title' => $prefix . ' Monthly' ) );
		return $prefix;
	}

	/**
	 * Insert a WP page with a shortcode tag and set $GLOBALS['post'].
	 *
	 * @param string $shortcode_tag Raw shortcode string, e.g. '[nettertech_events_list]'.
	 * @return \WP_Post
	 */
	private function make_page_with_shortcode( string $shortcode_tag ): \WP_Post {
		$page_id = wp_insert_post( array(
			'post_title'   => 'Test page for ' . $shortcode_tag,
			'post_content' => $shortcode_tag,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );

		$this->assertIsInt( $page_id, 'wp_insert_post must return an int.' );
		$this->assertGreaterThan( 0, $page_id, 'wp_insert_post must succeed.' );

		$post = get_post( $page_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		return $post;
	}

	/**
	 * Dequeue base, run Assets::maybe_enqueue_frontend() with $GLOBALS['post']
	 * set, then check whether base was enqueued.
	 *
	 * PageContextDetector reads $GLOBALS['post']->post_content to detect
	 * shortcode presence. This is the NTE-017 bug 2 regression gate: if a
	 * shortcode is absent from PageContextDetector's hardcoded map, maybe_enqueue
	 * will not enqueue base.css and this method returns false.
	 *
	 * @return bool
	 */
	private function base_css_was_enqueued(): bool {
		// Reset enqueued state before triggering.
		wp_dequeue_style( 'nettertech-events-base' );

		// Fresh Assets instance to avoid caching inside PageContextDetector.
		$assets = new Assets( new PaletteResolver(), new NetterTechEventsSettings() );
		$assets->register();
		$assets->maybe_enqueue_frontend();

		return wp_style_is( 'nettertech-events-base', 'enqueued' );
	}

	// -------------------------------------------------------------------------
	// [nettertech_events_list]
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_nte_list_renders_and_enqueues_base_css(): void {
		$prefix = $this->create_fixtures( 'NteListFixture' );
		$this->make_page_with_shortcode( '[nettertech_events_list]' );

		$output = do_shortcode( '[nettertech_events_list]' );

		$this->assertNotEmpty( $output, '[nettertech_events_list] must produce non-empty output.' );
		$this->assertStringNotContainsStringIgnoringCase(
			'no events',
			$output,
			'[nettertech_events_list] should not show empty-state text when fixtures are present.'
		);
		$this->assertStringContainsString(
			$prefix,
			$output,
			'[nettertech_events_list] output must contain at least one fixture event title prefix.'
		);

		// NTE-017 bug 2 class gate.
		$this->assertTrue(
			$this->base_css_was_enqueued(),
			'nettertech-events-base must be enqueued when [nettertech_events_list] is in post_content. ' .
			'Failure means nettertech_events_list is missing from PageContextDetector\'s shortcode map.'
		);
	}

	// -------------------------------------------------------------------------
	// [nettertech_events_carousel]
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_nte_carousel_renders_and_enqueues_base_css(): void {
		$prefix = $this->create_fixtures( 'NteCarouselFixture' );
		$this->make_page_with_shortcode( '[nettertech_events_carousel]' );

		$output = do_shortcode( '[nettertech_events_carousel]' );

		$this->assertNotEmpty( $output, '[nettertech_events_carousel] must produce non-empty output.' );
		$this->assertStringNotContainsStringIgnoringCase(
			'no events',
			$output,
			'[nettertech_events_carousel] should not show empty-state text when fixtures are present.'
		);
		$this->assertStringContainsString(
			$prefix,
			$output,
			'[nettertech_events_carousel] output must contain at least one fixture event title prefix.'
		);

		// NTE-017 bug 2 class gate.
		$this->assertTrue(
			$this->base_css_was_enqueued(),
			'nettertech-events-base must be enqueued when [nettertech_events_carousel] is in post_content. ' .
			'Failure means nettertech_events_carousel is missing from PageContextDetector\'s shortcode map.'
		);
	}

	// -------------------------------------------------------------------------
	// [nettertech_events_calendar]
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_nte_calendar_renders_and_enqueues_base_css(): void {
		$this->create_fixtures( 'NteCalendarFixture' );
		$this->make_page_with_shortcode( '[nettertech_events_calendar]' );

		$output = do_shortcode( '[nettertech_events_calendar]' );

		// Calendar loads events via AJAX; its initial HTML is the calendar shell
		// (nav, grid structure) — not event titles. Just verify it is non-empty.
		$this->assertNotEmpty( $output, '[nettertech_events_calendar] must produce non-empty output.' );
		$this->assertStringContainsString(
			'nte-calendar',
			$output,
			'[nettertech_events_calendar] output must contain the nte-calendar CSS class.'
		);

		// NTE-017 bug 2 class gate.
		$this->assertTrue(
			$this->base_css_was_enqueued(),
			'nettertech-events-base must be enqueued when [nettertech_events_calendar] is in post_content. ' .
			'Failure means nettertech_events_calendar is missing from PageContextDetector\'s shortcode map.'
		);
	}

	// -------------------------------------------------------------------------
	// [nettertech_events_regulars]
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_nte_regulars_renders_and_enqueues_base_css(): void {
		// Regulars only shows weekly recurring events.
		FixtureFactory::create_recurring_event( 'WEEKLY', 4, array( 'title' => 'NteRegularsFixture Weekly' ) );
		FixtureFactory::create_recurring_event( 'WEEKLY', 6, array( 'title' => 'NteRegularsFixture Weekly2' ) );
		FixtureFactory::create_recurring_event( 'MONTHLY', 3, array( 'title' => 'NteRegularsFixture Monthly' ) );

		$this->make_page_with_shortcode( '[nettertech_events_regulars]' );

		$output = do_shortcode( '[nettertech_events_regulars]' );

		$this->assertNotEmpty( $output, '[nettertech_events_regulars] must produce non-empty output.' );
		$this->assertStringContainsString(
			'NteRegularsFixture Weekly',
			$output,
			'[nettertech_events_regulars] output must contain weekly fixture event titles.'
		);

		// NTE-017 bug 2 class gate.
		$this->assertTrue(
			$this->base_css_was_enqueued(),
			'nettertech-events-base must be enqueued when [nettertech_events_regulars] is in post_content. ' .
			'Failure means nettertech_events_regulars is missing from PageContextDetector\'s shortcode map.'
		);
	}

	// -------------------------------------------------------------------------
	// [nettertech_events_rsvp]
	// -------------------------------------------------------------------------

	/**
	 * nettertech_events_rsvp render + enqueue gate.
	 *
	 * KNOWN ISSUE (NTE-017 bug 2 class): nettertech_events_rsvp is absent from
	 * PageContextDetector's shortcode map (PageContextDetector.php lines 86-99).
	 * RSVPFormShortcode calls wp_add_inline_style('nettertech-events-base', ...)
	 * which requires the handle to already be enqueued — but maybe_enqueue_frontend()
	 * never fires on rsvp-only pages because the detector doesn't recognize it.
	 * The base.css enqueue assertion below FAILS on current main, confirming
	 * this as a latent bug of the same class as NTE-017 bug 2.
	 *
	 * @return void
	 */
	public function test_nte_rsvp_renders_and_enqueues_base_css(): void {
		global $wpdb;

		// Single events have no occurrences; use a weekly recurring event which
		// generates occurrence rows via RecurrenceService during create_recurring_event.
		$event_id = FixtureFactory::create_recurring_event( 'WEEKLY', 4, array( 'title' => 'NteRsvpFixture Weekly' ) );

		// Fetch the first generated occurrence.
		$filter_repo     = new OccurrenceFilterRepository( $wpdb );
		$query_repo      = new OccurrenceQueryRepository( $wpdb, $filter_repo );
		$occurrence_repo = new OccurrenceRepository( $wpdb, $query_repo );
		$occurrences     = $occurrence_repo->for_event( $event_id );

		if ( empty( $occurrences ) ) {
			$this->markTestSkipped( 'No occurrences found for RSVP fixture event — cannot test nettertech_events_rsvp render.' );
			return;
		}

		$occurrence_id = (int) $occurrences[0]->id;
		$shortcode_tag = '[nettertech_events_rsvp occurrence_id="' . $occurrence_id . '"]';

		$this->make_page_with_shortcode( $shortcode_tag );

		$output = do_shortcode( $shortcode_tag );

		$this->assertNotEmpty( $output, '[nettertech_events_rsvp] must produce non-empty output.' );

		// NTE-017 bug 2 class gate — EXPECTED TO FAIL on current main.
		// nettertech_events_rsvp is missing from PageContextDetector's shortcode map, so
		// maybe_enqueue_frontend() never enqueues base.css on rsvp-only pages.
		// If this assertion passes, nettertech_events_rsvp was added to the detector map
		// or independently enqueues base (neither is currently true).
		$this->assertTrue(
			$this->base_css_was_enqueued(),
			'nettertech-events-base must be enqueued when [nettertech_events_rsvp] is in post_content. ' .
			'LATENT BUG (NTE-017 bug 2 class): nettertech_events_rsvp is absent from PageContextDetector\'s ' .
			'shortcode map, so base.css is never enqueued on rsvp-only pages. ' .
			'Fix: add nettertech_events_rsvp => \'single\' (or appropriate view) to the $shortcodes array ' .
			'in PageContextDetector::page_has_nettertech_events_content().'
		);
	}
}
