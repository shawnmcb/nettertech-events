<?php
/**
 * Privacy Service for GDPR compliance.
 *
 * Implements WordPress Privacy Tools (data exporters and erasers)
 * for all PII-bearing tables in the NetterTech Events plugin.
 *
 * @package NetterTechEvents\Services
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Database\Queries\Timeline;
use NetterTechEvents\Database\Schema;

/**
 * Handles personal data export and erasure for GDPR compliance.
 *
 * Registers with WordPress Privacy Tools to enable admins to export
 * and erase attendee PII via Tools → Export/Erase Personal Data.
 */
class PrivacyService {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Batch size for paginated privacy operations.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	// =========================================================================
	// Exporters
	// =========================================================================

	/**
	 * Export attendee personal data.
	 *
	 * Exports name, email, phone, and accessibility notes for attendees
	 * matching the requested email address.
	 *
	 * @param string $email_address Email address to export data for.
	 * @param int    $page          Page number for pagination.
	 * @return array{data: array<int, array<string, mixed>>, done: bool} Export data array.
	 */
	public function export_attendee_data( string $email_address, int $page = 1 ): array {
		$table         = Schema::table( 'attendees' );
		$tickets_table = Schema::table( 'tickets' );
		$offset        = ( $page - 1 ) * self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export, no caching needed.
		$attendees = $this->db->get_results(
			$this->db->prepare(
				"SELECT a.*, t.ticket_code, t.status AS ticket_status, t.price_paid, t.checked_in_at
				FROM {$table} a
				LEFT JOIN {$tickets_table} t ON t.attendee_id = a.id
				WHERE a.email = %s
				ORDER BY a.id ASC
				LIMIT %d OFFSET %d",
				$email_address,
				self::BATCH_SIZE,
				$offset
			)
		) ?? array();

		$export_items = array();

		foreach ( (array) $attendees as $attendee ) {
			$data = array(
				array(
					'name'  => __( 'Name', 'nettertech-events' ),
					'value' => $attendee->name,
				),
				array(
					'name'  => __( 'Email', 'nettertech-events' ),
					'value' => $attendee->email,
				),
			);

			if ( ! empty( $attendee->phone ) ) {
				$data[] = array(
					'name'  => __( 'Phone', 'nettertech-events' ),
					'value' => $attendee->phone,
				);
			}

			if ( ! empty( $attendee->notes ) ) {
				$data[] = array(
					'name'  => __( 'Order Notes', 'nettertech-events' ),
					'value' => $attendee->notes,
				);
			}

			if ( ! empty( $attendee->accessibility_notes ) ) {
				$data[] = array(
					'name'  => __( 'Accessibility Notes', 'nettertech-events' ),
					'value' => $attendee->accessibility_notes,
				);
			}

			if ( ! empty( $attendee->ticket_code ) ) {
				$data[] = array(
					'name'  => __( 'Ticket Code', 'nettertech-events' ),
					'value' => $attendee->ticket_code,
				);
				$data[] = array(
					'name'  => __( 'Ticket Status', 'nettertech-events' ),
					'value' => $attendee->ticket_status ?? '',
				);
				$data[] = array(
					'name'  => __( 'Price Paid', 'nettertech-events' ),
					'value' => $attendee->price_paid ?? '',
				);

				if ( ! empty( $attendee->checked_in_at ) ) {
					$data[] = array(
						'name'  => __( 'Checked In At', 'nettertech-events' ),
						'value' => $attendee->checked_in_at,
					);
				}
			}

			$export_items[] = array(
				'group_id'          => 'nettertech-events-attendees',
				'group_label'       => __( 'Event Attendance', 'nettertech-events' ),
				'group_description' => __( 'Event attendance and ticket records from NetterTech Events.', 'nettertech-events' ),
				'item_id'           => "nettertech-events-attendee-{$attendee->id}",
				'data'              => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $attendees ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Export activity log personal data.
	 *
	 * Exports IP address, user agent, and action details for logs
	 * associated with the user matching the given email.
	 *
	 * @param string $email_address Email address to export data for.
	 * @param int    $page          Page number for pagination.
	 * @return array{data: array<int, array<string, mixed>>, done: bool} Export data array.
	 */
	public function export_activity_log_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$table  = Schema::table( 'activity_log' );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export.
		$logs = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table}
				WHERE user_id = %d
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$user->ID,
				self::BATCH_SIZE,
				$offset
			)
		) ?? array();

		$export_items = array();

		foreach ( (array) $logs as $log ) {
			$data = array(
				array(
					'name'  => __( 'Action', 'nettertech-events' ),
					'value' => $log->action ?? '',
				),
				array(
					'name'  => __( 'Object Type', 'nettertech-events' ),
					'value' => $log->object_type ?? '',
				),
				array(
					'name'  => __( 'Date', 'nettertech-events' ),
					'value' => $log->created_at ?? '',
				),
			);

			if ( ! empty( $log->ip_address ) ) {
				$data[] = array(
					'name'  => __( 'IP Address', 'nettertech-events' ),
					'value' => $log->ip_address,
				);
			}

			if ( ! empty( $log->user_agent ) ) {
				$data[] = array(
					'name'  => __( 'User Agent', 'nettertech-events' ),
					'value' => $log->user_agent,
				);
			}

			$export_items[] = array(
				'group_id'          => 'nettertech-events-activity-log',
				'group_label'       => __( 'Event Activity Log', 'nettertech-events' ),
				'group_description' => __( 'Activity log entries from NetterTech Events.', 'nettertech-events' ),
				'item_id'           => "nettertech-events-log-{$log->id}",
				'data'              => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $logs ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Export organizer personal data.
	 *
	 * Exports name, email, phone for organizers matching the email.
	 *
	 * @param string $email_address Email address to export data for.
	 * @param int    $page          Page number for pagination.
	 * @return array{data: array<int, array<string, mixed>>, done: bool} Export data array.
	 */
	public function export_organizer_data( string $email_address, int $page = 1 ): array {
		$table  = Schema::table( 'organizers' );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export.
		$organizers = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table}
				WHERE email = %s
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$email_address,
				self::BATCH_SIZE,
				$offset
			)
		) ?? array();

		$export_items = array();

		foreach ( (array) $organizers as $organizer ) {
			$data = array(
				array(
					'name'  => __( 'Name', 'nettertech-events' ),
					'value' => $organizer->name ?? '',
				),
				array(
					'name'  => __( 'Email', 'nettertech-events' ),
					'value' => $organizer->email ?? '',
				),
			);

			if ( ! empty( $organizer->phone ) ) {
				$data[] = array(
					'name'  => __( 'Phone', 'nettertech-events' ),
					'value' => $organizer->phone,
				);
			}

			$export_items[] = array(
				'group_id'          => 'nettertech-events-organizers',
				'group_label'       => __( 'Event Organizers', 'nettertech-events' ),
				'group_description' => __( 'Organizer records from NetterTech Events.', 'nettertech-events' ),
				'item_id'           => "nettertech-events-organizer-{$organizer->id}",
				'data'              => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $organizers ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Export waitlist personal data.
	 *
	 * Exports name, email, phone, and waitlist status for entries
	 * matching the requested email address.
	 *
	 * @param string $email_address Email address to export data for.
	 * @param int    $page          Page number for pagination.
	 * @return array{data: array<int, array<string, mixed>>, done: bool} Export data array.
	 */
	public function export_waitlist_data( string $email_address, int $page = 1 ): array {
		$table  = Schema::table( 'waitlist' );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export, no caching needed.
		$entries = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table}
				WHERE email = %s
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$email_address,
				self::BATCH_SIZE,
				$offset
			)
		) ?? array();

		$export_items = array();

		foreach ( (array) $entries as $entry ) {
			$data = array(
				array(
					'name'  => __( 'Name', 'nettertech-events' ),
					'value' => $entry->name,
				),
				array(
					'name'  => __( 'Email', 'nettertech-events' ),
					'value' => $entry->email,
				),
				array(
					'name'  => __( 'Status', 'nettertech-events' ),
					'value' => $entry->status ?? '',
				),
				array(
					'name'  => __( 'Position', 'nettertech-events' ),
					'value' => $entry->position ?? '',
				),
			);

			if ( ! empty( $entry->phone ) ) {
				$data[] = array(
					'name'  => __( 'Phone', 'nettertech-events' ),
					'value' => $entry->phone,
				);
			}

			if ( ! empty( $entry->notified_at ) ) {
				$data[] = array(
					'name'  => __( 'Notified At', 'nettertech-events' ),
					'value' => $entry->notified_at,
				);
			}

			if ( ! empty( $entry->created_at ) ) {
				$data[] = array(
					'name'  => __( 'Joined Waitlist', 'nettertech-events' ),
					'value' => $entry->created_at,
				);
			}

			$export_items[] = array(
				'group_id'          => 'nettertech-events-waitlist',
				'group_label'       => __( 'Event Waitlist', 'nettertech-events' ),
				'group_description' => __( 'Waitlist entries from NetterTech Events.', 'nettertech-events' ),
				'item_id'           => "nettertech-events-waitlist-{$entry->id}",
				'data'              => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $entries ) < self::BATCH_SIZE,
		);
	}

	// =========================================================================
	// Erasers
	// =========================================================================

	/**
	 * Erase attendee personal data.
	 *
	 * Anonymizes attendee PII (name, email, phone, notes, accessibility_notes).
	 * Tickets reference attendees via FK only — no standalone PII to erase.
	 *
	 * @param string $email_address Email address to erase data for.
	 * @param int    $page          Page number for pagination.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase_attendee_data( string $email_address, int $page = 1 ): array {
		$table  = Schema::table( 'attendees' );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure.
		$attendees = $this->db->get_results(
			$this->db->prepare(
				"SELECT id FROM {$table}
				WHERE email = %s
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$email_address,
				self::BATCH_SIZE,
				$offset
			)
		) ?? array();

		$items_removed = false;

		foreach ( (array) $attendees as $attendee ) {
			// Anonymize attendee PII.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure.
			$this->db->update(
				$table,
				array(
					'name'                => __( '[Deleted]', 'nettertech-events' ),
					'email'               => 'deleted-' . $attendee->id . '@anonymized.invalid',
					'phone'               => null,
					'notes'               => null,
					'accessibility_notes' => null,
				),
				array( 'id' => $attendee->id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			$items_removed = true;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $attendees ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Erase activity log personal data.
	 *
	 * Anonymizes IP address and user agent for log entries
	 * associated with the user matching the given email.
	 *
	 * @param string $email_address Email address to erase data for.
	 * @param int    $page          Page number for pagination.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase_activity_log_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$table  = Schema::table( 'activity_log' );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure.
		$logs = $this->db->get_results(
			$this->db->prepare(
				"SELECT id FROM {$table}
				WHERE user_id = %d
				AND (ip_address IS NOT NULL OR user_agent IS NOT NULL)
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$user->ID,
				self::BATCH_SIZE,
				$offset
			)
		) ?? array();

		$items_removed = false;

		foreach ( (array) $logs as $log ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure.
			$this->db->update(
				$table,
				array(
					'ip_address' => null,
					'user_agent' => null,
				),
				array( 'id' => $log->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$items_removed = true;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $logs ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Erase organizer personal data.
	 *
	 * Anonymizes name, email, and phone for organizers matching the email.
	 *
	 * @param string $email_address Email address to erase data for.
	 * @param int    $page          Page number for pagination.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase_organizer_data( string $email_address, int $page = 1 ): array {
		$table  = Schema::table( 'organizers' );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure.
		$organizers = $this->db->get_results(
			$this->db->prepare(
				"SELECT id FROM {$table}
				WHERE email = %s
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$email_address,
				self::BATCH_SIZE,
				$offset
			)
		) ?? array();

		$items_removed = false;

		foreach ( (array) $organizers as $organizer ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure.
			$this->db->update(
				$table,
				array(
					'name'  => __( '[Deleted]', 'nettertech-events' ),
					'email' => 'deleted-' . $organizer->id . '@anonymized.invalid',
					'phone' => null,
				),
				array( 'id' => $organizer->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			$items_removed = true;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $organizers ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Erase waitlist personal data.
	 *
	 * Anonymizes waitlist PII (name, email, phone) for entries
	 * matching the requested email address.
	 *
	 * @param string $email_address Email address to erase data for.
	 * @param int    $page          Page number for pagination.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase_waitlist_data( string $email_address, int $page = 1 ): array {
		$table  = Schema::table( 'waitlist' );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure.
		$entries = $this->db->get_results(
			$this->db->prepare(
				"SELECT id FROM {$table}
				WHERE email = %s
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$email_address,
				self::BATCH_SIZE,
				$offset
			)
		) ?? array();

		$items_removed = false;

		foreach ( (array) $entries as $entry ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure.
			$this->db->update(
				$table,
				array(
					'name'  => __( '[Deleted]', 'nettertech-events' ),
					'email' => 'deleted-' . $entry->id . '@anonymized.invalid',
					'phone' => null,
				),
				array( 'id' => $entry->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			$items_removed = true;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $entries ) < self::BATCH_SIZE,
		);
	}

	// =========================================================================
	// Retention Policy
	// =========================================================================

	/**
	 * Purge PII from activity log entries older than the retention period.
	 *
	 * Anonymizes ip_address and user_agent fields for entries older
	 * than the specified number of days.
	 *
	 * @param int $days_to_keep Number of days to retain PII (default: 90).
	 * @return int Number of entries anonymized.
	 */
	public function purge_old_activity_log_pii( int $days_to_keep = 90 ): int {
		$table = Schema::table( 'activity_log' );

		$sql = $this->db->prepare(
			"UPDATE {$table}
			SET ip_address = NULL, user_agent = NULL
			WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
			AND (ip_address IS NOT NULL OR user_agent IS NOT NULL)",
			$days_to_keep
		);
		if ( null === $sql ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Scheduled retention cleanup; query prepared above.
		return (int) $this->db->query( $sql );
	}

	/**
	 * Clear accessibility notes on attendees whose occurrence ended long enough ago.
	 *
	 * Accessibility needs are special-category data collected for one purpose —
	 * arranging accommodations at the event. Once the event is over (plus a
	 * grace window for follow-up), the purpose is spent and the text is set to
	 * NULL. The attendee record itself stays. Compares the occurrence's stored
	 * UTC end instant (Timeline) — never the site wall-clock — and leaves
	 * unstamped rows alone, as every other "is it over" query does.
	 *
	 * @param int $days_after_end Days to keep notes after the occurrence ends (default: 30).
	 * @return int Number of attendee rows cleared.
	 */
	public function purge_aged_accessibility_notes( int $days_after_end = 30 ): int {
		$attendees   = Schema::table( 'attendees' );
		$occurrences = Schema::table( 'occurrences' );
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - ( max( 0, $days_after_end ) * DAY_IN_SECONDS ) );

		$sql = $this->db->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from Schema; predicate is a Timeline literal.
			"UPDATE {$attendees} a
			INNER JOIN {$occurrences} o ON o.id = a.occurrence_id
			SET a.accessibility_notes = NULL
			WHERE " . Timeline::ended() . ' AND a.accessibility_notes IS NOT NULL',
			$cutoff
		);
		if ( null === $sql ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Scheduled retention cleanup; query prepared above.
		return (int) $this->db->query( $sql );
	}
}
