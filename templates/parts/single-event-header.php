<?php
/**
 * Template part: Single event header.
 *
 * Displays the event title, subtitle (date/time/price), and venue information.
 *
 * @package NetterTechEvents
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext   $context Template context.
 * @var \NetterTechEvents\Models\Event                     $context->event         The event.
 * @var \NetterTechEvents\Models\Occurrence|null           $context->occurrence    Current occurrence (if viewing specific date).
 * @var \NetterTechEvents\Models\Occurrence|null           $context->target_occ    Occurrence to display info for.
 * @var string                                             $context->display_title Title to display.
 * @var string                                             $context->date_subtitle Formatted date/time string.
 * @var string                                             $context->price_display Formatted price string.
 */

declare(strict_types=1);

// Guard against direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHPStan reads only standalone assertions, not the header var-list
 * (audit GAP-029 rollout).
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context
 */

if ( ! $context->event ) {
	return;
}
?>

<header class="nte-single-event__header">
	<h1 class="nte-single-event__title"><?php echo esc_html( $context->get( 'display_title', '' ) ); ?></h1>

	<?php if ( $context->get( 'is_cancelled', false ) ) : ?>
		<div class="nte-single-event__cancelled-notice">
			<strong><?php esc_html_e( 'Cancelled', 'nettertech-events' ); ?></strong>
			<?php esc_html_e( 'This date has been cancelled.', 'nettertech-events' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $context->get( 'date_subtitle', '' ) ) : ?>
		<h2 class="nte-single-event__subtitle">
			<?php echo esc_html( $context->get( 'date_subtitle', '' ) ); ?>
			<?php if ( $context->get( 'price_display', '' ) ) : ?>
				<span class="nte-single-event__price"> · <?php echo wp_kses_post( $context->get( 'price_display', '' ) ); ?></span>
			<?php endif; ?>
		</h2>
	<?php endif; ?>

	<?php if ( ! $context->event->is_virtual_event() && ! empty( $context->event->venue_name ) ) : ?>
		<p class="nte-single-event__venue">
			<svg class="nte-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
				<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
				<circle cx="12" cy="10" r="3"/>
			</svg>
			<?php echo esc_html( $context->event->venue_name ); ?>
			<?php if ( ! empty( $context->event->venue_address ) ) : ?>
				<span class="nte-single-event__address"><?php echo esc_html( $context->event->venue_address ); ?></span>
				<a class="nte-single-event__directions" href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $context->event->venue_address ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Directions', 'nettertech-events' ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $context->event->is_hybrid() ) : ?>
		<p class="nte-single-event__venue">
			<svg class="nte-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
				<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
				<circle cx="12" cy="10" r="3"/>
			</svg>
			<?php echo esc_html( $context->event->venue_name ); ?>
			<?php if ( ! empty( $context->event->venue_address ) ) : ?>
				<span class="nte-single-event__address"><?php echo esc_html( $context->event->venue_address ); ?></span>
				<a class="nte-single-event__directions" href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $context->event->venue_address ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Directions', 'nettertech-events' ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $context->event->is_virtual_event() && ! empty( $context->event->virtual_url ) ) : ?>
		<p class="nte-single-event__virtual">
			<svg class="nte-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
				<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
				<line x1="8" y1="21" x2="16" y2="21"/>
				<line x1="12" y1="17" x2="12" y2="21"/>
			</svg>
			<?php if ( $context->event->is_hybrid() ) : ?>
				<?php esc_html_e( 'Also available online:', 'nettertech-events' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Online event:', 'nettertech-events' ); ?>
			<?php endif; ?>
			<a href="<?php echo esc_url( $context->event->virtual_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Join Online', 'nettertech-events' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<?php if ( $context->event->id && function_exists( '\\NetterTechEvents\\nettertech_events_container' ) ) : ?>
		<?php
		$nettertech_events_header_tag_repo = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Contracts\TagRepositoryInterface::class );
		$nettertech_events_header_tags     = $nettertech_events_header_tag_repo->find_by_event( $context->event->id );
		?>
		<?php if ( ! empty( $nettertech_events_header_tags ) ) : ?>
			<div class="nte-event-tags">
				<?php foreach ( $nettertech_events_header_tags as $nettertech_events_header_tag ) : ?>
					<span class="nte-tag-pill"><?php echo esc_html( $nettertech_events_header_tag->name ); ?></span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</header>
