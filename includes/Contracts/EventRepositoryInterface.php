<?php
/**
 * Event Repository Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Event;

/**
 * Interface for event repository implementations.
 *
 * @since 0.1.0
 */
interface EventRepositoryInterface {

	/**
	 * Find an event by ID.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Event ID.
	 * @return Event|null
	 */
	public function find( int $id ): ?Event;

	/**
	 * Find an event by slug.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug Event slug.
	 * @return Event|null
	 */
	public function find_by_slug( string $slug ): ?Event;

	/**
	 * Check if an event has any ticket types.
	 *
	 * @since 0.1.0
	 *
	 * @param int $event_id Event ID.
	 * @return bool
	 */
	public function has_ticket_types( int $event_id ): bool;

	/**
	 * Save an event.
	 *
	 * @since 0.1.0
	 *
	 * @param Event $event Event to save.
	 * @return Event
	 */
	public function save( Event $event ): Event;

	/**
	 * Delete an event.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Event ID.
	 * @return bool
	 */
	public function delete( int $id ): bool;

	/**
	 * Paginate events with filtering and search.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $args Query arguments (page, per_page, status, search, orderby, order).
	 * @return array{items: array<Event>, total: int, pages: int}
	 */
	public function paginate( array $args = array() ): array;

	/**
	 * Generate a unique slug from a title.
	 *
	 * @since 0.11.0
	 *
	 * @param string $title The title to generate a slug from.
	 * @return string Unique slug.
	 */
	public function generate_unique_slug( string $title ): string;

	/**
	 * Get all events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Event>
	 */
	public function all( array $args = array() ): array;
}
