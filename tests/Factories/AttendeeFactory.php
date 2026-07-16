<?php
/**
 * Attendee factory for tests.
 *
 * @package NetterTechEvents\Tests\Factories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Factories;

use NetterTechEvents\Enums\AttendeeStatus;
use NetterTechEvents\Models\Attendee;

/**
 * Factory for creating Attendee test fixtures.
 */
class AttendeeFactory {

	/**
	 * Counter for unique IDs.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	/**
	 * Create an Attendee instance.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Attendee
	 */
	public static function create( array $attributes = [] ): Attendee {
		++self::$counter;

		// Convert enum to string value if passed.
		$status = $attributes['status'] ?? AttendeeStatus::CONFIRMED;
		if ( $status instanceof AttendeeStatus ) {
			$status = $status->value;
		}

		$attendee                   = new Attendee();
		$attendee->id               = $attributes['id'] ?? self::$counter;
		$attendee->occurrence_id    = $attributes['occurrence_id'] ?? 1;
		$attendee->ticket_type_id   = $attributes['ticket_type_id'] ?? 1;
		$attendee->wc_order_id      = $attributes['wc_order_id'] ?? null;
		$attendee->name             = $attributes['name'] ?? 'John Doe';
		$attendee->email            = $attributes['email'] ?? 'john@example.com';
		$attendee->phone            = $attributes['phone'] ?? '555-1234';
		$attendee->quantity         = $attributes['quantity'] ?? 1;
		$attendee->status           = $status;
		$attendee->checked_in_at    = $attributes['checked_in_at'] ?? null;

		return $attendee;
	}

	/**
	 * Create a confirmed attendee.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Attendee
	 */
	public static function confirmed( array $attributes = [] ): Attendee {
		return self::create( array_merge( [ 'status' => AttendeeStatus::CONFIRMED ], $attributes ) );
	}

	/**
	 * Create a checked-in attendee.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Attendee
	 */
	public static function checkedIn( array $attributes = [] ): Attendee {
		return self::create(
			array_merge(
				[
					'status'        => AttendeeStatus::CONFIRMED,
					'checked_in_at' => gmdate( 'Y-m-d H:i:s' ),
				],
				$attributes
			)
		);
	}

	/**
	 * Create a cancelled attendee.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Attendee
	 */
	public static function cancelled( array $attributes = [] ): Attendee {
		return self::create( array_merge( [ 'status' => AttendeeStatus::CANCELLED ], $attributes ) );
	}

	/**
	 * Create an RSVP attendee (no WC order).
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Attendee
	 */
	public static function rsvp( array $attributes = [] ): Attendee {
		return self::create(
			array_merge(
				[
					'wc_order_id' => null,
					'status'      => AttendeeStatus::CONFIRMED,
				],
				$attributes
			)
		);
	}

	/**
	 * Create an attendee with multiple tickets.
	 *
	 * @param int                  $quantity   Number of tickets.
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Attendee
	 */
	public static function withQuantity( int $quantity, array $attributes = [] ): Attendee {
		return self::create( array_merge( [ 'quantity' => $quantity ], $attributes ) );
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
