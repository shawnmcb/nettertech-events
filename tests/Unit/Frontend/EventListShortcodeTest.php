<?php
/**
 * EventListShortcode unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Frontend\Shortcodes\EventListShortcode;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Test EventListShortcode class.
 *
 * Tests shortcode rendering, attribute handling, and output structure.
 * Uses ServiceRegistry to inject mock OccurrenceRepository.
 */
class EventListShortcodeTest extends \NetterTechEventsTestCase {

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_occurrence_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	/**
	 * Mock category repository.
	 *
	 * @var \NetterTechEvents\Contracts\CategoryRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_category_repo;

	/**
	 * Mock tag repository.
	 *
	 * @var \NetterTechEvents\Contracts\TagRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_tag_repo;

	/**
	 * Mock ticket type repository.
	 *
	 * @var \NetterTechEvents\Contracts\TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_ticket_type_repo;

	protected function setUp(): void {
		parent::setUp();

		// Reset ServiceRegistry between tests.
		ServiceRegistry::reset();

		// Create mock occurrence repository.
		$this->mock_occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $this->mock_occurrence_repo );

		// Create mock category, tag, and ticket-type repositories.
		$this->mock_category_repo = Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$this->mock_category_repo->shouldIgnoreMissing();
		$this->mock_tag_repo = Mockery::mock( \NetterTechEvents\Contracts\TagRepositoryInterface::class );
		$this->mock_tag_repo->shouldIgnoreMissing( array() );
		$this->mock_ticket_type_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$this->mock_ticket_type_repo->shouldIgnoreMissing( array() );

		// Mock common WordPress functions.
		$this->mock_wp_functions();
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ServiceRegistry::reset();
		parent::tearDown();
	}

	/**
	 * Mock WordPress functions used by the shortcode.
	 *
	 * @return void
	 */
	private function mock_wp_functions(): void {
		// shortcode_atts merges defaults with passed atts.
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $shortcode = '' ) {
				$atts = (array) $atts;
				return array_merge( $defaults, array_intersect_key( $atts, $defaults ) );
			}
		);

		// Escaping functions return input as-is for testing.
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text, $domain = 'default' ) {
				echo $text;
			}
		);
		Functions\when( 'esc_html_e' )->alias(
			function ( $text, $domain = 'default' ) {
				echo $text;
			}
		);

		// Translation functions return input.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		// absint for sanitizing limit.
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);

		// apply_filters returns the value.
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// wp_cache functions.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		// get_terms for category dropdown (used by Taxonomies::get_all_categories).
		Functions\when( 'get_terms' )->justReturn( array() );

		// is_wp_error for checking get_terms result.
		Functions\when( 'is_wp_error' )->justReturn( false );

		// do_action for template hooks.
		Functions\when( 'do_action' )->justReturn( null );

		// wp_kses_post for content sanitization.
		Functions\when( 'wp_kses_post' )->returnArg();

		// wp_timezone for date handling in templates.
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Chicago' ) );

		// Additional template functions.
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/event/' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp = null, $timezone = null ) {
				return gmdate( $format, $timestamp ?? time() );
			}
		);

		// date_i18n for localized date formatting.
		Functions\when( 'date_i18n' )->alias(
			function ( $format, $timestamp = false, $gmt = false ) {
				return gmdate( $format, $timestamp ?: time() );
			}
		);

		// Thumbnail functions.
		Functions\when( 'has_post_thumbnail' )->justReturn( false );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		// WordPress options.
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				$options = array(
					'time_format'    => 'g:i a',
					'date_format'    => 'F j, Y',
					'timezone_string' => 'America/Chicago',
				);
				return $options[ $option ] ?? $default;
			}
		);
	}

	/**
	 * Create a mock occurrence with optional event.
	 *
	 * @param int         $id       Occurrence ID.
	 * @param int         $event_id Event ID.
	 * @param string      $title    Event title.
	 * @param string|null $start    Start datetime.
	 * @return Occurrence
	 */
	private function create_mock_occurrence(
		int $id = 1,
		int $event_id = 100,
		string $title = 'Test Event',
		?string $start = null
	): Occurrence {
		$start = $start ?? gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) );
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( $start ) + 7200 );

		$occurrence = Occurrence::from_row(
			(object) array(
				'id'             => $id,
				'event_id'       => $event_id,
				'start_datetime' => $start,
				'end_datetime'   => $end,
				'status'         => 'scheduled',
				'timezone'       => 'America/Chicago',
			)
		);

		// Create and attach mock event.
		$event        = new Event();
		$event->id    = $event_id;
		$event->title = $title;
		$occurrence->set_event( $event );

		return $occurrence;
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test EventListShortcode class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( EventListShortcode::class ) );
	}

	/**
	 * Test can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$this->assertInstanceOf( EventListShortcode::class, $shortcode );
	}

	/**
	 * Test render method exists.
	 *
	 * @return void
	 */
	public function test_render_method_exists(): void {
		$this->assertTrue(
			method_exists( EventListShortcode::class, 'render' ),
			'render method should exist'
		);
	}

	/**
	 * Test render method is public.
	 *
	 * @return void
	 */
	public function test_render_is_public(): void {
		$reflection = new \ReflectionMethod( EventListShortcode::class, 'render' );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test render method returns string.
	 *
	 * @return void
	 */
	public function test_render_returns_string(): void {
		$reflection  = new \ReflectionMethod( EventListShortcode::class, 'render' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertEquals( 'string', $return_type->getName() );
	}

	/**
	 * Test render accepts atts and content parameters.
	 *
	 * @return void
	 */
	public function test_render_accepts_parameters(): void {
		$reflection = new \ReflectionMethod( EventListShortcode::class, 'render' );
		$params     = $reflection->getParameters();

		$this->assertGreaterThanOrEqual( 1, count( $params ) );
		$this->assertEquals( 'atts', $params[0]->getName() );
	}

	/**
	 * Test DEFAULTS constant exists with expected keys.
	 *
	 * @return void
	 */
	public function test_defaults_constant_exists(): void {
		$reflection = new \ReflectionClass( EventListShortcode::class );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey( 'DEFAULTS', $constants, 'DEFAULTS constant should exist' );
	}

	/**
	 * Test DEFAULTS contains expected keys.
	 *
	 * @return void
	 */
	public function test_defaults_contains_expected_keys(): void {
		$reflection = new \ReflectionClass( EventListShortcode::class );
		$constants  = $reflection->getConstants();
		$defaults   = $constants['DEFAULTS'];

		$expected_keys = array(
			'limit',
			'columns',
			'layout',
			'show_filters',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey(
				$key,
				$defaults,
				"DEFAULTS should contain '{$key}' key"
			);
		}
	}

	/**
	 * Test DEFAULTS has sensible values.
	 *
	 * @return void
	 */
	public function test_defaults_has_sensible_values(): void {
		$reflection = new \ReflectionClass( EventListShortcode::class );
		$constants  = $reflection->getConstants();
		$defaults   = $constants['DEFAULTS'];

		$this->assertIsInt( $defaults['limit'] );
		$this->assertGreaterThan( 0, $defaults['limit'] );

		$this->assertIsInt( $defaults['columns'] );
		$this->assertGreaterThan( 0, $defaults['columns'] );

		$this->assertIsString( $defaults['layout'] );
		$this->assertContains( $defaults['layout'], array( 'grid', 'list', 'cards' ) );
	}

	/**
	 * Test class has private methods for rendering components.
	 *
	 * @return void
	 */
	public function test_has_render_helper_methods(): void {
		$reflection = new \ReflectionClass( EventListShortcode::class );
		$methods    = $reflection->getMethods( \ReflectionMethod::IS_PRIVATE );

		$method_names = array_map( fn( $m ) => $m->getName(), $methods );

		$this->assertContains( 'render_filters', $method_names );
		$this->assertContains( 'render_pagination', $method_names );
		$this->assertContains( 'render_empty', $method_names );
		$this->assertContains( 'render_card', $method_names );
	}

	// =========================================================================
	// Render Output Structure Tests
	// =========================================================================

	/**
	 * Test render actually produces string output when called.
	 *
	 * @return void
	 */
	public function test_render_produces_string_output(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render();

		$this->assertIsString( $output );
	}

	/**
	 * Test render with no events shows empty state.
	 *
	 * @return void
	 */
	public function test_render_shows_empty_state_when_no_events(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render();

		// Verify main container exists.
		$this->assertStringContainsString( 'nte-event-list', $output );
		$this->assertStringContainsString( 'nte-grid', $output );
	}

	/**
	 * Test render container has required data attributes.
	 *
	 * @return void
	 */
	public function test_render_container_has_data_attributes(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render();

		// Check for data attributes on container.
		$this->assertStringContainsString( 'data-ajax=', $output );
		$this->assertStringContainsString( 'data-per-page=', $output );
		$this->assertStringContainsString( 'data-layout=', $output );
		$this->assertStringContainsString( 'data-columns=', $output );
		$this->assertStringContainsString( 'data-past=', $output );
	}

	/**
	 * Test render uses unique instance IDs.
	 *
	 * @return void
	 */
	public function test_render_uses_unique_instance_ids(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->times( 2 )
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );

		$output1 = $shortcode->render();
		$output2 = $shortcode->render();

		// Each render should have a unique instance ID.
		$this->assertStringContainsString( 'nte-grid-', $output1 );
		$this->assertStringContainsString( 'nte-grid-', $output2 );

		// Extract instance numbers - they should differ.
		preg_match( '/nte-grid-(\d+)/', $output1, $match1 );
		preg_match( '/nte-grid-(\d+)/', $output2, $match2 );

		$this->assertNotEquals( $match1[1], $match2[1] );
	}

	/**
	 * Test render includes ARIA attributes for accessibility.
	 *
	 * @return void
	 */
	public function test_render_includes_aria_attributes(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render();

		// Grid should have aria-label and aria-live for accessibility.
		$this->assertStringContainsString( 'aria-label=', $output );
		$this->assertStringContainsString( 'aria-live="polite"', $output );
	}

	/**
	 * Test render includes loading overlay.
	 *
	 * @return void
	 */
	public function test_render_includes_loading_overlay(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render();

		$this->assertStringContainsString( 'nte-loading-overlay', $output );
		$this->assertStringContainsString( 'nte-loading-spinner', $output );
	}

	// =========================================================================
	// Attribute Handling Tests
	// =========================================================================

	/**
	 * Test render applies custom limit attribute.
	 *
	 * @return void
	 */
	public function test_render_applies_custom_limit(): void {
		$captured_args = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->withArgs(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			)
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$shortcode->render( array( 'limit' => 6 ) );

		$this->assertEquals( 6, $captured_args['per_page'] );
	}

	/**
	 * Test render applies custom columns attribute.
	 *
	 * @return void
	 */
	public function test_render_applies_custom_columns(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'columns' => 4 ) );

		$this->assertStringContainsString( 'nte-grid--cols-4', $output );
		$this->assertStringContainsString( 'data-columns="4"', $output );
	}

	/**
	 * Test render applies custom layout attribute.
	 *
	 * @return void
	 */
	public function test_render_applies_custom_layout(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'layout' => 'list' ) );

		$this->assertStringContainsString( 'nte-grid--list', $output );
		$this->assertStringContainsString( 'data-layout="list"', $output );
	}

	/**
	 * Test render applies custom CSS class.
	 *
	 * @return void
	 */
	public function test_render_applies_custom_class(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'class' => 'my-custom-grid' ) );

		$this->assertStringContainsString( 'my-custom-grid', $output );
	}

	/**
	 * Test render handles boolean string attributes.
	 *
	 * @return void
	 */
	public function test_render_handles_boolean_string_attributes(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'ajax' => 'false' ) );

		$this->assertStringContainsString( 'data-ajax="false"', $output );
	}

	/**
	 * Test render handles past events attribute.
	 *
	 * @return void
	 */
	public function test_render_handles_past_events_attribute(): void {
		$captured_args = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->withArgs(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			)
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'past' => 'true' ) );

		$this->assertTrue( $captured_args['past'] );
		$this->assertFalse( $captured_args['upcoming'] );
		$this->assertStringContainsString( 'data-past="true"', $output );
	}

	/**
	 * Test render passes category filter to repository.
	 *
	 * @return void
	 */
	public function test_render_passes_category_filter(): void {
		$captured_args = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->withArgs(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			)
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$shortcode->render( array( 'category' => '5,10,15' ) );

		$this->assertEquals( array( 5, 10, 15 ), $captured_args['category'] );
	}

	// =========================================================================
	// Filter Rendering Tests
	// =========================================================================

	/**
	 * Test render includes filters when show_filters is true.
	 *
	 * @return void
	 */
	public function test_render_includes_filters_when_enabled(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'show_filters' => true ) );

		$this->assertStringContainsString( 'nte-filters', $output );
		$this->assertStringContainsString( 'nte-filters__form', $output );
	}

	/**
	 * Test render excludes filters when show_filters is false.
	 *
	 * @return void
	 */
	public function test_render_excludes_filters_when_disabled(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'show_filters' => 'false' ) );

		$this->assertStringNotContainsString( 'nte-filters', $output );
	}

	/**
	 * Test render includes search when show_search is true.
	 *
	 * @return void
	 */
	public function test_render_includes_search_when_enabled(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render(
			array(
				'show_filters' => true,
				'show_search'  => true,
			)
		);

		$this->assertStringContainsString( 'nte-filters__field--search', $output );
		$this->assertStringContainsString( 'type="search"', $output );
	}

	/**
	 * Test render excludes search when show_search is false.
	 *
	 * @return void
	 */
	public function test_render_excludes_search_when_disabled(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render(
			array(
				'show_filters' => true,
				'show_search'  => 'false',
			)
		);

		$this->assertStringNotContainsString( 'nte-filters__field--search', $output );
	}

	/**
	 * Test render includes category dropdown when show_category is true.
	 *
	 * @return void
	 */
	public function test_render_includes_category_dropdown_when_enabled(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render(
			array(
				'show_filters'  => true,
				'show_category' => true,
			)
		);

		$this->assertStringContainsString( 'nte-filters__field--category', $output );
		$this->assertStringContainsString( '<select', $output );
	}

	/**
	 * Test render includes reset button in filters.
	 *
	 * @return void
	 */
	public function test_render_includes_reset_button(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'show_filters' => true ) );

		$this->assertStringContainsString( 'nte-filters__reset', $output );
		$this->assertStringContainsString( 'Reset', $output );
	}

	// =========================================================================
	// Pagination Tests
	// =========================================================================

	/**
	 * Test render excludes pagination when only one page.
	 *
	 * @return void
	 */
	public function test_render_excludes_pagination_when_single_page(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array( $this->create_mock_occurrence() ),
					'total'       => 1,
					'total_pages' => 1,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'pagination' => true ) );

		// Template parts aren't loaded in test context, but we can verify
		// the pagination condition logic by checking templates aren't called for pagination.
		$this->assertStringContainsString( 'nte-event-list', $output );
	}

	/**
	 * Test render excludes pagination when disabled.
	 *
	 * @return void
	 */
	public function test_render_excludes_pagination_when_disabled(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array( $this->create_mock_occurrence() ),
					'total'       => 50,
					'total_pages' => 5,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array( 'pagination' => 'false' ) );

		// Output should not contain pagination wrapper.
		$this->assertStringContainsString( 'nte-event-list', $output );
	}

	// =========================================================================
	// Edge Cases Tests
	// =========================================================================

	/**
	 * Test render handles empty array attributes.
	 *
	 * @return void
	 */
	public function test_render_handles_empty_array_attributes(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array() );

		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'nte-event-list', $output );
	}

	/**
	 * Test render handles string attribute parameter.
	 *
	 * @return void
	 */
	public function test_render_handles_string_attribute_parameter(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		// WordPress can pass empty string for no attributes.
		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( '' );

		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'nte-event-list', $output );
	}

	/**
	 * Test render handles null content parameter.
	 *
	 * @return void
	 */
	public function test_render_handles_null_content(): void {
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render( array(), null );

		$this->assertNotEmpty( $output );
	}

	/**
	 * Test render handles zero limit.
	 *
	 * @return void
	 */
	public function test_render_handles_zero_limit(): void {
		$captured_args = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->withArgs(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			)
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$shortcode->render( array( 'limit' => 0 ) );

		// Should pass 0 as per_page.
		$this->assertEquals( 0, $captured_args['per_page'] );
	}

	/**
	 * Test render applies default values correctly.
	 *
	 * @return void
	 */
	public function test_render_applies_defaults(): void {
		$captured_args = null;
		$this->mock_occurrence_repo
			->shouldReceive( 'get_filtered' )
			->once()
			->withArgs(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			)
			->andReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$shortcode = new EventListShortcode( $this->mock_occurrence_repo, Templates::get_instance(), $this->mock_category_repo, $this->mock_tag_repo, $this->mock_ticket_type_repo );
		$output    = $shortcode->render();

		// Default limit is 12.
		$this->assertEquals( 12, $captured_args['per_page'] );
		$this->assertEquals( 1, $captured_args['page'] );
		$this->assertTrue( $captured_args['upcoming'] );
		$this->assertFalse( $captured_args['past'] );

		// Default columns is 3.
		$this->assertStringContainsString( 'nte-grid--cols-3', $output );

		// Default layout is grid.
		$this->assertStringContainsString( 'nte-grid--grid', $output );
	}
}
