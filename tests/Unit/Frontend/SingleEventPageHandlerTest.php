<?php
/**
 * SingleEventPageHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Frontend\SingleEventPageHandler;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\LayoutService;

/**
 * Test SingleEventPageHandler class.
 *
 * Covers resolve() branches: event-level vs occurrence-level view, fall-back
 * upcoming occurrence pickup, price display variants, and layout fallback chain.
 *
 * @coversDefaultClass \NetterTechEvents\Frontend\SingleEventPageHandler
 */
class SingleEventPageHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Mock ticket-type repository.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Mock layout service.
	 *
	 * @var LayoutService|Mockery\MockInterface
	 */
	private $layout_service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wc_price' )->alias( static fn( $p ) => '$' . number_format( (float) $p, 2 ) );

		$this->occurrence_repo  = Mockery::mock( OccurrenceRepositoryInterface::class );
		$this->ticket_type_repo = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->layout_service   = Mockery::mock( LayoutService::class );

		$this->layout_service->shouldReceive( 'get_preview_config' )->andReturn( array() )->byDefault();
		$this->layout_service->shouldReceive( 'get_layout' )->andReturn( array() )->byDefault();
		$this->layout_service->shouldReceive( 'get_visible_components' )->andReturn( array( 'title' ) )->byDefault();
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Build a handler instance.
	 *
	 * @return SingleEventPageHandler
	 */
	private function build_handler(): SingleEventPageHandler {
		return new SingleEventPageHandler( $this->occurrence_repo, $this->ticket_type_repo, $this->layout_service );
	}

	/**
	 * Test event-level resolve fetches upcoming occurrences.
	 *
	 * @return void
	 */
	public function test_resolve_for_event_level_fetches_upcoming(): void {
		$event                    = new Event();
		$event->id                = 7;
		$event->title             = 'Concert';
		$event->featured_image_id = 99;

		$this->occurrence_repo->shouldReceive( 'get_upcoming_by_event' )
			->with( 7, 10 )->andReturn( array() );

		$result = $this->build_handler()->resolve( $event );

		$this->assertSame( $event, $result['event'] );
		$this->assertNull( $result['occurrence'] );
		$this->assertSame( 99, $result['featured_image_id'] );
		$this->assertSame( 'Concert', $result['display_title'] );
		$this->assertSame( '', $result['date_subtitle'] );
		$this->assertSame( 0, $result['sibling_count'] );
	}

	/**
	 * Test occurrence-level resolve includes siblings.
	 *
	 * @return void
	 */
	public function test_resolve_for_occurrence_level_includes_siblings(): void {
		$event = new Event();
		$event->id    = 7;
		$event->title = 'Concert';

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->id             = 22;
		$occurrence->all_day        = true;
		$occurrence->shouldReceive( 'get_featured_image_id' )->andReturn( 50 );
		$occurrence->shouldReceive( 'get_title' )->andReturn( 'Special Show' );
		$occurrence->shouldReceive( 'get_start_date' )->andReturn( 'Monday, January 1, 2026' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '' );
		$occurrence->shouldReceive( 'is_cancelled' )->andReturn( false );

		$this->occurrence_repo->shouldReceive( 'get_siblings' )->with( 22 )->andReturn(
			array(
				'all'  => array(
					(object) array( 'id' => 22 ),
					(object) array( 'id' => 23 ),
				),
				'prev' => null,
				'next' => null,
			)
		);
		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array() );

		$result = $this->build_handler()->resolve( $event, $occurrence );

		$this->assertSame( $occurrence, $result['occurrence'] );
		$this->assertSame( 'Special Show', $result['display_title'] );
		$this->assertSame( 50, $result['featured_image_id'] );
		$this->assertSame( 2, $result['sibling_count'] );
		$this->assertSame( 'Monday, January 1, 2026', $result['date_subtitle'] );
	}

	/**
	 * Test price display: Free when only $0 tickets.
	 *
	 * @return void
	 */
	public function test_resolve_price_display_free_when_only_zero_tickets(): void {
		$event = new Event();
		$event->id = 7;

		$occurrence              = Mockery::mock( Occurrence::class );
		$occurrence->id          = 22;
		$occurrence->all_day     = true;
		$occurrence->shouldReceive( 'get_featured_image_id' )->andReturn( 0 );
		$occurrence->shouldReceive( 'get_title' )->andReturn( 'X' );
		$occurrence->shouldReceive( 'get_start_date' )->andReturn( 'Date' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '' );
		$occurrence->shouldReceive( 'is_cancelled' )->andReturn( false );

		$this->occurrence_repo->shouldReceive( 'get_siblings' )->andReturn(
			array(
				'all'  => array(),
				'prev' => null,
				'next' => null,
			)
		);
		$tt1        = new \stdClass();
		$tt1->price = 0.0;
		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array( $tt1 ) );

		$result = $this->build_handler()->resolve( $event, $occurrence );

		$this->assertSame( 'Free', $result['price_display'] );
	}

	/**
	 * Test price display: single ticket type returns formatted price.
	 *
	 * @return void
	 */
	public function test_resolve_price_display_single_price(): void {
		$event = new Event();
		$event->id = 7;

		$occurrence              = Mockery::mock( Occurrence::class );
		$occurrence->id          = 22;
		$occurrence->all_day     = true;
		$occurrence->shouldReceive( 'get_featured_image_id' )->andReturn( 0 );
		$occurrence->shouldReceive( 'get_title' )->andReturn( 'X' );
		$occurrence->shouldReceive( 'get_start_date' )->andReturn( 'D' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '' );
		$occurrence->shouldReceive( 'is_cancelled' )->andReturn( false );

		$this->occurrence_repo->shouldReceive( 'get_siblings' )->andReturn(
			array(
				'all'  => array(),
				'prev' => null,
				'next' => null,
			)
		);
		$tt        = new \stdClass();
		$tt->price = 25.0;
		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array( $tt ) );

		$result = $this->build_handler()->resolve( $event, $occurrence );
		$this->assertSame( '$25.00', $result['price_display'] );
	}

	/**
	 * Test price display: price range when min < max.
	 *
	 * @return void
	 */
	public function test_resolve_price_display_range(): void {
		$event = new Event();
		$event->id = 7;

		$occurrence              = Mockery::mock( Occurrence::class );
		$occurrence->id          = 22;
		$occurrence->all_day     = true;
		$occurrence->shouldReceive( 'get_featured_image_id' )->andReturn( 0 );
		$occurrence->shouldReceive( 'get_title' )->andReturn( 'X' );
		$occurrence->shouldReceive( 'get_start_date' )->andReturn( 'D' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '' );
		$occurrence->shouldReceive( 'is_cancelled' )->andReturn( false );

		$this->occurrence_repo->shouldReceive( 'get_siblings' )->andReturn(
			array(
				'all'  => array(),
				'prev' => null,
				'next' => null,
			)
		);
		$cheap        = new \stdClass();
		$cheap->price = 10.0;
		$expensive    = new \stdClass();
		$expensive->price = 50.0;

		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array( $cheap, $expensive ) );

		$result = $this->build_handler()->resolve( $event, $occurrence );
		$this->assertStringContainsString( '$10.00', $result['price_display'] );
		$this->assertStringContainsString( '$50.00', $result['price_display'] );
	}

	/**
	 * Test price display: free-up-to-max when min is zero and max positive.
	 *
	 * @return void
	 */
	public function test_resolve_price_display_free_to_max(): void {
		$event = new Event();
		$event->id = 7;

		$occurrence              = Mockery::mock( Occurrence::class );
		$occurrence->id          = 22;
		$occurrence->all_day     = true;
		$occurrence->shouldReceive( 'get_featured_image_id' )->andReturn( 0 );
		$occurrence->shouldReceive( 'get_title' )->andReturn( 'X' );
		$occurrence->shouldReceive( 'get_start_date' )->andReturn( 'D' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '' );
		$occurrence->shouldReceive( 'is_cancelled' )->andReturn( false );

		$this->occurrence_repo->shouldReceive( 'get_siblings' )->andReturn(
			array(
				'all'  => array(),
				'prev' => null,
				'next' => null,
			)
		);
		$free        = new \stdClass();
		$free->price = 0.0;
		$paid        = new \stdClass();
		$paid->price = 25.0;

		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array( $free, $paid ) );

		$result = $this->build_handler()->resolve( $event, $occurrence );
		$this->assertStringContainsString( 'Free', $result['price_display'] );
		$this->assertStringContainsString( '$25.00', $result['price_display'] );
	}

	/**
	 * Test resolve uses upcoming occurrence as target when event-level view has occurrences.
	 *
	 * @return void
	 */
	public function test_resolve_event_level_uses_first_upcoming_as_target(): void {
		$event = new Event();
		$event->id = 7;

		$first              = Mockery::mock( Occurrence::class );
		$first->id          = 100;
		$first->all_day     = true;
		$first->shouldReceive( 'get_start_date' )->andReturn( 'Tuesday' );
		$first->shouldReceive( 'get_formatted_time' )->andReturn( '' );

		$this->occurrence_repo->shouldReceive( 'get_upcoming_by_event' )->andReturn( array( $first ) );
		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array() );

		$result = $this->build_handler()->resolve( $event );

		$this->assertSame( $first, $result['target_occ'] );
		$this->assertSame( 'Tuesday', $result['date_subtitle'] );
	}

	/**
	 * Test resolve includes time suffix when occurrence is not all-day.
	 *
	 * Exercises format_occurrence_date's non-all-day branch.
	 *
	 * @return void
	 */
	public function test_resolve_includes_time_when_not_all_day(): void {
		$event = new Event();
		$event->id = 7;

		$occ                  = Mockery::mock( Occurrence::class );
		$occ->id              = 22;
		$occ->all_day         = false; // critical: triggers time-suffix branch.
		$occ->shouldReceive( 'get_featured_image_id' )->andReturn( 0 );
		$occ->shouldReceive( 'get_title' )->andReturn( 'X' );
		$occ->shouldReceive( 'get_start_date' )->andReturn( 'Monday, January 1' );
		$occ->shouldReceive( 'get_formatted_time' )->andReturn( '7:00 PM' );
		$occ->shouldReceive( 'is_cancelled' )->andReturn( false );

		$this->occurrence_repo->shouldReceive( 'get_siblings' )->andReturn(
			array(
				'all'  => array(),
				'prev' => null,
				'next' => null,
			)
		);
		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array() );

		$result = $this->build_handler()->resolve( $event, $occ );

		$this->assertStringContainsString( 'Monday, January 1', $result['date_subtitle'] );
		$this->assertStringContainsString( '7:00 PM', $result['date_subtitle'] );
	}

	/**
	 * Test resolve exposes is_cancelled true for a cancelled occurrence view.
	 *
	 * @return void
	 */
	public function test_resolve_exposes_is_cancelled_for_cancelled_occurrence(): void {
		$event     = new Event();
		$event->id = 7;

		$occurrence          = Mockery::mock( Occurrence::class );
		$occurrence->id      = 22;
		$occurrence->all_day = true;
		$occurrence->shouldReceive( 'get_featured_image_id' )->andReturn( 0 );
		$occurrence->shouldReceive( 'get_title' )->andReturn( 'X' );
		$occurrence->shouldReceive( 'get_start_date' )->andReturn( 'D' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '' );
		$occurrence->shouldReceive( 'is_cancelled' )->andReturn( true );

		$this->occurrence_repo->shouldReceive( 'get_siblings' )->andReturn(
			array(
				'all'  => array(),
				'prev' => null,
				'next' => null,
			)
		);
		$this->ticket_type_repo->shouldReceive( 'get_on_sale_for_occurrence' )->andReturn( array() );

		$result = $this->build_handler()->resolve( $event, $occurrence );

		$this->assertTrue( $result['is_cancelled'] );
	}

	/**
	 * Test resolve never flags is_cancelled for an event-level (no occurrence) view.
	 *
	 * @return void
	 */
	public function test_resolve_event_level_is_not_cancelled(): void {
		$event     = new Event();
		$event->id = 7;

		$this->occurrence_repo->shouldReceive( 'get_upcoming_by_event' )->andReturn( array() );

		$result = $this->build_handler()->resolve( $event );

		$this->assertFalse( $result['is_cancelled'] );
	}
}
