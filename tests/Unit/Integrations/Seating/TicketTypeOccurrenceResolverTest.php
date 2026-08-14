<?php
/**
 * TicketTypeOccurrenceResolver unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\Seating
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\Seating;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Integrations\Seating\TicketTypeOccurrenceResolver;
use NetterTechEvents\Models\TicketType;

/**
 * Test ticket-type → occurrence resolution for the Seating add-on.
 */
class TicketTypeOccurrenceResolverTest extends \NetterTechEventsTestCase {

	/**
	 * Mock ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Resolver under test.
	 *
	 * @var TicketTypeOccurrenceResolver
	 */
	private TicketTypeOccurrenceResolver $resolver;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->ticket_type_repo = $this->createMock( TicketTypeRepositoryInterface::class );
		$this->resolver         = new TicketTypeOccurrenceResolver( $this->ticket_type_repo );
	}

	/**
	 * Test register() hooks the resolution filter at priority 5.
	 *
	 * @return void
	 */
	public function test_register_hooks_filter_at_priority_five(): void {
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

		$this->assertArrayHasKey( 'nettertech_events_seating_resolve_occurrence', $registered );
		$hook = $registered['nettertech_events_seating_resolve_occurrence'];
		$this->assertSame( array( $this->resolver, 'resolve_occurrence' ), $hook['callback'] );
		$this->assertSame( 5, $hook['priority'] );
		$this->assertSame( 2, $hook['args'] );
	}

	/**
	 * Test an already-resolved occurrence ID passes through untouched.
	 *
	 * @return void
	 */
	public function test_already_resolved_occurrence_passes_through(): void {
		$this->ticket_type_repo->expects( $this->never() )->method( 'find' );

		$this->assertSame( 42, $this->resolver->resolve_occurrence( 42, 10 ) );
	}

	/**
	 * Test an invalid ticket type ID passes through unresolved.
	 *
	 * @return void
	 */
	public function test_invalid_ticket_type_id_passes_through(): void {
		$this->ticket_type_repo->expects( $this->never() )->method( 'find' );

		$this->assertSame( 0, $this->resolver->resolve_occurrence( 0, 0 ) );
	}

	/**
	 * Test a missing ticket type resolves to the incoming value.
	 *
	 * @return void
	 */
	public function test_missing_ticket_type_returns_unresolved(): void {
		$this->ticket_type_repo->method( 'find' )->with( 10 )->willReturn( null );

		$this->assertSame( 0, $this->resolver->resolve_occurrence( 0, 10 ) );
	}

	/**
	 * Test a ticket type without an occurrence (event/template scope) passes through.
	 *
	 * @return void
	 */
	public function test_ticket_type_without_occurrence_returns_unresolved(): void {
		$ticket_type                = new TicketType();
		$ticket_type->id            = 10;
		$ticket_type->scope         = 'event';
		$ticket_type->occurrence_id = null;

		$this->ticket_type_repo->method( 'find' )->with( 10 )->willReturn( $ticket_type );

		$this->assertSame( 0, $this->resolver->resolve_occurrence( 0, 10 ) );
	}

	/**
	 * Test an occurrence-scoped ticket type resolves to its occurrence.
	 *
	 * @return void
	 */
	public function test_occurrence_scoped_ticket_type_resolves(): void {
		$ticket_type                = new TicketType();
		$ticket_type->id            = 10;
		$ticket_type->scope         = 'occurrence';
		$ticket_type->occurrence_id = 77;

		$this->ticket_type_repo->method( 'find' )->with( 10 )->willReturn( $ticket_type );

		$this->assertSame( 77, $this->resolver->resolve_occurrence( 0, 10 ) );
	}
}
