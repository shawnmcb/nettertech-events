<?php
/**
 * Template part: More Dates Navigation
 *
 * Displays sibling occurrences for a recurring event with links to navigate between dates.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/parts/more-dates.php
 *
 * Available variables (read via $context):
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var \NetterTechEvents\Models\Occurrence $context->occurrence     The current occurrence being viewed.
 * @var \NetterTechEvents\Models\Event      $context->event          The parent event.
 * @var array                               $context->siblings       Array with 'previous', 'next', 'all' keys containing occurrences.
 * @var int                                 $context->sibling_count  Total number of sibling occurrences.
 * @var bool                                $context->show_view_all  Whether to show "View all" link (default: true).
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Ensure required variables are set.
if ( ! $context->occurrence || ! $context->event || ! $context->siblings ) {
	return;
}

// Only show if there are multiple occurrences.
if ( $context->get( 'sibling_count', count( $context->siblings['all'] ?? array() ) ) < 2 ) {
	return;
}
?>
<nav class="nte-occurrence-siblings" aria-label="<?php esc_attr_e( 'Other dates for this event', 'nettertech-events' ); ?>">
	<h3 class="nte-occurrence-siblings__title">
		<?php esc_html_e( 'More Dates', 'nettertech-events' ); ?>
		<?php if ( $context->get( 'show_view_all', true ) ) : ?>
			<a href="<?php echo esc_url( $context->event->get_series_url() ); ?>" class="nte-occurrence-siblings__view-all">
				<?php esc_html_e( 'View all', 'nettertech-events' ); ?>
			</a>
		<?php endif; ?>
	</h3>
	<ul class="nte-occurrence-siblings__list">
		<?php
		foreach ( $context->siblings['all'] as $nettertech_events_sibling ) :
			$nettertech_events_sibling->set_event( $context->event );
			$nettertech_events_is_current   = $nettertech_events_sibling->id === $context->occurrence->id;
			$nettertech_events_is_cancelled = 'cancelled' === $nettertech_events_sibling->status;

			$nettertech_events_item_classes = array( 'nte-occurrence-siblings__item' );
			if ( $nettertech_events_is_current ) {
				$nettertech_events_item_classes[] = 'nte-occurrence-siblings__item--current';
			}
			if ( $nettertech_events_is_cancelled ) {
				$nettertech_events_item_classes[] = 'nte-occurrence-siblings__item--cancelled';
			}
			?>
			<li class="<?php echo esc_attr( implode( ' ', $nettertech_events_item_classes ) ); ?>">
				<?php if ( $nettertech_events_is_current ) : ?>
					<span class="nte-occurrence-siblings__link nte-occurrence-siblings__link--current" aria-current="page">
						<?php echo esc_html( $nettertech_events_sibling->get_formatted_datetime() ); ?>
						<span class="screen-reader-text"><?php esc_html_e( '(current)', 'nettertech-events' ); ?></span>
					</span>
				<?php else : ?>
					<a href="<?php echo esc_url( $nettertech_events_sibling->get_url() ); ?>" class="nte-occurrence-siblings__link">
						<?php echo esc_html( $nettertech_events_sibling->get_formatted_datetime() ); ?>
						<?php if ( $nettertech_events_is_cancelled ) : ?>
							<span class="nte-occurrence-siblings__status"><?php esc_html_e( 'Cancelled', 'nettertech-events' ); ?></span>
						<?php endif; ?>
					</a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
