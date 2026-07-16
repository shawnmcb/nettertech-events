<?php
/**
 * Revision Repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for event revision repository implementations.
 *
 * Revision rows are raw wpdb rows; the RevisionRow shape documents the
 * event_revisions column contract (wpdb returns column values as strings).
 *
 * @since 1.0.0
 *
 * @phpstan-type RevisionRow object{id: string, event_id: string, user_id: string|null, revision_data: string, change_summary: string|null, created_at: string}
 */
interface RevisionRepositoryInterface {

	/**
	 * Insert a new revision.
	 *
	 * @param int                  $event_id       Event ID.
	 * @param int                  $user_id        User ID.
	 * @param array<string, mixed> $revision_data Snapshot data.
	 * @param string               $change_summary Human-readable summary of changes.
	 * @return int|false Inserted revision ID, or false on failure.
	 */
	public function insert( int $event_id, int $user_id, array $revision_data, string $change_summary );

	/**
	 * Get revisions for an event, newest first.
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Maximum number of revisions to return.
	 * @return array<object>
	 * @phpstan-return array<RevisionRow>
	 */
	public function for_event( int $event_id, int $limit = 10 ): array;

	/**
	 * Find a single revision by ID.
	 *
	 * @param int $revision_id Revision ID.
	 * @return object|null
	 * @phpstan-return RevisionRow|null
	 */
	public function find( int $revision_id ): ?object;

	/**
	 * Prune old revisions beyond the max limit.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $max_revisions Maximum revisions to keep.
	 * @return void
	 */
	public function prune( int $event_id, int $max_revisions ): void;

	/**
	 * Delete all revisions for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public function delete_for_event( int $event_id ): void;

	/**
	 * Count revisions for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	public function count_for_event( int $event_id ): int;
}
