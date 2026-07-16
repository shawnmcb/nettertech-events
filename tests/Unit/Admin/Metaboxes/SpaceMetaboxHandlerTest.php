<?php
/**
 * SpaceMetaboxHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Metaboxes\SpaceMetaboxHandler;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Models\Space;

/**
 * Test SpaceMetaboxHandler functionality.
 */
class SpaceMetaboxHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock space repository.
	 *
	 * @var SpaceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $space_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->space_repo = $this->createMock( SpaceRepositoryInterface::class );

		$this->setup_wp_functions();
	}

	/**
	 * Set up common WordPress function mocks.
	 *
	 * @return void
	 */
	private function setup_wp_functions(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) {
			echo $text;
		} );
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com/wp-admin/' . $path;
			}
		);
	}

	/**
	 * Test render shows empty message when no spaces.
	 *
	 * @return void
	 */
	public function test_render_shows_empty_message_when_no_spaces(): void {
		$this->space_repo->method( 'paginate' )->willReturn( array() );

		$handler = new SpaceMetaboxHandler( $this->space_repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No spaces found.', $output );
		$this->assertStringContainsString( 'Go to Spaces', $output );
	}

	/**
	 * Test render shows dropdown when spaces exist.
	 *
	 * @return void
	 */
	public function test_render_shows_dropdown_when_spaces_exist(): void {
		$space       = new Space();
		$space->id   = 1;
		$space->name = 'Main Hall';
		$space->capacity = 200;

		$this->space_repo->method( 'paginate' )->willReturn( array( $space ) );

		$handler = new SpaceMetaboxHandler( $this->space_repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'event_space_id', $output );
		$this->assertStringContainsString( 'Main Hall', $output );
		$this->assertStringContainsString( 'Capacity: 200', $output );
	}

	/**
	 * Test render shows dropdown without capacity when zero.
	 *
	 * @return void
	 */
	public function test_render_omits_capacity_when_zero(): void {
		$space           = new Space();
		$space->id       = 1;
		$space->name     = 'Small Room';
		$space->capacity = 0;

		$this->space_repo->method( 'paginate' )->willReturn( array( $space ) );

		$handler = new SpaceMetaboxHandler( $this->space_repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Small Room', $output );
		$this->assertStringNotContainsString( 'Capacity:', $output );
	}

	/**
	 * Test render shows "no space assigned" option.
	 *
	 * @return void
	 */
	public function test_render_shows_no_space_assigned_option(): void {
		$space       = new Space();
		$space->id   = 1;
		$space->name = 'Main Hall';
		$space->capacity = 100;

		$this->space_repo->method( 'paginate' )->willReturn( array( $space ) );

		$handler = new SpaceMetaboxHandler( $this->space_repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No space assigned', $output );
	}

	/**
	 * Test metabox header text.
	 *
	 * @return void
	 */
	public function test_render_has_correct_heading(): void {
		$this->space_repo->method( 'paginate' )->willReturn( array() );

		$handler = new SpaceMetaboxHandler( $this->space_repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Space / Venue', $output );
	}

	/**
	 * Test render preselects the currently assigned space.
	 *
	 * @return void
	 */
	public function test_render_preselects_current_space(): void {
		$space           = new Space();
		$space->id       = 7;
		$space->name     = 'Main Hall';
		$space->capacity = 0;

		$other           = new Space();
		$other->id       = 9;
		$other->name     = 'Annex';
		$other->capacity = 0;

		$this->space_repo->method( 'paginate' )->willReturn( array( $space, $other ) );

		$handler = new SpaceMetaboxHandler( $this->space_repo, 7 );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="7" selected="selected"', $output );
		$this->assertStringNotContainsString( 'value="9" selected="selected"', $output );
	}

	/**
	 * Test render selects nothing when no space is assigned.
	 *
	 * @return void
	 */
	public function test_render_selects_nothing_when_unassigned(): void {
		$space           = new Space();
		$space->id       = 7;
		$space->name     = 'Main Hall';
		$space->capacity = 0;

		$this->space_repo->method( 'paginate' )->willReturn( array( $space ) );

		$handler = new SpaceMetaboxHandler( $this->space_repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'selected="selected"', $output );
	}
}
