<?php
/**
 * TicketTypeSaver unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\TicketTypeSaver;

/**
 * Test TicketTypeSaver functionality.
 */
class TicketTypeSaverTest extends \NetterTechEventsTestCase {

	/**
	 * Mock ticket type repository.
	 *
	 * @var TicketTypeRepository|Mockery\MockInterface
	 */
	private $mock_repo;

	/**
	 * TicketTypeSaver instance.
	 *
	 * @var TicketTypeSaver
	 */
	private TicketTypeSaver $saver;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mock_repo = Mockery::mock( TicketTypeRepository::class );
		// Edits carry the stored row's WC links forward (NTE-158); the builder
		// reads them via find(). Null is the neutral default for tests that do
		// not care; tests pinning the carry-forward override this.
		$this->mock_repo->shouldReceive( 'find' )->andReturn( null )->byDefault();
		$this->saver     = new TicketTypeSaver( $this->mock_repo );

		// Mock WordPress functions - sanitize_text_field trims whitespace.
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $str ) {
				return trim( (string) $str );
			}
		);
	}

	// =========================================================================
	// Ticketing Disabled Tests
	// =========================================================================

	/**
	 * Test deletes ticket types when ticketing disabled.
	 *
	 * @return void
	 */
	public function test_deletes_ticket_types_when_ticketing_disabled(): void {
		$this->mock_repo
			->shouldReceive( 'delete_for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( 1 );

		$post_data = array( 'ticketing_enabled' => '' );

		$this->saver->save_for_occurrence( 123, $post_data );

		// Mockery verifies the expectation was met.
		$this->assertTrue( true );
	}

	/**
	 * Test deletes ticket types when ticketing_enabled is missing.
	 *
	 * @return void
	 */
	public function test_deletes_ticket_types_when_ticketing_enabled_missing(): void {
		$this->mock_repo
			->shouldReceive( 'delete_for_occurrence' )
			->once()
			->with( 456 )
			->andReturn( 0 );

		$post_data = array();

		$this->saver->save_for_occurrence( 456, $post_data );

		// Mockery verifies the expectation was met.
		$this->assertTrue( true );
	}

	// =========================================================================
	// Occurrence ID Mismatch Tests
	// =========================================================================

	/**
	 * Test returns early when occurrence_id mismatch.
	 *
	 * @return void
	 */
	public function test_returns_early_when_occurrence_id_mismatch(): void {
		// Should not call any repository methods.
		$this->mock_repo->shouldNotReceive( 'for_occurrence' );
		$this->mock_repo->shouldNotReceive( 'save' );
		$this->mock_repo->shouldNotReceive( 'delete' );

		$post_data = array(
			'ticketing_enabled'        => '1',
			'occurrence_id_for_tickets' => 999,
			'ticket_types'             => array(),
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		// Verify by asserting we got here without exception.
		$this->assertTrue( true );
	}

	// =========================================================================
	// Invalid Data Tests
	// =========================================================================

	/**
	 * Test returns early when ticket_types is not array.
	 *
	 * @return void
	 */
	public function test_returns_early_when_ticket_types_not_array(): void {
		$this->mock_repo->shouldNotReceive( 'for_occurrence' );

		$post_data = array(
			'ticketing_enabled'        => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'             => 'not-an-array',
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		$this->assertTrue( true );
	}

	/**
	 * Test skips ticket types with empty names.
	 *
	 * @return void
	 */
	public function test_skips_ticket_types_with_empty_names(): void {
		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array() );

		// Should not call save for empty name.
		$this->mock_repo->shouldNotReceive( 'save' );

		$post_data = array(
			'ticketing_enabled'        => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'             => array(
				array( 'name' => '', 'price' => 10 ),
				array( 'name' => '   ', 'price' => 20 ),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		$this->assertTrue( true );
	}

	// =========================================================================
	// Save Ticket Types Tests
	// =========================================================================

	/**
	 * Test saves new ticket type.
	 *
	 * @return void
	 */
	public function test_saves_new_ticket_type(): void {
		$saved_ticket = null;

		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array() );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $ticket_type ) use ( &$saved_ticket ) {
				$saved_ticket = $ticket_type;
				return $ticket_type instanceof TicketType;
			} ) );

		$post_data = array(
			'ticketing_enabled'        => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'             => array(
				array(
					'name'          => 'General Admission',
					'price'         => 25,
					'capacity_type' => 'fixed',
					'capacity'      => 100,
				),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		$this->assertNotNull( $saved_ticket );
		$this->assertSame( 123, $saved_ticket->occurrence_id );
		$this->assertSame( 'General Admission', $saved_ticket->name );
		$this->assertSame( 25.0, $saved_ticket->price );
		$this->assertSame( 100, $saved_ticket->capacity );
		$this->assertSame( 'active', $saved_ticket->status );
	}

	/**
	 * Test saves new ticket type from scope-keyed form data.
	 *
	 * Regression: the form posts ticket_types nested under a scope key
	 * (ticket_types[occurrence][N][field]), but process_ticket_types
	 * iterated the top-level array directly. The first iteration handed
	 * the nested 'occurrence' sub-array to the per-ticket logic, which
	 * found no 'name' field at that level and silently skipped — single-
	 * event ticket saves no-op'd in the admin (Five Cent Test case).
	 *
	 * @return void
	 */
	public function test_saves_new_ticket_type_from_scope_keyed_form_data(): void {
		$saved_ticket = null;

		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array() );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $ticket_type ) use ( &$saved_ticket ) {
				$saved_ticket = $ticket_type;
				return $ticket_type instanceof TicketType;
			} ) );

		$post_data = array(
			'ticketing_enabled'         => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'              => array(
				'occurrence' => array(
					array(
						'name'          => 'Five Cent Test',
						'price'         => 0.05,
						'capacity_type' => 'fixed',
						'capacity'      => 100,
					),
				),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		$this->assertNotNull( $saved_ticket, 'Scope-keyed ticket_types must reach the save path' );
		$this->assertSame( 'Five Cent Test', $saved_ticket->name );
		$this->assertSame( 0.05, $saved_ticket->price );
	}

	/**
	 * Test occurrence ticket save persists sale window, description, and order limits.
	 *
	 * Regression: build_ticket_type() mapped only name/price/capacity, silently
	 * discarding sale_start, sale_end, description, min_per_order, and
	 * max_per_order that the admin form posts for occurrence-scoped tickets.
	 * On the live CJAC site this left chained price tiers with no sale windows,
	 * so every tier displayed and sold simultaneously (event 2546, 2026-07-14).
	 *
	 * @return void
	 */
	public function test_occurrence_ticket_save_persists_sale_window_and_order_fields(): void {
		$saved_ticket = null;

		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array() );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $ticket_type ) use ( &$saved_ticket ) {
				$saved_ticket = $ticket_type;
				return $ticket_type instanceof TicketType;
			} ) );

		$post_data = array(
			'ticketing_enabled'         => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'              => array(
				'occurrence' => array(
					array(
						'name'          => 'Early Bird 2-Day Pass',
						'description'   => 'Cheapest tier, first window only.',
						'price'         => 40,
						'capacity_type' => 'unlimited',
						'min_per_order' => 2,
						'max_per_order' => 8,
						'sale_start'    => '2026-07-15T00:00',
						'sale_end'      => '2026-09-15T23:59',
					),
				),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		$this->assertNotNull( $saved_ticket );
		$this->assertSame( '2026-07-15T00:00', $saved_ticket->sale_start );
		$this->assertSame( '2026-09-15T23:59', $saved_ticket->sale_end );
		$this->assertSame( 'Cheapest tier, first window only.', $saved_ticket->description );
		$this->assertSame( 2, $saved_ticket->min_per_order );
		$this->assertSame( 8, $saved_ticket->max_per_order );
	}

	/**
	 * Test the saved hook announces the tier ids the save produced.
	 *
	 * Pins the announce_save() contract: after an occurrence save, the
	 * nettertech_events_ticket_types_saved action must fire exactly once,
	 * carrying every saved tier id flattened into $submitted_ids and the
	 * scope->row->id correlation in $saved_ids. An extension attaching to a
	 * tier the operator has only just created has no other way to learn its id.
	 *
	 * @return void
	 */
	public function test_announces_saved_tier_ids_on_hook(): void {
		$captured = array();
		Functions\when( 'do_action' )->alias(
			function () use ( &$captured ) {
				$captured[] = func_get_args();
			}
		);

		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array() );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $ticket_type ) {
				$ticket_type->id = 77;
				return $ticket_type instanceof TicketType;
			} ) );

		$post_data = array(
			'ticketing_enabled'         => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'              => array(
				'occurrence' => array(
					array(
						'name'          => 'General Admission',
						'price'         => 25,
						'capacity_type' => 'fixed',
						'capacity'      => 100,
					),
				),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data, 55 );

		$hook_calls = array_values(
			array_filter(
				$captured,
				static fn( array $args ): bool => 'nettertech_events_ticket_types_saved' === $args[0]
			)
		);

		$this->assertCount( 1, $hook_calls, 'Saved hook should fire exactly once.' );
		$this->assertSame( 55, $hook_calls[0][1], 'Hook event_id mismatch.' );
		$this->assertSame( 123, $hook_calls[0][2], 'Hook occurrence_id mismatch.' );
		$this->assertSame( array( 77 ), $hook_calls[0][3], 'Hook must flatten every saved tier id.' );
		$this->assertSame(
			array( 'occurrence' => array( 0 => 77 ) ),
			$hook_calls[0][4],
			'Hook must correlate posted row index to saved tier id.'
		);
	}

	/**
	 * Test saves new ticket type with event_id when provided.
	 *
	 * @return void
	 */
	public function test_saves_new_ticket_type_with_event_id(): void {
		$saved_ticket = null;

		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array() );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $ticket_type ) use ( &$saved_ticket ) {
				$saved_ticket = $ticket_type;
				return $ticket_type instanceof TicketType;
			} ) );

		$post_data = array(
			'ticketing_enabled'        => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'             => array(
				array(
					'name'          => 'VIP Pass',
					'price'         => 50,
					'capacity_type' => 'fixed',
					'capacity'      => 20,
				),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data, 42 );

		$this->assertNotNull( $saved_ticket );
		$this->assertSame( 42, $saved_ticket->event_id );
		$this->assertSame( 123, $saved_ticket->occurrence_id );
		$this->assertSame( 'VIP Pass', $saved_ticket->name );
	}

	/**
	 * Test event_id remains null when not provided (backward compatibility).
	 *
	 * @return void
	 */
	public function test_event_id_null_when_not_provided(): void {
		$saved_ticket = null;

		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array() );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $ticket_type ) use ( &$saved_ticket ) {
				$saved_ticket = $ticket_type;
				return $ticket_type instanceof TicketType;
			} ) );

		$post_data = array(
			'ticketing_enabled'        => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'             => array(
				array(
					'name'          => 'Free Entry',
					'price'         => 0,
					'capacity_type' => 'fixed',
					'capacity'      => 50,
				),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		$this->assertNotNull( $saved_ticket );
		$this->assertNull( $saved_ticket->event_id );
	}

	/**
	 * Test updates existing ticket type.
	 *
	 * @return void
	 */
	public function test_updates_existing_ticket_type(): void {
		$existing_type     = new TicketType();
		$existing_type->id = 456;

		$saved_ticket = null;

		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array( $existing_type ) );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $ticket_type ) use ( &$saved_ticket ) {
				$saved_ticket = $ticket_type;
				return $ticket_type instanceof TicketType;
			} ) );

		$post_data = array(
			'ticketing_enabled'        => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'             => array(
				array(
					'id'            => 456,
					'name'          => 'Updated Name',
					'price'         => 30,
					'capacity_type' => 'fixed',
					'capacity'      => 50,
				),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		$this->assertNotNull( $saved_ticket );
		$this->assertSame( 456, $saved_ticket->id );
		$this->assertSame( 'Updated Name', $saved_ticket->name );
	}

	/**
	 * Test deletes removed ticket types.
	 *
	 * @return void
	 */
	public function test_deletes_removed_ticket_types(): void {
		$existing_type1     = new TicketType();
		$existing_type1->id = 100;

		$existing_type2     = new TicketType();
		$existing_type2->id = 200;

		$deleted_id = null;

		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array( $existing_type1, $existing_type2 ) );

		// Only one type submitted (100), so 200 should be deleted.
		$this->mock_repo
			->shouldReceive( 'save' )
			->once();

		$this->mock_repo
			->shouldReceive( 'delete' )
			->once()
			->with( Mockery::on( function ( $id ) use ( &$deleted_id ) {
				$deleted_id = $id;
				return true;
			} ) );

		$post_data = array(
			'ticketing_enabled'        => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'             => array(
				array(
					'id'            => 100,
					'name'          => 'Keep This One',
					'price'         => 10,
					'capacity_type' => 'fixed',
					'capacity'      => 50,
				),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		// Verify ticket type 200 was deleted (not the one that was submitted).
		$this->assertSame( 200, $deleted_id );
	}

	/**
	 * Test an edit carries the stored row's WooCommerce links forward (NTE-158).
	 *
	 * The admin form never posts wc_product_id / wc_variation_id, so a rebuilt
	 * model would default them to null and sever the tier from its product on
	 * every save. When the model already has an id, build_ticket_type() reloads
	 * the stored row and copies the links back. Kills the NotIdentical mutant
	 * (`null !== $id` → `null === $id`): flipped, an edit (id set) would skip the
	 * reload and drop both links, so pinning them to the stored values fails.
	 *
	 * @return void
	 */
	public function test_edit_carries_wc_links_forward_from_the_stored_row(): void {
		$existing                   = new TicketType();
		$existing->id               = 456;
		$existing->wc_product_id    = 999;
		$existing->wc_variation_id  = 888;

		$existing_type     = new TicketType();
		$existing_type->id = 456;

		$saved_ticket = null;

		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array( $existing_type ) );

		// The builder reloads the stored row by id to recover its WC links.
		$this->mock_repo
			->shouldReceive( 'find' )
			->with( 456 )
			->andReturn( $existing );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->with( Mockery::on( function ( $ticket_type ) use ( &$saved_ticket ) {
				$saved_ticket = $ticket_type;
				return $ticket_type instanceof TicketType;
			} ) );

		$post_data = array(
			'ticketing_enabled'         => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'              => array(
				array(
					'id'            => 456,
					'name'          => 'Updated Name',
					'price'         => 30,
					'capacity_type' => 'fixed',
					'capacity'      => 50,
				),
			),
		);

		$this->saver->save_for_occurrence( 123, $post_data );

		$this->assertNotNull( $saved_ticket );
		$this->assertSame( 999, $saved_ticket->wc_product_id, 'Stored product id must survive an edit.' );
		$this->assertSame( 888, $saved_ticket->wc_variation_id, 'Stored variation id must survive an edit.' );
	}

	// =========================================================================
	// Error Handling Tests
	// =========================================================================

	/**
	 * Test handles save exception gracefully.
	 *
	 * @return void
	 */
	public function test_handles_save_exception_gracefully(): void {
		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->andReturn( array() );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->andThrow( new \RuntimeException( 'Database error' ) );

		// Should not throw, just log.
		$post_data = array(
			'ticketing_enabled'        => '1',
			'occurrence_id_for_tickets' => 123,
			'ticket_types'             => array(
				array(
					'name'          => 'Test',
					'price'         => 10,
					'capacity_type' => 'fixed',
					'capacity'      => 50,
				),
			),
		);

		// Should complete without exception.
		$this->saver->save_for_occurrence( 123, $post_data );
		$this->assertTrue( true );
	}
	/**
	 * An operator-entered SKU reaches product creation from the event editor (NTE-160).
	 *
	 * The metabox AJAX path always carried the SKU; this path dropped it, so the
	 * generated default won and the operator's SKU "reverted". The SKU rides
	 * keyed by the saved tier id — including a tier created in this request,
	 * whose id exists only after save.
	 *
	 * @return void
	 */
	public function test_occurrence_save_carries_admin_sku_to_product_creation(): void {
		$this->mock_repo
			->shouldReceive( 'for_occurrence' )
			->once()
			->with( 123 )
			->andReturn( array() );

		$this->mock_repo
			->shouldReceive( 'save' )
			->once()
			->with(
				Mockery::on(
					function ( $ticket_type ) {
						$ticket_type->id = 91;
						return $ticket_type instanceof TicketType;
					}
				)
			);

		$product_manager = Mockery::mock( \NetterTechEvents\Integrations\WooCommerce\ProductManager::class );
		$product_manager
			->shouldReceive( 'create_products_for_occurrence' )
			->once()
			->with( 123, true, array( 91 => 'FEST-2DAY-EB' ) )
			->andReturn( array( 500 ) );

		$saver = new TicketTypeSaver( $this->mock_repo, $product_manager );

		$saver->save_for_occurrence(
			123,
			array(
				'ticketing_enabled'         => '1',
				'occurrence_id_for_tickets' => 123,
				'ticket_types'              => array(
					'occurrence' => array(
						array(
							'name'          => 'Early Bird',
							'price'         => 40,
							'capacity_type' => 'unlimited',
							'sku'           => 'FEST-2DAY-EB',
						),
					),
				),
			),
			55
		);

		$this->assertTrue( true ); // Mockery verifies the SKU-carrying expectation.
	}

}
