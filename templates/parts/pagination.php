<?php
/**
 * Template part: Pagination
 *
 * Displays pagination controls for event grids and lists.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/parts/pagination.php
 *
 * Available variables (read via $context):
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var int    $context->current_page  Current page number.
 * @var int    $context->total_pages   Total number of pages.
 * @var string $context->instance_id   Optional unique ID for this pagination instance.
 * @var bool   $context->show_info     Whether to show "Page X of Y" text (default: true).
 * @var string $context->base_url      Optional base URL for noscript fallback links.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Ensure required variables are set.
if ( null === $context->get( 'current_page', null ) || null === $context->get( 'total_pages', null ) ) {
	return;
}

// Don't render if only one page.
if ( $context->get( 'total_pages', 0 ) <= 1 ) {
	return;
}

$nettertech_events_is_first = 1 === $context->get( 'current_page', 0 );
$nettertech_events_is_last  = $context->get( 'current_page', 0 ) === $context->get( 'total_pages', 0 );

$nettertech_events_prev_classes = array( 'nte-pagination__link', 'nte-pagination__link--prev' );
$nettertech_events_next_classes = array( 'nte-pagination__link', 'nte-pagination__link--next' );

if ( $nettertech_events_is_first ) {
	$nettertech_events_prev_classes[] = 'nte-pagination__link--disabled';
}
if ( $nettertech_events_is_last ) {
	$nettertech_events_next_classes[] = 'nte-pagination__link--disabled';
}
?>
<nav class="nte-pagination" aria-label="<?php esc_attr_e( 'Event pages', 'nettertech-events' ); ?>" data-total="<?php echo esc_attr( (string) $context->get( 'total_pages', 0 ) ); ?>">
	<button
		class="<?php echo esc_attr( implode( ' ', $nettertech_events_prev_classes ) ); ?>"
		data-page="prev"
		<?php disabled( $nettertech_events_is_first ); ?>
		aria-label="<?php esc_attr_e( 'Previous page', 'nettertech-events' ); ?>"
	>
		<svg class="nte-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
	</button>

	<?php if ( $context->get( 'show_info', true ) ) : ?>
		<span class="nte-pagination__info">
			<?php
			printf(
				/* translators: 1: Current page, 2: Total pages */
				esc_html__( 'Page %1$d of %2$d', 'nettertech-events' ),
				(int) $context->get( 'current_page', 0 ),
				(int) $context->get( 'total_pages', 0 )
			);
			?>
		</span>
	<?php endif; ?>

	<button
		class="<?php echo esc_attr( implode( ' ', $nettertech_events_next_classes ) ); ?>"
		data-page="next"
		<?php disabled( $nettertech_events_is_last ); ?>
		aria-label="<?php esc_attr_e( 'Next page', 'nettertech-events' ); ?>"
	>
		<svg class="nte-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>
	</button>

	<?php if ( $context->get( 'base_url', '' ) ) : ?>
		<noscript>
			<div class="nte-pagination__noscript">
				<?php if ( ! $nettertech_events_is_first ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'paged', $context->get( 'current_page', 0 ) - 1, $context->get( 'base_url', '' ) ) ); ?>" class="nte-pagination__link nte-pagination__link--prev">
						<?php esc_html_e( 'Previous', 'nettertech-events' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( ! $nettertech_events_is_last ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'paged', $context->get( 'current_page', 0 ) + 1, $context->get( 'base_url', '' ) ) ); ?>" class="nte-pagination__link nte-pagination__link--next">
						<?php esc_html_e( 'Next', 'nettertech-events' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</noscript>
	<?php endif; ?>
</nav>
