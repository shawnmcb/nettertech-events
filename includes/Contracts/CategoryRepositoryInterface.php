<?php
/**
 * Category Repository Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Category;

/**
 * Interface for Category repository implementations.
 *
 * @since 0.9.0
 * @api
 */
interface CategoryRepositoryInterface {

	/**
	 * Find a category by ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Category ID.
	 * @return Category|null
	 */
	public function find( int $id ): ?Category;

	/**
	 * Find a category by slug.
	 *
	 * @since 0.9.0
	 *
	 * @param string $slug Category slug.
	 * @return Category|null
	 */
	public function find_by_slug( string $slug ): ?Category;

	/**
	 * Save a category (insert or update).
	 *
	 * @since 0.9.0
	 *
	 * @param Category $category Category to save.
	 * @return Category The saved category with ID populated.
	 * @throws \RuntimeException If validation fails or save fails.
	 */
	public function save( Category $category ): Category;

	/**
	 * Delete a category.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Category ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Get all categories, optionally limited to those with events in a timeframe.
	 *
	 * When $timeframe is null (default), returns all categories regardless of
	 * whether any events carry them. When 'upcoming', returns only categories
	 * attached to events with at least one scheduled occurrence on or after
	 * current_time('mysql'). When 'past', returns only categories attached
	 * to events with at least one scheduled occurrence before that time.
	 *
	 * @since 0.9.0
	 * @since 1.1.0 Added $timeframe parameter (NTE-068).
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string|null          $timeframe Timeframe predicate: null, 'upcoming', or 'past'.
	 * @return array<Category>
	 */
	public function get_all( array $args = array(), ?string $timeframe = null ): array;

	/**
	 * Get categories for an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id Event ID.
	 * @return array<Category>
	 */
	public function find_by_event( int $event_id ): array;

	/**
	 * Get child categories.
	 *
	 * @since 0.9.0
	 *
	 * @param int|null $parent_id Parent category ID, or null for top-level.
	 * @return array<Category>
	 */
	public function find_children( ?int $parent_id = null ): array;

	/**
	 * Get category tree (hierarchical structure).
	 *
	 * @since 0.9.0
	 *
	 * @return array<array{category: Category, children: array<mixed>}>
	 */
	public function get_tree(): array;

	/**
	 * Attach a category to an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int  $event_id    Event ID.
	 * @param int  $category_id Category ID.
	 * @param bool $is_primary  Whether this is the primary category.
	 * @return bool True on success.
	 */
	public function attach_to_event( int $event_id, int $category_id, bool $is_primary = false ): bool;

	/**
	 * Detach a category from an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id    Event ID.
	 * @param int $category_id Category ID.
	 * @return bool True on success.
	 */
	public function detach_from_event( int $event_id, int $category_id ): bool;

	/**
	 * Sync categories for an event.
	 *
	 * Replaces all event categories with the provided list.
	 *
	 * @since 0.9.0
	 *
	 * @param int                                          $event_id   Event ID.
	 * @param array<int|array{id: int, is_primary?: bool}> $categories List of category IDs or config arrays.
	 * @return bool True on success.
	 */
	public function sync_event_categories( int $event_id, array $categories ): bool;
}
