<?php
/**
 * Resolves whether the waitlist is enabled for an occurrence.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Event override → site default → `nettertech_events_has_waitlist` filter (NTE-214).
 *
 * One rule, used by every waitlist surface — the sold-out ticket panel, the
 * RSVP "event is full" prompt, and the REST join endpoint — so turning the
 * waitlist off hides the form *and* rejects direct joins. The filter runs
 * last so an extension can still veto (never silently re-enable) — the same
 * hook base always fired, now with the occurrence as a second argument.
 *
 * The parent event is loaded through the injected repository, never through
 * `Occurrence::get_event()` alone: repository-built occurrences may carry no
 * event repo, so that accessor can be null in production.
 *
 * @since 1.4.4
 */
class WaitlistAvailabilityResolver {

	/**
	 * Settings key holding the site default.
	 */
	public const OPTION_KEY = 'enable_waitlist';

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface $event_repo Event repository.
	 */
	public function __construct( private readonly EventRepositoryInterface $event_repo ) {
	}

	/**
	 * Site-wide default (Settings → Ticketing → Waitlist). Defaults to on so
	 * existing installs keep their behaviour.
	 *
	 * @return bool
	 */
	public function site_default(): bool {
		$settings = get_option( 'nettertech_events_settings', array() );
		if ( ! is_array( $settings ) || ! array_key_exists( self::OPTION_KEY, $settings ) ) {
			return true;
		}

		return (bool) $settings[ self::OPTION_KEY ];
	}

	/**
	 * Whether the waitlist is enabled for an event (before the extension filter).
	 *
	 * @param Event|null $event Event, or null when unresolvable (falls back to the site default).
	 * @return bool
	 */
	public function is_enabled_for_event( ?Event $event ): bool {
		if ( null !== $event && null !== $event->waitlist_enabled ) {
			return $event->waitlist_enabled;
		}

		return $this->site_default();
	}

	/**
	 * Whether the waitlist is enabled for an occurrence — the value every
	 * surface must consult.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @return bool
	 */
	public function is_enabled_for_occurrence( Occurrence $occurrence ): bool {
		$event = $occurrence->get_event();
		if ( null === $event && $occurrence->event_id ) {
			$event = $this->event_repo->find( (int) $occurrence->event_id );
		}

		$enabled = $this->is_enabled_for_event( $event );

		/**
		 * Filters whether the waitlist is available for an occurrence.
		 *
		 * Runs after the event override and site default have been resolved, so
		 * extensions can veto but should not silently re-enable a waitlist the
		 * operator turned off.
		 *
		 * @since 1.0.2
		 * @since 1.4.4 `$occurrence` argument added; `$has_waitlist` now reflects the
		 *              operator's site default and per-event override instead of `true`.
		 *
		 * @param bool       $has_waitlist Resolved availability.
		 * @param Occurrence $occurrence   The occurrence being rendered or joined.
		 */
		return (bool) apply_filters( 'nettertech_events_has_waitlist', $enabled, $occurrence );
	}
}
