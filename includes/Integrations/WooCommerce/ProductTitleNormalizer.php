<?php
/**
 * Repair plan for ticket product titles that grew by repeated sync prefixes (NTE-235).
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;

/**
 * Builds and applies a plan that collapses stacked "{event} - {date} - "
 * (or "{event} - Series Pass - ") prefixes on ticket products and their tiers.
 *
 * Background (NTE-235): earlier releases composed the product title from the
 * tier name on every sync while an importer adopted the composed title back as
 * the tier name, so each import/sync cycle stacked one more prefix onto both
 * `ticket_types.name` and the product's `post_title`. The sync side is now
 * idempotent; this class repairs rows that were already affected.
 *
 * Rules, per ticket type that owns a WooCommerce product:
 *   - the tier name loses every leading copy of its own prefix (a tier is the
 *     bare name — "GA", never "Show - Jan 1, 2027 - GA");
 *   - the product title is only touched when it carries the prefix two or more
 *     times; one copy (or none) is left exactly as it is, so an adopted product
 *     that was never renamed is not renamed here either;
 *   - when the event or occurrence cannot be resolved the row is reported as
 *     unresolved and left alone.
 */
class ProductTitleNormalizer {

	public const ACTION_OK         = 'ok';
	public const ACTION_UNRESOLVED = 'unresolved';
	public const ACTION_TIER       = 'rename-tier';
	public const ACTION_PRODUCT    = 'rename-product';
	public const ACTION_BOTH       = 'rename-both';

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Occurrence repository (event title + date for occurrence-scoped tiers).
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Event repository (event title for series-pass tiers).
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Constructor.
	 *
	 * @param \wpdb                         $db              Database.
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param EventRepositoryInterface      $event_repo      Event repository.
	 */
	public function __construct( \wpdb $db, OccurrenceRepositoryInterface $occurrence_repo, EventRepositoryInterface $event_repo ) {
		$this->db              = $db;
		$this->occurrence_repo = $occurrence_repo;
		$this->event_repo      = $event_repo;
	}

	/**
	 * Build the repair plan. Makes no writes.
	 *
	 * @return array<int, array<string, mixed>> One row per ticket type with a product:
	 *         ticket_type_id, product_id, prefix, name, new_name, name_copies,
	 *         title, new_title, title_copies, action.
	 */
	public function plan(): array {
		$table = Schema::table( 'ticket_types' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off repair scan; table name from Schema.
		$rows = $this->db->get_results(
			"SELECT id, name, scope, event_id, occurrence_id, wc_product_id
			 FROM {$table}
			 WHERE wc_product_id IS NOT NULL AND wc_product_id > 0
			 ORDER BY id ASC"
		);

		$plan = array();
		foreach ( $rows ?? array() as $row ) {
			if ( $row instanceof \stdClass ) {
				$plan[] = $this->plan_row( $row );
			}
		}

		return $plan;
	}

	/**
	 * Apply the rename rows of a plan.
	 *
	 * @param array<int, array<string, mixed>> $plan Output of plan().
	 * @return int Rows changed.
	 */
	public function apply( array $plan ): int {
		$changed = 0;

		foreach ( $plan as $row ) {
			if ( ! in_array( $row['action'], array( self::ACTION_TIER, self::ACTION_PRODUCT, self::ACTION_BOTH ), true ) ) {
				continue;
			}

			if ( $row['new_name'] !== $row['name'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off repair write.
				$this->db->update(
					Schema::table( 'ticket_types' ),
					array( 'name' => $row['new_name'] ),
					array( 'id' => $row['ticket_type_id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}

			if ( $row['new_title'] !== $row['title'] ) {
				wp_update_post(
					array(
						'ID'         => $row['product_id'],
						'post_title' => $row['new_title'],
					)
				);
			}

			update_post_meta( $row['product_id'], MetaKeys::TIER_NAME, $row['new_name'] );
			++$changed;
		}

		return $changed;
	}

	/**
	 * Plan one ticket-type row.
	 *
	 * @param \stdClass $row Row with id, name, scope, event_id, occurrence_id, wc_product_id.
	 * @return array<string, mixed>
	 */
	private function plan_row( \stdClass $row ): array {
		$product_id = (int) $row->wc_product_id;
		$name       = $row->name;
		$raw_title  = get_post_field( 'post_title', $product_id, 'raw' );
		$title      = is_string( $raw_title ) ? $raw_title : '';
		$prefix     = $this->resolve_prefix( $row );

		$out = array(
			'ticket_type_id' => (int) $row->id,
			'product_id'     => $product_id,
			'prefix'         => $prefix ?? '',
			'name'           => $name,
			'new_name'       => $name,
			'name_copies'    => 0,
			'title'          => $title,
			'new_title'      => $title,
			'title_copies'   => 0,
			'action'         => self::ACTION_UNRESOLVED,
		);

		if ( null === $prefix ) {
			return $out;
		}

		$out['name_copies']  = self::count_prefix_copies( $name, $prefix );
		$out['title_copies'] = self::count_prefix_copies( $title, $prefix );
		$out['new_name']     = ProductManager::strip_title_prefix( $name, $prefix );

		if ( $out['title_copies'] >= 2 ) {
			$out['new_title'] = $prefix . ProductManager::strip_title_prefix( $title, $prefix );
		}

		$tier_changes    = $out['new_name'] !== $name;
		$product_changes = $out['new_title'] !== $title;

		if ( $tier_changes && $product_changes ) {
			$out['action'] = self::ACTION_BOTH;
		} elseif ( $tier_changes ) {
			$out['action'] = self::ACTION_TIER;
		} elseif ( $product_changes ) {
			$out['action'] = self::ACTION_PRODUCT;
		} else {
			$out['action'] = self::ACTION_OK;
		}

		return $out;
	}

	/**
	 * The prefix sync would put on this tier's product, or null when the
	 * event/occurrence it hangs off cannot be loaded.
	 *
	 * @param \stdClass $row Ticket-type row.
	 * @return string|null
	 */
	private function resolve_prefix( \stdClass $row ): ?string {
		$occurrence_id = (int) $row->occurrence_id;
		if ( $occurrence_id > 0 ) {
			$occurrence = $this->occurrence_repo->find_with_event( $occurrence_id );
			$event      = $occurrence ? $occurrence->get_event() : null;
			if ( $occurrence && $event ) {
				return ProductManager::occurrence_title_prefix( (string) $event->title, $occurrence->get_formatted_date() );
			}
			return null;
		}

		$event_id = (int) $row->event_id;
		if ( $event_id > 0 ) {
			$event = $this->event_repo->find( $event_id );
			if ( $event ) {
				return ProductManager::series_pass_title_prefix( (string) $event->title );
			}
		}

		return null;
	}

	/**
	 * How many times a string starts with consecutive copies of the prefix.
	 *
	 * @param string $value  Value.
	 * @param string $prefix Prefix.
	 * @return int
	 */
	private static function count_prefix_copies( string $value, string $prefix ): int {
		$count = 0;
		while ( '' !== $prefix && str_starts_with( $value, $prefix ) ) {
			$value = substr( $value, strlen( $prefix ) );
			++$count;
		}
		return $count;
	}
}
