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
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Enums\TicketTypeScope;
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
	 * Occurrence repository, used to anchor a series pass's sale window and
	 * availability to the event's next occurrence (NTE-156).
	 *
	 * @var OccurrenceRepositoryInterface|null
	 */
	private static ?OccurrenceRepositoryInterface $occurrence_repo = null;

	/**
	 * Space repository, used to read the venue's door-sales flag once online
	 * sales for a date have closed.
	 *
	 * @var SpaceRepositoryInterface|null
	 */
	private static ?SpaceRepositoryInterface $space_repo = null;

	/**
	 * Event IDs whose series-pass section has already rendered this request,
	 * so a theme firing both after-content hooks cannot double-render it.
	 *
	 * @var array<int, bool>
	 */
	private static array $pass_rendered = array();

	/**
	 * Initialize the display hooks with injected dependencies.
	 *
	 * @since 2.0.0
	 * @see ADR-013
	 *
	 * @param CapacityServiceInterface           $capacity_service Capacity service instance.
	 * @param TicketTypeRepositoryInterface      $ticket_type_repo Ticket type repository.
	 * @param Templates|null                     $templates        Templates service.
	 * @param Shortcodes\RSVPFormShortcode|null  $rsvp_shortcode   RSVP form shortcode.
	 * @param OccurrenceRepositoryInterface|null $occurrence_repo  Occurrence repository (series-pass anchor).
	 * @param SpaceRepositoryInterface|null      $space_repo       Space repository (door-sales flag; no door line when null).
	 * @return void
	 */
	public static function init(
		CapacityServiceInterface $capacity_service,
		TicketTypeRepositoryInterface $ticket_type_repo,
		?Templates $templates = null,
		?Shortcodes\RSVPFormShortcode $rsvp_shortcode = null,
		?OccurrenceRepositoryInterface $occurrence_repo = null,
		?SpaceRepositoryInterface $space_repo = null
	): void {
		self::$capacity_service = $capacity_service;
		self::$ticket_type_repo = $ticket_type_repo;
		self::$templates        = $templates ?? Templates::get_instance();
		self::$rsvp_shortcode   = $rsvp_shortcode;
		self::$occurrence_repo  = $occurrence_repo;
		self::$space_repo       = $space_repo;
		self::$pass_rendered    = array();
		add_action( 'nettertech_events_single_occurrence_actions', array( self::class, 'render_occurrence_actions' ), 10, 2 );

		// A series pass is event-level, not occurrence-level, so it surfaces once
		// per event page rather than per date. Both templates a ticketed event can
		// resolve to (single and series) fire an after-content action (NTE-156).
		add_action( 'nettertech_events_after_single_content', array( self::class, 'render_event_series_pass' ), 15, 1 );
		add_action( 'nettertech_events_after_series_content', array( self::class, 'render_event_series_pass' ), 15, 1 );
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
	 * @param Event      $event      The parent event.
	 * @return void
	 */
	public static function render_occurrence_actions( Occurrence $occurrence, Event $event ): void {
		$ticket_type_repo = self::$ticket_type_repo;
		if ( null === $ticket_type_repo || null === $occurrence->id ) {
			return;
		}
		$ticket_types = $ticket_type_repo->get_on_sale_for_occurrence( $occurrence->id );

		// A series pass is event-level and now has its own section (NTE-156); it
		// used to leak into every date's form via the occurrence lookup's
		// event-scope branch, so a pass could appear on some dates and not others.
		// Keep the per-date form to this date's own tiers.
		$ticket_types = array_values(
			array_filter(
				$ticket_types,
				static fn( TicketType $type ) => TicketTypeScope::EVENT->value !== $type->scope
			)
		);

		if ( empty( $ticket_types ) ) {
			self::render_sales_closed_notice( $occurrence, $event );
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
		$templates = self::$templates;
		if ( null === self::$capacity_service || null === $templates ) {
			return;
		}
		$tickets       = self::build_ticket_rows( $ticket_types );
		$has_available = self::rows_have_available( $tickets );

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
	 * Render the public buy affordance for an event's series pass(es) (NTE-156).
	 *
	 * A series pass is an event-scope tier that belongs to no single date, so
	 * the per-occurrence ticket actions never surface it — leaving a configured
	 * pass unbuyable from the front end. This renders it once per event page, as
	 * its own labelled section, reusing the same ticket-form template and batch
	 * AJAX path (the cart handler resolves a passless line to the next date).
	 *
	 * Fires on the bare `after_{single,series}_content` actions, so it escapes
	 * its own output at the boundary rather than relying on a caller to.
	 *
	 * @since 1.1.3
	 *
	 * @param Event $event The event being displayed.
	 * @return void
	 */
	public static function render_event_series_pass( Event $event ): void {
		$ticket_type_repo = self::$ticket_type_repo;
		$templates        = self::$templates;
		$occurrence_repo  = self::$occurrence_repo;

		if ( null === $ticket_type_repo || null === $templates || null === $event->id ) {
			return;
		}

		// Render at most once per event per request, even if a theme fires both
		// after-content hooks.
		if ( isset( self::$pass_rendered[ $event->id ] ) ) {
			return;
		}

		// A pass is a paid ticket; without WooCommerce there is no purchase path.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Anchor the sale window to the event's next occurrence, exactly as the
		// cart gate does (CartValidator reads it in that occurrence's zone), so a
		// pass shown here is one the cart will actually accept. No upcoming date
		// means nothing a pass could admit to.
		$next = null !== $occurrence_repo ? $occurrence_repo->next_for_event( (int) $event->id ) : null;
		if ( null === $next ) {
			return;
		}

		$tiers = $ticket_type_repo->get_on_sale_for_event( (int) $event->id, $next->get_timezone() );
		if ( empty( $tiers ) ) {
			return;
		}

		self::$pass_rendered[ $event->id ] = true;

		$tickets         = self::build_ticket_rows( $tiers );
		$has_available   = self::rows_have_available( $tickets );
		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';

		$form = $templates->get_template_part(
			'ticket-form',
			array(
				'form_id'         => 'nte-series-pass-form-' . (int) $event->id,
				'occurrence_id'   => 0,
				'occurrence'      => null,
				'has_available'   => $has_available,
				'currency_symbol' => $currency_symbol,
				'tickets'         => $tickets,
			)
		);

		$section  = '<section class="nte-series-pass" aria-label="' . esc_attr__( 'Series pass', 'nettertech-events' ) . '">';
		$section .= '<h2 class="nte-series-pass__title">' . esc_html__( 'Series Pass', 'nettertech-events' ) . '</h2>';
		$section .= '<p class="nte-series-pass__blurb">' . esc_html__( 'One ticket, valid for every date of this event.', 'nettertech-events' ) . '</p>';
		$section .= $form;
		$section .= '</section>';

		echo wp_kses( $section, ShortcodeOutput::get_allowlist() );

		self::enqueue_ticket_scripts();

		/** This action is documented in nettertech-events/includes/Frontend/TicketDisplay.php */
		do_action( 'nettertech_events_after_ticket_form', $tiers, $next );
	}

	/**
	 * Explain an empty ticket area when the reason is a closed sale window.
	 *
	 * A date with nothing on sale looks the same to a buyer whether it was never
	 * ticketed, its tiers have not opened yet, or they have closed — and only the
	 * last deserves a message. The first two stay silent. "Closed" means every
	 * tier on this date is off sale, none is still to open, and at least one has
	 * passed its sale end.
	 *
	 * Sold out is judged across every active tier the way the till judges it, so
	 * a date that filled up before its window closed reads the same as one that
	 * simply closed — except that a sold-out date is never offered at the door,
	 * whatever the venue's flag says.
	 *
	 * @since 1.4.7
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @param Event      $event      The parent event, for its space.
	 * @return void
	 */
	private static function render_sales_closed_notice( Occurrence $occurrence, Event $event ): void {
		$ticket_type_repo = self::$ticket_type_repo;
		$capacity_service = self::$capacity_service;
		if ( null === $ticket_type_repo || null === $capacity_service || null === $occurrence->id ) {
			return;
		}

		$active = array_values(
			array_filter(
				$ticket_type_repo->get_active_for_occurrence( $occurrence->id ),
				static fn( TicketType $type ) => TicketTypeScope::EVENT->value !== $type->scope
			)
		);

		if ( empty( $active ) ) {
			return;
		}

		if ( $occurrence->has_ended() ) {
			echo '<span class="nte-occurrence-status nte-occurrence-status--past">' . esc_html__( 'Past Event', 'nettertech-events' ) . '</span>';
			return;
		}

		$zone    = $occurrence->get_timezone();
		$closed  = false;
		$is_free = true;
		foreach ( $active as $type ) {
			if ( $type->sale_not_yet_open( $zone ) ) {
				return;
			}
			if ( $type->sale_has_ended( $zone ) ) {
				$closed = true;
			}
			if ( (float) $type->price > 0 ) {
				$is_free = false;
			}
		}

		if ( ! $closed ) {
			return;
		}

		$presenter = new OccurrenceAvailabilityPresenter( $capacity_service, $ticket_type_repo );
		$sold_out  = $presenter->is_sold_out( $active );
		$at_door   = ! $sold_out && self::space_sells_at_door( $event );

		$headline = $is_free
			? __( 'Online RSVPs have closed.', 'nettertech-events' )
			: __( 'Online ticket sales have closed.', 'nettertech-events' );

		echo '<div class="nte-occurrence-status nte-occurrence-status--closed">';
		echo '<p class="nte-occurrence-status__headline">' . esc_html( $headline ) . '</p>';
		if ( $at_door ) {
			echo '<p class="nte-occurrence-status__door">' . esc_html__( 'Tickets are still available at the door until sold out.', 'nettertech-events' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Whether the event's venue has opted in to door sales.
	 *
	 * @param Event $event The event.
	 * @return bool False when the event has no space, the space is unknown, or the flag is off.
	 */
	private static function space_sells_at_door( Event $event ): bool {
		$space_repo = self::$space_repo;
		if ( null === $space_repo || empty( $event->space_id ) ) {
			return false;
		}

		$space = $space_repo->find( (int) $event->space_id );

		return null !== $space && $space->door_sales;
	}

	/**
	 * Build the per-ticket-type display rows (availability, pricing, stock).
	 *
	 * Shared by the occurrence ticket form and the event-level series-pass form
	 * so both compute availability identically through the (pass-aware) capacity
	 * service.
	 *
	 * @since 1.1.3
	 *
	 * @param array<TicketType> $ticket_types Ticket types to display.
	 * @return array<int, array<string, mixed>> Row data consumed by the ticket-form template.
	 */
	private static function build_ticket_rows( array $ticket_types ): array {
		$capacity_service = self::$capacity_service;
		if ( null === $capacity_service ) {
			return array();
		}

		$tickets = array();
		foreach ( $ticket_types as $ticket_type ) {
			if ( null === $ticket_type->id ) {
				continue;
			}

			$available       = $capacity_service->get_available_count( $ticket_type->id );
			$max_purchasable = self::get_max_purchasable( $ticket_type, $available );
			$is_sold_out     = $ticket_type->is_sold_out() || 0 === $max_purchasable;

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

		return $tickets;
	}

	/**
	 * Whether any built row is a purchasable (not sold out, WC-linked) ticket.
	 *
	 * @since 1.1.3
	 *
	 * @param array<int, array<string, mixed>> $tickets Rows from build_ticket_rows().
	 * @return bool
	 */
	private static function rows_have_available( array $tickets ): bool {
		foreach ( $tickets as $row ) {
			if ( empty( $row['is_sold_out'] ) && ! empty( $row['has_product'] ) ) {
				return true;
			}
		}

		return false;
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
