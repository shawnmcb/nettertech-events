<?php
/**
 * Activity log write path integration test.
 *
 * Verifies that admin actions produce correctly-shaped rows in the activity_log table.
 *
 * @package NetterTechEvents\Tests\Integration\ActivityLog
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\ActivityLog;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Repositories\ActivityLogRepository;
use NetterTechEvents\Services\ActivityLogService;

/**
 * Integration test: ActivityLogService write path produces correct table rows.
 */
class ActivityLogWritePathTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Service under test.
	 *
	 * @var ActivityLogService
	 */
	private ActivityLogService $service;

	/**
	 * Activity log table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Admin user ID set as current user for each test.
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$repository    = new ActivityLogRepository( $wpdb );
		$this->service = new ActivityLogService( $repository );
		$this->table   = Schema::table( 'activity_log' );

		// Create an admin user context so rows receive a non-null user_id.
		$this->admin_user_id = (int) wp_insert_user(
			array(
				'user_login' => 'nettertech_events_test_admin_' . mt_rand( 1000, 9999 ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_user_id );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Fetch the most recent activity log row for a given object type and ID.
	 *
	 * @param string   $object_type Object type.
	 * @param int|null $object_id   Object ID, or null for type-only lookup.
	 * @return object|null Database row or null.
	 */
	private function fetch_latest_row( string $object_type, ?int $object_id = null ): ?object {
		global $wpdb;

		$table = $this->table;

		if ( null !== $object_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion query.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table(); trusted constant.
					$object_type,
					$object_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion query.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE object_type = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table(); trusted constant.
					$object_type
				)
			);
		}

		return $row ?: null;
	}

	/**
	 * Assert that a row has the expected base shape.
	 *
	 * @param object $row         Database row.
	 * @param int    $actor_id    Expected user_id.
	 * @param string $action      Expected action.
	 * @param string $object_type Expected object_type.
	 * @return void
	 */
	private function assert_row_shape( object $row, int $actor_id, string $action, string $object_type ): void {
		$this->assertSame( (string) $actor_id, (string) $row->user_id, 'user_id mismatch' );
		$this->assertSame( $action, $row->action, 'action mismatch' );
		$this->assertSame( $object_type, $row->object_type, 'object_type mismatch' );
		$this->assertNotEmpty( $row->created_at, 'created_at should be set' );

		// Compare created_at age against MySQL's own clock to avoid PHP/WP timezone skew.
		// TIMESTAMPDIFF operates entirely within MySQL, bypassing PHP strtotime() timezone issues.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off timestamp staleness check.
		$age_seconds = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TIMESTAMPDIFF(SECOND, %s, NOW())',
				$row->created_at
			)
		);
		$this->assertLessThanOrEqual( 60, $age_seconds, 'created_at is stale (age: ' . $age_seconds . 's)' );
	}

	// =========================================================================
	// Table existence gate
	// =========================================================================

	/**
	 * Activity log table must exist before write-path tests exercise it.
	 *
	 * @return void
	 */
	public function test_activity_log_table_exists(): void {
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table )
		);

		if ( null === $exists ) {
			$this->markTestSkipped( 'Activity log table not present in this database.' );
		}

		$this->assertSame( $this->table, $exists );
	}

	// =========================================================================
	// Event actions
	// =========================================================================

	/**
	 * log_event('create') writes a row with action=create and object_type=event.
	 *
	 * @return void
	 */
	public function test_log_event_create_writes_correct_row(): void {
		$event_id = 42;
		$title    = 'Test Event Create';

		$this->service->log_event( 'create', $event_id, $title, array( 'title' => $title ) );

		$row = $this->fetch_latest_row( 'event', $event_id );
		$this->assertNotNull( $row, 'Expected activity log row for event create.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'create', 'event' );
		$this->assertSame( (string) $event_id, (string) $row->object_id );
		$this->assertSame( $title, $row->object_name );
	}

	/**
	 * log_event('update') writes a row with action=update and object_type=event.
	 *
	 * @return void
	 */
	public function test_log_event_update_writes_correct_row(): void {
		$event_id = 43;
		$title    = 'Test Event Update';

		$this->service->log_event( 'update', $event_id, $title, array( 'title' => $title ) );

		$row = $this->fetch_latest_row( 'event', $event_id );
		$this->assertNotNull( $row, 'Expected activity log row for event update.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'update', 'event' );
		$this->assertSame( $title, $row->object_name );
	}

	/**
	 * log_event('delete') writes a row with action=delete and object_type=event.
	 *
	 * @return void
	 */
	public function test_log_event_delete_writes_correct_row(): void {
		$event_id = 44;
		$title    = 'Test Event Delete';

		$this->service->log_event( 'delete', $event_id, $title );

		$row = $this->fetch_latest_row( 'event', $event_id );
		$this->assertNotNull( $row, 'Expected activity log row for event delete.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'delete', 'event' );
	}

	// =========================================================================
	// Occurrence actions
	// =========================================================================

	/**
	 * log_occurrence('create') writes a row with object_type=occurrence.
	 *
	 * @return void
	 */
	public function test_log_occurrence_create_writes_correct_row(): void {
		$occurrence_id = 101;
		$title         = 'Test Occurrence';

		$this->service->log_occurrence( 'create', $occurrence_id, $title, array( 'event_title' => $title ) );

		$row = $this->fetch_latest_row( 'occurrence', $occurrence_id );
		$this->assertNotNull( $row, 'Expected activity log row for occurrence create.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'create', 'occurrence' );
		$this->assertSame( (string) $occurrence_id, (string) $row->object_id );
	}

	/**
	 * log_occurrence('delete') writes a row with action=delete.
	 *
	 * @return void
	 */
	public function test_log_occurrence_delete_writes_correct_row(): void {
		$occurrence_id = 102;

		$this->service->log_occurrence( 'delete', $occurrence_id, 'Occurrence To Delete' );

		$row = $this->fetch_latest_row( 'occurrence', $occurrence_id );
		$this->assertNotNull( $row, 'Expected activity log row for occurrence delete.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'delete', 'occurrence' );
	}

	// =========================================================================
	// Attendee actions
	// =========================================================================

	/**
	 * log_attendee('check_in') writes a row with action=check_in and object_type=attendee.
	 *
	 * @return void
	 */
	public function test_log_attendee_check_in_writes_correct_row(): void {
		$attendee_id = 201;
		$name        = 'Jane Doe';

		$this->service->log_attendee( 'check_in', $attendee_id, $name, array( 'name' => $name ) );

		$row = $this->fetch_latest_row( 'attendee', $attendee_id );
		$this->assertNotNull( $row, 'Expected activity log row for attendee check_in.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'check_in', 'attendee' );
		$this->assertSame( $name, $row->object_name );
	}

	/**
	 * log_attendee('cancel') writes a row with action=cancel.
	 *
	 * @return void
	 */
	public function test_log_attendee_cancel_writes_correct_row(): void {
		$attendee_id = 202;

		$this->service->log_attendee( 'cancel', $attendee_id, 'John Doe' );

		$row = $this->fetch_latest_row( 'attendee', $attendee_id );
		$this->assertNotNull( $row, 'Expected activity log row for attendee cancel.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'cancel', 'attendee' );
	}

	// =========================================================================
	// Ticket type actions
	// =========================================================================

	/**
	 * log_ticket_type('create') writes a row with object_type=ticket_type.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_create_writes_correct_row(): void {
		$ticket_type_id = 301;
		$name           = 'General Admission';

		$this->service->log_ticket_type( 'create', $ticket_type_id, $name, array( 'name' => $name ) );

		$row = $this->fetch_latest_row( 'ticket_type', $ticket_type_id );
		$this->assertNotNull( $row, 'Expected activity log row for ticket_type create.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'create', 'ticket_type' );
		$this->assertSame( $name, $row->object_name );
	}

	/**
	 * log_ticket_type('delete') writes a row with action=delete.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_delete_writes_correct_row(): void {
		$ticket_type_id = 302;

		$this->service->log_ticket_type( 'delete', $ticket_type_id, 'VIP Access' );

		$row = $this->fetch_latest_row( 'ticket_type', $ticket_type_id );
		$this->assertNotNull( $row, 'Expected activity log row for ticket_type delete.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'delete', 'ticket_type' );
	}

	// =========================================================================
	// Settings action
	// =========================================================================

	/**
	 * log_settings() writes a row with action=settings_update and object_type=settings.
	 *
	 * @return void
	 */
	public function test_log_settings_update_writes_correct_row(): void {
		$changes = array( 'email_notifications' => true );

		$this->service->log_settings( $changes );

		$row = $this->fetch_latest_row( 'settings' );
		$this->assertNotNull( $row, 'Expected activity log row for settings update.' );
		$this->assert_row_shape( $row, $this->admin_user_id, 'settings_update', 'settings' );
		$this->assertNull( $row->object_id, 'Settings rows should have null object_id.' );

		$decoded = json_decode( $row->details, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded['email_notifications'] );
	}

	// =========================================================================
	// ACTIVITY_LOGGING_ENABLED filter gate
	// =========================================================================

	/**
	 * When the nettertech_events_activity_logging_enabled filter returns false, no row is written.
	 *
	 * @return void
	 */
	public function test_logging_disabled_filter_suppresses_writes(): void {
		global $wpdb;
		$table = $this->table;

		add_filter( \NetterTechEvents\Core\Hooks::ACTIVITY_LOGGING_ENABLED, '__return_false' );

		// Use a sentinel object_id unlikely to collide.
		$sentinel_id = 999998;

		// Rebuild service so the constructor re-reads the filter.
		$repository      = new ActivityLogRepository( $wpdb );
		$disabled_service = new ActivityLogService( $repository );

		$disabled_service->log_event( 'create', $sentinel_id, 'Should Not Appear' );

		remove_filter( \NetterTechEvents\Core\Hooks::ACTIVITY_LOGGING_ENABLED, '__return_false' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion query.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE object_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table(); trusted constant.
				$sentinel_id
			)
		);

		$this->assertSame( 0, $count, 'No rows should be written when logging is disabled.' );
	}
}
