<?php
/**
 * Beaver Themer field connections for NTE data.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder\Themer;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Frontend\Router;
use NetterTechEvents\Models\Event;

/**
 * Registers FLPageData properties so Beaver Themer layouts can bind
 * dynamic text/photo modules directly to NTE event, space, and archive data.
 *
 * Three groups are exposed in the Beaver Builder field-connections picker:
 *
 * - **NetterTech Events: Event** — post-type properties available when
 *   editing any Singular or Header/Footer layout. Sources data from
 *   {@see Router::get_current_event()} at render time.
 * - **NetterTech Events: Space** — post-type properties for layouts that
 *   target the single-space context. Sources data from
 *   {@see Router::get_current_space()}.
 * - **NetterTech Events: Archive** — archive-type properties for layouts
 *   that target the events archive context.
 *
 * Getters are defensive: they read from the Router accessors, which return
 * null off the NTE virtual contexts. Each getter returns an empty string
 * (or 0 / empty array, per the declared type) when no source is available,
 * matching how core BB field connections behave on context mismatches.
 *
 * @since 1.1.0
 */
class FieldConnections {

	/**
	 * Group ID for event-level properties.
	 *
	 * @var string
	 */
	public const GROUP_EVENT = 'nettertech_events_event';

	/**
	 * Group ID for space-level properties.
	 *
	 * @var string
	 */
	public const GROUP_SPACE = 'nettertech_events_space';

	/**
	 * Group ID for archive-level properties.
	 *
	 * @var string
	 */
	public const GROUP_ARCHIVE = 'nettertech_events_archive';

	/**
	 * Register all field connections.
	 *
	 * Fires on `init` (priority 10) — late enough that BB has defined its
	 * FLPageData class, early enough to be present when the layout editor
	 * builds its connection picker on subsequent admin requests.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! class_exists( '\\FLPageData' ) ) {
			return;
		}

		add_action( 'init', array( $this, 'register' ), 11 );
	}

	/**
	 * Register groups and properties with FLPageData.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( '\\FLPageData' ) ) {
			return;
		}

		$this->register_group_event();
		$this->register_group_space();
		$this->register_group_archive();
	}

	/**
	 * Register the event group + its post-type properties.
	 *
	 * @return void
	 */
	private function register_group_event(): void {
		\FLPageData::add_group(
			self::GROUP_EVENT,
			array( 'label' => __( 'NetterTech Events: Event', 'nettertech-events' ) )
		);

		$properties = array(
			'title'              => array(
				'label' => __( 'Event title', 'nettertech-events' ),
				'type'  => 'string',
			),
			'description'        => array(
				'label' => __( 'Event description', 'nettertech-events' ),
				'type'  => 'string',
			),
			'excerpt'            => array(
				'label' => __( 'Event excerpt', 'nettertech-events' ),
				'type'  => 'string',
			),
			'url'                => array(
				'label' => __( 'Event URL', 'nettertech-events' ),
				'type'  => 'url',
			),
			'featured_image'     => array(
				'label' => __( 'Event featured image', 'nettertech-events' ),
				'type'  => 'photo',
			),
			'venue_name'         => array(
				'label' => __( 'Venue name', 'nettertech-events' ),
				'type'  => 'string',
			),
			'venue_address'      => array(
				'label' => __( 'Venue address', 'nettertech-events' ),
				'type'  => 'string',
			),
			'event_type'         => array(
				'label' => __( 'Event type (single / recurring / series_parent)', 'nettertech-events' ),
				'type'  => 'string',
			),
			'is_recurring'       => array(
				'label' => __( 'Event is recurring (yes / empty)', 'nettertech-events' ),
				'type'  => 'string',
			),
			'is_virtual'         => array(
				'label' => __( 'Event is virtual (yes / empty)', 'nettertech-events' ),
				'type'  => 'string',
			),
			'virtual_url'        => array(
				'label' => __( 'Virtual event URL', 'nettertech-events' ),
				'type'  => 'url',
			),
			'recurrence_summary' => array(
				'label' => __( 'Recurrence rule (raw RRULE)', 'nettertech-events' ),
				'type'  => 'string',
			),
		);

		foreach ( $properties as $key => $config ) {
			\FLPageData::add_post_property(
				self::GROUP_EVENT . '_' . $key,
				array_merge(
					$config,
					array(
						'group'  => self::GROUP_EVENT,
						'getter' => array( __CLASS__, 'get_event_' . $key ),
					)
				)
			);
		}
	}

	/**
	 * Register the space group + its post-type properties.
	 *
	 * @return void
	 */
	private function register_group_space(): void {
		\FLPageData::add_group(
			self::GROUP_SPACE,
			array( 'label' => __( 'NetterTech Events: Space', 'nettertech-events' ) )
		);

		$properties = array(
			'name'           => array(
				'label' => __( 'Space name', 'nettertech-events' ),
				'type'  => 'string',
			),
			'description'    => array(
				'label' => __( 'Space description', 'nettertech-events' ),
				'type'  => 'string',
			),
			'tagline'        => array(
				'label' => __( 'Space tagline', 'nettertech-events' ),
				'type'  => 'string',
			),
			'capacity'       => array(
				'label' => __( 'Space capacity', 'nettertech-events' ),
				'type'  => 'string',
			),
			'featured_image' => array(
				'label' => __( 'Space featured image', 'nettertech-events' ),
				'type'  => 'photo',
			),
		);

		foreach ( $properties as $key => $config ) {
			\FLPageData::add_post_property(
				self::GROUP_SPACE . '_' . $key,
				array_merge(
					$config,
					array(
						'group'  => self::GROUP_SPACE,
						'getter' => array( __CLASS__, 'get_space_' . $key ),
					)
				)
			);
		}
	}

	/**
	 * Register the archive group + its archive-type properties.
	 *
	 * @return void
	 */
	private function register_group_archive(): void {
		\FLPageData::add_group(
			self::GROUP_ARCHIVE,
			array( 'label' => __( 'NetterTech Events: Archive', 'nettertech-events' ) )
		);

		\FLPageData::add_archive_property(
			self::GROUP_ARCHIVE . '_title',
			array(
				'label'  => __( 'Archive title (Upcoming / Past)', 'nettertech-events' ),
				'group'  => self::GROUP_ARCHIVE,
				'type'   => 'string',
				'getter' => array( __CLASS__, 'get_archive_title' ),
			)
		);

		\FLPageData::add_archive_property(
			self::GROUP_ARCHIVE . '_context',
			array(
				'label'  => __( 'Archive context key (events_archive / past_events_archive)', 'nettertech-events' ),
				'group'  => self::GROUP_ARCHIVE,
				'type'   => 'string',
				'getter' => array( __CLASS__, 'get_archive_context' ),
			)
		);
	}

	// ----- Event getters ------------------------------------------------ //

	/**
	 * Resolve the current event from the Router singleton, or null off-context.
	 *
	 * Router::instance() throws when the plugin hasn't initialized its
	 * frontend yet — which happens in admin contexts where field-connection
	 * getters are exercised by the layout editor's preview. We treat any
	 * such state as "no current event".
	 *
	 * @return Event|null
	 */
	public static function current_event(): ?Event {
		if ( ! class_exists( Router::class ) ) {
			return null;
		}

		try {
			return Router::get_current_event();
		} catch ( \RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * Event title.
	 *
	 * @return string
	 */
	public static function get_event_title(): string {
		$event = self::current_event();
		return null === $event ? '' : $event->title;
	}

	/**
	 * Event description (HTML allowed).
	 *
	 * @return string
	 */
	public static function get_event_description(): string {
		$event = self::current_event();
		return null === $event ? '' : $event->description;
	}

	/**
	 * Event excerpt.
	 *
	 * @return string
	 */
	public static function get_event_excerpt(): string {
		$event = self::current_event();
		return null === $event ? '' : $event->excerpt;
	}

	/**
	 * Event permalink.
	 *
	 * @return string
	 */
	public static function get_event_url(): string {
		$event = self::current_event();
		return null === $event ? '' : $event->get_permalink();
	}

	/**
	 * Event featured image — attachment ID for BB's photo type.
	 *
	 * @return int
	 */
	public static function get_event_featured_image(): int {
		$event = self::current_event();
		return null === $event ? 0 : (int) ( $event->featured_image_id ?? 0 );
	}

	/**
	 * Venue name.
	 *
	 * @return string
	 */
	public static function get_event_venue_name(): string {
		$event = self::current_event();
		return null === $event ? '' : (string) ( $event->venue_name ?? '' );
	}

	/**
	 * Venue address.
	 *
	 * @return string
	 */
	public static function get_event_venue_address(): string {
		$event = self::current_event();
		return null === $event ? '' : (string) ( $event->venue_address ?? '' );
	}

	/**
	 * Event type ('single' / 'recurring' / 'series_parent').
	 *
	 * @return string
	 */
	public static function get_event_event_type(): string {
		$event = self::current_event();
		return null === $event ? '' : $event->event_type;
	}

	/**
	 * "yes" when the event is recurring, empty string otherwise.
	 *
	 * Stringly-typed for use in BB conditional-logic comparisons that
	 * expect a string value with a meaningful empty case.
	 *
	 * @return string
	 */
	public static function get_event_is_recurring(): string {
		$event = self::current_event();
		return ( null !== $event && $event->is_recurring() ) ? 'yes' : '';
	}

	/**
	 * "yes" when the event is virtual, empty string otherwise.
	 *
	 * @return string
	 */
	public static function get_event_is_virtual(): string {
		$event = self::current_event();
		return ( null !== $event && $event->is_virtual_event() ) ? 'yes' : '';
	}

	/**
	 * Virtual access URL.
	 *
	 * @return string
	 */
	public static function get_event_virtual_url(): string {
		$event = self::current_event();
		return null === $event ? '' : (string) ( $event->virtual_url ?? '' );
	}

	/**
	 * Raw recurrence rule string (RFC 5545 RRULE).
	 *
	 * @return string
	 */
	public static function get_event_recurrence_summary(): string {
		$event = self::current_event();
		return null === $event ? '' : (string) ( $event->recurrence_rule ?? '' );
	}

	// ----- Space getters ------------------------------------------------ //

	/**
	 * Resolve the current space (a stdClass row, not the Space model).
	 *
	 * See {@see self::current_event()} for the Router-singleton-not-ready
	 * rationale.
	 *
	 * @return object|null
	 */
	public static function current_space(): ?object {
		if ( ! class_exists( Router::class ) ) {
			return null;
		}

		try {
			return Router::get_current_space();
		} catch ( \RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * Read a public field from the current space, with default fallback.
	 *
	 * @param string $field    Field name on the stdClass row.
	 * @param string $fallback Default returned when off-context or field missing.
	 * @return string
	 */
	private static function space_string_field( string $field, string $fallback = '' ): string {
		$space = self::current_space();
		if ( null === $space || ! property_exists( $space, $field ) ) {
			return $fallback;
		}

		return (string) ( $space->{$field} ?? $fallback );
	}

	/**
	 * Space name.
	 *
	 * @return string
	 */
	public static function get_space_name(): string {
		return self::space_string_field( 'name' );
	}

	/**
	 * Space description.
	 *
	 * @return string
	 */
	public static function get_space_description(): string {
		return self::space_string_field( 'description' );
	}

	/**
	 * Space tagline.
	 *
	 * @return string
	 */
	public static function get_space_tagline(): string {
		return self::space_string_field( 'tagline' );
	}

	/**
	 * Space capacity (rendered as string for BB compatibility).
	 *
	 * @return string
	 */
	public static function get_space_capacity(): string {
		$space = self::current_space();
		if ( null === $space || ! property_exists( $space, 'capacity' ) ) {
			return '';
		}

		$capacity = (int) ( $space->capacity ?? 0 );
		return $capacity > 0 ? (string) $capacity : '';
	}

	/**
	 * Space featured image — attachment ID for BB's photo type.
	 *
	 * @return int
	 */
	public static function get_space_featured_image(): int {
		$space = self::current_space();
		if ( null === $space || ! property_exists( $space, 'featured_image_id' ) ) {
			return 0;
		}

		return (int) ( $space->featured_image_id ?? 0 );
	}

	// ----- Archive getters ---------------------------------------------- //

	/**
	 * Localized title for the current archive context.
	 *
	 * @return string
	 */
	public static function get_archive_title(): string {
		$context = Context::detect();
		if ( Context::EVENTS_ARCHIVE === $context ) {
			return __( 'Upcoming Events', 'nettertech-events' );
		}
		if ( Context::PAST_EVENTS_ARCHIVE === $context ) {
			return __( 'Past Events', 'nettertech-events' );
		}

		return '';
	}

	/**
	 * The detected archive context key (machine-readable).
	 *
	 * Useful for BB conditional-logic comparisons such as "show this row when
	 * archive context equals past_events_archive".
	 *
	 * @return string
	 */
	public static function get_archive_context(): string {
		$context = Context::detect();
		if ( Context::EVENTS_ARCHIVE === $context || Context::PAST_EVENTS_ARCHIVE === $context ) {
			return $context;
		}

		return '';
	}
}
