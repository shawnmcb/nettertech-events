<?php
/**
 * Template part: Single event current occurrence.
 *
 * Displays the current occurrence date/time with ticket actions.
 * On occurrence URLs, shows that occurrence. On event-level views it
 * falls back to the next upcoming occurrence when the Upcoming Dates
 * component is hidden (NTE-131).
 *
 * @package NetterTechEvents
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var \NetterTechEvents\Models\Event                   $context->event      The event.
 * @var \NetterTechEvents\Models\Occurrence              $context->occurrence Current occurrence.
 */

declare(strict_types=1);

use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;
use NetterTechEvents\TemplateLoader\Templates;

// Guard against direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// On event-level views (no occurrence in the URL) fall back to the next
// upcoming occurrence — but only when the Upcoming Dates component won't
// render it, so layouts with both components visible don't show the first
// date twice. Without this fallback, events configured to show only
// "Current Date/Time" lose their date row and ticket/RSVP actions on
// event-level views (NTE-131).
$nettertech_events_row_occurrence = $context->occurrence;
if ( ! $nettertech_events_row_occurrence
	&& ! in_array( 'upcoming_dates', (array) $context->get( 'visible_components', array() ), true ) ) {
	$nettertech_events_row_occurrence = $context->get( 'target_occ' );
}

if ( ! $nettertech_events_row_occurrence || ! $context->event ) {
	return;
}
?>

<section class="nte-single-event__dates" role="region" aria-label="<?php esc_attr_e( 'Event Date', 'nettertech-events' ); ?>">
	<ul class="nte-single-event__occurrences">
		<?php
		echo wp_kses(
			Templates::get_part(
				'occurrence-row',
				array(
					'occurrence'   => $nettertech_events_row_occurrence,
					'event'        => $context->event,
					'show_actions' => true,
				)
			),
			ShortcodeOutput::get_allowlist()
		);
		?>
	</ul>
</section>
