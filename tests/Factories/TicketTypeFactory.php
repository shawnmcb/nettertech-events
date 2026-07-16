<?php
/**
 * TicketType factory for tests.
 *
 * @package NetterTechEvents\Tests\Factories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Factories;

use NetterTechEvents\Models\TicketType;

/**
 * Factory for creating TicketType test fixtures.
 */
class TicketTypeFactory {

	/**
	 * Counter for unique IDs.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	/**
	 * Create a TicketType instance.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return TicketType
	 */
	public static function create( array $attributes = [] ): TicketType {
		++self::$counter;

		$ticket_type                = new TicketType();
		$ticket_type->id            = $attributes['id'] ?? self::$counter;
		$ticket_type->occurrence_id = $attributes['occurrence_id'] ?? 1;
		$ticket_type->name          = $attributes['name'] ?? 'General Admission';
		$ticket_type->description   = $attributes['description'] ?? null;
		$ticket_type->price         = $attributes['price'] ?? 25.00;
		// Use array_key_exists for capacity since null is a valid value (unlimited).
		$ticket_type->capacity      = array_key_exists( 'capacity', $attributes ) ? $attributes['capacity'] : 100;
		$ticket_type->sold_count    = $attributes['sold_count'] ?? 0;
		$ticket_type->stock_status  = $attributes['stock_status'] ?? 'in_stock';
		$ticket_type->min_per_order = $attributes['min_per_order'] ?? 1;
		$ticket_type->max_per_order = $attributes['max_per_order'] ?? 10;
		$ticket_type->status        = $attributes['status'] ?? 'active';
		$ticket_type->wc_product_id = $attributes['wc_product_id'] ?? null;
		$ticket_type->sale_start    = $attributes['sale_start'] ?? null;
		$ticket_type->sale_end      = $attributes['sale_end'] ?? null;

		return $ticket_type;
	}

	/**
	 * Create a free ticket type.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return TicketType
	 */
	public static function free( array $attributes = [] ): TicketType {
		return self::create( array_merge( [ 'price' => 0.0, 'name' => 'Free Entry' ], $attributes ) );
	}

	/**
	 * Create a VIP ticket type.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return TicketType
	 */
	public static function vip( array $attributes = [] ): TicketType {
		return self::create(
			array_merge(
				[
					'name'     => 'VIP',
					'price'    => 100.00,
					'capacity' => 20,
				],
				$attributes
			)
		);
	}

	/**
	 * Create a sold-out ticket type.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return TicketType
	 */
	public static function soldOut( array $attributes = [] ): TicketType {
		$capacity = $attributes['capacity'] ?? 100;

		return self::create(
			array_merge(
				[
					'capacity'     => $capacity,
					'sold_count'   => $capacity,
					'stock_status' => 'out_of_stock',
				],
				$attributes
			)
		);
	}

	/**
	 * Create a ticket type with unlimited capacity.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return TicketType
	 */
	public static function unlimited( array $attributes = [] ): TicketType {
		return self::create( array_merge( [ 'capacity' => null ], $attributes ) );
	}

	/**
	 * Create a low-stock ticket type.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return TicketType
	 */
	public static function lowStock( array $attributes = [] ): TicketType {
		return self::create(
			array_merge(
				[
					'capacity'     => 100,
					'sold_count'   => 90,
					'stock_status' => 'low_stock',
				],
				$attributes
			)
		);
	}

	/**
	 * Reset the counter.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$counter = 0;
	}
}
