<?php
/**
 * WooCommerceIntegration unit tests.
 *
 * Uses bracketed namespace syntax to define global class stubs (WooCommerce,
 * WC_Checkout) needed for type hints and instanceof checks.
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
}

// phpcs:enable

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce {

	use Brain\Monkey\Functions;
	use NetterTechEvents\Integrations\WooCommerce\CartHandler;
	use NetterTechEvents\Integrations\WooCommerce\DonationHandler;
	use NetterTechEvents\Integrations\WooCommerce\OrderHandler;
	use NetterTechEvents\Integrations\WooCommerce\ProductManager;
	use NetterTechEvents\Integrations\WooCommerce\WooCommerceIntegration;
	use NetterTechEvents\Services\EmailService;

	/**
	 * Test WooCommerceIntegration wiring and initialization.
	 *
	 * Note: check_woocommerce_active() is a private 1-line method tested
	 * indirectly. Tests use reflection to set is_active for active-path
	 * testing since function_exists('WC') cannot be reliably controlled
	 * in a Brain\Monkey / Patchwork test environment.
	 */
	class WooCommerceIntegrationTest extends \NetterTechEventsTestCase {

		/**
		 * @var \NetterTechEvents\Contracts\TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $ticket_type_repo;

		/**
		 * @var \NetterTechEvents\Contracts\CapacityServiceInterface|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $capacity_service;

		/**
		 * @var \NetterTechEvents\Contracts\OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $occurrence_repo;

		/**
		 * @var (\NetterTechEvents\Contracts\AttendeeRepositoryInterface&\NetterTechEvents\Contracts\AttendeeOrderInterface)|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $attendee_repo;

		/**
		 * @var \NetterTechEvents\Contracts\TicketRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $ticket_repo;

		/**
		 * @var \NetterTechEvents\Services\TicketCodeGenerator|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $code_generator;

		/**
		 * @var EmailService|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $email_service;

		/**
		 * @var ProductManager|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $product_manager;

		/**
		 * @var \NetterTechEvents\Services\PaletteResolver|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $palette_resolver;

		/**
		 * @var \NetterTechEvents\Contracts\ActivityLogServiceInterface|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $activity_log;

		/**
		 * @var \NetterTechEvents\Services\AttendeeFieldService|\PHPUnit\Framework\MockObject\MockObject
		 */
		private $field_service;

		/**
		 * Tracks hook tags registered via add_action/add_filter.
		 *
		 * @var array<string>
		 */
		private array $registered_hooks = array();

		/**
		 * Tracks full hook registrations (tag, callback, priority, accepted_args).
		 *
		 * @var array<int,array<string,mixed>>
		 */
		private array $hook_registrations = array();

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			$this->ticket_type_repo = $this->createMock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
			$this->capacity_service = $this->createMock( \NetterTechEvents\Contracts\CapacityServiceInterface::class );
			$this->occurrence_repo  = $this->createMock( \NetterTechEvents\Contracts\OccurrenceRepositoryInterface::class );
			$this->attendee_repo    = $this->createMockForIntersectionOfInterfaces(
				array(
					\NetterTechEvents\Contracts\AttendeeRepositoryInterface::class,
					\NetterTechEvents\Contracts\AttendeeOrderInterface::class,
				)
			);
			$this->ticket_repo      = $this->createMock( \NetterTechEvents\Contracts\TicketRepositoryInterface::class );
			$this->code_generator   = $this->createMock( \NetterTechEvents\Services\TicketCodeGenerator::class );
			$this->email_service    = $this->createMock( EmailService::class );
			$this->product_manager  = $this->createMock( ProductManager::class );
			$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
			$this->palette_resolver = $this->createMock( \NetterTechEvents\Services\PaletteResolver::class );
			$this->activity_log     = $this->createMock( \NetterTechEvents\Contracts\ActivityLogServiceInterface::class );
			$this->field_service    = $this->createMock( \NetterTechEvents\Services\AttendeeFieldService::class );

			Functions\when( 'get_option' )->justReturn( array() );
			Functions\when( '__' )->returnArg();

			// Override bootstrap stubs to track hook registrations.
			$test = $this;
			Functions\when( 'add_action' )->alias(
				function ( $tag, $callback = null, $priority = 10, $accepted_args = 1 ) use ( $test ) {
					$test->registered_hooks[]   = $tag;
					$test->hook_registrations[] = array(
						'tag'           => $tag,
						'callback'      => $callback,
						'priority'      => $priority,
						'accepted_args' => $accepted_args,
					);
					return true;
				}
			);
			Functions\when( 'add_filter' )->alias(
				function ( $tag, $callback = null, $priority = 10, $accepted_args = 1 ) use ( $test ) {
					$test->registered_hooks[]   = $tag;
					$test->hook_registrations[] = array(
						'tag'           => $tag,
						'callback'      => $callback,
						'priority'      => $priority,
						'accepted_args' => $accepted_args,
					);
					return true;
				}
			);
		}

		// =================================================================
		// Helpers
		// =================================================================

		/**
		 * Find the first captured registration for a given hook tag.
		 *
		 * @param string $tag Hook tag.
		 * @return array<string,mixed>|null
		 */
		private function get_hook_registration( string $tag ): ?array {
			foreach ( $this->hook_registrations as $registration ) {
				if ( $registration['tag'] === $tag ) {
					return $registration;
				}
			}
			return null;
		}

		/**
		 * Create an integration (WC inactive by default since WC() is not defined).
		 *
		 * @return WooCommerceIntegration
		 */
		private function make_integration(): WooCommerceIntegration {
			return new WooCommerceIntegration(
				$this->ticket_type_repo,
				$this->capacity_service,
				$this->occurrence_repo,
				$this->attendee_repo,
				$this->ticket_repo,
				$this->code_generator,
				$this->email_service,
				$this->product_manager,
				$this->palette_resolver,
				$this->activity_log,
				$this->field_service,
				$this->createMock( \NetterTechEvents\Contracts\EventRepositoryInterface::class ),
				$this->createMock( \NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface::class ),
				$this->createMock( \NetterTechEvents\Services\RateLimitService::class )
			);
		}

		/**
		 * Create an integration with is_active = true and init() called.
		 *
		 * @return WooCommerceIntegration
		 */
		private function make_initialized_integration(): WooCommerceIntegration {
			$integration = $this->make_integration();

			$ref = new \ReflectionProperty( $integration, 'is_active' );
			$ref->setValue( $integration, true );

			$integration->init();

			return $integration;
		}

		/**
		 * Create an integration with is_active forced to a specific value.
		 *
		 * @param bool $active Whether WC should appear active.
		 * @return WooCommerceIntegration
		 */
		private function make_integration_with_state( bool $active ): WooCommerceIntegration {
			$integration = $this->make_integration();

			$ref = new \ReflectionProperty( $integration, 'is_active' );
			$ref->setValue( $integration, $active );

			return $integration;
		}

		// =================================================================
		// Constructor + is_available() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_constructor_completes_successfully(): void {
			$integration = $this->make_integration();

			$this->assertInstanceOf( WooCommerceIntegration::class, $integration );
		}

		/**
		 * @return void
		 */
		public function test_is_available_returns_true_when_active(): void {
			$integration = $this->make_integration_with_state( true );

			$this->assertTrue( $integration->is_available() );
		}

		/**
		 * @return void
		 */
		public function test_is_available_returns_false_when_inactive(): void {
			$integration = $this->make_integration_with_state( false );

			$this->assertFalse( $integration->is_available() );
		}

		// =================================================================
		// init() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_init_returns_early_when_inactive(): void {
			$this->registered_hooks = array();

			$integration = $this->make_integration_with_state( false );
			$integration->init();

			$this->assertNotContains( 'nettertech_events_ticket_type_saved', $this->registered_hooks );
			$this->assertNotContains( 'woocommerce_payment_complete', $this->registered_hooks );
		}

		/**
		 * @return void
		 */
		public function test_init_creates_product_manager(): void {
			$integration = $this->make_initialized_integration();

			$this->assertInstanceOf( ProductManager::class, $integration->get_product_manager() );
		}

		/**
		 * @return void
		 */
		public function test_init_creates_cart_handler(): void {
			$integration = $this->make_initialized_integration();

			$this->assertInstanceOf( CartHandler::class, $integration->get_cart_handler() );
		}

		/**
		 * @return void
		 */
		public function test_init_creates_order_handler(): void {
			$integration = $this->make_initialized_integration();

			$this->assertInstanceOf( OrderHandler::class, $integration->get_order_handler() );
		}

		/**
		 * @return void
		 */
		public function test_init_creates_donation_handler(): void {
			$integration = $this->make_initialized_integration();

			$this->assertInstanceOf( DonationHandler::class, $integration->get_donation_handler() );
		}

		/**
		 * @return void
		 */
		public function test_init_creates_email_service(): void {
			$integration = $this->make_initialized_integration();

			$this->assertInstanceOf( \NetterTechEvents\Services\EmailService::class, $integration->get_email_service() );
		}

		// =================================================================
		// Hook Registration Tests (via init)
		// =================================================================

		/**
		 * @return void
		 */
		public function test_init_registers_product_manager_hooks(): void {
			$this->make_initialized_integration();

			$this->assertContains( 'nettertech_events_ticket_type_sync_product', $this->registered_hooks );
			$this->assertContains( 'nettertech_events_ticket_type_deleted', $this->registered_hooks );
		}

		/**
		 * @return void
		 */
		public function test_init_registers_cart_handler_hooks(): void {
			$this->make_initialized_integration();

			$this->assertContains( 'woocommerce_add_to_cart_validation', $this->registered_hooks );
			$this->assertContains( 'woocommerce_cart_item_name', $this->registered_hooks );
			$this->assertContains( 'woocommerce_get_item_data', $this->registered_hooks );
			$this->assertContains( 'woocommerce_cart_item_thumbnail', $this->registered_hooks );
			$this->assertContains( 'woocommerce_checkout_create_order_line_item', $this->registered_hooks );
		}

		/**
		 * @return void
		 */
		public function test_init_registers_cart_reservation_hooks(): void {
			$this->make_initialized_integration();

			$this->assertContains( 'woocommerce_cart_item_removed', $this->registered_hooks );
			$this->assertContains( 'woocommerce_after_cart_item_quantity_update', $this->registered_hooks );
			$this->assertContains( 'woocommerce_cart_emptied', $this->registered_hooks );
		}

		/**
		 * Pins the woocommerce_check_cart_items registration (source line 355):
		 * the hook must be registered (FunctionCallRemoval) with the exact
		 * cart-handler callback array (ArrayItemRemoval).
		 *
		 * @return void
		 */
		public function test_init_registers_check_cart_items_hook_with_exact_callback(): void {
			$integration = $this->make_initialized_integration();

			$registration = $this->get_hook_registration( 'woocommerce_check_cart_items' );

			$this->assertNotNull( $registration );
			$this->assertSame(
				array( $integration->get_cart_handler(), 'check_cart_items' ),
				$registration['callback']
			);
		}

		/**
		 * Pins the woocommerce_store_api_cart_errors registration (source line
		 * 356): the hook must be registered (FunctionCallRemoval) with the exact
		 * callback array (ArrayItemRemoval) and the exact priority 10 and
		 * accepted-args 2 (Increment/DecrementInteger).
		 *
		 * @return void
		 */
		public function test_init_registers_store_api_cart_errors_hook_with_exact_signature(): void {
			$integration = $this->make_initialized_integration();

			$registration = $this->get_hook_registration( 'woocommerce_store_api_cart_errors' );

			$this->assertNotNull( $registration );
			$this->assertSame(
				array( $integration->get_cart_handler(), 'collect_store_api_errors' ),
				$registration['callback']
			);
			$this->assertSame( 10, $registration['priority'] );
			$this->assertSame( 2, $registration['accepted_args'] );
		}

		/**
		 * @return void
		 */
		public function test_init_registers_order_handler_hooks(): void {
			$this->make_initialized_integration();

			$this->assertContains( 'woocommerce_payment_complete', $this->registered_hooks );
			$this->assertContains( 'woocommerce_order_status_completed', $this->registered_hooks );
			$this->assertContains( 'woocommerce_order_status_processing', $this->registered_hooks );
			$this->assertContains( 'woocommerce_order_status_cancelled', $this->registered_hooks );
			$this->assertContains( 'woocommerce_order_status_refunded', $this->registered_hooks );
			// NTE-202: 'failed' was the one unpaid terminal status with no listener,
			// so a failed order kept confirmed, checkable-in attendees holding capacity.
			$this->assertContains( 'woocommerce_order_status_failed', $this->registered_hooks );
			$this->assertContains( 'woocommerce_refund_created', $this->registered_hooks );
		}

		/**
		 * @return void
		 */
		public function test_init_registers_admin_hooks(): void {
			$this->make_initialized_integration();

			$this->assertContains( 'woocommerce_product_data_tabs', $this->registered_hooks );
			$this->assertContains( 'woocommerce_product_data_panels', $this->registered_hooks );
			$this->assertContains( 'woocommerce_product_options_stock', $this->registered_hooks );
		}

		/**
		 * @return void
		 */
		public function test_init_registers_checkout_hooks(): void {
			$this->make_initialized_integration();

			$this->assertContains( 'woocommerce_after_order_notes', $this->registered_hooks );
			$this->assertContains( 'woocommerce_checkout_update_order_meta', $this->registered_hooks );
			$this->assertContains( 'woocommerce_admin_order_data_after_billing_address', $this->registered_hooks );
		}

		/**
		 * @return void
		 */
		public function test_init_registers_donation_hooks(): void {
			$this->make_initialized_integration();

			$this->assertContains( 'woocommerce_cart_calculate_fees', $this->registered_hooks );
			$this->assertContains( 'wp_ajax_nettertech_events_update_donation', $this->registered_hooks );
			$this->assertContains( 'wp_ajax_nopriv_nettertech_events_update_donation', $this->registered_hooks );
		}

		/**
		 * @return void
		 */
		public function test_init_registers_enqueue_scripts_hook(): void {
			$this->make_initialized_integration();

			$this->assertContains( 'wp_enqueue_scripts', $this->registered_hooks );
		}

		/**
		 * @return void
		 */
		public function test_init_calls_email_service_register(): void {
			$this->email_service->expects( $this->once() )
				->method( 'register' );

			$this->make_initialized_integration();
		}

		// =================================================================
		// add_product_data_tab() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_add_product_data_tab_adds_nte_tab(): void {
			$integration = $this->make_integration();
			$tabs        = $integration->add_product_data_tab( array() );

			$this->assertArrayHasKey( 'nettertech_events', $tabs );
			$this->assertSame( 'Event Ticket', $tabs['nettertech_events']['label'] );
			$this->assertSame( 'nettertech_events_product_data', $tabs['nettertech_events']['target'] );
			$this->assertSame( 21, $tabs['nettertech_events']['priority'] );
			$this->assertContains( 'show_if_nettertech_events_event_ticket', $tabs['nettertech_events']['class'] );
		}

		/**
		 * @return void
		 */
		public function test_add_product_data_tab_preserves_existing_tabs(): void {
			$integration   = $this->make_integration();
			$existing_tabs = array( 'general' => array( 'label' => 'General' ) );
			$tabs          = $integration->add_product_data_tab( $existing_tabs );

			$this->assertArrayHasKey( 'general', $tabs );
			$this->assertArrayHasKey( 'nettertech_events', $tabs );
			$this->assertCount( 2, $tabs );
		}

		// =================================================================
		// add_to_cart() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_add_to_cart_returns_false_when_inactive(): void {
			$integration = $this->make_integration_with_state( false );

			$this->assertFalse( $integration->add_to_cart( 1, 2 ) );
		}

		/**
		 * @return void
		 */
		public function test_add_to_cart_delegates_to_cart_handler(): void {
			$integration = $this->make_initialized_integration();

			// CartHandler->add_ticket_to_cart() returns false without WC cart session.
			$result = $integration->add_to_cart( 1, 1 );

			$this->assertFalse( $result );
		}

		// =================================================================
		// get_cart_url() / get_checkout_url() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_get_cart_url_returns_empty_when_inactive(): void {
			$integration = $this->make_integration_with_state( false );

			$this->assertSame( '', $integration->get_cart_url() );
		}

		/**
		 * @return void
		 */
		public function test_get_cart_url_delegates_to_wc_function(): void {
			Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.com/cart/' );

			$integration = $this->make_integration_with_state( true );

			$this->assertSame( 'https://example.com/cart/', $integration->get_cart_url() );
		}

		/**
		 * @return void
		 */
		public function test_get_checkout_url_returns_empty_when_inactive(): void {
			$integration = $this->make_integration_with_state( false );

			$this->assertSame( '', $integration->get_checkout_url() );
		}

		/**
		 * @return void
		 */
		public function test_get_checkout_url_delegates_to_wc_function(): void {
			Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://example.com/checkout/' );

			$integration = $this->make_integration_with_state( true );

			$this->assertSame( 'https://example.com/checkout/', $integration->get_checkout_url() );
		}

		// =================================================================
		// maybe_enqueue_checkout_assets() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_maybe_enqueue_checkout_assets_returns_early_when_disabled(): void {
			$integration = $this->make_initialized_integration();

			// DonationHandler::is_enabled() returns false (no 'enable_donations' in options).
			$integration->maybe_enqueue_checkout_assets();

			$this->assertTrue( true );
		}

		// =================================================================
		// Accessibility notes tests → AccessibilityNotesHandlerTest.php
		// =================================================================

		// =================================================================
		// filter_ticket_thumbnail() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_filter_ticket_thumbnail_returns_original_without_product_id(): void {
			$integration = $this->make_initialized_integration();

			$result = $integration->filter_ticket_thumbnail(
				'<img src="original.jpg" />',
				array(),
				'key1'
			);

			$this->assertSame( '<img src="original.jpg" />', $result );
		}

		/**
		 * @return void
		 */
		public function test_filter_ticket_thumbnail_returns_original_for_zero_product(): void {
			$integration = $this->make_initialized_integration();

			$result = $integration->filter_ticket_thumbnail(
				'<img src="original.jpg" />',
				array( 'product_id' => 0 ),
				'key1'
			);

			$this->assertSame( '<img src="original.jpg" />', $result );
		}

		// =================================================================
		// filter_admin_order_item_thumbnail() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_filter_admin_thumbnail_returns_original_for_non_product_item(): void {
			$integration = $this->make_integration();

			$item   = $this->createMock( \WC_Order_Item::class );
			$result = $integration->filter_admin_order_item_thumbnail(
				'<img src="original.jpg" />',
				1,
				$item
			);

			$this->assertSame( '<img src="original.jpg" />', $result );
		}

		/**
		 * @return void
		 */
		public function test_filter_admin_thumbnail_returns_original_for_non_ticket(): void {
			$integration = $this->make_initialized_integration();

			$item = $this->createMock( \WC_Order_Item_Product::class );
			$item->method( 'get_product_id' )->willReturn( 0 );

			$result = $integration->filter_admin_order_item_thumbnail(
				'<img src="original.jpg" />',
				1,
				$item
			);

			$this->assertSame( '<img src="original.jpg" />', $result );
		}

		// =================================================================
		// render_product_data_panel() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_render_product_data_panel_shows_not_linked(): void {
			Functions\when( 'wc_get_product' )->justReturn( false );
			Functions\when( 'esc_html_e' )->alias(
				function ( $text ) {
					echo $text;
				}
			);
			Functions\when( 'esc_html' )->returnArg();

			$post     = new \stdClass();
			$post->ID = 1;

			$GLOBALS['post'] = $post;

			$integration = $this->make_integration();

			ob_start();
			$integration->render_product_data_panel();
			$output = ob_get_clean();

			unset( $GLOBALS['post'] );

			$this->assertStringContainsString( 'nettertech_events_product_data', $output );
			$this->assertStringContainsString( 'Not linked', $output );
		}

		/**
		 * @return void
		 */
		public function test_render_product_data_panel_shows_linked_with_checkin_link(): void {
			$wc_product = $this->createMock( \WC_Product::class );
			$wc_product->method( 'get_meta' )->willReturnCallback(
				function ( $key ) {
					if ( '_nettertech_events_occurrence_id' === $key ) {
						return '42';
					}
					if ( '_nettertech_events_ticket_type_id' === $key ) {
						return '7';
					}
					return '';
				}
			);
			Functions\when( 'wc_get_product' )->justReturn( $wc_product );
			Functions\when( 'esc_html_e' )->alias(
				function ( $text ) {
					echo $text;
				}
			);
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'admin_url' )->alias(
				function ( $path ) {
					return 'https://example.com/wp-admin/' . $path;
				}
			);

			$post     = new \stdClass();
			$post->ID = 1;

			$GLOBALS['post'] = $post;

			$integration = $this->make_integration();

			ob_start();
			$integration->render_product_data_panel();
			$output = ob_get_clean();

			unset( $GLOBALS['post'] );

			$this->assertStringContainsString( '42', $output );
			$this->assertStringContainsString( '7', $output );
			$this->assertStringContainsString( 'View Check-In List', $output );
			$this->assertStringContainsString( 'occurrence_id=42', $output );
		}

		// =================================================================
		// filter_ticket_thumbnail() Active Path
		// =================================================================

		/**
		 * @return void
		 */
		public function test_filter_ticket_thumbnail_returns_placeholder_for_ticket(): void {
			Functions\when( 'get_post_meta' )->alias(
				function ( $id, $key, $single ) {
					if ( '_nettertech_events_is_event_ticket' === $key ) {
						return 'yes';
					}
					return '';
				}
			);
			Functions\when( 'plugins_url' )->justReturn( 'https://example.com/ticket-placeholder.svg' );
			Functions\when( 'wc_get_image_size' )->justReturn( array( 'width' => 100, 'height' => 100 ) );
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'esc_attr__' )->returnArg();

			$integration = $this->make_initialized_integration();

			$result = $integration->filter_ticket_thumbnail(
				'<img src="original.jpg" />',
				array( 'product_id' => 42 ),
				'key1'
			);

			$this->assertStringContainsString( 'nte-ticket-placeholder', $result );
			$this->assertStringContainsString( 'ticket-placeholder.svg', $result );
			$this->assertStringNotContainsString( 'original.jpg', $result );
		}

		// =================================================================
		// filter_admin_order_item_thumbnail() Active Path
		// =================================================================

		/**
		 * @return void
		 */
		public function test_filter_admin_thumbnail_returns_placeholder_for_ticket(): void {
			Functions\when( 'get_post_meta' )->alias(
				function ( $id, $key, $single ) {
					if ( '_nettertech_events_is_event_ticket' === $key ) {
						return 'yes';
					}
					return '';
				}
			);
			Functions\when( 'plugins_url' )->justReturn( 'https://example.com/ticket-placeholder.svg' );
			Functions\when( 'wc_get_image_size' )->justReturn( array( 'width' => 50, 'height' => 50 ) );
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'esc_attr__' )->returnArg();

			$integration = $this->make_initialized_integration();

			$item = $this->createMock( \WC_Order_Item_Product::class );
			$item->method( 'get_product_id' )->willReturn( 42 );

			$result = $integration->filter_admin_order_item_thumbnail(
				'<img src="original.jpg" />',
				1,
				$item
			);

			$this->assertStringContainsString( 'nte-ticket-placeholder', $result );
			$this->assertStringNotContainsString( 'original.jpg', $result );
		}

		// =================================================================
		// maybe_enqueue_checkout_assets() Active Path
		// =================================================================

		/**
		 * @return void
		 */
		public function test_maybe_enqueue_checkout_assets_proceeds_when_enabled(): void {
			$integration = $this->make_initialized_integration();

			// Inject a mock DonationHandler that reports enabled.
			$mock_donation = $this->createMock( DonationHandler::class );
			$mock_donation->method( 'is_enabled' )->willReturn( true );

			$ref = new \ReflectionProperty( $integration, 'donation_handler' );
			$ref->setValue( $integration, $mock_donation );

			// Assets::enqueue_checkout() checks is_checkout() — stub it to return false
			// so it returns early after passing the is_enabled() guard.
			Functions\when( 'is_checkout' )->justReturn( false );

			$integration->maybe_enqueue_checkout_assets();

			$this->assertTrue( true );
		}

		// =================================================================
		// render_stock_notice_for_managed_products() Tests
		// =================================================================

		/**
		 * @return void
		 */
		public function test_stock_notice_renders_for_managed_product(): void {
			$ticket_type = \NetterTechEvents\Tests\Factories\TicketTypeFactory::create(
				array(
					'id'       => 7,
					'capacity' => 50,
				)
			);

			$wc_product = $this->createMock( \WC_Product::class );
			$wc_product->method( 'get_meta' )->willReturnCallback(
				function ( $key ) {
					if ( '_nettertech_events_ticket_type_id' === $key ) {
						return '7';
					}
					if ( '_nettertech_events_event_id' === $key ) {
						return '42';
					}
					if ( '_nettertech_events_is_event_ticket' === $key ) {
						return 'yes';
					}
					return '';
				}
			);

			$this->product_manager = $this->createMock( ProductManager::class );
			$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
			$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

			Functions\when( 'wc_get_product' )->justReturn( $wc_product );
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'wp_kses' )->returnArg();
			Functions\when( 'admin_url' )->alias(
				function ( $path ) {
					return 'https://example.com/wp-admin/' . $path;
				}
			);

			$post     = new \stdClass();
			$post->ID = 1;

			$GLOBALS['post'] = $post;

			$integration = $this->make_integration();

			ob_start();
			$integration->render_stock_notice_for_managed_products();
			$output = ob_get_clean();

			unset( $GLOBALS['post'] );

			$this->assertStringContainsString( 'Stock is managed by NetterTech Events', $output );
			$this->assertStringContainsString( '50', $output );
			$this->assertStringContainsString( 'Edit capacity', $output );
			$this->assertStringContainsString( 'event_id=42', $output );
			$this->assertStringNotContainsString( '<style>', $output );
			$this->assertStringNotContainsString( '._manage_stock_field', $output );
		}

		/**
		 * @return void
		 */
		public function test_stock_notice_does_not_render_for_regular_product(): void {
			$this->product_manager = $this->createMock( ProductManager::class );
			$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

			$post     = new \stdClass();
			$post->ID = 1;

			$GLOBALS['post'] = $post;

			$integration = $this->make_integration();

			ob_start();
			$integration->render_stock_notice_for_managed_products();
			$output = ob_get_clean();

			unset( $GLOBALS['post'] );

			$this->assertEmpty( $output );
		}

		/**
		 * @return void
		 */
		public function test_stock_notice_shows_unlimited_when_no_capacity(): void {
			$ticket_type = \NetterTechEvents\Tests\Factories\TicketTypeFactory::create(
				array(
					'id'       => 7,
					'capacity' => null,
				)
			);

			$wc_product = $this->createMock( \WC_Product::class );
			$wc_product->method( 'get_meta' )->willReturnCallback(
				function ( $key ) {
					if ( '_nettertech_events_ticket_type_id' === $key ) {
						return '7';
					}
					if ( '_nettertech_events_event_id' === $key ) {
						return '42';
					}
					if ( '_nettertech_events_is_event_ticket' === $key ) {
						return 'yes';
					}
					return '';
				}
			);

			$this->product_manager = $this->createMock( ProductManager::class );
			$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
			$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

			Functions\when( 'wc_get_product' )->justReturn( $wc_product );
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'wp_kses' )->returnArg();
			Functions\when( 'admin_url' )->alias(
				function ( $path ) {
					return 'https://example.com/wp-admin/' . $path;
				}
			);

			$post     = new \stdClass();
			$post->ID = 1;

			$GLOBALS['post'] = $post;

			$integration = $this->make_integration();

			ob_start();
			$integration->render_stock_notice_for_managed_products();
			$output = ob_get_clean();

			unset( $GLOBALS['post'] );

			$this->assertStringContainsString( 'Unlimited', $output );
		}

		/**
		 * @return void
		 */
		public function test_stock_notice_omits_link_without_event_id(): void {
			$wc_product = $this->createMock( \WC_Product::class );
			$wc_product->method( 'get_meta' )->willReturnCallback(
				function ( $key ) {
					if ( '_nettertech_events_ticket_type_id' === $key ) {
						return '7';
					}
					if ( '_nettertech_events_is_event_ticket' === $key ) {
						return 'yes';
					}
					return '';
				}
			);

			$this->product_manager = $this->createMock( ProductManager::class );
			$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
			$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

			Functions\when( 'wc_get_product' )->justReturn( $wc_product );
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'wp_kses' )->returnArg();

			$post     = new \stdClass();
			$post->ID = 1;

			$GLOBALS['post'] = $post;

			$integration = $this->make_integration();

			ob_start();
			$integration->render_stock_notice_for_managed_products();
			$output = ob_get_clean();

			unset( $GLOBALS['post'] );

			$this->assertStringContainsString( 'Stock is managed by NetterTech Events', $output );
			$this->assertStringNotContainsString( 'Edit capacity', $output );
			$this->assertStringNotContainsString( '<a href=', $output );
		}

		/**
		 * @return void
		 */
		public function test_stock_notice_returns_early_without_post(): void {
			unset( $GLOBALS['post'] );

			$integration = $this->make_integration();

			ob_start();
			$integration->render_stock_notice_for_managed_products();
			$output = ob_get_clean();

			$this->assertEmpty( $output );
		}

		// =================================================================
		// Getter coverage
		// =================================================================

		/**
		 * @return void
		 */
		public function test_getters_return_correct_types_after_init(): void {
			$integration = $this->make_initialized_integration();

			$this->assertInstanceOf( ProductManager::class, $integration->get_product_manager() );
			$this->assertInstanceOf( CartHandler::class, $integration->get_cart_handler() );
			$this->assertInstanceOf( OrderHandler::class, $integration->get_order_handler() );
			$this->assertInstanceOf( DonationHandler::class, $integration->get_donation_handler() );
			$this->assertInstanceOf( \NetterTechEvents\Services\EmailService::class, $integration->get_email_service() );
		}
	}
}
