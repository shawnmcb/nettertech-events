<?php
/**
 * Tickets Metabox for event editing.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Admin\Metaboxes\TicketFormAssets;
use NetterTechEvents\Admin\Metaboxes\TicketFormRenderer;
use NetterTechEvents\Admin\Metaboxes\TicketSaveHandler;

/**
 * Handles the tickets metabox UI and saving logic.
 *
 * Supports three-tier ticket scoping:
 * - OCCURRENCE: Single occurrence tickets
 * - EVENT: Series passes valid for all occurrences
 * - TEMPLATE: Templates that auto-create tickets for new occurrences
 *
 * @since 0.8.0
 * @api
 */
class TicketsMetabox {

	/**
	 * Metabox ID.
	 */
	public const METABOX_ID = 'nettertech_events_tickets_metabox';

	/**
	 * Nonce action.
	 */
	public const NONCE_ACTION = 'nettertech_events_tickets_metabox_nonce';

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Capacity service.
	 *
	 * @var CapacityServiceInterface
	 */
	private CapacityServiceInterface $capacity_service;

	/**
	 * Current event.
	 *
	 * @var Event|null
	 */
	private ?Event $event = null;

	/**
	 * Current occurrence (for single events).
	 *
	 * @var Occurrence|null
	 */
	private ?Occurrence $occurrence = null;

	/**
	 * Form assets handler.
	 *
	 * @var TicketFormAssets
	 */
	private TicketFormAssets $assets;

	/**
	 * Form row renderer.
	 *
	 * @var TicketFormRenderer
	 */
	private TicketFormRenderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 * @param CapacityServiceInterface      $capacity_service Capacity service.
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service
	) {
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;
		$this->assets           = new TicketFormAssets();
		$this->renderer         = new TicketFormRenderer( $this->capacity_service );
	}

	/**
	 * Set context for rendering.
	 *
	 * @param Event|null      $event      Event object.
	 * @param Occurrence|null $occurrence Occurrence object.
	 * @return self
	 */
	public function set_context( ?Event $event, ?Occurrence $occurrence = null ): self {
		$this->event      = $event;
		$this->occurrence = $occurrence;
		return $this;
	}

	/**
	 * Render the metabox content.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->event || ! $this->event->id ) {
			$this->render_save_first_notice();
			return;
		}

		$is_single_event = 'single' === $this->event->event_type;
		$is_recurring    = 'recurring' === $this->event->event_type;
		$has_woocommerce = class_exists( 'WooCommerce' );

		// A single event carrying extra hand-picked dates can sell a pass spanning
		// them, exactly as a recurring event can (NTE-156). Only the one-date single
		// keeps the flat, tabless form.
		$occurrence_repo = ServiceRegistry::get( OccurrenceRepositoryInterface::class );
		$is_multi_date   = $is_single_event
			&& $occurrence_repo->count_for_event( (int) $this->event->id, 'scheduled' ) > 1;

		// Get ticket types based on event type.
		$occurrence_tickets = array();
		$event_tickets      = array();
		$template_tickets   = array();

		if ( $this->occurrence && $this->occurrence->id ) {
			$occurrence_tickets = $this->ticket_type_repo->for_occurrence( $this->occurrence->id );
		}

		if ( ( $is_recurring || $is_multi_date ) && $this->event->id ) {
			// Scope explicitly: occurrence tiers carry event_id too (since 1.1.2.1),
			// and an unscoped for_event() would list every date's tickets as passes.
			$event_tickets = $this->ticket_type_repo->for_event(
				$this->event->id,
				array( 'scope' => TicketTypeScope::EVENT->value )
			);
		}
		if ( $is_recurring && $this->event->id ) {
			// Templates propagate to *generated* dates, which only a pattern produces.
			$template_tickets = $this->ticket_type_repo->get_templates( $this->event->id );
		}

		$occurrence_tickets = $this->editable_rows( $occurrence_tickets, TicketTypeScope::OCCURRENCE );
		$event_tickets      = $this->editable_rows( $event_tickets, TicketTypeScope::EVENT );
		$template_tickets   = $this->editable_rows( $template_tickets, TicketTypeScope::TEMPLATE );

		$has_tickets = ! empty( $occurrence_tickets ) || ! empty( $event_tickets ) || ! empty( $template_tickets );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_ACTION );
		?>
		<div class="nte-tickets-metabox">
			<input type="hidden" name="nte_tickets_metabox_rendered" value="1">
			<?php if ( ! $has_woocommerce ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'WooCommerce is not active. Paid ticketing requires WooCommerce.', 'nettertech-events' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $is_single_event && ! $is_multi_date ) : ?>
				<?php $this->render_single_event_tickets( $occurrence_tickets ); ?>
			<?php else : ?>
				<?php $this->render_recurring_event_tickets( $occurrence_tickets, $event_tickets, $template_tickets, $is_recurring ); ?>
			<?php endif; ?>
		</div>
		<?php

		$this->assets->render_scripts();
		$this->assets->render_styles();
	}

	/**
	 * Render the ticket types for one date, on that date's own editor.
	 *
	 * The event editor's ticket box is bound to the event's *first* date, which is the only date it
	 * has ever been able to edit. That was survivable while every date's tickets were either the
	 * same (a single event has one date) or generated from a template (a recurring one). It stopped
	 * being survivable when a date could be added by hand: an added date is a date the event really
	 * runs on, and without this there was no screen anywhere that could give it a ticket to sell.
	 *
	 * Only the occurrence scope. Templates and series passes belong to the event and are edited on
	 * the event; showing them here would offer to edit, from one date, what governs all of them.
	 *
	 * @since 1.1.2
	 *
	 * @return void
	 */
	public function render_for_occurrence(): void {
		if ( ! $this->event || ! $this->event->id || ! $this->occurrence || ! $this->occurrence->id ) {
			return;
		}

		$tickets = $this->editable_rows(
			$this->ticket_type_repo->for_occurrence( $this->occurrence->id ),
			TicketTypeScope::OCCURRENCE
		);

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_ACTION );
		?>
		<div class="nte-tickets-metabox">
			<input type="hidden" name="nte_tickets_metabox_rendered" value="1">

			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'WooCommerce is not active. Paid ticketing requires WooCommerce.', 'nettertech-events' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_single_event_tickets( $tickets ); ?>
		</div>
		<?php

		$this->assets->render_scripts();
		$this->assets->render_styles();
	}

	/**
	 * Render notice to save event first.
	 *
	 * @return void
	 */
	private function render_save_first_notice(): void {
		$presenter = new Presenters\SaveFirstNoticePresenter();
		include dirname( __DIR__, 3 ) . '/templates/admin/metaboxes/tickets-save-first-notice.php';
	}

	/**
	 * Render ticket management for single events.
	 *
	 * @param TicketType[] $tickets Existing ticket types.
	 * @return void
	 */
	private function render_single_event_tickets( array $tickets ): void {
		$is_enabled = ! empty( $tickets );
		?>
		<div class="nte-ticketing-toggle">
			<label class="nte-toggle-label">
				<input type="checkbox" name="ticketing_enabled" id="nte-ticketing-enabled" value="1"
					<?php checked( $is_enabled ); ?>>
				<span class="nte-toggle-slider"></span>
				<strong><?php esc_html_e( 'Enable Ticketing', 'nettertech-events' ); ?></strong>
			</label>
		</div>

		<div id="nte-tickets-container" class="<?php echo $is_enabled ? '' : 'nte-hidden'; ?>">
			<?php if ( $this->occurrence && $this->occurrence->id ) : ?>
				<input type="hidden" name="occurrence_id_for_tickets"
						value="<?php echo esc_attr( (string) $this->occurrence->id ); ?>">

				<div id="nte-ticket-types-list">
					<?php
					if ( ! empty( $tickets ) ) {
						foreach ( $tickets as $index => $ticket ) {
							$this->renderer->render_ticket_row( $ticket, $index, TicketTypeScope::OCCURRENCE );
						}
					}
					?>
				</div>

				<div class="nte-add-ticket-row">
					<button type="button" class="button nte-add-ticket" data-scope="occurrence">
						<span class="dashicons dashicons-plus-alt2"></span>
						<?php esc_html_e( 'Add Ticket Type', 'nettertech-events' ); ?>
					</button>
				</div>

				<?php
				do_action( 'nettertech_events_ticket_add_button_area', $this->event, count( $tickets ), 'occurrence' );
				?>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Set the event date and time first to configure tickets.', 'nettertech-events' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<?php $this->renderer->render_ticket_template( TicketTypeScope::OCCURRENCE ); ?>
		<?php
	}

	/**
	 * Render ticket management for recurring events.
	 *
	 * @param TicketType[] $occurrence_tickets Occurrence-specific tickets.
	 * @param TicketType[] $event_tickets      Event-wide tickets (series passes).
	 * @param TicketType[] $template_tickets   Template tickets.
	 * @param bool         $show_templates     Whether the Templates tab renders. Templates
	 *                                         propagate to pattern-generated dates, so a
	 *                                         multi-date single event (hand-picked dates
	 *                                         only) has nothing for them to act on.
	 * @return void
	 */
	private function render_recurring_event_tickets(
		array $occurrence_tickets,
		array $event_tickets,
		array $template_tickets,
		bool $show_templates = true
	): void {
		?>
		<?php
		// The tabbed form has no Enable Ticketing toggle — ticketing is implicit.
		// The occurrence save path reads a MISSING ticketing_enabled as "switched
		// off" and deletes the date's tickets (catastrophic when the toggle was
		// never on the page). A multi-date single event runs that path from this
		// form (NTE-156), so say explicitly that ticketing is on.
		?>
		<input type="hidden" name="ticketing_enabled" value="1">
		<div class="nte-ticket-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Ticket management tabs', 'nettertech-events' ); ?>">
			<button type="button" class="nte-tab-button active" data-tab="series-passes"
					role="tab" aria-selected="true" aria-controls="nte-tab-series-passes" id="nte-tabbutton-series-passes">
				<?php esc_html_e( 'Series Passes', 'nettertech-events' ); ?>
				<span class="nte-badge"><?php echo count( $event_tickets ); ?></span>
			</button>
			<?php if ( $show_templates ) : ?>
				<button type="button" class="nte-tab-button" data-tab="templates"
						role="tab" aria-selected="false" aria-controls="nte-tab-templates" id="nte-tabbutton-templates">
					<?php esc_html_e( 'Ticket Templates', 'nettertech-events' ); ?>
					<span class="nte-badge"><?php echo count( $template_tickets ); ?></span>
				</button>
			<?php endif; ?>
			<?php if ( $this->occurrence ) : ?>
				<button type="button" class="nte-tab-button" data-tab="occurrence"
						role="tab" aria-selected="false" aria-controls="nte-tab-occurrence" id="nte-tabbutton-occurrence">
					<?php esc_html_e( 'This Occurrence', 'nettertech-events' ); ?>
					<span class="nte-badge"><?php echo count( $occurrence_tickets ); ?></span>
				</button>
			<?php endif; ?>
		</div>

		<!-- Series Passes Tab -->
		<div class="nte-tab-content active" id="nte-tab-series-passes" role="tabpanel" aria-labelledby="nte-tabbutton-series-passes">
			<p class="description">
				<?php esc_html_e( 'Series passes are valid for all occurrences of this event.', 'nettertech-events' ); ?>
			</p>

			<input type="hidden" name="event_id_for_tickets"
					value="<?php echo esc_attr( (string) ( $this->event->id ?? '' ) ); ?>">

			<div id="nte-event-ticket-types-list" class="nte-ticket-list">
				<?php
				if ( ! empty( $event_tickets ) ) {
					foreach ( $event_tickets as $index => $ticket ) {
						$this->renderer->render_ticket_row( $ticket, $index, TicketTypeScope::EVENT );
					}
				} else {
					?>
					<p class="nte-empty-state">
						<?php esc_html_e( 'No series passes configured. Add one to allow customers to attend all occurrences.', 'nettertech-events' ); ?>
					</p>
					<?php
				}
				?>
			</div>

			<div class="nte-add-ticket-row">
				<button type="button" class="button nte-add-ticket" data-scope="event">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Add Series Pass', 'nettertech-events' ); ?>
				</button>
			</div>

			<?php
			do_action( 'nettertech_events_ticket_add_button_area', $this->event, count( $event_tickets ), 'series' );
			?>
		</div>

		<!-- Templates Tab -->
		<?php if ( $show_templates ) : ?>
		<div class="nte-tab-content" id="nte-tab-templates" role="tabpanel" aria-labelledby="nte-tabbutton-templates">
			<p class="description">
				<?php esc_html_e( 'Templates automatically create tickets for each new occurrence.', 'nettertech-events' ); ?>
			</p>

			<div id="nte-template-ticket-types-list" class="nte-ticket-list">
				<?php
				if ( ! empty( $template_tickets ) ) {
					foreach ( $template_tickets as $index => $ticket ) {
						$this->renderer->render_ticket_row( $ticket, $index, TicketTypeScope::TEMPLATE );
					}
				} else {
					?>
					<p class="nte-empty-state">
						<?php esc_html_e( 'No templates configured. Add one to auto-create tickets for new occurrences.', 'nettertech-events' ); ?>
					</p>
					<?php
				}
				?>
			</div>

			<div class="nte-add-ticket-row">
				<button type="button" class="button nte-add-ticket" data-scope="template">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Add Template', 'nettertech-events' ); ?>
				</button>
			</div>

			<?php
			do_action( 'nettertech_events_ticket_add_button_area', $this->event, count( $template_tickets ), 'template' );
			?>
		</div>
		<?php endif; ?>

		<!-- Occurrence Tab -->
		<?php if ( $this->occurrence ) : ?>
			<div class="nte-tab-content" id="nte-tab-occurrence" role="tabpanel" aria-labelledby="nte-tabbutton-occurrence">
				<p class="description">
					<?php
					printf(
						/* translators: %s: occurrence date */
						esc_html__( 'Tickets specific to %s only.', 'nettertech-events' ),
						esc_html( $this->occurrence->get_formatted_date() )
					);
					?>
				</p>

				<input type="hidden" name="occurrence_id_for_tickets"
						value="<?php echo esc_attr( (string) $this->occurrence->id ); ?>">

				<div id="nte-occurrence-ticket-types-list" class="nte-ticket-list">
					<?php
					if ( ! empty( $occurrence_tickets ) ) {
						foreach ( $occurrence_tickets as $index => $ticket ) {
							$this->renderer->render_ticket_row( $ticket, $index, TicketTypeScope::OCCURRENCE );
						}
					} else {
						?>
						<p class="nte-empty-state">
							<?php esc_html_e( 'No occurrence-specific tickets. These override series passes for this date.', 'nettertech-events' ); ?>
						</p>
						<?php
					}
					?>
				</div>

				<div class="nte-add-ticket-row">
					<button type="button" class="button nte-add-ticket" data-scope="occurrence">
						<span class="dashicons dashicons-plus-alt2"></span>
						<?php esc_html_e( 'Add Occurrence Ticket', 'nettertech-events' ); ?>
					</button>
				</div>

				<?php
				do_action( 'nettertech_events_ticket_add_button_area', $this->event, count( $occurrence_tickets ), 'occurrence' );
				?>
			</div>
		<?php endif; ?>

		<?php
		$this->renderer->render_ticket_template( TicketTypeScope::EVENT );
		$this->renderer->render_ticket_template( TicketTypeScope::TEMPLATE );
		$this->renderer->render_ticket_template( TicketTypeScope::OCCURRENCE );
	}

	/**
	 * The tiers this form should offer the operator to edit.
	 *
	 * Not every tier that exists is a tier the operator authored. An extension may derive one
	 * from another and keep it in step — Pro's early-bird tier is generated from its parent's
	 * settings, not written by hand — and editing such a tier directly would only be undone the
	 * next time its parent was saved. A derived tier is therefore withheld from this form and
	 * managed through whatever control created it.
	 *
	 * Withheld here and nowhere else: the tier still sells, still reports, still owns its
	 * attendees. This governs one form, not the tier's existence.
	 *
	 * @param array<\NetterTechEvents\Models\TicketType> $tickets The tiers found for this scope.
	 * @param TicketTypeScope                            $scope   Which list is being drawn.
	 * @return array<\NetterTechEvents\Models\TicketType>
	 */
	private function editable_rows( array $tickets, TicketTypeScope $scope ): array {
		/**
		 * Filters the tiers offered for editing in the tickets metabox.
		 *
		 * @since 1.1.2
		 *
		 * @param array<\NetterTechEvents\Models\TicketType>    $tickets    The tiers about to be drawn.
		 * @param TicketTypeScope                               $scope      Which list is being drawn.
		 * @param \NetterTechEvents\Models\Event|null           $event      The event being edited.
		 * @param \NetterTechEvents\Models\Occurrence|null      $occurrence The occurrence, when one is in scope.
		 */
		$filtered = apply_filters(
			'nettertech_events_admin_ticket_rows',
			$tickets,
			$scope,
			$this->event,
			$this->occurrence
		);

		return is_array( $filtered ) ? array_values( $filtered ) : $tickets;
	}

	/**
	 * Save ticket types from form submission.
	 *
	 * @param int                                                            $event_id          Event ID.
	 * @param int                                                            $occurrence_id     Occurrence ID (optional).
	 * @param \NetterTechEvents\Contracts\OccurrenceRepositoryInterface      $occurrence_repo   Occurrence repository.
	 * @param \NetterTechEvents\Contracts\CapacityServiceInterface           $capacity_service  Capacity service.
	 * @param \NetterTechEvents\Contracts\TicketTypeRepositoryInterface      $ticket_type_repo  Ticket type repository.
	 * @param \NetterTechEvents\Integrations\WooCommerce\ProductManager|null $product_manager   Product manager (nullable).
	 * @return void
	 */
	public static function save(
		int $event_id,
		int $occurrence_id,
		\NetterTechEvents\Contracts\OccurrenceRepositoryInterface $occurrence_repo,
		\NetterTechEvents\Contracts\CapacityServiceInterface $capacity_service,
		\NetterTechEvents\Contracts\TicketTypeRepositoryInterface $ticket_type_repo,
		?\NetterTechEvents\Integrations\WooCommerce\ProductManager $product_manager = null
	): void {
		$handler = new TicketSaveHandler( self::NONCE_ACTION, $ticket_type_repo, $occurrence_repo, $product_manager, $capacity_service );
		$handler->handle( $event_id, $occurrence_id );
	}
}
