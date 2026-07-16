<?php
/**
 * Per-request identity map trait for repository entities.
 *
 * @package NetterTechEvents\Traits
 */

declare(strict_types=1);

namespace NetterTechEvents\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * Per-request identity map for repository entities.
 *
 * Provides a simple in-memory cache keyed by entity ID.
 * Does NOT replace wp_cache (cross-request) -- this is per-request only.
 * Repositories that use wp_cache should layer it: identity_map -> wp_cache -> DB.
 *
 * @since 1.6.0
 * @api
 */
trait IdentityMapTrait {

	/**
	 * Per-request identity map keyed by entity ID.
	 *
	 * @var array<int, object>
	 */
	private array $identity_map = array();

	/**
	 * Store an entity in the identity map.
	 *
	 * @param int    $id     Entity ID.
	 * @param object $entity Entity instance.
	 * @return void
	 */
	protected function remember( int $id, object $entity ): void {
		$this->identity_map[ $id ] = $entity;
	}

	/**
	 * Retrieve an entity from the identity map.
	 *
	 * @param int $id Entity ID.
	 * @return object|null The cached entity, or null if not found.
	 */
	protected function recalled( int $id ): ?object {
		return $this->identity_map[ $id ] ?? null;
	}

	/**
	 * Remove an entity from the identity map.
	 *
	 * @param int $id Entity ID.
	 * @return void
	 */
	protected function forget( int $id ): void {
		unset( $this->identity_map[ $id ] );
	}
}
