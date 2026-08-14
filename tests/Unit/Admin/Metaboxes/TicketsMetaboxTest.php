<?php
/**
 * TicketsMetabox unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Metaboxes\TicketsMetabox;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;

/**
 * Test TicketsMetabox functionality.
 *
 * Tests the ticket configuration metabox for event editing.
 */
class TicketsMetaboxTest extends \NetterTechEventsTestCase {

	/**
	 * Mock ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Mock capacity service.
	 *
	 * @var CapacityServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $capacity_service;

	/**
	 * TicketsMetabox instance.
	 *
	 * @var TicketsMetabox
	 */
	private TicketsMetabox $metabox;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create mocks.
		$this->ticket_type_repo = $this->createMock( TicketTypeRepositoryInterface::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->event_repo       = $this->createMock( EventRepositoryInterface::class );
		$this->capacity_service = $this->createMock( CapacityServiceInterface::class );

		// Reset service registry.
		ServiceRegistry::reset();

		// Set up common WordPress function mocks.
		$this->setup_wp_functions();

		$this->metabox = new TicketsMetabox(
			$this->ticket_type_repo,
			$this->capacity_service
		);
	}

	/**
	 * Set up common WordPress function mocks.
	 *
	 * @return void
	 */
	private function setup_wp_functions(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn();
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'wp_localize_script' )->justReturn( null );
		Functions\when( 'current_time' )->justReturn( '2026-07-22' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'America/Chicago' );
		Functions\when( 'checked' )->alias(
			function ( $checked, $current = true, $echo = true ) {
				$result = ( $checked === $current ) ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, $echo = true ) {
				$result = ( $selected === $current ) ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		// Note: class_exists cannot be mocked with Brain Monkey (internal function).
		// WooCommerce will show as not active in test environment.
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_edit_post_link' )->justReturn( 'http://example.com/edit-product' );

		Functions\when( 'add_query_arg' )->alias(
			function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $key ) );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test METABOX_ID constant is defined.
	 *
	 * @return void
	 */
	public function test_metabox_id_constant_exists(): void {
		$this->assertEquals( 'nettertech_events_tickets_metabox', TicketsMetabox::METABOX_ID );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor accepts dependencies via injection.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_dependencies(): void {
		$metabox = new TicketsMetabox(
			$this->ticket_type_repo,
			$this->capacity_service
		);

		$this->assertInstanceOf( TicketsMetabox::class, $metabox );
	}

	/**
	 * Test constructor with all required dependencies.
	 *
	 * @return void
	 */
	public function test_constructor_with_all_dependencies(): void {
		$metabox = new TicketsMetabox(
			$this->ticket_type_repo,
			$this->capacity_service
		);

		$this->assertInstanceOf( TicketsMetabox::class, $metabox );
	}

	// =========================================================================
	// set_context() Tests
	// =========================================================================

	/**
	 * Test set_context returns self for fluent interface.
	 *
	 * @return void
	 */
	public function test_set_context_returns_self(): void {
		$event = new Event();
		$event->id = 1;

		$result = $this->metabox->set_context( $event );

		$this->assertSame( $this->metabox, $result );
	}

	/**
	 * Test set_context with event and occurrence.
	 *
	 * @return void
	 */
	public function test_set_context_with_event_and_occurrence(): void {
		$event = new Event();
		$event->id = 1;

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$result = $this->metabox->set_context( $event, $occurrence );

		$this->assertSame( $this->metabox, $result );
	}

	/**
	 * Test set_context with null event.
	 *
	 * @return void
	 */
	public function test_set_context_with_null_event(): void {
		$result = $this->metabox->set_context( null );

		$this->assertSame( $this->metabox, $result );
	}

	// =========================================================================
	// render() Tests - Buffered (unsaved event) form
	// =========================================================================

	/**
	 * A brand-new (contextless) event renders a working, buffered ticket form (NTE-177).
	 *
	 * @return void
	 */
	public function test_render_shows_buffered_form_when_no_event(): void {
		$this->metabox->set_context( null );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-tickets-metabox', $output );
		// Buffered form, not the old "save first" dead end.
		$this->assertStringNotContainsString( 'Save the event first', $output );
		$this->assertStringContainsString( 'name="ticketing_enabled"', $output );
		$this->assertStringContainsString( 'nte_tickets_metabox_rendered', $output );
		$this->assertStringContainsString( 'nte-add-ticket', $output );

		// WooCommerce inactive in the test environment → the warning notice renders.
		$this->assertStringContainsString( 'WooCommerce is not active', $output );
		// Copy strings that must not be dropped.
		$this->assertStringContainsString( 'Enable Ticketing', $output );
		$this->assertStringContainsString( 'Add ticket types now', $output );
		$this->assertStringContainsString( 'Add Ticket Type', $output );
		// The occurrence-scope row template must render (its named inputs prove scope).
		$this->assertStringContainsString( 'ticket_types[occurrence]', $output );
	}

	/**
	 * The buffered form fires the ticket add-button hook once with count 0, occurrence scope.
	 *
	 * @return void
	 */
	public function test_buffered_form_fires_add_button_hook_with_zero_count(): void {
		Actions\expectDone( 'nettertech_events_ticket_add_button_area' )
			->once()
			->with( null, 0, 'occurrence' );

		$this->metabox->set_context( null );

		ob_start();
		$this->metabox->render();
		ob_get_clean();
	}

	/**
	 * An event with no ID (unsaved) also renders the buffered ticket form.
	 *
	 * @return void
	 */
	public function test_render_shows_buffered_form_when_event_has_no_id(): void {
		$event = new Event();
		// No ID set.

		$this->metabox->set_context( $event );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Save the event first', $output );
		$this->assertStringContainsString( 'name="ticketing_enabled"', $output );
		$this->assertStringContainsString( 'nte-add-ticket', $output );
	}

	// =========================================================================
	// render() Tests - Single Event
	// =========================================================================

	/**
	 * Test render for single event without tickets.
	 *
	 * @return void
	 */
	public function test_render_single_event_without_tickets(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;
		$occurrence->event_id = 1;

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-tickets-metabox', $output );
		$this->assertStringContainsString( 'nte-ticketing-toggle', $output );
		$this->assertStringContainsString( 'Enable Ticketing', $output );
	}

	/**
	 * Test render for single event with tickets shows them enabled.
	 *
	 * @return void
	 */
	public function test_render_single_event_with_tickets(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;
		$occurrence->event_id = 1;

		$ticket = new TicketType();
		$ticket->id = 1;
		$ticket->name = 'General Admission';
		$ticket->price = 25.00;
		$ticket->capacity_type = 'fixed';
		$ticket->capacity = 100;

		$this->ticket_type_repo->method( 'for_occurrence' )
			->willReturn( array( $ticket ) );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => 100,
				'sold'                => 10,
				'available'           => 90,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => 90,
				'is_unlimited'        => false,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'General Admission', $output );
		$this->assertStringContainsString( 'nte-ticket-row', $output );
	}

	/**
	 * Test render for single event without occurrence shows date first message.
	 *
	 * @return void
	 */
	public function test_render_single_event_without_occurrence(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$this->metabox->set_context( $event, null );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Set the event date and time first', $output );
	}

	// =========================================================================
	// render() Tests - Recurring Event
	// =========================================================================

	/**
	 * Test render for recurring event shows tabs.
	 *
	 * @return void
	 */
	public function test_render_recurring_event_shows_tabs(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'recurring';

		$occurrence = new Occurrence();
		$occurrence->id = 100;
		$occurrence->event_id = 1;
		$occurrence->start_datetime = '2026-01-15 19:00:00';

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );
		$this->ticket_type_repo->method( 'for_event' )->willReturn( array() );
		$this->ticket_type_repo->method( 'get_templates' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-ticket-tabs', $output );
		$this->assertStringContainsString( 'Series Passes', $output );
		$this->assertStringContainsString( 'Ticket Templates', $output );
		$this->assertStringContainsString( 'This Occurrence', $output );
	}

	/**
	 * Test render for recurring event with event-scoped tickets.
	 *
	 * @return void
	 */
	public function test_render_recurring_event_with_series_passes(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'recurring';

		$occurrence = new Occurrence();
		$occurrence->id = 100;
		$occurrence->event_id = 1;
		$occurrence->start_datetime = '2026-01-15 19:00:00';

		$series_pass = new TicketType();
		$series_pass->id = 1;
		$series_pass->name = 'Season Pass';
		$series_pass->scope = 'event';
		$series_pass->price = 200.00;
		$series_pass->capacity_type = 'fixed';

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );
		$this->ticket_type_repo->method( 'for_event' )->willReturn( array( $series_pass ) );
		$this->ticket_type_repo->method( 'get_templates' )->willReturn( array() );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => null,
				'sold'                => 5,
				'available'           => null,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => null,
				'is_unlimited'        => true,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Season Pass', $output );
		$this->assertStringContainsString( 'nte-scope-event', $output );
	}

	/**
	 * Test render for recurring event with templates.
	 *
	 * @return void
	 */
	public function test_render_recurring_event_with_templates(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'recurring';

		$occurrence = new Occurrence();
		$occurrence->id = 100;
		$occurrence->event_id = 1;
		$occurrence->start_datetime = '2026-01-15 19:00:00';

		$template = new TicketType();
		$template->id = 2;
		$template->name = 'Standard Ticket Template';
		$template->scope = 'template';
		$template->price = 50.00;
		$template->capacity_type = 'fixed';

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );
		$this->ticket_type_repo->method( 'for_event' )->willReturn( array() );
		$this->ticket_type_repo->method( 'get_templates' )->willReturn( array( $template ) );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => null,
				'sold'                => 0,
				'available'           => null,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => null,
				'is_unlimited'        => true,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Standard Ticket Template', $output );
		$this->assertStringContainsString( 'nte-scope-template', $output );
	}

	// =========================================================================
	// render() Tests - WooCommerce
	// =========================================================================

	/**
	 * Test render shows WooCommerce warning when not active.
	 *
	 * Note: In test environment, WooCommerce class_exists returns false,
	 * so this warning is always shown.
	 *
	 * @return void
	 */
	public function test_render_shows_woocommerce_warning_when_not_active(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		// WooCommerce is not loaded in test environment.
		$this->assertStringContainsString( 'WooCommerce is not active', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
	}

	/**
	 * Test ticket with WC product ID includes hidden field.
	 *
	 * Note: WC link won't show since class_exists('WooCommerce') is false in tests.
	 * This test verifies the ticket row still renders with the hidden id field.
	 *
	 * @return void
	 */
	public function test_render_ticket_with_wc_product_id(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$ticket = new TicketType();
		$ticket->id = 1;
		$ticket->name = 'General Admission';
		$ticket->price = 25.00;
		$ticket->capacity_type = 'fixed';
		$ticket->wc_product_id = 999;

		$this->ticket_type_repo->method( 'for_occurrence' )
			->willReturn( array( $ticket ) );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => 100,
				'sold'                => 0,
				'available'           => 100,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => 100,
				'is_unlimited'        => false,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		// Ticket should still render, just without the WC link (WooCommerce not loaded).
		$this->assertStringContainsString( 'General Admission', $output );
		$this->assertStringContainsString( 'nte-ticket-row', $output );
	}

	// =========================================================================
	// render() Tests - Capacity Display
	// =========================================================================

	/**
	 * Test render shows capacity status for limited tickets.
	 *
	 * @return void
	 */
	public function test_render_shows_capacity_status(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$ticket = new TicketType();
		$ticket->id = 1;
		$ticket->name = 'Limited Ticket';
		$ticket->price = 25.00;
		$ticket->capacity_type = 'fixed';
		$ticket->capacity = 50;

		$this->ticket_type_repo->method( 'for_occurrence' )
			->willReturn( array( $ticket ) );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => 50,
				'sold'                => 40,
				'available'           => 10,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => 10,
				'is_unlimited'        => false,
				'is_sold_out'         => false,
				'is_low_stock'        => true,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '40 sold', $output );
		$this->assertStringContainsString( '10 available', $output );
		$this->assertStringContainsString( 'Low stock', $output );
	}

	/**
	 * Test render shows sold out status.
	 *
	 * @return void
	 */
	public function test_render_shows_sold_out_status(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$ticket = new TicketType();
		$ticket->id = 1;
		$ticket->name = 'Sold Out Ticket';
		$ticket->price = 25.00;
		$ticket->capacity_type = 'fixed';
		$ticket->capacity = 50;

		$this->ticket_type_repo->method( 'for_occurrence' )
			->willReturn( array( $ticket ) );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => 50,
				'sold'                => 50,
				'available'           => 0,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => 0,
				'is_unlimited'        => false,
				'is_sold_out'         => true,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Sold out', $output );
	}

	// =========================================================================
	// render() Tests - Capacity Type Options
	// =========================================================================

	/**
	 * Test render shows capacity type options.
	 *
	 * @return void
	 */
	public function test_render_shows_capacity_type_options(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$ticket = new TicketType();
		$ticket->id = 1;
		$ticket->name = 'Test Ticket';
		$ticket->price = 25.00;
		$ticket->capacity_type = 'fixed';

		$this->ticket_type_repo->method( 'for_occurrence' )
			->willReturn( array( $ticket ) );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => null,
				'sold'                => 0,
				'available'           => null,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => null,
				'is_unlimited'        => true,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-capacity-type-select', $output );
		$this->assertStringContainsString( 'Capacity Type', $output );
	}

	// =========================================================================
	// render() Tests - Scripts and Styles
	// =========================================================================

	/**
	 * Test render does not include executable inline JavaScript.
	 *
	 * @return void
	 */
	public function test_render_does_not_include_executable_inline_javascript(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'ticketIndexes', $output );
		$this->assertStringNotContainsString( '<script>', str_replace( '<script type="text/template"', '', $output ) );
	}

	/**
	 * Test render does not include inline CSS styles.
	 *
	 * @return void
	 */
	public function test_render_does_not_include_inline_styles(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<style>', $output );
		$this->assertStringContainsString( 'nte-tickets-metabox', $output );
	}

	// =========================================================================
	// render() Tests - Ticket Templates (JS)
	// =========================================================================

	/**
	 * Test render includes JavaScript templates for adding tickets.
	 *
	 * @return void
	 */
	public function test_render_includes_ticket_templates(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-ticket-template-occurrence', $output );
		$this->assertStringContainsString( '{{INDEX}}', $output );
	}

	// =========================================================================
	// render() Tests - Add Ticket Buttons
	// =========================================================================

	/**
	 * Test render shows add ticket button for single events.
	 *
	 * @return void
	 */
	public function test_render_shows_add_ticket_button_for_single_event(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-add-ticket', $output );
		$this->assertStringContainsString( 'Add Ticket Type', $output );
	}

	/**
	 * Test render shows add buttons for recurring events.
	 *
	 * @return void
	 */
	public function test_render_shows_add_buttons_for_recurring_event(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'recurring';

		$occurrence = new Occurrence();
		$occurrence->id = 100;
		$occurrence->start_datetime = '2026-01-15 19:00:00';

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );
		$this->ticket_type_repo->method( 'for_event' )->willReturn( array() );
		$this->ticket_type_repo->method( 'get_templates' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Add Series Pass', $output );
		$this->assertStringContainsString( 'Add Template', $output );
		$this->assertStringContainsString( 'Add Occurrence Ticket', $output );
	}

	// =========================================================================
	// render() Tests - Form Fields
	// =========================================================================

	/**
	 * Test render includes hidden fields for IDs.
	 *
	 * @return void
	 */
	public function test_render_includes_hidden_id_fields(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'occurrence_id_for_tickets', $output );
		$this->assertStringContainsString( 'value="100"', $output );
	}

	/**
	 * Test render includes ticket form fields.
	 *
	 * @return void
	 */
	public function test_render_includes_ticket_form_fields(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$ticket = new TicketType();
		$ticket->id = 1;
		$ticket->name = 'Test Ticket';
		$ticket->price = 25.00;
		$ticket->capacity_type = 'fixed';
		$ticket->capacity = 100;
		$ticket->min_per_order = 1;
		$ticket->max_per_order = 10;
		$ticket->description = 'Test description';

		$this->ticket_type_repo->method( 'for_occurrence' )
			->willReturn( array( $ticket ) );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => 100,
				'sold'                => 0,
				'available'           => 100,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => 100,
				'is_unlimited'        => false,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Ticket Name', $output );
		$this->assertStringContainsString( 'Price', $output );
		$this->assertStringContainsString( 'Capacity', $output );
		$this->assertStringContainsString( 'Min per Order', $output );
		$this->assertStringContainsString( 'Max per Order', $output );
		$this->assertStringContainsString( 'Sale starts', $output );
		$this->assertStringContainsString( 'Sale ends', $output );
		$this->assertStringContainsString( 'Description', $output );
	}

	// =========================================================================
	// render() Tests - Sale Dates
	// =========================================================================

	/**
	 * Test render shows sale date fields.
	 *
	 * @return void
	 */
	public function test_render_shows_sale_date_fields(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$ticket = new TicketType();
		$ticket->id = 1;
		$ticket->name = 'Test Ticket';
		$ticket->price = 25.00;
		$ticket->capacity_type = 'fixed';
		$ticket->sale_start = '2026-01-01 09:00:00';
		$ticket->sale_end = '2026-01-14 23:59:59';

		$this->ticket_type_repo->method( 'for_occurrence' )
			->willReturn( array( $ticket ) );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => null,
				'sold'                => 0,
				'available'           => null,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => null,
				'is_unlimited'        => true,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		// NTE-190: combined datetime-local spinner replaced by split
		// date + suggest-and-type time entry per boundary.
		$this->assertStringNotContainsString( 'datetime-local', $output );
		$this->assertStringContainsString( 'sale_start_date', $output );
		$this->assertStringContainsString( 'sale_start_time', $output );
		$this->assertStringContainsString( 'sale_end_date', $output );
		$this->assertStringContainsString( 'sale_end_time', $output );

		// Stored values decompose into the split fields.
		$this->assertStringContainsString( 'value="2026-01-01"', $output );
		$this->assertStringContainsString( 'value="09:00"', $output );
		$this->assertStringContainsString( 'value="2026-01-14"', $output );
		$this->assertStringContainsString( 'value="23:59"', $output );

		// Grouped + labeled boundaries with presets.
		$this->assertStringContainsString( 'Sale starts', $output );
		$this->assertStringContainsString( 'Sale ends', $output );
		$this->assertStringContainsString( 'data-nte-sale-preset="now"', $output );
		$this->assertStringContainsString( 'data-nte-sale-preset="event-start"', $output );
		$this->assertMatchesRegularExpression(
			'/name="[^"]*\[sale_start_time\]"[^>]*data-nte-time-combobox/s',
			$output
		);
	}

	// =========================================================================
	// render() Tests - Empty States
	// =========================================================================

	/**
	 * Test render shows empty state for series passes.
	 *
	 * @return void
	 */
	public function test_render_shows_empty_state_for_series_passes(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'recurring';

		$occurrence = new Occurrence();
		$occurrence->id = 100;
		$occurrence->start_datetime = '2026-01-15 19:00:00';

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );
		$this->ticket_type_repo->method( 'for_event' )->willReturn( array() );
		$this->ticket_type_repo->method( 'get_templates' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-empty-state', $output );
		$this->assertStringContainsString( 'No series passes configured', $output );
	}

	// =========================================================================
	// Multiple Tickets Tests
	// =========================================================================

	/**
	 * Test render handles multiple ticket types.
	 *
	 * @return void
	 */
	public function test_render_handles_multiple_tickets(): void {
		$event = new Event();
		$event->id = 1;
		$event->event_type = 'single';

		$occurrence = new Occurrence();
		$occurrence->id = 100;

		$ticket1 = new TicketType();
		$ticket1->id = 1;
		$ticket1->name = 'General Admission';
		$ticket1->price = 25.00;
		$ticket1->capacity_type = 'fixed';

		$ticket2 = new TicketType();
		$ticket2->id = 2;
		$ticket2->name = 'VIP';
		$ticket2->price = 75.00;
		$ticket2->capacity_type = 'fixed';

		$this->ticket_type_repo->method( 'for_occurrence' )
			->willReturn( array( $ticket1, $ticket2 ) );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => null,
				'sold'                => 0,
				'available'           => null,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => null,
				'is_unlimited'        => true,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		// Both ticket names should appear in the rendered output.
		$this->assertStringContainsString( 'General Admission', $output );
		$this->assertStringContainsString( 'VIP', $output );

		// Verify both tickets have their own ticket row containers.
		// The ticket name appears in the header span with class nte-ticket-name.
		$this->assertStringContainsString( '<span class="nte-ticket-name">', $output );

		// Verify hidden id fields for both tickets exist.
		$this->assertMatchesRegularExpression( '/value="1"/', $output );
		$this->assertMatchesRegularExpression( '/value="2"/', $output );
	}

	// =========================================================================
	// render() Tests - Multi-Date Single Event (NTE-156)
	// =========================================================================

	/**
	 * Single event with one scheduled date keeps the flat, tabless form.
	 *
	 * A single event whose scheduled-date count is not greater than one is NOT
	 * multi-date: it renders the Enable Ticketing toggle, never the tabs, and
	 * never reaches for the event-scoped (series-pass) fetch.
	 *
	 * Kills line 141/142 (is_multi_date &&/> mutants) via the flat branch, and
	 * every line 153 mutant via the never()-called for_event expectation: the
	 * flat single is the one case where the original never fetches series passes
	 * but each surviving mutant would.
	 *
	 * @return void
	 */
	public function test_render_single_one_scheduled_date_renders_flat_form(): void {
		$event             = new Event();
		$event->id         = 1;
		$event->event_type = 'single';

		$occurrence           = new Occurrence();
		$occurrence->id       = 100;
		$occurrence->event_id = 1;

		$this->occurrence_repo->expects( $this->once() )
			->method( 'count_for_event' )
			->with( 1, 'scheduled' )
			->willReturn( 1 );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $this->occurrence_repo );

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );
		// A one-date single must never fetch event-scoped series passes.
		$this->ticket_type_repo->expects( $this->never() )->method( 'for_event' );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		// Flat form: toggle present, no tabs, no forced ticketing_enabled input.
		$this->assertStringContainsString( 'nte-ticketing-toggle', $output );
		$this->assertStringContainsString( 'Enable Ticketing', $output );
		$this->assertStringNotContainsString( 'nte-ticket-tabs', $output );
		$this->assertStringNotContainsString( '<input type="hidden" name="ticketing_enabled" value="1">', $output );
	}

	/**
	 * Single event with more than one scheduled date renders the tabbed form.
	 *
	 * The multi-date single event (hand-picked extra dates) sells series passes
	 * like a recurring event, so it renders tabs, the forced ticketing_enabled
	 * hidden input, and the Series Passes tab — but NOT the Templates tab, since
	 * templates only act on pattern-generated dates.
	 *
	 * Kills line 141 (And negations → flat), line 142 (> vs >=, count is exactly
	 * 2 here but paired with the count==1 flat test), line 153 (OrSingle that
	 * suppresses the fetch), line 158 (scope-arg ArrayItem / removal via the
	 * strict-matched for_event expectation), and line 323 default indirectly via
	 * the absent Templates tab.
	 *
	 * @return void
	 */
	public function test_render_single_multi_date_renders_tabbed_series_passes(): void {
		$event             = new Event();
		$event->id         = 1;
		$event->event_type = 'single';

		$occurrence                 = new Occurrence();
		$occurrence->id             = 100;
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-01-15 19:00:00';

		$this->occurrence_repo->expects( $this->once() )
			->method( 'count_for_event' )
			->with( 1, 'scheduled' )
			->willReturn( 2 );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $this->occurrence_repo );

		$series_pass                = new TicketType();
		$series_pass->id            = 1;
		$series_pass->name          = 'Season Pass';
		$series_pass->scope         = 'event';
		$series_pass->price         = 200.00;
		$series_pass->capacity_type = 'fixed';

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );
		// The event-scoped fetch must be scoped to EVENT, never unscoped.
		$this->ticket_type_repo->expects( $this->once() )
			->method( 'for_event' )
			->with( 1, array( 'scope' => TicketTypeScope::EVENT->value ) )
			->willReturn( array( $series_pass ) );
		// A hand-picked-dates single has no pattern, so templates are never fetched.
		$this->ticket_type_repo->expects( $this->never() )->method( 'get_templates' );

		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'capacity'            => null,
				'sold'                => 0,
				'available'           => null,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => null,
				'is_unlimited'        => true,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			)
		);

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		// Tabbed form with the forced ticketing_enabled input and Series Passes tab.
		$this->assertStringContainsString( 'nte-ticket-tabs', $output );
		$this->assertStringContainsString( '<input type="hidden" name="ticketing_enabled" value="1">', $output );
		$this->assertStringContainsString( 'Series Passes', $output );
		$this->assertStringContainsString( 'Season Pass', $output );
		// No flat toggle, and no Templates tab for a hand-picked-dates single.
		$this->assertStringNotContainsString( 'nte-ticketing-toggle', $output );
		$this->assertStringNotContainsString( 'Ticket Templates', $output );
		// Scope receipts (NTE-178): the form vouches only for sections it drew —
		// event and occurrence here, never the unrendered template scope.
		$this->assertStringContainsString( 'ticket_types_rendered[event]', $output );
		$this->assertStringContainsString( 'ticket_types_rendered[occurrence]', $output );
		$this->assertStringNotContainsString( 'ticket_types_rendered[template]', $output );
	}

	/**
	 * Recurring event fetches event-scoped series passes and shows the Templates tab.
	 *
	 * Reinforces the line 158 scope-argument mutants on the recurring path and
	 * pins the line 323 show_templates=true default: a recurring event renders
	 * the Templates tab, distinguishing it from the multi-date single event.
	 *
	 * @return void
	 */
	public function test_render_recurring_fetches_event_scope_and_shows_templates(): void {
		$event             = new Event();
		$event->id         = 1;
		$event->event_type = 'recurring';

		$occurrence                 = new Occurrence();
		$occurrence->id             = 100;
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-01-15 19:00:00';

		// A recurring event does not consult the scheduled-date count.
		$this->occurrence_repo->expects( $this->never() )->method( 'count_for_event' );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $this->occurrence_repo );

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );
		$this->ticket_type_repo->expects( $this->once() )
			->method( 'for_event' )
			->with( 1, array( 'scope' => TicketTypeScope::EVENT->value ) )
			->willReturn( array() );
		$this->ticket_type_repo->method( 'get_templates' )->willReturn( array() );

		$this->metabox->set_context( $event, $occurrence );

		ob_start();
		$this->metabox->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-ticket-tabs', $output );
		$this->assertStringContainsString( 'Series Passes', $output );
		$this->assertStringContainsString( 'Ticket Templates', $output );
		// Scope receipt (NTE-178): a recurring event rendered its Templates tab,
		// so the form vouches for the template scope.
		$this->assertStringContainsString( 'ticket_types_rendered[template]', $output );
	}
}
