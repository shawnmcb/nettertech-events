<?php
/**
 * Template part: Single event upcoming dates.
 *
 * Displays a list of upcoming occurrences for events with multiple dates.
 * Only shown when NOT viewing a specific occurrence URL.
 *
 * @package NetterTechEvents
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var \NetterTechEvents\Models\Event                   $context->event       The event.
 * @var \NetterTechEvents\Models\Occurrence[]            $context->occurrences Array of upcoming occurrences.
 */

declare(strict_types=1);

use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;
use NetterTechEvents\TemplateLoader\Templates;

// Guard against direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This component only renders when there are upcoming occurrences.
if ( empty( $context->get( 'occurrences', array() ) ) || ! $context->event ) {
	return;
}
?>

<section class="nte-single-event__dates" role="region" aria-label="<?php esc_attr_e( 'Event Dates', 'nettertech-events' ); ?>">
	<?php if ( count( $context->get( 'occurrences', array() ) ) > 1 ) : ?>
		<h2 class="nte-single-event__section-title"><?php esc_html_e( 'Upcoming Dates', 'nettertech-events' ); ?></h2>
	<?php endif; ?>
	<ul class="nte-single-event__occurrences">
		<?php foreach ( $context->get( 'occurrences', array() ) as $nettertech_events_occ ) : ?>
			<?php
			echo wp_kses(
				Templates::get_part(
					'occurrence-row',
					array(
						'occurrence'   => $nettertech_events_occ,
						'event'        => $context->event,
						'show_actions' => true,
					)
				),
				ShortcodeOutput::get_allowlist()
			);
			?>
		<?php endforeach; ?>
	</ul>
</section>
