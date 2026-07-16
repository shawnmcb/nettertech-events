<?php
/**
 * Tests for OpenGraphTags.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Frontend\OpenGraphTags;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use Brain\Monkey\Functions;

/**
 * @coversDefaultClass \NetterTechEvents\Frontend\OpenGraphTags
 */
class OpenGraphTagsTest extends \NetterTechEventsTestCase {

	/**
	 * Create a mock Event with default properties.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Event
	 */
	private function make_event( array $overrides = array() ): Event {
		$event                    = new Event();
		$event->id                = $overrides['id'] ?? 1;
		$event->title             = $overrides['title'] ?? 'Test Concert';
		$event->slug              = $overrides['slug'] ?? 'test-concert';
		$event->description       = $overrides['description'] ?? 'A great show with live music.';
		$event->excerpt           = $overrides['excerpt'] ?? '';
		$event->status            = isset( $overrides['status'] )
			? ( $overrides['status'] instanceof EventStatus ? $overrides['status'] : EventStatus::tryFrom( $overrides['status'] ) ?? EventStatus::DRAFT )
			: EventStatus::PUBLISHED;
		$event->event_type        = $overrides['event_type'] ?? 'single';
		$event->venue_name        = array_key_exists( 'venue_name', $overrides ) ? $overrides['venue_name'] : 'The Venue';
		$event->venue_address     = array_key_exists( 'venue_address', $overrides ) ? $overrides['venue_address'] : '123 Main St';
		$event->featured_image_id = $overrides['featured_image_id'] ?? 0;

		return $event;
	}

	/**
	 * Create a mock Occurrence with default properties.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Occurrence
	 */
	private function make_occurrence( array $overrides = array() ): Occurrence {
		$occ                       = new Occurrence();
		$occ->id                   = $overrides['id'] ?? 10;
		$occ->event_id             = $overrides['event_id'] ?? 1;
		$occ->start_datetime       = $overrides['start_datetime'] ?? '2026-03-15 19:30:00';
		$occ->end_datetime         = $overrides['end_datetime'] ?? '2026-03-15 22:00:00';
		$occ->timezone             = $overrides['timezone'] ?? 'America/Chicago';
		$occ->status               = $overrides['status'] ?? 'scheduled';
		$occ->is_rescheduled       = (bool) ( $overrides['is_rescheduled'] ?? false );
		$occ->title_override       = $overrides['title_override'] ?? null;
		$occ->description_override = $overrides['description_override'] ?? null;
		$occ->featured_image_id    = $overrides['featured_image_id'] ?? 0;

		return $occ;
	}

	/**
	 * Set up common WP function stubs.
	 *
	 * @return void
	 */
	private function stub_wp_functions(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'The Bellwright' );
	}

	// =========================================================================
	// build_tags() — Basic Structure
	// =========================================================================

	/**
	 * @covers ::build_tags
	 */
	public function test_build_tags_returns_all_required_og_keys(): void {
		$this->stub_wp_functions();

		$event = $this->make_event();
		$tags  = OpenGraphTags::build_tags( $event );

		$this->assertArrayHasKey( 'og:type', $tags );
		$this->assertArrayHasKey( 'og:title', $tags );
		$this->assertArrayHasKey( 'og:description', $tags );
		$this->assertArrayHasKey( 'og:url', $tags );
		$this->assertArrayHasKey( 'og:site_name', $tags );
		$this->assertArrayHasKey( 'twitter:card', $tags );
		$this->assertArrayHasKey( 'twitter:title', $tags );
		$this->assertArrayHasKey( 'twitter:description', $tags );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_og_type_is_event(): void {
		$this->stub_wp_functions();

		$event = $this->make_event();
		$tags  = OpenGraphTags::build_tags( $event );

		$this->assertEquals( 'event', $tags['og:type'] );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_og_site_name_uses_bloginfo(): void {
		$this->stub_wp_functions();

		$event = $this->make_event();
		$tags  = OpenGraphTags::build_tags( $event );

		$this->assertEquals( 'The Bellwright', $tags['og:site_name'] );
	}

	// =========================================================================
	// build_tags() — Title
	// =========================================================================

	/**
	 * @covers ::build_tags
	 */
	public function test_title_uses_event_title(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array( 'title' => 'Jazz Night' ) );
		$tags  = OpenGraphTags::build_tags( $event );

		$this->assertEquals( 'Jazz Night', $tags['og:title'] );
		$this->assertEquals( 'Jazz Night', $tags['twitter:title'] );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_title_uses_occurrence_override_when_available(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array( 'title' => 'Jazz Night' ) );
		$occ   = $this->make_occurrence( array( 'title_override' => 'Special Jazz Night' ) );
		$tags  = OpenGraphTags::build_tags( $event, $occ );

		$this->assertEquals( 'Special Jazz Night', $tags['og:title'] );
		$this->assertEquals( 'Special Jazz Night', $tags['twitter:title'] );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_title_falls_back_to_event_when_occurrence_has_no_override(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array( 'title' => 'Jazz Night' ) );
		$occ   = $this->make_occurrence( array( 'title_override' => null ) );
		$tags  = OpenGraphTags::build_tags( $event, $occ );

		$this->assertEquals( 'Jazz Night', $tags['og:title'] );
	}

	// =========================================================================
	// build_tags() — Description
	// =========================================================================

	/**
	 * @covers ::build_tags
	 */
	public function test_description_uses_excerpt_when_available(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array(
			'excerpt'     => 'Short summary',
			'description' => 'Long description',
		) );
		$tags = OpenGraphTags::build_tags( $event );

		$this->assertEquals( 'Short summary', $tags['og:description'] );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_description_falls_back_to_description(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array(
			'excerpt'     => '',
			'description' => 'Full description here',
		) );
		$tags = OpenGraphTags::build_tags( $event );

		$this->assertEquals( 'Full description here', $tags['og:description'] );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_description_truncated_at_200_chars(): void {
		$this->stub_wp_functions();

		$long_desc = str_repeat( 'A', 250 );
		$event     = $this->make_event( array(
			'excerpt'     => '',
			'description' => $long_desc,
		) );
		$tags = OpenGraphTags::build_tags( $event );

		$this->assertEquals( 200, mb_strlen( $tags['og:description'] ) );
		$this->assertStringEndsWith( '...', $tags['og:description'] );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_description_empty_when_both_empty(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array( 'excerpt' => '', 'description' => '' ) );
		$tags  = OpenGraphTags::build_tags( $event );

		$this->assertEquals( '', $tags['og:description'] );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_twitter_description_mirrors_og_description(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array( 'excerpt' => 'Some excerpt' ) );
		$tags  = OpenGraphTags::build_tags( $event );

		$this->assertEquals( $tags['og:description'], $tags['twitter:description'] );
	}

	// =========================================================================
	// build_tags() — Image
	// =========================================================================

	/**
	 * @covers ::build_tags
	 */
	public function test_image_included_when_event_has_featured_image(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array( 'featured_image_id' => 42 ) );

		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/image.jpg' );

		$tags = OpenGraphTags::build_tags( $event );

		$this->assertEquals( 'https://example.com/image.jpg', $tags['og:image'] );
		$this->assertEquals( 'https://example.com/image.jpg', $tags['twitter:image'] );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_no_image_keys_when_no_featured_image(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array( 'featured_image_id' => 0 ) );

		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );

		$tags = OpenGraphTags::build_tags( $event );

		$this->assertArrayNotHasKey( 'og:image', $tags );
		$this->assertArrayNotHasKey( 'twitter:image', $tags );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_twitter_card_summary_large_image_with_image(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array( 'featured_image_id' => 42 ) );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/image.jpg' );

		$tags = OpenGraphTags::build_tags( $event );

		$this->assertEquals( 'summary_large_image', $tags['twitter:card'] );
	}

	/**
	 * @covers ::build_tags
	 */
	public function test_twitter_card_summary_without_image(): void {
		$this->stub_wp_functions();

		$event = $this->make_event( array( 'featured_image_id' => 0 ) );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );

		$tags = OpenGraphTags::build_tags( $event );

		$this->assertEquals( 'summary', $tags['twitter:card'] );
	}

	// =========================================================================
	// build_tags() — URL
	// =========================================================================

	/**
	 * @covers ::build_tags
	 */
	public function test_url_uses_event_permalink(): void {
		$this->stub_wp_functions();

		$event = $this->make_event();
		$tags  = OpenGraphTags::build_tags( $event );

		// Event::get_permalink() returns a string — verify it's set.
		$this->assertNotEmpty( $tags['og:url'] );
	}

	// =========================================================================
	// seo_plugin_handles_og() — SEO Plugin Detection
	// =========================================================================

	/**
	 * @covers ::init
	 */
	public function test_init_skips_when_yoast_active(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '22.0' );
		}

		Functions\when( 'add_action' )->alias( function () {} );

		// Capture actions added.
		$actions_added = array();
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$actions_added ) {
			$actions_added[] = $hook;
		} );

		OpenGraphTags::init();

		// No wp_head action should be added.
		$this->assertNotContains( 'wp_head', $actions_added );
	}
}
