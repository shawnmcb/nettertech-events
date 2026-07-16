<?php
/**
 * EventGridBlock render.php unit tests.
 *
 * Verifies that the Event Grid block render.php correctly maps camelCase block
 * attributes to snake_case shortcode attributes and delegates to the shortcode.
 *
 * @package NetterTechEvents\Tests\Unit\Blocks
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Blocks;

use Mockery;
use NetterTechEvents\Core\Container;
use NetterTechEvents\Frontend\Shortcodes\EventListShortcode;

/**
 * Test Event Grid block render.php attribute mapping.
 *
 * @since 1.0.0
 * @coversNothing
 */
class EventGridBlockTest extends \NetterTechEventsTestCase {

	/**
	 * Absolute path to the render.php file under test.
	 *
	 * @var string
	 */
	private string $render_file;

	/**
	 * Mock shortcode instance.
	 *
	 * @var EventListShortcode|Mockery\MockInterface
	 */
	private $mock_shortcode;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $nettertech_events_test_container;

		$this->render_file    = dirname( __DIR__, 3 ) . '/blocks/event-grid/render.php';
		$this->mock_shortcode = Mockery::mock( EventListShortcode::class );

		$container = new Container();
		$container->set( EventListShortcode::class, $this->mock_shortcode );
		$nettertech_events_test_container = $container;
	}

	/**
	 * Tear down: clear the test container so other tests use their own.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $nettertech_events_test_container;
		$nettertech_events_test_container = null;
		parent::tearDown();
	}

	/**
	 * Include render.php with given attributes, return buffered output.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function render_block( array $attributes ): string {
		ob_start();
		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- test-only include of known path.
		include $this->render_file;
		return (string) ob_get_clean();
	}

	/**
	 * Test render with default (empty) attributes uses fallback defaults.
	 *
	 * @return void
	 */
	public function test_render_with_default_attributes(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				array(
					'limit'         => 12,
					'columns'       => 3,
					'layout'        => 'grid',
					'show_filters'  => true,
					'show_search'   => true,
					'show_category' => true,
					'show_image'    => true,
					'show_date'     => true,
					'show_time'     => true,
					'show_venue'    => true,
					'show_excerpt'  => false,
					'pagination'    => true,
					'ajax'          => true,
					'category'      => '',
					'past'          => false,
				)
			)
			->andReturn( '<div class="nte-event-grid"></div>' );

		$output = $this->render_block( array() );

		$this->assertSame( '<div class="nte-event-grid"></div>', $output );
	}

	/**
	 * Test render maps layout attribute correctly.
	 *
	 * @return void
	 */
	public function test_render_maps_list_layout(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				Mockery::on(
					function ( array $atts ): bool {
						return $atts['layout'] === 'list';
					}
				)
			)
			->andReturn( '<div class="nte-event-grid nte-event-grid--list"></div>' );

		$output = $this->render_block( array( 'layout' => 'list' ) );

		$this->assertStringContainsString( 'nte-event-grid--list', $output );
	}

	/**
	 * Test render maps category filter attribute.
	 *
	 * @return void
	 */
	public function test_render_maps_category_filter(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				Mockery::on(
					function ( array $atts ): bool {
						return $atts['category'] === 'music';
					}
				)
			)
			->andReturn( '<div class="nte-event-grid" data-category="music"></div>' );

		$output = $this->render_block( array( 'category' => 'music' ) );

		$this->assertNotEmpty( $output );
	}

	/**
	 * Test render maps past events flag.
	 *
	 * @return void
	 */
	public function test_render_maps_past_events_flag(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				Mockery::on(
					function ( array $atts ): bool {
						return $atts['past'] === true;
					}
				)
			)
			->andReturn( '<div class="nte-event-grid nte-event-grid--past"></div>' );

		$output = $this->render_block( array( 'past' => true ) );

		$this->assertIsString( $output );
		$this->assertNotEmpty( $output );
	}

	/**
	 * Test render maps full attribute set including all display toggles.
	 *
	 * @return void
	 */
	public function test_render_maps_full_attribute_set(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				array(
					'limit'         => 6,
					'columns'       => 2,
					'layout'        => 'cards',
					'show_filters'  => false,
					'show_search'   => false,
					'show_category' => false,
					'show_image'    => true,
					'show_date'     => true,
					'show_time'     => false,
					'show_venue'    => false,
					'show_excerpt'  => true,
					'pagination'    => false,
					'ajax'          => false,
					'category'      => 'theatre',
					'past'          => false,
				)
			)
			->andReturn( '<div class="nte-event-grid nte-event-grid--cards"></div>' );

		$output = $this->render_block(
			array(
				'limit'        => 6,
				'columns'      => 2,
				'layout'       => 'cards',
				'showFilters'  => false,
				'showSearch'   => false,
				'showCategory' => false,
				'showImage'    => true,
				'showDate'     => true,
				'showTime'     => false,
				'showVenue'    => false,
				'showExcerpt'  => true,
				'pagination'   => false,
				'ajax'         => false,
				'category'     => 'theatre',
				'past'         => false,
			)
		);

		$this->assertIsString( $output );
		$this->assertNotEmpty( $output );
	}
}
