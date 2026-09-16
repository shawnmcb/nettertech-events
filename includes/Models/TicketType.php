<?php
/**
 * TicketType model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\TicketTypeScope;

/**
 * Represents a ticket type for an occurrence, event, or template.
 *
 * Each occurrence can have multiple ticket types (VIP, General, etc.)
 * with different prices and capacities.
 *
 * @since 0.8.0
 * @api
 */
class TicketType {

	/**
	 * Ticket type ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Parent occurrence ID (required for OCCURRENCE scope).
	 *
	 * @var int|null
	 */
	public ?int $occurrence_id = null;

	/**
	 * Ticket scope (occurrence, event, template).
	 *
	 * @var string
	 */
	public string $scope = 'occurrence';

	/**
	 * Parent event ID (required for EVENT and TEMPLATE scopes).
	 *
	 * @var int|null
	 */
	public ?int $event_id = null;

	/**
	 * Parent template ID (for OCCURRENCE tickets created from templates).
	 *
	 * @var int|null
	 */
	public ?int $template_id = null;

	/**
	 * Ticket type name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Description.
	 *
	 * @var string|null
	 */
	public ?string $description = null;

	/**
	 * Price (0.00 for free/RSVP).
	 *
	 * @var float
	 */
	public float $price = 0.00;

	/**
	 * Capacity type (fixed, unlimited, shared).
	 *
	 * - 'fixed': Uses the capacity column value (NULL = unlimited for backward compat)
	 * - 'unlimited': Explicit unlimited, capacity column ignored
	 * - 'shared': Draws from the occurrence's remaining capacity
	 *
	 * @var string
	 */
	public string $capacity_type = 'fixed';

	/**
	 * Capacity limit (null = unlimited for fixed type).
	 *
	 * Only used when capacity_type is 'fixed'.
	 *
	 * @var int|null
	 */
	public ?int $capacity = null;

	/**
	 * Number of tickets sold (denormalized for performance).
	 *
	 * @var int
	 */
	public int $sold_count = 0;

	/**
	 * Stock status (in_stock, low_stock, out_of_stock).
	 *
	 * @var string
	 */
	public string $stock_status = 'in_stock';

	/**
	 * Sale start datetime.
	 *
	 * @var string|null
	 */
	public ?string $sale_start = null;

	/**
	 * Sale end datetime.
	 *
	 * @var string|null
	 */
	public ?string $sale_end = null;

	/**
	 * Minimum tickets per order.
	 *
	 * @var int
	 */
	public int $min_per_order = 1;

	/**
	 * Maximum tickets per order.
	 *
	 * @var int
	 */
	public int $max_per_order = 10;

	/**
	 * Sort order for display.
	 *
	 * @var int
	 */
	public int $sort_order = 0;

	/**
	 * Status (active, inactive, sold_out).
	 *
	 * @var string
	 */
	public string $status = 'active';

	/**
	 * WooCommerce product ID.
	 *
	 * @var int|null
	 */
	public ?int $wc_product_id = null;

	/**
	 * WooCommerce variation ID.
	 *
	 * @var int|null
	 */
	public ?int $wc_variation_id = null;

	/**
	 * Source of ticket creation (woocommerce, rsvp, manual).
	 *
	 * Used for lossless round-trip migration between VE and TEC.
	 *
	 * @var string
	 */
	public string $source = 'woocommerce';

	/**
	 * Valid source values.
	 *
	 * @var array<string>
	 */
	public const SOURCES = array( 'woocommerce', 'rsvp', 'manual' );

	/**
	 * Created timestamp.
	 *
	 * @var string|null
	 */
	public ?string $created_at = null;

	/**
	 * Updated timestamp.
	 *
	 * @var string|null
	 */
	public ?string $updated_at = null;

	/**
	 * Valid statuses.
	 *
	 * @var array<string>
	 */
	public const STATUSES = array( 'active', 'draft', 'inactive', 'sold_out' );

	/**
	 * Create a TicketType from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row  = (object) $row;
		$type = new self();

		$type->id              = isset( $row->id ) ? (int) $row->id : null;
		$type->occurrence_id   = isset( $row->occurrence_id ) ? (int) $row->occurrence_id : null;
		$type->scope           = $row->scope ?? 'occurrence';
		$type->event_id        = isset( $row->event_id ) ? (int) $row->event_id : null;
		$type->template_id     = isset( $row->template_id ) ? (int) $row->template_id : null;
		$type->name            = $row->name ?? '';
		$type->description     = $row->description ?? null;
		$type->price           = (float) ( $row->price ?? 0.00 );
		$type->capacity_type   = $row->capacity_type ?? 'fixed';
		$type->capacity        = isset( $row->capacity ) ? (int) $row->capacity : null;
		$type->sold_count      = (int) ( $row->sold_count ?? 0 );
		$type->stock_status    = $row->stock_status ?? 'in_stock';
		$type->sale_start      = $row->sale_start ?? null;
		$type->sale_end        = $row->sale_end ?? null;
		$type->min_per_order   = (int) ( $row->min_per_order ?? 1 );
		$type->max_per_order   = (int) ( $row->max_per_order ?? 10 );
		$type->sort_order      = (int) ( $row->sort_order ?? 0 );
		$type->status          = $row->status ?? 'active';
		$type->wc_product_id   = isset( $row->wc_product_id ) ? (int) $row->wc_product_id : null;
		$type->wc_variation_id = isset( $row->wc_variation_id ) ? (int) $row->wc_variation_id : null;
		$type->source          = $row->source ?? 'woocommerce';
		$type->created_at      = $row->created_at ?? null;
		$type->updated_at      = $row->updated_at ?? null;

		return $type;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'occurrence_id'   => $this->occurrence_id,
			'scope'           => $this->scope,
			'event_id'        => $this->event_id,
			'template_id'     => $this->template_id,
			'name'            => $this->name,
			'description'     => $this->description,
			'price'           => $this->price,
			'capacity_type'   => $this->capacity_type,
			'capacity'        => $this->capacity,
			'sale_start'      => $this->sale_start,
			'sale_end'        => $this->sale_end,
			'min_per_order'   => $this->min_per_order,
			'max_per_order'   => $this->max_per_order,
			'sort_order'      => $this->sort_order,
			'status'          => $this->status,
			'wc_product_id'   => $this->wc_product_id,
			'wc_variation_id' => $this->wc_variation_id,
			'source'          => $this->source,
		);
	}

	/**
	 * Get format specifiers for database operations.
	 *
	 * @return array<string>
	 */
	public function get_formats(): array {
		return array(
			'%d', // Occurrence ID.
			'%s', // Scope.
			'%d', // Event ID.
			'%d', // Template ID.
			'%s', // Name.
			'%s', // Description.
			'%f', // Price.
			'%s', // Capacity type.
			'%d', // Capacity.
			'%s', // Sale start.
			'%s', // Sale end.
			'%d', // Min per order.
			'%d', // Max per order.
			'%d', // Sort order.
			'%s', // Status.
			'%d', // WC product ID.
			'%d', // WC variation ID.
			'%s', // Source.
		);
	}

	/**
	 * Drop a capacity the tier's type does not own.
	 *
	 * Only a FIXED tier has a capacity of its own; for every other type the column is
	 * meaningless. It is not, however, reliably empty: an operator who types 250 into
	 * the box and then switches the tier to shared leaves 250 behind, and stored
	 * unchecked it reads back later as a real limit — a 250-seat allotment nobody
	 * granted. Clearing it here, at the storage boundary every writer passes through,
	 * is what keeps the column honest; readers should still resolve limits through
	 * HouseRule rather than trusting it (ADR-019).
	 *
	 * @since 1.1.2
	 *
	 * @return void
	 */
	public function normalize_capacity(): void {
		// An unrecognised type falls back to FIXED, matching HouseRule::own_remaining().
		// Two readings of the same column is the very drift this method exists to stop.
		$type = CapacityType::tryFrom( $this->capacity_type ) ?? CapacityType::FIXED;

		if ( CapacityType::FIXED !== $type ) {
			$this->capacity = null;
		}
	}

	/**
	 * Validate the ticket type data.
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		// Validate scope.
		if ( ! in_array( $this->scope, TicketTypeScope::values(), true ) ) {
			$errors[] = __( 'Invalid ticket scope.', 'nettertech-events' );
		}

		// Validate capacity type.
		if ( ! in_array( $this->capacity_type, CapacityType::values(), true ) ) {
			$errors[] = __( 'Invalid capacity type.', 'nettertech-events' );
		}

		// SHARED capacity type requires OCCURRENCE scope.
		if ( CapacityType::SHARED->value === $this->capacity_type
			&& TicketTypeScope::OCCURRENCE->value !== $this->scope ) {
			$errors[] = __( 'Shared capacity is only available for occurrence-scoped tickets.', 'nettertech-events' );
		}

		// Scope-specific validation.
		if ( TicketTypeScope::OCCURRENCE->value === $this->scope ) {
			// OCCURRENCE scope requires occurrence_id.
			if ( empty( $this->occurrence_id ) ) {
				$errors[] = __( 'Occurrence ID is required for occurrence-scoped tickets.', 'nettertech-events' );
			}
		} elseif ( in_array( $this->scope, array( TicketTypeScope::EVENT->value, TicketTypeScope::TEMPLATE->value ), true ) ) {
			// EVENT and TEMPLATE scopes require event_id.
			if ( empty( $this->event_id ) ) {
				$errors[] = __( 'Event ID is required for event and template scoped tickets.', 'nettertech-events' );
			}
			// These scopes should NOT have occurrence_id.
			if ( ! empty( $this->occurrence_id ) ) {
				$errors[] = __( 'Occurrence ID must be empty for event and template scoped tickets.', 'nettertech-events' );
			}
		}

		if ( empty( $this->name ) ) {
			$errors[] = __( 'Ticket type name is required.', 'nettertech-events' );
		}

		if ( $this->price < 0 ) {
			$errors[] = __( 'Price cannot be negative.', 'nettertech-events' );
		}

		if ( ! in_array( $this->status, self::STATUSES, true ) ) {
			$errors[] = __( 'Invalid ticket type status.', 'nettertech-events' );
		}

		if ( ! in_array( $this->source, self::SOURCES, true ) ) {
			$errors[] = __( 'Invalid ticket type source.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Check if this is a free ticket type.
	 *
	 * @return bool
	 */
	public function is_free(): bool {
		return $this->price <= 0;
	}

	/**
	 * Check if ticket type is currently on sale.
	 *
	 * `sale_start` and `sale_end` are stored as bare wall-clock, exactly as the operator
	 * typed them — the column records no zone. The zone they were meant in is the
	 * *event's*, which is why callers pass it: an occurrence in Sydney sold from a site
	 * in Chicago must open its window at 9am Sydney time, not 9am Chicago time. Reading
	 * the window against the site clock is how The Events Calendar left Sydney early-bird
	 * tickets hidden for eleven hours past their intended release (NTE-148).
	 *
	 * The site zone is the default so that a caller with no occurrence in hand behaves as
	 * before, and single-timezone sites — nearly all of them — see no change at all.
	 *
	 * @since 1.1.2 Accepts the event's timezone; previously evaluated against the site clock.
	 *
	 * @param DateTimeZone|null $timezone The event's timezone. Defaults to the site's.
	 * @return bool
	 */
	public function is_on_sale( ?DateTimeZone $timezone = null ): bool {
		if ( 'active' !== $this->status ) {
			return false;
		}

		$zone = $timezone ?? wp_timezone();
		$now  = new DateTimeImmutable( 'now', $zone );

		$start = $this->parse_wall_clock( $this->sale_start, $zone );
		if ( null !== $start && $now < $start ) {
			return false;
		}

		$end = $this->parse_wall_clock( $this->sale_end, $zone );
		if ( null !== $end && $now > $end ) {
			return false;
		}

		return true;
	}

	/**
	 * Read a stored wall-clock as a moment in the given zone.
	 *
	 * An absent or unreadable bound is no bound: a window we cannot parse must not
	 * silently refuse every sale.
	 *
	 * @param string|null  $wall_clock The stored datetime, or null.
	 * @param DateTimeZone $zone       The zone to read it in.
	 * @return DateTimeImmutable|null The moment, or null when there is no usable bound.
	 */
	private function parse_wall_clock( ?string $wall_clock, DateTimeZone $zone ): ?DateTimeImmutable {
		if ( null === $wall_clock || '' === $wall_clock ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $wall_clock, $zone );
		} catch ( \Exception $e ) {
			unset( $e ); // Unreadable stored value — treat the bound as absent.
			return null;
		}
	}

	/**
	 * Get formatted price string.
	 *
	 * @return string
	 */
	public function get_formatted_price(): string {
		if ( $this->is_free() ) {
			return __( 'Free', 'nettertech-events' );
		}

		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $this->price );
		}

		return '$' . number_format( $this->price, 2 );
	}

	/**
	 * Check if ticket type is sold out.
	 *
	 * @return bool
	 */
	public function is_sold_out(): bool {
		return 'out_of_stock' === $this->stock_status;
	}

	/**
	 * Whether this tier's sale window has closed.
	 *
	 * Distinct from "not on sale": a tier can be off sale because its window has
	 * not opened yet, and the public page must not call that closed.
	 *
	 * @since 1.4.7
	 *
	 * @param DateTimeZone|null $timezone The event's timezone. Defaults to the site's.
	 * @return bool True when a sale end is set and has passed.
	 */
	public function sale_has_ended( ?DateTimeZone $timezone = null ): bool {
		$zone = $timezone ?? wp_timezone();
		$end  = $this->parse_wall_clock( $this->sale_end, $zone );

		return null !== $end && new DateTimeImmutable( 'now', $zone ) > $end;
	}

	/**
	 * Whether this tier's sale window has not opened yet.
	 *
	 * @since 1.4.7
	 *
	 * @param DateTimeZone|null $timezone The event's timezone. Defaults to the site's.
	 * @return bool True when a sale start is set and is still in the future.
	 */
	public function sale_not_yet_open( ?DateTimeZone $timezone = null ): bool {
		$zone  = $timezone ?? wp_timezone();
		$start = $this->parse_wall_clock( $this->sale_start, $zone );

		return null !== $start && new DateTimeImmutable( 'now', $zone ) < $start;
	}

	/**
	 * Check if ticket type has low stock.
	 *
	 * @return bool
	 */
	public function is_low_stock(): bool {
		return 'low_stock' === $this->stock_status;
	}

	/**
	 * Get available count for FIXED capacity type (computed from sold_count).
	 *
	 * Note: this reads the tier's own allotment only. Tiers on an occurrence share
	 * one house, so a caller deciding whether a seat may actually be sold must go
	 * through CapacityService::get_available_count(), which floors the tier by the
	 * house remainder (see HouseRule).
	 *
	 * @return int|null Available tickets (null = unlimited).
	 */
	public function get_available_count(): ?int {
		// UNLIMITED type always returns null (unlimited).
		if ( CapacityType::UNLIMITED->value === $this->capacity_type ) {
			return null;
		}

		// SHARED type requires CapacityService calculation.
		// Return null here; actual availability is calculated in CapacityService.
		if ( CapacityType::SHARED->value === $this->capacity_type ) {
			return null;
		}

		// FIXED type with null capacity = unlimited (backward compat).
		if ( null === $this->capacity ) {
			return null;
		}

		return max( 0, $this->capacity - $this->sold_count );
	}

	/**
	 * Check if quantity is available for FIXED capacity type.
	 *
	 * Note: this reads the tier's own allotment only, and does not know about the
	 * house the tier sells into. CapacityService::has_availability() is the check
	 * that gates a sale (see HouseRule).
	 *
	 * @param int $quantity Quantity to check.
	 * @return bool
	 */
	public function has_availability( int $quantity = 1 ): bool {
		// UNLIMITED type is always available.
		if ( CapacityType::UNLIMITED->value === $this->capacity_type ) {
			return true;
		}

		// SHARED type requires CapacityService calculation.
		// Return true here; actual availability is checked in CapacityService.
		if ( CapacityType::SHARED->value === $this->capacity_type ) {
			return true;
		}

		// FIXED type with null capacity = unlimited.
		if ( null === $this->capacity ) {
			return true;
		}

		return $this->get_available_count() >= $quantity;
	}

	/**
	 * Get the CapacityType enum for this ticket type.
	 *
	 * @return CapacityType
	 */
	public function get_capacity_type_enum(): CapacityType {
		return CapacityType::from( $this->capacity_type );
	}

	/**
	 * Check if this ticket type uses shared capacity.
	 *
	 * @return bool
	 */
	public function uses_shared_capacity(): bool {
		return CapacityType::SHARED->value === $this->capacity_type;
	}

	/**
	 * Check if this ticket type has unlimited capacity.
	 *
	 * @return bool
	 */
	public function has_unlimited_capacity(): bool {
		if ( CapacityType::UNLIMITED->value === $this->capacity_type ) {
			return true;
		}

		// FIXED with NULL capacity is also unlimited for backward compatibility.
		if ( CapacityType::FIXED->value === $this->capacity_type && null === $this->capacity ) {
			return true;
		}

		return false;
	}
}
