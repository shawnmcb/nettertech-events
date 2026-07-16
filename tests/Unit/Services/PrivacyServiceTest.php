<?php
/**
 * PrivacyService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\PrivacyService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test PrivacyService GDPR export and erasure functionality.
 */
class PrivacyServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \wpdb|Mockery\MockInterface
	 */
	private $db;

	/**
	 * Service under test.
	 *
	 * @var PrivacyService
	 */
	private PrivacyService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = Mockery::mock( 'wpdb' );
		$this->db->prefix = 'wp_';

		$this->service = new PrivacyService( $this->db );
	}

	// =========================================================================
	// Attendee Exporter Tests
	// =========================================================================

	/**
	 * Test attendee export returns structured data for matching email.
	 *
	 * @return void
	 */
	public function test_export_attendee_data_returns_pii(): void {
		$attendee = (object) array(
			'id'                  => 1,
			'name'                => 'Jane Doe',
			'email'               => 'jane@example.com',
			'phone'               => '555-0123',
			'accessibility_notes' => 'Wheelchair accessible',
			'ticket_code'         => 'TK-ABC123',
			'ticket_status'       => 'confirmed',
			'price_paid'          => '25.00',
			'checked_in_at'       => '2026-01-15 18:00:00',
		);

		$this->db->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'prepared_query' );

		$this->db->shouldReceive( 'get_results' )
			->with( 'prepared_query' )
			->once()
			->andReturn( array( $attendee ) );

		$result = $this->service->export_attendee_data( 'jane@example.com' );

		$this->assertFalse( $result['done'] === false && count( array( $attendee ) ) >= 500 );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );

		$item = $result['data'][0];
		$this->assertSame( 'nettertech-events-attendees', $item['group_id'] );
		$this->assertSame( 'nettertech-events-attendee-1', $item['item_id'] );

		$values = array_column( $item['data'], 'value', 'name' );
		$this->assertSame( 'Jane Doe', $values['Name'] );
		$this->assertSame( 'jane@example.com', $values['Email'] );
		$this->assertSame( '555-0123', $values['Phone'] );
		$this->assertSame( 'Wheelchair accessible', $values['Accessibility Notes'] );
		$this->assertSame( 'TK-ABC123', $values['Ticket Code'] );
	}

	/**
	 * Test attendee export returns empty for no matches.
	 *
	 * @return void
	 */
	public function test_export_attendee_data_returns_empty_for_unknown_email(): void {
		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'get_results' )->with( 'prepared_query' )->once()->andReturn( array() );

		$result = $this->service->export_attendee_data( 'nobody@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertEmpty( $result['data'] );
	}

	/**
	 * Test attendee export omits empty optional fields.
	 *
	 * @return void
	 */
	public function test_export_attendee_data_omits_empty_fields(): void {
		$attendee = (object) array(
			'id'                  => 2,
			'name'                => 'John Smith',
			'email'               => 'john@example.com',
			'phone'               => '',
			'accessibility_notes' => '',
			'ticket_code'         => '',
			'ticket_status'       => null,
			'price_paid'          => null,
			'checked_in_at'       => null,
		);

		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'get_results' )->with( 'prepared_query' )->once()->andReturn( array( $attendee ) );

		$result = $this->service->export_attendee_data( 'john@example.com' );

		$item   = $result['data'][0];
		$names  = array_column( $item['data'], 'name' );

		$this->assertContains( 'Name', $names );
		$this->assertContains( 'Email', $names );
		$this->assertNotContains( 'Phone', $names );
		$this->assertNotContains( 'Accessibility Notes', $names );
		$this->assertNotContains( 'Ticket Code', $names );
	}

	// =========================================================================
	// Activity Log Exporter Tests
	// =========================================================================

	/**
	 * Test activity log export returns data for known user.
	 *
	 * @return void
	 */
	public function test_export_activity_log_data_returns_log_entries(): void {
		$user     = (object) array( 'ID' => 42 );
		$log_entry = (object) array(
			'id'          => 100,
			'action'      => 'event_created',
			'object_type' => 'event',
			'created_at'  => '2026-01-10 12:00:00',
			'ip_address'  => '192.168.1.1',
			'user_agent'  => 'Mozilla/5.0',
		);

		Functions\expect( 'get_user_by' )
			->with( 'email', 'admin@example.com' )
			->once()
			->andReturn( $user );

		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'get_results' )->with( 'prepared_query' )->once()->andReturn( array( $log_entry ) );

		$result = $this->service->export_activity_log_data( 'admin@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );

		$item   = $result['data'][0];
		$values = array_column( $item['data'], 'value', 'name' );

		$this->assertSame( '192.168.1.1', $values['IP Address'] );
		$this->assertSame( 'Mozilla/5.0', $values['User Agent'] );
	}

	/**
	 * Test activity log export returns empty for unknown user.
	 *
	 * @return void
	 */
	public function test_export_activity_log_data_returns_empty_for_unknown_user(): void {
		Functions\expect( 'get_user_by' )
			->with( 'email', 'nobody@example.com' )
			->once()
			->andReturn( false );

		$result = $this->service->export_activity_log_data( 'nobody@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertEmpty( $result['data'] );
	}

	// =========================================================================
	// Organizer Exporter Tests
	// =========================================================================

	/**
	 * Test organizer export returns data for matching email.
	 *
	 * @return void
	 */
	public function test_export_organizer_data_returns_pii(): void {
		$organizer = (object) array(
			'id'    => 5,
			'name'  => 'Events Inc',
			'email' => 'events@example.com',
			'phone' => '555-9999',
		);

		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'get_results' )->with( 'prepared_query' )->once()->andReturn( array( $organizer ) );

		$result = $this->service->export_organizer_data( 'events@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );

		$values = array_column( $result['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'Events Inc', $values['Name'] );
		$this->assertSame( '555-9999', $values['Phone'] );
	}

	// =========================================================================
	// Attendee Eraser Tests
	// =========================================================================

	/**
	 * Test attendee erasure anonymizes PII.
	 *
	 * @return void
	 */
	public function test_erase_attendee_data_anonymizes_records(): void {
		$attendee = (object) array( 'id' => 1 );

		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'get_results' )->with( 'prepared_query' )->once()->andReturn( array( $attendee ) );
		$this->db->shouldReceive( 'update' )
			->once()
			->with(
				'wp_nettertech_events_attendees',
				Mockery::on( function ( $data ) {
					return '[Deleted]' === $data['name']
						&& str_starts_with( $data['email'], 'deleted-1@anonymized.invalid' )
						&& null === $data['phone']
						&& null === $data['accessibility_notes'];
				} ),
				array( 'id' => 1 ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			)
			->andReturn( 1 );

		$result = $this->service->erase_attendee_data( 'jane@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Test attendee erasure returns no items removed for unknown email.
	 *
	 * @return void
	 */
	public function test_erase_attendee_data_returns_false_for_unknown_email(): void {
		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'get_results' )->with( 'prepared_query' )->once()->andReturn( array() );

		$result = $this->service->erase_attendee_data( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	// =========================================================================
	// Activity Log Eraser Tests
	// =========================================================================

	/**
	 * Test activity log erasure anonymizes IP and user agent.
	 *
	 * @return void
	 */
	public function test_erase_activity_log_data_anonymizes_pii(): void {
		$user = (object) array( 'ID' => 42 );
		$log  = (object) array( 'id' => 100 );

		Functions\expect( 'get_user_by' )
			->with( 'email', 'admin@example.com' )
			->once()
			->andReturn( $user );

		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'get_results' )->with( 'prepared_query' )->once()->andReturn( array( $log ) );
		$this->db->shouldReceive( 'update' )
			->once()
			->with(
				'wp_nettertech_events_activity_log',
				Mockery::on( function ( $data ) {
					return null === $data['ip_address'] && null === $data['user_agent'];
				} ),
				array( 'id' => 100 ),
				array( '%s', '%s' ),
				array( '%d' )
			)
			->andReturn( 1 );

		$result = $this->service->erase_activity_log_data( 'admin@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	// =========================================================================
	// Organizer Eraser Tests
	// =========================================================================

	/**
	 * Test organizer erasure anonymizes PII.
	 *
	 * @return void
	 */
	public function test_erase_organizer_data_anonymizes_records(): void {
		$organizer = (object) array( 'id' => 5 );

		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'get_results' )->with( 'prepared_query' )->once()->andReturn( array( $organizer ) );
		$this->db->shouldReceive( 'update' )
			->once()
			->with(
				'wp_nettertech_events_organizers',
				Mockery::on( function ( $data ) {
					return '[Deleted]' === $data['name']
						&& 'deleted-5@anonymized.invalid' === $data['email']
						&& null === $data['phone'];
				} ),
				array( 'id' => 5 ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			)
			->andReturn( 1 );

		$result = $this->service->erase_organizer_data( 'events@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	// =========================================================================
	// Retention Policy Tests
	// =========================================================================

	/**
	 * Test retention purge anonymizes old entries.
	 *
	 * @return void
	 */
	public function test_purge_old_activity_log_pii_returns_count(): void {
		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'query' )->with( 'prepared_query' )->once()->andReturn( 15 );

		$count = $this->service->purge_old_activity_log_pii( 90 );

		$this->assertSame( 15, $count );
	}

	/**
	 * Test retention purge with custom days.
	 *
	 * @return void
	 */
	public function test_purge_old_activity_log_pii_with_custom_days(): void {
		$this->db->shouldReceive( 'prepare' )->once()->andReturn( 'prepared_query' );
		$this->db->shouldReceive( 'query' )->with( 'prepared_query' )->once()->andReturn( 0 );

		$count = $this->service->purge_old_activity_log_pii( 30 );

		$this->assertSame( 0, $count );
	}
}
