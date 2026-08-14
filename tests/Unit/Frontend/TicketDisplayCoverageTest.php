<?php
/**
 * TicketDisplay coverage-targeted unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Frontend\Shortcodes\RSVPFormShortcode;
use NetterTechEvents\Frontend\TicketDisplay;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Behavior coverage for TicketDisplay.
 *
 * Existing TicketDisplayTest covers class structure only. This suite exercises
 * the render_occurrence_actions dispatch branches and reset-aware static state.
 *
 * @coversDefaultClass \NetterTechEvents\Frontend\TicketDisplay
 */
class TicketDisplayCoverageTest extends \NetterTechEventsTestCase {

	/**
	 * Mock capacity service.
	 *
	 * @var CapacityServiceInterface|Mockery\MockInterface
	 */
	private $capacity_service;

	/**
	 * Mock ticket-type repo.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Mock templates.
	 *
	 * @var Templates|Mockery\MockInterface
	 */
	private $templates;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NETTERTECH_EVENTS_VERSION' ) ) {
			define( 'NETTERTECH_EVENTS_VERSION', '1.0.0' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_add_inline_script' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->capacity_service = Mockery::mock( CapacityServiceInterface::class );
		$this->ticket_type_repo = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->templates        = Mockery::mock( Templates::class );

		// Reset static state via reflection so each test starts clean.
		$ref = new \ReflectionClass( TicketDisplay::class );
		foreach ( array( 'scripts_enqueued', 'ticket_type_repo', 'capacity_service', 'templates', 'rsvp_shortcode', 'occurrence_repo' ) as $prop_name ) {
			if ( $ref->hasProperty( $prop_name ) ) {
				$prop  = $ref->getProperty( $prop_name );
				$type  = $prop->getType();
				$value = ( $type && $type->allowsNull() ) ? null : false;
				$prop->setValue( null, $value );
			}
		}
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Test init injects deps.
	 *
	 * @return void
	 */
	public function test_init_injects_dependencies(): void {
		TicketDisplay::init( $this->capacity_service, $this->ticket_type_repo, $this->templates );

		$ref      = new \ReflectionClass( TicketDisplay::class );
		$repo_val = $ref->getProperty( 'ticket_type_repo' )->getValue();

		$this->assertSame( $this->ticket_type_repo, $repo_val );
	}

	/**
	 * Test render_occurrence_actions noop when no ticket types.
	 *
	 * @return void
	 */
	public function test_render_returns_when_no_ticket_types(): void {
		TicketDisplay::init( $this->capacity_service, $this->ticket_type_repo, $this->templates );

		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array() );

		$event       = new Event();
		$event->id   = 1;
		$occ         = Mockery::mock( Occurrence::class );
		$occ->id     = 5;

		ob_start();
		TicketDisplay::render_occurrence_actions( $occ, $event );
		$out = ob_get_clean();

		$this->assertSame( '', $out );
	}

	/**
	 * An event-scope (series pass) tier is filtered out of the per-date form —
	 * it belongs in the dedicated Series Pass section, not repeated per date
	 * (NTE-156). With only a pass tier on sale, the occurrence form renders
	 * nothing.
	 *
	 * @return void
	 */
	public function test_render_occurrence_actions_excludes_series_pass(): void {
		TicketDisplay::init( $this->capacity_service, $this->ticket_type_repo, $this->templates );

		$pass        = Mockery::mock( TicketType::class );
		$pass->scope = 'event';
		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array( $pass ) );

		$occ     = Mockery::mock( Occurrence::class );
		$occ->id = 5;
		$event   = new Event();
		$event->id = 1;

		ob_start();
		TicketDisplay::render_occurrence_actions( $occ, $event );
		$out = ob_get_clean();

		$this->assertSame( '', $out );
	}

	/**
	 * Series-pass render is a paid path: with WooCommerce absent (as in the test
	 * environment) it emits nothing and never queries for pass tiers (NTE-156).
	 *
	 * @return void
	 */
	public function test_series_pass_noop_without_woocommerce(): void {
		$occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );
		TicketDisplay::init( $this->capacity_service, $this->ticket_type_repo, $this->templates, null, $occurrence_repo );

		// The paid-path gate must short-circuit before any data access.
		$this->ticket_type_repo->shouldNotReceive( 'get_on_sale_for_event' );
		$occurrence_repo->shouldNotReceive( 'next_for_event' );

		$event     = new Event();
		$event->id = 7;

		ob_start();
		TicketDisplay::render_event_series_pass( $event );
		$out = ob_get_clean();

		$this->assertSame( '', $out );
	}

	/**
	 * Series-pass render noops for an unsaved event (null id) — nothing to query.
	 *
	 * @return void
	 */
	public function test_series_pass_noop_when_event_id_null(): void {
		$occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );
		TicketDisplay::init( $this->capacity_service, $this->ticket_type_repo, $this->templates, null, $occurrence_repo );

		$this->ticket_type_repo->shouldNotReceive( 'get_on_sale_for_event' );
		$occurrence_repo->shouldNotReceive( 'next_for_event' );

		$event = new Event(); // id stays null.

		ob_start();
		TicketDisplay::render_event_series_pass( $event );
		$out = ob_get_clean();

		$this->assertSame( '', $out );
	}

	/**
	 * Test render shows past-event badge when occurrence ended.
	 *
	 * @return void
	 */
	public function test_render_shows_past_badge_when_ended(): void {
		TicketDisplay::init( $this->capacity_service, $this->ticket_type_repo, $this->templates );

		$tt = Mockery::mock( TicketType::class );
		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array( $tt ) );

		$event       = new Event();
		$event->id   = 1;
		$occ         = Mockery::mock( Occurrence::class );
		$occ->id     = 5;
		$occ->shouldReceive( 'has_ended' )->andReturn( true );

		ob_start();
		TicketDisplay::render_occurrence_actions( $occ, $event );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Past Event', $out );
	}

	/**
	 * Test render outputs RSVP button when occurrence is free.
	 *
	 * @return void
	 */
	public function test_render_outputs_rsvp_button_when_free(): void {
		$rsvp_mock = Mockery::mock( RSVPFormShortcode::class );
		$rsvp_mock->shouldReceive( 'render' )->andReturn( '<form>rsvp</form>' );

		TicketDisplay::init( $this->capacity_service, $this->ticket_type_repo, $this->templates, $rsvp_mock );

		$tt = Mockery::mock( TicketType::class );
		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array( $tt ) );
		$this->ticket_type_repo->shouldReceive( 'occurrence_is_free' )->andReturn( true );

		$event       = new Event();
		$event->id   = 1;
		$occ         = Mockery::mock( Occurrence::class );
		$occ->id     = 5;
		$occ->shouldReceive( 'has_ended' )->andReturn( false );

		ob_start();
		TicketDisplay::render_occurrence_actions( $occ, $event );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'nte-rsvp-toggle', $out );
		$this->assertStringContainsString( 'rsvp-form-5', $out );
	}

	/**
	 * Test render outputs ticket cards when WooCommerce inactive (fallback path).
	 *
	 * @return void
	 */
	public function test_render_outputs_ticket_cards_when_wc_inactive(): void {
		$this->assertFalse( class_exists( 'WooCommerce' ) );

		TicketDisplay::init( $this->capacity_service, $this->ticket_type_repo, $this->templates );

		$tt              = Mockery::mock( TicketType::class );
		$tt->id          = 100;
		$tt->name        = 'General';
		$tt->description = 'Standard ticket';
		$tt->shouldReceive( 'get_formatted_price' )->andReturn( '$10.00' );

		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array( $tt ) );
		$this->ticket_type_repo->shouldReceive( 'occurrence_is_free' )->andReturn( false );

		$event       = new Event();
		$event->id   = 1;
		$occ         = Mockery::mock( Occurrence::class );
		$occ->id     = 5;
		$occ->shouldReceive( 'has_ended' )->andReturn( false );

		ob_start();
		TicketDisplay::render_occurrence_actions( $occ, $event );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'nte-ticket-card', $out );
		$this->assertStringContainsString( 'General', $out );
		$this->assertStringContainsString( '$10.00', $out );
	}
}
