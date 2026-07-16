<?php
/**
 * CarouselBlock render.php unit tests.
 *
 * Verifies that the Carousel block render.php correctly maps camelCase block
 * attributes to snake_case shortcode attributes and delegates to the shortcode.
 *
 * @package NetterTechEvents\Tests\Unit\Blocks
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Blocks;

use Mockery;
use NetterTechEvents\Core\Container;
use NetterTechEvents\Frontend\Shortcodes\CarouselShortcode;

/**
 * Test Carousel block render.php attribute mapping.
 *
 * @since 1.0.0
 * @coversNothing
 */
class CarouselBlockTest extends \NetterTechEventsTestCase {

	/**
	 * Absolute path to the render.php file under test.
	 *
	 * @var string
	 */
	private string $render_file;

	/**
	 * Mock shortcode instance.
	 *
	 * @var CarouselShortcode|Mockery\MockInterface
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

		$this->render_file    = dirname( __DIR__, 3 ) . '/blocks/carousel/render.php';
		$this->mock_shortcode = Mockery::mock( CarouselShortcode::class );

		$container = new Container();
		$container->set( CarouselShortcode::class, $this->mock_shortcode );
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
					'limit'         => 6,
					'columns'       => 3,
					'show_image'    => true,
					'show_date'     => true,
					'show_time'     => true,
					'show_venue'    => true,
					'show_year'     => false,
					'autoplay'      => false,
					'interval'      => 5000,
					'playback_mode' => 'rewind',
					'max_tags'      => 3,
				)
			)
			->andReturn( '<div class="nte-carousel"></div>' );

		$output = $this->render_block( array() );

		$this->assertSame( '<div class="nte-carousel"></div>', $output );
	}

	/**
	 * Test render maps limit and columns from block attributes.
	 *
	 * @return void
	 */
	public function test_render_maps_limit_and_columns(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				array(
					'limit'         => 4,
					'columns'       => 2,
					'show_image'    => true,
					'show_date'     => true,
					'show_time'     => true,
					'show_venue'    => true,
					'show_year'     => false,
					'autoplay'      => false,
					'interval'      => 5000,
					'playback_mode' => 'rewind',
					'max_tags'      => 3,
				)
			)
			->andReturn( '<div class="nte-carousel nte-carousel--2col"></div>' );

		$output = $this->render_block(
			array(
				'limit'   => 4,
				'columns' => 2,
			)
		);

		$this->assertNotEmpty( $output );
	}

	/**
	 * Test render maps autoplay and interval attributes.
	 *
	 * @return void
	 */
	public function test_render_maps_autoplay_with_custom_interval(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				array(
					'limit'         => 6,
					'columns'       => 3,
					'show_image'    => true,
					'show_date'     => true,
					'show_time'     => true,
					'show_venue'    => true,
					'show_year'     => false,
					'autoplay'      => true,
					'interval'      => 3000,
					'playback_mode' => 'rewind',
					'max_tags'      => 3,
				)
			)
			->andReturn( '<div class="nte-carousel nte-carousel--autoplay"></div>' );

		$output = $this->render_block(
			array(
				'autoplay' => true,
				'interval' => 3000,
			)
		);

		$this->assertStringContainsString( 'nte-carousel', $output );
	}

	/**
	 * Test render maps all boolean display-toggle attributes.
	 *
	 * @return void
	 */
	public function test_render_maps_full_attribute_set(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				array(
					'limit'         => 8,
					'columns'       => 4,
					'show_image'    => false,
					'show_date'     => false,
					'show_time'     => false,
					'show_venue'    => false,
					'show_year'     => true,
					'autoplay'      => true,
					'interval'      => 4000,
					'playback_mode' => 'rewind',
					'max_tags'      => 3,
				)
			)
			->andReturn( '<div class="nte-carousel nte-carousel--minimal"></div>' );

		$output = $this->render_block(
			array(
				'limit'     => 8,
				'columns'   => 4,
				'showImage' => false,
				'showDate'  => false,
				'showTime'  => false,
				'showVenue' => false,
				'showYear'  => true,
				'autoplay'  => true,
				'interval'  => 4000,
			)
		);

		$this->assertIsString( $output );
		$this->assertNotEmpty( $output );
	}

	/**
	 * Test render maps the camelCase playbackMode block attribute to the
	 * snake_case playback_mode shortcode attribute (NTE-061).
	 *
	 * @return void
	 */
	public function test_render_maps_playback_mode_loop(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				array(
					'limit'         => 6,
					'columns'       => 3,
					'show_image'    => true,
					'show_date'     => true,
					'show_time'     => true,
					'show_venue'    => true,
					'show_year'     => false,
					'autoplay'      => true,
					'interval'      => 5000,
					'playback_mode' => 'loop',
					'max_tags'      => 3,
				)
			)
			->andReturn( '<div class="nte-carousel" data-playback-mode="loop"></div>' );

		$output = $this->render_block(
			array(
				'autoplay'     => true,
				'playbackMode' => 'loop',
			)
		);

		$this->assertStringContainsString( 'data-playback-mode="loop"', $output );
	}

	/**
	 * Test render maps the camelCase maxTags block attribute to the
	 * snake_case max_tags shortcode attribute (NTE-063).
	 *
	 * @return void
	 */
	public function test_render_maps_max_tags(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				array(
					'limit'         => 6,
					'columns'       => 3,
					'show_image'    => true,
					'show_date'     => true,
					'show_time'     => true,
					'show_venue'    => true,
					'show_year'     => false,
					'autoplay'      => false,
					'interval'      => 5000,
					'playback_mode' => 'rewind',
					'max_tags'      => 7,
				)
			)
			->andReturn( '<div class="nte-carousel"></div>' );

		$output = $this->render_block(
			array(
				'maxTags' => 7,
			)
		);

		$this->assertIsString( $output );
	}
}
