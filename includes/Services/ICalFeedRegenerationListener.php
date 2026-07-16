<?php
/**
 * Static iCal feed regeneration listener.
 *
 * When static-feed mode is enabled, listens on event-lifecycle hooks and
 * schedules a single debounced cron run that rebuilds the static feed file.
 * See NTE-016.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\NetterTechEventsSettings;

/**
 * Schedules debounced regeneration of the static iCal feed file.
 *
 * Registration is a no-op unless static-feed mode is enabled, so a site that
 * never opts in pays nothing. A burst of saves (e.g. a bulk import) schedules
 * the regeneration once: wp_schedule_single_event() is guarded by
 * wp_next_scheduled() against the fixed hook name, so additional saves before
 * the run fires are coalesced.
 *
 * @since 3.13.0
 */
class ICalFeedRegenerationListener {

	/**
	 * Debounce delay before the regeneration runs (seconds).
	 *
	 * @var int
	 */
	private const DEBOUNCE_SECONDS = 30;

	/**
	 * Event-lifecycle hooks that invalidate the static feed.
	 *
	 * @var array<string>
	 */
	private const INVALIDATION_HOOKS = array(
		Hooks::EVENT_CREATED,
		Hooks::EVENT_UPDATED,
		Hooks::EVENT_DELETED,
		Hooks::OCCURRENCE_CREATED,
		Hooks::OCCURRENCE_DELETED,
		Hooks::OCCURRENCE_CANCELLED,
		Hooks::OCCURRENCE_STATUS_CHANGED,
	);

	/**
	 * Static feed file writer.
	 *
	 * @var ICalFileWriter
	 */
	private ICalFileWriter $file_writer;

	/**
	 * Constructor.
	 *
	 * @param ICalFileWriter $file_writer Static feed file writer.
	 */
	public function __construct( ICalFileWriter $file_writer ) {
		$this->file_writer = $file_writer;
	}

	/**
	 * Register hook listeners and the cron callback.
	 *
	 * The cron callback is always bound (so an already-scheduled run still
	 * fires after the mode is toggled off mid-cycle), but invalidation
	 * listeners are only attached when static mode is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Hooks::ICAL_STATIC_REGENERATE, array( $this, 'run' ) );
		add_action( 'admin_post_nettertech_events_regenerate_ical', array( $this, 'handle_manual_regeneration' ) );

		if ( ! NetterTechEventsSettings::from_option()->performance->ical_feed_static_mode ) {
			return;
		}

		foreach ( self::INVALIDATION_HOOKS as $hook ) {
			add_action( $hook, array( $this, 'schedule_regeneration' ) );
		}
	}

	/**
	 * Schedule a single debounced regeneration if one is not already pending.
	 *
	 * @return void
	 */
	public function schedule_regeneration(): void {
		if ( wp_next_scheduled( Hooks::ICAL_STATIC_REGENERATE ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, Hooks::ICAL_STATIC_REGENERATE );
	}

	/**
	 * Cron callback: rebuild the static feed file.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->file_writer->regenerate();
	}

	/**
	 * Admin-post handler for the "Regenerate now" settings button.
	 *
	 * Capability is checked before the nonce per project security policy.
	 * Redirects back to the Advanced settings tab with a status flag.
	 *
	 * @return void
	 */
	public function handle_manual_regeneration(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nettertech-events' ) );
		}

		check_admin_referer( 'nettertech_events_regenerate_ical' );

		$ok = $this->file_writer->regenerate();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'nettertech-events-settings',
					'tab'              => 'email',
					'nte_ical_rebuilt' => $ok ? '1' : '0',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
