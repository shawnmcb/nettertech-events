<?php
/**
 * LayoutInjector unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer;

use Brain\Monkey\Functions;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\Context;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\LayoutAssignments;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\LayoutInjector;

/**
 * Tests for the Themer current-page-layouts filter hook.
 *
 * @coversDefaultClass \NetterTechEvents\Integrations\BeaverBuilder\Themer\LayoutInjector
 */
class LayoutInjectorTest extends \NetterTechEventsTestCase {

	/**
	 * No NTE context active => returns input layouts unchanged.
	 *
	 * @return void
	 */
	public function test_returns_input_when_no_nte_context(): void {
		$this->stub_query_vars( array() );

		$input = array(
			'header' => array( array( 'id' => 1, 'type' => 'header' ) ),
		);

		$result = ( new LayoutInjector() )->inject_layouts( $input );

		$this->assertSame( $input, $result );
	}

	/**
	 * Non-array input is coerced to an empty array (defensive).
	 *
	 * Upstream filters can pass anything; we must not warn or fatal.
	 *
	 * @return void
	 */
	public function test_coerces_non_array_input(): void {
		$this->stub_query_vars( array() );

		$result = ( new LayoutInjector() )->inject_layouts( null );

		$this->assertSame( array(), $result );
	}

	/**
	 * NTE context active + assignment present => slot is replaced with our entry.
	 *
	 * @return void
	 */
	public function test_injects_layout_for_matching_context_and_slot(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );
		$this->stub_assignments(
			array(
				Context::EVENTS_ARCHIVE => array(
					LayoutAssignments::SLOT_HEADER => 42,
				),
			)
		);
		$this->stub_post( 42, 'header' );
		Functions\when( 'get_post_meta' )->justReturn( 'header' );

		$input = array(
			'header' => array( array( 'id' => 99, 'type' => 'header' ) ),
		);

		$result = ( new LayoutInjector() )->inject_layouts( $input );

		$this->assertCount( 1, $result['header'] );
		$this->assertSame( 42, $result['header'][0]['id'] );
		$this->assertSame( 'header', $result['header'][0]['type'] );
		$this->assertSame( 'nettertech_events:' . Context::EVENTS_ARCHIVE, $result['header'][0]['locations'][0] );
		$this->assertSame( array(), $result['header'][0]['users'] );
	}

	/**
	 * Assignment ID of 0 (== unassigned) leaves the slot untouched.
	 *
	 * @return void
	 */
	public function test_zero_id_leaves_slot_untouched(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );
		$this->stub_assignments(
			array(
				Context::EVENTS_ARCHIVE => array(
					LayoutAssignments::SLOT_HEADER => 0,
				),
			)
		);

		$input = array(
			'header' => array( array( 'id' => 99, 'type' => 'header' ) ),
		);

		$result = ( new LayoutInjector() )->inject_layouts( $input );

		$this->assertSame( $input, $result );
	}

	/**
	 * Stale assignment (post deleted) => slot left untouched, no fatal.
	 *
	 * @return void
	 */
	public function test_missing_post_is_silently_skipped(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );
		$this->stub_assignments(
			array(
				Context::EVENTS_ARCHIVE => array(
					LayoutAssignments::SLOT_HEADER => 42,
				),
			)
		);
		Functions\when( 'get_post' )->justReturn( null );

		$input = array( 'header' => array() );

		$result = ( new LayoutInjector() )->inject_layouts( $input );

		$this->assertSame( array(), $result['header'] );
	}

	/**
	 * Stale assignment (post unpublished) => slot left untouched.
	 *
	 * @return void
	 */
	public function test_unpublished_post_is_skipped(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );
		$this->stub_assignments(
			array(
				Context::EVENTS_ARCHIVE => array(
					LayoutAssignments::SLOT_HEADER => 42,
				),
			)
		);
		$this->stub_post( 42, 'header', 'draft' );
		Functions\when( 'get_post_meta' )->justReturn( 'header' );

		$input = array( 'header' => array() );

		$result = ( new LayoutInjector() )->inject_layouts( $input );

		$this->assertSame( array(), $result['header'] );
	}

	/**
	 * Stale assignment (layout type changed) => slot left untouched.
	 *
	 * An admin assigned a header-type layout, then later switched its
	 * `_fl_theme_layout_type` meta to 'footer' inside Themer. The
	 * assignment is now invalid; we silently skip rather than render a
	 * footer where a header was expected.
	 *
	 * @return void
	 */
	public function test_layout_type_mismatch_is_skipped(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );
		$this->stub_assignments(
			array(
				Context::EVENTS_ARCHIVE => array(
					LayoutAssignments::SLOT_HEADER => 42,
				),
			)
		);
		$this->stub_post( 42, 'header' );
		// Meta now says 'footer' — mismatch with the SLOT_HEADER assignment.
		Functions\when( 'get_post_meta' )->justReturn( 'footer' );

		$input = array( 'header' => array() );

		$result = ( new LayoutInjector() )->inject_layouts( $input );

		$this->assertSame( array(), $result['header'] );
	}

	/**
	 * Wrong post_type (someone fed in a regular page ID) => skipped.
	 *
	 * @return void
	 */
	public function test_wrong_post_type_is_skipped(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );
		$this->stub_assignments(
			array(
				Context::EVENTS_ARCHIVE => array(
					LayoutAssignments::SLOT_HEADER => 42,
				),
			)
		);
		$this->stub_post( 42, 'header', 'publish', 'page' );

		$input = array( 'header' => array() );

		$result = ( new LayoutInjector() )->inject_layouts( $input );

		$this->assertSame( array(), $result['header'] );
	}

	/**
	 * Header and footer can be injected together on the same request.
	 *
	 * @return void
	 */
	public function test_injects_both_header_and_footer_when_assigned(): void {
		$this->stub_query_vars( array( 'nettertech_events_event_slug' => 'summer-concert' ) );
		$this->stub_assignments(
			array(
				Context::SINGLE_EVENT => array(
					LayoutAssignments::SLOT_HEADER => 100,
					LayoutAssignments::SLOT_FOOTER => 200,
				),
			)
		);

		Functions\when( 'get_post' )->alias(
			function ( int $id ) {
				$post              = new \WP_Post();
				$post->ID          = $id;
				$post->post_type   = 'fl-theme-layout';
				$post->post_status = 'publish';
				return $post;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( int $id ) {
				// 100 = header, 200 = footer.
				return 100 === $id ? 'header' : 'footer';
			}
		);

		$result = ( new LayoutInjector() )->inject_layouts( array() );

		$this->assertSame( 100, $result['header'][0]['id'] );
		$this->assertSame( 'header', $result['header'][0]['type'] );
		$this->assertSame( 200, $result['footer'][0]['id'] );
		$this->assertSame( 'footer', $result['footer'][0]['type'] );
	}

	/**
	 * Replacement strategy: NTE layout overrides any prior slot entry.
	 *
	 * If Themer's own location matcher had populated $layouts['header']
	 * (e.g. via an "Entire site" rule), our NTE-specific assignment must
	 * win.
	 *
	 * @return void
	 */
	public function test_replaces_existing_slot_entries(): void {
		$this->stub_query_vars( array( 'nettertech_events_space_slug' => 'main-hall' ) );
		$this->stub_assignments(
			array(
				Context::SINGLE_SPACE => array(
					LayoutAssignments::SLOT_HEADER => 7,
				),
			)
		);
		$this->stub_post( 7, 'header' );
		Functions\when( 'get_post_meta' )->justReturn( 'header' );

		$input = array(
			'header' => array(
				array( 'id' => 1, 'type' => 'header' ),
				array( 'id' => 2, 'type' => 'header' ),
			),
		);

		$result = ( new LayoutInjector() )->inject_layouts( $input );

		$this->assertCount( 1, $result['header'] );
		$this->assertSame( 7, $result['header'][0]['id'] );
	}

	/**
	 * Stub get_query_var with a fixed override map.
	 *
	 * @param array<string, string> $overrides Query var => value map.
	 * @return void
	 */
	private function stub_query_vars( array $overrides ): void {
		Functions\when( 'get_query_var' )->alias(
			function ( $name, $default = '' ) use ( $overrides ) {
				return $overrides[ $name ] ?? $default;
			}
		);
	}

	/**
	 * Stub get_option to return a sparse assignment map (canonicalised on read).
	 *
	 * @param array<string, array<string, int>> $sparse Sparse context => slots map.
	 * @return void
	 */
	private function stub_assignments( array $sparse ): void {
		Functions\when( 'get_option' )->justReturn( $sparse );
	}

	/**
	 * Stub get_post to return a WP_Post-compatible layout post.
	 *
	 * @param int    $id     Post ID.
	 * @param string $type   `_fl_theme_layout_type` meta value (also used as title hint).
	 * @param string $status post_status.
	 * @param string $ptype  post_type.
	 * @return void
	 */
	private function stub_post( int $id, string $type, string $status = 'publish', string $ptype = 'fl-theme-layout' ): void {
		Functions\when( 'get_post' )->alias(
			function () use ( $id, $type, $status, $ptype ) {
				$post              = new \WP_Post();
				$post->ID          = $id;
				$post->post_type   = $ptype;
				$post->post_status = $status;
				$post->post_title  = "Test {$type} layout";
				return $post;
			}
		);
	}
}
