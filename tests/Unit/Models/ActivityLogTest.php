<?php
/**
 * ActivityLog model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\ActivityLog;
use Brain\Monkey\Functions;

/**
 * Test ActivityLog model functionality.
 */
class ActivityLogTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor assigns all properties.
	 *
	 * @return void
	 */
	public function test_constructor_assigns_all_properties(): void {
		$details = array( 'old_value' => 10, 'new_value' => 20 );

		$log = new ActivityLog(
			1,
			42,
			'update',
			'event',
			100,
			'Test Event',
			$details,
			'192.168.1.1',
			'Mozilla/5.0',
			'2026-01-12 10:30:00'
		);

		$this->assertSame( 1, $log->id );
		$this->assertSame( 42, $log->user_id );
		$this->assertSame( 'update', $log->action );
		$this->assertSame( 'event', $log->object_type );
		$this->assertSame( 100, $log->object_id );
		$this->assertSame( 'Test Event', $log->object_name );
		$this->assertSame( $details, $log->details );
		$this->assertSame( '192.168.1.1', $log->ip_address );
		$this->assertSame( 'Mozilla/5.0', $log->user_agent );
		$this->assertSame( '2026-01-12 10:30:00', $log->created_at );
	}

	/**
	 * Test constructor handles null values.
	 *
	 * @return void
	 */
	public function test_constructor_handles_null_values(): void {
		$log = new ActivityLog(
			1,
			null,
			'export',
			'attendees',
			null,
			null,
			null,
			null,
			null,
			'2026-01-12 10:30:00'
		);

		$this->assertNull( $log->user_id );
		$this->assertNull( $log->object_id );
		$this->assertNull( $log->object_name );
		$this->assertNull( $log->details );
		$this->assertNull( $log->ip_address );
		$this->assertNull( $log->user_agent );
	}

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates ActivityLog from database row.
	 *
	 * @return void
	 */
	public function test_from_row_creates_activity_log_from_object(): void {
		$row = (object) array(
			'id'          => 5,
			'user_id'     => 10,
			'action'      => 'create',
			'object_type' => 'event',
			'object_id'   => 50,
			'object_name' => 'Summer Concert',
			'details'     => '{"key":"value"}',
			'ip_address'  => '10.0.0.1',
			'user_agent'  => 'Chrome/100',
			'created_at'  => '2026-01-12 12:00:00',
		);

		$log = ActivityLog::from_row( $row );

		$this->assertSame( 5, $log->id );
		$this->assertSame( 10, $log->user_id );
		$this->assertSame( 'create', $log->action );
		$this->assertSame( 'event', $log->object_type );
		$this->assertSame( 50, $log->object_id );
		$this->assertSame( 'Summer Concert', $log->object_name );
		$this->assertSame( array( 'key' => 'value' ), $log->details );
		$this->assertSame( '10.0.0.1', $log->ip_address );
		$this->assertSame( 'Chrome/100', $log->user_agent );
		$this->assertSame( '2026-01-12 12:00:00', $log->created_at );
	}

	/**
	 * Test from_row handles missing user_id.
	 *
	 * @return void
	 */
	public function test_from_row_handles_missing_user_id(): void {
		$row = (object) array(
			'id'          => 1,
			'action'      => 'export',
			'object_type' => 'data',
			'details'     => null,
			'created_at'  => '2026-01-12 12:00:00',
		);

		$log = ActivityLog::from_row( $row );

		$this->assertNull( $log->user_id );
	}

	/**
	 * Test from_row handles missing object_id.
	 *
	 * @return void
	 */
	public function test_from_row_handles_missing_object_id(): void {
		$row = (object) array(
			'id'          => 1,
			'user_id'     => 5,
			'action'      => 'settings_update',
			'object_type' => 'settings',
			'details'     => null,
			'created_at'  => '2026-01-12 12:00:00',
		);

		$log = ActivityLog::from_row( $row );

		$this->assertNull( $log->object_id );
	}

	/**
	 * Test from_row handles empty details string.
	 *
	 * @return void
	 */
	public function test_from_row_handles_empty_details(): void {
		$row = (object) array(
			'id'          => 1,
			'user_id'     => 5,
			'action'      => 'create',
			'object_type' => 'event',
			'details'     => '',
			'created_at'  => '2026-01-12 12:00:00',
		);

		$log = ActivityLog::from_row( $row );

		$this->assertNull( $log->details );
	}

	/**
	 * Test from_row handles null details.
	 *
	 * @return void
	 */
	public function test_from_row_handles_null_details(): void {
		$row = (object) array(
			'id'          => 1,
			'user_id'     => 5,
			'action'      => 'create',
			'object_type' => 'event',
			'details'     => null,
			'created_at'  => '2026-01-12 12:00:00',
		);

		$log = ActivityLog::from_row( $row );

		$this->assertNull( $log->details );
	}

	/**
	 * Test from_row handles invalid JSON details.
	 *
	 * @return void
	 */
	public function test_from_row_handles_invalid_json_details(): void {
		$row = (object) array(
			'id'          => 1,
			'user_id'     => 5,
			'action'      => 'create',
			'object_type' => 'event',
			'details'     => 'not valid json',
			'created_at'  => '2026-01-12 12:00:00',
		);

		$log = ActivityLog::from_row( $row );

		$this->assertNull( $log->details );
	}

	/**
	 * Test from_row handles JSON that decodes to non-array.
	 *
	 * @return void
	 */
	public function test_from_row_handles_json_non_array(): void {
		$row = (object) array(
			'id'          => 1,
			'user_id'     => 5,
			'action'      => 'create',
			'object_type' => 'event',
			'details'     => '"just a string"',
			'created_at'  => '2026-01-12 12:00:00',
		);

		$log = ActivityLog::from_row( $row );

		$this->assertNull( $log->details );
	}

	/**
	 * Test from_row parses complex JSON details.
	 *
	 * @return void
	 */
	public function test_from_row_parses_complex_json_details(): void {
		$details_array = array(
			'old_capacity' => 100,
			'new_capacity' => 150,
			'tickets_sold' => 75,
			'modified_by'  => 'admin',
		);

		$row = (object) array(
			'id'          => 1,
			'user_id'     => 5,
			'action'      => 'capacity_change',
			'object_type' => 'occurrence',
			'details'     => json_encode( $details_array ),
			'created_at'  => '2026-01-12 12:00:00',
		);

		$log = ActivityLog::from_row( $row );

		$this->assertSame( $details_array, $log->details );
	}

	// =========================================================================
	// get_description Tests
	// =========================================================================

	/**
	 * Test get_description for create action.
	 *
	 * @return void
	 */
	public function test_get_description_for_create_action(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'create',
			'event',
			100,
			'Summer Festival',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertStringContainsString( 'event', $description );
		$this->assertStringContainsString( 'Summer Festival', $description );
	}

	/**
	 * Check-in action keys must resolve to human labels.
	 *
	 * An unmapped key falls through to the raw action string, leaking an
	 * underscore into the Description column (regression guard for NTE-144).
	 *
	 * @dataProvider check_in_action_provider
	 *
	 * @param string $action   Action key written by the check-in handler.
	 * @param string $expected Expected human fragment.
	 * @return void
	 */
	public function test_get_description_maps_check_in_actions( string $action, string $expected ): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			$action,
			'attendee',
			70,
			'UX Audit',
			null,
			null,
			null,
			'2026-07-08 10:00:00'
		);

		$description = $log->get_description();

		// The description ucfirst()s the mapped label, so compare case-insensitively.
		$this->assertStringContainsStringIgnoringCase( $expected, $description );
		$this->assertStringNotContainsString( '_', $description );
	}

	/**
	 * Action keys the check-in handler emits.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function check_in_action_provider(): array {
		return array(
			'check in' => array( 'check_in', 'checked in' ),
			'undo'     => array( 'undo_check_in', 'undid check-in for' ),
		);
	}

	/**
	 * Test get_description for update action.
	 *
	 * @return void
	 */
	public function test_get_description_for_update_action(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'update',
			'occurrence',
			200,
			'Concert Night',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertStringContainsString( 'occurrence', $description );
		$this->assertStringContainsString( 'Concert Night', $description );
	}

	/**
	 * Test get_description for delete action.
	 *
	 * @return void
	 */
	public function test_get_description_for_delete_action(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'delete',
			'ticket_type',
			50,
			'VIP Pass',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertStringContainsString( 'ticket_type', $description );
		$this->assertStringContainsString( 'VIP Pass', $description );
	}

	/**
	 * Test get_description for check_in action.
	 *
	 * @return void
	 */
	public function test_get_description_for_check_in_action(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'check_in',
			'attendee',
			300,
			'John Doe',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertStringContainsString( 'attendee', $description );
		$this->assertStringContainsString( 'John Doe', $description );
	}

	/**
	 * Test get_description for settings_update returns only label.
	 *
	 * @return void
	 */
	public function test_get_description_for_settings_update(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'settings_update',
			'settings',
			null,
			null,
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertEquals( 'updated settings', $description );
	}

	/**
	 * Test get_description uses object_id when object_name is null.
	 *
	 * @return void
	 */
	public function test_get_description_uses_object_id_fallback(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'update',
			'event',
			123,
			null,
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertStringContainsString( '#123', $description );
	}

	/**
	 * Test get_description for unknown action.
	 *
	 * @return void
	 */
	public function test_get_description_for_unknown_action(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'custom_action',
			'widget',
			99,
			'My Widget',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertStringContainsString( 'Custom_action', $description );
		$this->assertStringContainsString( 'widget', $description );
		$this->assertStringContainsString( 'My Widget', $description );
	}

	/**
	 * Test get_description for publish action.
	 *
	 * @return void
	 */
	public function test_get_description_for_publish_action(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'publish',
			'event',
			100,
			'Spring Gala',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertStringContainsString( 'event', $description );
		$this->assertStringContainsString( 'Spring Gala', $description );
	}

	/**
	 * Test get_description for cancel action.
	 *
	 * @return void
	 */
	public function test_get_description_for_cancel_action(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'cancel',
			'attendee',
			500,
			'Jane Smith',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertStringContainsString( 'attendee', $description );
		$this->assertStringContainsString( 'Jane Smith', $description );
	}

	/**
	 * Test get_description for capacity_change action.
	 *
	 * @return void
	 */
	public function test_get_description_for_capacity_change_action(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			5,
			'capacity_change',
			'occurrence',
			200,
			'Main Event',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$description = $log->get_description();

		$this->assertStringContainsString( 'occurrence', $description );
		$this->assertStringContainsString( 'Main Event', $description );
	}

	// =========================================================================
	// get_username Tests
	// =========================================================================

	/**
	 * Test get_username returns System for null user_id.
	 *
	 * @return void
	 */
	public function test_get_username_returns_system_for_null_user(): void {
		Functions\when( '__' )->returnArg();

		$log = new ActivityLog(
			1,
			null,
			'export',
			'data',
			null,
			null,
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$this->assertEquals( 'System', $log->get_username() );
	}

	/**
	 * Test get_username returns display name for valid user.
	 *
	 * @return void
	 */
	public function test_get_username_returns_display_name(): void {
		$mock_user               = new \stdClass();
		$mock_user->display_name = 'John Admin';

		Functions\when( 'get_userdata' )->justReturn( $mock_user );

		$log = new ActivityLog(
			1,
			42,
			'update',
			'event',
			100,
			'Test Event',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$this->assertEquals( 'John Admin', $log->get_username() );
	}

	/**
	 * Test get_username returns deleted user message for invalid user.
	 *
	 * @return void
	 */
	public function test_get_username_returns_deleted_user_message(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_userdata' )->justReturn( false );

		$log = new ActivityLog(
			1,
			999,
			'update',
			'event',
			100,
			'Test Event',
			null,
			null,
			null,
			'2026-01-12 10:00:00'
		);

		$username = $log->get_username();

		$this->assertStringContainsString( '999', $username );
		$this->assertStringContainsString( 'deleted', $username );
	}

	// =========================================================================
	// get_formatted_time Tests
	// =========================================================================

	/**
	 * Test get_formatted_time uses custom format.
	 *
	 * @return void
	 */
	public function test_get_formatted_time_uses_custom_format(): void {
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);

		$log = new ActivityLog(
			1,
			5,
			'create',
			'event',
			100,
			'Test Event',
			null,
			null,
			null,
			'2026-01-12 10:30:00'
		);

		$formatted = $log->get_formatted_time( 'Y-m-d' );

		$this->assertEquals( '2026-01-12', $formatted );
	}

	/**
	 * Test get_formatted_time uses WordPress options when format empty.
	 *
	 * @return void
	 */
	public function test_get_formatted_time_uses_wp_options(): void {
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'date_format' === $option ) {
					return 'F j, Y';
				}
				if ( 'time_format' === $option ) {
					return 'g:i a';
				}
				return '';
			}
		);
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);

		$log = new ActivityLog(
			1,
			5,
			'create',
			'event',
			100,
			'Test Event',
			null,
			null,
			null,
			'2026-01-12 10:30:00'
		);

		$formatted = $log->get_formatted_time();

		$this->assertStringContainsString( 'January', $formatted );
		$this->assertStringContainsString( '2026', $formatted );
	}

	/**
	 * Test get_formatted_time handles different timestamps.
	 *
	 * @return void
	 */
	public function test_get_formatted_time_handles_different_timestamps(): void {
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);

		$log = new ActivityLog(
			1,
			5,
			'create',
			'event',
			100,
			'Test Event',
			null,
			null,
			null,
			'2025-06-15 14:45:30'
		);

		$formatted = $log->get_formatted_time( 'H:i:s' );

		$this->assertEquals( '14:45:30', $formatted );
	}
}
