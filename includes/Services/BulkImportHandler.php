<?php
/**
 * Bulk Import Handler.
 *
 * Handles post-batch housekeeping when entities are bulk-imported by
 * the Migrator plugin: cache invalidation, activity logging, and
 * WooCommerce stock synchronisation.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;

/**
 * Listens for BULK_IMPORT_COMPLETED and performs housekeeping.
 *
 * Follows the OrderFailureHandler pattern: every step is wrapped in
 * try/catch so one failure does not prevent subsequent steps, and the
 * outer try/catch ensures the handler never breaks import processing.
 *
 * @since 3.6.0
 */
class BulkImportHandler {

	/**
	 * Activity log service.
	 *
	 * @var ActivityLogServiceInterface
	 */
	private readonly ActivityLogServiceInterface $activity_log;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private readonly TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private readonly OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Constructor.
	 *
	 * @since 3.6.0
	 *
	 * @param ActivityLogServiceInterface   $activity_log     Activity log service.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo  Occurrence repository.
	 */
	public function __construct(
		ActivityLogServiceInterface $activity_log,
		TicketTypeRepositoryInterface $ticket_type_repo,
		OccurrenceRepositoryInterface $occurrence_repo
	) {
		$this->activity_log     = $activity_log;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->occurrence_repo  = $occurrence_repo;
	}

	/**
	 * Register hooks.
	 *
	 * @since 3.6.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nettertech_events_bulk_import_completed', array( $this, 'handle_batch_complete' ), 10, 4 );
	}

	/**
	 * Handle a bulk import batch completion.
	 *
	 * @since 3.6.0
	 *
	 * @param string     $type   Entity type (e.g., 'event', 'occurrence', 'attendee').
	 * @param int        $count  Number of entities in the batch.
	 * @param array<int> $ids    Array of entity IDs created/updated.
	 * @param string     $source Import source identifier (e.g., 'tec').
	 * @return void
	 */
	public function handle_batch_complete( string $type, int $count, array $ids, string $source ): void {
		try {
			// Step 1: Invalidate caches for imported entities.
			$this->invalidate_caches( $type, $ids );

			// Step 2: Log the batch import to the activity log.
			$this->log_batch_import( $type, $count, $ids, $source );

			// Step 3: Sync WC stock for ticket type imports.
			$this->maybe_sync_wc_stock( $type, $ids );

		} catch ( \Throwable $e ) {
			// Last-resort logging - handler must never break import processing.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Last-resort logging when bulk import handler fails.
			error_log(
				sprintf(
					'[NTE] BulkImportHandler failed for %s batch (%d entities): %s',
					$type,
					$count,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Invalidate object caches for imported entities.
	 *
	 * Uses wp_cache_delete in bulk to clear any cached data that may
	 * be stale after the import.
	 *
	 * @since 3.6.0
	 *
	 * @param string     $type Entity type.
	 * @param array<int> $ids  Entity IDs.
	 * @return void
	 */
	private function invalidate_caches( string $type, array $ids ): void {
		try {
			$group = 'nettertech_events_' . $type;

			foreach ( $ids as $id ) {
				wp_cache_delete( (string) $id, $group );
			}

			// Fire global cache invalidation hook for downstream consumers.
			do_action( 'nettertech_events_cache_invalidated' );
		} catch ( \Throwable $e ) {
			$this->log_step_failure( 'invalidate_caches', $type, $e );
		}
	}

	/**
	 * Log the batch import to the activity log.
	 *
	 * @since 3.6.0
	 *
	 * @param string     $type   Entity type.
	 * @param int        $count  Number of entities.
	 * @param array<int> $ids    Entity IDs.
	 * @param string     $source Import source.
	 * @return void
	 */
	private function log_batch_import( string $type, int $count, array $ids, string $source ): void {
		try {
			$this->activity_log->log(
				'bulk_import',
				$type,
				$ids[0] ?? 0,
				sprintf( 'Imported %d %s(s) from %s', $count, $type, $source ),
				array(
					'count'      => $count,
					'source'     => $source,
					'entity_ids' => array_slice( $ids, 0, 50 ), // Cap at 50 to avoid bloat.
				)
			);
		} catch ( \Throwable $e ) {
			$this->log_step_failure( 'log_batch_import', $type, $e );
		}
	}

	/**
	 * Sync WooCommerce stock for affected ticket types.
	 *
	 * Only triggers for ticket_type entity imports. Fires the
	 * TICKET_TYPE_SYNC_PRODUCT hook for each ticket type ID so
	 * ProductManager can create/update the linked WC products.
	 *
	 * @since 3.6.0
	 *
	 * @param string     $type Entity type.
	 * @param array<int> $ids  Entity IDs.
	 * @return void
	 */
	private function maybe_sync_wc_stock( string $type, array $ids ): void {
		if ( 'ticket_type' !== $type ) {
			return;
		}

		// Only sync if WooCommerce is active.
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		try {
			foreach ( $ids as $ticket_type_id ) {
				$ticket_type = $this->ticket_type_repo->find( (int) $ticket_type_id );

				// Skip non-occurrence-scoped ticket types — they have no linked WC product.
				if ( null === $ticket_type || null === $ticket_type->occurrence_id ) {
					continue;
				}

				$occurrence = $this->occurrence_repo->find( $ticket_type->occurrence_id );

				if ( null === $occurrence ) {
					continue;
				}

				/**
				 * Fires to trigger WC product sync for a ticket type.
				 *
				 * ProductManager listens for this hook and creates/updates
				 * the linked WC product.
				 *
				 * @param \NetterTechEvents\Models\TicketType  $ticket_type The ticket type to sync.
				 * @param \NetterTechEvents\Models\Occurrence $occurrence  The parent occurrence.
				 */
				do_action( 'nettertech_events_ticket_type_sync_product', $ticket_type, $occurrence );
			}
		} catch ( \Throwable $e ) {
			$this->log_step_failure( 'wc_stock_sync', $type, $e );
		}
	}

	/**
	 * Log a failure within a handler step.
	 *
	 * Uses error_log as last-resort fallback so the handler never throws.
	 *
	 * @since 3.6.0
	 *
	 * @param string     $step Handler step that failed.
	 * @param string     $type Entity type.
	 * @param \Throwable $e    The exception.
	 * @return void
	 */
	private function log_step_failure( string $step, string $type, \Throwable $e ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Last-resort logging when handler step fails.
		error_log(
			sprintf(
				'[NTE] BulkImportHandler: step "%s" failed for %s: %s',
				$step,
				$type,
				$e->getMessage()
			)
		);
	}
}
