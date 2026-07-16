<?php
/**
 * Space Repository Interface.
 *
 * Defines core CRUD and query operations for spaces.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Space;

/**
 * Interface for Space repository implementations.
 *
 * @since 2.1.0
 * @api
 */
interface SpaceRepositoryInterface {

	/**
	 * Find a space by ID.
	 *
	 * @since 2.1.0
	 *
	 * @param int $id Space ID.
	 * @return Space|null
	 */
	public function find( int $id ): ?Space;

	/**
	 * Find a space by slug.
	 *
	 * @since 2.1.0
	 *
	 * @param string $slug Space slug.
	 * @return Space|null
	 */
	public function find_by_slug( string $slug ): ?Space;

	/**
	 * Save a space (insert or update).
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $data Space data.
	 * @return int Space ID.
	 * @throws \RuntimeException If validation fails or save fails.
	 */
	public function save( array $data ): int;

	/**
	 * Soft-delete a space (set status to archived).
	 *
	 * @since 2.1.0
	 *
	 * @param int $id Space ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Get paginated, filtered list of spaces.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $args Query arguments (search, status, orderby, order, limit, offset).
	 * @return array<Space>
	 */
	public function paginate( array $args = array() ): array;

	/**
	 * Check if a slug is unique.
	 *
	 * @since 2.1.0
	 *
	 * @param string   $slug       Slug to check.
	 * @param int|null $exclude_id Space ID to exclude (for updates).
	 * @return bool True if slug is unique.
	 */
	public function is_slug_unique( string $slug, ?int $exclude_id = null ): bool;

	/**
	 * Count spaces matching filter criteria.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $args Filter arguments (search, status).
	 * @return int
	 */
	public function count( array $args = array() ): int;
}
