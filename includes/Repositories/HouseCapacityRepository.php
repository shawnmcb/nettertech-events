<?php
/**
 * House capacity repository.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\HouseCapacityRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Services\Capacity\HouseRule;

/**
 * Reads the tiers that share a room with a given ticket type.
 *
 * @since 1.1.2
 */
class HouseCapacityRepository implements HouseCapacityRepositoryInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Ticket types table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Occurrences table name.
	 *
	 * @var string
	 */
	private string $occurrences_table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db                = $db;
		$this->table             = Schema::table( 'ticket_types' );
		$this->occurrences_table = Schema::table( 'occurrences' );
	}

	/**
	 * Load the house context for a ticket type.
	 *
	 * @since 1.1.2
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array{house: ?int, house_sold: int, house_reserved: int, own_capacity: ?int, own_capacity_type: string, own_sold: int, peer_ids: array<int, int>}|null
	 */
	public function context_for_ticket_type( int $ticket_type_id ): ?array {
		$self = $this->db->get_row(
			$this->db->prepare(
				"SELECT tt.id, tt.capacity, tt.capacity_type, tt.sold_count, tt.occurrence_id, tt.event_id, o.capacity AS ceiling
				 FROM {$this->table} tt
				 LEFT JOIN {$this->occurrences_table} o ON tt.occurrence_id = o.id
				 WHERE tt.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$ticket_type_id
			)
		);

		if ( ! $self ) {
			return null;
		}

		$peers   = $this->fetch_peers( $self );
		$ceiling = null !== $self->ceiling ? (int) $self->ceiling : null;

		return array(
			'house'             => HouseRule::house( self::sizing_tiers( $peers ), $ceiling ),
			'house_sold'        => self::sum( $peers, 'sold_count' ),
			'house_reserved'    => self::sum( $peers, 'reserved' ),
			'own_capacity'      => null !== $self->capacity ? (int) $self->capacity : null,
			'own_capacity_type' => (string) $self->capacity_type,
			'own_sold'          => (int) $self->sold_count,
			'peer_ids'          => array_map( static fn( \stdClass $row ): int => (int) $row->id, $peers ),
		);
	}

	/**
	 * Resolve one date's room: size, seats sold, seats reserved.
	 *
	 * Pass tiers (event-scoped) are counted among the sold/reserved seats — a pass
	 * occupies a seat on every date it spans (NTE-156) — but never size the room.
	 * Used to bound a pass's availability by its tightest date.
	 *
	 * @since 1.1.3
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array{house: ?int, house_sold: int, house_reserved: int}|null Null when the occurrence does not exist.
	 */
	public function house_context_for_occurrence( int $occurrence_id ): ?array {
		$occurrence = $this->db->get_row(
			$this->db->prepare(
				"SELECT id, capacity FROM {$this->occurrences_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id
			)
		);

		if ( ! $occurrence ) {
			return null;
		}

		$synthetic                = new \stdClass();
		$synthetic->id            = 0;
		$synthetic->occurrence_id = $occurrence_id;
		$synthetic->event_id      = null;

		$peers   = $this->fetch_peers( $synthetic );
		$ceiling = null !== $occurrence->capacity ? (int) $occurrence->capacity : null;
		$sizing  = self::sizing_tiers( $peers );

		// A date with no ceiling and no sizing tiers has no room to run out of.
		// HouseRule::house() reads an empty tier list as "largest tier = 0", which
		// is right when the caller is itself a tier (it is always among its peers)
		// and wrong here, where the peer set may legitimately be passes only.
		$house = ( null === $ceiling && empty( $sizing ) )
			? null
			: HouseRule::house( $sizing, $ceiling );

		return array(
			'house'          => $house,
			'house_sold'     => self::sum( $peers, 'sold_count' ),
			'house_reserved' => self::sum( $peers, 'reserved' ),
		);
	}

	/**
	 * List the ticket type IDs sharing a house with the given one, including itself.
	 *
	 * @since 1.1.2
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array<int, int>
	 */
	public function house_peer_ids( int $ticket_type_id ): array {
		$self = $this->db->get_row(
			$this->db->prepare(
				"SELECT id, occurrence_id, event_id FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$ticket_type_id
			)
		);

		if ( ! $self ) {
			return array();
		}

		return array_map( static fn( \stdClass $row ): int => (int) $row->id, $this->fetch_peers( $self ) );
	}

	/**
	 * Fetch every tier sharing a room with the given tier, including itself.
	 *
	 * A tier bound to an occurrence shares that date's room. An event-scoped tier
	 * (no occurrence) shares a room with the event's other event-scoped tiers. A
	 * tier attached to neither is its own house.
	 *
	 * @param \stdClass $tier Row carrying id, occurrence_id and event_id.
	 * @return array<int, \stdClass>
	 */
	private function fetch_peers( \stdClass $tier ): array {
		if ( null !== $tier->occurrence_id ) {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, capacity, capacity_type, sold_count, reserved, status
					 FROM {$this->table} WHERE occurrence_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
					(int) $tier->occurrence_id
				)
			);

			// A series pass occupies a seat on EVERY date it spans (NTE-156), so the
			// event's pass tiers sit in this date's room too: their sold seats count
			// against the house. They are flagged so sizing_tiers() can exclude them —
			// a pass's own allotment says how many passes may exist, not how big any
			// one room is.
			$pass_rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT tt.id, tt.capacity, tt.capacity_type, tt.sold_count, tt.reserved, tt.status
					 FROM {$this->table} tt
					 JOIN {$this->occurrences_table} o ON o.id = %d
					 WHERE tt.occurrence_id IS NULL AND tt.event_id = o.event_id
					 ORDER BY tt.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from trusted constants; occurrence ID bound via prepare().
					(int) $tier->occurrence_id
				)
			);

			foreach ( $pass_rows ? $pass_rows : array() as $pass_row ) {
				$pass_row->is_pass = true;
				$rows[]            = $pass_row;
			}
		} elseif ( null !== $tier->event_id ) {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, capacity, capacity_type, sold_count, reserved, status
					 FROM {$this->table} WHERE event_id = %d AND occurrence_id IS NULL ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
					(int) $tier->event_id
				)
			);
		} else {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, capacity, capacity_type, sold_count, reserved, status
					 FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
					(int) $tier->id
				)
			);
		}

		return $rows ? $rows : array();
	}

	/**
	 * Reduce peer rows to the tiers that determine the room's size.
	 *
	 * A retired tier no longer sells, so it cannot enlarge the room — but seats it
	 * already sold are still occupied, which is why `house_sold` counts every row.
	 *
	 * @param array<int, \stdClass> $peers Peer rows.
	 * @return array<int, array{capacity: ?int, capacity_type: string}>
	 */
	private static function sizing_tiers( array $peers ): array {
		$tiers = array();

		foreach ( $peers as $peer ) {
			if ( 'active' !== (string) $peer->status ) {
				continue;
			}

			// A pass tier occupies seats in this room but does not size it (NTE-156):
			// its allotment counts passes across the whole event, not chairs here.
			if ( ! empty( $peer->is_pass ) ) {
				continue;
			}

			$tiers[] = array(
				'capacity'      => null !== $peer->capacity ? (int) $peer->capacity : null,
				'capacity_type' => (string) $peer->capacity_type,
			);
		}

		return $tiers;
	}

	/**
	 * Sum an integer column across peer rows.
	 *
	 * @param array<int, object> $peers  Peer rows.
	 * @param string             $column Column name.
	 * @return int
	 *
	 * @phpstan-param array<int, object> $peers
	 */
	private static function sum( array $peers, string $column ): int {
		$total = 0;

		foreach ( $peers as $peer ) {
			$total += (int) $peer->{$column};
		}

		return $total;
	}
}
