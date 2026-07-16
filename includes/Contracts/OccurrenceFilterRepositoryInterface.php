<?php
/**
 * Occurrence Filter Repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Occurrence;

/**
 * Interface for filtered occurrence query implementations.
 *
 * @since 1.0.0
 */
interface OccurrenceFilterRepositoryInterface {

	/**
	 * Get filtered occurrences with pagination.
	 *
	 * Supports filtering by category, search, and upcoming-only.
	 * Uses object cache for per-request caching (persistent on sites with Redis/Memcached).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<Occurrence>, total: int, total_pages: int}
	 */
	public function get_filtered( array $args = array() ): array;
}
