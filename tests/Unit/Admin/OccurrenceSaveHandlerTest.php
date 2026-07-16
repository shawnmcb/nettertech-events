<?php
/**
 * OccurrenceSaveHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Metaboxes\TicketsMetabox;
use NetterTechEvents\Admin\OccurrenceSaveHandler;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\Organizer;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Models\Tag;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\TicketTypeSaver;

/**
 * Test OccurrenceSaveHandler three-branch persistence.
 */
class OccurrenceSaveHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Occurrence repository mock.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Event repository mock.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Recurrence service mock.
	 *
	 * @var RecurrenceService|Mockery\MockInterface
	 */
	private $recurrence_service;

	/**
	 * Ticket type repository mock.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Category repository mock.
	 *
	 * @var CategoryRepositoryInterface|Mockery\MockInterface
	 */
	private $category_repo;

	/**
	 * Tag repository mock.
	 *
	 * @var TagRepositoryInterface|Mockery\MockInterface
	 */
	private $tag_repo;

	/**
	 * Organizer repository mock.
	 *
	 * @var OrganizerRepositoryInterface|Mockery\MockInterface
	 */
	private $organizer_repo;

	/**
	 * Handler under test.
	 *
	 * @var OccurrenceSaveHandler
	 */
	private OccurrenceSaveHandler $handler;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->occurrence_repo    = Mockery::mock( OccurrenceRepositoryInterface::class );
		$this->event_repo         = Mockery::mock( EventRepositoryInterface::class );
		$this->recurrence_service = Mockery::mock( RecurrenceService::class );
		$this->ticket_type_repo   = Mockery::mock( TicketTypeRepositoryInterface::class );
		// Use shouldIgnoreMissing() so tests that do not exercise junctions
		// are not forced to define expectations for every repo call.
		$this->category_repo  = Mockery::mock( CategoryRepositoryInterface::class )->shouldIgnoreMissing();
		$this->tag_repo       = Mockery::mock( TagRepositoryInterface::class )->shouldIgnoreMissing();
		$this->organizer_repo = Mockery::mock( OrganizerRepositoryInterface::class )->shouldIgnoreMissing();

		$this->handler = new OccurrenceSaveHandler(
			$this->occurrence_repo,
			$this->event_repo,
			$this->recurrence_service,
			$this->ticket_type_repo,
			$this->category_repo,
			$this->tag_repo,
			$this->organizer_repo
		);

		// esc_url_raw is not stubbed by the base bootstrap; extract_fields always calls it.
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	/**
	 * Tear down POST.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Seed a valid nonce in POST.
	 *
	 * @return void
	 */
	private function seed_nonce(): void {
		$_POST['nettertech_events_occurrence_nonce'] = 'valid';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
	}

	/**
	 * Build an occurrence fixture.
	 *
	 * @param int    $id    Occurrence ID.
	 * @param int    $event Event ID.
	 * @param string $start Start datetime.
	 * @return Occurrence
	 */
	private function make_occurrence( int $id, int $event, string $start ): Occurrence {
		$occ                 = new Occurrence();
		$occ->id             = $id;
		$occ->event_id       = $event;
		$occ->start_datetime = $start;
		$occ->end_datetime   = ( new \DateTimeImmutable( $start ) )->modify( '+2 hours' )->format( 'Y-m-d H:i:s' );
		$occ->status         = 'scheduled';
		return $occ;
	}

	/**
	 * Test missing capability dies.
	 *
	 * @return void
	 */
	public function test_process_save_dies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->handler->process_save();
		} catch ( \Exception $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test invalid nonce dies.
	 *
	 * @return void
	 */
	public function test_process_save_dies_with_invalid_nonce(): void {
		$_POST['nettertech_events_occurrence_nonce'] = 'bad';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->handler->process_save();
		} catch ( \Exception $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test missing occurrence redirects with error.
	 *
	 * @return void
	 */
	public function test_process_save_redirects_error_when_occurrence_missing(): void {
		$this->seed_nonce();
		$_POST['occurrence_id'] = '404';

		$this->occurrence_repo->shouldReceive( 'find' )->with( 404 )->andReturn( null );

		$url = $this->handler->process_save();

		$this->assertStringContainsString( 'message=error', $url );
	}

	/**
	 * Test scope=this writes overrides and sets is_override.
	 *
	 * @return void
	 */
	public function test_scope_this_sets_overrides_and_flag(): void {
		$this->seed_nonce();
		$occurrence = $this->make_occurrence( 11, 5, '2026-06-15 19:00:00' );

		$_POST = array_merge(
			$_POST,
			array(
				'occurrence_id'        => '11',
				'event_id'             => '5',
				'scope'                => 'this',
				'start_date'           => '2026-06-15',
				'start_time'           => '20:00',
				'end_date'             => '2026-06-15',
				'end_time'             => '22:00',
				'status'               => 'scheduled',
				'capacity'             => '50',
				'venue_name_override'  => 'Hall B',
				'description_override' => '',
			)
		);

		$this->occurrence_repo->shouldReceive( 'find' )->with( 11 )->andReturn( $occurrence );
		$this->event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $this->make_event( 5 ) );

		$saved = null;
		$this->occurrence_repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( Occurrence $o ) use ( &$saved ) {
				$saved = $o;
				return $o;
			}
		);

		$url = $this->handler->process_save();

		$this->assertStringContainsString( 'message=updated', $url );
		$this->assertInstanceOf( Occurrence::class, $saved );
		$this->assertTrue( $saved->is_override );
		$this->assertSame( '2026-06-15 20:00:00', $saved->start_datetime );
		$this->assertSame( 50, $saved->capacity );
		$this->assertSame( 'Hall B', $saved->venue_name_override );
		$this->assertNull( $saved->description_override );
	}

	/**
	 * Test scope=this persists the per-occurrence featured image override (NTE-159 C).
	 *
	 * The image rides the same is_override row as every other per-date override,
	 * so regeneration protection (NTE-077) covers it — but only if the save
	 * actually lands the posted attachment id on the occurrence.
	 *
	 * @return void
	 */
	public function test_scope_this_persists_featured_image_override(): void {
		$this->seed_nonce();
		$occurrence = $this->make_occurrence( 11, 5, '2026-06-15 19:00:00' );

		$_POST = array_merge(
			$_POST,
			array(
				'occurrence_id'     => '11',
				'event_id'          => '5',
				'scope'             => 'this',
				'start_date'        => '2026-06-15',
				'start_time'        => '19:00',
				'end_date'          => '2026-06-15',
				'end_time'          => '21:00',
				'status'            => 'scheduled',
				'featured_image_id' => '123',
			)
		);

		$this->occurrence_repo->shouldReceive( 'find' )->with( 11 )->andReturn( $occurrence );
		$this->event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $this->make_event( 5 ) );

		$saved = null;
		$this->occurrence_repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( Occurrence $o ) use ( &$saved ) {
				$saved = $o;
				return $o;
			}
		);

		$this->handler->process_save();

		$this->assertInstanceOf( Occurrence::class, $saved );
		$this->assertTrue( $saved->is_override );
		$this->assertSame( 123, $saved->featured_image_id );
	}

	/**
	 * Test scope=this cancel keeps is_override (protected removal).
	 *
	 * @return void
	 */
	public function test_scope_this_cancel_sets_cancelled_and_override(): void {
		$this->seed_nonce();
		$occurrence = $this->make_occurrence( 11, 5, '2026-06-15 19:00:00' );

		$_POST = array_merge(
			$_POST,
			array(
				'occurrence_id' => '11',
				'event_id'      => '5',
				'scope'         => 'this',
				'start_date'    => '2026-06-15',
				'start_time'    => '19:00',
				'end_date'      => '2026-06-15',
				'end_time'      => '21:00',
				'status'        => 'cancelled',
			)
		);

		$this->occurrence_repo->shouldReceive( 'find' )->with( 11 )->andReturn( $occurrence );
		$this->event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $this->make_event( 5 ) );

		$saved = null;
		$this->occurrence_repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( Occurrence $o ) use ( &$saved ) {
				$saved = $o;
				return $o;
			}
		);

		$this->handler->process_save();

		$this->assertSame( 'cancelled', $saved->status );
		$this->assertTrue( $saved->is_override );
	}

	/**
	 * Test scope=all propagates to event and future occurrences.
	 *
	 * @return void
	 */
	public function test_scope_all_propagates_to_event_and_future(): void {
		$this->seed_nonce();
		$occurrence = $this->make_occurrence( 11, 5, '2026-06-15 19:00:00' );
		$event      = $this->make_event( 5 );

		$_POST = array_merge(
			$_POST,
			array(
				'occurrence_id'       => '11',
				'event_id'            => '5',
				'scope'               => 'all',
				'start_date'          => '2026-06-15',
				'start_time'          => '18:30',
				'end_date'            => '2026-06-15',
				'end_time'            => '20:30',
				'status'              => 'scheduled',
				'capacity'            => '80',
				'venue_name_override' => 'Main Stage',
			)
		);

		$this->occurrence_repo->shouldReceive( 'find' )->with( 11 )->andReturn( $occurrence );
		$this->event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $event );

		$saved_event = null;
		$this->event_repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( Event $e ) use ( &$saved_event ) {
				$saved_event = $e;
				return $e;
			}
		);

		$future = array(
			$this->make_occurrence( 11, 5, '2026-06-15 19:00:00' ),
			$this->make_occurrence( 12, 5, '2026-06-22 19:00:00' ),
		);
		$this->occurrence_repo->shouldReceive( 'for_event' )
			->with( 5, array( 'upcoming' => true ) )
			->andReturn( $future );

		$saved_occ = array();
		$this->occurrence_repo->shouldReceive( 'save' )->times( 2 )->andReturnUsing(
			function ( Occurrence $o ) use ( &$saved_occ ) {
				$saved_occ[] = $o;
				return $o;
			}
		);

		$this->handler->process_save();

		$this->assertSame( 'Main Stage', $saved_event->venue_name );
		$this->assertCount( 2, $saved_occ );
		// Each future occurrence keeps its own date but takes the new time + capacity.
		$this->assertSame( '2026-06-15 18:30:00', $saved_occ[0]->start_datetime );
		$this->assertSame( '2026-06-22 18:30:00', $saved_occ[1]->start_datetime );
		$this->assertSame( 80, $saved_occ[1]->capacity );
	}

	/**
	 * Test scope=following splits the series via re-point.
	 *
	 * @return void
	 */
	public function test_scope_following_repoints_future_and_caps_parent(): void {
		$this->seed_nonce();

		$event = $this->make_event( 5 );
		$event->recurrence_rule = 'FREQ=WEEKLY';

		$clicked = $this->make_occurrence( 11, 5, '2026-06-15 19:00:00' );

		$_POST = array_merge(
			$_POST,
			array(
				'occurrence_id' => '11',
				'event_id'      => '5',
				'scope'         => 'following',
				'start_date'    => '2026-06-15',
				'start_time'    => '19:00',
				'end_date'      => '2026-06-15',
				'end_time'      => '21:00',
				'status'        => 'scheduled',
			)
		);

		$this->occurrence_repo->shouldReceive( 'find' )->with( 11 )->andReturn( $clicked );
		$this->event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $event );
		$this->recurrence_service->shouldReceive( 'parse_rule' )
			->with( 'FREQ=WEEKLY' )
			->andReturn( RecurrenceRule::weekly() );

		// Two before cutoff, the clicked one, two after.
		$all = array(
			$this->make_occurrence( 9, 5, '2026-06-01 19:00:00' ),
			$this->make_occurrence( 10, 5, '2026-06-08 19:00:00' ),
			$this->make_occurrence( 11, 5, '2026-06-15 19:00:00' ),
			$this->make_occurrence( 12, 5, '2026-06-22 19:00:00' ),
			$this->make_occurrence( 13, 5, '2026-06-29 19:00:00' ),
		);
		$this->occurrence_repo->shouldReceive( 'for_event' )->with( 5 )->andReturn( $all );
		// New-series re-sequencing fetch: the three future occurrences re-pointed to
		// event 99, returned in start_datetime order.
		$this->occurrence_repo->shouldReceive( 'for_event' )
			->with( 99, Mockery::type( 'array' ) )
			->andReturn( array( $all[2], $all[3], $all[4] ) );

		Functions\when( 'sanitize_title' )->returnArg();
		$this->event_repo->shouldReceive( 'generate_unique_slug' )->andReturn( 'weekly-2' );

		$saved_events = array();
		$this->event_repo->shouldReceive( 'save' )->andReturnUsing(
			function ( Event $e ) use ( &$saved_events ) {
				if ( null === $e->id ) {
					$e->id = 99;
				}
				$saved_events[] = $e;
				return $e;
			}
		);

		$saved_occ = array();
		$this->occurrence_repo->shouldReceive( 'save' )->andReturnUsing(
			function ( Occurrence $o ) use ( &$saved_occ ) {
				$saved_occ[] = $o;
				return $o;
			}
		);

		$this->ticket_type_repo->shouldReceive( 'for_occurrence' )->andReturn( array() );
		$this->ticket_type_repo->shouldReceive( 'get_templates' )->with( 5 )->andReturn( array() );

		$url = $this->handler->process_save();

		$this->assertStringContainsString( 'message=updated', $url );

		// New event created (id 99) carries the recurrence; parent capped with UNTIL.
		$new_event = null;
		$parent    = null;
		foreach ( $saved_events as $e ) {
			if ( 99 === $e->id ) {
				$new_event = $e;
			} elseif ( 5 === $e->id ) {
				$parent = $e;
			}
		}
		$this->assertNotNull( $new_event );
		$this->assertNotNull( $parent );
		$this->assertStringContainsString( 'FREQ=WEEKLY', (string) $new_event->recurrence_rule );
		$this->assertStringContainsString( 'UNTIL=', (string) $parent->recurrence_rule );
		$this->assertStringContainsString( '(through', $parent->title );

		// Clicked + two trailing occurrences re-pointed to event 99.
		$repointed = array_filter( $saved_occ, fn( Occurrence $o ) => 99 === $o->event_id );
		$ids       = array_map( fn( Occurrence $o ) => $o->id, $repointed );
		sort( $ids );
		$this->assertSame( array( 11, 12, 13 ), array_values( array_unique( $ids ) ) );
	}

	/**
	 * Test scope=following converts COUNT to the remaining count on the new series.
	 *
	 * @return void
	 */
	public function test_scope_following_count_remainder(): void {
		$this->seed_nonce();

		$event                  = $this->make_event( 5 );
		$event->recurrence_rule = 'FREQ=WEEKLY;COUNT=10';
		$clicked                = $this->make_occurrence( 11, 5, '2026-06-15 19:00:00' );

		$_POST = array_merge(
			$_POST,
			array(
				'occurrence_id' => '11',
				'event_id'      => '5',
				'scope'         => 'following',
				'start_date'    => '2026-06-15',
				'start_time'    => '19:00',
				'end_date'      => '2026-06-15',
				'end_time'      => '21:00',
				'status'        => 'scheduled',
			)
		);

		$rule        = RecurrenceRule::weekly();
		$rule->count = 10;

		$this->occurrence_repo->shouldReceive( 'find' )->with( 11 )->andReturn( $clicked );
		$this->event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $event );
		$this->recurrence_service->shouldReceive( 'parse_rule' )->andReturn( $rule );

		// Four occurrences before the cutoff.
		$all = array(
			$this->make_occurrence( 7, 5, '2026-05-18 19:00:00' ),
			$this->make_occurrence( 8, 5, '2026-05-25 19:00:00' ),
			$this->make_occurrence( 9, 5, '2026-06-01 19:00:00' ),
			$this->make_occurrence( 10, 5, '2026-06-08 19:00:00' ),
			$this->make_occurrence( 11, 5, '2026-06-15 19:00:00' ),
		);
		$this->occurrence_repo->shouldReceive( 'for_event' )->with( 5 )->andReturn( $all );
		// New-series re-sequencing fetch (clicked occurrence only is re-pointed here).
		$this->occurrence_repo->shouldReceive( 'for_event' )
			->with( 99, Mockery::type( 'array' ) )
			->andReturn( array( $all[4] ) );

		Functions\when( 'sanitize_title' )->returnArg();
		$this->event_repo->shouldReceive( 'generate_unique_slug' )->andReturn( 'weekly-2' );

		$new_event = null;
		$this->event_repo->shouldReceive( 'save' )->andReturnUsing(
			function ( Event $e ) use ( &$new_event ) {
				if ( null === $e->id ) {
					$e->id     = 99;
					$new_event = $e;
				}
				return $e;
			}
		);
		$this->occurrence_repo->shouldReceive( 'save' )->andReturnUsing( fn( Occurrence $o ) => $o );
		$this->ticket_type_repo->shouldReceive( 'for_occurrence' )->andReturn( array() );
		$this->ticket_type_repo->shouldReceive( 'get_templates' )->andReturn( array() );

		$this->handler->process_save();

		// 10 total - 4 before cutoff = 6 remaining on the new series.
		$this->assertStringContainsString( 'COUNT=6', (string) $new_event->recurrence_rule );
	}

	/**
	 * Test scope=following copies category/tag/organizer junctions to the new event.
	 *
	 * @return void
	 */
	public function test_scope_following_copies_junctions_to_new_event(): void {
		$this->seed_nonce();

		$event                  = $this->make_event( 5 );
		$event->recurrence_rule = 'FREQ=WEEKLY';
		$clicked                = $this->make_occurrence( 11, 5, '2026-06-15 19:00:00' );

		$_POST = array_merge(
			$_POST,
			array(
				'occurrence_id' => '11',
				'event_id'      => '5',
				'scope'         => 'following',
				'start_date'    => '2026-06-15',
				'start_time'    => '19:00',
				'end_date'      => '2026-06-15',
				'end_time'      => '21:00',
				'status'        => 'scheduled',
			)
		);

		$this->occurrence_repo->shouldReceive( 'find' )->with( 11 )->andReturn( $clicked );
		$this->event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $event );
		$this->recurrence_service->shouldReceive( 'parse_rule' )
			->with( 'FREQ=WEEKLY' )
			->andReturn( RecurrenceRule::weekly() );

		$all = array(
			$this->make_occurrence( 10, 5, '2026-06-08 19:00:00' ),
			$this->make_occurrence( 11, 5, '2026-06-15 19:00:00' ),
			$this->make_occurrence( 12, 5, '2026-06-22 19:00:00' ),
		);
		$this->occurrence_repo->shouldReceive( 'for_event' )->with( 5 )->andReturn( $all );
		$this->occurrence_repo->shouldReceive( 'for_event' )
			->with( 99, Mockery::type( 'array' ) )
			->andReturn( array( $all[1], $all[2] ) );

		Functions\when( 'sanitize_title' )->returnArg();
		$this->event_repo->shouldReceive( 'generate_unique_slug' )->andReturn( 'weekly-2' );
		$this->event_repo->shouldReceive( 'save' )->andReturnUsing(
			function ( Event $e ) {
				if ( null === $e->id ) {
					$e->id = 99;
				}
				return $e;
			}
		);
		$this->occurrence_repo->shouldReceive( 'save' )->andReturnUsing(
			function ( Occurrence $o ) {
				return $o;
			}
		);
		$this->ticket_type_repo->shouldReceive( 'for_occurrence' )->andReturn( array() );
		$this->ticket_type_repo->shouldReceive( 'get_templates' )->with( 5 )->andReturn( array() );

		// Source event has one category, one tag, one organizer.
		$cat       = new Category();
		$cat->id   = 7;
		$tag       = new Tag();
		$tag->id   = 3;
		$org       = new Organizer();
		$org->id   = 12;

		$synced_categories  = null;
		$synced_tags        = null;
		$synced_organizers  = null;

		$this->category_repo->shouldReceive( 'find_by_event' )
			->with( 5 )
			->andReturn( array( $cat ) );
		$this->category_repo->shouldReceive( 'sync_event_categories' )
			->with( 99, Mockery::type( 'array' ) )
			->andReturnUsing(
				function ( int $event_id, array $ids ) use ( &$synced_categories ) {
					$synced_categories = $ids;
					return true;
				}
			);

		$this->tag_repo->shouldReceive( 'find_by_event' )
			->with( 5 )
			->andReturn( array( $tag ) );
		$this->tag_repo->shouldReceive( 'sync_event_tags' )
			->with( 99, Mockery::type( 'array' ) )
			->andReturnUsing(
				function ( int $event_id, array $ids ) use ( &$synced_tags ) {
					$synced_tags = $ids;
					return true;
				}
			);

		$this->organizer_repo->shouldReceive( 'find_by_event' )
			->with( 5 )
			->andReturn( array( $org ) );
		$this->organizer_repo->shouldReceive( 'sync_event_organizers' )
			->with( 99, Mockery::type( 'array' ) )
			->andReturnUsing(
				function ( int $event_id, array $ids ) use ( &$synced_organizers ) {
					$synced_organizers = $ids;
					return true;
				}
			);

		$url = $this->handler->process_save();

		$this->assertStringContainsString( 'message=updated', $url );
		$this->assertSame( array( 7 ), $synced_categories, 'Category ID 7 must be synced to new event' );
		$this->assertSame( array( 3 ), $synced_tags, 'Tag ID 3 must be synced to new event' );
		$this->assertSame( array( 12 ), $synced_organizers, 'Organizer ID 12 must be synced to new event' );
	}

	/**
	 * Build a handler that owns the given ticket saver (8th constructor arg).
	 *
	 * @param TicketTypeSaver|null $saver Ticket saver (null disables per-date ticketing).
	 * @return OccurrenceSaveHandler
	 */
	private function make_handler_with_saver( ?TicketTypeSaver $saver ): OccurrenceSaveHandler {
		return new OccurrenceSaveHandler(
			$this->occurrence_repo,
			$this->event_repo,
			$this->recurrence_service,
			$this->ticket_type_repo,
			$this->category_repo,
			$this->tag_repo,
			$this->organizer_repo,
			$saver
		);
	}

	/**
	 * Seed a scope=this occurrence save (find + event + save stubbed).
	 *
	 * @return void
	 */
	private function seed_scope_this_save(): void {
		$occurrence = $this->make_occurrence( 11, 5, '2026-06-15 19:00:00' );

		$_POST = array_merge(
			$_POST,
			array(
				'occurrence_id' => '11',
				'event_id'      => '5',
				'scope'         => 'this',
				'start_date'    => '2026-06-15',
				'start_time'    => '19:00',
				'end_date'      => '2026-06-15',
				'end_time'      => '21:00',
				'status'        => 'scheduled',
			)
		);

		$this->occurrence_repo->shouldReceive( 'find' )->with( 11 )->andReturn( $occurrence );
		$this->event_repo->shouldReceive( 'find' )->with( 5 )->andReturn( $this->make_event( 5 ) );
		$this->occurrence_repo->shouldReceive( 'save' )->andReturnUsing(
			function ( Occurrence $o ) {
				return $o;
			}
		);
	}

	/**
	 * The ticket saver fires when a saver is present and the metabox rendered.
	 *
	 * Pins the method call at OccurrenceSaveHandler.php:288 (MethodCallRemoval) and the
	 * `null === $this->ticket_saver` sub-expression at :315 (Identical / LogicalOrNegation /
	 * LogicalOrAllSubExprNegation): with (saver present, metabox rendered) the guard is false
	 * and the save must run.
	 *
	 * @return void
	 */
	public function test_ticket_saver_fires_when_present_and_metabox_rendered(): void {
		$this->seed_nonce();
		$this->seed_scope_this_save();

		$_POST['nte_tickets_metabox_rendered'] = '1';
		$_POST[ TicketsMetabox::NONCE_ACTION ] = 'valid';

		$saver = Mockery::mock( TicketTypeSaver::class );
		$saver->shouldReceive( 'save_for_occurrence' )
			->once()
			->with( 11, Mockery::type( 'array' ), 5 );

		$handler = $this->make_handler_with_saver( $saver );
		$url     = $handler->process_save();

		$this->assertStringContainsString( 'message=updated', $url );
	}

	/**
	 * The ticket saver is skipped (early return) when the metabox never rendered.
	 *
	 * Pins the `|| empty(...)` at :315 (LogicalOr: `||`->`&&` would proceed) and the
	 * `return;` at :316 (ReturnRemoval: without it, control falls through to the save).
	 * The ticket nonce is present and valid, so a removed guard/return WOULD reach the
	 * saver — asserting it never fires kills both mutants.
	 *
	 * @return void
	 */
	public function test_ticket_saver_skipped_when_metabox_not_rendered(): void {
		$this->seed_nonce();
		$this->seed_scope_this_save();

		// No nte_tickets_metabox_rendered key: guard sub-expression B is true.
		$_POST[ TicketsMetabox::NONCE_ACTION ] = 'valid';

		$saver = Mockery::mock( TicketTypeSaver::class );
		$saver->shouldReceive( 'save_for_occurrence' )->never();

		$handler = $this->make_handler_with_saver( $saver );
		$url     = $handler->process_save();

		$this->assertStringContainsString( 'message=updated', $url );
	}

	/**
	 * A null saver short-circuits even when the metabox rendered.
	 *
	 * Covers the (A true, B false) guard combination at :315: `null === $this->ticket_saver`
	 * is true, so the method returns before dereferencing the null saver. Completes cleanly.
	 *
	 * @return void
	 */
	public function test_null_saver_short_circuits_even_when_metabox_rendered(): void {
		$this->seed_nonce();
		$this->seed_scope_this_save();

		$_POST['nte_tickets_metabox_rendered'] = '1';
		$_POST[ TicketsMetabox::NONCE_ACTION ] = 'valid';

		$handler = $this->make_handler_with_saver( null );
		$url     = $handler->process_save();

		$this->assertStringContainsString( 'message=updated', $url );
	}

	/**
	 * A ValidationException from the saver is caught and surfaced as an error redirect.
	 *
	 * Pins the ValidationException catch at :289 (CatchBlockRemoval): removing it lets the
	 * exception escape process_save. Asserting the stored error message + error redirect
	 * proves the catch ran.
	 *
	 * @return void
	 */
	public function test_validation_exception_from_saver_redirects_with_error(): void {
		$this->seed_nonce();
		$this->seed_scope_this_save();

		$_POST['nte_tickets_metabox_rendered'] = '1';
		$_POST[ TicketsMetabox::NONCE_ACTION ] = 'valid';

		$stored = null;
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$saver = Mockery::mock( TicketTypeSaver::class );
		$saver->shouldReceive( 'save_for_occurrence' )
			->once()
			->andThrow( ValidationException::fromErrors( array( 'Bad ticket data' ) ) );

		$handler = $this->make_handler_with_saver( $saver );
		$url     = $handler->process_save();

		$this->assertStringContainsString( 'message=error', $url );
		$this->assertSame( 'Bad ticket data', $stored );
	}

	/**
	 * A RuntimeException from the saver is caught and surfaced as an error redirect.
	 *
	 * Pins the \RuntimeException catch at :292 (the second CatchBlockRemoval at :274):
	 * removing it lets the exception escape. Asserting the stored message + error redirect
	 * proves the catch ran.
	 *
	 * @return void
	 */
	public function test_runtime_exception_from_saver_redirects_with_error(): void {
		$this->seed_nonce();
		$this->seed_scope_this_save();

		$_POST['nte_tickets_metabox_rendered'] = '1';
		$_POST[ TicketsMetabox::NONCE_ACTION ] = 'valid';

		$stored = null;
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$saver = Mockery::mock( TicketTypeSaver::class );
		$saver->shouldReceive( 'save_for_occurrence' )
			->once()
			->andThrow( new \RuntimeException( 'Saver blew up' ) );

		$handler = $this->make_handler_with_saver( $saver );
		$url     = $handler->process_save();

		$this->assertStringContainsString( 'message=error', $url );
		$this->assertSame( 'Saver blew up', $stored );
	}

	/**
	 * Build an event fixture.
	 *
	 * @param int $id Event ID.
	 * @return Event
	 */
	private function make_event( int $id ): Event {
		$event             = new Event();
		$event->id         = $id;
		$event->title      = 'Weekly';
		$event->slug       = 'weekly';
		$event->event_type = 'recurring';
		return $event;
	}
}
