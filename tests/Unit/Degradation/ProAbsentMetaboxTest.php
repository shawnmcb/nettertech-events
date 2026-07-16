<?php
/**
 * EventMetaboxHandler degradation tests — Pro absent.
 *
 * Proves EventMetaboxHandler renders metaboxes correctly when the QR handler
 * object is null (Pro absent): render_qr_code_box() silently no-ops and all
 * base metaboxes continue to work.
 *
 * @package NetterTechEvents\Tests\Unit\Degradation
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Degradation;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\EventMetaboxHandler;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Services\RecurrenceService;

/**
 * Verify EventMetaboxHandler metabox rendering without QR handler.
 *
 * @covers \NetterTechEvents\Admin\EventMetaboxHandler
 */
class ProAbsentMetaboxTest extends \NetterTechEventsTestCase {

	/**
	 * Test event model.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Mock ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Mock capacity service.
	 *
	 * @var CapacityServiceInterface|Mockery\MockInterface
	 */
	private $capacity_service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->event             = new Event();
		$this->event->id         = 1;
		$this->event->title      = 'Test Event';
		$this->event->slug       = 'test-event';
		$this->event->status     = EventStatus::PUBLISHED;
		$this->event->event_type = 'single';
		$this->event->created_at = '2026-01-01 10:00:00';

		$this->occurrence_repo  = Mockery::mock( OccurrenceRepositoryInterface::class );
		$this->ticket_type_repo = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->capacity_service = Mockery::mock( CapacityServiceInterface::class );

		// Common WP function stubs for rendering.
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, $echo = true ) {
				$result = $selected == $current ? " selected='selected'" : '';
				if ( $echo ) {
					echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				return $result;
			}
		);
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url ) => $url . '&_wpnonce=test' );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'date_i18n' )->alias( fn( $format, $ts ) => date( $format, $ts ) );
		Functions\when( 'get_date_from_gmt' )->alias( fn( $string, $format = 'Y-m-d H:i:s' ) => gmdate( $format, strtotime( $string ) ) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
	}

	/**
	 * Build EventMetaboxHandler with null QR handler (Pro absent).
	 *
	 * @return EventMetaboxHandler
	 */
	private function create_handler(): EventMetaboxHandler {
		$mock_recurrence = Mockery::mock( RecurrenceService::class );
		$mock_recurrence->shouldIgnoreMissing();

		$mock_revision_repo = Mockery::mock( RevisionRepositoryInterface::class );
		$mock_revision_repo->shouldIgnoreMissing();

		$mock_layout = Mockery::mock( LayoutService::class );
		$mock_layout->shouldReceive( 'get_components' )->andReturn(
			array(
				'title'       => array( 'label' => 'Title', 'description' => 'Title', 'default_visible' => true ),
				'description' => array( 'label' => 'Description', 'description' => 'Desc', 'default_visible' => true ),
			)
		);
		$mock_layout->shouldReceive( 'get_layout' )->andReturn(
			array(
				'order'      => array( 'title', 'description' ),
				'visibility' => array( 'title' => true, 'description' => true ),
			)
		);
		$mock_layout->shouldIgnoreMissing();

		$mock_category_repo = Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$mock_category_repo->shouldIgnoreMissing();

		// qr_handler defaults to null — no set_qr_handler call.
		return new EventMetaboxHandler(
			$this->event,
			1,
			$this->ticket_type_repo,
			$this->capacity_service,
			$mock_recurrence,
			$mock_revision_repo,
			$mock_layout,
			$mock_category_repo
		);
	}

	// =========================================================================
	// Instantiation
	// =========================================================================

	/**
	 * Test EventMetaboxHandler instantiates without QR handler.
	 *
	 * @return void
	 */
	public function test_instantiates_without_qr_handler(): void {
		$handler = $this->create_handler();

		$this->assertInstanceOf( EventMetaboxHandler::class, $handler );
	}

	// =========================================================================
	// render_qr_code_box() renders for saved events (NTE-041: base owns QR)
	// =========================================================================

	/**
	 * Test render_qr_code_box produces output when the event has id + slug.
	 *
	 * Per NTE-041, QR code generation lives in base. The fixture creates a
	 * saved event (id=1, slug='test-event'), so the metabox renders content
	 * even with Pro absent.
	 *
	 * @return void
	 */
	public function test_render_qr_code_box_renders_for_saved_event(): void {
		$handler = $this->create_handler();

		ob_start();
		$handler->render_qr_code_box();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );
	}

	// =========================================================================
	// Base metabox rendering still works
	// =========================================================================

	/**
	 * Test render_publish_box renders without errors.
	 *
	 * @return void
	 */
	public function test_render_publish_box_renders_without_errors(): void {
		$handler = $this->create_handler();

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Publish', $output );
	}
}
