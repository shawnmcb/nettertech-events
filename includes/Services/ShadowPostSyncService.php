<?php
/**
 * Shadow Post Sync Service.
 *
 * Synchronizes custom-table events to shadow WordPress posts
 * for admin search and Gutenberg link dialog integration.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\ShadowPostType;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Tag;

/**
 * Keeps shadow posts in sync with custom-table events.
 *
 * Shadow posts are minimal WP posts (title + slug only) that mirror
 * events stored in nettertech_events_events. They enable WordPress admin bar search,
 * Gutenberg link insertion, and other native WP search features.
 *
 * @since 1.7.0
 * @api
 */
class ShadowPostSyncService {

	/**
	 * Guard flag to prevent infinite loops.
	 *
	 * When sync() calls wp_insert_post/wp_update_post, WordPress fires
	 * save_post hooks which could re-trigger event save if any listener
	 * tries to sync back. This flag prevents that recursion.
	 *
	 * @var bool
	 */
	private bool $syncing = false;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Category repository (nullable for shadow taxonomy sync).
	 *
	 * @var CategoryRepositoryInterface|null
	 */
	private ?CategoryRepositoryInterface $category_repo;

	/**
	 * Tag repository (nullable for shadow taxonomy sync).
	 *
	 * @var TagRepositoryInterface|null
	 */
	private ?TagRepositoryInterface $tag_repo;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface         $event_repo    Event repository.
	 * @param \wpdb                            $db            WordPress database instance.
	 * @param CategoryRepositoryInterface|null $category_repo Category repository for shadow taxonomy sync (NTE-002j).
	 * @param TagRepositoryInterface|null      $tag_repo      Tag repository for shadow taxonomy sync (NTE-002j).
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		\wpdb $db,
		?CategoryRepositoryInterface $category_repo = null,
		?TagRepositoryInterface $tag_repo = null
	) {
		$this->event_repo    = $event_repo;
		$this->db            = $db;
		$this->category_repo = $category_repo;
		$this->tag_repo      = $tag_repo;
	}

	/**
	 * Register hook listeners for event lifecycle events.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nettertech_events_after_save_event', array( $this, 'sync' ) );
		add_action( 'nettertech_events_after_delete_event', array( $this, 'on_delete' ), 10, 2 );
	}

	/**
	 * Sync a shadow post for the given event.
	 *
	 * Creates or updates a WordPress post mirroring the event's
	 * title, slug, and publication status. Stores no content or meta.
	 *
	 * @param Event $event The event to sync.
	 * @return int|false Shadow post ID on success, false on failure or guard.
	 */
	public function sync( Event $event ): int|false {
		if ( $this->syncing ) {
			return false;
		}

		if ( ! $event->id ) {
			return false;
		}

		$this->syncing = true;

		try {
			$post_data = array(
				'post_type'   => ShadowPostType::POST_TYPE,
				'post_title'  => $event->title,
				'post_name'   => $event->slug,
				'post_status' => $this->map_status( $event->status ),
			);

			$existing_post_id = $this->find_shadow_post( $event->id );

			if ( $existing_post_id ) {
				$post_data['ID'] = $existing_post_id;
				$result          = wp_update_post( $post_data, true );
			} else {
				$result = wp_insert_post( $post_data, true );

				if ( ! is_wp_error( $result ) ) {
					// Store the mapping: event_id on the shadow post.
					update_post_meta( $result, '_nettertech_events_event_id', $event->id );
				}
			}

			if ( is_wp_error( $result ) ) {
				return false;
			}

			$shadow_post_id = (int) $result;

			// NTE-002j: Sync shadow taxonomy terms for this event.
			$this->sync_shadow_taxonomies( $event->id, $shadow_post_id );

			return $shadow_post_id;
		} finally {
			$this->syncing = false;
		}
	}

	/**
	 * Sync shadow category and tag taxonomy terms for an event's shadow post.
	 *
	 * Upserts shadow terms by slug, then assigns them to the shadow post
	 * via wp_set_object_terms. Called from sync() after the shadow post
	 * itself is created/updated. See NTE-002j.
	 *
	 * @param int $event_id       Event ID (custom table).
	 * @param int $shadow_post_id Shadow post ID.
	 * @return void
	 */
	private function sync_shadow_taxonomies( int $event_id, int $shadow_post_id ): void {
		if ( null !== $this->category_repo ) {
			$categories        = $this->category_repo->find_by_event( $event_id );
			$category_term_ids = array();
			foreach ( $categories as $category ) {
				if ( ! $category instanceof Category ) {
					continue;
				}
				$term_id = $this->upsert_shadow_term(
					ShadowPostType::CATEGORY_TAXONOMY,
					$category->slug,
					$category->name
				);
				if ( null !== $term_id ) {
					$category_term_ids[] = $term_id;
				}
			}
			wp_set_object_terms( $shadow_post_id, $category_term_ids, ShadowPostType::CATEGORY_TAXONOMY );
		}

		if ( null !== $this->tag_repo ) {
			$tags         = $this->tag_repo->find_by_event( $event_id );
			$tag_term_ids = array();
			foreach ( $tags as $tag ) {
				if ( ! $tag instanceof Tag ) {
					continue;
				}
				$term_id = $this->upsert_shadow_term(
					ShadowPostType::TAG_TAXONOMY,
					$tag->slug,
					$tag->name
				);
				if ( null !== $term_id ) {
					$tag_term_ids[] = $term_id;
				}
			}
			wp_set_object_terms( $shadow_post_id, $tag_term_ids, ShadowPostType::TAG_TAXONOMY );
		}
	}

	/**
	 * Upsert a shadow taxonomy term by slug.
	 *
	 * If a term with the given slug exists in the taxonomy, update its
	 * name if changed. Otherwise insert it. Returns the term_id on
	 * success or null on failure.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $slug     Term slug.
	 * @param string $name     Term display name.
	 * @return int|null Term ID, or null on failure.
	 */
	private function upsert_shadow_term( string $taxonomy, string $slug, string $name ): ?int {
		$existing = get_term_by( 'slug', $slug, $taxonomy );

		if ( $existing instanceof \WP_Term ) {
			if ( $existing->name !== $name ) {
				wp_update_term( $existing->term_id, $taxonomy, array( 'name' => $name ) );
			}
			return (int) $existing->term_id;
		}

		$result = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $result ) ) {
			return null;
		}

		return (int) $result['term_id'];
	}

	/**
	 * Handle event deletion — remove the shadow post.
	 *
	 * @param int   $event_id The deleted event ID.
	 * @param Event $event    The deleted event model (unused; required by hook signature).
	 * @return bool True if shadow post was deleted.
	 */
	public function on_delete( int $event_id, Event $event ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		$post_id = $this->find_shadow_post( $event_id );

		if ( ! $post_id ) {
			return false;
		}

		$result = wp_delete_post( $post_id, true );

		return false !== $result && null !== $result;
	}

	/**
	 * Bulk sync all published events.
	 *
	 * Intended for one-time migration when shadow posts are first enabled.
	 *
	 * @return array{synced: int, skipped: int, failed: int} Sync results.
	 */
	public function sync_all(): array {
		$results = array(
			'synced'  => 0,
			'skipped' => 0,
			'failed'  => 0,
		);

		$result = $this->event_repo->paginate(
			array(
				'status'   => 'published',
				'per_page' => 9999,
			)
		);
		$events = $result['items'];

		foreach ( $events as $event ) {
			if ( null === $event->id ) {
				++$results['skipped'];
				continue;
			}

			$existing = $this->find_shadow_post( $event->id );
			if ( $existing ) {
				++$results['skipped'];
				continue;
			}

			$post_id = $this->sync( $event );
			if ( false === $post_id ) {
				++$results['failed'];
			} else {
				++$results['synced'];
			}
		}

		return $results;
	}

	/**
	 * Find the shadow post ID for a given event.
	 *
	 * @param int $event_id The event ID.
	 * @return int|false Shadow post ID, or false if not found.
	 */
	public function find_shadow_post( int $event_id ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off lookup, no persistent cache needed.
		$post_id = $this->db->get_var(
			$this->db->prepare(
				"SELECT pm.post_id FROM {$this->db->postmeta} pm
				INNER JOIN {$this->db->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = '_nettertech_events_event_id' AND pm.meta_value = %s AND p.post_type = %s
				LIMIT 1",
				(string) $event_id,
				ShadowPostType::POST_TYPE
			)
		);

		return $post_id ? (int) $post_id : false;
	}

	/**
	 * Map event status to WordPress post status.
	 *
	 * @param EventStatus $event_status Event status.
	 * @return string WordPress post status.
	 */
	private function map_status( EventStatus $event_status ): string {
		return match ( $event_status ) {
			EventStatus::PUBLISHED => 'publish',
			EventStatus::CANCELLED, EventStatus::POSTPONED => 'private',
			default => 'draft',
		};
	}
}
