<?php
/**
 * EventMetaboxHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\EventMetaboxHandler;
use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\QRGeneratorPage;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Services\RecurrenceService;

/**
 * Test EventMetaboxHandler metabox rendering.
 *
 * Tests all render methods for correct HTML output, escaping, and conditional logic.
 */
class EventMetaboxHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_occurrence_repo;

	/**
	 * Mock ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $mock_ticket_type_repo;

	/**
	 * Mock capacity service.
	 *
	 * @var CapacityServiceInterface|Mockery\MockInterface
	 */
	private $mock_capacity_service;

	/**
	 * Test event model.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create mock dependencies.
		$this->mock_occurrence_repo  = Mockery::mock( OccurrenceRepositoryInterface::class );
		$this->mock_ticket_type_repo = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->mock_capacity_service = Mockery::mock( CapacityServiceInterface::class );

		// Create test event.
		$this->event             = new Event();
		$this->event->id         = 1;
		$this->event->title      = 'Test Event';
		$this->event->slug       = 'test-event';
		$this->event->status     = EventStatus::PUBLISHED;
		$this->event->event_type = 'single';
		$this->event->created_at = '2026-01-15 10:00:00';

		// Mock common WordPress functions.
		$this->mock_common_wp_functions();
	}

	/**
	 * Mock common WordPress functions used in rendering.
	 *
	 * @return void
	 */
	private function mock_common_wp_functions(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->returnArg();
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, $echo = true ) {
				$result = $selected == $current ? " selected='selected'" : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'checked' )->alias(
			function ( $checked, $current = true, $echo = true ) {
				$result = $checked == $current ? " checked='checked'" : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url ) => $url . '&_wpnonce=test123' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce_123' );
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( 'date_format' === $option ) {
					return 'Y-m-d';
				}
				if ( 'time_format' === $option ) {
					return 'H:i';
				}
				if ( 'nettertech_events_settings' === $option ) {
					return array(
						'show_end_time_by_default' => true,
						'require_end_time'         => false,
					);
				}
				return $default;
			}
		);
		Functions\when( 'date_i18n' )->alias( fn( $format, $timestamp ) => date( $format, $timestamp ) );
		Functions\when( 'get_date_from_gmt' )->alias( fn( $string, $format = 'Y-m-d H:i:s' ) => gmdate( $format, strtotime( $string ) ) );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/image.jpg' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'wp_add_inline_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		Functions\when( 'get_theme_mod' )->justReturn( '' );
		Functions\when( 'add_query_arg' )->returnArg();

		// Define constants if not defined.
		if ( ! defined( 'NETTERTECH_EVENTS_PLUGIN_URL' ) ) {
			define( 'NETTERTECH_EVENTS_PLUGIN_URL', 'https://example.com/wp-content/plugins/nettertech-events/' );
		}
		if ( ! defined( 'NETTERTECH_EVENTS_VERSION' ) ) {
			define( 'NETTERTECH_EVENTS_VERSION', '1.0.0' );
		}
	}

	/**
	 * Create handler instance with dependencies.
	 *
	 * @param Event                                                              $event         Event model.
	 * @param int                                                                $event_id      Event ID (0 for new).
	 * @param \NetterTechEvents\Contracts\CategoryRepositoryInterface|null       $category_repo Optional category repo mock.
	 * @return EventMetaboxHandler
	 */
	private function create_handler( Event $event, int $event_id = 0, ?\NetterTechEvents\Contracts\CategoryRepositoryInterface $category_repo = null ): EventMetaboxHandler {
		$mock_recurrence = \Mockery::mock( \NetterTechEvents\Services\RecurrenceService::class );
		$mock_recurrence->shouldIgnoreMissing();
		$mock_revision_repo = \Mockery::mock( \NetterTechEvents\Repositories\RevisionRepository::class );
		$mock_revision_repo->shouldIgnoreMissing();

		$mock_layout = \Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout->shouldReceive( 'get_components' )->andReturn( array(
			'title'       => array( 'label' => 'Title', 'description' => 'Title', 'default_visible' => true ),
			'description' => array( 'label' => 'Description', 'description' => 'Desc', 'default_visible' => true ),
		) );
		$mock_layout->shouldReceive( 'get_layout' )->andReturn( array(
			'order'      => array( 'title', 'description' ),
			'visibility' => array( 'title' => true, 'description' => true ),
		) );
		$mock_layout->shouldIgnoreMissing();

		if ( null === $category_repo ) {
			$category_repo = \Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
			$category_repo->shouldIgnoreMissing();
		}

		return new EventMetaboxHandler(
			$event,
			$event_id,
			$this->mock_ticket_type_repo,
			$this->mock_capacity_service,
			$mock_recurrence,
			$mock_revision_repo,
			$mock_layout,
			$category_repo
		);
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor sets dependencies correctly.
	 *
	 * @return void
	 */
	public function test_constructor_sets_dependencies(): void {
		$handler = $this->create_handler( $this->event, 1 );

		$this->assertInstanceOf( EventMetaboxHandler::class, $handler );
	}

	/**
	 * Test constructor works with new event (id=0).
	 *
	 * @return void
	 */
	public function test_constructor_works_with_new_event(): void {
		$new_event = new Event();
		$handler   = $this->create_handler( $new_event, 0 );

		$this->assertInstanceOf( EventMetaboxHandler::class, $handler );
	}

	// =========================================================================
	// render_publish_box() Tests
	// =========================================================================

	/**
	 * Test render_publish_box outputs postbox structure.
	 *
	 * @return void
	 */
	public function test_render_publish_box_outputs_postbox(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="postbox"', $output );
		$this->assertStringContainsString( 'Publish', $output );
	}

	/**
	 * Test render_publish_box shows status select.
	 *
	 * @return void
	 */
	public function test_render_publish_box_shows_status_select(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="event_status"', $output );
		$this->assertStringContainsString( 'value="draft"', $output );
		$this->assertStringContainsString( 'value="published"', $output );
		$this->assertStringContainsString( 'value="cancelled"', $output );
		$this->assertStringContainsString( 'value="postponed"', $output );
	}

	/**
	 * Test render_publish_box shows event type select.
	 *
	 * @return void
	 */
	public function test_render_publish_box_shows_event_type_select(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="event_type"', $output );
		$this->assertStringContainsString( 'value="single"', $output );
		$this->assertStringContainsString( 'value="recurring"', $output );
	}

	/**
	 * Test render_publish_box shows created date for existing events.
	 *
	 * @return void
	 */
	public function test_render_publish_box_shows_created_date(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Created:', $output );
	}

	/**
	 * Test render_publish_box hides created date for new events.
	 *
	 * @return void
	 */
	public function test_render_publish_box_hides_created_for_new_events(): void {
		$new_event     = new Event();
		$new_event->id = 0;
		$handler       = $this->create_handler( $new_event, 0 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Created:', $output );
	}

	/**
	 * Test render_publish_box shows preview button for existing events.
	 *
	 * @return void
	 */
	public function test_render_publish_box_shows_preview_for_existing(): void {
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'View Event', $output );
	}

	/**
	 * Test render_publish_box shows delete link for existing events.
	 *
	 * @return void
	 */
	public function test_render_publish_box_shows_delete_for_existing(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Delete', $output );
		$this->assertStringContainsString( 'action=delete', $output );
	}

	/**
	 * Test render_publish_box hides delete for new events.
	 *
	 * @return void
	 */
	public function test_render_publish_box_hides_delete_for_new(): void {
		$new_event     = new Event();
		$new_event->id = 0;
		$handler       = $this->create_handler( $new_event, 0 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'action=delete', $output );
	}

	/**
	 * Test render_publish_box shows Update button for existing events.
	 *
	 * @return void
	 */
	public function test_render_publish_box_shows_update_for_existing(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="Update"', $output );
	}

	/**
	 * Test render_publish_box shows Save button for new events.
	 *
	 * @return void
	 */
	public function test_render_publish_box_shows_save_for_new(): void {
		$new_event     = new Event();
		$new_event->id = 0;
		$handler       = $this->create_handler( $new_event, 0 );

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="Save"', $output );
	}

	// =========================================================================
	// render_date_time_box() Tests
	// =========================================================================

	/**
	 * Test render_date_time_box outputs postbox structure.
	 *
	 * @return void
	 */
	public function test_render_date_time_box_outputs_postbox(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="postbox"', $output );
		$this->assertStringContainsString( 'Date & Time', $output );
	}

	/**
	 * Test render_date_time_box shows all-day checkbox.
	 *
	 * @return void
	 */
	public function test_render_date_time_box_shows_all_day(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="all_day"', $output );
		$this->assertStringContainsString( 'All-day event', $output );
	}

	/**
	 * Test render_date_time_box shows start date/time inputs.
	 *
	 * @return void
	 */
	public function test_render_date_time_box_shows_start_inputs(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="start_date"', $output );
		$this->assertStringContainsString( 'type="date"', $output );
		$this->assertStringContainsString( 'name="start_time"', $output );
		$this->assertStringContainsString( 'type="time"', $output );
	}

	/**
	 * Test render_date_time_box shows end date/time inputs.
	 *
	 * @return void
	 */
	public function test_render_date_time_box_shows_end_inputs(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="end_date"', $output );
		$this->assertStringContainsString( 'name="end_time"', $output );
	}

	/**
	 * Test render_date_time_box shows capacity input.
	 *
	 * @return void
	 */
	public function test_render_date_time_box_shows_capacity(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="occurrence_capacity"', $output );
		$this->assertStringContainsString( 'Capacity:', $output );
	}

	/**
	 * Test render_date_time_box populates from occurrence.
	 *
	 * @return void
	 */
	public function test_render_date_time_box_populates_from_occurrence(): void {
		$occurrence                 = new Occurrence();
		$occurrence->id             = 1;
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-02-15 14:00:00';
		$occurrence->end_datetime   = '2026-02-15 16:00:00';
		$occurrence->all_day        = false;
		$occurrence->capacity       = 100;

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( $occurrence, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="2026-02-15"', $output );
		$this->assertStringContainsString( 'value="14:00"', $output );
		$this->assertStringContainsString( 'value="100"', $output );
	}

	/**
	 * Test render_date_time_box shows empty fields for new event (no defaults).
	 *
	 * @return void
	 */
	public function test_render_date_time_box_uses_empty_fields_for_new(): void {
		$handler = $this->create_handler( $this->event, 0 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		// Time fields must be empty — no default times that could mask load failures.
		$this->assertStringNotContainsString( 'value="12:00"', $output );
		$this->assertStringNotContainsString( 'value="13:00"', $output );
		$this->assertStringContainsString( 'name="start_time"', $output );
		$this->assertStringContainsString( 'name="end_time"', $output );
	}

	/**
	 * Test render_date_time_box does not print executable inline script.
	 *
	 * @return void
	 */
	public function test_render_date_time_box_does_not_print_inline_script(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( 'nte-end-time-toggle', $output );
	}

	// =========================================================================
	// render_venue_box() Tests
	// =========================================================================

	/**
	 * Test render_venue_box outputs postbox structure.
	 *
	 * @return void
	 */
	public function test_render_venue_box_outputs_postbox(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_venue_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="postbox"', $output );
		$this->assertStringContainsString( 'Venue', $output );
	}

	/**
	 * Test render_venue_box shows venue name input.
	 *
	 * @return void
	 */
	public function test_render_venue_box_shows_name_input(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_venue_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="venue_name"', $output );
		$this->assertStringContainsString( 'Venue Name:', $output );
	}

	/**
	 * Test render_venue_box shows address textarea.
	 *
	 * @return void
	 */
	public function test_render_venue_box_shows_address_textarea(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_venue_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="venue_address"', $output );
		$this->assertStringContainsString( 'Address:', $output );
		$this->assertStringContainsString( '<textarea', $output );
	}

	/**
	 * Test render_venue_box populates from event.
	 *
	 * @return void
	 */
	public function test_render_venue_box_populates_from_event(): void {
		$this->event->venue_name    = 'Celtic Junction';
		$this->event->venue_address = '836 Prior Ave N';

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_venue_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Celtic Junction', $output );
		$this->assertStringContainsString( '836 Prior Ave N', $output );
	}

	// =========================================================================
	// render_featured_image_box() Tests
	// =========================================================================

	/**
	 * Test render_featured_image_box outputs postbox structure.
	 *
	 * @return void
	 */
	public function test_render_featured_image_box_outputs_postbox(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_featured_image_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="postbox"', $output );
		$this->assertStringContainsString( 'Featured Image', $output );
	}

	/**
	 * Test render_featured_image_box shows hidden input.
	 *
	 * @return void
	 */
	public function test_render_featured_image_box_shows_hidden_input(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_featured_image_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="featured_image_id"', $output );
		$this->assertStringContainsString( 'type="hidden"', $output );
	}

	/**
	 * Test render_featured_image_box shows select button.
	 *
	 * @return void
	 */
	public function test_render_featured_image_box_shows_select_button(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_featured_image_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="select-featured-image"', $output );
	}

	/**
	 * Test render_featured_image_box shows image when set.
	 *
	 * @return void
	 */
	public function test_render_featured_image_box_shows_image(): void {
		$this->event->featured_image_id = 123;

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_featured_image_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="123"', $output );
		$this->assertStringContainsString( 'Change Image', $output );
	}

	/**
	 * Test render_featured_image_box does not print executable inline script.
	 *
	 * @return void
	 */
	public function test_render_featured_image_box_does_not_print_inline_script(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_featured_image_box();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( 'nettertech-events-featured-image', $output );
	}

	// =========================================================================
	// render_layout_box() Tests
	// =========================================================================

	/**
	 * Test render_layout_box outputs postbox structure.
	 *
	 * @return void
	 */
	public function test_render_layout_box_outputs_postbox(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_layout_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="postbox"', $output );
		$this->assertStringContainsString( 'Page Layout', $output );
	}

	/**
	 * Test render_layout_box shows mode radio buttons.
	 *
	 * @return void
	 */
	public function test_render_layout_box_shows_mode_radios(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_layout_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="nettertech_events_layout_mode"', $output );
		$this->assertStringContainsString( 'value="global"', $output );
		$this->assertStringContainsString( 'value="custom"', $output );
	}

	/**
	 * Test render_layout_box shows layout order input.
	 *
	 * @return void
	 */
	public function test_render_layout_box_shows_order_input(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_layout_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="event_layout_order"', $output );
		$this->assertStringContainsString( 'name="event_layout_visibility"', $output );
	}

	/**
	 * Test render_layout_box includes layout editor elements.
	 *
	 * @return void
	 */
	public function test_render_layout_box_includes_editor_elements(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_layout_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-layout-editor', $output );
		$this->assertStringContainsString( 'nte-layout-editor__list', $output );
	}

	// =========================================================================
	// render_layout_preview_box() Tests
	// =========================================================================

	/**
	 * Test render_layout_preview_box shows nothing for new events.
	 *
	 * @return void
	 */
	public function test_render_layout_preview_box_empty_for_new(): void {
		$new_event     = new Event();
		$new_event->id = 0;
		$handler       = $this->create_handler( $new_event, 0 );

		ob_start();
		$handler->render_layout_preview_box();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_layout_preview_box shows nothing without slug.
	 *
	 * @return void
	 */
	public function test_render_layout_preview_box_empty_without_slug(): void {
		$this->event->slug = '';
		$handler           = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_layout_preview_box();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_layout_preview_box shows iframe for existing event.
	 *
	 * @return void
	 */
	public function test_render_layout_preview_box_shows_iframe(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_layout_preview_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<iframe', $output );
		$this->assertStringContainsString( 'Layout Preview', $output );
		$this->assertStringContainsString( 'nte-layout-preview-frame', $output );
	}

	/**
	 * Test render_layout_preview_box shows refresh button.
	 *
	 * @return void
	 */
	public function test_render_layout_preview_box_shows_refresh(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_layout_preview_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-layout-refresh-preview', $output );
		$this->assertStringContainsString( 'Refresh Preview', $output );
	}

	// =========================================================================
	// render_qr_code_box() Tests
	// =========================================================================

	/**
	 * Test render_qr_code_box shows nothing for new events.
	 *
	 * @return void
	 */
	public function test_render_qr_code_box_empty_for_new(): void {
		$new_event     = new Event();
		$new_event->id = 0;
		$handler       = $this->create_handler( $new_event, 0 );

		ob_start();
		$handler->render_qr_code_box();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_qr_code_box outputs content for saved events (NTE-041: base owns QR).
	 *
	 * @return void
	 */
	public function test_render_qr_code_box_outputs_for_saved_event(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_qr_code_box();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );
	}

	// =========================================================================
	// render_checkin_settings_box() Tests
	// =========================================================================

	/**
	 * Test render_checkin_settings_box shows nothing for new events.
	 *
	 * @return void
	 */
	public function test_render_checkin_settings_box_empty_for_new(): void {
		$new_event     = new Event();
		$new_event->id = 0;
		$handler       = $this->create_handler( $new_event, 0 );

		ob_start();
		$handler->render_checkin_settings_box();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_checkin_settings_box shows postbox for existing event.
	 *
	 * @return void
	 */
	public function test_render_checkin_settings_box_shows_postbox(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_checkin_settings_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="postbox"', $output );
		$this->assertStringContainsString( 'Check-In Settings', $output );
	}

	/**
	 * Test render_checkin_settings_box shows email textarea.
	 *
	 * @return void
	 */
	public function test_render_checkin_settings_box_shows_emails(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_checkin_settings_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="checkin_emails"', $output );
		$this->assertStringContainsString( 'Report Recipients:', $output );
	}

	/**
	 * Test render_checkin_settings_box shows default email info.
	 *
	 * @return void
	 */
	public function test_render_checkin_settings_box_shows_default_info(): void {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'checkin_completion_email' => 'admin@example.com' );
				}
				if ( str_starts_with( $option, 'nettertech_events_event_checkin_emails_' ) ) {
					return array();
				}
				return $default;
			}
		);

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_checkin_settings_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'admin@example.com', $output );
		$this->assertStringContainsString( 'System default', $output );
	}

	// =========================================================================
	// render_recurrence_box() Tests
	// =========================================================================

	/**
	 * Test render_recurrence_box outputs postbox structure.
	 *
	 * @return void
	 */
	public function test_render_recurrence_box_outputs_postbox(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="postbox"', $output );
		$this->assertStringContainsString( 'Recurrence Pattern', $output );
	}

	/**
	 * Test render_recurrence_box is hidden for single events.
	 *
	 * @return void
	 */
	public function test_render_recurrence_box_hidden_for_single(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'display: none;', $output );
	}

	/**
	 * Test render_recurrence_box is visible for recurring events.
	 *
	 * @return void
	 */
	public function test_render_recurrence_box_visible_for_recurring(): void {
		$this->event->event_type = 'recurring';
		$handler                 = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		// Check postbox doesn't have display:none.
		$this->assertStringNotContainsString( 'id="recurrence-box" style="display: none;', $output );
	}

	/**
	 * Test render_recurrence_box shows preset select.
	 *
	 * @return void
	 */
	public function test_render_recurrence_box_shows_presets(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="recurrence_preset"', $output );
		$this->assertStringContainsString( 'Select pattern...', $output );
		$this->assertStringContainsString( 'Custom...', $output );
	}

	/**
	 * Test render_recurrence_box shows frequency options.
	 *
	 * @return void
	 */
	public function test_render_recurrence_box_shows_frequency(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="recurrence_freq"', $output );
		$this->assertStringContainsString( 'value="DAILY"', $output );
		$this->assertStringContainsString( 'value="WEEKLY"', $output );
		$this->assertStringContainsString( 'value="MONTHLY"', $output );
	}

	/**
	 * Test render_recurrence_box shows end type options.
	 *
	 * @return void
	 */
	public function test_render_recurrence_box_shows_end_types(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="recurrence_end_type"', $output );
		$this->assertStringContainsString( 'value="never"', $output );
		$this->assertStringContainsString( 'value="count"', $output );
		$this->assertStringContainsString( 'value="until"', $output );
	}

	/**
	 * Test render_recurrence_box shows hidden rule input.
	 *
	 * @return void
	 */
	public function test_render_recurrence_box_shows_rule_input(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="recurrence_rule"', $output );
		$this->assertStringContainsString( 'type="hidden"', $output );
	}

	/**
	 * Test render_recurrence_box shows current pattern for recurring event.
	 *
	 * @return void
	 */
	public function test_render_recurrence_box_shows_current_pattern(): void {
		$this->event->event_type      = 'recurring';
		$this->event->recurrence_rule = 'FREQ=WEEKLY;BYDAY=MO';

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Current pattern:', $output );
	}

	// =========================================================================
	// render_ticket_types_box() Tests
	// =========================================================================

	/**
	 * Test render_ticket_types_box outputs postbox structure.
	 *
	 * @return void
	 */
	public function test_render_ticket_types_box_outputs_postbox(): void {
		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_ticket_types_box( null );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="nte-tickets-metabox"', $output );
		$this->assertStringContainsString( 'Ticketing', $output );
	}

	/**
	 * Test render_ticket_types_box shows save prompt for new events.
	 *
	 * @return void
	 */
	public function test_render_ticket_types_box_shows_buffered_form_for_new(): void {
		$new_event     = new Event();
		$new_event->id = 0;
		$handler       = $this->create_handler( $new_event, 0 );

		ob_start();
		$handler->render_ticket_types_box( null );
		$output = ob_get_clean();

		// New events render a working buffered ticket form (NTE-177).
		$this->assertStringNotContainsString( 'Save the event first', $output );
		$this->assertStringContainsString( 'ticketing_enabled', $output );
	}

	/**
	 * Test render_ticket_types_box shows recurring message.
	 *
	 * @return void
	 */
	public function test_render_ticket_types_box_shows_recurring_message(): void {
		$this->event->event_type = 'recurring';

		$this->mock_ticket_type_repo
			->shouldReceive( 'for_event' )
			->with( 1, array( 'scope' => 'event' ) )
			->andReturn( array() );
		$this->mock_ticket_type_repo
			->shouldReceive( 'get_templates' )
			->with( 1 )
			->andReturn( array() );

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_ticket_types_box( null );
		$output = ob_get_clean();

		// Recurring events now render a tabbed UI with Series Passes / Ticket Templates.
		$this->assertStringContainsString( 'Series Passes', $output );
		$this->assertStringContainsString( 'Ticket Templates', $output );
	}

	/**
	 * Test render_ticket_types_box shows enable checkbox with occurrence.
	 *
	 * @return void
	 */
	public function test_render_ticket_types_box_shows_enable_checkbox(): void {
		$occurrence           = new Occurrence();
		$occurrence->id       = 1;
		$occurrence->event_id = 1;

		$this->mock_ticket_type_repo
			->shouldReceive( 'for_occurrence' )
			->with( 1 )
			->andReturn( array() );

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_ticket_types_box( $occurrence );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="ticketing_enabled"', $output );
		$this->assertStringContainsString( 'Enable Ticketing', $output );
	}

	/**
	 * Test render_ticket_types_box shows add button with occurrence.
	 *
	 * @return void
	 */
	public function test_render_ticket_types_box_shows_add_button(): void {
		$occurrence           = new Occurrence();
		$occurrence->id       = 1;
		$occurrence->event_id = 1;

		$this->mock_ticket_type_repo
			->shouldReceive( 'for_occurrence' )
			->with( 1 )
			->andReturn( array() );

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_ticket_types_box( $occurrence );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-add-ticket', $output );
		$this->assertStringContainsString( 'Add Ticket Type', $output );
	}

	/**
	 * Test render_ticket_types_box renders existing ticket types.
	 *
	 * @return void
	 */
	public function test_render_ticket_types_box_renders_existing(): void {
		$occurrence           = new Occurrence();
		$occurrence->id       = 1;
		$occurrence->event_id = 1;

		$ticket_type        = new TicketType();
		$ticket_type->id    = 1;
		$ticket_type->name  = 'General Admission';
		$ticket_type->price = 25.00;

		$this->mock_ticket_type_repo
			->shouldReceive( 'for_occurrence' )
			->with( 1 )
			->andReturn( array( $ticket_type ) );

		$this->mock_capacity_service
			->shouldReceive( 'get_capacity_summary' )
			->with( 1 )
			->andReturn( array(
				'is_unlimited'       => true,
				'sold'               => 0,
				'effective_available' => null,
			) );
		$this->mock_capacity_service
			->shouldReceive( 'get_buffer_stock' )
			->with( 1 )
			->andReturn( 0 );

		$handler = $this->create_handler( $this->event, 1 );

		ob_start();
		$handler->render_ticket_types_box( $occurrence );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'General Admission', $output );
	}

	// =========================================================================
	// Categories Box Tests
	// =========================================================================

	/**
	 * Test categories box renders with checkboxes for available categories.
	 *
	 * @return void
	 */
	public function test_render_categories_box_shows_available_categories(): void {
		$cat1       = new \NetterTechEvents\Models\Category();
		$cat1->id   = 10;
		$cat1->name = 'Concert';
		$cat1->slug = 'concert';

		$cat2       = new \NetterTechEvents\Models\Category();
		$cat2->id   = 20;
		$cat2->name = 'Workshop';
		$cat2->slug = 'workshop';

		$mock_repo = \Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$mock_repo->shouldReceive( 'get_all' )->andReturn( array( $cat1, $cat2 ) );
		$mock_repo->shouldReceive( 'find_by_event' )->with( 1 )->andReturn( array( $cat1 ) );

		$handler = $this->create_handler( $this->event, 1, $mock_repo );

		ob_start();
		$handler->render_categories_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Categories', $output );
		$this->assertStringContainsString( 'Concert', $output );
		$this->assertStringContainsString( 'Workshop', $output );
		$this->assertStringContainsString( 'event_categories[]', $output );
		$this->assertStringContainsString( 'value="10"', $output );
		$this->assertStringContainsString( 'value="20"', $output );
	}

	/**
	 * Test categories box pre-checks assigned categories.
	 *
	 * @return void
	 */
	public function test_render_categories_box_checks_assigned_categories(): void {
		$cat1       = new \NetterTechEvents\Models\Category();
		$cat1->id   = 10;
		$cat1->name = 'Concert';
		$cat1->slug = 'concert';

		$mock_repo = \Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$mock_repo->shouldReceive( 'get_all' )->andReturn( array( $cat1 ) );
		$mock_repo->shouldReceive( 'find_by_event' )->with( 1 )->andReturn( array( $cat1 ) );

		$handler = $this->create_handler( $this->event, 1, $mock_repo );

		ob_start();
		$handler->render_categories_box();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="10"[^>]*checked/', $output );
	}

	/**
	 * Test categories box shows message when no categories exist.
	 *
	 * @return void
	 */
	public function test_render_categories_box_shows_empty_message(): void {
		$mock_repo = \Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$mock_repo->shouldReceive( 'get_all' )->andReturn( array() );
		$mock_repo->shouldReceive( 'find_by_event' )->andReturn( array() );

		$handler = $this->create_handler( $this->event, 1, $mock_repo );

		ob_start();
		$handler->render_categories_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No categories found', $output );
		$this->assertStringContainsString( 'nettertech-events-categories', $output );
	}

	/**
	 * Test categories box for new event has no pre-checked items.
	 *
	 * @return void
	 */
	public function test_render_categories_box_new_event_none_checked(): void {
		$cat1       = new \NetterTechEvents\Models\Category();
		$cat1->id   = 10;
		$cat1->name = 'Concert';
		$cat1->slug = 'concert';

		$mock_repo = \Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$mock_repo->shouldReceive( 'get_all' )->andReturn( array( $cat1 ) );

		$handler = $this->create_handler( $this->event, 0, $mock_repo );

		ob_start();
		$handler->render_categories_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Concert', $output );
		$this->assertStringNotContainsString( 'checked', $output );
	}
}
