<?php
/**
 * WooCommerceMissingNotice unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Notices
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Notices;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Notices\WooCommerceMissingNotice;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\TicketType;

/**
 * Tests for the WooCommerce-missing notice.
 *
 * @covers \NetterTechEvents\Admin\Notices\WooCommerceMissingNotice
 */
final class WooCommerceMissingNoticeTest extends \NetterTechEventsTestCase {

	/**
	 * Ticket type repository mock.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $repo;

	/**
	 * Notice instance under test (with mocked WC detection).
	 *
	 * @var TestableWooCommerceMissingNotice
	 */
	private TestableWooCommerceMissingNotice $notice;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repo   = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->notice = new TestableWooCommerceMissingNotice( $this->repo );

		// Default: WooCommerce inactive (the case the notice exists for).
		$this->notice->set_woocommerce_active( false );

		// Reset $_GET between tests.
		$_GET = array();

		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
	}

	/**
	 * Tear down the test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * register() should hook into admin_notices.
	 *
	 * @return void
	 */
	public function test_register_hooks_admin_notices(): void {
		$actions_added = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$actions_added ) {
				$actions_added[] = array(
					'hook'     => $hook,
					'callback' => $callback,
				);
				return true;
			}
		);

		$this->notice->register();

		$hooks = array_column( $actions_added, 'hook' );
		$this->assertContains( 'admin_notices', $hooks );
	}

	/**
	 * Notice should not render when WooCommerce is active.
	 *
	 * @return void
	 */
	public function test_does_not_render_when_woocommerce_active(): void {
		$this->notice->set_woocommerce_active( true );

		$_GET['page']     = 'nettertech-events-edit';
		$_GET['event_id'] = '42';

		$this->repo->shouldNotReceive( 'for_event' );

		ob_start();
		$this->notice->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Notice should not render when not on the event editor screen.
	 *
	 * @return void
	 */
	public function test_does_not_render_off_event_editor(): void {
		$_GET['page']     = 'nettertech-events';
		$_GET['event_id'] = '42';

		$this->repo->shouldNotReceive( 'for_event' );

		ob_start();
		$this->notice->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Notice should not render when no event_id is in the query string.
	 *
	 * @return void
	 */
	public function test_does_not_render_without_event_id(): void {
		$_GET['page'] = 'nettertech-events-edit';

		$this->repo->shouldNotReceive( 'for_event' );

		ob_start();
		$this->notice->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Notice should not render when the event has no ticket types at all.
	 *
	 * @return void
	 */
	public function test_does_not_render_when_event_has_no_tickets(): void {
		$_GET['page']     = 'nettertech-events-edit';
		$_GET['event_id'] = '42';

		$this->repo->shouldReceive( 'for_event' )
			->once()
			->with( 42 )
			->andReturn( array() );

		ob_start();
		$this->notice->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Notice should not render when all ticket types are free (RSVP-only).
	 *
	 * @return void
	 */
	public function test_does_not_render_when_all_tickets_are_free(): void {
		$_GET['page']     = 'nettertech-events-edit';
		$_GET['event_id'] = '42';

		$free_ticket        = new TicketType();
		$free_ticket->price = 0.00;

		$this->repo->shouldReceive( 'for_event' )
			->once()
			->with( 42 )
			->andReturn( array( $free_ticket ) );

		ob_start();
		$this->notice->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Notice SHOULD render when the event has at least one paid ticket type
	 * and WooCommerce is not active.
	 *
	 * @return void
	 */
	public function test_renders_when_event_has_paid_ticket_and_no_woocommerce(): void {
		$_GET['page']     = 'nettertech-events-edit';
		$_GET['event_id'] = '42';

		$paid_ticket        = new TicketType();
		$paid_ticket->price = 25.00;

		$this->repo->shouldReceive( 'for_event' )
			->once()
			->with( 42 )
			->andReturn( array( $paid_ticket ) );

		ob_start();
		$this->notice->maybe_render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice notice-warning', $output );
		$this->assertStringContainsString( 'WooCommerce is not active', $output );
		$this->assertStringContainsString( 'NetterTech Events', $output );
	}

	/**
	 * Mixed ticket types (one free, one paid) should still trigger the notice.
	 *
	 * @return void
	 */
	public function test_renders_when_event_has_mixed_ticket_types(): void {
		$_GET['page']     = 'nettertech-events-edit';
		$_GET['event_id'] = '42';

		$free_ticket        = new TicketType();
		$free_ticket->price = 0.00;

		$paid_ticket        = new TicketType();
		$paid_ticket->price = 10.00;

		$this->repo->shouldReceive( 'for_event' )
			->once()
			->with( 42 )
			->andReturn( array( $free_ticket, $paid_ticket ) );

		ob_start();
		$this->notice->maybe_render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice notice-warning', $output );
	}

	/**
	 * Negative or invalid event_id should be treated as missing.
	 *
	 * @return void
	 */
	public function test_does_not_render_when_event_id_is_zero(): void {
		$_GET['page']     = 'nettertech-events-edit';
		$_GET['event_id'] = '0';

		$this->repo->shouldNotReceive( 'for_event' );

		ob_start();
		$this->notice->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}

/**
 * Test subclass exposing a setter for WooCommerce detection.
 *
 * Avoids polluting the global class table via eval() to mock class_exists().
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
final class TestableWooCommerceMissingNotice extends WooCommerceMissingNotice {

	/**
	 * Whether the notice should consider WooCommerce active.
	 *
	 * @var bool
	 */
	private bool $woocommerce_active = false;

	/**
	 * Set the WooCommerce-active flag for the test.
	 *
	 * @param bool $active Whether WooCommerce is considered active.
	 * @return void
	 */
	public function set_woocommerce_active( bool $active ): void {
		$this->woocommerce_active = $active;
	}

	/**
	 * Override the production class_exists() check.
	 *
	 * @return bool
	 */
	protected function is_woocommerce_active(): bool {
		return $this->woocommerce_active;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
