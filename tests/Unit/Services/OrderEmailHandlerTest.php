<?php
/**
 * OrderEmailHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\EmailTemplateRendererInterface;
use NetterTechEvents\Contracts\IcsGeneratorInterface;
use NetterTechEvents\Contracts\QRCodeServiceInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Services\EmailConfig;
use NetterTechEvents\Services\OrderEmailHandler;
use NetterTechEvents\Services\PdfTicketService;

/**
 * Test OrderEmailHandler functionality.
 *
 * Tests WooCommerce order email flows: customer confirmation,
 * venue notifications, resend logic, and AJAX handler.
 */
class OrderEmailHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Handler under test.
	 *
	 * @var OrderEmailHandler
	 */
	private OrderEmailHandler $handler;

	/**
	 * Mock QR code service.
	 *
	 * @var QRCodeServiceInterface|Mockery\MockInterface
	 */
	private $qr_service;

	/**
	 * Mock ticket repository.
	 *
	 * @var TicketRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_repo;

	/**
	 * Mock email template renderer.
	 *
	 * @var EmailTemplateRendererInterface|Mockery\MockInterface
	 */
	private $renderer;

	/**
	 * Mock email configuration.
	 *
	 * @var EmailConfig|Mockery\MockInterface
	 */
	private $email_config;

	/**
	 * Mock ICS generator.
	 *
	 * @var IcsGeneratorInterface|Mockery\MockInterface
	 */
	private $ics_generator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->qr_service    = Mockery::mock( QRCodeServiceInterface::class );
		$this->ticket_repo   = Mockery::mock( TicketRepositoryInterface::class );
		$this->renderer      = Mockery::mock( EmailTemplateRendererInterface::class );
		$this->email_config  = Mockery::mock( EmailConfig::class );
		$this->ics_generator = Mockery::mock( IcsGeneratorInterface::class );

		$this->handler = new OrderEmailHandler(
			$this->qr_service,
			$this->ticket_repo,
			$this->renderer,
			$this->email_config,
			$this->ics_generator
		);
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test handler can be instantiated.
	 *
	 * @return void
	 */
	public function test_handler_can_be_instantiated(): void {
		$this->assertInstanceOf( OrderEmailHandler::class, $this->handler );
	}

	// =========================================================================
	// register() Tests
	// =========================================================================

	/**
	 * Test register hooks WooCommerce order status actions.
	 *
	 * @return void
	 */
	public function test_register_hooks_order_status_actions(): void {
		$hooks_added = [];

		Functions\when( 'add_action' )->alias(
			function ( $hook ) use ( &$hooks_added ) {
				$hooks_added[] = $hook;
				return true;
			}
		);

		$this->handler->register();

		$this->assertContains( 'woocommerce_order_status_processing', $hooks_added );
		$this->assertContains( 'woocommerce_order_status_completed', $hooks_added );
		$this->assertContains( 'wp_ajax_nettertech_events_resend_confirmation_email', $hooks_added );
	}

	// =========================================================================
	// handle_order_completed() Tests
	// =========================================================================

	/**
	 * Test returns early for invalid order.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_returns_early_for_invalid_order(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->ticket_repo->shouldNotReceive( 'find_by_order' );

		$this->handler->handle_order_completed( 999 );
	}

	/**
	 * Test returns early when confirmation already sent.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_returns_early_when_already_sent(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )->willReturnCallback( function ( $key ) {
			return $key === MetaKeys::CONFIRMATION_EMAIL_SENT ? 'yes' : '';
		} );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->ticket_repo->shouldNotReceive( 'find_by_order' );

		$this->handler->handle_order_completed( 1 );
	}

	/**
	 * Test returns early when no tickets found.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_returns_early_when_no_tickets(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->method( 'get_id' )->willReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->ticket_repo->shouldReceive( 'find_by_order' )
			->once()
			->with( 1 )
			->andReturn( [] );

		$this->email_config->shouldNotReceive( 'is_customer_email_disabled' );

		$this->handler->handle_order_completed( 1 );
	}

	/**
	 * Test sends emails and marks order when both customer and venue emails succeed.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_sends_emails_and_marks_order(): void {
		$meta_store = [];
		$order      = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )->willReturnCallback( function ( $key ) use ( &$meta_store ) {
			return $meta_store[ $key ] ?? '';
		} );
		$order->method( 'update_meta_data' )->willReturnCallback( function ( $key, $value ) use ( &$meta_store ) {
			$meta_store[ $key ] = $value;
		} );
		$order->method( 'get_id' )->willReturn( 1 );
		$order->method( 'get_billing_email' )->willReturn( 'customer@example.com' );
		$order->expects( $this->once() )->method( 'save' );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = 'https://example.com/qr.png';

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'wp_delete_file' )->justReturn();
		Functions\when( 'file_exists' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-03-31 12:00:00' );

		$this->ticket_repo->shouldReceive( 'find_by_order' )
			->once()
			->andReturn( [ $ticket ] );

		$this->email_config->shouldReceive( 'is_customer_email_disabled' )->andReturn( false );
		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( [] );
		$this->email_config->shouldReceive( 'get_venue_notification_recipients' )->andReturn( [] );

		$this->renderer->shouldReceive( 'get_customer_email_subject' )->andReturn( 'Your Tickets' );
		$this->renderer->shouldReceive( 'render_customer_email' )->andReturn( '<html>Tickets</html>' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [ 'Content-Type: text/html' ] );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );

		$this->handler->handle_order_completed( 1 );

		$this->assertSame( 'yes', $meta_store[ MetaKeys::CONFIRMATION_EMAIL_SENT ] ?? '' );
	}

	/**
	 * Test generates QR codes for tickets without them.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_generates_qr_codes_when_missing(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->method( 'get_id' )->willReturn( 1 );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = '';

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'current_time' )->justReturn( '2026-03-31' );

		$this->ticket_repo->shouldReceive( 'find_by_order' )->andReturn( [ $ticket ] );

		$this->qr_service->shouldReceive( 'generate_for_ticket' )
			->once()
			->with( $ticket );

		$this->email_config->shouldReceive( 'is_customer_email_disabled' )->andReturn( true );
		$this->email_config->shouldReceive( 'get_venue_notification_recipients' )->andReturn( [] );

		$this->handler->handle_order_completed( 1 );
	}

	/**
	 * Test skips customer email when disabled.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_skips_customer_email_when_disabled(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->method( 'get_id' )->willReturn( 1 );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = 'https://example.com/qr.png';

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->ticket_repo->shouldReceive( 'find_by_order' )->andReturn( [ $ticket ] );

		$this->email_config->shouldReceive( 'is_customer_email_disabled' )->andReturn( true );
		$this->email_config->shouldReceive( 'get_venue_notification_recipients' )->andReturn( [] );

		$this->renderer->shouldNotReceive( 'render_customer_email' );

		$this->handler->handle_order_completed( 1 );
	}

	// =========================================================================
	// send_customer_confirmation() Tests
	// =========================================================================

	/**
	 * Test returns true when wp_mail succeeds.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_returns_true_on_success(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
		$order->method( 'get_id' )->willReturn( 1 );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = 'https://example.com/qr.png';

		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'wp_delete_file' )->justReturn();
		Functions\when( 'file_exists' )->justReturn( false );

		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( [] );
		$this->renderer->shouldReceive( 'get_customer_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_customer_email' )->andReturn( '<html>Body</html>' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );

		$result = $this->handler->send_customer_confirmation( $order, [ $ticket ] );

		$this->assertTrue( $result );
	}

	/**
	 * Test returns false when wp_mail fails.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_returns_false_on_failure(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
		$order->method( 'get_id' )->willReturn( 1 );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = 'https://example.com/qr.png';

		Functions\when( 'wp_mail' )->justReturn( false );
		Functions\when( 'wp_delete_file' )->justReturn();
		Functions\when( 'file_exists' )->justReturn( false );

		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( [] );
		$this->renderer->shouldReceive( 'get_customer_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_customer_email' )->andReturn( '<html>Body</html>' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );

		$result = $this->handler->send_customer_confirmation( $order, [ $ticket ] );

		$this->assertFalse( $result );
	}

	/**
	 * Test handles exception from wp_mail gracefully.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_handles_exception(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
		$order->method( 'get_id' )->willReturn( 1 );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = 'https://example.com/qr.png';

		Functions\when( 'wp_mail' )->alias( function () {
			throw new \RuntimeException( 'Mail error' );
		} );
		Functions\when( 'wp_delete_file' )->justReturn();
		Functions\when( 'file_exists' )->justReturn( false );

		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( [] );
		$this->renderer->shouldReceive( 'get_customer_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_customer_email' )->andReturn( '<html>Body</html>' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );

		$result = $this->handler->send_customer_confirmation( $order, [ $ticket ] );

		$this->assertFalse( $result );
	}

	/**
	 * Test includes PDF attachment when pdf service is set.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_includes_pdf_attachment(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
		$order->method( 'get_id' )->willReturn( 1 );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = 'https://example.com/qr.png';

		$pdf_service = Mockery::mock( PdfTicketService::class );
		$pdf_service->shouldReceive( 'generate_pdf' )->once()->andReturn( 'pdf-content' );
		$pdf_service->shouldReceive( 'save_to_temp' )->once()->andReturn( '/tmp/tickets.pdf' );

		$this->handler->set_pdf_service( $pdf_service );

		Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body, $headers, $attachments ) {
			return in_array( '/tmp/tickets.pdf', $attachments, true );
		} );
		Functions\when( 'wp_delete_file' )->justReturn();
		Functions\when( 'file_exists' )->justReturn( false );

		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( [] );
		$this->renderer->shouldReceive( 'get_customer_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_customer_email' )->andReturn( 'Body' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );

		$result = $this->handler->send_customer_confirmation( $order, [ $ticket ] );

		$this->assertTrue( $result );
	}

	/**
	 * Test skips PDF when generation fails.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_skips_pdf_when_generation_fails(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
		$order->method( 'get_id' )->willReturn( 1 );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = 'https://example.com/qr.png';

		$pdf_service = Mockery::mock( PdfTicketService::class );
		$pdf_service->shouldReceive( 'generate_pdf' )->once()->andReturn( false );
		$this->handler->set_pdf_service( $pdf_service );

		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'wp_delete_file' )->justReturn();
		Functions\when( 'file_exists' )->justReturn( false );

		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( [] );
		$this->renderer->shouldReceive( 'get_customer_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_customer_email' )->andReturn( 'Body' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );

		$result = $this->handler->send_customer_confirmation( $order, [ $ticket ] );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// send_venue_notifications() Tests
	// =========================================================================

	/**
	 * Test returns false when no recipients.
	 *
	 * @return void
	 */
	public function test_send_venue_notifications_returns_false_when_no_recipients(): void {
		$order = $this->createMock( \WC_Order::class );

		$this->email_config->shouldReceive( 'get_venue_notification_recipients' )
			->once()
			->andReturn( [] );

		$result = $this->handler->send_venue_notifications( $order, [] );

		$this->assertFalse( $result );
	}

	/**
	 * Test sends to all recipients.
	 *
	 * @return void
	 */
	public function test_send_venue_notifications_sends_to_all_recipients(): void {
		$order  = $this->createMock( \WC_Order::class );
		$ticket = new Ticket();

		$this->email_config->shouldReceive( 'get_venue_notification_recipients' )
			->once()
			->andReturn( [ 'venue1@example.com', 'venue2@example.com' ] );

		$this->renderer->shouldReceive( 'get_venue_email_subject' )->andReturn( 'New Order' );
		$this->renderer->shouldReceive( 'render_venue_email' )->andReturn( '<html>Venue</html>' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );

		Functions\when( 'wp_mail' )->justReturn( true );

		$result = $this->handler->send_venue_notifications( $order, [ $ticket ] );

		$this->assertTrue( $result );
	}

	/**
	 * Test returns true when at least one recipient succeeds.
	 *
	 * @return void
	 */
	public function test_send_venue_notifications_returns_true_when_partial_success(): void {
		$order  = $this->createMock( \WC_Order::class );
		$ticket = new Ticket();

		$this->email_config->shouldReceive( 'get_venue_notification_recipients' )
			->once()
			->andReturn( [ 'fail@example.com', 'success@example.com' ] );

		$this->renderer->shouldReceive( 'get_venue_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_venue_email' )->andReturn( 'Body' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );

		Functions\when( 'wp_mail' )->alias( function ( $to ) {
			return $to === 'success@example.com';
		} );

		$result = $this->handler->send_venue_notifications( $order, [ $ticket ] );

		$this->assertTrue( $result );
	}

	/**
	 * Test handles exception from wp_mail gracefully.
	 *
	 * @return void
	 */
	public function test_send_venue_notifications_handles_exception_gracefully(): void {
		$order  = $this->createMock( \WC_Order::class );
		$ticket = new Ticket();

		$this->email_config->shouldReceive( 'get_venue_notification_recipients' )
			->once()
			->andReturn( [ 'fail@example.com' ] );

		$this->renderer->shouldReceive( 'get_venue_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_venue_email' )->andReturn( 'Body' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );

		Functions\when( 'wp_mail' )->alias( function () {
			throw new \RuntimeException( 'SMTP error' );
		} );

		$result = $this->handler->send_venue_notifications( $order, [ $ticket ] );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// resend_confirmation() Tests
	// =========================================================================

	/**
	 * Test fails for invalid order.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_fails_for_invalid_order(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$result = $this->handler->resend_confirmation( 999 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Order not found.', $result['message'] );
	}

	/**
	 * Test fails when no tickets found.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_fails_when_no_tickets(): void {
		$order = $this->createMock( \WC_Order::class );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->ticket_repo->shouldReceive( 'find_by_order' )
			->once()
			->andReturn( [] );

		$result = $this->handler->resend_confirmation( 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'No tickets found for this order.', $result['message'] );
	}

	/**
	 * Test succeeds and updates resent metadata.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_succeeds_and_updates_meta(): void {
		$meta_store = [];
		$order      = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
		$order->method( 'get_id' )->willReturn( 1 );
		$order->method( 'update_meta_data' )->willReturnCallback( function ( $key, $value ) use ( &$meta_store ) {
			$meta_store[ $key ] = $value;
		} );
		$order->expects( $this->once() )->method( 'save' );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = 'https://example.com/qr.png';

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'wp_delete_file' )->justReturn();
		Functions\when( 'file_exists' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-03-31 14:00:00' );

		$this->ticket_repo->shouldReceive( 'find_by_order' )->andReturn( [ $ticket ] );

		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( [] );
		$this->renderer->shouldReceive( 'get_customer_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_customer_email' )->andReturn( 'Body' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );

		$result = $this->handler->resend_confirmation( 1 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Confirmation email resent successfully.', $result['message'] );
		$this->assertSame( '2026-03-31 14:00:00', $meta_store[ MetaKeys::CONFIRMATION_EMAIL_RESENT_AT ] ?? '' );
	}

	/**
	 * Test returns failure when send fails.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_returns_failure_when_send_fails(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
		$order->method( 'get_id' )->willReturn( 1 );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = 'https://example.com/qr.png';

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wp_mail' )->justReturn( false );
		Functions\when( 'wp_delete_file' )->justReturn();
		Functions\when( 'file_exists' )->justReturn( false );

		$this->ticket_repo->shouldReceive( 'find_by_order' )->andReturn( [ $ticket ] );

		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( [] );
		$this->renderer->shouldReceive( 'get_customer_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_customer_email' )->andReturn( 'Body' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );

		$result = $this->handler->resend_confirmation( 1 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Failed to send email', $result['message'] );
	}

	/**
	 * Test generates QR codes for tickets missing them.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_generates_missing_qr_codes(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
		$order->method( 'get_id' )->willReturn( 1 );

		$ticket              = new Ticket();
		$ticket->id          = 1;
		$ticket->qr_code_url = '';

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wp_mail' )->justReturn( false );
		Functions\when( 'wp_delete_file' )->justReturn();
		Functions\when( 'file_exists' )->justReturn( false );

		$this->ticket_repo->shouldReceive( 'find_by_order' )->andReturn( [ $ticket ] );
		$this->qr_service->shouldReceive( 'generate_for_ticket' )->once()->with( $ticket );

		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( [] );
		$this->renderer->shouldReceive( 'get_customer_email_subject' )->andReturn( 'Subject' );
		$this->renderer->shouldReceive( 'render_customer_email' )->andReturn( 'Body' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( [] );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );

		$this->handler->resend_confirmation( 1 );
	}
}
