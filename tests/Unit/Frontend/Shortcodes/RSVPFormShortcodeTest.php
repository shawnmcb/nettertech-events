<?php
/**
 * RSVPFormShortcode unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend\Shortcodes;

use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Frontend\Shortcodes\RSVPFormShortcode;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\RateLimitService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test RSVPFormShortcode class.
 *
 * @since 0.9.0
 * @coversDefaultClass \NetterTechEvents\Frontend\Shortcodes\RSVPFormShortcode
 */
class RSVPFormShortcodeTest extends \NetterTechEventsTestCase {

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepository|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Mock TicketTypeRepository.
	 *
	 * @var TicketTypeRepository|Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Mock AttendeeRepository.
	 *
	 * @var AttendeeRepository|Mockery\MockInterface
	 */
	private $attendee_repo;

	/**
	 * Mock CapacityCalculatorInterface.
	 *
	 * @var CapacityCalculatorInterface|Mockery\MockInterface
	 */
	private $capacity_calculator;

	/**
	 * Mock RateLimitService.
	 *
	 * @var RateLimitService|Mockery\MockInterface
	 */
	private $rate_limit_service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create mock repositories.
		$this->occurrence_repo     = Mockery::mock( OccurrenceRepository::class );
		$this->ticket_type_repo    = Mockery::mock( TicketTypeRepository::class );
		$this->attendee_repo       = Mockery::mock( AttendeeRepository::class );
		$this->capacity_calculator = Mockery::mock( CapacityCalculatorInterface::class );

		$this->rate_limit_service  = Mockery::mock( RateLimitService::class );
		$this->rate_limit_service->shouldReceive( 'should_bypass' )->byDefault()->andReturn( true );

		// Default: unlimited capacity (no constraint).
		$this->capacity_calculator
			->shouldReceive( 'get_occurrence_capacity' )
			->byDefault()
			->andReturn( array(
				'total_capacity'  => null,
				'total_sold'      => 0,
				'total_available' => null,
				'has_unlimited'   => true,
				'ticket_types'    => array(),
			) );

		// Reset POST superglobal.
		$_POST = array();
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * @covers ::__construct
	 */
	public function test_constructor_accepts_all_repositories(): void {
		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$this->assertInstanceOf( RSVPFormShortcode::class, $shortcode );
	}

	// =========================================================================
	// Render - Default Attributes Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_uses_correct_default_attributes(): void {
		$atts_received = null;

		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $name ) use ( &$atts_received ) {
				$atts_received = $defaults;
				return array_merge( $defaults, $atts );
			}
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$shortcode->render();

		$this->assertArrayHasKey( 'occurrence_id', $atts_received );
		$this->assertArrayHasKey( 'show_party', $atts_received );
		$this->assertArrayHasKey( 'max_party', $atts_received );
		$this->assertArrayHasKey( 'button_text', $atts_received );
		$this->assertArrayHasKey( 'success_text', $atts_received );
		$this->assertEquals( 0, $atts_received['occurrence_id'] );
		$this->assertEquals( 'true', $atts_received['show_party'] );
		$this->assertEquals( 10, $atts_received['max_party'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_uses_correct_shortcode_name(): void {
		$shortcode_name = null;

		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $name ) use ( &$shortcode_name ) {
				$shortcode_name = $name;
				return array_merge( $defaults, $atts );
			}
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$shortcode->render();

		$this->assertEquals( 'nettertech_events_rsvp', $shortcode_name );
	}

	// =========================================================================
	// Render - Occurrence Not Found Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_returns_error_when_occurrence_not_found(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( null );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertStringContainsString( 'nte-rsvp-error', $result );
		$this->assertStringContainsString( 'Event not found', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_returns_error_when_occurrence_id_zero(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();

		// Repository should not be called for occurrence_id = 0.
		$this->occurrence_repo->shouldNotReceive( 'find_with_event' );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 0 ) );

		$this->assertStringContainsString( 'nte-rsvp-error', $result );
		$this->assertStringContainsString( 'Event not found', $result );
	}

	// =========================================================================
	// Render - Event Ended Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_returns_closed_when_event_ended(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( true );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( $occurrence );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertStringContainsString( 'nte-rsvp-closed', $result );
		$this->assertStringContainsString( 'event has ended', $result );
	}

	// =========================================================================
	// Render - Paid Tickets Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_returns_empty_when_has_paid_tickets(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->with( 123 )
			->andReturn( false );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertEmpty( $result );
	}

	// =========================================================================
	// Render - Form Display Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_displays_form_for_free_event(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->with( 123 )
			->andReturn( true );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertStringContainsString( 'nte-rsvp-form-wrapper', $result );
		$this->assertStringContainsString( '<form', $result );
		$this->assertStringContainsString( 'nte-rsvp-name', $result );
		$this->assertStringContainsString( 'nte-rsvp-email', $result );
		$this->assertStringContainsString( 'nte-rsvp-button', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_displays_party_selector_when_enabled(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->with( 123 )
			->andReturn( true );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123, 'show_party' => 'true' ) );

		$this->assertStringContainsString( 'nte-rsvp-quantity', $result );
		$this->assertStringContainsString( '<select', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_hides_party_selector_when_disabled(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->with( 123 )
			->andReturn( true );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123, 'show_party' => 'false' ) );

		$this->assertStringNotContainsString( '<select', $result );
		$this->assertStringContainsString( 'type="hidden" name="nettertech_events_rsvp_quantity" value="1"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_prefills_logged_in_user_info(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$user                = new \stdClass();
		$user->user_email    = 'john@example.com';
		$user->display_name  = 'John Doe';
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->with( 123 )
			->andReturn( true );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertStringContainsString( 'john@example.com', $result );
		$this->assertStringContainsString( 'John Doe', $result );
	}

	// =========================================================================
	// Handle Submission - Nonce Verification Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_returns_null_when_not_posted(): void {
		$_POST = array();

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertNull( $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_returns_error_on_nonce_failure(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit' => '1',
			'nettertech_events_rsvp_nonce'  => 'invalid_nonce',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( '__' )->returnArg();

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'Your session has expired', $result['text'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_verifies_nonce_with_occurrence_id(): void {
		$verified_action = null;

		$_POST = array(
			'nettertech_events_rsvp_submit' => '1',
			'nettertech_events_rsvp_nonce'  => 'some_nonce',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->alias(
			function ( $nonce, $action ) use ( &$verified_action ) {
				$verified_action = $action;
				return false;
			}
		);
		Functions\when( '__' )->returnArg();

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$method->invoke( $shortcode, 456 );

		$this->assertEquals( 'nettertech_events_rsvp_456', $verified_action );
	}

	// =========================================================================
	// Handle Submission - Occurrence ID Mismatch Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_returns_error_on_occurrence_id_mismatch(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '456', // Doesn't match 123.
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( '__' )->returnArg();

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'Invalid form submission', $result['text'] );
	}

	// =========================================================================
	// Handle Submission - Field Validation Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_returns_error_for_empty_name(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => '',
			'nettertech_events_rsvp_email'         => 'test@example.com',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( '__' )->returnArg();

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'enter your name', $result['text'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_returns_error_for_empty_email(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => '',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( false );
		Functions\when( '__' )->returnArg();

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'valid email', $result['text'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_returns_error_for_invalid_email(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'not-an-email',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->alias( fn( $e ) => '' ); // Invalid email sanitizes to empty.
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( false );
		Functions\when( '__' )->returnArg();

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'valid email', $result['text'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_normalizes_zero_quantity_to_one(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
			'nettertech_events_rsvp_quantity'      => '0',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->with( 123, 'john@example.com' )
			->andReturn( false );

		$saved_attendee = null;
		$this->attendee_repo
			->shouldReceive( 'save' )
			->with( Mockery::type( Attendee::class ) )
			->andReturnUsing( function ( $attendee ) use ( &$saved_attendee ) {
				$saved_attendee = $attendee;
				return $attendee;
			} );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 1, $saved_attendee->quantity );
	}

	// =========================================================================
	// Handle Submission - Duplicate Email Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_returns_error_for_duplicate_email(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->with( 123, 'john@example.com' )
			->andReturn( true );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'already registered', $result['text'] );
	}

	// =========================================================================
	// Handle Submission - Success Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_creates_attendee_on_success(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
			'nettertech_events_rsvp_quantity'      => '3',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->with( 123, 'john@example.com' )
			->andReturn( false );

		$saved_attendee = null;
		$this->attendee_repo
			->shouldReceive( 'save' )
			->with( Mockery::type( Attendee::class ) )
			->once()
			->andReturnUsing( function ( $attendee ) use ( &$saved_attendee ) {
				$saved_attendee = $attendee;
				return $attendee;
			} );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'success', $result['type'] );
		$this->assertStringContainsString( 'RSVP has been confirmed', $result['text'] );
		$this->assertEquals( 123, $saved_attendee->occurrence_id );
		$this->assertEquals( 'John Doe', $saved_attendee->name );
		$this->assertEquals( 'john@example.com', $saved_attendee->email );
		$this->assertEquals( 3, $saved_attendee->quantity );
		$this->assertEquals( 'confirmed', $saved_attendee->status );
	}

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_fires_action_on_success(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
			'nettertech_events_rsvp_quantity'      => '1',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		$action_fired      = false;
		$action_attendee_id = null;
		$action_form_data  = null;
		Functions\when( 'do_action' )->alias(
			function ( $action, $attendee_id = null, $form_data = null ) use ( &$action_fired, &$action_attendee_id, &$action_form_data ) {
				if ( 'nettertech_events_rsvp_submitted' === $action ) {
					$action_fired       = true;
					$action_attendee_id = $attendee_id;
					$action_form_data   = $form_data;
				}
			}
		);

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		$this->attendee_repo
			->shouldReceive( 'save' )
			->once();

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$method->invoke( $shortcode, 123 );

		$this->assertTrue( $action_fired );
		$this->assertIsInt( $action_attendee_id );
		$this->assertIsArray( $action_form_data );
		$this->assertArrayHasKey( 'occurrence_id', $action_form_data );
		$this->assertArrayHasKey( 'email', $action_form_data );
		$this->assertArrayHasKey( 'name', $action_form_data );
		$this->assertArrayHasKey( 'quantity', $action_form_data );
	}

	// =========================================================================
	// Handle Submission - Error Handling Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_returns_error_on_save_exception(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		$this->attendee_repo
			->shouldReceive( 'save' )
			->andThrow( new \RuntimeException( 'Database error' ) );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'error occurred', $result['text'] );
	}

	// =========================================================================
	// Render - Message Display Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_displays_error_message(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit' => '1',
			'nettertech_events_rsvp_nonce'  => 'invalid',
		);

		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->andReturn( true );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertStringContainsString( 'nte-rsvp-error', $result );
		$this->assertStringContainsString( 'Your session has expired', $result );
		// Form should still display after error.
		$this->assertStringContainsString( '<form', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_hides_form_after_success(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
		);

		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'do_action' )->justReturn( null );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->andReturn( true );

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		$this->attendee_repo
			->shouldReceive( 'save' );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertStringContainsString( 'nte-rsvp-success', $result );
		$this->assertStringContainsString( 'RSVP has been confirmed', $result );
		// Form should NOT display after success.
		$this->assertStringNotContainsString( '<form', $result );
	}

	// =========================================================================
	// Render - Max Party Options Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_generates_correct_party_size_options(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->andReturn( true );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123, 'max_party' => 5 ) );

		// Should have options 1-5.
		$this->assertStringContainsString( '<option value="1">', $result );
		$this->assertStringContainsString( '<option value="2">', $result );
		$this->assertStringContainsString( '<option value="3">', $result );
		$this->assertStringContainsString( '<option value="4">', $result );
		$this->assertStringContainsString( '<option value="5">', $result );
		// Should NOT have option 6.
		$this->assertStringNotContainsString( '<option value="6">', $result );
	}

	// =========================================================================
	// Render - Custom Button Text Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_uses_custom_button_text(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->andReturn( true );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123, 'button_text' => 'Register Now' ) );

		$this->assertStringContainsString( 'Register Now', $result );
	}

	// =========================================================================
	// Render - CSS Output Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_inline_styles(): void {
		// Reset static styles flag so wp_add_inline_style is called in this test.
		$reflection = new \ReflectionClass( RSVPFormShortcode::class );
		$prop       = $reflection->getProperty( 'styles_enqueued' );
		$prop->setValue( null, false );

		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$inline_style_handle = null;
		$inline_style_css    = null;
		Functions\when( 'wp_add_inline_style' )->alias(
			function ( $handle, $css ) use ( &$inline_style_handle, &$inline_style_css ) {
				$inline_style_handle = $handle;
				$inline_style_css    = $css;
			}
		);

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->andReturn( true );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		// Styles are now enqueued via wp_add_inline_style, not inline in the HTML.
		$this->assertStringNotContainsString( '<style>', $result );
		$this->assertSame( 'nettertech-events-base', $inline_style_handle );
		$this->assertStringContainsString( '.nte-rsvp-form-wrapper', $inline_style_css );
		$this->assertStringContainsString( '.nte-rsvp-button', $inline_style_css );
	}

	// =========================================================================
	// Sanitization Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_sanitizes_name_input(): void {
		$sanitized_value = null;

		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => '<script>alert("xss")</script>John',
			'nettertech_events_rsvp_email'         => 'john@example.com',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $str ) use ( &$sanitized_value ) {
				$sanitized_value = $str;
				return strip_tags( $str );
			}
		);
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		$saved_attendee = null;
		$this->attendee_repo
			->shouldReceive( 'save' )
			->andReturnUsing( function ( $attendee ) use ( &$saved_attendee ) {
				$saved_attendee = $attendee;
				return $attendee;
			} );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$method->invoke( $shortcode, 123 );

		// Script tags should be stripped (strip_tags removes tags but keeps content inside).
		$this->assertEquals( 'alert("xss")John', $saved_attendee->name );
	}

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_sanitizes_email_input(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john+test@example.com',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->alias(
			fn( $e ) => filter_var( $e, FILTER_SANITIZE_EMAIL )
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		$saved_attendee = null;
		$this->attendee_repo
			->shouldReceive( 'save' )
			->andReturnUsing( function ( $attendee ) use ( &$saved_attendee ) {
				$saved_attendee = $attendee;
				return $attendee;
			} );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$method->invoke( $shortcode, 123 );

		$this->assertEquals( 'john+test@example.com', $saved_attendee->email );
	}

	// =========================================================================
	// Capacity Checking - Render Time Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_hides_form_when_capacity_is_zero(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->with( 123 )
			->andReturn( true );

		// Override default: capacity is 0.
		$this->capacity_calculator
			->shouldReceive( 'get_occurrence_capacity' )
			->with( 123, true )
			->andReturn( array(
				'total_capacity'  => 50,
				'total_sold'      => 50,
				'total_available' => 0,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			) );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertStringContainsString( 'nte-rsvp-error', $result );
		$this->assertStringContainsString( 'event is full', $result );
		$this->assertStringNotContainsString( '<form', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_shows_form_when_capacity_is_unlimited(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->with( 123 )
			->andReturn( true );

		// Explicitly unlimited capacity.
		$this->capacity_calculator
			->shouldReceive( 'get_occurrence_capacity' )
			->with( 123, true )
			->andReturn( array(
				'total_capacity'  => null,
				'total_sold'      => 0,
				'total_available' => null,
				'has_unlimited'   => true,
				'ticket_types'    => array(),
			) );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertStringContainsString( '<form', $result );
		$this->assertStringContainsString( 'nte-rsvp-form', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_shows_form_when_capacity_available(): void {
		Functions\when( 'shortcode_atts' )->alias(
			fn( $defaults, $atts ) => array_merge( $defaults, $atts )
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$this->occurrence_repo
			->shouldReceive( 'find_with_event' )
			->with( 123 )
			->andReturn( $occurrence );

		$this->ticket_type_repo
			->shouldReceive( 'occurrence_is_free' )
			->with( 123 )
			->andReturn( true );

		// Finite capacity with spots available.
		$this->capacity_calculator
			->shouldReceive( 'get_occurrence_capacity' )
			->with( 123, true )
			->andReturn( array(
				'total_capacity'  => 50,
				'total_sold'      => 20,
				'total_available' => 30,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			) );

		$shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);

		$result = $shortcode->render( array( 'occurrence_id' => 123 ) );

		$this->assertStringContainsString( '<form', $result );
		$this->assertStringContainsString( 'nte-rsvp-form', $result );
	}

	// =========================================================================
	// Capacity Checking - Submission Time Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_submission_succeeds_when_capacity_available(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
			'nettertech_events_rsvp_quantity'      => '2',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		$this->attendee_repo
			->shouldReceive( 'save' )
			->once();

		// 10 spots available, requesting 2.
		$this->capacity_calculator
			->shouldReceive( 'get_occurrence_capacity' )
			->with( 123, true )
			->andReturn( array(
				'total_capacity'  => 50,
				'total_sold'      => 40,
				'total_available' => 10,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			) );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'success', $result['type'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_submission_rejected_when_capacity_is_zero(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
			'nettertech_events_rsvp_quantity'      => '1',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		// Zero capacity.
		$this->capacity_calculator
			->shouldReceive( 'get_occurrence_capacity' )
			->with( 123, true )
			->andReturn( array(
				'total_capacity'  => 50,
				'total_sold'      => 50,
				'total_available' => 0,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			) );

		// Attendee should NOT be saved.
		$this->attendee_repo->shouldNotReceive( 'save' );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'event is full', $result['text'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_submission_rejected_when_capacity_insufficient_for_party_size(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
			'nettertech_events_rsvp_quantity'      => '5',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		// Only 3 spots left, requesting 5.
		$this->capacity_calculator
			->shouldReceive( 'get_occurrence_capacity' )
			->with( 123, true )
			->andReturn( array(
				'total_capacity'  => 50,
				'total_sold'      => 47,
				'total_available' => 3,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			) );

		// Attendee should NOT be saved.
		$this->attendee_repo->shouldNotReceive( 'save' );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( '3 spot(s) remaining', $result['text'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_submission_proceeds_when_capacity_is_unlimited(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
			'nettertech_events_rsvp_quantity'      => '10',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		$this->attendee_repo
			->shouldReceive( 'save' )
			->once();

		// Explicitly unlimited.
		$this->capacity_calculator
			->shouldReceive( 'get_occurrence_capacity' )
			->with( 123, true )
			->andReturn( array(
				'total_capacity'  => null,
				'total_sold'      => 100,
				'total_available' => null,
				'has_unlimited'   => true,
				'ticket_types'    => array(),
			) );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'success', $result['type'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_submission_proceeds_when_no_capacity_configured(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit'        => '1',
			'nettertech_events_rsvp_nonce'         => 'valid_nonce',
			'nettertech_events_rsvp_occurrence_id' => '123',
			'nettertech_events_rsvp_name'          => 'John Doe',
			'nettertech_events_rsvp_email'         => 'john@example.com',
			'nettertech_events_rsvp_quantity'      => '1',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$this->attendee_repo
			->shouldReceive( 'email_exists_for_occurrence' )
			->andReturn( false );

		$this->attendee_repo
			->shouldReceive( 'save' )
			->once();

		// No ticket types configured — total_capacity is null but not unlimited.
		$this->capacity_calculator
			->shouldReceive( 'get_occurrence_capacity' )
			->with( 123, true )
			->andReturn( array(
				'total_capacity'  => null,
				'total_sold'      => 0,
				'total_available' => null,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			) );

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'success', $result['type'] );
	}

	// =========================================================================
	// Capacity Checking - Waitlist Message Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_build_full_message_mentions_waitlist_when_filter_enabled(): void {
		Functions\when( '__' )->returnArg();

		// Simulate Pro enabling waitlist via filter.
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) {
				if ( $hook === 'nettertech_events_has_waitlist' ) {
					return true;
				}
				return $value;
			}
		);

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'build_full_message' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'waitlist', $result['text'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_build_full_message_mentions_waitlist_by_default(): void {
		Functions\when( '__' )->returnArg();

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'build_full_message' );

		$result = $method->invoke( $shortcode, 123 );

		// Waitlist is enabled by default in Base (since 2.1.0).
		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'waitlist', $result['text'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_build_full_message_no_waitlist_when_filter_disabled(): void {
		Functions\when( '__' )->returnArg();

		// Simulate disabling waitlist via filter.
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) {
				if ( $hook === 'nettertech_events_has_waitlist' ) {
					return false;
				}
				return $value;
			}
		);

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$this->rate_limit_service
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'build_full_message' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'event is full', $result['text'] );
		$this->assertStringNotContainsString( 'waitlist', $result['text'] );
	}

	// =========================================================================
	// Rate Limiting Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_handle_submission_rate_limited(): void {
		$_POST = array(
			'nettertech_events_rsvp_submit' => '1',
			'nettertech_events_rsvp_nonce'  => 'valid_nonce',
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();

		$rate_limit = Mockery::mock( RateLimitService::class );
		$rate_limit->shouldReceive( 'should_bypass' )->once()->andReturn( false );
		$rate_limit->shouldReceive( 'check_and_increment' )->once()->andReturn(
			new \WP_REST_Response(
				array(
					'code'    => 'rate_limit_exceeded',
					'message' => 'Too many requests.',
				),
				429
			)
		);

		$shortcode  = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$this->capacity_calculator,
			$rate_limit
		);
		$reflection = new \ReflectionClass( $shortcode );
		$method     = $reflection->getMethod( 'handle_submission' );

		$result = $method->invoke( $shortcode, 123 );

		$this->assertIsArray( $result );
		$this->assertEquals( 'error', $result['type'] );
		$this->assertStringContainsString( 'Too many requests', $result['text'] );
	}
}
