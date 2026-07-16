<?php
/**
 * Email handler degradation tests — Pro absent.
 *
 * Proves OrderEmailHandler and RsvpEmailHandler work correctly when
 * QRCodeServiceInterface and PdfTicketService are null (Pro absent).
 *
 * @package NetterTechEvents\Tests\Unit\Degradation
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Degradation;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\EmailTemplateRendererInterface;
use NetterTechEvents\Contracts\IcsGeneratorInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Services\EmailConfig;
use NetterTechEvents\Services\OrderEmailHandler;
use NetterTechEvents\Services\RsvpEmailHandler;
use NetterTechEvents\Services\TicketCodeGenerator;

/**
 * Verify email handlers operate correctly without Pro services.
 *
 * @covers \NetterTechEvents\Services\OrderEmailHandler
 * @covers \NetterTechEvents\Services\RsvpEmailHandler
 */
class ProAbsentEmailTest extends \NetterTechEventsTestCase {

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
	 * Real ticket code generator (pure random-bytes utility; safe to instantiate).
	 *
	 * @var TicketCodeGenerator
	 */
	private TicketCodeGenerator $code_generator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->ticket_repo    = Mockery::mock( TicketRepositoryInterface::class );
		$this->renderer       = Mockery::mock( EmailTemplateRendererInterface::class );
		$this->email_config   = Mockery::mock( EmailConfig::class );
		$this->ics_generator  = Mockery::mock( IcsGeneratorInterface::class );
		$this->code_generator = new TicketCodeGenerator();
	}

	// =========================================================================
	// OrderEmailHandler — null QR service
	// =========================================================================

	/**
	 * Test OrderEmailHandler can be constructed with null QR service.
	 *
	 * @return void
	 */
	public function test_order_handler_instantiates_with_null_qr_service(): void {
		$handler = new OrderEmailHandler(
			null,
			$this->ticket_repo,
			$this->renderer,
			$this->email_config,
			$this->ics_generator
		);

		$this->assertInstanceOf( OrderEmailHandler::class, $handler );
	}

	/**
	 * Test OrderEmailHandler with null PDF service (no set_pdf_service call).
	 *
	 * The handler must accept no PDF service at all.
	 *
	 * @return void
	 */
	public function test_order_handler_instantiates_without_pdf_service(): void {
		$handler = new OrderEmailHandler(
			null,
			$this->ticket_repo,
			$this->renderer,
			$this->email_config,
			$this->ics_generator
		);

		// pdf_service defaults to null; set_pdf_service is never called.
		$this->assertInstanceOf( OrderEmailHandler::class, $handler );
	}

	/**
	 * Test register() on OrderEmailHandler wires WooCommerce hooks without errors.
	 *
	 * @return void
	 */
	public function test_order_handler_register_adds_hooks_without_errors(): void {
		$handler = new OrderEmailHandler(
			null,
			$this->ticket_repo,
			$this->renderer,
			$this->email_config,
			$this->ics_generator
		);

		// add_action is already stubbed by the base test case; just confirm no exception.
		$handler->register();

		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// RsvpEmailHandler — null QR service
	// =========================================================================

	/**
	 * Test RsvpEmailHandler can be constructed with null QR service.
	 *
	 * @return void
	 */
	public function test_rsvp_handler_instantiates_with_null_qr_service(): void {
		$handler = new RsvpEmailHandler(
			null,
			$this->code_generator,
			$this->ticket_repo,
			$this->renderer,
			$this->email_config,
			$this->ics_generator
		);

		$this->assertInstanceOf( RsvpEmailHandler::class, $handler );
	}

	/**
	 * Test RSVP handler uses TicketCodeGenerator for ticket codes when QR service is null.
	 *
	 * Ticket code generation is now a base concern (TicketCodeGenerator), so RSVP
	 * tickets get canonical XXXX-XXXX-XXXX-XXXX codes regardless of Pro state.
	 *
	 * @return void
	 */
	public function test_rsvp_handler_uses_ticket_code_generator_when_qr_absent(): void {
		$handler = new RsvpEmailHandler(
			null,
			$this->code_generator,
			$this->ticket_repo,
			$this->renderer,
			$this->email_config,
			$this->ics_generator
		);

		$this->email_config->shouldReceive( 'is_customer_email_disabled' )->andReturn( false );
		$this->renderer->shouldReceive( 'get_rsvp_confirmation_subject' )->andReturn( 'Your ticket' );
		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( array() );
		$this->renderer->shouldReceive( 'render_rsvp_confirmation' )->andReturn( '<p>Confirmed</p>' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( array() );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );
		$this->renderer->shouldReceive( 'get_rsvp_venue_notification_subject' )->andReturn( 'Venue notification' );
		$this->renderer->shouldReceive( 'render_rsvp_venue_notification' )->andReturn( '<p>Venue</p>' );
		$this->email_config->shouldReceive( 'get_venue_notification_recipients' )->andReturn( array() );

		$saved_ticket = null;
		$this->ticket_repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( Ticket $t ) use ( &$saved_ticket ) {
				$saved_ticket = $t;
				return $t;
			}
		);

		$handler->handle_rsvp_submitted(
			1,
			array(
				'occurrence_id'  => 42,
				'ticket_type_id' => 1,
				'email'          => 'test@example.com',
				'name'           => 'Test User',
			)
		);

		$this->assertNotNull( $saved_ticket );
		// Canonical code format: XXXX-XXXX-XXXX-XXXX (uppercase hex).
		$this->assertMatchesRegularExpression(
			'/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/',
			$saved_ticket->ticket_code
		);
	}

	/**
	 * Test RsvpEmailHandler sends confirmation without QR code image.
	 *
	 * When qr_service is null no QR generation is attempted.
	 *
	 * @return void
	 */
	public function test_rsvp_handler_sends_confirmation_without_qr_code(): void {
		$handler = new RsvpEmailHandler(
			null,
			$this->code_generator,
			$this->ticket_repo,
			$this->renderer,
			$this->email_config,
			$this->ics_generator
		);

		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'is_email' )->justReturn( true );

		$this->email_config->shouldReceive( 'is_customer_email_disabled' )->andReturn( false );
		$this->renderer->shouldReceive( 'get_rsvp_confirmation_subject' )->andReturn( 'Subject' );
		$this->email_config->shouldReceive( 'get_template_settings' )->andReturn( array() );
		$this->renderer->shouldReceive( 'render_rsvp_confirmation' )->andReturn( 'body' );
		$this->renderer->shouldReceive( 'get_email_headers' )->andReturn( array() );
		$this->ics_generator->shouldReceive( 'generate_ics_file' )->andReturn( false );
		$this->renderer->shouldReceive( 'get_rsvp_venue_notification_subject' )->andReturn( 'Venue' );
		$this->renderer->shouldReceive( 'render_rsvp_venue_notification' )->andReturn( 'body' );
		$this->email_config->shouldReceive( 'get_venue_notification_recipients' )->andReturn( array() );
		$this->ticket_repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( Ticket $t ) {
				return $t;
			}
		);

		// Should not throw — no QR service means no QR generation.
		$handler->handle_rsvp_submitted(
			1,
			array(
				'occurrence_id'  => 10,
				'ticket_type_id' => 1,
				'email'          => 'user@example.com',
				'name'           => 'User',
			)
		);

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test RsvpEmailHandler register adds RSVP hook without errors.
	 *
	 * @return void
	 */
	public function test_rsvp_handler_register_adds_hook_without_errors(): void {
		$handler = new RsvpEmailHandler(
			null,
			$this->code_generator,
			$this->ticket_repo,
			$this->renderer,
			$this->email_config,
			$this->ics_generator
		);

		// add_action is already stubbed by the base test case; just confirm no exception.
		$handler->register();

		$this->addToAssertionCount( 1 );
	}
}
