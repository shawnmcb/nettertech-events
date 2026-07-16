<?php
/**
 * CarouselShortcode unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend\Shortcodes;

use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Frontend\Shortcodes\CarouselShortcode;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\Event;
use NetterTechEvents\TemplateLoader\Templates;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test CarouselShortcode class.
 *
 * Uses dependency injection to mock OccurrenceRepository for comprehensive
 * testing without database dependencies (ADR-008).
 *
 * @since 0.9.0
 * @coversDefaultClass \NetterTechEvents\Frontend\Shortcodes\CarouselShortcode
 */
class CarouselShortcodeTest extends \NetterTechEventsTestCase {

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Mock templates service.
	 *
	 * @var Templates|Mockery\MockInterface
	 */
	private $templates;

	/**
	 * Mock tag repository.
	 *
	 * @var \NetterTechEvents\Contracts\TagRepositoryInterface|Mockery\MockInterface
	 */
	private $tag_repo;

	/**
	 * Mock ticket type repository.
	 *
	 * @var \NetterTechEvents\Contracts\TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->occurrence_repo  = Mockery::mock( OccurrenceRepositoryInterface::class );
		$this->templates        = Mockery::mock( Templates::class );
		$this->templates->shouldReceive( 'get_template_part' )->byDefault()->andReturn( '' );
		$this->tag_repo         = Mockery::mock( \NetterTechEvents\Contracts\TagRepositoryInterface::class );
		$this->tag_repo->shouldIgnoreMissing( array() );
		$this->ticket_type_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$this->ticket_type_repo->shouldIgnoreMissing( array() );
	}

	/**
	 * Set up common render mocks.
	 *
	 * @return void
	 */
	private function setup_render_mocks(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $name ) {
				if ( ! is_array( $atts ) ) {
					$atts = array();
				}
				return array_merge( $defaults, $atts );
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text, $domain = 'default' ) {
				echo $text;
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();
	}

	// =========================================================================
	// Constructor Tests (DI)
	// =========================================================================

	/**
	 * @covers ::__construct
	 */
	public function test_constructor_accepts_repository(): void {
		$shortcode  = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$reflection = new \ReflectionClass( $shortcode );
		$prop       = $reflection->getProperty( 'occurrence_repo' );

		$this->assertSame( $this->occurrence_repo, $prop->getValue( $shortcode ) );
	}


	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * @covers ::__construct
	 */
	public function test_has_defaults_constant(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );

		$this->assertTrue( $reflection->hasConstant( 'DEFAULTS' ) );
	}

	/**
	 * @covers ::__construct
	 */
	public function test_defaults_contain_expected_keys(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$expected_keys = array(
			'limit',
			'columns',
			'show_image',
			'show_date',
			'show_time',
			'show_venue',
			'show_year',
			'autoplay',
			'interval',
			'max_tags',
			'class',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "DEFAULTS should have key: {$key}" );
		}
	}

	/**
	 * @covers ::__construct
	 */
	public function test_default_limit_is_six(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$this->assertEquals( 6, $defaults['limit'] );
	}

	/**
	 * @covers ::__construct
	 */
	public function test_default_columns_is_three(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$this->assertEquals( 3, $defaults['columns'] );
	}

	/**
	 * @covers ::__construct
	 */
	public function test_autoplay_disabled_by_default(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$this->assertFalse( $defaults['autoplay'] );
	}

	/**
	 * @covers ::__construct
	 */
	public function test_default_interval_is_5000(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$this->assertEquals( 5000, $defaults['interval'] );
	}

	// =========================================================================
	// Playback Mode Tests (NTE-061)
	// =========================================================================

	/**
	 * @covers ::__construct
	 */
	public function test_default_playback_mode_is_rewind(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$this->assertArrayHasKey( 'playback_mode', $defaults );
		$this->assertSame( 'rewind', $defaults['playback_mode'] );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_passes_through_loop_playback_mode(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'playback_mode' => 'loop' ) );

		$this->assertStringContainsString( 'data-playback-mode="loop"', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_normalizes_invalid_playback_mode_to_rewind(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		// Untrusted shortcode input - whitelist must reject anything outside ['rewind', 'loop'].
		$result = $shortcode->render( array( 'playback_mode' => 'spinny-marquee' ) );

		$this->assertStringContainsString( 'data-playback-mode="rewind"', $result );
		$this->assertStringNotContainsString( 'data-playback-mode="spinny-marquee"', $result );
	}

	/**
	 * Verifies the PHP-side contract that enables the JS reduced-motion override:
	 * the shortcode emits the operator's chosen mode faithfully on the
	 * `data-playback-mode` attribute. The JS layer reads that attribute and
	 * forces 'rewind' at runtime via `matchMedia('(prefers-reduced-motion: reduce)')`
	 * - the override is intentionally client-side because PHP cannot detect
	 * the user's motion preference.
	 *
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_emits_data_playback_mode_attribute_for_client_override(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );

		// Default render: attribute present so JS can read it and apply the override.
		$default_result = $shortcode->render();
		$this->assertStringContainsString( 'data-playback-mode="rewind"', $default_result );

		// Operator-set loop: attribute reflects choice; JS handles reduced-motion downgrade.
		$loop_result = $shortcode->render( array( 'playback_mode' => 'loop' ) );
		$this->assertStringContainsString( 'data-playback-mode="loop"', $loop_result );
	}

	// =========================================================================
	// Max Tags Tests (NTE-063)
	// =========================================================================

	/**
	 * @covers ::__construct
	 */
	public function test_default_max_tags_is_three(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$this->assertArrayHasKey( 'max_tags', $defaults );
		$this->assertSame( 3, $defaults['max_tags'] );
	}

	/**
	 * Verify max_tags is passed through to the card template args.
	 *
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_passes_max_tags_to_card_template(): void {
		$this->setup_render_mocks();

		$event      = Mockery::mock( \NetterTechEvents\Models\Event::class );
		$occurrence = Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array( $occurrence ) );

		$captured_args = null;
		$this->templates->shouldReceive( 'get_template_part' )
			->once()
			->andReturnUsing(
				function ( $template, $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return '';
				}
			);

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$shortcode->render( array( 'max_tags' => 5 ) );

		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'max_tags', $captured_args );
		$this->assertSame( 5, $captured_args['max_tags'] );
	}

	/**
	 * max_tags=0 passes through as zero (show all, no overflow indicator).
	 *
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_max_tags_zero_passes_as_zero(): void {
		$this->setup_render_mocks();

		$event      = Mockery::mock( \NetterTechEvents\Models\Event::class );
		$occurrence = Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array( $occurrence ) );

		$captured_args = null;
		$this->templates->shouldReceive( 'get_template_part' )
			->once()
			->andReturnUsing(
				function ( $template, $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return '';
				}
			);

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$shortcode->render( array( 'max_tags' => 0 ) );

		$this->assertNotNull( $captured_args );
		$this->assertSame( 0, $captured_args['max_tags'] );
	}

	/**
	 * Negative max_tags is clamped to 0 (show all — negative cap is meaningless).
	 *
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_negative_max_tags_becomes_zero(): void {
		$this->setup_render_mocks();

		$event      = Mockery::mock( \NetterTechEvents\Models\Event::class );
		$occurrence = Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array( $occurrence ) );

		$captured_args = null;
		$this->templates->shouldReceive( 'get_template_part' )
			->once()
			->andReturnUsing(
				function ( $template, $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return '';
				}
			);

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		// Negative input from untrusted shortcode attribute — max(0, ...) clamps to 0.
		$shortcode->render( array( 'max_tags' => '-5' ) );

		$this->assertNotNull( $captured_args );
		$this->assertSame( 0, $captured_args['max_tags'] );
	}

	// =========================================================================
	// Render - Basic Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_returns_string(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array() );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render();

		$this->assertIsString( $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_uses_correct_shortcode_name(): void {
		$shortcode_name = null;

		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $name ) use ( &$shortcode_name ) {
				$shortcode_name = $name;
				return $defaults;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( '__' )->returnArg();

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array() );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$shortcode->render();

		$this->assertEquals( 'nettertech_events_carousel', $shortcode_name );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_calls_repository_with_limit(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->once()
			->with( 10 )
			->andReturn( array() );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$shortcode->render( array( 'limit' => 10 ) );

		$this->assertTrue( true ); // Mockery verifies the call.
	}

	/**
	 * @covers ::render
	 */
	public function test_render_uses_default_limit_of_six(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->once()
			->with( 6 )
			->andReturn( array() );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$shortcode->render();

		$this->assertTrue( true ); // Mockery verifies the call.
	}

	// =========================================================================
	// Render - Empty State Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_empty_state_when_no_occurrences(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array() );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'nte-carousel--empty', $result );
		$this->assertStringContainsString( 'No upcoming events', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_empty_applies_filter(): void {
		$filtered_message = 'Custom empty message';

		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $name ) {
				if ( ! is_array( $atts ) ) {
					$atts = array();
				}
				return array_merge( $defaults, $atts );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) use ( $filtered_message ) {
				if ( 'nettertech_events_carousel_empty_message' === $filter ) {
					return $filtered_message;
				}
				return $value;
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( '__' )->returnArg();

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array() );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( $filtered_message, $result );
	}

	// =========================================================================
	// Render - Carousel Structure Tests
	// =========================================================================

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_carousel_wrapper(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'nte-carousel', $result );
		$this->assertStringNotContainsString( 'nte-carousel--empty', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_includes_track(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'nte-carousel__track', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_includes_data_columns(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'columns' => 4 ) );

		$this->assertStringContainsString( 'data-columns="4"', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_includes_data_autoplay(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'autoplay' => 'true' ) );

		$this->assertStringContainsString( 'data-autoplay="true"', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_includes_data_interval(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'interval' => 3000 ) );

		$this->assertStringContainsString( 'data-interval="3000"', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_includes_custom_class(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'class' => 'my-carousel' ) );

		$this->assertStringContainsString( 'my-carousel', $result );
	}

	// =========================================================================
	// Render - Navigation Tests
	// =========================================================================

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_shows_navigation_when_events_exceed_columns(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 5 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'columns' => 3 ) );

		$this->assertStringContainsString( 'nte-carousel__nav--prev', $result );
		$this->assertStringContainsString( 'nte-carousel__nav--next', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_hides_navigation_when_events_fit_in_columns(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 3 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'columns' => 3 ) );

		$this->assertStringNotContainsString( 'nte-carousel__nav--prev', $result );
		$this->assertStringNotContainsString( 'nte-carousel__nav--next', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_navigation_has_aria_labels(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 5 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'columns' => 3 ) );

		$this->assertStringContainsString( 'aria-label="Previous"', $result );
		$this->assertStringContainsString( 'aria-label="Next"', $result );
	}

	// =========================================================================
	// Render - Accessibility Tests
	// =========================================================================

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_includes_role_region(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'role="region"', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_includes_aria_label(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'aria-label="Events carousel"', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_includes_live_region(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'nte-carousel__live-region', $result );
		$this->assertStringContainsString( 'aria-live="polite"', $result );
	}

	// =========================================================================
	// Render - Card Rendering Tests
	// =========================================================================

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_delegates_to_templates(): void {
		$this->setup_render_mocks();

		// Create mock occurrence with event.
		$event      = Mockery::mock( Event::class );
		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array( $occurrence ) );

		// Verify get_template_part is called on injected instance.
		$templates_called = false;
		$this->templates->shouldReceive( 'get_template_part' )
			->once()
			->with(
				'event-card',
				Mockery::type( 'array' )
			)
			->andReturnUsing(
				function ( $template, $args ) use ( &$templates_called ) {
					$templates_called = true;
					return '<div class="nte-event-card">Test Card</div>';
				}
			);

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render();

		$this->assertTrue( $templates_called );
		$this->assertStringContainsString( 'Test Card', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_card_passes_correct_args(): void {
		$this->setup_render_mocks();

		// Create mock occurrence with event.
		$event      = Mockery::mock( Event::class );
		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array( $occurrence ) );

		// Capture args passed to get_template_part on injected instance.
		$captured_args = null;
		$this->templates->shouldReceive( 'get_template_part' )
			->once()
			->andReturnUsing(
				function ( $template, $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return '';
				}
			);

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$shortcode->render(
			array(
				'show_image' => 'true',
				'show_date'  => 'false',
				'show_time'  => 'true',
				'show_venue' => 'false',
			)
		);

		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'occurrence', $captured_args );
		$this->assertArrayHasKey( 'event', $captured_args );
		$this->assertTrue( $captured_args['show_image'] );
		$this->assertFalse( $captured_args['show_date'] );
		$this->assertTrue( $captured_args['show_time'] );
		$this->assertFalse( $captured_args['show_venue'] );
		$this->assertFalse( $captured_args['show_excerpt'] );
	}

	// =========================================================================
	// Render - Boolean Attribute Handling Tests
	// =========================================================================

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_normalizes_string_true_to_boolean(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'autoplay' => 'true' ) );

		// autoplay attribute becomes boolean, reflected in data attribute.
		$this->assertStringContainsString( 'data-autoplay="true"', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_normalizes_string_false_to_boolean(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'autoplay' => 'false' ) );

		$this->assertStringContainsString( 'data-autoplay="false"', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_normalizes_yes_to_boolean_true(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'autoplay' => 'yes' ) );

		$this->assertStringContainsString( 'data-autoplay="true"', $result );
	}

	/**
	 * @covers ::render
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_normalizes_one_to_boolean_true(): void {
		$this->setup_render_mocks();
		$this->setup_occurrences_mock( 1 );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );
		$result    = $shortcode->render( array( 'autoplay' => '1' ) );

		$this->assertStringContainsString( 'data-autoplay="true"', $result );
	}

	// =========================================================================
	// Render - Content Parameter Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_accepts_content_parameter(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array() );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );

		// Should not throw - content parameter is accepted but unused.
		$result = $shortcode->render( array(), 'Some content' );

		$this->assertIsString( $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_accepts_string_atts(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( array() );

		$shortcode = new CarouselShortcode( $this->occurrence_repo, $this->templates, $this->tag_repo, $this->ticket_type_repo );

		// WordPress sometimes passes empty string for atts.
		$result = $shortcode->render( '' );

		$this->assertIsString( $result );
	}

	// =========================================================================
	// Private Method Tests (via Reflection)
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_empty_method_exists(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );

		$this->assertTrue(
			$reflection->hasMethod( 'render_empty' ),
			'Class should have render_empty method'
		);
	}

	/**
	 * @covers ::render
	 */
	public function test_render_carousel_method_exists(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );

		$this->assertTrue(
			$reflection->hasMethod( 'render_carousel' ),
			'Class should have render_carousel method'
		);
	}

	/**
	 * @covers ::render
	 */
	public function test_render_card_method_exists(): void {
		$reflection = new \ReflectionClass( CarouselShortcode::class );

		$this->assertTrue(
			$reflection->hasMethod( 'render_card' ),
			'Class should have render_card method'
		);
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Set up mock occurrences.
	 *
	 * @param int $count Number of occurrences to create.
	 * @return void
	 */
	private function setup_occurrences_mock( int $count ): void {
		$occurrences = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$event      = Mockery::mock( Event::class );
			$occurrence = Mockery::mock( Occurrence::class );
			$occurrence->shouldReceive( 'get_event' )->andReturn( $event );
			$occurrences[] = $occurrence;
		}

		$this->occurrence_repo
			->shouldReceive( 'upcoming' )
			->andReturn( $occurrences );

		// Override default template mock to return card HTML.
		$this->templates->shouldReceive( 'get_template_part' )
			->andReturn( '<div class="nte-event-card">Card</div>' );
	}
}
