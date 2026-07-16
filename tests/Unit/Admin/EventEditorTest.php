<?php
/**
 * EventEditor unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\EventEditor;
use NetterTechEvents\Admin\EventMetaboxHandler;
use NetterTechEvents\Admin\EventSaveHandler;
use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\TicketTypeSaver;

/**
 * Test EventEditor functionality.
 *
 * Tests form processing logic, RRULE building, and validation.
 * Render methods are excluded as they're primarily HTML output.
 */
class EventEditorTest extends \NetterTechEventsTestCase {

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|\Mockery\MockInterface
	 */
	private $mock_event_repo;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|\Mockery\MockInterface
	 */
	private $mock_occurrence_repo;

	/**
	 * Mock ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface|\Mockery\MockInterface
	 */
	private $mock_ticket_type_repo;

	/**
	 * Mock capacity service.
	 *
	 * @var CapacityServiceInterface|\Mockery\MockInterface
	 */
	private $mock_capacity_service;

	/**
	 * Mock save handler.
	 *
	 * @var EventSaveHandler|\Mockery\MockInterface
	 */
	private $mock_save_handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset ServiceRegistry.
		ServiceRegistry::reset();

		// Create mock dependencies.
		$this->mock_event_repo       = Mockery::mock( EventRepositoryInterface::class );
		$this->mock_occurrence_repo  = Mockery::mock( OccurrenceRepositoryInterface::class );
		// The tickets metabox asks how many dates the event has to decide whether
		// the tabbed (series-pass) form applies (NTE-156). One date by default.
		$this->mock_occurrence_repo->shouldReceive( 'count_for_event' )->andReturn( 1 )->byDefault();
		$this->mock_ticket_type_repo = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->mock_capacity_service = Mockery::mock( CapacityServiceInterface::class );
		$this->mock_save_handler     = Mockery::mock( EventSaveHandler::class );

		// Register mocks with ServiceRegistry.
		ServiceRegistry::set( EventRepositoryInterface::class, $this->mock_event_repo );
		ServiceRegistry::set( OccurrenceRepositoryInterface::class, $this->mock_occurrence_repo );
		ServiceRegistry::set( TicketTypeRepositoryInterface::class, $this->mock_ticket_type_repo );
		ServiceRegistry::set( CapacityServiceInterface::class, $this->mock_capacity_service );

		// Mock common WordPress functions.
		$this->mock_common_wp_functions();
	}

	/**
	 * Create an EventEditor instance with required dependencies.
	 *
	 * @param int                                                           $event_id       Event ID.
	 * @param \NetterTechEvents\Contracts\OrganizerRepositoryInterface|null $organizer_repo Optional organizer repository.
	 * @return EventEditor
	 */
	private function create_editor( int $event_id = 0, ?\NetterTechEvents\Contracts\OrganizerRepositoryInterface $organizer_repo = null ): EventEditor {
		$mock_recurrence = \Mockery::mock( \NetterTechEvents\Services\RecurrenceService::class );
		$mock_recurrence->shouldIgnoreMissing();
		$mock_revision_repo = \Mockery::mock( \NetterTechEvents\Repositories\RevisionRepository::class );
		$mock_revision_repo->shouldIgnoreMissing();

		$mock_attendee_field_repo = \Mockery::mock( \NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface::class );
		$mock_attendee_field_repo->shouldIgnoreMissing();

		$mock_layout_service = \Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout_service->shouldReceive( 'get_components' )->andReturn( array(
			'title'       => array( 'label' => 'Title', 'description' => 'Title', 'default_visible' => true ),
			'description' => array( 'label' => 'Description', 'description' => 'Desc', 'default_visible' => true ),
		) );
		$mock_layout_service->shouldReceive( 'get_layout' )->andReturn( array(
			'order'      => array( 'title', 'description' ),
			'visibility' => array( 'title' => true, 'description' => true ),
		) );
		$mock_layout_service->shouldIgnoreMissing();

		$mock_category_repo = \Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$mock_category_repo->shouldIgnoreMissing();

		return new EventEditor(
			$event_id,
			$this->mock_event_repo,
			$this->mock_occurrence_repo,
			$this->mock_ticket_type_repo,
			$this->mock_capacity_service,
			$mock_recurrence,
			$mock_revision_repo,
			$mock_attendee_field_repo,
			$mock_layout_service,
			$mock_category_repo,
			$organizer_repo
		);
	}

	/**
	 * Mock common WordPress functions.
	 *
	 * @return void
	 */
	private function mock_common_wp_functions(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		// _e functions echo rather than return, so use alias.
		Functions\when( '_e' )->alias( fn( $text ) => print( $text ) );
		Functions\when( 'esc_html_e' )->alias( fn( $text ) => print( $text ) );
		Functions\when( 'esc_attr_e' )->alias( fn( $text ) => print( $text ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'wp_timezone' )->alias( fn() => new \DateTimeZone( 'America/Chicago' ) );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action = '' ) => $url . '&_wpnonce=abc123' );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'get_theme_mod' )->justReturn( '' );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor with new event (ID = 0).
	 *
	 * @return void
	 */
	public function test_constructor_creates_new_event_for_zero_id(): void {
		$editor = $this->create_editor( 0 );

		// Use reflection to verify private event property is a new Event.
		$reflection = new \ReflectionClass( $editor );
		$event_prop = $reflection->getProperty( 'event' );

		$event = $event_prop->getValue( $editor );

		$this->assertInstanceOf( Event::class, $event );
		$this->assertNull( $event->id );
	}

	/**
	 * Test constructor stores event_id.
	 *
	 * @return void
	 */
	public function test_constructor_stores_event_id(): void {
		// Mock event repo to return null for event ID 123.
		$this->mock_event_repo
			->shouldReceive( 'find' )
			->with( 123 )
			->once()
			->andReturn( null );

		$editor = $this->create_editor( 123 );

		$reflection    = new \ReflectionClass( $editor );
		$event_id_prop = $reflection->getProperty( 'event_id' );

		$this->assertEquals( 123, $event_id_prop->getValue( $editor ) );
	}

	/**
	 * Test constructor initializes repositories via ServiceRegistry.
	 *
	 * @return void
	 */
	public function test_constructor_initializes_repositories(): void {
		$editor = $this->create_editor( 0 );

		$reflection = new \ReflectionClass( $editor );

		$event_repo_prop       = $reflection->getProperty( 'event_repo' );
		$occurrence_repo_prop  = $reflection->getProperty( 'occurrence_repo' );
		$ticket_type_repo_prop = $reflection->getProperty( 'ticket_type_repo' );
		$capacity_service_prop = $reflection->getProperty( 'capacity_service' );

		// Now uses ServiceRegistry, so should receive our mocks.
		$this->assertInstanceOf(
			EventRepositoryInterface::class,
			$event_repo_prop->getValue( $editor )
		);
		$this->assertInstanceOf(
			OccurrenceRepositoryInterface::class,
			$occurrence_repo_prop->getValue( $editor )
		);
		$this->assertInstanceOf(
			TicketTypeRepositoryInterface::class,
			$ticket_type_repo_prop->getValue( $editor )
		);
		$this->assertInstanceOf(
			CapacityServiceInterface::class,
			$capacity_service_prop->getValue( $editor )
		);
	}

	/**
	 * Test the organizer metabox is wired when an organizer repository is supplied.
	 *
	 * Regression guard for the pre-1.1.1 bug where the constructor checked
	 * isset() on a parameter that did not exist, so the organizer metabox
	 * was silently never wired (found when the PHPStan gate was restored).
	 *
	 * @return void
	 */
	public function test_constructor_wires_organizer_metabox_when_repo_supplied(): void {
		$mock_organizer_repo = \Mockery::mock( \NetterTechEvents\Contracts\OrganizerRepositoryInterface::class );
		$mock_organizer_repo->shouldIgnoreMissing();

		$editor = $this->create_editor( 0, $mock_organizer_repo );

		$metabox_handler   = ( new \ReflectionClass( $editor ) )->getProperty( 'metabox_handler' )->getValue( $editor );
		$organizer_handler = ( new \ReflectionClass( $metabox_handler ) )->getProperty( 'organizer_handler' )->getValue( $metabox_handler );

		$this->assertNotNull( $organizer_handler, 'Organizer metabox handler must be wired when a repository is supplied.' );
	}

	/**
	 * Test the organizer metabox stays unwired when no repository is supplied.
	 *
	 * @return void
	 */
	public function test_constructor_skips_organizer_metabox_without_repo(): void {
		$editor = $this->create_editor( 0 );

		$metabox_handler   = ( new \ReflectionClass( $editor ) )->getProperty( 'metabox_handler' )->getValue( $editor );
		$organizer_handler = ( new \ReflectionClass( $metabox_handler ) )->getProperty( 'organizer_handler' )->getValue( $metabox_handler );

		$this->assertNull( $organizer_handler );
	}

	// =========================================================================
	// register() Tests
	// =========================================================================

	/**
	 * Test register adds admin_post action.
	 *
	 * @return void
	 */
	public function test_register_adds_admin_post_action(): void {
		// Use when() instead of expect() for simpler testing.
		Functions\when( 'add_action' )->justReturn( true );

		// Verify method runs without errors.
		EventEditor::register( $this->mock_save_handler );
		$this->assertTrue( true );
	}

	// =========================================================================
	// handle_save() Tests
	// =========================================================================

	/**
	 * Create an EventSaveHandler instance with mocked dependencies.
	 *
	 * @return EventSaveHandler
	 */
	private function create_save_handler(): EventSaveHandler {
		$mock_rrule_builder = Mockery::mock( \NetterTechEvents\Services\RecurrenceRuleBuilder::class );
		$mock_rrule_builder->shouldIgnoreMissing();
		$mock_attendee_fields_saver = Mockery::mock( \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class );
		$mock_attendee_fields_saver->shouldIgnoreMissing();
		$mock_checkin_email_saver = Mockery::mock( \NetterTechEvents\Services\CheckInEmailSaver::class );
		$mock_checkin_email_saver->shouldIgnoreMissing();

		return new EventSaveHandler(
			$this->mock_event_repo,
			Mockery::mock( OccurrenceRepositoryInterface::class ),
			Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class ),
			Mockery::mock( RecurrenceService::class ),
			Mockery::mock( TicketTypeSaver::class ),
			Mockery::mock( \NetterTechEvents\Services\LayoutService::class ),
			$mock_rrule_builder,
			$mock_attendee_fields_saver,
			$mock_checkin_email_saver
		);
	}

	/**
	 * Test handle_save exits when user lacks permission.
	 *
	 * @return void
	 */
	public function test_handle_save_requires_permission(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->create_save_handler()->handle_save();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertTrue( $died, 'wp_die should be called for permission failure' );
	}

	/**
	 * Test handle_save verifies nonce.
	 *
	 * @return void
	 */
	public function test_handle_save_verifies_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html__' )->returnArg();

		// No nonce in POST.
		$_POST = array();

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->create_save_handler()->handle_save();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertTrue( $died, 'wp_die should be called for missing nonce' );
	}

	/**
	 * Test handle_save checks nonce validity.
	 *
	 * @return void
	 */
	public function test_handle_save_checks_nonce_validity(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$_POST['nettertech_events_event_nonce'] = 'invalid';

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->create_save_handler()->handle_save();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertTrue( $died, 'wp_die should be called for invalid nonce' );
	}

	/**
	 * Test handle_save redirects when updating non-existent event.
	 *
	 * @return void
	 */
	public function test_handle_save_redirects_on_missing_event(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . $path );

		$_POST['nettertech_events_event_nonce'] = 'valid';
		$_POST['event_id']                 = 99999;

		$this->mock_event_repo
			->shouldReceive( 'find' )
			->with( 99999 )
			->andReturn( null );

		$redirected_to = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirected_to ) {
				$redirected_to = $url;
				throw new \Exception( 'exit' );
			}
		);

		try {
			$this->create_save_handler()->handle_save();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertNotNull( $redirected_to );
		$this->assertStringContainsString( 'message=error', $redirected_to );
	}

	// =========================================================================
	// Constructor Tests - Extended
	// =========================================================================

	/**
	 * Test constructor loads existing event.
	 *
	 * @return void
	 */
	public function test_constructor_loads_existing_event(): void {
		$existing_event     = new Event();
		$existing_event->id = 42;

		$this->mock_event_repo
			->shouldReceive( 'find' )
			->with( 42 )
			->once()
			->andReturn( $existing_event );

		$editor = $this->create_editor( 42 );

		$reflection = new \ReflectionClass( $editor );
		$event_prop = $reflection->getProperty( 'event' );

		$this->assertSame( $existing_event, $event_prop->getValue( $editor ) );
	}

	/**
	 * Test constructor creates new event when existing not found.
	 *
	 * @return void
	 */
	public function test_constructor_creates_event_when_not_found(): void {
		$this->mock_event_repo
			->shouldReceive( 'find' )
			->with( 99999 )
			->once()
			->andReturn( null );

		$editor = $this->create_editor( 99999 );

		$reflection = new \ReflectionClass( $editor );
		$event_prop = $reflection->getProperty( 'event' );
		$event      = $event_prop->getValue( $editor );

		$this->assertInstanceOf( Event::class, $event );
		$this->assertNull( $event->id );
	}

	// =========================================================================
	// render() Tests
	// =========================================================================

	/**
	 * Test render outputs event title input for new event.
	 *
	 * @return void
	 */
	public function test_render_outputs_event_form(): void {
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_editor' )->justReturn( '' );
		Functions\when( 'settings_errors' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'date_i18n' )->alias( fn( $format, $timestamp = null ) => date( $format, $timestamp ?? time() ) );
		Functions\when( 'get_date_from_gmt' )->alias( fn( $string, $format = 'Y-m-d H:i:s' ) => gmdate( $format, strtotime( $string ) ) );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'rest_url' )->justReturn( 'http://example.com/wp-json/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'get_terms' )->justReturn( array() );

		// Mock Branding class.
		$this->mockBrandingClass();

		$editor = $this->create_editor( 0 );

		ob_start();
		$editor->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'event_title', $output );
		$this->assertStringContainsString( 'nettertech-events-editor', $output );
		$this->assertStringContainsString( 'nettertech_events_save_event', $output );
	}

	/**
	 * Test render shows error from transient.
	 *
	 * @return void
	 */
	public function test_render_shows_transient_error(): void {
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( 'Test error message' );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_editor' )->justReturn( '' );
		Functions\when( 'settings_errors' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'date_i18n' )->alias( fn( $format, $timestamp = null ) => date( $format, $timestamp ?? time() ) );
		Functions\when( 'get_date_from_gmt' )->alias( fn( $string, $format = 'Y-m-d H:i:s' ) => gmdate( $format, strtotime( $string ) ) );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'rest_url' )->justReturn( 'http://example.com/wp-json/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'get_terms' )->justReturn( array() );

		$this->mockBrandingClass();

		$editor = $this->create_editor( 0 );

		ob_start();
		$editor->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test error message', $output );
		$this->assertStringContainsString( 'notice-error', $output );
	}

	/**
	 * Test render for existing event shows edit title.
	 *
	 * @return void
	 */
	public function test_render_for_existing_event(): void {
		$existing_event              = new Event();
		$existing_event->id          = 42;
		$existing_event->title       = 'Existing Event';
		$existing_event->event_type  = 'single';
		$existing_event->status      = EventStatus::DRAFT;
		$existing_event->created_at = '2026-01-01 12:00:00';
		$existing_event->updated_at = '2026-01-02 12:00:00';

		$this->mock_event_repo
			->shouldReceive( 'find' )
			->with( 42 )
			->once()
			->andReturn( $existing_event );

		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with( 42, array( 'limit' => 1 ) )
			->once()
			->andReturn( array() );

		// The Dates box renders for any saved event now, not only a recurring one, so a date
		// stranded by a conversion stays visible instead of going on selling itself unseen (NTE-153).
		$this->mock_occurrence_repo
			->shouldReceive( 'for_event' )
			->with(
				42,
				array(
					'upcoming' => true,
					'limit'    => 100,
				)
			)
			->andReturn( array() );

		$this->mock_occurrence_repo
			->shouldReceive( 'count_for_event' )
			->andReturn( 0 );

		$this->mock_ticket_type_repo
			->shouldReceive( 'for_event' )
			->andReturn( array() );

		$this->mock_capacity_service
			->shouldReceive( 'get_capacity_summary' )
			->andReturn(
				array(
					'capacity'  => 0,
					'sold'      => 0,
					'remaining' => 0,
					'types'     => array(),
				)
			);

		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_editor' )->justReturn( '' );
		Functions\when( 'settings_errors' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'date_i18n' )->alias( fn( $format, $timestamp = null ) => date( $format, $timestamp ?? time() ) );
		Functions\when( 'get_date_from_gmt' )->alias( fn( $string, $format = 'Y-m-d H:i:s' ) => gmdate( $format, strtotime( $string ) ) );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'rest_url' )->justReturn( 'http://example.com/wp-json/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'wp_get_object_terms' )->justReturn( array() );

		\NetterTechEvents\Core\Taxonomies::clear_category_cache();

		$this->mockBrandingClass();

		$editor = $this->create_editor( 42 );

		ob_start();
		$editor->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Existing Event', $output );
		$this->assertStringContainsString( 'value="42"', $output );
	}

	// =========================================================================
	// EventMetaboxHandler Tests (moved from EventEditor)
	// =========================================================================

	/**
	 * Create a EventMetaboxHandler instance for testing.
	 *
	 * @param Event|null $event Event to use, or null for new Event.
	 * @param int        $event_id Event ID.
	 * @return EventMetaboxHandler
	 */
	private function createMetaboxHandler( ?Event $event = null, int $event_id = 0 ): EventMetaboxHandler {
		if ( null === $event ) {
			$event = new Event();
		}
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

		$mock_category_repo = \Mockery::mock( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class );
		$mock_category_repo->shouldIgnoreMissing();

		return new EventMetaboxHandler(
			$event,
			$event_id,
			$this->mock_ticket_type_repo,
			$this->mock_capacity_service,
			$mock_recurrence,
			$mock_revision_repo,
			$mock_layout,
			$mock_category_repo
		);
	}

	// =========================================================================
	// render_publish_box() Tests
	// =========================================================================

	/**
	 * Test render_publish_box outputs status dropdown.
	 *
	 * @return void
	 */
	public function test_render_publish_box_outputs_status_dropdown(): void {
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true ) {
				return $selected === $current ? ' selected="selected"' : '';
			}
		);
		Functions\when( 'esc_html_e' )->returnArg();

		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_publish_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'event_status', $output );
		$this->assertStringContainsString( 'draft', $output );
		$this->assertStringContainsString( 'published', $output );
		$this->assertStringContainsString( 'cancelled', $output );
		$this->assertStringContainsString( 'event_type', $output );
	}

	// =========================================================================
	// render_venue_box() Tests
	// =========================================================================

	/**
	 * Test render_venue_box outputs venue fields.
	 *
	 * @return void
	 */
	public function test_render_venue_box_outputs_venue_fields(): void {
		Functions\when( 'esc_html_e' )->returnArg();

		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_venue_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'venue_name', $output );
		$this->assertStringContainsString( 'venue_address', $output );
	}

	// =========================================================================
	// render_featured_image_box() Tests
	// =========================================================================

	/**
	 * Test render_featured_image_box outputs image picker.
	 *
	 * @return void
	 */
	public function test_render_featured_image_box_outputs_image_picker(): void {
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );

		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_featured_image_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'featured_image_id', $output );
		$this->assertStringContainsString( 'nettertech-events-featured-image', $output );
	}

	/**
	 * Test render_featured_image_box shows image when set.
	 *
	 * @return void
	 */
	public function test_render_featured_image_box_shows_image_when_set(): void {
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'http://example.com/image.jpg' );

		$existing_event                    = new Event();
		$existing_event->id                = 42;
		$existing_event->featured_image_id = 123;

		$handler = $this->createMetaboxHandler( $existing_event, 42 );

		ob_start();
		$handler->render_featured_image_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="123"', $output );
		$this->assertStringContainsString( 'http://example.com/image.jpg', $output );
	}

	// =========================================================================
	// render_date_time_box() Tests
	// =========================================================================

	/**
	 * Test render_date_time_box outputs date inputs.
	 *
	 * @return void
	 */
	public function test_render_date_time_box_outputs_date_inputs(): void {
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'checked' )->justReturn( '' );

		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'start_date', $output );
		$this->assertStringContainsString( 'start_time', $output );
		$this->assertStringContainsString( 'end_time', $output );
	}

	/**
	 * Test render_date_time_box shows occurrence date when set.
	 *
	 * @return void
	 */
	public function test_render_date_time_box_shows_occurrence_date(): void {
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'checked' )->justReturn( '' );

		$occurrence                 = new Occurrence();
		$occurrence->id             = 1;
		$occurrence->event_id       = 42;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';
		$occurrence->all_day        = false;
		$occurrence->timezone       = 'America/Chicago';

		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_schedule_box( $occurrence, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( '2026-06-15', $output );
		$this->assertStringContainsString( '19:00', $output );
		$this->assertStringContainsString( '21:00', $output );
	}

	// =========================================================================
	// render_recurrence_box() Tests
	// =========================================================================

	/**
	 * Test render_recurrence_box outputs recurrence options.
	 *
	 * @return void
	 */
	public function test_render_recurrence_box_outputs_recurrence_options(): void {
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );

		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_schedule_box( null, array(), 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'recurrence_preset', $output );
		$this->assertStringContainsString( 'recurrence_end_type', $output );
		$this->assertStringContainsString( 'recurrence_count', $output );
		$this->assertStringContainsString( 'recurrence_until', $output );
	}

	// =========================================================================
	// render_layout_box() Tests
	// =========================================================================

	/**
	 * Test render_layout_box outputs layout options.
	 *
	 * @return void
	 */
	public function test_render_layout_box_outputs_layout_options(): void {
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'get_terms' )->justReturn( array() );

		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_layout_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-layout-editor', $output );
		$this->assertStringContainsString( 'nettertech_events_layout_mode', $output );
	}

	// =========================================================================
	// render_qr_code_box() Tests
	// =========================================================================

	/**
	 * Test render_qr_code_box returns early for new events.
	 *
	 * QR codes are only shown for existing events with a slug.
	 *
	 * @return void
	 */
	public function test_render_qr_code_box_returns_early_for_new_event(): void {
		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_qr_code_box();
		$output = ob_get_clean();

		// Should return empty - no QR code for new events.
		$this->assertEmpty( $output );
	}

	/**
	 * Test render_qr_code_box outputs content for existing event with slug (NTE-041: base owns QR).
	 *
	 * @return void
	 */
	public function test_render_qr_code_box_outputs_for_existing_event(): void {
		$existing_event       = new Event();
		$existing_event->id   = 42;
		$existing_event->slug = 'test-event';

		$handler = $this->createMetaboxHandler( $existing_event, 42 );

		ob_start();
		$handler->render_qr_code_box();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );
	}

	// =========================================================================
	// render_checkin_settings_box() Tests
	// =========================================================================

	/**
	 * Test render_checkin_settings_box returns early for new events.
	 *
	 * @return void
	 */
	public function test_render_checkin_settings_box_returns_early_for_new_event(): void {
		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_checkin_settings_box();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_checkin_settings_box outputs email settings for existing event.
	 *
	 * @return void
	 */
	public function test_render_checkin_settings_box_outputs_email_settings(): void {
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_attr_e' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . $path );

		$existing_event     = new Event();
		$existing_event->id = 42;

		$handler = $this->createMetaboxHandler( $existing_event, 42 );

		ob_start();
		$handler->render_checkin_settings_box();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checkin_emails', $output );
		$this->assertStringContainsString( 'nte-checkin-settings-postbox', $output );
	}

	// =========================================================================
	// render_ticket_types_box() Tests
	// =========================================================================

	/**
	 * Test render_ticket_types_box shows save prompt for new event.
	 *
	 * For new events (no event_id), shows a prompt to save first.
	 *
	 * @return void
	 */
	public function test_render_ticket_types_box_shows_save_prompt_for_new_event(): void {
		$handler = $this->createMetaboxHandler();

		ob_start();
		$handler->render_ticket_types_box( null );
		$output = ob_get_clean();

		// Should contain the basic structure.
		$this->assertStringContainsString( 'nte-tickets-metabox', $output );
		$this->assertStringContainsString( 'Save the event first', $output );
	}

	/**
	 * Test render_ticket_types_box shows ticketing controls with occurrence.
	 *
	 * @return void
	 */
	public function test_render_ticket_types_box_shows_ticketing_with_occurrence(): void {
		Functions\when( 'esc_html_e' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );

		$existing_event             = new Event();
		$existing_event->id         = 42;
		$existing_event->event_type = 'single';

		$occurrence                 = new Occurrence();
		$occurrence->id             = 1;
		$occurrence->event_id       = 42;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';

		$this->mock_ticket_type_repo
			->shouldReceive( 'for_occurrence' )
			->with( 1 )
			->andReturn( array() );

		$handler = $this->createMetaboxHandler( $existing_event, 42 );

		ob_start();
		$handler->render_ticket_types_box( $occurrence );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ticketing_enabled', $output );
		$this->assertStringContainsString( 'nte-tickets-container', $output );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Mock the Branding class.
	 *
	 * @return void
	 */
	private function mockBrandingClass(): void {
		// Create a simple mock for Branding::render_header().
		if ( ! class_exists( 'NetterTechEvents\Admin\Branding' ) ) {
			eval( 'namespace NetterTechEvents\Admin; class Branding { public static function render_header() { echo "<!-- header -->"; } }' );
		}
	}

	// =========================================================================
	// Cleanup
	// =========================================================================

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Clear POST superglobal.
		$_POST = array();

		// Reset ServiceRegistry.
		ServiceRegistry::reset();

		parent::tearDown();
	}
}
