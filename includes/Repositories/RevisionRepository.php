<?php
/**
 * Revision Repository.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);


namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Database\Schema;

/**
 * Handles database operations for event revisions.
 *
 * @since 1.5.0
 *
 * @phpstan-import-type RevisionRow from \NetterTechEvents\Contracts\RevisionRepositoryInterface
 */
class RevisionRepository implements RevisionRepositoryInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db WordPress database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Insert a new revision.
	 *
	 * @param int                  $event_id       Event ID.
	 * @param int                  $user_id        User ID.
	 * @param array<string, mixed> $revision_data Snapshot data.
	 * @param string               $change_summary Human-readable summary of changes.
	 * @return int|false Inserted revision ID, or false on failure.
	 */
	public function insert( int $event_id, int $user_id, array $revision_data, string $change_summary ) {
		$table = Schema::table( 'event_revisions' );

		$this->db->insert(
			$table,
			array(
				'event_id'       => $event_id,
				'user_id'        => $user_id,
				'revision_data'  => wp_json_encode( $revision_data ),
				'change_summary' => mb_substr( $change_summary, 0, 500 ),
				// Store created_at explicitly in UTC (NTE-131) rather than relying on the
				// MySQL CURRENT_TIMESTAMP default, which is written in the environment-
				// dependent DB session timezone. The revision metabox renders this via
				// human_time_diff() against time() (both UTC), so UTC storage is correct.
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return $this->db->insert_id ? (int) $this->db->insert_id : false;
	}

	/**
	 * Get revisions for an event, newest first.
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Maximum number of revisions to return.
	 * @return array<object>
	 * @phpstan-return array<RevisionRow>
	 */
	public function for_event( int $event_id, int $limit = 10 ): array {
		$table = Schema::table( 'event_revisions' );

		/**
		 * Raw event_revisions rows; columns per the RevisionRow contract.
		 *
		 * @var array<RevisionRow>|null $rows
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at DESC LIMIT %d", $event_id, $limit ) );

		return $rows ?? array();
	}

	/**
	 * Find a single revision by ID.
	 *
	 * @param int $revision_id Revision ID.
	 * @return object|null
	 * @phpstan-return RevisionRow|null
	 */
	public function find( int $revision_id ): ?object {
		$table = Schema::table( 'event_revisions' );

		/**
		 * Raw event_revisions row; columns per the RevisionRow contract.
		 *
		 * @var RevisionRow|null $row
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $revision_id ) );

		return $row ? $row : null;
	}

	/**
	 * Prune old revisions beyond the max limit.
	 *
	 * Keeps the newest $max_revisions and deletes older ones.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $max_revisions Maximum revisions to keep.
	 * @return void
	 */
	public function prune( int $event_id, int $max_revisions ): void {
		$table = Schema::table( 'event_revisions' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source.
		$cutoff_id = $this->db->get_var(
			$this->db->prepare(
				"SELECT id FROM {$table} WHERE event_id = %d ORDER BY created_at DESC LIMIT 1 OFFSET %d",
				$event_id,
				$max_revisions
			)
		);

		if ( $cutoff_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source.
			$sql = $this->db->prepare(
				"DELETE FROM {$table} WHERE event_id = %d AND id <= %d",
				$event_id,
				(int) $cutoff_id
			);
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
				$this->db->query( $sql );
			}
		}
	}

	/**
	 * Delete all revisions for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public function delete_for_event( int $event_id ): void {
		$table = Schema::table( 'event_revisions' );

		$this->db->delete( $table, array( 'event_id' => $event_id ), array( '%d' ) );
	}

	/**
	 * Count revisions for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	public function count_for_event( int $event_id ): int {
		$table = Schema::table( 'event_revisions' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source.
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $event_id ) );
	}
}
