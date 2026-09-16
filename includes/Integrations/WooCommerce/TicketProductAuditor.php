<?php
/**
 * Audit and prune orphaned ticket products (NTE-228).
 *
 * @package NetterTechEvents
 */

declare( strict_types=1 );

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Database\Schema;

/**
 * Classifies every WooCommerce ticket product against the ticket_types table
 * and prunes the ones nothing refers to any more.
 *
 * Residue of the NTE-158 / NTE-160 / NTE-156 era: each event-screen save used
 * to mint a fresh product per tier, leaving the previous one behind with its
 * NTE meta intact. A product is:
 *
 * - `linked`         its ticket type exists and points back at it;
 * - `type-missing`   its ticket type id no longer exists;
 * - `type-relinked`  its ticket type exists but points at another product.
 *
 * The last two are orphans. Orphans with order line items are reported but
 * never trashed; a merge decision belongs to the operator.
 */
class TicketProductAuditor {

	public const LINKED        = 'linked';
	public const TYPE_MISSING  = 'type-missing';
	public const TYPE_RELINKED = 'type-relinked';

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Classify every non-trashed ticket product. Makes no writes.
	 *
	 * @return array<int, array<string, mixed>> One row per product, ordered by
	 *         event title then product id: product_id, title, status, sku,
	 *         event_id, event_title, occurrence_id, ticket_type_id,
	 *         ticket_type_name, is_series_pass, classification,
	 *         linked_product_id, order_lines, order_count, orders_known.
	 */
	public function audit(): array {
		$orders_known = $this->order_lookup_available();
		$rows         = $this->fetch_product_rows( $orders_known );

		$result = array();
		foreach ( $rows as $row ) {
			if ( $row instanceof \stdClass ) {
				$result[] = $this->classify_row( $row, $orders_known );
			}
		}

		return $result;
	}

	/**
	 * Keep only the orphan rows of an audit.
	 *
	 * @param array<int, array<string, mixed>> $rows Output of audit().
	 * @return array<int, array<string, mixed>>
	 */
	public function orphans( array $rows ): array {
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return self::LINKED !== $row['classification'];
				}
			)
		);
	}

	/**
	 * Trash the orphans that carry no order line items.
	 *
	 * Products with orders, and every product when order counts could not be
	 * established, are skipped. With $execute false nothing is written and the
	 * candidates are returned under `would_trash`.
	 *
	 * @param array<int, array<string, mixed>> $rows    Output of audit() or orphans().
	 * @param bool                             $execute Whether to trash for real.
	 * @return array{trashed: int[], would_trash: int[], skipped: int[], failed: int[]}
	 */
	public function prune( array $rows, bool $execute ): array {
		$out = array(
			'trashed'     => array(),
			'would_trash' => array(),
			'skipped'     => array(),
			'failed'      => array(),
		);

		foreach ( $this->orphans( $rows ) as $row ) {
			$product_id = (int) $row['product_id'];

			if ( ! $row['orders_known'] || $row['order_lines'] > 0 ) {
				$out['skipped'][] = $product_id;
				continue;
			}

			if ( ! $execute ) {
				$out['would_trash'][] = $product_id;
				continue;
			}

			if ( wp_trash_post( $product_id ) ) {
				$out['trashed'][] = $product_id;
			} else {
				$out['failed'][] = $product_id;
			}
		}

		return $out;
	}

	/**
	 * Ticket products whose SKU is a `-N` collision variant of another product's SKU.
	 *
	 * Report only: nothing here decides which of the two is canonical.
	 *
	 * @param array<int, array<string, mixed>> $rows Output of audit().
	 * @return array<int, array<string, mixed>> product_id, sku, base_sku,
	 *         base_product_id, suffix, classification, order_lines.
	 */
	public function suffixed_skus( array $rows ): array {
		$sku_owner = $this->fetch_sku_owners();
		$report    = array();

		foreach ( $rows as $row ) {
			$sku = $row['sku'];
			if ( ! preg_match( '/(.+)-(\d+)$/', $sku, $m ) ) {
				continue;
			}

			$base = $m[1];
			if ( ! isset( $sku_owner[ $base ] ) ) {
				continue;
			}

			$report[] = array(
				'product_id'      => $row['product_id'],
				'sku'             => $sku,
				'base_sku'        => $base,
				'base_product_id' => $sku_owner[ $base ],
				'suffix'          => (int) $m[2],
				'classification'  => $row['classification'],
				'order_lines'     => $row['order_lines'],
			);
		}

		return $report;
	}

	/**
	 * Turn a raw audit row into the classified array shape.
	 *
	 * @param \stdClass $row          Raw row from fetch_product_rows().
	 * @param bool      $orders_known Whether order counts were queried.
	 * @return array<string, mixed>
	 */
	private function classify_row( \stdClass $row, bool $orders_known ): array {
		// wpdb returns every column as a string, or NULL from the LEFT JOINs;
		// (int) NULL is 0 and (string) NULL is '', so no defaults are needed.
		$product_id     = (int) $row->product_id;
		$tt_product_id  = (int) $row->tt_product_id;
		$classification = self::TYPE_MISSING;

		if ( ! empty( $row->tt_id ) ) {
			$classification = $tt_product_id === $product_id ? self::LINKED : self::TYPE_RELINKED;
		}

		$event_id = (int) $row->tt_event_id;
		if ( $event_id <= 0 ) {
			$event_id = (int) $row->meta_event_id;
		}

		return array(
			'product_id'        => $product_id,
			'title'             => $row->title,
			'status'            => $row->status,
			'sku'               => (string) $row->sku,
			'event_id'          => $event_id,
			'event_title'       => (string) $row->event_title,
			'occurrence_id'     => (int) $row->occurrence_id,
			'ticket_type_id'    => (int) $row->ticket_type_id,
			'ticket_type_name'  => (string) $row->tt_name,
			'is_series_pass'    => ! empty( $row->is_series_pass ),
			'classification'    => $classification,
			'linked_product_id' => $tt_product_id,
			'order_lines'       => $orders_known ? (int) $row->order_lines : null,
			'order_count'       => $orders_known ? (int) $row->order_count : null,
			'orders_known'      => $orders_known,
		);
	}

	/**
	 * Whether WooCommerce's order/product lookup table exists.
	 *
	 * @return bool
	 */
	private function order_lookup_available(): bool {
		$table = $this->db->prefix . 'wc_order_product_lookup';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off audit scan.
		$found = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return is_string( $found ) && $found === $table;
	}

	/**
	 * Fetch every non-trashed ticket product joined to its ticket type, event,
	 * and (when available) order line counts.
	 *
	 * @param bool $with_orders Join the order/product lookup table.
	 * @return array<int, \stdClass>
	 */
	private function fetch_product_rows( bool $with_orders ): array {
		$posts        = $this->db->posts;
		$postmeta     = $this->db->postmeta;
		$ticket_types = Schema::table( 'ticket_types' );
		$events       = Schema::table( 'events' );
		$lookup       = $this->db->prefix . 'wc_order_product_lookup';

		$order_select = 'NULL AS order_lines, NULL AS order_count';
		$order_join   = '';
		if ( $with_orders ) {
			$order_select = 'COALESCE( ol.line_count, 0 ) AS order_lines, COALESCE( ol.order_count, 0 ) AS order_count';
			$order_join   = "LEFT JOIN ( SELECT product_id, COUNT(*) AS line_count, COUNT( DISTINCT order_id ) AS order_count FROM {$lookup} GROUP BY product_id ) ol ON ol.product_id = p.ID";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off audit scan; table names from $wpdb and Schema, meta keys via prepare().
		$sql = $this->db->prepare(
			"SELECT p.ID AS product_id, p.post_title AS title, p.post_status AS status,
				sku.meta_value AS sku,
				CAST( COALESCE( ttm.meta_value, '0' ) AS UNSIGNED ) AS ticket_type_id,
				CAST( COALESCE( evm.meta_value, '0' ) AS UNSIGNED ) AS meta_event_id,
				CAST( COALESCE( ocm.meta_value, '0' ) AS UNSIGNED ) AS occurrence_id,
				( spm.meta_id IS NOT NULL ) AS is_series_pass,
				tt.id AS tt_id, tt.wc_product_id AS tt_product_id, tt.event_id AS tt_event_id, tt.name AS tt_name,
				e.title AS event_title,
				{$order_select}
			FROM {$posts} p
			INNER JOIN {$postmeta} flag ON flag.post_id = p.ID AND flag.meta_key = %s AND flag.meta_value = 'yes'
			LEFT JOIN {$postmeta} ttm ON ttm.post_id = p.ID AND ttm.meta_key = %s
			LEFT JOIN {$postmeta} evm ON evm.post_id = p.ID AND evm.meta_key = %s
			LEFT JOIN {$postmeta} ocm ON ocm.post_id = p.ID AND ocm.meta_key = %s
			LEFT JOIN {$postmeta} spm ON spm.post_id = p.ID AND spm.meta_key = %s AND spm.meta_value = 'yes'
			LEFT JOIN {$postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
			LEFT JOIN {$ticket_types} tt ON tt.id = CAST( COALESCE( ttm.meta_value, '0' ) AS UNSIGNED )
			LEFT JOIN {$events} e ON e.id = COALESCE( tt.event_id, CAST( COALESCE( evm.meta_value, '0' ) AS UNSIGNED ) )
			{$order_join}
			WHERE p.post_type = 'product' AND p.post_status <> 'trash'
			ORDER BY e.title ASC, p.ID ASC",
			MetaKeys::IS_EVENT_TICKET,
			MetaKeys::TICKET_TYPE_ID,
			MetaKeys::EVENT_ID,
			MetaKeys::OCCURRENCE_ID,
			MetaKeys::IS_SERIES_PASS
		);

		$rows = $this->db->get_results( $sql );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Map every non-trashed product SKU to its product id.
	 *
	 * @return array<string, int>
	 */
	private function fetch_sku_owners(): array {
		$posts    = $this->db->posts;
		$postmeta = $this->db->postmeta;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off audit scan; table names from $wpdb.
		$rows = $this->db->get_results(
			"SELECT m.post_id, m.meta_value AS sku
			 FROM {$postmeta} m
			 INNER JOIN {$posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = '_sku' AND m.meta_value <> '' AND p.post_type = 'product' AND p.post_status <> 'trash'"
		);

		$map = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $row instanceof \stdClass ) {
				$map[ (string) $row->sku ] = (int) $row->post_id;
			}
		}

		return $map;
	}
}
