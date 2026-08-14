<?php
/**
 * Template part: Event Card
 *
 * Displays an event occurrence in a card format for grid layouts.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/parts/event-card.php
 *
 * Available variables (read via $context):
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var \NetterTechEvents\Models\Occurrence $context->occurrence  The occurrence to display.
 * @var \NetterTechEvents\Models\Event      $context->event       The parent event.
 * @var bool                                $context->show_image   Whether to show the featured image (default: true).
 * @var bool                                $context->show_date    Whether to show the date badge (default: true).
 * @var bool                                $context->show_time    Whether to show the time (default: true).
 * @var bool                                $context->show_venue   Whether to show the venue name (default: true).
 * @var bool                                $context->show_price   Whether to show the price range (default: false).
 * @var bool                                $context->show_excerpt Whether to show the excerpt (default: false).
 * @var string                              $context->image_ratio  CSS aspect-ratio value for per-instance override (default: '').
 * @var string                              $context->heading_tag  HTML heading tag for the card title (default: 'h2').
 * @var bool                                $context->heading_date Whether to include the date in the heading for disambiguation (default: false).
 * @var array                               $context->prefetched_availability Optional availability verdict ('sold_out' => bool), supplied by listing controllers when an extension opts in via the nettertech_events_cards_need_availability filter. Exposed as a card class and to the card-status action; base renders no label (default: unset).
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Ensure required variables are set.
if ( ! $context->occurrence || ! $context->event ) {
	return;
}

// Build inline style for image if per-instance ratio is set.
$nettertech_events_image_style = '';
if ( ! empty( $context->get( 'image_ratio', '' ) ) ) {
	$nettertech_events_image_style = sprintf( 'aspect-ratio: %s;', esc_attr( $context->get( 'image_ratio', '' ) ) );
}

// Per-event vertical crop anchor → object-position Y for the card image (NTE-132).
// Mirrors the single-page anchor (NTE-119); reuses the same image_vertical_anchor
// column. center/null falls through to 50% (the CSS var's own default).
$nettertech_events_card_anchor_map = array(
	'top'    => '0%',
	'bottom' => '100%',
);
$nettertech_events_card_anchor_y   = $nettertech_events_card_anchor_map[ (string) $context->event->image_vertical_anchor ] ?? '50%';
$nettertech_events_image_style    .= sprintf( '--nte-card-image-anchor-y: %s;', esc_attr( $nettertech_events_card_anchor_y ) );

// Occurrence-level hero override with event fallback (NTE-177 / FR-011): a
// per-occurrence featured image must show on cards, not just the single page.
$nettertech_events_card_image_id = $context->occurrence->get_featured_image_id();

// Display timestamps: strtotime(wall-clock) + date_i18n is the WP idiom that renders
// the authored local components — leave unchanged.
$nettertech_events_start_time   = strtotime( $context->occurrence->start_datetime );
$nettertech_events_end_time     = $context->occurrence->end_datetime ? strtotime( $context->occurrence->end_datetime ) : null;
$nettertech_events_current_year = (int) gmdate( 'Y' );
$nettertech_events_event_year   = (int) gmdate( 'Y', $nettertech_events_start_time );
$nettertech_events_show_year    = $nettertech_events_event_year !== $nettertech_events_current_year;
// Past once it has ended, not once it has started — and never re-derived here. is_past()
// owns the rule (true instants, authoring zone, DST-aware); a copy of it in a template is a
// copy that drifts.
$nettertech_events_is_past      = $context->occurrence->is_past();
$nettertech_events_is_cancelled = 'cancelled' === $context->occurrence->status;
$nettertech_events_permalink    = $context->occurrence->get_url();

// Availability verdict (NTE-203): supplied by listing controllers when an
// extension opts in via the nettertech_events_cards_need_availability filter.
// Base renders no sold-out label — visible availability UI is an extension
// concern (the card-status action below) — but the verdict is exposed as a
// card modifier class so extensions can style the whole card. Cancelled and
// past cards ignore it: those states already end the sale.
$nettertech_events_is_sold_out = false;
if ( ! $nettertech_events_is_cancelled && ! $nettertech_events_is_past && $context->has( 'prefetched_availability' ) ) {
	$nettertech_events_card_availability = (array) $context->get( 'prefetched_availability', array() );
	$nettertech_events_is_sold_out       = ! empty( $nettertech_events_card_availability['sold_out'] );
}

$nettertech_events_card_classes = array( 'nte-event-card' );
if ( $nettertech_events_is_past ) {
	$nettertech_events_card_classes[] = 'nte-event-card--past';
}
if ( $nettertech_events_is_cancelled ) {
	$nettertech_events_card_classes[] = 'nte-event-card--cancelled';
}
if ( $nettertech_events_is_sold_out ) {
	$nettertech_events_card_classes[] = 'nte-event-card--sold-out';
}
?>
<article class="<?php echo esc_attr( implode( ' ', $nettertech_events_card_classes ) ); ?>">
	<a href="<?php echo esc_url( $nettertech_events_permalink ); ?>" class="nte-event-card__link">
		<?php if ( $context->get( 'show_image', true ) ) : ?>
			<?php if ( $nettertech_events_card_image_id ) : ?>
				<div class="nte-event-card__image"<?php echo $nettertech_events_image_style ? ' style="' . esc_attr( $nettertech_events_image_style ) . '"' : ''; ?>>
					<?php
					/*
					 * Uses 800x800 'nettertech-events-tile' size for crisp display on retina screens.
					 * WordPress automatically generates srcset for responsive images.
					 *
					 * Note: Existing images need regeneration to use this size.
					 * WP-CLI: wp media regenerate --only-missing
					 * Or use "Regenerate Thumbnails" plugin.
					 */
					// wp_get_attachment_image() is a recognized escape primitive in PHPCS.
					echo wp_get_attachment_image(
						$nettertech_events_card_image_id,
						'nettertech-events-tile',
						false,
						array(
							'class' => 'nte-event-card__img',
							'sizes' => '(max-width: 480px) 100vw, (max-width: 768px) 50vw, 400px',
						)
					);
					?>
				</div>
			<?php else : ?>
				<div class="nte-event-card__image nte-event-card__image--placeholder"<?php echo $nettertech_events_image_style ? ' style="' . esc_attr( $nettertech_events_image_style ) . '"' : ''; ?>>
					<span class="nte-event-card__placeholder-icon" aria-hidden="true"></span>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<div class="nte-event-card__content">
			<?php if ( $context->get( 'show_date', true ) ) : ?>
				<div class="nte-event-card__date">
					<span class="nte-event-card__date-month"><?php echo esc_html( date_i18n( 'M', $nettertech_events_start_time ) ); ?></span>
					<span class="nte-event-card__date-day"><?php echo esc_html( date_i18n( 'j', $nettertech_events_start_time ) ); ?></span>
					<?php if ( $nettertech_events_show_year ) : ?>
						<span class="nte-event-card__date-year"><?php echo esc_html( date_i18n( 'Y', $nettertech_events_start_time ) ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="nte-event-card__details">
				<<?php echo esc_attr( $context->get( 'heading_tag', 'h2' ) ); ?> class="nte-event-card__title">
					<?php echo esc_html( $context->event->title ); ?>
					<?php if ( $context->get( 'heading_date', false ) && $nettertech_events_start_time ) : ?>
						<span class="nte-sr-only"> &mdash; <?php echo esc_html( date_i18n( 'F j', $nettertech_events_start_time ) ); ?></span>
					<?php endif; ?>
				</<?php echo esc_attr( $context->get( 'heading_tag', 'h2' ) ); ?>>

				<?php if ( $context->get( 'show_time', true ) ) : ?>
					<p class="nte-event-card__time">
						<?php if ( $context->occurrence->all_day ) : ?>
							<?php esc_html_e( 'All Day', 'nettertech-events' ); ?>
						<?php else : ?>
							<?php echo esc_html( date_i18n( get_option( 'time_format' ), $nettertech_events_start_time ) ); ?>
							<?php if ( $nettertech_events_end_time ) : ?>
								- <?php echo esc_html( date_i18n( get_option( 'time_format' ), $nettertech_events_end_time ) ); ?>
							<?php endif; ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( $nettertech_events_is_cancelled ) : ?>
					<p class="nte-event-card__status nte-event-card__status--cancelled">
						<?php esc_html_e( 'Cancelled', 'nettertech-events' ); ?>
					</p>
				<?php elseif ( $nettertech_events_is_past ) : ?>
					<p class="nte-event-card__status nte-event-card__status--past">
						<?php esc_html_e( 'Completed', 'nettertech-events' ); ?>
					</p>
				<?php else : ?>
					<?php
					// Extension status slot for active cards (e.g. a Pro sold-out
					// badge). Documented on Hooks::EVENT_CARD_STATUS; output must
					// satisfy ShortcodeOutput::get_allowlist().
					do_action( \NetterTechEvents\Core\Hooks::EVENT_CARD_STATUS, $context );
					?>
				<?php endif; ?>

				<?php if ( $context->get( 'show_venue', true ) && ! empty( $context->event->venue_name ) && ! $context->event->is_virtual_event() ) : ?>
					<p class="nte-event-card__venue"><?php echo esc_html( $context->event->venue_name ); ?></p>
				<?php elseif ( $context->get( 'show_venue', true ) && $context->event->is_hybrid() ) : ?>
					<p class="nte-event-card__venue"><?php echo esc_html( $context->event->venue_name ); ?> · <?php esc_html_e( 'Online', 'nettertech-events' ); ?></p>
				<?php elseif ( $context->event->is_virtual_event() ) : ?>
					<p class="nte-event-card__venue"><?php esc_html_e( 'Online Event', 'nettertech-events' ); ?></p>
				<?php endif; ?>

				<?php if ( $context->get( 'show_price', false ) && $context->occurrence->id ) : ?>
					<?php
					// Prefer prefetched ticket types (passed by shortcode controllers to avoid N+1).
					// Fall back to a per-card lookup only for direct template callers that did not
					// prefetch — preserves backward compatibility for theme/plugin code that calls
					// the template part directly with the minimal context.
					if ( $context->has( 'prefetched_ticket_types' ) ) {
						$nettertech_events_card_ticket_types = $context->get( 'prefetched_ticket_types', array() );
					} elseif ( function_exists( '\\NetterTechEvents\\nettertech_events_container' ) ) {
						$nettertech_events_ticket_type_repo  = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
						$nettertech_events_card_ticket_types = $nettertech_events_ticket_type_repo->get_on_sale_for_occurrence( $context->occurrence->id );
					} else {
						$nettertech_events_card_ticket_types = array();
					}
					if ( ! empty( $nettertech_events_card_ticket_types ) ) :
						$nettertech_events_card_prices = array_map( fn( $nettertech_events_tt ) => $nettertech_events_tt->price, $nettertech_events_card_ticket_types );
						$nettertech_events_card_min    = min( $nettertech_events_card_prices );
						$nettertech_events_card_max    = max( $nettertech_events_card_prices );
						if ( $nettertech_events_card_max <= 0 ) :
							$nettertech_events_card_price_display = __( 'Free', 'nettertech-events' );
						elseif ( $nettertech_events_card_min <= 0 ) :
							/* translators: %s: maximum ticket price. */
							$nettertech_events_card_price_display = sprintf( __( 'Free – %s', 'nettertech-events' ), wp_strip_all_tags( wc_price( $nettertech_events_card_max ) ) );
						elseif ( abs( $nettertech_events_card_min - $nettertech_events_card_max ) < 0.01 ) :
							$nettertech_events_card_price_display = wp_strip_all_tags( wc_price( $nettertech_events_card_min ) );
						else :
							/* translators: 1: minimum ticket price, 2: maximum ticket price. */
							$nettertech_events_card_price_display = sprintf( __( '%1$s – %2$s', 'nettertech-events' ), wp_strip_all_tags( wc_price( $nettertech_events_card_min ) ), wp_strip_all_tags( wc_price( $nettertech_events_card_max ) ) );
						endif;
						?>
						<p class="nte-event-card__price"><?php echo esc_html( $nettertech_events_card_price_display ); ?></p>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $context->get( 'show_excerpt', false ) && ! empty( $context->event->excerpt ) ) : ?>
					<p class="nte-event-card__excerpt"><?php echo esc_html( wp_trim_words( $context->event->excerpt, 20 ) ); ?></p>
				<?php endif; ?>

				<?php if ( $context->event->id ) : ?>
					<?php
					// Prefer prefetched tags (passed by shortcode controllers to avoid N+1).
					// Fall back to a per-card lookup only for direct template callers that did
					// not prefetch — preserves backward compatibility for theme/plugin code
					// that calls the template part directly with the minimal context.
					if ( $context->has( 'prefetched_tags' ) ) {
						$nettertech_events_card_tags = $context->get( 'prefetched_tags', array() );
					} elseif ( function_exists( '\\NetterTechEvents\\nettertech_events_container' ) ) {
						$nettertech_events_card_tag_repo = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Contracts\TagRepositoryInterface::class );
						$nettertech_events_card_tags     = $nettertech_events_card_tag_repo->find_by_event( $context->event->id );
					} else {
						$nettertech_events_card_tags = array();
					}
					?>
					<?php if ( ! empty( $nettertech_events_card_tags ) ) : ?>
						<?php
						$nettertech_events_max_tags     = (int) $context->get( 'max_tags', 0 );
						$nettertech_events_total_tags   = count( $nettertech_events_card_tags );
						$nettertech_events_visible_tags = ( $nettertech_events_max_tags > 0 && $nettertech_events_total_tags > $nettertech_events_max_tags )
							? array_slice( $nettertech_events_card_tags, 0, $nettertech_events_max_tags )
							: $nettertech_events_card_tags;
						$nettertech_events_remaining    = $nettertech_events_total_tags - count( $nettertech_events_visible_tags );
						?>
						<div class="nte-event-tags">
							<?php foreach ( $nettertech_events_visible_tags as $nettertech_events_card_tag ) : ?>
								<span class="nte-tag-pill"><?php echo esc_html( $nettertech_events_card_tag->name ); ?></span>
							<?php endforeach; ?>
							<?php if ( $nettertech_events_remaining > 0 ) : ?>
								<span class="nte-tag-more">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of additional tags not shown. */
											_n( '…and %d more', '…and %d more', $nettertech_events_remaining, 'nettertech-events' ),
											$nettertech_events_remaining
										)
									);
									?>
								</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	</a>
</article>
