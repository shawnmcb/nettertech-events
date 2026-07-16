<?php
/**
 * Ticket Type Saver Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\TicketTypeScope;
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
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface                                  $ticket_type_repo Ticket type repository.
	 * @param \NetterTechEvents\Integrations\WooCommerce\ProductManager|null $product_manager  Product manager (null when WC inactive).
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		?\NetterTechEvents\Integrations\WooCommerce\ProductManager $product_manager = null
	) {
		$this->ticket_type_repo = $ticket_type_repo;
		$this->product_manager  = $product_manager;
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
		// Check if ticketing is enabled.
		if ( empty( $post_data['ticketing_enabled'] ) ) {
			$this->ticket_type_repo->delete_for_occurrence( $occurrence_id );
			return;
		}

		// Verify occurrence_id matches form data.
		$form_occurrence_id = absint( $post_data['occurrence_id_for_tickets'] ?? 0 );
		if ( $form_occurrence_id !== $occurrence_id ) {
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
	 * @param int                  $event_id  Event ID.
	 * @param array<string, mixed> $post_data POST data array.
	 * @return void
	 */
	public function save_for_event( int $event_id, array $post_data ): void {
		if ( empty( $post_data['nte_tickets_metabox_rendered'] ) ) {
			return;
		}

		$ticket_types_data = $post_data['ticket_types'] ?? array();
		if ( ! is_array( $ticket_types_data ) ) {
			return;
		}

		$admin_skus = $this->process_event_ticket_scope( $event_id, $ticket_types_data, TicketTypeScope::EVENT );
		$this->process_event_ticket_scope( $event_id, $ticket_types_data, TicketTypeScope::TEMPLATE );

		// A pass that cannot be bought is a tab that lies (NTE-156): every saved
		// event-scoped tier gets its WooCommerce product, exactly as occurrence
		// tiers do in process_ticket_types().
		if ( null !== $this->product_manager ) {
			try {
				$this->product_manager->create_products_for_event( $event_id, $admin_skus );
			} catch ( \RuntimeException $e ) {
				DebugLogger::exception( $e, 'TicketTypeSaver' );
			}
		}
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

		// Get existing ticket type IDs.
		$existing_types = $this->ticket_type_repo->for_occurrence( $occurrence_id );
		$existing_ids   = array_filter( array_map( fn( $type ) => $type->id, $existing_types ) );
		$submitted_ids  = array();
		$saved_ids      = array();
		$admin_skus     = array();

		foreach ( $scope_data as $index => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$name = sanitize_text_field( $data['name'] ?? '' );
			if ( empty( $name ) ) {
				continue;
			}

			$ticket_type = $this->build_ticket_type( $occurrence_id, $data, $event_id );

			// Track submitted ID for deletion logic.
			$tt_id = $ticket_type->id;
			if ( null !== $tt_id && $tt_id > 0 ) {
				$submitted_ids[] = $tt_id;
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
	 * @return array<int, string> Tier id => operator-entered SKU, for product creation (NTE-160).
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
		$submitted_ids  = array();
		$saved_ids      = array();

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
			}

			// Operator-entered SKU rides to product creation (NTE-160); the
			// generated default must not win over an explicit entry.
			if ( null !== $ticket_type->id && '' !== sanitize_text_field( (string) ( $data['sku'] ?? '' ) ) ) {
				$admin_skus[ $ticket_type->id ] = sanitize_text_field( (string) $data['sku'] );
			}
		}

		$this->delete_removed_types( array_filter( $existing_ids ), $submitted_ids );

		$this->announce_save( $event_id, 0, $saved_ids );

		return $admin_skus;
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
		$ticket_type->status        = 'active';

		// Validate capacity_type.
		if ( ! in_array( $ticket_type->capacity_type, CapacityType::values(), true ) ) {
			$ticket_type->capacity_type = 'fixed';
		}

		// Set ID if updating existing.
		if ( ! empty( $data['id'] ) ) {
			$ticket_type->id = absint( $data['id'] );
		}

		if ( ! empty( $data['sale_start'] ) ) {
			$ticket_type->sale_start = sanitize_text_field( $data['sale_start'] );
		}
		if ( ! empty( $data['sale_end'] ) ) {
			$ticket_type->sale_end = sanitize_text_field( $data['sale_end'] );
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
		$ticket_type->status        = 'active';

		if ( ! in_array( $ticket_type->capacity_type, CapacityType::values(), true ) ) {
			$ticket_type->capacity_type = 'fixed';
		}
		if ( CapacityType::SHARED->value === $ticket_type->capacity_type ) {
			$ticket_type->capacity_type = 'fixed';
		}

		if ( ! empty( $data['id'] ) ) {
			$ticket_type->id = absint( $data['id'] );
		}
		if ( ! empty( $data['sale_start'] ) ) {
			$ticket_type->sale_start = sanitize_text_field( $data['sale_start'] );
		}
		if ( ! empty( $data['sale_end'] ) ) {
			$ticket_type->sale_end = sanitize_text_field( $data['sale_end'] );
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
	 * @return void
	 */
	private function delete_removed_types( array $existing_ids, array $submitted_ids ): void {
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
			return;
		}

		foreach ( $removed as $removed_id ) {
			$this->ticket_type_repo->delete( (int) $removed_id );
		}
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
