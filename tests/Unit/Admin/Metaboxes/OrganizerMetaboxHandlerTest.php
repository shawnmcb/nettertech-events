<?php
/**
 * OrganizerMetaboxHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Metaboxes\OrganizerMetaboxHandler;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Models\Organizer;

/**
 * Test OrganizerMetaboxHandler rendering.
 *
 * Covers render output for empty organizers, populated organizers,
 * and correct assignment state.
 */
class OrganizerMetaboxHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock organizer repository.
	 *
	 * @var OrganizerRepositoryInterface|Mockery\MockInterface
	 */
	private $repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repo = Mockery::mock( OrganizerRepositoryInterface::class );
	}

	/**
	 * Create a test organizer.
	 *
	 * @param int    $id   Organizer ID.
	 * @param string $name Organizer name.
	 * @return Organizer
	 */
	private function make_organizer( int $id, string $name ): Organizer {
		$org       = new Organizer();
		$org->id   = $id;
		$org->name = $name;
		return $org;
	}

	// =========================================================================
	// render() Tests
	// =========================================================================

	/**
	 * Test render shows empty state when no organizers exist.
	 *
	 * @return void
	 */
	public function test_render_shows_empty_state(): void {
		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );

		$handler = new OrganizerMetaboxHandler( 0, $this->repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No organizers found.', $output );
		$this->assertStringContainsString( 'Go to Organizers', $output );
	}

	/**
	 * Test render shows organizer checkboxes.
	 *
	 * @return void
	 */
	public function test_render_shows_organizer_checkboxes(): void {
		$org1 = $this->make_organizer( 1, 'Organizer A' );
		$org2 = $this->make_organizer( 2, 'Organizer B' );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $org1, $org2 ) );

		$handler = new OrganizerMetaboxHandler( 0, $this->repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Organizer A', $output );
		$this->assertStringContainsString( 'Organizer B', $output );
		$this->assertStringContainsString( 'event_organizers[]', $output );
	}

	/**
	 * Test render pre-checks assigned organizers for existing event.
	 *
	 * @return void
	 */
	public function test_render_checks_assigned_organizers(): void {
		$org1 = $this->make_organizer( 1, 'Organizer A' );
		$org2 = $this->make_organizer( 2, 'Organizer B' );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $org1, $org2 ) );
		$this->repo->shouldReceive( 'find_by_event' )->with( 42 )->once()->andReturn( array( $org1 ) );

		Functions\when( 'checked' )->alias(
			function ( $checked, $current = true, $display = true ) {
				$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
				if ( $display ) {
					echo $result;
				}
				return $result;
			}
		);

		$handler = new OrganizerMetaboxHandler( 42, $this->repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checked="checked"', $output );
		$this->assertStringContainsString( 'Organizer A', $output );
		$this->assertStringContainsString( 'Organizer B', $output );
	}

	/**
	 * Test render does not call find_by_event for new events.
	 *
	 * @return void
	 */
	public function test_render_skips_find_by_event_for_new_events(): void {
		$org = $this->make_organizer( 1, 'Organizer A' );

		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array( $org ) );
		$this->repo->shouldNotReceive( 'find_by_event' );

		$handler = new OrganizerMetaboxHandler( 0, $this->repo );

		ob_start();
		$handler->render();
		ob_get_clean();
	}

	/**
	 * Test render includes postbox structure.
	 *
	 * @return void
	 */
	public function test_render_includes_postbox_structure(): void {
		$this->repo->shouldReceive( 'get_all' )->once()->andReturn( array() );

		$handler = new OrganizerMetaboxHandler( 0, $this->repo );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'postbox', $output );
		$this->assertStringContainsString( 'Organizers', $output );
	}
}
