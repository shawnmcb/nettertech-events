<?php
/**
 * Ticket Type Saver Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Handles saving ticket types for occurrences from form data.
 *
 * Extracted from EventEditor to reduce complexity and improve testability.
 *
 * @since 0.9.0
 */
class TicketTypeSaver {

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Product manager for WC product sync (null when WC is inactive).
	 *
	 * @var \NetterTechEvents\Integrations\WooCommerce\ProductManager|null
	 */
	private ?\NetterTechEvents\Integrations\WooCommerce\ProductManager $product_manager;

	/**
	 * Event repository for status derivation (null falls back to ServiceRegistry).
	 *
	 * @var EventRepositoryInterface|null
	 */
	private ?EventRepositoryInterface $event_repo;

	/**
	 * Carries template changes to the copies on future dates (null: templates reach no copy).
	 *
	 * @var TemplatePropagator|null
	 */
	private ?TemplatePropagator $template_propagator;

	/**
	 * Constructor.
	 *
	 * @since 1.5.4 Added `$template_propagator`.
	 *
	 * @param TicketTypeRepositoryInterface                                  $ticket_type_repo    Ticket type repository.
	 * @param \NetterTechEvents\Integrations\WooCommerce\ProductManager|null $product_manager     Product manager (null when WC inactive).
	 * @param EventRepositoryInterface|null                                  $event_repo          Event repository for status derivation.
	 * @param TemplatePropagator|null                                        $template_propagator Carries template changes to their copies.
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		?\NetterTechEvents\Integrations\WooCommerce\ProductManager $product_manager = null,
		?EventRepositoryInterface $event_repo = null,
		?TemplatePropagator $template_propagator = null
	) {
		$this->ticket_type_repo    = $ticket_type_repo;
		$this->product_manager     = $product_manager;
		$this->event_repo          = $event_repo;
		$this->template_propagator = $template_propagator;
	}

	/**
	 * Derive a ticket's status from its parent event's status.
	 *
	 * A ticket must never be sellable before its event is (NTE-177): a published
	 * event yields `active` tickets (publishable products), any other event state
	 * yields `draft`. Without a resolvable event (missing event_id, or a lookup
	 * that returns nothing) the status fails CLOSED to `draft` — a ticket whose
	 * event cannot be confirmed published must not go on sale (NTE-188, FR-005
	 * safe direction; SPEC.md:609). The prior fail-open `active` default could
	 * publish a tier for an event that was never resolvable.
	 *
	 * @param int $event_id Parent event ID (0 when unknown).
	 * @return string 'active' or 'draft'.
	 */
	private function derive_ticket_status( int $event_id ): string {
		if ( $event_id <= 0 ) {
			return 'draft';
		}

		$repo  = $this->event_repo ?? ServiceRegistry::event_repository();
		$event = $repo->find( $event_id );

		if ( null === $event ) {
			return 'draft';
		}

		return EventStatus::PUBLISHED === $event->status ? 'active' : 'draft';
	}

	/**
	 * Save ticket types for an occurrence from form data.
	 *
	 * Caller must pass already-unslashed POST data after verifying nonce at the
	 * request boundary. The service does not read superglobals directly.
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $post_data     POST data array (required).
	 * @param int                  $event_id      Event ID to associate with ticket types.
	 * @return void
	 */
	public function save_for_occurrence( int $occurrence_id, array $post_data, int $event_id = 0 ): void {
		// The ticket section for this occurrence must actually have been on the page. Its absence
		// means the rows were never rendered — not that the operator cleared them — so deleting
		// here would wipe tiers on any save where the section did not render (NTE-186, mirroring the
		// save_for_event guard from NTE-178). Only a rendered, posted-empty section deletes.
		if ( empty( $post_data['nte_tickets_metabox_rendered'] ) ) {
			return;
		}

		// Verify the form vouches for THIS occurrence before touching its tiers.
		$form_occurrence_id = absint( $post_data['occurrence_id_for_tickets'] ?? 0 );
		if ( $form_occurrence_id !== $occurrence_id ) {
			return;
		}

		// Section rendered for this occurrence and posted disabled: the operator turned ticketing off.
		if ( empty( $post_data['ticketing_enabled'] ) ) {
			$this->ticket_type_repo->delete_for_occurrence( $occurrence_id );
			return;
		}

		$ticket_types_data = $post_data['ticket_types'] ?? array();
		if ( ! is_array( $ticket_types_data ) ) {
			return;
		}

		$this->process_ticket_types( $occurrence_id, $ticket_types_data, $event_id );
	}

	/**
	 * Save recurring-event ticket scopes from event editor form data.
	 *
	 * @since 1.5.4 Returns the templates this save created, changed and deleted.
	 *
	 * @param int                  $event_id  Event ID.
	 * @param array<string, mixed> $post_data POST data array.
	 * @return array{changed: array<int>, deleted: array<int>} Template IDs, for propagate_templates().
	 */
	public function save_for_event( int $event_id, array $post_data ): array {
		$template_changes = array(
			'changed' => array(),
			'deleted' => array(),
		);

		if ( empty( $post_data['nte_tickets_metabox_rendered'] ) ) {
			return $template_changes;
		}

		// The buffered first-save path refuses to touch tickets while ticketing
		// is off (EventSaveHandler::process_buffered_tickets); this path deleted
		// them anyway (NTE-178). The tabbed form always posts a hidden
		// ticketing_enabled=1, so its absence means the ticket rows were not on
		// the page — not that the operator removed anything.
		if ( empty( $post_data['ticketing_enabled'] ) ) {
			return $template_changes;
		}

		$ticket_types_data = $post_data['ticket_types'] ?? array();
		if ( ! is_array( $ticket_types_data ) ) {
			return $template_changes;
		}

		// nte_tickets_metabox_rendered is form-level: it proves the metabox was
		// on the page, not that a given scope's rows were. A scope whose section
		// never rendered — Templates tab absent for the current event type, rows
		// withheld via nettertech_events_admin_ticket_rows — posts nothing, and
		// running its pass would read that silence as "delete every tier"
		// (NTE-178). Only scopes the form vouches for are processed at all.
		$rendered_scopes = $post_data['ticket_types_rendered'] ?? array();
		if ( ! is_array( $rendered_scopes ) ) {
			$rendered_scopes = array();
		}

		$admin_skus      = array();
		$processed_scope = false;

		if ( ! empty( $rendered_scopes[ TicketTypeScope::EVENT->value ] ) ) {
			$admin_skus      = $this->process_event_ticket_scope( $event_id, $ticket_types_data, TicketTypeScope::EVENT )['skus'];
			$processed_scope = true;
		}

		if ( ! empty( $rendered_scopes[ TicketTypeScope::TEMPLATE->value ] ) ) {
			$templates        = $this->process_event_ticket_scope( $event_id, $ticket_types_data, TicketTypeScope::TEMPLATE );
			$template_changes = array(
				'changed' => $templates['changed'],
				'deleted' => $templates['deleted'],
			);
			$processed_scope  = true;
		}

		// A pass that cannot be bought is a tab that lies (NTE-156): every saved
		// event-scoped tier gets its WooCommerce product, exactly as occurrence
		// tiers do in process_ticket_types().
		if ( $processed_scope && null !== $this->product_manager ) {
			try {
				$this->product_manager->create_products_for_event( $event_id, $admin_skus );
			} catch ( \RuntimeException $e ) {
				DebugLogger::exception( $e, 'TicketTypeSaver' );
			}
		}

		return $template_changes;
	}

	/**
	 * Carry the templates a save created, changed or deleted to the copies on future dates.
	 *
	 * Run it after the save has settled the event's dates, so it reaches the dates the event ends
	 * up with.
	 *
	 * @since 1.5.4
	 *
	 * @param int                                             $event_id         Event ID.
	 * @param array{changed: array<int>, deleted: array<int>} $template_changes What save_for_event() returned.
	 * @return array{updated: int, created: int, retired: int, kept: array<int, \NetterTechEvents\Models\Occurrence>} See TemplatePropagator::propagate().
	 */
	public function propagate_templates( int $event_id, array $template_changes ): array {
		if ( null === $this->template_propagator ) {
			return array(
				'updated' => 0,
				'created' => 0,
				'retired' => 0,
				'kept'    => array(),
			);
		}

		return $this->template_propagator->propagate( $event_id, $template_changes['changed'], $template_changes['deleted'] );
	}

	/**
	 * Process ticket type data and save/update/delete as needed.
	 *
	 * @param int                        $occurrence_id     Occurrence ID.
	 * @param array<array<string,mixed>> $ticket_types_data Ticket type form data.
	 * @param int                        $event_id          Event ID.
	 * @return void
	 */
	private function process_ticket_types( int $occurrence_id, array $ticket_types_data, int $event_id = 0 ): void {
		// Form posts ticket_types nested by scope (ticket_types[occurrence][N][field]).
		// Single events only carry the 'occurrence' scope. Tolerate legacy callers
		// that pass a flat indexed array directly by falling through when no scope
		// key is present.
		$scope_data = isset( $ticket_types_data[ TicketTypeScope::OCCURRENCE->value ] )
			&& is_array( $ticket_types_data[ TicketTypeScope::OCCURRENCE->value ] )
				? $ticket_types_data[ TicketTypeScope::OCCURRENCE->value ]
				: $ticket_types_data;

		// Only the date's own tiers: for a date without any, for_occurrence() lists the event's
		// series passes and templates, which this form neither shows nor may delete or rewrite.
		$own_types = array();
		foreach ( $this->ticket_type_repo->for_occurrence( $occurrence_id ) as $type ) {
			if ( null !== $type->id && $occurrence_id === $type->occurrence_id ) {
				$own_types[ $type->id ] = $type;
			}
		}
		$existing_ids  = array_keys( $own_types );
		$submitted_ids = array();
		$saved_ids     = array();
		$admin_skus    = array();

		foreach ( $scope_data as $index => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$name = sanitize_text_field( $data['name'] ?? '' );
			if ( empty( $name ) ) {
				continue;
			}

			$ticket_type = $this->build_ticket_type( $occurrence_id, $data, $event_id );

			// A posted id that is not one of this date's own tiers belongs to another date or
			// scope; saving it here would move that tier onto this date.
			$tt_id = $ticket_type->id;
			if ( null !== $tt_id && $tt_id > 0 ) {
				if ( ! isset( $own_types[ $tt_id ] ) ) {
					continue;
				}
				$submitted_ids[]          = $tt_id;
				$ticket_type->template_id = $this->unchanged( $own_types[ $tt_id ], $ticket_type )
					? $own_types[ $tt_id ]->template_id
					: null;
			}

			$this->save_ticket_type( $ticket_type );

			// After the save, not before: a tier created by this request is given its id here and
			// nowhere else, and an extension attaching to a tier the operator has only just added
			// has no other way to learn what it was called.
			if ( null !== $ticket_type->id ) {
				$saved_ids[ TicketTypeScope::OCCURRENCE->value ][ $index ] = $ticket_type->id;
			}

			// The SKU the operator typed lives on the WC product, not the tier, so
			// it rides to product creation separately. Only the metabox AJAX path
			// used to carry it (NTE-114); this path silently dropped it, and the
			// generated default won — the "my SKU reverted" report (NTE-160).
			if ( null !== $ticket_type->id && '' !== sanitize_text_field( (string) ( $data['sku'] ?? '' ) ) ) {
				$admin_skus[ $ticket_type->id ] = sanitize_text_field( (string) $data['sku'] );
			}
		}

		// Delete ticket types that were removed from the form.
		$this->delete_removed_types( $existing_ids, $submitted_ids );

		// Create WooCommerce products for paid ticket types.
		$this->create_woocommerce_products( $occurrence_id, $admin_skus );

		$this->announce_save( $event_id, $occurrence_id, $saved_ids );
	}

	/**
	 * Whether a posted tier carries the same operator-set values as the stored one.
	 *
	 * A copy of a template stays linked to it until one of these actually changes, so saving a
	 * date for another reason does not detach its tickets.
	 *
	 * @param TicketType $stored The tier as stored.
	 * @param TicketType $posted The tier as posted back.
	 * @return bool
	 */
	private function unchanged( TicketType $stored, TicketType $posted ): bool {
		$stored = clone $stored;
		$posted = clone $posted;
		$stored->normalize_capacity();
		$posted->normalize_capacity();

		return $this->comparable( $stored ) === $this->comparable( $posted );
	}

	/**
	 * The values a copy takes from its template that the date's form shows, in a form two tiers can
	 * be compared by.
	 *
	 * Sale windows are compared to the minute the form shows; the form posts `Y-m-dTH:i` and the
	 * table returns `Y-m-d H:i:s`.
	 *
	 * @param TicketType $ticket_type Tier.
	 * @return array<string, mixed>
	 */
	private function comparable( TicketType $ticket_type ): array {
		$values = TemplatePropagator::followed( $ticket_type );
		unset( $values['sort_order'], $values['status'] );

		foreach ( array( 'sale_start', 'sale_end' ) as $window ) {
			$values[ $window ] = substr( str_replace( 'T', ' ', (string) $values[ $window ] ), 0, 16 );
		}

		return $values;
	}

	/**
	 * Tell extensions which tiers this save produced.
	 *
	 * The same hook the metabox save path fires, from the path the event editor actually uses. An
	 * extension cannot be expected to know which of the two saved the form in front of it.
	 *
	 * @param int                                   $event_id      Event ID.
	 * @param int                                   $occurrence_id Occurrence ID.
	 * @param array<string, array<int|string, int>> $saved_ids     Scope, then posted row, to tier id.
	 * @return void
	 */
	private function announce_save( int $event_id, int $occurrence_id, array $saved_ids ): void {
		$submitted = array();

		foreach ( $saved_ids as $rows ) {
			foreach ( $rows as $ticket_type_id ) {
				$submitted[] = $ticket_type_id;
			}
		}

		/**
		 * Fires after ticket types are saved for an event/occurrence.
		 *
		 * @since 1.0.2
		 *
		 * @param int                                   $event_id      Event ID.
		 * @param int                                   $occurrence_id Occurrence ID.
		 * @param int[]                                 $submitted_ids Ids of every tier saved, new ones included.
		 * @param array<string, array<int|string, int>> $saved_ids     Scope, then posted row, to tier id.
		 */
		do_action( 'nettertech_events_ticket_types_saved', $event_id, $occurrence_id, $submitted, $saved_ids );
	}

	/**
	 * Process event-level ticket type data for one scope.
	 *
	 * @param int                  $event_id          Event ID.
	 * @param array<string, mixed> $ticket_types_data Submitted ticket data grouped by scope.
	 * @param TicketTypeScope      $scope             Event-level scope to save.
	 * @return array{skus: array<int, string>, changed: array<int>, deleted: array<int>} Tier id =>
	 *         operator-entered SKU for product creation (NTE-160); tiers created or changed; tiers deleted.
	 */
	private function process_event_ticket_scope( int $event_id, array $ticket_types_data, TicketTypeScope $scope ): array {
		$scope_data = $ticket_types_data[ $scope->value ] ?? array();
		if ( ! is_array( $scope_data ) ) {
			$scope_data = array();
		}
		$admin_skus = array();

		$existing_types = array_filter(
			$this->ticket_type_repo->for_event( $event_id ),
			static fn( TicketType $type ): bool => $type->scope === $scope->value
		);
		$existing_ids   = array_map( static fn( TicketType $type ): ?int => $type->id, $existing_types );
		$stored         = array();
		foreach ( $existing_types as $existing_type ) {
			if ( null !== $existing_type->id ) {
				$stored[ $existing_type->id ] = $existing_type;
			}
		}
		$submitted_ids = array();
		$saved_ids     = array();
		$changed       = array();

		foreach ( $scope_data as $index => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$name = sanitize_text_field( $data['name'] ?? '' );
			if ( empty( $name ) ) {
				continue;
			}

			$ticket_type = $this->build_event_ticket_type( $event_id, $data, $scope );

			$tt_id = $ticket_type->id;
			if ( null !== $tt_id && $tt_id > 0 ) {
				$submitted_ids[] = $tt_id;
			}

			$this->save_ticket_type( $ticket_type );

			if ( null !== $ticket_type->id ) {
				$saved_ids[ $scope->value ][ $index ] = $ticket_type->id;

				if ( TicketTypeScope::TEMPLATE === $scope && $this->created_or_changed( $stored[ $ticket_type->id ] ?? null, $ticket_type->id ) ) {
					$changed[] = $ticket_type->id;
				}
			}

			// Operator-entered SKU rides to product creation (NTE-160); the
			// generated default must not win over an explicit entry.
			if ( null !== $ticket_type->id && '' !== sanitize_text_field( (string) ( $data['sku'] ?? '' ) ) ) {
				$admin_skus[ $ticket_type->id ] = sanitize_text_field( (string) $data['sku'] );
			}
		}

		$deleted = $this->delete_removed_types( array_filter( $existing_ids ), $submitted_ids );

		$this->announce_save( $event_id, 0, $saved_ids );

		return array(
			'skus'    => $admin_skus,
			'changed' => $changed,
			'deleted' => $deleted,
		);
	}

	/**
	 * Whether a template saved in this request is new, or differs from how it was stored.
	 *
	 * @param TicketType|null $stored   The template before the save; null when it is new.
	 * @param int             $saved_id The saved template's ID.
	 * @return bool
	 */
	private function created_or_changed( ?TicketType $stored, int $saved_id ): bool {
		if ( null === $stored ) {
			return true;
		}

		$saved = $this->ticket_type_repo->find( $saved_id );

		return null !== $saved && TemplatePropagator::differs( $stored, $saved );
	}

	/**
	 * Build a TicketType model from form data.
	 *
	 * @param int                 $occurrence_id Occurrence ID.
	 * @param array<string,mixed> $data          Form data for single ticket type.
	 * @param int                 $event_id      Event ID.
	 * @return TicketType Built ticket type model.
	 */
	private function build_ticket_type( int $occurrence_id, array $data, int $event_id = 0 ): TicketType {
		$ticket_dto  = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
		$default_min = $ticket_dto->tickets->default_min_per_order;
		$default_max = $ticket_dto->tickets->default_max_per_order;

		$ticket_type                = new TicketType();
		$ticket_type->occurrence_id = $occurrence_id;
		$ticket_type->event_id      = $event_id > 0 ? $event_id : null;
		$ticket_type->name          = sanitize_text_field( $data['name'] ?? '' );
		$ticket_type->description   = sanitize_textarea_field( $data['description'] ?? '' );
		$ticket_type->price         = (float) ( $data['price'] ?? 0 );
		$ticket_type->capacity_type = sanitize_text_field( $data['capacity_type'] ?? 'fixed' );
		$ticket_type->capacity      = ! empty( $data['capacity'] ) ? absint( $data['capacity'] ) : null;
		$ticket_type->min_per_order = absint( $data['min_per_order'] ?? $default_min );
		$ticket_type->max_per_order = absint( $data['max_per_order'] ?? $default_max );
		// A ticket is only sellable once its event is public (NTE-177): status
		// follows the event's, so a draft event's tickets stay draft/non-purchasable.
		$ticket_type->status = $this->derive_ticket_status( $event_id );

		// Validate capacity_type.
		if ( ! in_array( $ticket_type->capacity_type, CapacityType::values(), true ) ) {
			$ticket_type->capacity_type = 'fixed';
		}

		// Set ID if updating existing.
		if ( ! empty( $data['id'] ) ) {
			$ticket_type->id = absint( $data['id'] );
		}

		$sale_start = SaleWindowInput::compose( $data, 'sale_start', SaleWindowInput::DEFAULT_START_TIME );
		if ( '' !== $sale_start ) {
			$ticket_type->sale_start = $sale_start;
		}
		$sale_end = SaleWindowInput::compose( $data, 'sale_end', SaleWindowInput::DEFAULT_END_TIME );
		if ( '' !== $sale_end ) {
			$ticket_type->sale_end = $sale_end;
		}

		// An edit posts back the fields the operator can see; the row's stateful
		// links are not among them. Rebuilding the model without them severed the
		// tier from its WooCommerce product on every save, and the next sync then
		// minted a fresh product — orphaning the old one along with its stock and
		// order history (NTE-158).
		if ( null !== $ticket_type->id ) {
			$existing = $this->ticket_type_repo->find( $ticket_type->id );
			if ( $existing ) {
				$ticket_type->wc_product_id   = $existing->wc_product_id;
				$ticket_type->wc_variation_id = $existing->wc_variation_id;
			}
		}

		return $ticket_type;
	}

	/**
	 * Build an event-level TicketType model from form data.
	 *
	 * @param int                 $event_id Event ID.
	 * @param array<string,mixed> $data     Form data for one ticket type.
	 * @param TicketTypeScope     $scope    Event-level scope.
	 * @return TicketType Built ticket type model.
	 * @throws ValidationException When an occurrence-only capacity type (SHARED/SEATED) is chosen for an event-level tier (R7).
	 */
	private function build_event_ticket_type( int $event_id, array $data, TicketTypeScope $scope ): TicketType {
		$ticket_dto  = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
		$default_min = $ticket_dto->tickets->default_min_per_order;
		$default_max = $ticket_dto->tickets->default_max_per_order;

		$ticket_type                = new TicketType();
		$ticket_type->scope         = $scope->value;
		$ticket_type->event_id      = $event_id;
		$ticket_type->occurrence_id = null;
		$ticket_type->name          = sanitize_text_field( $data['name'] ?? '' );
		$ticket_type->description   = sanitize_textarea_field( $data['description'] ?? '' );
		$ticket_type->price         = (float) ( $data['price'] ?? 0 );
		$ticket_type->capacity_type = sanitize_text_field( $data['capacity_type'] ?? 'fixed' );
		$ticket_type->capacity      = ! empty( $data['capacity'] ) ? absint( $data['capacity'] ) : null;
		$ticket_type->min_per_order = absint( $data['min_per_order'] ?? $default_min );
		$ticket_type->max_per_order = absint( $data['max_per_order'] ?? $default_max );
		// Status follows the parent event's (NTE-177); a draft event's passes
		// stay draft/non-purchasable until it publishes.
		$ticket_type->status = $this->derive_ticket_status( $event_id );

		if ( ! in_array( $ticket_type->capacity_type, CapacityType::values(), true ) ) {
			$ticket_type->capacity_type = 'fixed';
		}

		// SHARED/SEATED are occurrence-level concepts: an event-level tier (series pass or
		// template) carries its own allotment and cannot borrow a single date's house. Silently
		// coercing the operator's choice to fixed hid a real conflict; surface it as a validation
		// error instead (operator ruling 2026-07-20, spec-001 invention audit — R7).
		$chosen_type = CapacityType::tryFrom( $ticket_type->capacity_type );
		if ( null !== $chosen_type && $chosen_type->requires_occurrence_scope() ) {
			throw ValidationException::fromErrors(
				array(
					sprintf(
						/* translators: 1: capacity type label (e.g. Shared); 2: ticket tier name. */
						esc_html__( '%1$s capacity cannot be used on the event-level tier "%2$s" — event tiers carry their own allotment. Choose Fixed or Unlimited.', 'nettertech-events' ),
						esc_html( $chosen_type->label() ),
						esc_html( $ticket_type->name )
					),
				)
			);
		}

		if ( ! empty( $data['id'] ) ) {
			$ticket_type->id = absint( $data['id'] );
		}
		$sale_start = SaleWindowInput::compose( $data, 'sale_start', SaleWindowInput::DEFAULT_START_TIME );
		if ( '' !== $sale_start ) {
			$ticket_type->sale_start = $sale_start;
		}
		$sale_end = SaleWindowInput::compose( $data, 'sale_end', SaleWindowInput::DEFAULT_END_TIME );
		if ( '' !== $sale_end ) {
			$ticket_type->sale_end = $sale_end;
		}

		// An edit posts back the fields the operator can see; the row's stateful
		// links are not among them. Rebuilding the model without them severed the
		// tier from its WooCommerce product on every save, and the next sync then
		// minted a fresh product — orphaning the old one along with its stock and
		// order history (NTE-158).
		if ( null !== $ticket_type->id ) {
			$existing = $this->ticket_type_repo->find( $ticket_type->id );
			if ( $existing ) {
				$ticket_type->wc_product_id   = $existing->wc_product_id;
				$ticket_type->wc_variation_id = $existing->wc_variation_id;
			}
		}

		return $ticket_type;
	}

	/**
	 * Save a ticket type with error handling.
	 *
	 * @param TicketType $ticket_type Ticket type to save.
	 * @return void
	 */
	private function save_ticket_type( TicketType $ticket_type ): void {
		try {
			$this->ticket_type_repo->save( $ticket_type );
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'TicketTypeSaver' );
		}
	}

	/**
	 * Delete ticket types that were removed from the form.
	 *
	 * The premise here is that a tier which existed before the save and did not come back with it
	 * was deleted by the operator. That premise holds only for tiers the form actually offered.
	 * An extension may withhold a tier it derives and keeps in step with another — see
	 * `nettertech_events_admin_ticket_rows` — and such a tier is missing from the payload for
	 * reasons that have nothing to do with anyone's intent. Deleting it would be reading silence
	 * as an instruction.
	 *
	 * @param array<int> $existing_ids  IDs of existing ticket types.
	 * @param array<int> $submitted_ids IDs submitted in the form.
	 * @return array<int> IDs deleted.
	 */
	private function delete_removed_types( array $existing_ids, array $submitted_ids ): array {
		$removed = array_values( array_diff( $existing_ids, $submitted_ids ) );

		/**
		 * Filters the tiers about to be deleted for not coming back with the form.
		 *
		 * @since 1.1.2
		 *
		 * @param array<int> $removed       Tier IDs about to be deleted.
		 * @param array<int> $existing_ids  Every tier that existed before this save.
		 * @param array<int> $submitted_ids The tier IDs the form posted back.
		 */
		$removed = apply_filters(
			'nettertech_events_ticket_types_to_delete',
			$removed,
			$existing_ids,
			$submitted_ids
		);

		if ( ! is_array( $removed ) ) {
			return array();
		}

		$deleted = array();
		foreach ( $removed as $removed_id ) {
			if ( $this->ticket_type_repo->delete( (int) $removed_id ) ) {
				$deleted[] = (int) $removed_id;
			}
		}

		return $deleted;
	}

	/**
	 * Create WooCommerce products for paid ticket types.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return void
	 */
	/**
	 * Create WooCommerce products for the occurrence's tiers.
	 *
	 * @param int                $occurrence_id Occurrence ID.
	 * @param array<int, string> $admin_skus    Tier id => operator-entered SKU (first save only; NTE-114).
	 * @return void
	 */
	private function create_woocommerce_products( int $occurrence_id, array $admin_skus = array() ): void {
		if ( null !== $this->product_manager ) {
			$this->product_manager->create_products_for_occurrence( $occurrence_id, true, $admin_skus );
		}
	}
}
