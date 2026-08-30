<?php
/**
 * Template part: Occurrence Row
 *
 * Displays a single occurrence in a styled row format with day/date, time, and actions.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/parts/occurrence-row.php
 *
 * Available variables (read via $context):
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var \NetterTechEvents\Models\Occurrence $context->occurrence   The occurrence to display.
 * @var \NetterTechEvents\Models\Event      $context->event        The parent event.
 * @var bool                                $context->show_actions Whether to show ticket/RSVP actions (default: true).
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * PHPStan reads only standalone assertions, not the header var-list
 * (audit GAP-029 rollout).
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context
 */

// Ensure required variables are set.
if ( ! $context->occurrence || ! $context->event ) {
	return;
}

// Display timestamps: strtotime(wall-clock) + date_i18n renders the authored local
// components (WP idiom) — leave unchanged.
$nettertech_events_start_time = strtotime( $context->occurrence->start_datetime );
$nettertech_events_end_time   = $context->occurrence->end_datetime ? strtotime( $context->occurrence->end_datetime ) : null;
// Past once it has ended, not once it has started — and never re-derived here. is_past()
// owns the rule (true instants, authoring zone, DST-aware); a copy of it in a template is a
// copy that drifts.
$nettertech_events_is_past = $context->occurrence->is_past();

$nettertech_events_row_classes = array( 'nte-single-event__occurrence' );
if ( $nettertech_events_is_past ) {
	$nettertech_events_row_classes[] = 'nte-single-event__occurrence--past';
}
?>
<li class="<?php echo esc_attr( implode( ' ', $nettertech_events_row_classes ) ); ?>">
	<div class="nte-single-event__occurrence-header">
		<div class="nte-single-event__occurrence-date">
			<time class="nte-single-event__occurrence-day" datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $nettertech_events_start_time ) ); ?>"><?php echo esc_html( date_i18n( 'l', $nettertech_events_start_time ) ); ?></time>
			<time class="nte-single-event__occurrence-full-date" datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $nettertech_events_start_time ) ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), $nettertech_events_start_time ) ); ?></time>
		</div>
		<div class="nte-single-event__occurrence-time">
			<?php if ( $context->occurrence->all_day ) : ?>
				<span><?php esc_html_e( 'All Day', 'nettertech-events' ); ?></span>
			<?php else : ?>
				<time datetime="<?php echo esc_attr( gmdate( 'c', $nettertech_events_start_time ) ); ?>">
					<?php echo esc_html( date_i18n( get_option( 'time_format' ), $nettertech_events_start_time ) ); ?>
				</time>
				<?php if ( $nettertech_events_end_time ) : ?>
					- <time datetime="<?php echo esc_attr( gmdate( 'c', $nettertech_events_end_time ) ); ?>"><?php echo esc_html( date_i18n( get_option( 'time_format' ), $nettertech_events_end_time ) ); ?></time>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php if ( $context->get( 'show_actions', true ) ) : ?>
		<div class="nte-occurrence-actions">
			<?php
			/**
			 * Hook to add ticket buttons or other actions.
			 *
			 * @param \NetterTechEvents\Models\Occurrence $occurrence The occurrence.
			 * @param \NetterTechEvents\Models\Event      $event      The parent event.
			 */
			ob_start();
			do_action( 'nettertech_events_single_occurrence_actions', $context->occurrence, $context->event );

			/*
			 * Defensive escape applied to the do_action capture so third-party
			 * listeners cannot inject <script>, <iframe>, or other dangerous
			 * tags into the occurrence-actions surface. wp_kses_post()'s
			 * default post-content allowlist strips the tags our own internal
			 * listeners (TicketDisplay::render_occurrence_actions,
			 * ICalButton::render) need — <form>, <input>, <select>, <label>,
			 * inline <svg> — so it is merged with the plugin's canonical
			 * ShortcodeOutput::get_allowlist(), the single contract-tested
			 * source for interactive markup (AllowlistContractTest).
			 *
			 * Do NOT hand-build an allowlist here. A previous local copy used
			 * an 'aria-*' wildcard attribute key, which wp_kses does not
			 * support (only 'data-*' is special-cased in core), silently
			 * stripping aria-label/aria-required from the RSVP form — the
			 * exact drift INV-R2 (.coherence-invariants.md) exists to catch.
			 */
			$nettertech_events_allowed_html = array_merge(
				wp_kses_allowed_html( 'post' ),
				\NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput::get_allowlist()
			);
			echo wp_kses( ob_get_clean(), $nettertech_events_allowed_html );
			?>
		</div>
	<?php endif; ?>
</li>
