<?php
/**
 * CalendarBlock render.php unit tests.
 *
 * Verifies that the Calendar block render.php correctly maps camelCase block
 * attributes to snake_case shortcode attributes and delegates to the shortcode.
 *
 * @package NetterTechEvents\Tests\Unit\Blocks
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Blocks;

use Mockery;
use NetterTechEvents\Core\Container;
use NetterTechEvents\Frontend\Shortcodes\CalendarShortcode;

/**
 * Test Calendar block render.php attribute mapping.
 *
 * @since 1.0.0
 * @coversNothing
 */
class CalendarBlockTest extends \NetterTechEventsTestCase {

	/**
	 * Absolute path to the render.php file under test.
	 *
	 * @var string
	 */
	private string $render_file;

	/**
	 * Mock shortcode instance.
	 *
	 * @var CalendarShortcode|Mockery\MockInterface
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

		$this->render_file    = dirname( __DIR__, 3 ) . '/blocks/calendar/render.php';
		$this->mock_shortcode = Mockery::mock( CalendarShortcode::class );

		$container = new Container();
		$container->set( CalendarShortcode::class, $this->mock_shortcode );
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
					'view'               => 'month',
					'show_view_switcher' => true,
					'show_navigation'    => true,
				)
			)
			->andReturn( '<div class="nte-calendar"></div>' );

		$output = $this->render_block( array() );

		$this->assertSame( '<div class="nte-calendar"></div>', $output );
	}

	/**
	 * Test render maps week view attribute correctly.
	 *
	 * @return void
	 */
	public function test_render_maps_week_view(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				array(
					'view'               => 'week',
					'show_view_switcher' => true,
					'show_navigation'    => true,
				)
			)
			->andReturn( '<div class="nte-calendar nte-calendar--week"></div>' );

		$output = $this->render_block(
			array(
				'view' => 'week',
			)
		);

		$this->assertStringContainsString( 'nte-calendar--week', $output );
	}

	/**
	 * Test render maps all boolean attributes from block format.
	 *
	 * @return void
	 */
	public function test_render_maps_full_attribute_set(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->with(
				array(
					'view'               => 'day',
					'show_view_switcher' => false,
					'show_navigation'    => false,
				)
			)
			->andReturn( '<div class="nte-calendar nte-calendar--day"></div>' );

		$output = $this->render_block(
			array(
				'view'             => 'day',
				'showViewSwitcher' => false,
				'showNavigation'   => false,
			)
		);

		$this->assertNotEmpty( $output );
	}

	/**
	 * Test render returns non-empty string.
	 *
	 * @return void
	 */
	public function test_render_returns_non_empty_string(): void {
		$this->mock_shortcode
			->shouldReceive( 'render' )
			->once()
			->andReturn( '<div class="nte-calendar"><!-- calendar --></div>' );

		$output = $this->render_block( array() );

		$this->assertIsString( $output );
		$this->assertNotEmpty( $output );
	}
}
