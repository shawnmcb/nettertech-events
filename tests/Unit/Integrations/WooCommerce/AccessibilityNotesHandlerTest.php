<?php
/**
 * AccessibilityNotesHandler unit tests.
 *
 * Uses bracketed namespace syntax to define global class stubs (WC_Checkout,
 * WC_Order) needed for type hints.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock stubs for unit testing.

namespace {
	if ( ! class_exists( 'WC_Checkout' ) ) {
		class WC_Checkout {
			/**
			 * @param string $key Field key.
			 * @return string
			 */
			public function get_value( string $key ): string {
				return '';
			}
		}
	}

	if ( ! class_exists( 'WC_Order' ) ) {
		class WC_Order {
			/**
			 * @param string $key Meta key.
			 * @param bool   $single Whether to return single value.
			 * @return mixed
			 */
			public function get_meta( string $key, bool $single = true ): mixed {
				return '';
			}

			/**
			 * @param string $key   Meta key.
			 * @param mixed  $value Meta value.
			 * @return void
			 */
			public function update_meta_data( string $key, $value ): void {}

			/**
			 * @return void
			 */
			public function save(): void {}
		}
	}
}

// phpcs:enable

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce {

	use Brain\Monkey\Functions;
	use Mockery;
	use NetterTechEvents\Integrations\WooCommerce\AccessibilityNotesHandler;
	use NetterTechEvents\Integrations\WooCommerce\CartHandler;

	/**
	 * Test AccessibilityNotesHandler business logic.
	 *
	 * Covers the three extracted methods:
	 * - render_field(): conditional on cart_has_tickets()
	 * - save_notes(): sanitizes and saves to order meta
	 * - display_admin(): renders notes only when non-empty
	 */
	class AccessibilityNotesHandlerTest extends \NetterTechEventsTestCase {

		/**
		 * Cart handler mock.
		 *
		 * @var CartHandler&\Mockery\MockInterface
		 */
		private $cart_handler;

		/**
		 * System under test.
		 *
		 * @var AccessibilityNotesHandler
		 */
		private AccessibilityNotesHandler $sut;

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			$this->cart_handler = Mockery::mock( CartHandler::class );
			$this->sut          = new AccessibilityNotesHandler( $this->cart_handler );

			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'esc_html' )->returnArg();
		}

		// =====================================================================
		// render_field()
		// =====================================================================

		/**
		 * @return void
		 */
		public function test_render_field_outputs_nothing_when_cart_has_no_tickets(): void {
			$this->cart_handler->allows( 'cart_has_tickets' )->andReturn( false );

			$checkout = new \WC_Checkout();

			ob_start();
			$this->sut->render_field( $checkout );
			$output = ob_get_clean();

			$this->assertSame( '', $output );
		}

		/**
		 * @return void
		 */
		public function test_render_field_outputs_form_when_cart_has_tickets(): void {
			$this->cart_handler->allows( 'cart_has_tickets' )->andReturn( true );

			Functions\when( 'woocommerce_form_field' )->justEcho( '<textarea name="nettertech_events_accessibility_notes"></textarea>' );

			$checkout = new \WC_Checkout();

			ob_start();
			$this->sut->render_field( $checkout );
			$output = ob_get_clean();

			$this->assertStringContainsString( 'nte-accessibility-notes-field', $output );
			$this->assertStringContainsString( 'nettertech_events_accessibility_notes', $output );
		}

		// =====================================================================
		// save_notes()
		// =====================================================================

		/**
		 * @return void
		 */
		public function test_save_notes_skips_when_post_field_absent(): void {
			$checkout = Mockery::mock( \WC_Checkout::class );
			$checkout->shouldReceive( 'get_value' )
				->with( 'nettertech_events_accessibility_notes' )
				->andReturn( '' );

			$wc = Mockery::mock();
			$wc->shouldReceive( 'checkout' )->andReturn( $checkout );
			Functions\when( 'WC' )->justReturn( $wc );

			$order = Mockery::mock( \WC_Order::class );
			$order->shouldNotReceive( 'update_meta_data' );
			$order->shouldNotReceive( 'save' );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$this->sut->save_notes( 1 );

			// No assertions needed — Mockery verifies no unexpected calls.
			$this->addToAssertionCount( 1 );
		}

		/**
		 * @return void
		 */
		public function test_save_notes_sanitizes_and_saves_when_field_present(): void {
			$checkout = Mockery::mock( \WC_Checkout::class );
			$checkout->shouldReceive( 'get_value' )
				->with( 'nettertech_events_accessibility_notes' )
				->andReturn( 'I need wheelchair access.' );

			$wc = Mockery::mock();
			$wc->shouldReceive( 'checkout' )->andReturn( $checkout );
			Functions\when( 'WC' )->justReturn( $wc );

			Functions\when( 'sanitize_textarea_field' )->returnArg();

			$order = Mockery::mock( \WC_Order::class );
			$order->shouldReceive( 'update_meta_data' )
				->once()
				->with( '_nettertech_events_accessibility_notes', 'I need wheelchair access.' );
			$order->shouldReceive( 'save' )->once();

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$this->sut->save_notes( 42 );
		}

		// =====================================================================
		// display_admin()
		// =====================================================================

		/**
		 * @return void
		 */
		public function test_display_admin_outputs_nothing_when_no_notes(): void {
			$order = Mockery::mock( \WC_Order::class );
			$order->allows( 'get_meta' )->andReturn( '' );

			ob_start();
			$this->sut->display_admin( $order );
			$output = ob_get_clean();

			$this->assertSame( '', $output );
		}

		/**
		 * @return void
		 */
		public function test_display_admin_renders_notes_when_present(): void {
			$order = Mockery::mock( \WC_Order::class );
			$order->allows( 'get_meta' )->andReturn( 'Wheelchair access needed.' );

			ob_start();
			$this->sut->display_admin( $order );
			$output = ob_get_clean();

			$this->assertStringContainsString( 'nte-accessibility-notes-admin', $output );
			$this->assertStringContainsString( 'Wheelchair access needed.', $output );
		}
	}
}
