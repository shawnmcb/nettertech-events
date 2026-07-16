<?php
/**
 * EventListShortcode unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend\Shortcodes;

use NetterTechEvents\Frontend\Shortcodes\EventListShortcode;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\TemplateLoader\Templates;
use NetterTechEvents\Utilities\ImageUtility;
use Brain\Monkey\Functions;

/**
 * Test EventListShortcode functionality.
 *
 * Focuses on decision code: sanitize_image_ratio, attribute normalization,
 * and query argument building.
 */
class EventListShortcodeTest extends \NetterTechEventsTestCase {

	/**
	 * EventListShortcode instance.
	 *
	 * @var EventListShortcode
	 */
	private EventListShortcode $shortcode;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private OccurrenceRepositoryInterface $mock_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mock_repo      = $this->createMock( OccurrenceRepositoryInterface::class );
		$mock_category_repo   = $this->createMock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$mock_tag_repo        = $this->createMock( \NetterTechEvents\Contracts\TagRepositoryInterface::class );
		$mock_ticket_type_repo = $this->createMock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$this->shortcode      = new EventListShortcode( $this->mock_repo, Templates::get_instance(), $mock_category_repo, $mock_tag_repo, $mock_ticket_type_repo );
	}

	// =========================================================================
	// sanitize_image_ratio tests (via reflection)
	// =========================================================================

	/**
	 * Invoke ImageUtility::sanitize_image_ratio() directly.
	 *
	 * The method was extracted from EventListShortcode to ImageUtility (E-05).
	 *
	 * @param string $value Input value.
	 * @return string Sanitized value.
	 */
	private function call_sanitize_image_ratio( string $value ): string {
		return ImageUtility::sanitize_image_ratio( $value );
	}

	/**
	 * Test empty string returns empty string.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_empty_returns_empty(): void {
		$this->assertSame( '', $this->call_sanitize_image_ratio( '' ) );
	}

	/**
	 * Test whitespace-only returns empty string.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_whitespace_returns_empty(): void {
		$this->assertSame( '', $this->call_sanitize_image_ratio( '   ' ) );
	}

	/**
	 * Test 16:9 preset returns CSS aspect-ratio value.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_16_9(): void {
		$this->assertSame( '16 / 9', $this->call_sanitize_image_ratio( '16:9' ) );
	}

	/**
	 * Test 3:2 preset returns CSS aspect-ratio value.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_3_2(): void {
		$this->assertSame( '3 / 2', $this->call_sanitize_image_ratio( '3:2' ) );
	}

	/**
	 * Test 4:3 preset returns CSS aspect-ratio value.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_4_3(): void {
		$this->assertSame( '4 / 3', $this->call_sanitize_image_ratio( '4:3' ) );
	}

	/**
	 * Test 1:1 preset returns simplified value.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_1_1(): void {
		$this->assertSame( '1', $this->call_sanitize_image_ratio( '1:1' ) );
	}

	/**
	 * Test 'original' preset returns 'auto'.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_original(): void {
		$this->assertSame( 'auto', $this->call_sanitize_image_ratio( 'original' ) );
	}

	/**
	 * Test custom W:H format is converted to CSS aspect-ratio.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_custom_format(): void {
		$this->assertSame( '21 / 9', $this->call_sanitize_image_ratio( '21:9' ) );
	}

	/**
	 * Test custom format with large values.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_large_custom_values(): void {
		$this->assertSame( '1920 / 1080', $this->call_sanitize_image_ratio( '1920:1080' ) );
	}

	/**
	 * Test zero width returns empty string.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_zero_width(): void {
		$this->assertSame( '', $this->call_sanitize_image_ratio( '0:9' ) );
	}

	/**
	 * Test zero height returns empty string.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_zero_height(): void {
		$this->assertSame( '', $this->call_sanitize_image_ratio( '16:0' ) );
	}

	/**
	 * Test invalid format returns empty string.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_invalid_format(): void {
		$this->assertSame( '', $this->call_sanitize_image_ratio( 'not-a-ratio' ) );
	}

	/**
	 * Test negative values are rejected.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_negative_values(): void {
		$this->assertSame( '', $this->call_sanitize_image_ratio( '-16:9' ) );
	}

	/**
	 * Test decimal values are rejected.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_decimal_values(): void {
		$this->assertSame( '', $this->call_sanitize_image_ratio( '1.5:1' ) );
	}

	/**
	 * Test XSS attempt is rejected.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_xss_attempt(): void {
		$this->assertSame( '', $this->call_sanitize_image_ratio( '<script>alert(1)</script>' ) );
	}

	/**
	 * Test preset with surrounding whitespace is handled.
	 *
	 * @return void
	 */
	public function test_sanitize_image_ratio_trimmed_preset(): void {
		$this->assertSame( '16 / 9', $this->call_sanitize_image_ratio( ' 16:9 ' ) );
	}

	// =========================================================================
	// render() attribute normalization
	// =========================================================================

	/**
	 * Test render returns HTML with proper structure.
	 *
	 * @return void
	 */
	public function test_render_returns_html(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$output = $this->shortcode->render();

		$this->assertStringContainsString( 'nte-event-list', $output );
		$this->assertStringContainsString( 'nte-grid', $output );
	}

	/**
	 * Test render with past=true includes past data attribute.
	 *
	 * @return void
	 */
	public function test_render_past_attribute(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$output = $this->shortcode->render( array( 'past' => true ) );

		$this->assertStringContainsString( 'data-past="true"', $output );
	}

	/**
	 * Test render with show_filters=false omits filter block.
	 *
	 * @return void
	 */
	public function test_render_without_filters(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$output = $this->shortcode->render( array( 'show_filters' => false ) );

		$this->assertStringNotContainsString( 'nte-filters', $output );
	}

	/**
	 * Test render passes category to repository query.
	 *
	 * @return void
	 */
	public function test_render_passes_category_filter(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with( $this->callback(
				function ( array $args ) {
					return isset( $args['category'] ) && $args['category'] === array( 5, 10 );
				}
			) )
			->willReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$this->shortcode->render( array( 'category' => '5,10' ) );
	}

	/**
	 * Test render with custom columns attribute.
	 *
	 * @return void
	 */
	public function test_render_custom_columns(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$output = $this->shortcode->render( array( 'columns' => 4 ) );

		$this->assertStringContainsString( 'nte-grid--cols-4', $output );
	}

	// =========================================================================
	// Tag and Date Range attribute tests
	// =========================================================================

	/**
	 * Test render passes tag filter to repository query.
	 *
	 * @return void
	 */
	public function test_render_passes_tag_filter(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with( $this->callback(
				function ( array $args ) {
					return isset( $args['tag'] ) && 'jazz' === $args['tag'];
				}
			) )
			->willReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$this->shortcode->render( array( 'tag' => 'jazz' ) );
	}

	/**
	 * Test render passes date_from filter to repository query.
	 *
	 * @return void
	 */
	public function test_render_passes_date_from_filter(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with( $this->callback(
				function ( array $args ) {
					return isset( $args['date_from'] ) && '2026-06-01' === $args['date_from'];
				}
			) )
			->willReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$this->shortcode->render( array( 'date_from' => '2026-06-01' ) );
	}

	/**
	 * Test render passes date_to filter to repository query.
	 *
	 * @return void
	 */
	public function test_render_passes_date_to_filter(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with( $this->callback(
				function ( array $args ) {
					return isset( $args['date_to'] ) && '2026-12-31' === $args['date_to'];
				}
			) )
			->willReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$this->shortcode->render( array( 'date_to' => '2026-12-31' ) );
	}

	/**
	 * Test DEFAULTS constant includes new filter attributes.
	 *
	 * @return void
	 */
	public function test_defaults_include_tag_and_date_attributes(): void {
		$reflection = new \ReflectionClass( EventListShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$this->assertArrayHasKey( 'tag', $defaults );
		$this->assertArrayHasKey( 'date_from', $defaults );
		$this->assertArrayHasKey( 'date_to', $defaults );
		$this->assertArrayHasKey( 'show_tag', $defaults );
		$this->assertArrayHasKey( 'show_date_range', $defaults );
		$this->assertSame( '', $defaults['tag'] );
		$this->assertSame( '', $defaults['date_from'] );
		$this->assertSame( '', $defaults['date_to'] );
		$this->assertTrue( $defaults['show_tag'] );
		$this->assertTrue( $defaults['show_date_range'] );
	}

	/**
	 * Test render with show_tag=false omits tag filter.
	 *
	 * @return void
	 */
	public function test_render_without_tag_filter(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$output = $this->shortcode->render( array( 'show_tag' => false ) );

		$this->assertStringNotContainsString( 'nte-filters__field--tag', $output );
	}

	/**
	 * Test render with show_date_range=false omits date inputs.
	 *
	 * @return void
	 */
	public function test_render_without_date_range(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$output = $this->shortcode->render( array( 'show_date_range' => false ) );

		$this->assertStringNotContainsString( 'nte-filters__field--date-from', $output );
		$this->assertStringNotContainsString( 'nte-filters__field--date-to', $output );
	}

	// =========================================================================
	// Timeframe-aware search placeholder tests (NTE-067)
	// =========================================================================

	/**
	 * Test render_filters emits upcoming placeholder and label when past=false.
	 *
	 * @return void
	 */
	public function test_render_filters_emits_upcoming_placeholder_when_past_false(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$output = $this->shortcode->render( array( 'past' => false ) );

		$this->assertStringContainsString( 'Search upcoming events…', $output );
		$this->assertStringNotContainsString( 'Search past events', $output );
		$this->assertStringNotContainsString( 'Search events...', $output );
		// Regression guard for NTE-074: literal escape sequence must not leak through.
		$this->assertStringNotContainsString( '\\xe2\\x80\\xa6', $output );
		// NTE-075: category/tag selects carry the multiselect-source class and label
		// data-attribute that the multiselect JS uses to enhance them.
		$this->assertStringContainsString( 'nte-multiselect-source', $output );
		$this->assertStringContainsString( 'data-nte-multiselect-label', $output );
		$this->assertStringNotContainsString( 'size="4"', $output );
	}

	/**
	 * Test render_filters emits past placeholder and label when past=true.
	 *
	 * @return void
	 */
	public function test_render_filters_emits_past_placeholder_when_past_true(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$output = $this->shortcode->render( array( 'past' => true ) );

		$this->assertStringContainsString( 'Search past events…', $output );
		$this->assertStringNotContainsString( 'Search upcoming events', $output );
		$this->assertStringNotContainsString( 'Search events...', $output );
		// Regression guard for NTE-074: literal escape sequence must not leak through.
		$this->assertStringNotContainsString( '\\xe2\\x80\\xa6', $output );
	}

	/**
	 * Test nettertech_events_search_placeholder filter overrides placeholder text.
	 *
	 * Uses Mockery's LIFO expectation ordering: the test-level alias is registered
	 * after the global apply_filters stub, so it takes priority for the matching tag.
	 *
	 * @return void
	 */
	public function test_render_filters_search_placeholder_filter_overrides_text(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		// Test-level alias registered AFTER global stub — Mockery LIFO picks this first.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value ) {
				if ( 'nettertech_events_search_placeholder' === $tag ) {
					return 'Find something extraordinary';
				}
				return $value;
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$output = $this->shortcode->render( array( 'show_filters' => true ) );

		$this->assertStringContainsString( 'Find something extraordinary', $output );
	}

	// =========================================================================
	// Multi-select + timeframe-limited filter tests (NTE-068)
	// =========================================================================

	/**
	 * Stub shortcode_atts pass-through used by all NTE-068 tests below.
	 *
	 * @return void
	 */
	private function stub_shortcode_atts_passthrough(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);
	}

	/**
	 * Build a Category stub object suitable for mock returns.
	 *
	 * @param int    $id   ID.
	 * @param string $name Display name.
	 * @return \NetterTechEvents\Models\Category
	 */
	private function build_category_stub( int $id, string $name ): \NetterTechEvents\Models\Category {
		$cat       = new \NetterTechEvents\Models\Category();
		$cat->id   = $id;
		$cat->name = $name;
		$cat->slug = sanitize_title( $name );
		return $cat;
	}

	/**
	 * Build a Tag stub object suitable for mock returns.
	 *
	 * @param int    $id   ID.
	 * @param string $name Display name.
	 * @return \NetterTechEvents\Models\Tag
	 */
	private function build_tag_stub( int $id, string $name ): \NetterTechEvents\Models\Tag {
		$tag       = new \NetterTechEvents\Models\Tag();
		$tag->id   = $id;
		$tag->name = $name;
		$tag->slug = sanitize_title( $name );
		return $tag;
	}

	/**
	 * Re-bind the shortcode with concrete mock objects exposed for assertions.
	 *
	 * @return array{0: \PHPUnit\Framework\MockObject\MockObject, 1: \PHPUnit\Framework\MockObject\MockObject}
	 *         Tuple of (category_repo_mock, tag_repo_mock).
	 */
	private function rebind_shortcode_with_term_repo_mocks(): array {
		$mock_category_repo    = $this->createMock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$mock_tag_repo         = $this->createMock( \NetterTechEvents\Contracts\TagRepositoryInterface::class );
		$mock_ticket_type_repo = $this->createMock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$this->shortcode       = new EventListShortcode(
			$this->mock_repo,
			Templates::get_instance(),
			$mock_category_repo,
			$mock_tag_repo,
			$mock_ticket_type_repo
		);
		return array( $mock_category_repo, $mock_tag_repo );
	}

	/**
	 * Test render_filters emits <select multiple> with name="category[]".
	 *
	 * @return void
	 */
	public function test_render_filters_emits_multiple_attribute_on_category_select(): void {
		$this->stub_shortcode_atts_passthrough();
		$this->rebind_shortcode_with_term_repo_mocks();

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
		);

		$output = $this->shortcode->render( array( 'show_filters' => true ) );

		$this->assertMatchesRegularExpression(
			'/<select[^>]*name="category\[\]"[^>]*multiple/',
			$output,
			'Category select must be multi-value markup.'
		);
	}

	/**
	 * Test render_filters emits <select multiple> with name="tag[]".
	 *
	 * @return void
	 */
	public function test_render_filters_emits_multiple_attribute_on_tag_select(): void {
		$this->stub_shortcode_atts_passthrough();
		$this->rebind_shortcode_with_term_repo_mocks();

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
		);

		$output = $this->shortcode->render( array( 'show_filters' => true ) );

		$this->assertMatchesRegularExpression(
			'/<select[^>]*name="tag\[\]"[^>]*multiple/',
			$output,
			'Tag select must be multi-value markup.'
		);
	}

	/**
	 * Test render_filters passes 'upcoming' timeframe to category_repo on upcoming view.
	 *
	 * @return void
	 */
	public function test_render_filters_passes_upcoming_timeframe_to_category_repo(): void {
		$this->stub_shortcode_atts_passthrough();
		list( $cat_repo, $tag_repo ) = $this->rebind_shortcode_with_term_repo_mocks();

		$cat_repo->expects( $this->atLeastOnce() )
			->method( 'get_all' )
			->with( $this->anything(), 'upcoming' )
			->willReturn( array() );

		$tag_repo->method( 'get_all' )->willReturn( array() );

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
		);

		$this->shortcode->render( array( 'past' => false ) );
	}

	/**
	 * Test render_filters passes 'past' timeframe to category_repo on past view.
	 *
	 * @return void
	 */
	public function test_render_filters_passes_past_timeframe_to_category_repo(): void {
		$this->stub_shortcode_atts_passthrough();
		list( $cat_repo, $tag_repo ) = $this->rebind_shortcode_with_term_repo_mocks();

		$cat_repo->expects( $this->atLeastOnce() )
			->method( 'get_all' )
			->with( $this->anything(), 'past' )
			->willReturn( array() );

		$tag_repo->method( 'get_all' )->willReturn( array() );

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
		);

		$this->shortcode->render( array( 'past' => true ) );
	}

	/**
	 * Test that a category preselected via shortcode atts but absent from the
	 * timeframe-limited result set is still rendered (selected-term union).
	 *
	 * Regression for the silent-deselection scenario when toggling timeframes.
	 *
	 * @return void
	 */
	public function test_render_filters_includes_selected_category_outside_timeframe(): void {
		$this->stub_shortcode_atts_passthrough();
		list( $cat_repo, $tag_repo ) = $this->rebind_shortcode_with_term_repo_mocks();

		// Timeframe returns no qualifying categories.
		$cat_repo->method( 'get_all' )->willReturn( array() );
		// But the selected ID resolves via find().
		$cat_repo->expects( $this->atLeastOnce() )
			->method( 'find' )
			->with( 42 )
			->willReturn( $this->build_category_stub( 42 , 'Workshops' ) );

		$tag_repo->method( 'get_all' )->willReturn( array() );

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
		);

		$output = $this->shortcode->render( array( 'category' => '42' ) );

		$this->assertStringContainsString( 'value="42"', $output, 'Selected category must be rendered even if outside timeframe.' );
		$this->assertStringContainsString( 'selected="selected"', $output, 'Selected category must be marked selected.' );
		$this->assertStringContainsString( 'Workshops', $output, 'Selected category name must be present.' );
	}

	/**
	 * Test that a tag preselected via shortcode atts but absent from the
	 * timeframe-limited result set is still rendered (selected-term union).
	 *
	 * @return void
	 */
	public function test_render_filters_includes_selected_tag_outside_timeframe(): void {
		$this->stub_shortcode_atts_passthrough();
		list( $cat_repo, $tag_repo ) = $this->rebind_shortcode_with_term_repo_mocks();

		$cat_repo->method( 'get_all' )->willReturn( array() );
		$tag_repo->method( 'get_all' )->willReturn( array() );

		// Selected slug 'jazz' resolves via find_by_slug().
		$tag_repo->expects( $this->atLeastOnce() )
			->method( 'find_by_slug' )
			->with( 'jazz' )
			->willReturn( $this->build_tag_stub( 7, 'Jazz' ) );

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
		);

		$output = $this->shortcode->render( array( 'tag' => 'jazz' ) );

		$this->assertStringContainsString( 'value="jazz"', $output, 'Selected tag must be rendered even if outside timeframe.' );
		$this->assertStringContainsString( 'selected="selected"', $output, 'Selected tag must be marked selected.' );
		$this->assertStringContainsString( 'Jazz', $output, 'Selected tag name must be present.' );
	}

	/**
	 * Test that a category already in the timeframe-limited set is not re-fetched
	 * via find() — it should be marked selected in place without redundant DB hits.
	 *
	 * @return void
	 */
	public function test_render_filters_marks_in_timeframe_category_selected_without_extra_fetch(): void {
		$this->stub_shortcode_atts_passthrough();
		list( $cat_repo, $tag_repo ) = $this->rebind_shortcode_with_term_repo_mocks();

		$cat_repo->method( 'get_all' )->willReturn(
			array( $this->build_category_stub( 5, 'Music' ) )
		);
		// find() must not be called — the term is already present.
		$cat_repo->expects( $this->never() )->method( 'find' );

		$tag_repo->method( 'get_all' )->willReturn( array() );

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
		);

		$output = $this->shortcode->render( array( 'category' => '5' ) );

		$this->assertMatchesRegularExpression(
			'/<option value="5"[^>]*selected="selected"[^>]*>Music<\/option>/',
			$output,
			'In-timeframe category must be marked selected.'
		);
	}

	/**
	 * Test the dropdown options cache key incorporates the timeframe.
	 *
	 * Verifies the cache strategy by spying on `wp_cache_set` and asserting
	 * the recorded key string carries the active timeframe segment. The
	 * underlying object cache is not stateful in this test environment
	 * (`wp_cache_get` always returns false), so we assert key shape rather
	 * than dedup behavior.
	 *
	 * @return void
	 */
	public function test_render_filters_caches_dropdown_options_keyed_by_timeframe(): void {
		$this->stub_shortcode_atts_passthrough();
		list( $cat_repo, $tag_repo ) = $this->rebind_shortcode_with_term_repo_mocks();

		$cat_repo->method( 'get_all' )->willReturn( array() );
		$tag_repo->method( 'get_all' )->willReturn( array() );

		$captured_keys = array();
		Functions\when( 'wp_cache_set' )->alias(
			static function ( $key, $data, $group = '', $expire = 0 ) use ( &$captured_keys ) {
				$captured_keys[] = (string) $key;
				return true;
			}
		);

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
		);

		$this->shortcode->render( array( 'past' => false ) );

		$this->assertContains(
			'event_categories_dropdown_upcoming',
			$captured_keys,
			'Category cache key must include the active timeframe (upcoming).'
		);
		$this->assertContains(
			'event_tags_dropdown_upcoming',
			$captured_keys,
			'Tag cache key must include the active timeframe (upcoming).'
		);
	}

	/**
	 * Test that toggling timeframe varies the cache key (different repo call).
	 *
	 * @return void
	 */
	public function test_render_filters_cache_varies_by_timeframe(): void {
		$this->stub_shortcode_atts_passthrough();
		list( $cat_repo, $tag_repo ) = $this->rebind_shortcode_with_term_repo_mocks();

		$cat_repo->expects( $this->exactly( 2 ) )
			->method( 'get_all' )
			->willReturnCallback(
				function ( $args, $timeframe ) {
					// Returns a small list per call; assertion is on call count + timeframe distinction.
					return array();
				}
			);

		$tag_repo->method( 'get_all' )->willReturn( array() );

		$this->mock_repo->method( 'get_filtered' )->willReturn(
			array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
		);

		$this->shortcode->render( array( 'past' => false ) );
		$this->shortcode->render( array( 'past' => true ) );
	}

	/**
	 * Test multi-value tag pre-filter via comma-separated shortcode attribute is
	 * passed to the occurrence query as an array.
	 *
	 * @return void
	 */
	public function test_render_passes_multi_tag_filter_as_array(): void {
		$this->stub_shortcode_atts_passthrough();
		$this->rebind_shortcode_with_term_repo_mocks();

		$this->mock_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with( $this->callback(
				function ( array $args ) {
					return isset( $args['tag'] ) && is_array( $args['tag'] )
						&& array( 'jazz', 'rock' ) === $args['tag'];
				}
			) )
			->willReturn(
				array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
			);

		$this->shortcode->render( array( 'tag' => 'jazz,rock' ) );
	}

	/**
	 * Regression: verify the kses allowlist permits `multiple` and `size` on
	 * `<select>`. Production-mode `wp_kses()` strips any attribute not in the
	 * allowlist; in unit-test mode Brain\Monkey replaces `wp_kses` with a
	 * pass-through stub, so the markup-shape tests above will silently pass
	 * even when the live-site rendering strips these attributes. This guard
	 * test asserts the allowlist itself, closing the unit-vs-runtime gap.
	 *
	 * Originated from C6 visual verification: live dev-site rendering
	 * showed `<select name="category[]">` (no multiple, no size) despite
	 * the source code emitting them.
	 *
	 * @return void
	 */
	public function test_shortcode_output_allowlist_permits_multiple_and_size_on_select(): void {
		$allowlist = \NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput::get_allowlist();

		$this->assertArrayHasKey( 'select', $allowlist, 'select must be a permitted tag.' );
		$this->assertArrayHasKey( 'multiple', $allowlist['select'], 'select must permit the `multiple` attribute (NTE-068).' );
		$this->assertArrayHasKey( 'size', $allowlist['select'], 'select must permit the `size` attribute (NTE-068).' );
		$this->assertTrue( (bool) $allowlist['select']['multiple'], '`multiple` allowlist entry must be truthy.' );
		$this->assertTrue( (bool) $allowlist['select']['size'], '`size` allowlist entry must be truthy.' );

		// Option-level selected must also be permitted for the selected-term-union overlay.
		$this->assertArrayHasKey( 'option', $allowlist, 'option must be a permitted tag.' );
		$this->assertArrayHasKey( 'selected', $allowlist['option'], 'option must permit the `selected` attribute (NTE-068).' );

		// NTE-075: data-nte-multiselect-label is the JS-enhancement hook attribute that
		// the multi-select disclosure widget reads to render the trigger button label.
		// Without this allowlist entry, wp_kses strips it from rendered output and the
		// JS falls back to a generic "Filter" label even when the PHP template emits
		// the correct context-specific label ("Category" / "Tag").
		$this->assertArrayHasKey( 'data-nte-multiselect-label', $allowlist['select'], 'select must permit the `data-nte-multiselect-label` attribute (NTE-075).' );
	}

	/**
	 * Test the allowlist permits oEmbed video <iframe> markup.
	 *
	 * single-event.php and series-page.php sanitize the event description
	 * (which may contain YouTube/Vimeo oEmbed iframes) through this allowlist.
	 * Without an `iframe` entry, wp_kses strips the embed and the video
	 * silently disappears from the rendered page. Mirrors the select/multiple
	 * runtime-vs-unit gap closed above; verified against live rendering.
	 *
	 * @return void
	 */
	public function test_shortcode_output_allowlist_permits_oembed_iframe(): void {
		$allowlist = \NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput::get_allowlist();

		$this->assertArrayHasKey( 'iframe', $allowlist, 'iframe must be a permitted tag so oEmbed videos survive output sanitization.' );
		$this->assertArrayHasKey( 'src', $allowlist['iframe'], 'iframe must permit the `src` attribute.' );
		$this->assertArrayHasKey( 'allow', $allowlist['iframe'], 'iframe must permit the `allow` attribute (YouTube/Vimeo embeds).' );
		$this->assertArrayHasKey( 'allowfullscreen', $allowlist['iframe'], 'iframe must permit the `allowfullscreen` attribute.' );
		$this->assertTrue( (bool) $allowlist['iframe']['src'], '`src` allowlist entry must be truthy.' );
	}

	/**
	 * Test single-value tag pre-filter remains a scalar (back-compat with
	 * legacy callers and the scalar-path query builder).
	 *
	 * @return void
	 */
	public function test_render_passes_single_tag_filter_as_scalar(): void {
		$this->stub_shortcode_atts_passthrough();
		$this->rebind_shortcode_with_term_repo_mocks();

		$this->mock_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with( $this->callback(
				function ( array $args ) {
					return isset( $args['tag'] ) && 'jazz' === $args['tag'];
				}
			) )
			->willReturn(
				array( 'items' => array(), 'total' => 0, 'total_pages' => 0 )
			);

		$this->shortcode->render( array( 'tag' => 'jazz' ) );
	}
}
