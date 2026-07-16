<?php
/**
 * Ticket display class.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Handles ticket/RSVP display on single event pages.
 */
class TicketDisplay {

	/**
	 * Whether scripts have been enqueued.
	 *
	 * @var bool
	 */
	private static bool $scripts_enqueued = false;

	/**
	 * Injected ticket type repository instance.
	 *
	 * @var TicketTypeRepositoryInterface|null
	 */
	private static ?TicketTypeRepositoryInterface $ticket_type_repo = null;

	/**
	 * Injected capacity service instance.
	 *
	 * @var CapacityServiceInterface|null
	 */
	private static ?CapacityServiceInterface $capacity_service = null;

	/**
	 * Templates service instance.
	 *
	 * @var Templates|null
	 */
	private static ?Templates $templates = null;

	/**
	 * RSVP form shortcode instance for free event inline forms.
	 *
	 * @var Shortcodes\RSVPFormShortcode|null
	 */
	private static ?Shortcodes\RSVPFormShortcode $rsvp_shortcode = null;

	/**
	 * Initialize the display hooks with injected dependencies.
	 *
	 * @since 2.0.0
	 * @see ADR-013
	 *
	 * @param CapacityServiceInterface          $capacity_service Capacity service instance.
	 * @param TicketTypeRepositoryInterface     $ticket_type_repo Ticket type repository.
	 * @param Templates|null                    $templates        Templates service.
	 * @param Shortcodes\RSVPFormShortcode|null $rsvp_shortcode   RSVP form shortcode.
	 * @return void
	 */
	public static function init(
		CapacityServiceInterface $capacity_service,
		TicketTypeRepositoryInterface $ticket_type_repo,
		?Templates $templates = null,
		?Shortcodes\RSVPFormShortcode $rsvp_shortcode = null
	): void {
		self::$capacity_service = $capacity_service;
		self::$ticket_type_repo = $ticket_type_repo;
		self::$templates        = $templates ?? Templates::get_instance();
		self::$rsvp_shortcode   = $rsvp_shortcode;
		add_action( 'nettertech_events_single_occurrence_actions', array( self::class, 'render_occurrence_actions' ), 10, 2 );
	}


	/**
	 * Enqueue RSVP toggle script.
	 *
	 * @return void
	 */
	private static function enqueue_scripts(): void {
		if ( self::$scripts_enqueued ) {
			return;
		}

		self::$scripts_enqueued = true;

		$handle = 'nettertech-events-rsvp-toggle';
		wp_register_script(
			$handle,
			false,
			array( 'jquery' ),
			NETTERTECH_EVENTS_VERSION,
			true
		);
		wp_enqueue_script( $handle );

		$data = array(
			'cancelText' => __( 'Cancel', 'nettertech-events' ),
			'rsvpText'   => __( 'RSVP', 'nettertech-events' ),
		);

		wp_add_inline_script(
			$handle,
			'window.nettertechEventsRsvpToggle = ' . wp_json_encode( $data ) . ';
jQuery(function($) {
	// Progressive enhancement: the container ships visible (usable without
	// JS); collapse it at init so the initial state matches the toggle
	// button aria-expanded="false". An inline style cannot do this — the
	// template pipeline wp_kses() strips style attributes (NTE-131).
	$(".nte-rsvp-form-container").hide();
	$(".nte-rsvp-toggle").on("click", function() {
		var id = $(this).data("occurrence");
		var $form = $("#rsvp-form-" + id);
		var isExpanded = $form.is(":visible");
		$form.slideToggle();
		$(this).attr("aria-expanded", !isExpanded).text(!isExpanded ? window.nettertechEventsRsvpToggle.cancelText : window.nettertechEventsRsvpToggle.rsvpText);
	});
});'
		);
	}

	/**
	 * Render ticket/RSVP actions for an occurrence.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @param Event      $event      The parent event (reserved for future use).
	 * @return void
	 */
	public static function render_occurrence_actions( Occurrence $occurrence, Event $event ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		$ticket_type_repo = self::$ticket_type_repo;
		if ( null === $ticket_type_repo || null === $occurrence->id ) {
			return;
		}
		$ticket_types = $ticket_type_repo->get_on_sale_for_occurrence( $occurrence->id );

		if ( empty( $ticket_types ) ) {
			return;
		}

		// Check if event has ended.
		if ( $occurrence->has_ended() ) {
			echo '<span class="nte-occurrence-status nte-occurrence-status--past">' . esc_html__( 'Past Event', 'nettertech-events' ) . '</span>';
			return;
		}

		// Determine if free (RSVP) or paid (WooCommerce).
		$is_free   = $ticket_type_repo->occurrence_is_free( $occurrence->id );
		$wc_active = class_exists( 'WooCommerce' );

		?>
		<?php if ( $is_free ) : ?>
			<button type="button" class="nte-btn nte-btn--primary wp-element-button nte-rsvp-toggle"
					data-occurrence="<?php echo esc_attr( (string) $occurrence->id ); ?>"
					aria-expanded="false"
					aria-controls="rsvp-form-<?php echo esc_attr( (string) $occurrence->id ); ?>">
				<?php esc_html_e( 'RSVP', 'nettertech-events' ); ?>
			</button>
		<?php elseif ( $wc_active ) : ?>
			<?php self::render_ticket_display( $ticket_types, $occurrence ); ?>
		<?php else : ?>
			<?php foreach ( $ticket_types as $ticket_type ) : ?>
				<?php $card_name_id = 'nte-ticket-name-' . $ticket_type->id; ?>
				<div class="nte-ticket-card" role="group" aria-labelledby="<?php echo esc_attr( $card_name_id ); ?>">
					<div class="nte-ticket-card__info">
						<p class="nte-ticket-card__name" id="<?php echo esc_attr( $card_name_id ); ?>"><?php echo esc_html( $ticket_type->name ); ?></p>
						<?php if ( ! empty( $ticket_type->description ) ) : ?>
							<p class="nte-ticket-card__description"><?php echo esc_html( $ticket_type->description ); ?></p>
						<?php endif; ?>
						<span class="nte-ticket-card__price"><?php echo wp_kses_post( $ticket_type->get_formatted_price() ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( $is_free ) : ?>
			<?php // No inline display:none — wp_kses() in the template pipeline strips style attributes; the toggle script collapses the container at init (NTE-131). ?>
			<div class="nte-rsvp-form-container" id="rsvp-form-<?php echo esc_attr( (string) $occurrence->id ); ?>">
				<?php
				if ( null !== self::$rsvp_shortcode ) {
					echo wp_kses(
						self::$rsvp_shortcode->render( array( 'occurrence_id' => $occurrence->id ) ),
						ShortcodeOutput::get_allowlist()
					);
				}
				?>
			</div>
			<?php
			// Enqueue RSVP toggle script (only for free events).
			self::enqueue_scripts();
			?>
		<?php endif; ?>
		<?php
		// Admin-only SKU reference on the public page (NTE-114). A read-only
		// convenience for logged-in editors viewing the live event; entirely
		// invisible to visitors. Gated on edit_posts and WooCommerce presence.
		if ( $wc_active && current_user_can( 'edit_posts' ) ) {
			self::render_admin_skus( $ticket_types );
		}
	}

	/**
	 * Render an admin-only ticket SKU list on the public single-event page.
	 *
	 * @since 1.0.3
	 *
	 * @param array<TicketType> $ticket_types Ticket types for the occurrence.
	 * @return void
	 */
	private static function render_admin_skus( array $ticket_types ): void {
		$rows = array();
		foreach ( $ticket_types as $ticket_type ) {
			if ( ! $ticket_type->wc_product_id || ! function_exists( 'wc_get_product' ) ) {
				continue;
			}
			$product = wc_get_product( $ticket_type->wc_product_id );
			$sku     = $product ? (string) $product->get_sku() : '';
			if ( '' === $sku ) {
				continue;
			}
			$rows[] = array(
				'name' => $ticket_type->name,
				'sku'  => $sku,
			);
		}

		if ( empty( $rows ) ) {
			return;
		}

		echo '<details class="nte-admin-skus"><summary>' . esc_html__( 'Ticket SKUs (admin only)', 'nettertech-events' ) . '</summary><ul class="nte-admin-skus__list">';
		foreach ( $rows as $row ) {
			printf(
				'<li><span class="nte-admin-skus__name">%s</span> <code>%s</code></li>',
				esc_html( $row['name'] ),
				esc_html( $row['sku'] )
			);
		}
		echo '</ul></details>';
	}

	/**
	 * Render the ticket form via overridable template.
	 *
	 * Prepares ticket data (availability, pricing, stock) and renders
	 * via Templates::get_part('ticket-form'). Enqueues external JS
	 * for progressive enhancement (quantity buttons, batch AJAX).
	 *
	 * @since 1.2.0
	 *
	 * @param array<TicketType> $ticket_types Ticket types to display.
	 * @param Occurrence        $occurrence   The occurrence.
	 * @return void
	 */
	private static function render_ticket_display( array $ticket_types, Occurrence $occurrence ): void {
		$capacity_service = self::$capacity_service;
		$templates        = self::$templates;
		if ( null === $capacity_service || null === $templates ) {
			return;
		}
		$has_available = false;
		$tickets       = array();

		foreach ( $ticket_types as $ticket_type ) {
			if ( null === $ticket_type->id ) {
				continue;
			}

			$available       = $capacity_service->get_available_count( $ticket_type->id );
			$max_purchasable = self::get_max_purchasable( $ticket_type, $available );
			$is_sold_out     = $ticket_type->is_sold_out() || 0 === $max_purchasable;

			if ( ! $is_sold_out && $ticket_type->wc_product_id ) {
				$has_available = true;
			}

			$tickets[] = array(
				'ticket_type'     => $ticket_type,
				'id'              => $ticket_type->id,
				'name'            => $ticket_type->name,
				'description'     => $ticket_type->description,
				'formatted_price' => $ticket_type->get_formatted_price(),
				'price'           => $ticket_type->price,
				'min_per_order'   => $ticket_type->min_per_order,
				'max_per_order'   => $ticket_type->max_per_order,
				'has_product'     => (bool) $ticket_type->wc_product_id,
				'available'       => $available,
				'available_attr'  => null === $available ? 'unlimited' : (string) $available,
				'max_purchasable' => $max_purchasable,
				'is_sold_out'     => $is_sold_out,
				'is_low_stock'    => $ticket_type->is_low_stock(),
				'input_id'        => 'nte-qty-' . $ticket_type->id,
			);
		}

		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';

		// Render ticket form via overridable template (escaped at boundary).
		echo wp_kses(
			$templates->get_template_part(
				'ticket-form',
				array(
					'form_id'         => 'nte-ticket-form-' . $occurrence->id,
					'occurrence_id'   => $occurrence->id,
					'occurrence'      => $occurrence,
					'has_available'   => $has_available,
					'currency_symbol' => $currency_symbol,
					'tickets'         => $tickets,
				)
			),
			ShortcodeOutput::get_allowlist()
		);

		// Enqueue ticket quantity JS (progressive enhancement + AJAX submission).
		self::enqueue_ticket_scripts();

		// Extensibility: add-ons can inject content after the ticket form.
		do_action( 'nettertech_events_after_ticket_form', $ticket_types, $occurrence );
	}

	/**
	 * Enqueue ticket quantity scripts and localization.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private static function enqueue_ticket_scripts(): void {
		static $enqueued = false;
		if ( $enqueued ) {
			return;
		}
		$enqueued = true;

		wp_enqueue_script( 'nettertech-events-ticket-quantity' );
		wp_localize_script(
			'nettertech-events-ticket-quantity',
			'nettertechEventsTicketForm',
			array(
				'i18n' => array(
					'decrease'     => __( 'Decrease quantity', 'nettertech-events' ),
					'increase'     => __( 'Increase quantity', 'nettertech-events' ),
					'selectTicket' => __( 'Please select at least one ticket.', 'nettertech-events' ),
					'adding'       => __( 'Adding to cart...', 'nettertech-events' ),
					'addedToCart'  => __( 'Tickets added to cart.', 'nettertech-events' ),
					'redirecting'  => __( 'Redirecting to cart...', 'nettertech-events' ),
					'addError'     => __( 'Failed to add tickets to cart.', 'nettertech-events' ),
					'networkError' => __( 'An error occurred. Please try again.', 'nettertech-events' ),
				),
			)
		);
	}

	/**
	 * Calculate maximum purchasable quantity for a ticket type.
	 *
	 * Considers both the ticket's max_per_order and available capacity.
	 *
	 * @param TicketType $ticket_type Ticket type.
	 * @param int|null   $available   Available capacity (null = unlimited).
	 * @return int Maximum purchasable quantity.
	 */
	private static function get_max_purchasable( TicketType $ticket_type, ?int $available ): int {
		$max = $ticket_type->max_per_order;

		if ( null !== $available ) {
			$max = min( $max, $available );
		}

		return max( 0, $max );
	}
}
