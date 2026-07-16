<?php
/**
 * Add to Calendar Dropdown
 *
 * Renders an "Add to Calendar" dropdown with links for Google Calendar,
 * Outlook 365, Outlook Live, and iCal file download.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Contracts\CalendarLinkServiceInterface;

/**
 * Renders an "Add to Calendar" dropdown with multiple calendar service options.
 *
 * @since 1.0.0
 */
class ICalButton {

	/**
	 * Calendar link service.
	 *
	 * @var CalendarLinkServiceInterface
	 */
	private CalendarLinkServiceInterface $calendar_link_service;

	/**
	 * Constructor.
	 *
	 * @param CalendarLinkServiceInterface $calendar_link_service Calendar link service.
	 */
	public function __construct( CalendarLinkServiceInterface $calendar_link_service ) {
		$this->calendar_link_service = $calendar_link_service;
	}

	/**
	 * Initialize the hook.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'nettertech_events_single_occurrence_actions', array( $this, 'render' ), 20, 2 );
	}

	/**
	 * Render the "Add to Calendar" dropdown for an occurrence.
	 *
	 * Skipped for past occurrences.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @param Event      $event      The parent event.
	 * @return void
	 */
	public function render( Occurrence $occurrence, Event $event ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		if ( $occurrence->has_ended() ) {
			return;
		}

		$links = $this->calendar_link_service->all_links( $occurrence );
		?>
		<div class="nte-add-to-calendar">
			<button type="button"
				class="nte-add-to-calendar__trigger"
				aria-expanded="false"
				aria-haspopup="true"
				aria-label="<?php esc_attr_e( 'Add to Calendar', 'nettertech-events' ); ?>">
				<svg class="nte-add-to-calendar__icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
					<rect x="2" y="3" width="12" height="11" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
					<path d="M2 6.5h12" stroke="currentColor" stroke-width="1.5"/>
					<path d="M5.5 1.5v3M10.5 1.5v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
				</svg>
				<?php esc_html_e( 'Add to Calendar', 'nettertech-events' ); ?>
				<svg class="nte-add-to-calendar__chevron" width="10" height="6" viewBox="0 0 10 6" fill="none" aria-hidden="true">
					<path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</button>
			<ul class="nte-add-to-calendar__menu" role="menu">
				<?php foreach ( $links as $provider => $link ) : ?>
					<li role="none">
						<a role="menuitem"
							class="nte-add-to-calendar__item nte-add-to-calendar__item--<?php echo esc_attr( $provider ); ?>"
							href="<?php echo esc_url( $link['url'] ); ?>"
							<?php echo 'ical' === $provider ? 'download' : 'target="_blank" rel="noopener noreferrer"'; ?>
						><?php echo esc_html( $link['label'] ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
