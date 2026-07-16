<?php
/**
 * Event factory for tests.
 *
 * @package NetterTechEvents\Tests\Factories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Factories;

use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;

/**
 * Factory for creating Event test fixtures.
 */
class EventFactory {

	/**
	 * Default attributes.
	 *
	 * @var array<string, mixed>
	 */
	private static array $defaults = [
		'post_id'       => 1,
		'title'         => 'Test Event',
		'slug'          => 'test-event',
		'description'   => 'Test event description',
		'event_type'    => 'single',
		'status'        => EventStatus::PUBLISHED,
		'venue_name'    => 'Test Venue',
		'venue_address' => '123 Test St',
	];

	/**
	 * Counter for unique IDs.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	/**
	 * Create an Event instance.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Event
	 */
	public static function create( array $attributes = [] ): Event {
		++self::$counter;

		$data = array_merge( self::$defaults, $attributes );

		$event                = new Event();
		$event->id            = $data['id'] ?? self::$counter;
		$event->post_id       = $data['post_id'];
		$event->title         = $data['title'];
		$event->slug          = $data['slug'];
		$event->description   = $data['description'];
		$event->event_type    = $data['event_type'];
		$event->status        = $data['status'] instanceof EventStatus
			? $data['status']
			: ( EventStatus::tryFrom( $data['status'] ) ?? EventStatus::DRAFT );
		$event->venue_name    = $data['venue_name'];
		$event->venue_address = $data['venue_address'];

		if ( array_key_exists( 'recurrence_rule', $data ) ) {
			$event->recurrence_rule = $data['recurrence_rule'];
		}

		return $event;
	}

	/**
	 * Create a single event.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Event
	 */
	public static function single( array $attributes = [] ): Event {
		return self::create( array_merge( [ 'event_type' => 'single' ], $attributes ) );
	}

	/**
	 * Create a recurring event.
	 *
	 * Supplies a default weekly RRULE so the returned Event passes
	 * Event::validate() (which requires recurring events to carry a rule).
	 * Callers that need a specific rule override the 'recurrence_rule'
	 * attribute; callers that only need *some* valid rule get one by default.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Event
	 */
	public static function recurring( array $attributes = [] ): Event {
		return self::create(
			array_merge(
				[
					'event_type'      => 'recurring',
					'recurrence_rule' => 'FREQ=WEEKLY;COUNT=4',
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
