<?php
/**
 * OccurrenceSpaceResolver unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\Seating
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\Seating;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Integrations\Seating\OccurrenceSpaceResolver;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Test occurrence → event → space resolution for the Seating add-on.
 */
class OccurrenceSpaceResolverTest extends \NetterTechEventsTestCase {

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Resolver under test.
	 *
	 * @var OccurrenceSpaceResolver
	 */
	private OccurrenceSpaceResolver $resolver;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->occurrence_repo = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->event_repo      = $this->createMock( EventRepositoryInterface::class );
		$this->resolver        = new OccurrenceSpaceResolver( $this->occurrence_repo, $this->event_repo );
	}

	/**
	 * Test register() hooks both seating resolution filters at priority 5.
	 *
	 * @return void
	 */
	public function test_register_hooks_both_filters_at_priority_five(): void {
		$registered = array();
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$registered ) {
				$registered[ $hook ] = array(
					'callback' => $callback,
					'priority' => $priority,
					'args'     => $args,
				);
			}
		);

		$this->resolver->register();

		foreach ( array( 'nettertech_events_seating_resolve_space', 'nettertech_events_seating_occurrence_space_id' ) as $hook ) {
			$this->assertArrayHasKey( $hook, $registered );
			$this->assertSame( array( $this->resolver, 'resolve_space' ), $registered[ $hook ]['callback'] );
			$this->assertSame( 5, $registered[ $hook ]['priority'] );
			$this->assertSame( 2, $registered[ $hook ]['args'] );
		}
	}

	/**
	 * Test an already-resolved space ID passes through untouched.
	 *
	 * @return void
	 */
	public function test_already_resolved_space_passes_through(): void {
		$this->occurrence_repo->expects( $this->never() )->method( 'find' );

		$this->assertSame( 42, $this->resolver->resolve_space( 42, 10 ) );
	}

	/**
	 * Test an invalid occurrence ID passes through unresolved.
	 *
	 * @return void
	 */
	public function test_invalid_occurrence_id_passes_through(): void {
		$this->occurrence_repo->expects( $this->never() )->method( 'find' );

		$this->assertSame( 0, $this->resolver->resolve_space( 0, 0 ) );
	}

	/**
	 * Test a missing occurrence resolves to the incoming value.
	 *
	 * @return void
	 */
	public function test_missing_occurrence_returns_unresolved(): void {
		$this->occurrence_repo->method( 'find' )->with( 10 )->willReturn( null );

		$this->assertSame( 0, $this->resolver->resolve_space( 0, 10 ) );
	}

	/**
	 * Test an event without a space assignment resolves to the incoming value.
	 *
	 * @return void
	 */
	public function test_event_without_assignment_returns_unresolved(): void {
		$occurrence           = new Occurrence();
		$occurrence->event_id = 5;

		$event           = new Event();
		$event->id       = 5;
		$event->space_id = null;

		$this->occurrence_repo->method( 'find' )->with( 10 )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->with( 5 )->willReturn( $event );

		$this->assertSame( 0, $this->resolver->resolve_space( 0, 10 ) );
	}

	/**
	 * Test the event's assigned space resolves.
	 *
	 * @return void
	 */
	public function test_assigned_space_resolves(): void {
		$occurrence           = new Occurrence();
		$occurrence->event_id = 5;

		$event           = new Event();
		$event->id       = 5;
		$event->space_id = 7;

		$this->occurrence_repo->method( 'find' )->with( 10 )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->with( 5 )->willReturn( $event );

		$this->assertSame( 7, $this->resolver->resolve_space( 0, 10 ) );
	}
}
