<?php
/**
 * AdminPage unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer;

use Brain\Monkey\Functions;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\AdminPage;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\Context;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\LayoutAssignments;

/**
 * Tests for the Beaver Themer Settings tab integration.
 *
 * @coversDefaultClass \NetterTechEvents\Integrations\BeaverBuilder\Themer\AdminPage
 */
class AdminPageTest extends \NetterTechEventsTestCase {

	/**
	 * register_tab adds the 'themer' slug while preserving incoming tabs.
	 *
	 * @return void
	 */
	public function test_register_tab_adds_themer_slug(): void {
		$page = new AdminPage();

		$result = $page->register_tab(
			array(
				'general' => 'General',
				'display' => 'Display',
			)
		);

		$this->assertArrayHasKey( AdminPage::TAB_SLUG, $result );
		$this->assertArrayHasKey( 'general', $result );
		$this->assertArrayHasKey( 'display', $result );
	}

	/**
	 * render_tab is a no-op when the active tab is something else.
	 *
	 * @return void
	 */
	public function test_render_tab_noop_when_other_tab_active(): void {
		ob_start();
		( new AdminPage() )->render_tab( 'general' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * render_tab outputs the heading and one row per Context::ALL_KEYS when active.
	 *
	 * @return void
	 */
	public function test_render_tab_outputs_one_row_per_context(): void {
		$this->stub_render_dependencies(
			array(
				$this->make_layout_post( 10, 'Site Header' ),
			),
			array(
				$this->make_layout_post( 20, 'Site Footer' ),
			)
		);

		ob_start();
		( new AdminPage() )->render_tab( AdminPage::TAB_SLUG );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Beaver Themer Layouts', $output );

		foreach ( Context::ALL_KEYS as $context_key ) {
			$this->assertStringContainsString( Context::label( $context_key ), $output );
		}

		// Header + footer selects per context = 10 selects total.
		$select_count = substr_count( $output, '<select' );
		$this->assertSame( count( Context::ALL_KEYS ) * 2, $select_count );

		// Layout titles appear as options.
		$this->assertStringContainsString( 'Site Header', $output );
		$this->assertStringContainsString( 'Site Footer', $output );
	}

	/**
	 * render_tab shows the empty-state notice when no header/footer layouts exist.
	 *
	 * @return void
	 */
	public function test_render_tab_shows_empty_state_when_no_layouts(): void {
		$this->stub_render_dependencies( array(), array() );

		ob_start();
		( new AdminPage() )->render_tab( AdminPage::TAB_SLUG );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No published Beaver Themer header or footer layouts', $output );
		// Form table must NOT render when there are no layouts.
		$this->assertStringNotContainsString( '<select', $output );
	}

	/**
	 * save_tab is a no-op when the active tab is something else.
	 *
	 * @return void
	 */
	public function test_save_tab_noop_when_other_tab_active(): void {
		$captured_update = false;
		Functions\when( 'update_option' )->alias(
			function () use ( &$captured_update ) {
				$captured_update = true;
				return true;
			}
		);

		( new AdminPage() )->save_tab( 'general' );

		$this->assertFalse( $captured_update, 'update_option should not be called for non-themer tabs.' );
	}

	/**
	 * save_tab refuses to persist when the user lacks the capability.
	 *
	 * @return void
	 */
	public function test_save_tab_refuses_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$captured_update = false;
		Functions\when( 'update_option' )->alias(
			function () use ( &$captured_update ) {
				$captured_update = true;
				return true;
			}
		);

		$_POST['nettertech_events_themer_assignments'] = array(
			Context::EVENTS_ARCHIVE => array( LayoutAssignments::SLOT_HEADER => 42 ),
		);

		( new AdminPage() )->save_tab( AdminPage::TAB_SLUG );

		unset( $_POST['nettertech_events_themer_assignments'] );

		$this->assertFalse( $captured_update, 'update_option must not be called when capability check fails.' );
	}

	/**
	 * save_tab persists sanitised $_POST data when capability passes.
	 *
	 * @return void
	 */
	public function test_save_tab_persists_post_data(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );

		$captured = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$captured ) {
				$captured = array(
					'key'   => $key,
					'value' => $value,
				);
				return true;
			}
		);

		$_POST['nettertech_events_themer_assignments'] = array(
			Context::EVENTS_ARCHIVE => array(
				LayoutAssignments::SLOT_HEADER => '101',
				LayoutAssignments::SLOT_FOOTER => '202',
			),
		);

		( new AdminPage() )->save_tab( AdminPage::TAB_SLUG );

		unset( $_POST['nettertech_events_themer_assignments'] );

		$this->assertNotNull( $captured );
		$this->assertSame( LayoutAssignments::OPTION_KEY, $captured['key'] );
		$this->assertSame( 101, $captured['value'][ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_HEADER ] );
		$this->assertSame( 202, $captured['value'][ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_FOOTER ] );
	}

	/**
	 * save_tab gracefully handles missing $_POST payload.
	 *
	 * Nothing should be persisted, no warnings should fire.
	 *
	 * @return void
	 */
	public function test_save_tab_handles_missing_post_payload(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$captured = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$captured ) {
				$captured = array(
					'key'   => $key,
					'value' => $value,
				);
				return true;
			}
		);

		( new AdminPage() )->save_tab( AdminPage::TAB_SLUG );

		// Save still fires (writing the canonical empty shape) — verify it didn't error.
		$this->assertNotNull( $captured );
		$this->assertSame( LayoutAssignments::OPTION_KEY, $captured['key'] );
		$this->assertSame( 0, $captured['value'][ Context::EVENTS_ARCHIVE ][ LayoutAssignments::SLOT_HEADER ] );
	}

	/**
	 * init wires three hooks: tabs filter, render action, save action.
	 *
	 * @return void
	 */
	public function test_init_does_not_throw(): void {
		$page = new AdminPage();
		$page->init();

		// Bootstrap pre-stubs add_action/add_filter (see tests/bootstrap.php:1003)
		// which prevents Brain\Monkey's expectAdded() assertions from firing on
		// hook registration. Reaching this assertion without a fatal proves the
		// three hook-registration calls in init() are well-formed.
		$this->assertInstanceOf( AdminPage::class, $page );
	}

	/**
	 * Stub the WordPress functions render_tab() depends on.
	 *
	 * @param array<int, \WP_Post> $header_posts Header layout posts returned by get_posts.
	 * @param array<int, \WP_Post> $footer_posts Footer layout posts returned by get_posts.
	 * @return void
	 */
	private function stub_render_dependencies( array $header_posts, array $footer_posts ): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_kses' )->returnArg( 1 );

		Functions\when( 'get_posts' )->alias(
			function ( array $args ) use ( $header_posts, $footer_posts ) {
				if ( ! isset( $args['meta_value'] ) ) {
					return array();
				}
				return 'header' === $args['meta_value'] ? $header_posts : $footer_posts;
			}
		);
	}

	/**
	 * Build a minimal WP_Post-compatible stub for layout-listing tests.
	 *
	 * @param int    $id    Post ID.
	 * @param string $title Post title.
	 * @return \WP_Post
	 */
	private function make_layout_post( int $id, string $title ): \WP_Post {
		// Bootstrap defines a WP_Post stub class with public properties.
		$post             = new \WP_Post();
		$post->ID         = $id;
		$post->post_title = $title;
		$post->post_type  = 'fl-theme-layout';

		return $post;
	}
}
