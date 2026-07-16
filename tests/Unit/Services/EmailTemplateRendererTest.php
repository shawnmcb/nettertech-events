<?php
/**
 * Tests for EmailTemplateRenderer.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\EmailTemplateRenderer;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use Brain\Monkey\Functions;

/**
 * Test cases for EmailTemplateRenderer.
 *
 * @covers \NetterTechEvents\Services\EmailTemplateRenderer
 */
class EmailTemplateRendererTest extends \NetterTechEventsTestCase {

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock EventRepository.
	 *
	 * @var EventRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Mock TicketTypeRepository.
	 *
	 * @var TicketTypeRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * System under test.
	 *
	 * @var EmailTemplateRenderer
	 */
	private EmailTemplateRenderer $renderer;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->occurrence_repo  = $this->createMock( OccurrenceRepository::class );
		$this->event_repo       = $this->createMock( EventRepository::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepository::class );

		$this->renderer = new EmailTemplateRenderer(
			$this->occurrence_repo,
			$this->event_repo,
			$this->ticket_type_repo
		);

		// Set up common function stubs.
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_option' )->justReturn( 'admin@test.com' );
		Functions\when( 'home_url' )->justReturn( 'https://test.local' );
		Functions\when( 'locate_template' )->justReturn( '' );
	}

	/**
	 * Create a mock WC_Order.
	 *
	 * @param array $args Override arguments.
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_order( array $args = array() ): \WC_Order {
		$order = $this->createMock( \WC_Order::class );

		$defaults = array(
			'id'                   => 123,
			'billing_first_name'   => 'John',
			'billing_last_name'    => 'Doe',
			'billing_email'        => 'john@example.com',
		);

		$args = array_merge( $defaults, $args );

		$order->method( 'get_id' )->willReturn( $args['id'] );
		$order->method( 'get_billing_first_name' )->willReturn( $args['billing_first_name'] );
		$order->method( 'get_billing_last_name' )->willReturn( $args['billing_last_name'] );
		$order->method( 'get_billing_email' )->willReturn( $args['billing_email'] );

		return $order;
	}

	/**
	 * Create a test ticket.
	 *
	 * @param array $args Override arguments.
	 * @return Ticket
	 */
	private function create_test_ticket( array $args = array() ): Ticket {
		$ticket = new Ticket();
		$ticket->id             = $args['id'] ?? 1;
		$ticket->occurrence_id  = $args['occurrence_id'] ?? 100;
		$ticket->ticket_type_id = $args['ticket_type_id'] ?? 10;
		$ticket->ticket_code    = $args['ticket_code'] ?? 'TEST-CODE-123';
		$ticket->price_paid     = $args['price_paid'] ?? 25.00;
		$ticket->status         = $args['status'] ?? 'confirmed';

		return $ticket;
	}

	/**
	 * Create a test event.
	 *
	 * @param array $args Override arguments.
	 * @return Event
	 */
	private function create_test_event( array $args = array() ): Event {
		$event = new Event();
		$event->id            = $args['id'] ?? 1;
		$event->title         = $args['title'] ?? 'Test Event';
		$event->description   = $args['description'] ?? 'Event description';
		$event->venue_name    = $args['venue_name'] ?? 'Test Venue';
		$event->venue_address = $args['venue_address'] ?? '123 Main St';

		return $event;
	}

	/**
	 * Create a test occurrence.
	 *
	 * @param array $args Override arguments.
	 * @return Occurrence
	 */
	private function create_test_occurrence( array $args = array() ): Occurrence {
		$occurrence = new Occurrence();
		$occurrence->id             = $args['id'] ?? 100;
		$occurrence->event_id       = $args['event_id'] ?? 1;
		$occurrence->start_datetime = $args['start_datetime'] ?? '2026-02-01 19:00:00';
		$occurrence->end_datetime   = $args['end_datetime'] ?? '2026-02-01 22:00:00';
		$occurrence->status         = $args['status'] ?? 'scheduled';

		return $occurrence;
	}

	/**
	 * Create a test ticket type.
	 *
	 * @param array $args Override arguments.
	 * @return TicketType
	 */
	private function create_test_ticket_type( array $args = array() ): TicketType {
		$ticket_type = new TicketType();
		$ticket_type->id    = $args['id'] ?? 10;
		$ticket_type->name  = $args['name'] ?? 'General Admission';
		$ticket_type->price = $args['price'] ?? 25.00;

		return $ticket_type;
	}

	/**
	 * Create a test attendee.
	 *
	 * @param array $args Override arguments.
	 * @return Attendee
	 */
	private function create_test_attendee( array $args = array() ): Attendee {
		$attendee                = new Attendee();
		$attendee->id            = $args['id'] ?? 1;
		$attendee->occurrence_id = $args['occurrence_id'] ?? 100;
		$attendee->name          = $args['name'] ?? 'Alice Johnson';
		$attendee->email         = $args['email'] ?? 'alice@example.com';
		$attendee->quantity      = $args['quantity'] ?? 1;

		return $attendee;
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor with injected dependencies.
	 */
	public function test_constructor_with_dependencies(): void {
		$renderer = new EmailTemplateRenderer(
			$this->occurrence_repo,
			$this->event_repo,
			$this->ticket_type_repo
		);

		$this->assertInstanceOf( EmailTemplateRenderer::class, $renderer );
	}

	/**
	 * Test constructor requires all dependencies.
	 */
	public function test_constructor_requires_all_dependencies(): void {
		$renderer = new EmailTemplateRenderer(
			$this->occurrence_repo,
			$this->event_repo,
			$this->ticket_type_repo
		);

		$this->assertInstanceOf( EmailTemplateRenderer::class, $renderer );
	}

	// =========================================================================
	// get_email_headers Tests
	// =========================================================================

	/**
	 * Test get_email_headers returns correct headers.
	 */
	public function test_get_email_headers_returns_html_content_type(): void {
		$headers = $this->renderer->get_email_headers();

		$this->assertIsArray( $headers );
		$this->assertCount( 2, $headers );
		$this->assertEquals( 'Content-Type: text/html; charset=UTF-8', $headers[0] );
		$this->assertStringContainsString( 'From:', $headers[1] );
	}

	// =========================================================================
	// get_customer_email_subject Tests
	// =========================================================================

	/**
	 * Test get_customer_email_subject with single ticket and event.
	 */
	public function test_get_customer_email_subject_single_ticket_with_event(): void {
		$order  = $this->create_mock_order();
		$ticket = $this->create_test_ticket();

		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event( array( 'title' => 'Concert Night' ) );

		$this->occurrence_repo->method( 'find' )
			->with( 100 )
			->willReturn( $occurrence );
		$this->event_repo->method( 'find' )
			->with( 1 )
			->willReturn( $event );

		$subject = $this->renderer->get_customer_email_subject( $order, array( $ticket ) );

		$this->assertStringContainsString( 'Concert Night', $subject );
	}

	/**
	 * Test get_customer_email_subject with multiple tickets.
	 */
	public function test_get_customer_email_subject_multiple_tickets(): void {
		$order   = $this->create_mock_order();
		$ticket1 = $this->create_test_ticket( array( 'id' => 1 ) );
		$ticket2 = $this->create_test_ticket( array( 'id' => 2 ) );
		$ticket3 = $this->create_test_ticket( array( 'id' => 3 ) );

		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event();

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );

		$subject = $this->renderer->get_customer_email_subject( $order, array( $ticket1, $ticket2, $ticket3 ) );

		$this->assertStringContainsString( '3', $subject );
		$this->assertStringContainsString( 'Ticket', $subject );
	}

	/**
	 * Test get_customer_email_subject with no tickets.
	 */
	public function test_get_customer_email_subject_no_tickets(): void {
		$order = $this->create_mock_order();

		$subject = $this->renderer->get_customer_email_subject( $order, array() );

		$this->assertStringContainsString( 'Ticket Confirmation', $subject );
	}

	/**
	 * Test get_customer_email_subject when occurrence not found.
	 */
	public function test_get_customer_email_subject_occurrence_not_found(): void {
		$order  = $this->create_mock_order();
		$ticket = $this->create_test_ticket();

		$this->occurrence_repo->method( 'find' )->willReturn( null );

		$subject = $this->renderer->get_customer_email_subject( $order, array( $ticket ) );

		$this->assertStringContainsString( 'Ticket Confirmation', $subject );
	}

	/**
	 * Test get_customer_email_subject when event not found.
	 */
	public function test_get_customer_email_subject_event_not_found(): void {
		$order  = $this->create_mock_order();
		$ticket = $this->create_test_ticket();

		$occurrence = $this->create_test_occurrence();

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( null );

		$subject = $this->renderer->get_customer_email_subject( $order, array( $ticket ) );

		$this->assertStringContainsString( 'Ticket Confirmation', $subject );
	}

	// =========================================================================
	// get_venue_email_subject Tests
	// =========================================================================

	/**
	 * Test get_venue_email_subject formats correctly.
	 */
	public function test_get_venue_email_subject_formats_correctly(): void {
		$order   = $this->create_mock_order( array(
			'billing_first_name' => 'Jane',
			'billing_last_name'  => 'Smith',
		) );
		$ticket1 = $this->create_test_ticket( array( 'id' => 1 ) );
		$ticket2 = $this->create_test_ticket( array( 'id' => 2 ) );

		$subject = $this->renderer->get_venue_email_subject( $order, array( $ticket1, $ticket2 ) );

		$this->assertStringContainsString( 'Jane Smith', $subject );
		$this->assertStringContainsString( '2', $subject );
	}

	// =========================================================================
	// render_email_template Tests
	// =========================================================================

	/**
	 * Test render_email_template returns empty string for missing template.
	 */
	public function test_render_email_template_returns_empty_for_missing_template(): void {
		$result = $this->renderer->render_email_template( 'nonexistent/template', array() );

		$this->assertEquals( '', $result );
	}

	// =========================================================================
	// get_rsvp_confirmation_subject Tests
	// =========================================================================

	/**
	 * Test get_rsvp_confirmation_subject with event found.
	 */
	public function test_get_rsvp_confirmation_subject_with_event(): void {
		$ticket     = $this->create_test_ticket();
		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event( array( 'title' => 'Jazz Night' ) );

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );

		$subject = $this->renderer->get_rsvp_confirmation_subject( $ticket );

		$this->assertStringContainsString( 'Jazz Night', $subject );
	}

	/**
	 * Test get_rsvp_confirmation_subject without event.
	 */
	public function test_get_rsvp_confirmation_subject_without_event(): void {
		$ticket = $this->create_test_ticket();

		$this->occurrence_repo->method( 'find' )->willReturn( null );

		$subject = $this->renderer->get_rsvp_confirmation_subject( $ticket );

		$this->assertStringContainsString( 'RSVP Confirmation', $subject );
	}

	// =========================================================================
	// get_rsvp_venue_notification_subject Tests
	// =========================================================================

	/**
	 * Test get_rsvp_venue_notification_subject with event and name.
	 */
	public function test_get_rsvp_venue_notification_subject_with_event_and_name(): void {
		$ticket     = $this->create_test_ticket();
		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event( array( 'title' => 'Folk Festival' ) );

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );

		$subject = $this->renderer->get_rsvp_venue_notification_subject( $ticket, 'Bob Wilson' );

		$this->assertStringContainsString( 'Bob Wilson', $subject );
		$this->assertStringContainsString( 'Folk Festival', $subject );
	}

	/**
	 * Test get_rsvp_venue_notification_subject without name uses Guest.
	 */
	public function test_get_rsvp_venue_notification_subject_without_name(): void {
		$ticket     = $this->create_test_ticket();
		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event();

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );

		$subject = $this->renderer->get_rsvp_venue_notification_subject( $ticket, '' );

		$this->assertStringContainsString( 'Guest', $subject );
	}

	/**
	 * Test get_rsvp_venue_notification_subject without event.
	 */
	public function test_get_rsvp_venue_notification_subject_without_event(): void {
		$ticket = $this->create_test_ticket();

		$this->occurrence_repo->method( 'find' )->willReturn( null );

		$subject = $this->renderer->get_rsvp_venue_notification_subject( $ticket, 'John' );

		$this->assertStringContainsString( 'an event', $subject );
	}

	// =========================================================================
	// group_tickets_by_event Tests
	// =========================================================================

	/**
	 * Test group_tickets_by_event groups by occurrence.
	 */
	public function test_group_tickets_by_event_groups_by_occurrence(): void {
		$ticket1 = $this->create_test_ticket( array( 'id' => 1, 'occurrence_id' => 100 ) );
		$ticket2 = $this->create_test_ticket( array( 'id' => 2, 'occurrence_id' => 100 ) );
		$ticket3 = $this->create_test_ticket( array( 'id' => 3, 'occurrence_id' => 200 ) );

		$occ100 = $this->create_test_occurrence( array( 'id' => 100, 'event_id' => 1 ) );
		$occ200 = $this->create_test_occurrence( array( 'id' => 200, 'event_id' => 2 ) );

		$event1 = $this->create_test_event( array( 'id' => 1, 'title' => 'Event One' ) );
		$event2 = $this->create_test_event( array( 'id' => 2, 'title' => 'Event Two' ) );

		$ticket_type = $this->create_test_ticket_type();

		$this->occurrence_repo->method( 'find' )
			->willReturnCallback( function ( $id ) use ( $occ100, $occ200 ) {
				return $id === 100 ? $occ100 : ( $id === 200 ? $occ200 : null );
			} );

		$this->event_repo->method( 'find' )
			->willReturnCallback( function ( $id ) use ( $event1, $event2 ) {
				return $id === 1 ? $event1 : ( $id === 2 ? $event2 : null );
			} );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$grouped = $this->renderer->group_tickets_by_event( array( $ticket1, $ticket2, $ticket3 ) );

		$this->assertCount( 2, $grouped );
		$this->assertArrayHasKey( 100, $grouped );
		$this->assertArrayHasKey( 200, $grouped );
		$this->assertCount( 2, $grouped[100]['tickets'] );
		$this->assertCount( 1, $grouped[200]['tickets'] );
	}

	/**
	 * Test group_tickets_by_event with empty array.
	 */
	public function test_group_tickets_by_event_empty_array(): void {
		$grouped = $this->renderer->group_tickets_by_event( array() );

		$this->assertIsArray( $grouped );
		$this->assertEmpty( $grouped );
	}

	/**
	 * Test group_tickets_by_event includes event and ticket type data.
	 */
	public function test_group_tickets_by_event_includes_event_data(): void {
		$ticket     = $this->create_test_ticket();
		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event( array( 'title' => 'My Event' ) );
		$ticket_type = $this->create_test_ticket_type( array( 'name' => 'VIP' ) );

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );
		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$grouped = $this->renderer->group_tickets_by_event( array( $ticket ) );

		$this->assertEquals( 'My Event', $grouped[100]['event']->title );
		$this->assertEquals( 'VIP', $grouped[100]['ticket_type']->name );
	}

	/**
	 * Test group_tickets_by_event handles missing occurrence.
	 */
	public function test_group_tickets_by_event_handles_missing_occurrence(): void {
		$ticket = $this->create_test_ticket();

		$this->occurrence_repo->method( 'find' )->willReturn( null );
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$grouped = $this->renderer->group_tickets_by_event( array( $ticket ) );

		$this->assertArrayHasKey( 100, $grouped );
		$this->assertNull( $grouped[100]['event'] );
		$this->assertNull( $grouped[100]['occurrence'] );
	}

	/**
	 * Test group_tickets_by_event handles ticket without ticket_type_id.
	 */
	public function test_group_tickets_by_event_handles_no_ticket_type(): void {
		$ticket = $this->create_test_ticket( array( 'ticket_type_id' => 0 ) );
		$occurrence = $this->create_test_occurrence();
		$event = $this->create_test_event();

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );

		$grouped = $this->renderer->group_tickets_by_event( array( $ticket ) );

		$this->assertNull( $grouped[100]['ticket_type'] );
	}

	// =========================================================================
	// render_customer_email Tests
	// =========================================================================

	/**
	 * Create a mock WC_Order with get_order_number support.
	 *
	 * The bootstrap WC_Order mock lacks get_order_number, so this uses
	 * getMockBuilder with addMethods to include it alongside onlyMethods
	 * for the existing methods we need to configure.
	 *
	 * @param array $args Override arguments.
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_order_with_order_number( array $args = array() ): \WC_Order {
		$defaults = array(
			'id'                   => 123,
			'billing_first_name'   => 'John',
			'billing_last_name'    => 'Doe',
			'billing_email'        => 'john@example.com',
		);

		$args = array_merge( $defaults, $args );

		$order = $this->getMockBuilder( \WC_Order::class )
			->onlyMethods( array( 'get_id', 'get_billing_first_name', 'get_billing_last_name', 'get_billing_email' ) )
			->addMethods( array( 'get_order_number' ) )
			->getMock();

		$order->method( 'get_id' )->willReturn( $args['id'] );
		$order->method( 'get_billing_first_name' )->willReturn( $args['billing_first_name'] );
		$order->method( 'get_billing_last_name' )->willReturn( $args['billing_last_name'] );
		$order->method( 'get_billing_email' )->willReturn( $args['billing_email'] );
		$order->method( 'get_order_number' )->willReturn( (string) $args['id'] );

		return $order;
	}

	/**
	 * Test render_customer_email returns HTML with ticket info.
	 */
	public function test_render_customer_email_returns_html_with_ticket_info(): void {
		$order = $this->create_mock_order_with_order_number( array(
			'billing_first_name' => 'John',
			'billing_last_name'  => 'Doe',
		) );

		$ticket      = $this->create_test_ticket();
		$occurrence  = $this->create_test_occurrence();
		$event       = $this->create_test_event( array( 'title' => 'Live Concert' ) );
		$ticket_type = $this->create_test_ticket_type( array( 'name' => 'General Admission' ) );

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );
		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$html = $this->renderer->render_customer_email( $order, array( $ticket ) );

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'Live Concert', $html );
		$this->assertStringContainsString( 'Test Site', $html );
		$this->assertStringContainsString( 'General Admission', $html );
		$this->assertStringContainsString( 'John', $html );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
	}

	/**
	 * Test render_customer_email passes settings to template.
	 */
	public function test_render_customer_email_passes_settings(): void {
		$order = $this->create_mock_order_with_order_number( array(
			'id'                 => 456,
			'billing_first_name' => 'Jane',
			'billing_last_name'  => 'Smith',
			'billing_email'      => 'jane@example.com',
		) );

		$ticket     = $this->create_test_ticket();
		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event();

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$settings = array(
			'venue_logo'          => 'https://example.com/logo.png',
			'show_qr_codes'       => false,
			'cancellation_policy' => 'No refunds within 24 hours.',
		);

		$html = $this->renderer->render_customer_email( $order, array( $ticket ), $settings );

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'https://example.com/logo.png', $html );
		$this->assertStringContainsString( 'No refunds within 24 hours.', $html );
	}

	// =========================================================================
	// render_venue_email Tests
	// =========================================================================

	/**
	 * Test render_venue_email returns HTML with buyer name and revenue.
	 */
	public function test_render_venue_email_returns_html_with_buyer_info(): void {
		Functions\when( 'wp_kses_post' )->returnArg();

		$order = $this->create_mock_order_with_order_number( array(
			'id'                 => 789,
			'billing_first_name' => 'Bob',
			'billing_last_name'  => 'Wilson',
			'billing_email'      => 'bob@example.com',
		) );

		$ticket     = $this->create_test_ticket( array( 'price_paid' => 50.00 ) );
		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event( array( 'title' => 'Jazz Night' ) );

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$html = $this->renderer->render_venue_email( $order, array( $ticket ) );

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'Bob Wilson', $html );
		$this->assertStringContainsString( 'Jazz Night', $html );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
	}

	/**
	 * Test render_venue_email calculates total_revenue from multiple tickets.
	 */
	public function test_render_venue_email_calculates_total_revenue(): void {
		Functions\when( 'wp_kses_post' )->returnArg();

		$order = $this->create_mock_order_with_order_number( array(
			'id'                 => 100,
			'billing_first_name' => 'Alice',
			'billing_last_name'  => 'Brown',
			'billing_email'      => 'alice@example.com',
		) );

		$ticket1 = $this->create_test_ticket( array( 'id' => 1, 'price_paid' => 25.00 ) );
		$ticket2 = $this->create_test_ticket( array( 'id' => 2, 'price_paid' => 35.00 ) );
		$ticket3 = $this->create_test_ticket( array( 'id' => 3, 'price_paid' => 40.00 ) );

		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event();

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$html = $this->renderer->render_venue_email( $order, array( $ticket1, $ticket2, $ticket3 ) );

		$this->assertNotEmpty( $html );
		// Total revenue: 25 + 35 + 40 = 100, rendered via wc_price as $100.00.
		$this->assertStringContainsString( '$100.00', $html );
	}

	// =========================================================================
	// render_rsvp_confirmation Tests
	// =========================================================================

	/**
	 * Test render_rsvp_confirmation with found occurrence and event.
	 */
	public function test_render_rsvp_confirmation_with_occurrence_and_event(): void {
		$ticket     = $this->create_test_ticket();
		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event( array( 'title' => 'Celtic Music Night' ) );

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );

		$html = $this->renderer->render_rsvp_confirmation( $ticket, array(), 'Mary O\'Brien' );

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'Celtic Music Night', $html );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'RSVP', $html );
	}

	/**
	 * Test render_rsvp_confirmation handles missing occurrence.
	 */
	public function test_render_rsvp_confirmation_handles_missing_occurrence(): void {
		$ticket = $this->create_test_ticket();

		$this->occurrence_repo->method( 'find' )->willReturn( null );

		$html = $this->renderer->render_rsvp_confirmation( $ticket, array(), 'Guest' );

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
	}

	// =========================================================================
	// render_rsvp_venue_notification Tests
	// =========================================================================

	/**
	 * Test render_rsvp_venue_notification renders with attendee info.
	 */
	public function test_render_rsvp_venue_notification_with_attendee_info(): void {
		$ticket     = $this->create_test_ticket();
		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event( array( 'title' => 'Folk Session' ) );

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );

		$html = $this->renderer->render_rsvp_venue_notification(
			$ticket,
			'Patrick Byrne',
			'patrick@example.com'
		);

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'Patrick Byrne', $html );
		$this->assertStringContainsString( 'patrick@example.com', $html );
		$this->assertStringContainsString( 'Folk Session', $html );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
	}

	// =========================================================================
	// get_reminder_subject Tests
	// =========================================================================

	/**
	 * Test get_reminder_subject returns subject with event title and date.
	 */
	public function test_get_reminder_subject_returns_title_and_date(): void {
		$event      = $this->create_test_event( array( 'title' => 'Irish Dancing' ) );
		$occurrence = $this->create_test_occurrence( array(
			'start_datetime' => '2026-03-15 19:00:00',
		) );

		$subject = $this->renderer->get_reminder_subject( $event, $occurrence );

		$this->assertStringContainsString( 'Reminder', $subject );
		$this->assertStringContainsString( 'Irish Dancing', $subject );
		$this->assertStringContainsString( 'Mar 15, 2026', $subject );
	}

	// =========================================================================
	// render_reminder_email Tests
	// =========================================================================

	/**
	 * Test render_reminder_email renders HTML.
	 */
	public function test_render_reminder_email_renders_html(): void {
		Functions\when( 'get_permalink' )->justReturn( 'https://test.local/events/fiddle-workshop/' );

		$occurrence = $this->create_test_occurrence( array(
			'start_datetime' => '2026-04-10 18:00:00',
			'end_datetime'   => '2026-04-10 20:00:00',
		) );

		$event          = $this->create_test_event( array( 'title' => 'Fiddle Workshop' ) );
		$event->post_id = 5;
		$event->slug    = 'fiddle-workshop';

		$attendee = $this->create_test_attendee( array(
			'name'  => 'Siobhan Kelly',
			'email' => 'siobhan@example.com',
		) );

		$html = $this->renderer->render_reminder_email( $occurrence, $event, $attendee );

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'Fiddle Workshop', $html );
		$this->assertStringContainsString( 'Siobhan Kelly', $html );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'Event Reminder', $html );
	}

}
