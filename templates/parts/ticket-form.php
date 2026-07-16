<?php
/**
 * Template part: Batch ticket purchase form.
 *
 * Renders the multi-ticket-type purchase form with quantity selectors,
 * sold-out badges, low-stock notices, and a running-total submit button.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/parts/ticket-form.php
 *
 * @package NetterTechEvents
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var string                              $context->form_id         Form element ID (e.g. 'nte-ticket-form-{occurrence_id}').
 * @var int                                 $context->occurrence_id   Occurrence ID.
 * @var bool                                $context->has_available   Whether any tickets are currently purchasable.
 * @var string                              $context->currency_symbol Currency symbol for price display (e.g. '$').
 * @var array                               $context->tickets         Pre-computed ticket data arrays (see data contract below).
 * @var \NetterTechEvents\Models\Occurrence $context->occurrence      Occurrence object (used for action hooks).
 *
 * Each entry in $tickets contains:
 *   'ticket_type'     => TicketType object (for hooks)
 *   'id'              => int
 *   'name'            => string
 *   'description'     => string
 *   'formatted_price' => string  (HTML-safe formatted price)
 *   'price'           => float
 *   'min_per_order'   => int
 *   'max_per_order'   => int
 *   'has_product'     => bool    (true if linked to a WooCommerce product)
 *   'available'       => int|null (null = unlimited)
 *   'available_attr'  => string  ('unlimited' or numeric string)
 *   'max_purchasable' => int
 *   'is_sold_out'     => bool
 *   'is_low_stock'    => bool
 *   'input_id'        => string  (e.g. 'nte-qty-{ticket_type_id}')
 */

declare(strict_types=1);

use NetterTechEvents\Models\Occurrence;

// Guard against direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<form class="nte-ticket-form"
		id="<?php echo esc_attr( $context->get( 'form_id', '' ) ); ?>"
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-occurrence="<?php echo esc_attr( (string) $context->get( 'occurrence_id', 0 ) ); ?>">

	<?php wp_nonce_field( 'nettertech_events_ticket_cart_nonce', 'nettertech_events_ticket_nonce' ); ?>
	<input type="hidden" name="action" value="nettertech_events_add_tickets_batch">

	<?php foreach ( $context->get( 'tickets', array() ) as $nettertech_events_index => $nettertech_events_ticket ) : ?>
		<?php if ( $nettertech_events_index > 0 ) : ?>
			<hr class="nte-ticket-card__separator">
		<?php endif; ?>
		<div class="nte-ticket-card" data-ticket-id="<?php echo esc_attr( (string) $nettertech_events_ticket['id'] ); ?>">
			<div class="nte-ticket-card__info">
				<p class="nte-ticket-card__name">
					<label for="<?php echo esc_attr( $nettertech_events_ticket['input_id'] ); ?>">
						<?php echo esc_html( $nettertech_events_ticket['name'] ); ?>
					</label>
				</p>
				<?php if ( ! empty( $nettertech_events_ticket['description'] ) ) : ?>
					<p class="nte-ticket-card__description"><?php echo esc_html( $nettertech_events_ticket['description'] ); ?></p>
				<?php endif; ?>
			</div>
			<div class="nte-ticket-card__footer">
				<span class="nte-ticket-card__price"><?php echo wp_kses_post( $nettertech_events_ticket['formatted_price'] ); ?></span>
				<div class="nte-ticket-card__action">
				<?php if ( $nettertech_events_ticket['is_sold_out'] ) : ?>
					<span class="nte-btn nte-btn--sold-out wp-element-button"><?php esc_html_e( 'Sold Out', 'nettertech-events' ); ?></span>
					<?php
					if ( $context->get( 'occurrence', null ) instanceof Occurrence ) {
						/** This action is documented in nettertech-events/includes/Frontend/TicketDisplay.php */
						do_action( 'nettertech_events_after_sold_out', $nettertech_events_ticket['ticket_type'], $context->get( 'occurrence', null ) );
					}
					?>
					<input type="hidden"
							name="tickets[<?php echo esc_attr( (string) $nettertech_events_ticket['id'] ); ?>]"
							value="0">
				<?php elseif ( $nettertech_events_ticket['has_product'] ) : ?>
					<div class="nte-ticket-quantity">
						<input type="number"
								id="<?php echo esc_attr( $nettertech_events_ticket['input_id'] ); ?>"
								name="tickets[<?php echo esc_attr( (string) $nettertech_events_ticket['id'] ); ?>]"
								class="nte-ticket-quantity__input"
								value="0"
								min="0"
								max="<?php echo esc_attr( (string) $nettertech_events_ticket['max_purchasable'] ); ?>"
								step="1"
								data-ticket-id="<?php echo esc_attr( (string) $nettertech_events_ticket['id'] ); ?>"
								data-price="<?php echo esc_attr( (string) $nettertech_events_ticket['price'] ); ?>"
								data-available="<?php echo esc_attr( $nettertech_events_ticket['available_attr'] ); ?>"
								data-min-per-order="<?php echo esc_attr( (string) $nettertech_events_ticket['min_per_order'] ); ?>"
								data-max-per-order="<?php echo esc_attr( (string) $nettertech_events_ticket['max_per_order'] ); ?>"
								aria-label="<?php /* translators: %s: ticket type name */ echo esc_attr( sprintf( __( 'Quantity for %s', 'nettertech-events' ), $nettertech_events_ticket['name'] ) ); ?>"
							<?php if ( $nettertech_events_ticket['is_low_stock'] && null !== $nettertech_events_ticket['available'] ) : ?>
								aria-describedby="nte-stock-<?php echo esc_attr( (string) $nettertech_events_ticket['id'] ); ?>"
							<?php endif; ?>>
						<?php if ( $nettertech_events_ticket['is_low_stock'] && null !== $nettertech_events_ticket['available'] ) : ?>
							<span class="nte-ticket-stock nte-ticket-stock--low" id="nte-stock-<?php echo esc_attr( (string) $nettertech_events_ticket['id'] ); ?>">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of tickets remaining */
										_n( '%d left', '%d left', $nettertech_events_ticket['available'], 'nettertech-events' ),
										$nettertech_events_ticket['available']
									)
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<span class="nte-ticket-info"><?php esc_html_e( 'Contact the organizer to purchase this ticket type.', 'nettertech-events' ); ?></span>
				<?php endif; ?>
				</div>
			</div>
		</div>
	<?php endforeach; ?>

	<?php if ( $context->get( 'has_available', false ) ) : ?>
		<div class="nte-ticket-form__footer">
			<button type="submit"
					class="nte-btn nte-btn--primary wp-element-button nte-ticket-form__submit"
					data-text-none="<?php esc_attr_e( 'No tickets selected', 'nettertech-events' ); ?>"
					data-text-single="<?php esc_attr_e( 'Get Ticket', 'nettertech-events' ); ?>"
					data-text-plural="<?php esc_attr_e( 'Get Tickets', 'nettertech-events' ); ?>"
					data-currency="<?php echo esc_attr( $context->get( 'currency_symbol', '$' ) ); ?>"
					disabled>
				<span class="nte-ticket-form__submit-label"><?php esc_html_e( 'No tickets selected', 'nettertech-events' ); ?></span>
			</button>
		</div>
		<div class="nte-ticket-form__status nte-sr-only" aria-live="polite" aria-atomic="true"></div>
	<?php endif; ?>
</form>
