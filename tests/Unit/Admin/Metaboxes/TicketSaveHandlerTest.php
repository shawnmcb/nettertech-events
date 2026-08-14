<?php
/**
 * TicketSaveHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Metaboxes\TicketSaveHandler;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Repositories\TicketTypeRepository;

/**
 * Test TicketSaveHandler functionality.
 *
 * Tests nonce verification, capability checks, ticket type creation,
 * scope handling, capacity type validation, and hook firing.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Metaboxes\TicketSaveHandler
 */
class TicketSaveHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Nonce action used in tests.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'save_ticket_types';

	/**
	 * Captured do_action calls.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $captured_actions = array();

	/**
	 * Mock ticket type repository.
	 *
	 * @var \NetterTechEvents\Contracts\TicketTypeRepositoryInterface&Mockery\MockInterface
	 */
	private \NetterTechEvents\Contracts\TicketTypeRepositoryInterface $mock_ticket_type_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mock_ticket_type_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$this->mock_ticket_type_repo->shouldIgnoreMissing();
	}

	/**
	 * Clean up $_POST after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Override do_action to capture calls for assertion.
	 *
	 * Brain Monkey's Actions\expectDone conflicts with the base class do_action stub,
	 * so we override the stub and capture args directly.
	 *
	 * @return void
	 */
	private function capture_do_action(): void {
		$this->captured_actions = array();
		Functions\when( 'do_action' )->alias(
			function () {
				$this->captured_actions[] = func_get_args();
			}
		);
	}

	/**
	 * Assert the TICKET_TYPES_SAVED hook was fired with expected args.
	 *
	 * @param int   $event_id      Expected event ID.
	 * @param int   $occurrence_id Expected occurrence ID.
	 * @param int[] $submitted_ids Expected submitted IDs.
	 * @return void
	 */
	private function assert_hook_fired( int $event_id, int $occurrence_id, array $submitted_ids ): void {
		$args = $this->hook_args();

		$this->assertSame( $event_id, $args[1], 'Hook event_id mismatch.' );
		$this->assertSame( $occurrence_id, $args[2], 'Hook occurrence_id mismatch.' );
		$this->assertSame( $submitted_ids, $args[3], 'Hook submitted_ids mismatch.' );
	}

	/**
	 * The arguments TICKET_TYPES_SAVED fired with.
	 *
	 * @return array<int, mixed>
	 */
	private function hook_args(): array {
		$hook_calls = array_filter(
			$this->captured_actions,
			fn( $args ) => $args[0] === Hooks::TICKET_TYPES_SAVED
		);

		$this->assertCount( 1, $hook_calls, 'TICKET_TYPES_SAVED should fire exactly once.' );

		return array_values( $hook_calls )[0];
	}

	/**
	 * Assert the TICKET_TYPES_SAVED hook was NOT fired.
	 *
	 * @return void
	 */
	private function assert_hook_not_fired(): void {
		$hook_calls = array_filter(
			$this->captured_actions,
			fn( $args ) => $args[0] === Hooks::TICKET_TYPES_SAVED
		);

		$this->assertCount( 0, $hook_calls, 'TICKET_TYPES_SAVED should not fire.' );
	}

	// =========================================================================
	// Nonce & Capability Guard Tests
	// =========================================================================

	/**
	 * Test handle returns early when nonce is missing from POST.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_returns_early_when_nonce_missing(): void {
		$this->capture_do_action();
		$_POST = array();

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_not_fired();
	}

	/**
	 * Test handle returns early when nonce verification fails.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_returns_early_when_nonce_invalid(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'bad_nonce',
		);

		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_not_fired();
	}

	/**
	 * Test handle returns early when user lacks edit_posts capability.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_returns_early_when_user_lacks_capability(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
		);

		Functions\when( 'current_user_can' )->justReturn( false );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_not_fired();
	}

	/**
	 * Test handle returns early when ticket_types POST data is not an array.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_returns_early_when_ticket_types_not_array(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => 'not_an_array',
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldNotReceive( 'save' );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_not_fired();
	}

	// =========================================================================
	// Successful Processing Tests
	// =========================================================================

	/**
	 * Test handle processes ticket types and fires the saved hook.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_processes_ticket_types_and_fires_hook(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'General Admission',
						'price'         => '25.00',
						'capacity_type' => 'fixed',
						'capacity'      => '100',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 'General Admission' === $tt->name
					&& 25.0 === $tt->price
					&& 'fixed' === $tt->capacity_type
					&& 100 === $tt->capacity
					&& 'occurrence' === $tt->scope;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				$tt->id = 1;
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 42 );

		$this->assert_hook_fired( 42, 0, array( 1 ) );
	}

	/**
	 * Test handle tracks submitted IDs for existing ticket types.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_tracks_submitted_ids_for_existing_types(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'id'            => '55',
						'name'          => 'Existing Ticket',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
						'capacity'      => '50',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 55 === $tt->id;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array( 55 ) );
	}

	/**
	 * Test handle processes multiple scopes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_processes_multiple_scopes(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Single Ticket',
						'price'         => '15.00',
						'capacity_type' => 'fixed',
					),
				),
				'event'      => array(
					array(
						'name'          => 'Series Pass',
						'price'         => '50.00',
						'capacity_type' => 'unlimited',
					),
				),
			),
		);

		$saved_scopes = array();
		$mock_repo    = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->twice()
			->andReturnUsing( function ( TicketType $tt ) use ( &$saved_scopes ) {
				$saved_scopes[] = $tt->scope;
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assertContains( 'occurrence', $saved_scopes );
		$this->assertContains( 'event', $saved_scopes );
		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test handle skips non-array scope entries.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_skips_non_array_scope_entries(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => 'not_an_array',
				'event'      => array(
					array(
						'name'          => 'Valid Ticket',
						'price'         => '20.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	// =========================================================================
	// create_ticket_type_from_data Tests (via handle)
	// =========================================================================

	/**
	 * Test empty name produces null and ticket is skipped.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_skips_ticket_with_empty_name(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => '',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldNotReceive( 'save' );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test invalid capacity_type defaults to 'fixed'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_defaults_invalid_capacity_type_to_fixed(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Test Ticket',
						'price'         => '10.00',
						'capacity_type' => 'bogus_type',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 'fixed' === $tt->capacity_type;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test SHARED capacity_type forced to 'fixed' for non-occurrence scope.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_forces_shared_to_fixed_for_event_scope(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'event' => array(
					array(
						'name'          => 'Series Pass',
						'price'         => '30.00',
						'capacity_type' => 'shared',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 'fixed' === $tt->capacity_type
					&& 'event' === $tt->scope;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test SHARED capacity_type preserved for occurrence scope.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_preserves_shared_for_occurrence_scope(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Shared Ticket',
						'price'         => '20.00',
						'capacity_type' => 'shared',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 'shared' === $tt->capacity_type
					&& 'occurrence' === $tt->scope;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test occurrence scope sets occurrence_id on the ticket type.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_sets_occurrence_id_for_occurrence_scope(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Occurrence Ticket',
						'price'         => '15.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 99 === $tt->occurrence_id
					&& 'occurrence' === $tt->scope;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1, 99 );

		$this->assert_hook_fired( 1, 99, array() );
	}

	/**
	 * Test non-occurrence scope does not set occurrence_id.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_does_not_set_occurrence_id_for_event_scope(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'event' => array(
					array(
						'name'          => 'Event Ticket',
						'price'         => '25.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return null === $tt->occurrence_id
					&& 'event' === $tt->scope;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1, 99 );

		$this->assert_hook_fired( 1, 99, array() );
	}

	/**
	 * Test sale dates are set when provided.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_sets_sale_dates_when_provided(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Timed Sale',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
						'sale_start'    => '2026-03-01 09:00:00',
						'sale_end'      => '2026-03-15 23:59:59',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return '2026-03-01 09:00:00' === $tt->sale_start
					&& '2026-03-15 23:59:59' === $tt->sale_end;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Split date + time fields (NTE-190) recombine to the exact wire format
	 * the old datetime-local input submitted — byte-identical round trip.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_composes_split_sale_fields_to_legacy_format(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'            => 'Split Fields',
						'price'           => '10.00',
						'capacity_type'   => 'fixed',
						// Decomposed form of stored '2026-03-01 09:00:00'.
						'sale_start_date' => '2026-03-01',
						'sale_start_time' => '09:00',
						'sale_end_date'   => '2026-03-15',
						'sale_end_time'   => '23:59',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return '2026-03-01T09:00' === $tt->sale_start
					&& '2026-03-15T23:59' === $tt->sale_end;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * A boundary date without a time falls back to an explicit default:
	 * 00:00 for sale start, 23:59 for sale end. A time without a date is
	 * ignored (no boundary).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_defaults_missing_sale_times_per_boundary(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'            => 'Date Only',
						'price'           => '10.00',
						'capacity_type'   => 'fixed',
						'sale_start_date' => '2026-03-01',
						'sale_end_date'   => '2026-03-15',
						// Time without a date: ignored, no boundary.
						'sale_end_time'   => '',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return '2026-03-01T00:00' === $tt->sale_start
					&& '2026-03-15T23:59' === $tt->sale_end;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test sale dates remain null when not provided.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_leaves_sale_dates_null_when_absent(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'No Dates',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return null === $tt->sale_start
					&& null === $tt->sale_end;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test default min/max per order come from settings.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_uses_default_min_max_from_settings(): void {
		$this->capture_do_action();

		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array(
						'default_min_per_order' => 2,
						'default_max_per_order' => 8,
					);
				}
				return $default;
			}
		);

		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Default Limits',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 2 === $tt->min_per_order
					&& 8 === $tt->max_per_order;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test explicit min/max per order override defaults.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_uses_explicit_min_max_over_defaults(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Custom Limits',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
						'min_per_order' => '3',
						'max_per_order' => '6',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 3 === $tt->min_per_order
					&& 6 === $tt->max_per_order;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	// =========================================================================
	// Error Handling Tests
	// =========================================================================

	/**
	 * Test handle catches RuntimeException from repo save and continues.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_catches_runtime_exception_and_logs(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Will Fail',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
					),
					array(
						'name'          => 'Will Succeed',
						'price'         => '20.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$call_count = 0;
		$mock_repo  = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->twice()
			->andReturnUsing(
				function ( TicketType $tt ) use ( &$call_count ) {
					++$call_count;
					if ( 1 === $call_count ) {
						throw new \RuntimeException( 'DB insert failed' );
					}
					return $tt;
				}
			);

		// WP_DEBUG is true in phpunit.xml.dist, so error_log should be called.
		$logged_message = '';
		Functions\when( 'error_log' )->alias(
			function ( $message ) use ( &$logged_message ) {
				$logged_message = $message;
			}
		);

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assertStringContainsString( '[NTE:TicketSaveHandler]', $logged_message );
		$this->assertStringContainsString( 'DB insert failed', $logged_message );
		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test handle sets event_id on ticket type.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_sets_event_id_on_ticket_type(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Test',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 42 === $tt->event_id;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 42 );

		$this->assert_hook_fired( 42, 0, array() );
	}

	/**
	 * Test handle sets status to active on new ticket types.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_sets_active_status(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Active Ticket',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 'active' === $tt->status;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test handle sanitizes description with sanitize_textarea_field.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_sanitizes_description(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Described Ticket',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
						'description'   => 'A detailed description of this ticket type.',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 'A detailed description of this ticket type.' === $tt->description;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor stores nonce action.
	 *
	 * @covers ::__construct
	 *
	 * @return void
	 */
	public function test_constructor_stores_nonce_action(): void {
		$this->capture_do_action();

		$handler = new TicketSaveHandler( 'custom_nonce_action', $mock_repo ?? $this->mock_ticket_type_repo );

		$_POST = array(
			'custom_nonce_action' => 'valid_nonce',
			'ticket_types'        => array(),
		);

		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test handle with empty ticket_types array still fires hook.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_fires_hook_with_empty_ticket_types_array(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldNotReceive( 'save' );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test handle sets capacity to null when not provided.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_sets_capacity_null_when_empty(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'Unlimited',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
						'capacity'      => '',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return null === $tt->capacity;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test handle with ticket_types missing from POST defaults to empty array.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_defaults_missing_ticket_types_to_empty_array(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldNotReceive( 'save' );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test SHARED capacity_type forced to 'fixed' for template scope.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::create_ticket_type_from_data
	 *
	 * @return void
	 */
	public function test_handle_forces_shared_to_fixed_for_template_scope(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'template' => array(
					array(
						'name'          => 'Template Ticket',
						'price'         => '10.00',
						'capacity_type' => 'shared',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( TicketType $tt ) {
				return 'fixed' === $tt->capacity_type
					&& 'template' === $tt->scope;
			} ) )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array() );
	}

	/**
	 * Test handle tracks multiple existing ticket type IDs.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_tracks_multiple_submitted_ids(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'id'            => '10',
						'name'          => 'First',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
					),
					array(
						'id'            => '20',
						'name'          => 'Second',
						'price'         => '20.00',
						'capacity_type' => 'fixed',
					),
					array(
						'name'          => 'New One',
						'price'         => '30.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->times( 3 )
			->andReturnUsing( function ( TicketType $tt ) {
				return $tt;
			} );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array( 10, 20 ) );
	}

	/**
	 * Test a tier created in this request reaches the hook, with its brand-new id.
	 *
	 * The id is assigned by the insert and exists nowhere in the payload that produced it. If
	 * the hook does not carry it, an extension wanting to attach something to a tier the
	 * operator has just created has no way to name it — which is what Pro's early bird needs to
	 * do the moment an operator adds a tier and configures it in the same save.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_a_tier_created_in_this_request_reaches_the_hook(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					// Row 0: an edit. Row 1: brand new, no id in the payload.
					0 => array(
						'id'            => '10',
						'name'          => 'Existing',
						'price'         => '10.00',
						'capacity_type' => 'fixed',
					),
					1 => array(
						'name'          => 'Brand New',
						'price'         => '30.00',
						'capacity_type' => 'fixed',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->times( 2 )
			->andReturnUsing(
				function ( TicketType $tt ) {
					// What a real insert does: hands back an id the caller never had.
					if ( null === $tt->id ) {
						$tt->id = 77;
					}
					return $tt;
				}
			);

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo );
		$handler->handle( 1 );

		$this->assert_hook_fired( 1, 0, array( 10, 77 ) );

		$args = $this->hook_args();

		$this->assertSame(
			array(
				'occurrence' => array(
					0 => 10,
					1 => 77,
				),
			),
			$args[4],
			'The hook must map each posted row to the tier it became.'
		);
	}

	// =========================================================================
	// Buffer Stock Save Tests
	// =========================================================================

	/**
	 * Test buffer stock saves correctly when provided in form data.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::save_buffer_stock
	 *
	 * @return void
	 */
	public function test_buffer_stock_saves_correctly(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'General Admission',
						'price'         => '25.00',
						'capacity_type' => 'fixed',
						'capacity'      => '100',
						'buffer_stock'  => '10',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->andReturnUsing( function ( TicketType $tt ) {
				$tt->id = 1;
				return $tt;
			} );

		$mock_capacity = Mockery::mock( CapacityServiceInterface::class );
		$mock_capacity->shouldReceive( 'set_buffer_stock' )
			->once()
			->with( 1, 10 )
			->andReturn( true );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo, null, null, $mock_capacity );
		$handler->handle( 42 );

		$this->assert_hook_fired( 42, 0, array( 1 ) );
	}

	/**
	 * Test buffer stock defaults to zero when not provided.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::save_buffer_stock
	 *
	 * @return void
	 */
	public function test_buffer_stock_defaults_to_zero(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'General Admission',
						'price'         => '25.00',
						'capacity_type' => 'fixed',
						'capacity'      => '100',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->andReturnUsing( function ( TicketType $tt ) {
				$tt->id = 1;
				return $tt;
			} );

		$mock_capacity = Mockery::mock( CapacityServiceInterface::class );
		$mock_capacity->shouldReceive( 'set_buffer_stock' )
			->once()
			->with( 1, 0 )
			->andReturn( true );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo, null, null, $mock_capacity );
		$handler->handle( 42 );

		$this->assert_hook_fired( 42, 0, array( 1 ) );
	}

	/**
	 * Test buffer stock clamps to capacity minus one when buffer >= capacity.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::save_buffer_stock
	 *
	 * @return void
	 */
	public function test_buffer_stock_clamps_when_exceeding_capacity(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'General Admission',
						'price'         => '25.00',
						'capacity_type' => 'fixed',
						'capacity'      => '50',
						'buffer_stock'  => '50',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->andReturnUsing( function ( TicketType $tt ) {
				$tt->id = 1;
				return $tt;
			} );

		$mock_capacity = Mockery::mock( CapacityServiceInterface::class );
		// Buffer of 50 with capacity 50 should clamp to 49.
		$mock_capacity->shouldReceive( 'set_buffer_stock' )
			->once()
			->with( 1, 49 )
			->andReturn( true );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo, null, null, $mock_capacity );
		$handler->handle( 42 );

		$this->assert_hook_fired( 42, 0, array( 1 ) );
	}

	/**
	 * Test buffer stock clamps to capacity minus one when buffer exceeds capacity.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::save_buffer_stock
	 *
	 * @return void
	 */
	public function test_buffer_stock_clamps_when_greater_than_capacity(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'General Admission',
						'price'         => '25.00',
						'capacity_type' => 'fixed',
						'capacity'      => '10',
						'buffer_stock'  => '99',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->andReturnUsing( function ( TicketType $tt ) {
				$tt->id = 1;
				return $tt;
			} );

		$mock_capacity = Mockery::mock( CapacityServiceInterface::class );
		// Buffer of 99 with capacity 10 should clamp to 9.
		$mock_capacity->shouldReceive( 'set_buffer_stock' )
			->once()
			->with( 1, 9 )
			->andReturn( true );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo, null, null, $mock_capacity );
		$handler->handle( 42 );

		$this->assert_hook_fired( 42, 0, array( 1 ) );
	}

	/**
	 * Test buffer stock not saved when capacity service is null.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_buffer_stock_not_saved_without_capacity_service(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'General Admission',
						'price'         => '25.00',
						'capacity_type' => 'fixed',
						'capacity'      => '100',
						'buffer_stock'  => '10',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->andReturnUsing( function ( TicketType $tt ) {
				$tt->id = 1;
				return $tt;
			} );

		// No capacity service passed — buffer stock should be silently skipped.
		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo );
		$handler->handle( 42 );

		$this->assert_hook_fired( 42, 0, array( 1 ) );
	}

	/**
	 * Test buffer stock allowed without limit for unlimited capacity.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @covers ::handle
	 * @covers ::save_buffer_stock
	 *
	 * @return void
	 */
	public function test_buffer_stock_allowed_for_unlimited_capacity(): void {
		$this->capture_do_action();
		$_POST = array(
			self::NONCE_ACTION => 'valid_nonce',
			'ticket_types'     => array(
				'occurrence' => array(
					array(
						'name'          => 'General Admission',
						'price'         => '25.00',
						'capacity_type' => 'fixed',
						'buffer_stock'  => '500',
					),
				),
			),
		);

		$mock_repo = Mockery::mock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$mock_repo->shouldReceive( 'save' )
			->once()
			->andReturnUsing( function ( TicketType $tt ) {
				$tt->id = 1;
				return $tt;
			} );

		$mock_capacity = Mockery::mock( CapacityServiceInterface::class );
		// Unlimited capacity (null) — buffer stock should pass through unclamped.
		$mock_capacity->shouldReceive( 'set_buffer_stock' )
			->once()
			->with( 1, 500 )
			->andReturn( true );

		$handler = new TicketSaveHandler( self::NONCE_ACTION, $mock_repo ?? $this->mock_ticket_type_repo, null, null, $mock_capacity );
		$handler->handle( 42 );

		$this->assert_hook_fired( 42, 0, array( 1 ) );
	}
}
