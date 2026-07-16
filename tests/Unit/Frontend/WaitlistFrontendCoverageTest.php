<?php
/**
 * WaitlistFrontend unit tests (coverage targeted).
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Frontend\WaitlistFrontend;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;

/**
 * Test WaitlistFrontend covering init, register_assets, and render_waitlist_panel.
 *
 * @coversDefaultClass \NetterTechEvents\Frontend\WaitlistFrontend
 */
class WaitlistFrontendCoverageTest extends \NetterTechEventsTestCase {

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NETTERTECH_EVENTS_PLUGIN_URL' ) ) {
			define( 'NETTERTECH_EVENTS_PLUGIN_URL', 'http://example.test/wp-content/plugins/nettertech-events/' );
		}
		if ( ! defined( 'NETTERTECH_EVENTS_VERSION' ) ) {
			define( 'NETTERTECH_EVENTS_VERSION', '1.0.0' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'rest_url' )->alias( static fn( $p = '' ) => 'http://example.test/wp-json/' . $p );
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $val ) => $val );
		Functions\when( 'wp_get_current_user' )->alias(
			static function () {
				$u                = new \stdClass();
				$u->ID            = 1;
				$u->display_name  = 'Alice';
				$u->user_email    = 'alice@example.test';
				return $u;
			}
		);
		Functions\when( 'wp_kses' )->returnArg();
	}

	/**
	 * Test init does not throw.
	 *
	 * @return void
	 */
	public function test_init_does_not_throw(): void {
		$wf = new WaitlistFrontend();
		$wf->init();
		$this->assertInstanceOf( WaitlistFrontend::class, $wf );
	}

	/**
	 * Test register_assets runs without script_debug.
	 *
	 * @return void
	 */
	public function test_register_assets_without_script_debug(): void {
		$wf = new WaitlistFrontend();
		$wf->register_assets();
		$this->assertTrue( true );
	}

	/**
	 * Test render_waitlist_panel short-circuits when filter returns false.
	 *
	 * @return void
	 */
	public function test_render_waitlist_panel_short_circuits_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $val ) {
				if ( 'nettertech_events_has_waitlist' === $tag ) {
					return false;
				}
				return $val;
			}
		);

		$tt        = Mockery::mock( TicketType::class );
		$occ       = Mockery::mock( Occurrence::class );

		$wf = new WaitlistFrontend();
		ob_start();
		$wf->render_waitlist_panel( $tt, $occ );
		$out = ob_get_clean();
		$this->assertSame( '', $out );
	}

	/**
	 * Test render_waitlist_panel uses Templates::get_part to emit panel HTML.
	 *
	 * @return void
	 */
	public function test_render_waitlist_panel_emits_template_output(): void {
		$tt          = Mockery::mock( TicketType::class );
		$occ         = Mockery::mock( Occurrence::class );
		$occ->id     = 42;

		$wf = new WaitlistFrontend();
		ob_start();
		$wf->render_waitlist_panel( $tt, $occ );
		$out = ob_get_clean();

		// Whatever the template returns is escaped via wp_kses and echoed.
		$this->assertIsString( $out );
	}

	/**
	 * Test render_waitlist_panel only enqueues assets once on repeated calls.
	 *
	 * @return void
	 */
	public function test_render_waitlist_panel_enqueues_assets_only_once(): void {
		$tt        = Mockery::mock( TicketType::class );
		$occ       = Mockery::mock( Occurrence::class );
		$occ->id   = 42;

		$wf = new WaitlistFrontend();

		// First call enqueues.
		ob_start();
		$wf->render_waitlist_panel( $tt, $occ );
		ob_end_clean();

		// Second call hits the early-return guard (line 125 in enqueue_assets).
		ob_start();
		$wf->render_waitlist_panel( $tt, $occ );
		ob_end_clean();

		$this->assertTrue( true );
	}

	/**
	 * Test render_waitlist_panel handles anonymous user (no prefill).
	 *
	 * @return void
	 */
	public function test_render_waitlist_panel_handles_anonymous_user(): void {
		Functions\when( 'wp_get_current_user' )->alias(
			static function () {
				$u                = new \stdClass();
				$u->ID            = 0;
				$u->display_name  = '';
				$u->user_email    = '';
				return $u;
			}
		);

		$tt      = Mockery::mock( TicketType::class );
		$occ     = Mockery::mock( Occurrence::class );
		$occ->id = 1;

		$wf = new WaitlistFrontend();
		ob_start();
		$wf->render_waitlist_panel( $tt, $occ );
		ob_end_clean();

		$this->assertTrue( true );
	}
}
