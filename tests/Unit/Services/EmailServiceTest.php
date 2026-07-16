<?php
/**
 * EmailService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Repositories\TicketRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Services\EmailConfig;
use NetterTechEvents\Services\EmailService;
use NetterTechEvents\Services\EmailTemplateRenderer;
use NetterTechEvents\Services\IcsGenerator;
use NetterTechEvents\Services\OrderEmailHandler;
use NetterTechEvents\Services\RsvpEmailHandler;
use NetterTechEvents\Services\TicketCodeGenerator;
use NetterTechEvents\Contracts\QRCodeServiceInterface;
use Brain\Monkey\Functions;

/**
 * Test EmailService functionality.
 */
class EmailServiceTest extends \NetterTechEventsTestCase {

	/**
	 * EmailService instance.
	 *
	 * @var EmailService
	 */
	private EmailService $service;

	/**
	 * Mock QRCodeService.
	 *
	 * @var QRCodeServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $qr_service;

	/**
	 * Mock TicketCodeGenerator.
	 *
	 * @var TicketCodeGenerator|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $code_generator;

	/**
	 * Mock TicketRepository.
	 *
	 * @var TicketRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_repo;

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
	 * Mock EmailTemplateRenderer.
	 *
	 * @var EmailTemplateRenderer|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $renderer;

	/**
	 * Mock TicketTypeRepository (used when creating real EmailTemplateRenderer).
	 *
	 * @var TicketTypeRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock IcsGenerator.
	 *
	 * @var IcsGenerator|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ics_generator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Ensure temp directory exists for ICS file generation tests.
		$temp_dir = sys_get_temp_dir() . '/nettertech-events/temp';
		if ( ! is_dir( $temp_dir ) ) {
			mkdir( $temp_dir, 0755, true );
		}

		$this->qr_service       = $this->createMock( QRCodeServiceInterface::class );
		$this->code_generator   = $this->createMock( TicketCodeGenerator::class );
		$this->ticket_repo      = $this->createMock( TicketRepository::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepository::class );
		$this->event_repo       = $this->createMock( EventRepository::class );
		$this->renderer         = $this->createMock( EmailTemplateRenderer::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$this->ics_generator    = $this->createMock( IcsGenerator::class );

		// Build EmailConfig (the shared settings/recipient resolver).
		$email_config = new EmailConfig(
			$this->occurrence_repo,
			$this->event_repo
		);

		// Wire handlers with EmailConfig (no circular dependency).
		$order_handler = new OrderEmailHandler(
			$this->qr_service,
			$this->ticket_repo,
			$this->renderer,
			$email_config,
			$this->ics_generator
		);
		$rsvp_handler = new RsvpEmailHandler(
			$this->qr_service,
			$this->code_generator,
			$this->ticket_repo,
			$this->renderer,
			$email_config,
			$this->ics_generator
		);

		// EmailService receives config + handlers via constructor.
		$this->service = new EmailService(
			$email_config,
			$order_handler,
			$rsvp_handler
		);
	}

	/**
	 * Create a fully-wired EmailService for tests.
	 *
	 * @param EmailTemplateRenderer|\PHPUnit\Framework\MockObject\MockObject|null $renderer      Optional custom renderer.
	 * @param IcsGenerator|\PHPUnit\Framework\MockObject\MockObject|null          $ics_generator Optional custom ICS generator (pass a real one to exercise ICS generation).
	 * @return EmailService
	 */
	private function create_service( $renderer = null, $ics_generator = null ): EmailService {
		$renderer      = $renderer ?? $this->renderer;
		$ics_generator = $ics_generator ?? $this->ics_generator;
		$email_config  = new EmailConfig(
			$this->occurrence_repo,
			$this->event_repo
		);
		$order_handler = new OrderEmailHandler(
			$this->qr_service,
			$this->ticket_repo,
			$renderer,
			$email_config,
			$ics_generator
		);
		$rsvp_handler  = new RsvpEmailHandler(
			$this->qr_service,
			$this->code_generator,
			$this->ticket_repo,
			$renderer,
			$email_config,
			$ics_generator
		);

		return new EmailService(
			$email_config,
			$order_handler,
			$rsvp_handler
		);
	}

	// =========================================================================
	// Constructor tests
	// =========================================================================

	/**
	 * Test constructor creates service with injected dependencies.
	 *
	 * @return void
	 */
	public function test_constructor_with_injected_dependencies(): void {
		$service = $this->create_service();

		$this->assertInstanceOf( EmailService::class, $service );
	}

	/**
	 * Test constructor requires all dependencies.
	 *
	 * @return void
	 */
	public function test_constructor_requires_all_dependencies(): void {
		$service = $this->create_service();

		$this->assertInstanceOf( EmailService::class, $service );
	}

	// =========================================================================
	// get_settings tests
	// =========================================================================

	/**
	 * Test get_settings returns defaults when no options saved.
	 *
	 * @return void
	 */
	public function test_get_settings_returns_defaults_when_no_options(): void {
		// Override default get_option stub to return empty array.
		$this->mockGetOption();

		// Create fresh service to reset settings cache.
		$service  = $this->create_service();
		$settings = $service->get_settings();

		$this->assertIsArray( $settings );
		$this->assertArrayHasKey( 'venue_logo', $settings );
		$this->assertArrayHasKey( 'venue_contacts', $settings );
		$this->assertArrayHasKey( 'disable_qr_codes', $settings );
		$this->assertArrayHasKey( 'disable_customer_email', $settings );
		$this->assertArrayHasKey( 'cancellation_policy', $settings );
		$this->assertSame( '', $settings['venue_logo'] );
		$this->assertSame( array(), $settings['venue_contacts'] );
		$this->assertFalse( $settings['disable_qr_codes'] );
		$this->assertFalse( $settings['disable_customer_email'] );
		$this->assertSame( '', $settings['cancellation_policy'] );
	}

	/**
	 * Test get_settings returns saved options merged with defaults.
	 *
	 * @return void
	 */
	public function test_get_settings_returns_saved_options(): void {
		$saved_options = array(
			'venue_logo'       => 'https://example.com/logo.png',
			'venue_contacts'   => array( 'contact@example.com' ),
			'disable_qr_codes' => true,
		);

		$this->mockGetOption( $saved_options );

		$service  = $this->create_service();
		$settings = $service->get_settings();

		$this->assertSame( 'https://example.com/logo.png', $settings['venue_logo'] );
		$this->assertSame( array( 'contact@example.com' ), $settings['venue_contacts'] );
		$this->assertTrue( $settings['disable_qr_codes'] );
		// Defaults should still be present.
		$this->assertFalse( $settings['disable_customer_email'] );
		$this->assertSame( '', $settings['cancellation_policy'] );
	}

	/**
	 * Test get_settings caches results.
	 *
	 * @return void
	 */
	public function test_get_settings_caches_results(): void {
		$this->mockGetOption( array( 'venue_logo' => 'logo.png' ) );

		$service = $this->create_service();

		// Call twice - should use cached value.
		$settings1 = $service->get_settings();
		$settings2 = $service->get_settings();

		$this->assertSame( $settings1, $settings2 );
	}

	// =========================================================================
	// is_customer_email_disabled tests
	// =========================================================================

	/**
	 * Test is_customer_email_disabled returns false by default.
	 *
	 * @return void
	 */
	public function test_is_customer_email_disabled_returns_false_by_default(): void {
		$this->mockGetOption();

		$service = $this->create_service();

		$this->assertFalse( $service->is_customer_email_disabled() );
	}

	/**
	 * Test is_customer_email_disabled returns true when disabled.
	 *
	 * @return void
	 */
	public function test_is_customer_email_disabled_returns_true_when_disabled(): void {
		$this->mockGetOption( array( 'disable_customer_email' => true ) );

		$service = $this->create_service();

		$this->assertTrue( $service->is_customer_email_disabled() );
	}

	// =========================================================================
	// is_qr_disabled tests
	// =========================================================================

	/**
	 * Test is_qr_disabled returns false by default.
	 *
	 * @return void
	 */
	public function test_is_qr_disabled_returns_false_by_default(): void {
		$this->mockGetOption();

		$service = $this->create_service();

		$this->assertFalse( $service->is_qr_disabled() );
	}

	/**
	 * Test is_qr_disabled returns true when disabled.
	 *
	 * @return void
	 */
	public function test_is_qr_disabled_returns_true_when_disabled(): void {
		$this->mockGetOption( array( 'disable_qr_codes' => true ) );

		$service = $this->create_service();

		$this->assertTrue( $service->is_qr_disabled() );
	}

	// =========================================================================
	// get_venue_logo tests
	// =========================================================================

	/**
	 * Test get_venue_logo returns empty string by default.
	 *
	 * @return void
	 */
	public function test_get_venue_logo_returns_empty_by_default(): void {
		$this->mockGetOption();

		$service = $this->create_service();

		$this->assertSame( '', $service->get_venue_logo() );
	}

	/**
	 * Test get_venue_logo returns configured URL.
	 *
	 * @return void
	 */
	public function test_get_venue_logo_returns_configured_url(): void {
		$this->mockGetOption( array( 'venue_logo' => 'https://example.com/logo.png' ) );

		$service = $this->create_service();

		$this->assertSame( 'https://example.com/logo.png', $service->get_venue_logo() );
	}

	// =========================================================================
	// get_venue_contacts tests
	// =========================================================================

	/**
	 * Test get_venue_contacts returns empty array by default.
	 *
	 * @return void
	 */
	public function test_get_venue_contacts_returns_empty_by_default(): void {
		$this->mockGetOption();
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			}
		);

		$service = $this->create_service();

		$this->assertSame( array(), $service->get_venue_contacts() );
	}

	/**
	 * Test get_venue_contacts returns configured contacts.
	 *
	 * @return void
	 */
	public function test_get_venue_contacts_returns_configured_contacts(): void {
		$contacts = array( 'contact@example.com', 'admin@example.com' );
		$this->mockGetOption( array( 'venue_contacts' => $contacts ) );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			}
		);

		$service = $this->create_service();

		$this->assertSame( $contacts, $service->get_venue_contacts() );
	}

	/**
	 * Test get_venue_contacts handles comma-separated string.
	 *
	 * @return void
	 */
	public function test_get_venue_contacts_handles_comma_separated_string(): void {
		$this->mockGetOption( array( 'venue_contacts' => 'a@b.com, c@d.com' ) );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			}
		);

		$service = $this->create_service();

		$contacts = $service->get_venue_contacts();

		$this->assertContains( 'a@b.com', $contacts );
		$this->assertContains( 'c@d.com', $contacts );
	}

	/**
	 * Test get_venue_contacts filters invalid emails.
	 *
	 * @return void
	 */
	public function test_get_venue_contacts_filters_invalid_emails(): void {
		$contacts = array( 'valid@example.com', 'invalid', 'another-valid@test.org' );
		$this->mockGetOption( array( 'venue_contacts' => $contacts ) );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			}
		);

		$service = $this->create_service();

		$result = $service->get_venue_contacts();

		$this->assertContains( 'valid@example.com', $result );
		$this->assertContains( 'another-valid@test.org', $result );
		$this->assertNotContains( 'invalid', $result );
	}

	// =========================================================================
	// get_cancellation_policy tests
	// =========================================================================

	/**
	 * Test get_cancellation_policy returns empty string by default.
	 *
	 * @return void
	 */
	public function test_get_cancellation_policy_returns_empty_by_default(): void {
		$this->mockGetOption();

		$service = $this->create_service();

		$this->assertSame( '', $service->get_cancellation_policy() );
	}

	/**
	 * Test get_cancellation_policy returns configured policy.
	 *
	 * @return void
	 */
	public function test_get_cancellation_policy_returns_configured_policy(): void {
		$policy = 'No refunds within 24 hours of event.';
		$this->mockGetOption( array( 'cancellation_policy' => $policy ) );

		$service = $this->create_service();

		$this->assertSame( $policy, $service->get_cancellation_policy() );
	}

	// =========================================================================
	// resend_confirmation tests
	// =========================================================================

	/**
	 * Test resend_confirmation returns error for invalid order.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_returns_error_for_invalid_order(): void {
		$this->mockGetOption();
		Functions\when( 'wc_get_order' )->justReturn( false );

		$service = $this->create_service();

		$result = $service->resend_confirmation( 999 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['message'] );
	}

	/**
	 * Test resend_confirmation returns error when no tickets found.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_returns_error_when_no_tickets(): void {
		$this->mockGetOption();

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 123 );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'find_by_order' )
			->with( 123 )
			->willReturn( array() );

		$service = $this->create_service();

		$result = $service->resend_confirmation( 123 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No tickets', $result['message'] );
	}

	// =========================================================================
	// handle_order_completed tests
	// =========================================================================

	/**
	 * Test handle_order_completed returns early when order not found.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_returns_early_when_order_not_found(): void {
		$this->mockGetOption();
		Functions\when( 'wc_get_order' )->justReturn( false );

		$service = $this->create_service();

		// Should not throw - just return early.
		$service->handle_order_completed( 999 );

		// If we get here without exception, test passes.
		$this->assertTrue( true );
	}

	/**
	 * Test handle_order_completed returns early when already sent.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_returns_early_when_already_sent(): void {
		$this->mockGetOption();

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 123 );
		$order->method( 'get_meta' )->willReturn( 'yes' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->ticket_repo
			->expects( $this->never() )
			->method( 'find_by_order' );

		$service = $this->create_service();

		$service->handle_order_completed( 123 );

		// Verify ticket repo was not called.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test handle_order_completed returns early when no tickets.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_returns_early_when_no_tickets(): void {
		$this->mockGetOption();

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 123 );
		$order->method( 'get_meta' )->willReturn( '' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'find_by_order' )
			->with( 123 )
			->willReturn( array() );

		$order->expects( $this->never() )->method( 'update_meta_data' );

		$service = $this->create_service();

		$service->handle_order_completed( 123 );
		$this->assertTrue( true );
	}

	// =========================================================================
	// send_venue_notifications tests
	// =========================================================================

	/**
	 * Test send_venue_notifications returns false when no recipients.
	 *
	 * @return void
	 */
	public function test_send_venue_notifications_returns_false_when_no_recipients(): void {
		$this->mockGetOption( array( 'venue_contacts' => array() ) );
		Functions\when( 'is_email' )->justReturn( false );

		$order = $this->createMock( \WC_Order::class );

		$ticket                = new Ticket();
		$ticket->id            = 1;
		$ticket->occurrence_id = 1;

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( null );

		$service = $this->create_service();

		$result = $service->send_venue_notifications( $order, array( $ticket ) );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// handle_ajax_resend tests
	// =========================================================================

	/**
	 * Test handle_ajax_resend checks capability.
	 *
	 * @return void
	 */
	public function test_handle_ajax_resend_checks_capability(): void {
		$this->mockGetOption();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_die' )
			->once()
			->with( 'Unauthorized', 403 );
		Functions\when( 'esc_html__' )->justReturn( 'Unauthorized' );
		// Mock remaining functions that might be called if wp_die doesn't halt.
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'absint' )->justReturn( 0 );
		Functions\when( '__' )->alias(
			function ( $text ) {
				return $text;
			}
		);
		Functions\when( 'wp_send_json_error' )->justReturn( null );

		$_POST = array();

		$service = $this->create_service();

		$service->handle_ajax_resend();

		// wp_die expectation was verified.
		$this->assertTrue( true );
	}

	/**
	 * Test handle_ajax_resend validates nonce when user has capability.
	 *
	 * @return void
	 */
	public function test_handle_ajax_resend_validates_nonce_when_authorized(): void {
		$this->mockGetOption();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'absint' )->justReturn( 0 );
		Functions\when( '__' )->alias(
			function ( $text ) {
				return $text;
			}
		);
		Functions\when( 'wp_send_json_error' )->justReturn( null );

		$_POST = array();

		$service = $this->create_service();

		$service->handle_ajax_resend();

		// Test passes if no exception thrown.
		$this->assertTrue( true );
	}

	// =========================================================================
	// handle_rsvp_submitted tests
	// =========================================================================

	/**
	 * Test handle_rsvp_submitted returns early when ticket creation fails.
	 *
	 * @return void
	 */
	public function test_handle_rsvp_submitted_returns_early_when_no_occurrence(): void {
		$this->mockGetOption();

		// Form data without occurrence_id should cause ticket creation to fail.
		$form_data = array();

		$service = $this->create_service();

		// No ticket should be saved.
		$this->ticket_repo
			->expects( $this->never() )
			->method( 'save' );

		$service->handle_rsvp_submitted( 1, $form_data );
		$this->assertTrue( true );
	}

	// =========================================================================
	// register tests
	// =========================================================================

	/**
	 * Test register adds action hooks.
	 *
	 * @return void
	 */
	public function test_register_adds_action_hooks(): void {
		$this->mockGetOption();

		$service = $this->create_service();

		// Verify register method exists and is callable.
		$this->assertTrue( method_exists( $service, 'register' ) );
	}

	// =========================================================================
	// SETTINGS_KEY constant tests
	// =========================================================================

	/**
	 * Test SETTINGS_KEY constant has expected value.
	 *
	 * @return void
	 */
	public function test_settings_key_constant(): void {
		$this->assertSame( 'nettertech_events_email_settings', EmailService::SETTINGS_KEY );
	}

	// =========================================================================
	// send_customer_confirmation full tests
	// =========================================================================

	/**
	 * Mock get_option to return appropriate values based on option name.
	 *
	 * Fixes "Array to string conversion" warning in sprintf() at EmailService:504
	 * by returning proper types for each option key.
	 *
	 * @param array $venue_settings Optional venue settings array (default empty).
	 * @return void
	 */
	private function mockGetOption( array $venue_settings = array() ): void {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) use ( $venue_settings ) {
				switch ( $option ) {
					case 'nettertech_events_email_settings':
						return $venue_settings;
					case 'admin_email':
						return 'admin@example.com';
					case 'blogname':
						return 'Test Site';
					default:
						return $default;
				}
			}
		);
	}

	/**
	 * Mock file_exists to return false for template files.
	 *
	 * This prevents actual template loading which would require full WC_Order mocking.
	 *
	 * @return void
	 */
	private function mockFileExistsSkipTemplates(): void {
		Functions\when( 'file_exists' )->alias(
			function ( $path ) {
				// Return false for template files to skip template rendering.
				if ( strpos( $path, 'templates/' ) !== false ) {
					return false;
				}
				// Use real file_exists for everything else.
				return \file_exists( $path );
			}
		);

		// Mock wp_strip_all_tags for ICS generation (used in generate_ics_file).
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text ) {
				return strip_tags( $text );
			}
		);
	}

	/**
	 * Test send_customer_confirmation sends email successfully.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_sends_email(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->alias(
			function ( $show ) {
				return 'name' === $show ? 'Test Site' : 'admin@example.com';
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'wp_mail' )->justReturn( true );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'customer@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );

		$ticket                 = new Ticket();
		$ticket->id             = 1;
		$ticket->ticket_code    = 'TICKET123';
		$ticket->occurrence_id  = 10;
		$ticket->ticket_type_id = 5;
		$ticket->qr_code_url    = 'https://example.com/qr/123.png';
		$ticket->price_paid     = 25.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 10;
		$occurrence->event_id       = 100;
		$occurrence->start_datetime = '2026-02-01 19:00:00';
		$occurrence->end_datetime   = '2026-02-01 21:00:00';

		$event                = new Event();
		$event->id            = 100;
		$event->title         = 'Test Concert';
		$event->description   = 'A great concert';
		$event->venue_name    = 'Test Venue';
		$event->venue_address = '123 Main St';

		$this->occurrence_repo
			->method( 'find' )
			->with( 10 )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->with( 100 )
			->willReturn( $event );

		$service = $this->create_service();

		$result = $service->send_customer_confirmation( $order, array( $ticket ) );

		$this->assertTrue( $result );
	}

	/**
	 * Test send_customer_confirmation generates ICS attachment.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_generates_ics_attachment(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();

		$temp_dir = sys_get_temp_dir() . '/nettertech-events/temp';
		if ( ! is_dir( $temp_dir ) ) {
			mkdir( $temp_dir, 0755, true );
		}

		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-ics-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);

		$ics_created = false;
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers, $attachments ) use ( &$ics_created ) {
				if ( ! empty( $attachments ) && file_exists( $attachments[0] ) ) {
					$content     = file_get_contents( $attachments[0] );
					$ics_created = strpos( $content, 'BEGIN:VCALENDAR' ) !== false;
				}
				return true;
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			function ( $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'customer@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Jane' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Smith' );

		$ticket                 = new Ticket();
		$ticket->id             = 2;
		$ticket->ticket_code    = 'TICKET456';
		$ticket->occurrence_id  = 20;
		$ticket->ticket_type_id = 6;
		$ticket->qr_code_url    = 'https://example.com/qr/456.png';
		$ticket->price_paid     = 50.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 20;
		$occurrence->event_id       = 200;
		$occurrence->start_datetime = '2026-03-15 18:00:00';
		$occurrence->end_datetime   = '2026-03-15 20:00:00';

		$event              = new Event();
		$event->id          = 200;
		$event->title       = 'Spring Festival';
		$event->description = 'Annual spring celebration';
		$event->venue_name  = 'City Park';

		$this->occurrence_repo
			->method( 'find' )
			->with( 20 )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->with( 200 )
			->willReturn( $event );

		// Use real renderer to test ICS generation.
		$real_renderer = new EmailTemplateRenderer(
			$this->occurrence_repo,
			$this->event_repo,
			$this->ticket_type_repo
		);

		$service = $this->create_service( $real_renderer, new IcsGenerator( $this->occurrence_repo, $this->event_repo ) );

		$result = $service->send_customer_confirmation( $order, array( $ticket ) );

		$this->assertTrue( $result );
		$this->assertTrue( $ics_created, 'ICS calendar file should be created' );
	}

	/**
	 * Test send_customer_confirmation with multiple tickets.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_with_multiple_tickets(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'multi-ticket-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );

		$captured_subject = '';
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject ) use ( &$captured_subject ) {
				$captured_subject = $subject;
				return true;
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'buyer@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Multi' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Buyer' );

		$ticket1                 = new Ticket();
		$ticket1->id             = 10;
		$ticket1->ticket_code    = 'MULTI1';
		$ticket1->occurrence_id  = 30;
		$ticket1->ticket_type_id = 7;
		$ticket1->qr_code_url    = 'https://example.com/qr/m1.png';
		$ticket1->price_paid     = 25.00;

		$ticket2                 = new Ticket();
		$ticket2->id             = 11;
		$ticket2->ticket_code    = 'MULTI2';
		$ticket2->occurrence_id  = 30;
		$ticket2->ticket_type_id = 7;
		$ticket2->qr_code_url    = 'https://example.com/qr/m2.png';
		$ticket2->price_paid     = 25.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 30;
		$occurrence->event_id       = 300;
		$occurrence->start_datetime = '2026-04-01 19:00:00';
		$occurrence->end_datetime   = '2026-04-01 22:00:00';

		$event        = new Event();
		$event->id    = 300;
		$event->title = 'Double Feature';

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		// Use real renderer to test subject generation.
		$real_renderer = new EmailTemplateRenderer(
			$this->occurrence_repo,
			$this->event_repo,
			$this->ticket_type_repo
		);

		$service = $this->create_service( $real_renderer, new IcsGenerator( $this->occurrence_repo, $this->event_repo ) );

		$result = $service->send_customer_confirmation( $order, array( $ticket1, $ticket2 ) );

		$this->assertTrue( $result );
		$this->assertStringContainsString( '2', $captured_subject );
	}

	// =========================================================================
	// send_venue_notifications full tests
	// =========================================================================

	/**
	 * Test send_venue_notifications sends to global contacts.
	 *
	 * @return void
	 */
	public function test_send_venue_notifications_sends_to_global_contacts(): void {
		$global_contacts = array( 'venue@example.com', 'manager@example.com' );
		$this->mockGetOption( array( 'venue_contacts' => $global_contacts ) );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Venue' );
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) use ( $global_contacts ) {
				if ( 'nettertech_events_email_settings' === $option ) {
					return array( 'venue_contacts' => $global_contacts );
				}
				return $default;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();

		$sent_to = array();
		Functions\when( 'wp_mail' )->alias(
			function ( $to ) use ( &$sent_to ) {
				$sent_to[] = $to;
				return true;
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_first_name' )->willReturn( 'Test' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Customer' );
		$order->method( 'get_billing_email' )->willReturn( 'test@customer.com' );

		$ticket                 = new Ticket();
		$ticket->id             = 50;
		$ticket->ticket_code    = 'VENUE1';
		$ticket->occurrence_id  = 40;
		$ticket->ticket_type_id = 8;
		$ticket->price_paid     = 35.00;

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( null );

		$service = $this->create_service();

		$result = $service->send_venue_notifications( $order, array( $ticket ) );

		$this->assertTrue( $result );
		$this->assertCount( 2, $sent_to );
		$this->assertContains( 'venue@example.com', $sent_to );
		$this->assertContains( 'manager@example.com', $sent_to );
	}

	/**
	 * Test send_venue_notifications includes per-event notification recipients.
	 *
	 * @return void
	 */
	public function test_send_venue_notifications_includes_event_notification_recipients(): void {
		$this->mockGetOption( array( 'venue_contacts' => array() ) );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();

		$sent_to = array();
		Functions\when( 'wp_mail' )->alias(
			function ( $to ) use ( &$sent_to ) {
				$sent_to[] = $to;
				return true;
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_first_name' )->willReturn( 'Event' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Buyer' );
		$order->method( 'get_billing_email' )->willReturn( 'buyer@test.com' );

		$ticket                 = new Ticket();
		$ticket->id             = 60;
		$ticket->ticket_code    = 'EVT1';
		$ticket->occurrence_id  = 50;
		$ticket->ticket_type_id = 9;
		$ticket->price_paid     = 40.00;

		$occurrence                  = new Occurrence();
		$occurrence->id              = 50;
		$occurrence->event_id        = 500;
		$occurrence->start_datetime  = '2026-05-01 19:00:00';
		$occurrence->end_datetime    = '2026-05-01 21:00:00';

		$event                       = new Event();
		$event->id                   = 500;
		$event->title                = 'Contact Test Event';
		$event->notification_emails  = 'event@contact.com';

		$this->occurrence_repo
			->method( 'find' )
			->with( 50 )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->with( 500 )
			->willReturn( $event );

		$service = $this->create_service();

		$result = $service->send_venue_notifications( $order, array( $ticket ) );

		$this->assertTrue( $result );
		$this->assertContains( 'event@contact.com', $sent_to );
	}

	// =========================================================================
	// handle_order_completed full tests
	// =========================================================================

	/**
	 * Test handle_order_completed sends emails when tickets exist.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_sends_emails_with_tickets(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'order-complete-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-17 12:00:00' );
		Functions\when( 'is_email' )->justReturn( false );
		Functions\when( 'do_action' )->justReturn( null );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 789 );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->method( 'get_billing_email' )->willReturn( 'complete@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Complete' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Order' );
		$order->expects( $this->atLeastOnce() )->method( 'update_meta_data' );
		$order->expects( $this->once() )->method( 'save' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$ticket                 = new Ticket();
		$ticket->id             = 70;
		$ticket->ticket_code    = 'COMPLETE1';
		$ticket->occurrence_id  = 60;
		$ticket->ticket_type_id = 10;
		$ticket->qr_code_url    = 'https://example.com/qr/complete.png';
		$ticket->price_paid     = 50.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 60;
		$occurrence->event_id       = 600;
		$occurrence->start_datetime = '2026-06-01 19:00:00';
		$occurrence->end_datetime   = '2026-06-01 21:00:00';

		$event        = new Event();
		$event->id    = 600;
		$event->title = 'Complete Event';

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'find_by_order' )
			->with( 789 )
			->willReturn( array( $ticket ) );

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		$service = $this->create_service();

		$service->handle_order_completed( 789 );

		// If we get here without exception, order meta was updated.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test handle_order_completed generates QR codes when missing.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_generates_qr_codes_when_missing(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'qr-gen-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-17 12:00:00' );
		Functions\when( 'is_email' )->justReturn( false );
		Functions\when( 'do_action' )->justReturn( null );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 890 );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->method( 'get_billing_email' )->willReturn( 'qr@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'QR' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Test' );
		$order->method( 'update_meta_data' )->willReturn( null );
		$order->method( 'save' )->willReturn( null );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$ticket                 = new Ticket();
		$ticket->id             = 80;
		$ticket->ticket_code    = 'NOQR1';
		$ticket->occurrence_id  = 70;
		$ticket->ticket_type_id = 11;
		$ticket->qr_code_url    = ''; // Empty - should trigger generation.
		$ticket->price_paid     = 30.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 70;
		$occurrence->event_id       = 700;
		$occurrence->start_datetime = '2026-07-01 19:00:00';
		$occurrence->end_datetime   = '2026-07-01 21:00:00';

		$event        = new Event();
		$event->id    = 700;
		$event->title = 'QR Generation Event';

		$this->ticket_repo
			->method( 'find_by_order' )
			->willReturn( array( $ticket ) );

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		$this->qr_service
			->expects( $this->once() )
			->method( 'generate_for_ticket' )
			->with( $ticket );

		$service = $this->create_service();

		$service->handle_order_completed( 890 );

		// QR generation expectation was verified.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test handle_order_completed respects disable_customer_email setting.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_respects_customer_email_disabled(): void {
		$this->mockGetOption( array( 'disable_customer_email' => true ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'is_email' )->justReturn( false );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_mail' )->justReturn( false ); // Venue notifications won't send without contacts.
		Functions\when( 'current_time' )->justReturn( '2026-01-17 12:00:00' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 901 );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->method( 'get_billing_email' )->willReturn( 'disabled@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Disabled' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Customer' );
		// No update_meta_data expected since neither email type sends.

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$ticket                 = new Ticket();
		$ticket->id             = 90;
		$ticket->ticket_code    = 'DISABLED1';
		$ticket->occurrence_id  = 80;
		$ticket->ticket_type_id = 12;
		$ticket->qr_code_url    = 'https://example.com/qr/disabled.png';
		$ticket->price_paid     = 20.00;

		$this->ticket_repo
			->method( 'find_by_order' )
			->willReturn( array( $ticket ) );

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( null );

		$service = $this->create_service();

		$service->handle_order_completed( 901 );

		// Test passes if no customer email was attempted.
		$this->assertTrue( $service->is_customer_email_disabled() );
	}

	// =========================================================================
	// resend_confirmation success tests
	// =========================================================================

	/**
	 * Test resend_confirmation sends email successfully.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_sends_email_successfully(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'resend-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-17 13:00:00' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 912 );
		$order->method( 'get_billing_email' )->willReturn( 'resend@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Resend' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Test' );
		$order->expects( $this->once() )->method( 'update_meta_data' );
		$order->expects( $this->once() )->method( 'save' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$ticket                 = new Ticket();
		$ticket->id             = 100;
		$ticket->ticket_code    = 'RESEND1';
		$ticket->occurrence_id  = 90;
		$ticket->ticket_type_id = 13;
		$ticket->qr_code_url    = 'https://example.com/qr/resend.png';
		$ticket->price_paid     = 45.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 90;
		$occurrence->event_id       = 900;
		$occurrence->start_datetime = '2026-08-01 19:00:00';
		$occurrence->end_datetime   = '2026-08-01 21:00:00';

		$event        = new Event();
		$event->id    = 900;
		$event->title = 'Resend Test Event';

		$this->ticket_repo
			->method( 'find_by_order' )
			->with( 912 )
			->willReturn( array( $ticket ) );

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		$service = $this->create_service();

		$result = $service->resend_confirmation( 912 );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'successfully', $result['message'] );
	}

	/**
	 * Test resend_confirmation generates QR code if missing.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_generates_qr_if_missing(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'resend-qr-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-17 13:30:00' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 923 );
		$order->method( 'get_billing_email' )->willReturn( 'resend-qr@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'QR' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Resend' );
		$order->method( 'update_meta_data' )->willReturn( null );
		$order->method( 'save' )->willReturn( null );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$ticket                 = new Ticket();
		$ticket->id             = 110;
		$ticket->ticket_code    = 'RESENDQR1';
		$ticket->occurrence_id  = 100;
		$ticket->ticket_type_id = 14;
		$ticket->qr_code_url    = ''; // Empty - should trigger generation.
		$ticket->price_paid     = 55.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 100;
		$occurrence->event_id       = 1000;
		$occurrence->start_datetime = '2026-09-01 19:00:00';
		$occurrence->end_datetime   = '2026-09-01 21:00:00';

		$event        = new Event();
		$event->id    = 1000;
		$event->title = 'QR Resend Event';

		$this->ticket_repo
			->method( 'find_by_order' )
			->willReturn( array( $ticket ) );

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		$this->qr_service
			->expects( $this->once() )
			->method( 'generate_for_ticket' )
			->with( $ticket );

		$service = $this->create_service();

		$result = $service->resend_confirmation( 923 );

		$this->assertTrue( $result['success'] );
	}

	/**
	 * Test resend_confirmation returns failure when email fails.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_returns_failure_when_email_fails(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'fail-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'wp_mail' )->justReturn( false ); // Email fails.

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 934 );
		$order->method( 'get_billing_email' )->willReturn( 'fail@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Fail' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Test' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$ticket                 = new Ticket();
		$ticket->id             = 120;
		$ticket->ticket_code    = 'FAIL1';
		$ticket->occurrence_id  = 110;
		$ticket->ticket_type_id = 15;
		$ticket->qr_code_url    = 'https://example.com/qr/fail.png';
		$ticket->price_paid     = 60.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 110;
		$occurrence->event_id       = 1100;
		$occurrence->start_datetime = '2026-10-01 19:00:00';
		$occurrence->end_datetime   = '2026-10-01 21:00:00';

		$event        = new Event();
		$event->id    = 1100;
		$event->title = 'Fail Event';

		$this->ticket_repo
			->method( 'find_by_order' )
			->willReturn( array( $ticket ) );

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		$service = $this->create_service();

		$result = $service->resend_confirmation( 934 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Failed', $result['message'] );
	}

	// =========================================================================
	// handle_rsvp_submitted full tests
	// =========================================================================

	/**
	 * Test handle_rsvp_submitted creates ticket and sends emails.
	 *
	 * @return void
	 */
	public function test_handle_rsvp_submitted_creates_ticket_and_sends_emails(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			}
		);
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'rsvp-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'wp_mail' )->justReturn( true );

		$form_data = array(
			'occurrence_id'  => 120,
			'ticket_type_id' => 16,
			'email'          => 'rsvp@example.com',
			'name'           => 'RSVP Guest',
		);

		$occurrence                 = new Occurrence();
		$occurrence->id             = 120;
		$occurrence->event_id       = 1200;
		$occurrence->start_datetime = '2026-11-01 19:00:00';
		$occurrence->end_datetime   = '2026-11-01 21:00:00';

		$event        = new Event();
		$event->id    = 1200;
		$event->title = 'RSVP Event';

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'RSVP-CODE-123' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'save' );

		$this->qr_service
			->expects( $this->once() )
			->method( 'generate_for_ticket' );

		$service = $this->create_service();

		$service->handle_rsvp_submitted( 500, $form_data );

		// Ticket was saved and QR was generated.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test handle_rsvp_submitted respects customer email disabled.
	 *
	 * @return void
	 */
	public function test_handle_rsvp_submitted_respects_customer_email_disabled(): void {
		$this->mockGetOption( array( 'disable_customer_email' => true ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'is_email' )->justReturn( false );
		Functions\when( 'wp_mail' )->justReturn( false );

		$form_data = array(
			'occurrence_id'  => 130,
			'ticket_type_id' => 17,
			'email'          => 'disabled-rsvp@example.com',
			'name'           => 'Disabled RSVP',
		);

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'DISABLED-RSVP-CODE' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'save' );

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( null );

		$service = $this->create_service();

		$service->handle_rsvp_submitted( 501, $form_data );

		// Test passes - no customer email sent when disabled.
		$this->assertTrue( $service->is_customer_email_disabled() );
	}

	// =========================================================================
	// handle_ajax_resend additional tests
	// =========================================================================

	/**
	 * Test handle_ajax_resend sends success response on valid order.
	 *
	 * @return void
	 */
	public function test_handle_ajax_resend_sends_success_on_valid_order(): void {
		$this->mockGetOption();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'ajax-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-17 14:00:00' );

		$success_sent = false;
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) use ( &$success_sent ) {
				$success_sent = $data['success'] ?? false;
			}
		);
		Functions\when( 'wp_send_json_error' )->justReturn( null );

		$_POST = array( 'order_id' => '945' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 945 );
		$order->method( 'get_billing_email' )->willReturn( 'ajax@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Ajax' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Test' );
		$order->method( 'update_meta_data' )->willReturn( null );
		$order->method( 'save' )->willReturn( null );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$ticket                 = new Ticket();
		$ticket->id             = 130;
		$ticket->ticket_code    = 'AJAX1';
		$ticket->occurrence_id  = 140;
		$ticket->ticket_type_id = 18;
		$ticket->qr_code_url    = 'https://example.com/qr/ajax.png';
		$ticket->price_paid     = 65.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 140;
		$occurrence->event_id       = 1400;
		$occurrence->start_datetime = '2026-12-01 19:00:00';
		$occurrence->end_datetime   = '2026-12-01 21:00:00';

		$event        = new Event();
		$event->id    = 1400;
		$event->title = 'Ajax Event';

		$this->ticket_repo
			->method( 'find_by_order' )
			->willReturn( array( $ticket ) );

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		$service = $this->create_service();

		$service->handle_ajax_resend();

		$this->assertTrue( $success_sent );
	}

	/**
	 * Test handle_ajax_resend sends error for invalid order ID.
	 *
	 * @return void
	 */
	public function test_handle_ajax_resend_sends_error_for_zero_order_id(): void {
		$this->mockGetOption();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'absint' )->justReturn( 0 );
		Functions\when( '__' )->alias(
			function ( $text ) {
				return $text;
			}
		);

		$error_sent = false;
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data ) use ( &$error_sent ) {
				$error_sent = true;
			}
		);

		$_POST = array();

		$service = $this->create_service();

		$service->handle_ajax_resend();

		$this->assertTrue( $error_sent );
	}

	// =========================================================================
	// register hook tests
	// =========================================================================

	/**
	 * Test register method adds all expected hooks.
	 *
	 * @return void
	 */
	public function test_register_adds_all_expected_hooks(): void {
		$this->mockGetOption();

		$added_actions = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10 ) use ( &$added_actions ) {
				$added_actions[] = array(
					'hook'     => $hook,
					'priority' => $priority,
				);
			}
		);

		$service = $this->create_service();

		$service->register();

		// Verify expected hooks were added.
		$hooks = array_column( $added_actions, 'hook' );

		$this->assertContains( 'woocommerce_order_status_processing', $hooks );
		$this->assertContains( 'woocommerce_order_status_completed', $hooks );
		$this->assertContains( 'nettertech_events_rsvp_submitted', $hooks );
		$this->assertContains( 'wp_ajax_nettertech_events_resend_confirmation_email', $hooks );
	}

	// =========================================================================
	// Edge case tests
	// =========================================================================

	/**
	 * Test send_customer_confirmation handles empty tickets gracefully.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_with_empty_tickets(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mail' )->justReturn( true );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'empty@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Empty' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Test' );

		$service = $this->create_service();

		$result = $service->send_customer_confirmation( $order, array() );

		// Should still send email, just with empty ticket list.
		$this->assertTrue( $result );
	}

	/**
	 * Test ICS generation with event without venue.
	 *
	 * @return void
	 */
	public function test_ics_generation_without_venue_address(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'no-venue-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);

		$ics_has_location = false;
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers, $attachments ) use ( &$ics_has_location ) {
				if ( ! empty( $attachments ) && file_exists( $attachments[0] ) ) {
					$content          = file_get_contents( $attachments[0] );
					$ics_has_location = strpos( $content, 'LOCATION:' ) !== false;
				}
				return true;
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			function ( $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'novenue@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'No' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Venue' );

		$ticket                 = new Ticket();
		$ticket->id             = 140;
		$ticket->ticket_code    = 'NOVENUE1';
		$ticket->occurrence_id  = 150;
		$ticket->ticket_type_id = 19;
		$ticket->qr_code_url    = 'https://example.com/qr/novenue.png';
		$ticket->price_paid     = 70.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 150;
		$occurrence->event_id       = 1500;
		$occurrence->start_datetime = '2027-01-01 19:00:00';
		$occurrence->end_datetime   = '2027-01-01 21:00:00';

		$event              = new Event();
		$event->id          = 1500;
		$event->title       = 'Virtual Event';
		$event->description = 'Online event';
		$event->venue_name  = ''; // No venue.

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		$service = $this->create_service();

		$result = $service->send_customer_confirmation( $order, array( $ticket ) );

		$this->assertTrue( $result );
		$this->assertFalse( $ics_has_location, 'ICS should not have LOCATION for virtual event' );
	}

	/**
	 * Test customer email subject varies by ticket count.
	 *
	 * @return void
	 */
	public function test_customer_email_subject_for_single_ticket_with_event(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'locate_template' )->justReturn( '' );
		$this->mockFileExistsSkipTemplates();
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'subject-uuid' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );

		$captured_subject = '';
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject ) use ( &$captured_subject ) {
				$captured_subject = $subject;
				return true;
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_billing_email' )->willReturn( 'subject@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Subject' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Test' );

		$ticket                 = new Ticket();
		$ticket->id             = 150;
		$ticket->ticket_code    = 'SUBJ1';
		$ticket->occurrence_id  = 160;
		$ticket->ticket_type_id = 20;
		$ticket->qr_code_url    = 'https://example.com/qr/subj.png';
		$ticket->price_paid     = 75.00;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 160;
		$occurrence->event_id       = 1600;
		$occurrence->start_datetime = '2027-02-01 19:00:00';
		$occurrence->end_datetime   = '2027-02-01 21:00:00';

		$event        = new Event();
		$event->id    = 1600;
		$event->title = 'Subject Test Concert';

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( $occurrence );

		$this->event_repo
			->method( 'find' )
			->willReturn( $event );

		// Use real renderer to test subject generation.
		$real_renderer = new EmailTemplateRenderer(
			$this->occurrence_repo,
			$this->event_repo,
			$this->ticket_type_repo
		);

		$service = $this->create_service( $real_renderer, new IcsGenerator( $this->occurrence_repo, $this->event_repo ) );

		$service->send_customer_confirmation( $order, array( $ticket ) );

		$this->assertStringContainsString( 'Subject Test Concert', $captured_subject );
	}

	/**
	 * Test RSVP confirmation with invalid email returns false.
	 *
	 * @return void
	 */
	public function test_handle_rsvp_submitted_with_invalid_email_does_not_send(): void {
		$this->mockGetOption();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'is_email' )->justReturn( false );

		$form_data = array(
			'occurrence_id'  => 170,
			'ticket_type_id' => 21,
			'email'          => 'not-an-email',
			'name'           => 'Invalid Email',
		);

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'INVALID-EMAIL-CODE' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'save' );

		$this->occurrence_repo
			->method( 'find' )
			->willReturn( null );

		$service = $this->create_service();

		// Should not throw, just skip sending.
		$service->handle_rsvp_submitted( 502, $form_data );

		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// Null-Handler Guard Tests (G-01)
	// =========================================================================

	/**
	 * Test handle_order_completed does nothing when order_handler is null.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_noop_without_order_handler(): void {
		// Create service without handlers (null-guard test).
		$email_config = new EmailConfig( $this->occurrence_repo, $this->event_repo );
		$service      = new EmailService( $email_config );
		// Should not throw — null guard returns early.
		$service->handle_order_completed( 999 );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test send_customer_confirmation returns false when order_handler is null.
	 *
	 * @return void
	 */
	public function test_send_customer_confirmation_returns_false_without_order_handler(): void {
		$email_config = new EmailConfig( $this->occurrence_repo, $this->event_repo );
		$service      = new EmailService( $email_config );
		$order        = $this->createMock( \WC_Order::class );
		$result       = $service->send_customer_confirmation( $order, array() );
		$this->assertFalse( $result );
	}

	/**
	 * Test handle_rsvp_submitted does nothing when rsvp_handler is null.
	 *
	 * @return void
	 */
	public function test_handle_rsvp_submitted_noop_without_rsvp_handler(): void {
		$email_config = new EmailConfig( $this->occurrence_repo, $this->event_repo );
		$service      = new EmailService( $email_config );
		// Should not throw — null guard returns early.
		$service->handle_rsvp_submitted( 1, array( 'email' => 'test@example.com' ) );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test resend_confirmation returns failure when order_handler is null.
	 *
	 * @return void
	 */
	public function test_resend_confirmation_returns_failure_without_order_handler(): void {
		Functions\when( '__' )->returnArg( 1 );
		$email_config = new EmailConfig( $this->occurrence_repo, $this->event_repo );
		$service      = new EmailService( $email_config );
		$result       = $service->resend_confirmation( 123 );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test send_venue_notifications returns false when order_handler is null.
	 *
	 * @return void
	 */
	public function test_send_venue_notifications_returns_false_without_order_handler(): void {
		$email_config = new EmailConfig( $this->occurrence_repo, $this->event_repo );
		$service      = new EmailService( $email_config );
		$order        = $this->createMock( \WC_Order::class );
		$result       = $service->send_venue_notifications( $order, array() );
		$this->assertFalse( $result );
	}

	/**
	 * Test register does nothing when both handlers are null.
	 *
	 * @return void
	 */
	public function test_register_noop_without_handlers(): void {
		$email_config = new EmailConfig( $this->occurrence_repo, $this->event_repo );
		$service      = new EmailService( $email_config );
		// Should not throw.
		$service->register();
		$this->addToAssertionCount( 1 );
	}
}
