<?php
/**
 * Tag Repository Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Tag;

/**
 * Interface for Tag repository implementations.
 *
 * @since 0.9.0
 * @api
 */
interface TagRepositoryInterface {

	/**
	 * Find a tag by ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Tag ID.
	 * @return Tag|null
	 */
	public function find( int $id ): ?Tag;

	/**
	 * Find a tag by slug.
	 *
	 * @since 0.9.0
	 *
	 * @param string $slug Tag slug.
	 * @return Tag|null
	 */
	public function find_by_slug( string $slug ): ?Tag;

	/**
	 * Save a tag (insert or update).
	 *
	 * @since 0.9.0
	 *
	 * @param Tag $tag Tag to save.
	 * @return Tag The saved tag with ID populated.
	 * @throws \RuntimeException If validation fails or save fails.
	 */
	public function save( Tag $tag ): Tag;

	/**
	 * Delete a tag.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Tag ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Get all tags, optionally limited to those with events in a timeframe.
	 *
	 * When $timeframe is null (default), returns all tags regardless of
	 * whether any events carry them. When 'upcoming', returns only tags
	 * attached to events with at least one scheduled occurrence on or after
	 * current_time('mysql'). When 'past', returns only tags attached
	 * to events with at least one scheduled occurrence before that time.
	 *
	 * @since 0.9.0
	 * @since 1.1.0 Added $timeframe parameter (NTE-068).
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string|null          $timeframe Timeframe predicate: null, 'upcoming', or 'past'.
	 * @return array<Tag>
	 */
	public function get_all( array $args = array(), ?string $timeframe = null ): array;

	/**
	 * Get tags for an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id Event ID.
	 * @return array<Tag>
	 */
	public function find_by_event( int $event_id ): array;

	/**
	 * Get tags for multiple events in a single query.
	 *
	 * N+1 prevention for grid/list renderers that need tags per event.
	 * Returns a map keyed by event_id; events with no tags are absent
	 * from the returned array.
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $event_ids Event IDs.
	 * @return array<int, array<Tag>> Map of event_id => list of tags.
	 */
	public function find_by_event_ids( array $event_ids ): array;

	/**
	 * Search tags by name.
	 *
	 * @since 0.9.0
	 *
	 * @param string $query  Search query.
	 * @param int    $limit  Maximum results.
	 * @return array<Tag>
	 */
	public function search( string $query, int $limit = 10 ): array;

	/**
	 * Attach a tag to an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id Event ID.
	 * @param int $tag_id   Tag ID.
	 * @return bool True on success.
	 */
	public function attach_to_event( int $event_id, int $tag_id ): bool;

	/**
	 * Detach a tag from an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id Event ID.
	 * @param int $tag_id   Tag ID.
	 * @return bool True on success.
	 */
	public function detach_from_event( int $event_id, int $tag_id ): bool;

	/**
	 * Sync tags for an event.
	 *
	 * Replaces all event tags with the provided list.
	 *
	 * @since 0.9.0
	 *
	 * @param int        $event_id Event ID.
	 * @param array<int> $tag_ids  List of tag IDs.
	 * @return bool True on success.
	 */
	public function sync_event_tags( int $event_id, array $tag_ids ): bool;

	/**
	 * Find or create a tag by name.
	 *
	 * @since 0.9.0
	 *
	 * @param string $name Tag name.
	 * @return Tag The found or created tag.
	 */
	public function find_or_create( string $name ): Tag;
}
