<?php
/**
 * Activity Log model.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents an activity log entry for OWASP A09 compliance.
 *
 * Tracks administrative actions such as:
 * - Event create/update/delete/publish
 * - Occurrence modifications
 * - Ticket type changes
 * - Attendee check-ins and cancellations
 * - Settings updates
 * - Data exports
 *
 * @since 0.9.0
 */
class ActivityLog {

	/**
	 * Log entry ID.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * WordPress user ID who performed the action.
	 *
	 * @var int|null
	 */
	public ?int $user_id;

	/**
	 * Action performed (e.g., 'create', 'update', 'delete', 'check_in').
	 *
	 * @var string
	 */
	public string $action;

	/**
	 * Type of object affected (e.g., 'event', 'occurrence', 'attendee').
	 *
	 * @var string
	 */
	public string $object_type;

	/**
	 * ID of the affected object.
	 *
	 * @var int|null
	 */
	public ?int $object_id;

	/**
	 * Human-readable name of the affected object.
	 *
	 * @var string|null
	 */
	public ?string $object_name;

	/**
	 * Additional details as JSON.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $details;

	/**
	 * IP address of the user.
	 *
	 * @var string|null
	 */
	public ?string $ip_address;

	/**
	 * User agent string.
	 *
	 * @var string|null
	 */
	public ?string $user_agent;

	/**
	 * Timestamp when the action occurred.
	 *
	 * @var string
	 */
	public string $created_at;

	/**
	 * Create a new ActivityLog instance.
	 *
	 * @param int                       $id          Log entry ID.
	 * @param int|null                  $user_id     User who performed the action.
	 * @param string                    $action      Action performed.
	 * @param string                    $object_type Type of object affected.
	 * @param int|null                  $object_id   ID of affected object.
	 * @param string|null               $object_name Name of affected object.
	 * @param array<string, mixed>|null $details     Additional details.
	 * @param string|null               $ip_address  User's IP address.
	 * @param string|null               $user_agent  User's browser agent.
	 * @param string                    $created_at  When action occurred.
	 */
	public function __construct(
		int $id,
		?int $user_id,
		string $action,
		string $object_type,
		?int $object_id,
		?string $object_name,
		?array $details,
		?string $ip_address,
		?string $user_agent,
		string $created_at
	) {
		$this->id          = $id;
		$this->user_id     = $user_id;
		$this->action      = $action;
		$this->object_type = $object_type;
		$this->object_id   = $object_id;
		$this->object_name = $object_name;
		$this->details     = $details;
		$this->ip_address  = $ip_address;
		$this->user_agent  = $user_agent;
		$this->created_at  = $created_at;
	}

	/**
	 * Create an ActivityLog from a database row.
	 *
	 * @param \stdClass $row Database row object (raw wpdb row; optional
	 *                       columns guarded with isset()/?? below).
	 * @return self
	 */
	public static function from_row( \stdClass $row ): self {
		$details = null;
		if ( ! empty( $row->details ) ) {
			$decoded = json_decode( $row->details, true );
			if ( is_array( $decoded ) ) {
				$details = $decoded;
			}
		}

		return new self(
			(int) $row->id,
			isset( $row->user_id ) ? (int) $row->user_id : null,
			$row->action,
			$row->object_type,
			isset( $row->object_id ) ? (int) $row->object_id : null,
			$row->object_name ?? null,
			$details,
			$row->ip_address ?? null,
			$row->user_agent ?? null,
			$row->created_at
		);
	}

	/**
	 * Get a human-readable description of the action.
	 *
	 * @return string
	 */
	public function get_description(): string {
		$action_labels = array(
			'create'          => __( 'created', 'nettertech-events' ),
			'update'          => __( 'updated', 'nettertech-events' ),
			'delete'          => __( 'deleted', 'nettertech-events' ),
			'publish'         => __( 'published', 'nettertech-events' ),
			'unpublish'       => __( 'unpublished', 'nettertech-events' ),
			'check_in'        => __( 'checked in', 'nettertech-events' ),
			'undo_check_in'   => __( 'undid check-in for', 'nettertech-events' ),
			'cancel'          => __( 'cancelled', 'nettertech-events' ),
			'refund'          => __( 'refunded', 'nettertech-events' ),
			'export'          => __( 'exported', 'nettertech-events' ),
			'capacity_change' => __( 'changed capacity for', 'nettertech-events' ),
			'price_change'    => __( 'changed price for', 'nettertech-events' ),
			'settings_update' => __( 'updated settings', 'nettertech-events' ),
		);

		$action_label = $action_labels[ $this->action ] ?? $this->action;
		$object_name  = $this->object_name ?? "#{$this->object_id}";

		if ( 'settings_update' === $this->action ) {
			return $action_label;
		}

		return sprintf(
			/* translators: 1: action label, 2: object type, 3: object name */
			__( '%1$s %2$s "%3$s"', 'nettertech-events' ),
			ucfirst( $action_label ),
			$this->object_type,
			$object_name
		);
	}

	/**
	 * Get the username of the user who performed the action.
	 *
	 * @return string
	 */
	public function get_username(): string {
		if ( null === $this->user_id ) {
			return __( 'System', 'nettertech-events' );
		}

		$user = get_userdata( $this->user_id );
		if ( ! $user ) {
			/* translators: %d: user ID number */
			return sprintf( __( 'User #%d (deleted)', 'nettertech-events' ), $this->user_id );
		}

		return $user->display_name;
	}

	/**
	 * Get formatted timestamp.
	 *
	 * @param string $format Date format (default: WordPress date + time format).
	 * @return string
	 */
	public function get_formatted_time( string $format = '' ): string {
		if ( empty( $format ) ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}

		$timestamp = strtotime( $this->created_at );
		if ( false === $timestamp ) {
			return $this->created_at;
		}
		return (string) wp_date( $format, $timestamp );
	}
}
