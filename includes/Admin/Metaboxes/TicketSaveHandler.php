<?php
/**
 * Ticket save handler.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Handles saving ticket types from form submission.
 *
 * Extracted from TicketsMetabox to reduce god class size.
 *
 * @since 0.9.5
 */
class TicketSaveHandler {

	/**
	 * Nonce action for verification.
	 *
	 * @var string
	 */
	private string $nonce_action;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Occurrence repository.
	 *
	 * @var \NetterTechEvents\Contracts\OccurrenceRepositoryInterface|null
	 */
	private ?\NetterTechEvents\Contracts\OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Product manager for WC product sync.
	 *
	 * @var \NetterTechEvents\Integrations\WooCommerce\ProductManager|null
	 */
	private ?\NetterTechEvents\Integrations\WooCommerce\ProductManager $product_manager;

	/**
	 * Capacity service for buffer stock.
	 *
	 * @var CapacityServiceInterface|null
	 */
	private ?CapacityServiceInterface $capacity_service;

	/**
	 * Constructor.
	 *
	 * @param string                                                         $nonce_action      Nonce action for verification.
	 * @param TicketTypeRepositoryInterface                                  $ticket_type_repo  Ticket type repository.
	 * @param \NetterTechEvents\Contracts\OccurrenceRepositoryInterface|null $occurrence_repo   Occurrence repository.
	 * @param \NetterTechEvents\Integrations\WooCommerce\ProductManager|null $product_manager   Product manager.
	 * @param CapacityServiceInterface|null                                  $capacity_service  Capacity service.
	 */
	public function __construct(
		string $nonce_action,
		TicketTypeRepositoryInterface $ticket_type_repo,
		?\NetterTechEvents\Contracts\OccurrenceRepositoryInterface $occurrence_repo = null,
		?\NetterTechEvents\Integrations\WooCommerce\ProductManager $product_manager = null,
		?CapacityServiceInterface $capacity_service = null
	) {
		$this->nonce_action     = $nonce_action;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->occurrence_repo  = $occurrence_repo;
		$this->product_manager  = $product_manager;
		$this->capacity_service = $capacity_service;
	}

	/**
	 * Handle ticket types form submission.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $occurrence_id Occurrence ID (optional).
	 * @return void
	 */
	public function handle( int $event_id, int $occurrence_id = 0 ): void {
		// Check capabilities first (before nonce — prevents timing side-channel).
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST[ $this->nonce_action ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $this->nonce_action ] ) ), $this->nonce_action ) ) {
			return;
		}

		$ticket_type_repo = $this->ticket_type_repo;

		// Reject malformed submissions where 'ticket_types' is present but is
		// not an array (e.g. scalar). Missing-altogether is OK — listeners on
		// the post-save hook may run cleanup even with no submitted types.
		if ( isset( $_POST['ticket_types'] ) && ! is_array( $_POST['ticket_types'] ) ) {
			return;
		}

		// Boundary sanitization satisfies Plugin Check's ValidatedSanitizedInput;
		// per-field type tightening continues in create_ticket_type_from_data().
		$ticket_types_data = isset( $_POST['ticket_types'] )
			? map_deep( wp_unslash( $_POST['ticket_types'] ), 'sanitize_textarea_field' )
			: array();

		$submitted_ids = array();
		$saved_ids     = array();

		// Process each scope.
		foreach ( $ticket_types_data as $scope => $types ) {
			if ( ! is_array( $types ) ) {
				continue;
			}

			foreach ( $types as $index => $data ) {
				$ticket_type = $this->create_ticket_type_from_data( $data, $scope, $event_id, $occurrence_id );
				if ( null === $ticket_type ) {
					continue;
				}

				// An id in the payload means this row is an edit, not a new tier.
				if ( ! empty( $data['id'] ) ) {
					$ticket_type->id = absint( $data['id'] );
				}

				try {
					$ticket_type_repo->save( $ticket_type );

					// Recorded after the save, not before: a tier created in this request is
					// given its id here and nowhere else. Collecting ids up front — as this
					// once did — meant a brand-new tier was absent from the payload of the
					// hook below, and an extension had no way to learn what it had been
					// called. Keyed by row so a listener can tell which posted row became
					// which tier.
					if ( null !== $ticket_type->id ) {
						$submitted_ids[]               = $ticket_type->id;
						$saved_ids[ $scope ][ $index ] = $ticket_type->id;
					}

					// Save buffer stock if capacity service is available.
					if ( null !== $this->capacity_service && $ticket_type->id ) {
						$this->save_buffer_stock( $ticket_type, $data );
					}

					// Create/update WooCommerce product.
					if ( null !== $this->product_manager && null !== $this->occurrence_repo && $ticket_type->price > 0 && $occurrence_id > 0 ) {
						$occurrence = $this->occurrence_repo->find( $occurrence_id );
						if ( null !== $occurrence ) {
							// Admin-supplied SKU (NTE-114): honored only while the
							// product's SKU is still empty; the lock is enforced in
							// ProductManager::sync_product().
							$admin_sku = isset( $data['sku'] ) ? sanitize_text_field( (string) $data['sku'] ) : null;
							$this->product_manager->sync_product( $ticket_type, $occurrence, $admin_sku );
						}
					}
				} catch ( \RuntimeException $e ) {
					// Log error but continue.
					DebugLogger::exception( $e, 'TicketSaveHandler' );
				}
			}
		}

		/**
		 * Fires after ticket types are saved.
		 *
		 * @since 1.0.2
		 * @since 1.1.0 $submitted_ids gained the ids of tiers created in this request, which it
		 *              had previously omitted. $saved_ids added.
		 *
		 * @param int                            $event_id      Event ID.
		 * @param int                            $occurrence_id Occurrence ID.
		 * @param int[]                          $submitted_ids Ids of every tier saved, new ones included.
		 * @param array<string, array<int|string, int>> $saved_ids Scope, then posted row index, to tier id.
		 *                                                         The correlation an extension needs to attach
		 *                                                         something to a tier the operator has only
		 *                                                         just created.
		 */
		do_action( 'nettertech_events_ticket_types_saved', $event_id, $occurrence_id, $submitted_ids, $saved_ids );
	}

	/**
	 * Create a TicketType model from form data.
	 *
	 * @param array<string,mixed> $data          Form data for ticket type.
	 * @param string              $scope         Ticket scope.
	 * @param int                 $event_id      Event ID.
	 * @param int                 $occurrence_id Occurrence ID.
	 * @return TicketType|null Ticket type or null if invalid.
	 */
	private function create_ticket_type_from_data( array $data, string $scope, int $event_id, int $occurrence_id ): ?TicketType {
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( empty( $name ) ) {
			return null;
		}

		// Get default min/max from settings.
		$dto         = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
		$default_min = $dto->tickets->default_min_per_order;
		$default_max = $dto->tickets->default_max_per_order;

		$ticket_type                = new TicketType();
		$ticket_type->scope         = $scope;
		$ticket_type->event_id      = $event_id;
		$ticket_type->name          = $name;
		$ticket_type->price         = (float) ( $data['price'] ?? 0 );
		$ticket_type->capacity_type = sanitize_text_field( $data['capacity_type'] ?? 'fixed' );
		$ticket_type->capacity      = ! empty( $data['capacity'] ) ? absint( $data['capacity'] ) : null;
		$ticket_type->min_per_order = absint( $data['min_per_order'] ?? $default_min );
		$ticket_type->max_per_order = absint( $data['max_per_order'] ?? $default_max );
		$ticket_type->status        = 'active';
		$ticket_type->description   = sanitize_textarea_field( wp_unslash( $data['description'] ?? '' ) );

		// Validate capacity_type is valid for scope.
		if ( ! in_array( $ticket_type->capacity_type, CapacityType::values(), true ) ) {
			$ticket_type->capacity_type = 'fixed';
		}
		// SHARED is only valid for OCCURRENCE scope.
		if ( CapacityType::SHARED->value === $ticket_type->capacity_type
			&& TicketTypeScope::OCCURRENCE->value !== $scope ) {
			$ticket_type->capacity_type = 'fixed';
		}

		// Handle scope-specific fields.
		if ( TicketTypeScope::OCCURRENCE->value === $scope ) {
			$ticket_type->occurrence_id = $occurrence_id;
		}

		// Sale dates. The form submits split date + time parts (NTE-190); they
		// recombine here to the exact wire format the old datetime-local input
		// produced, so downstream storage semantics are unchanged. The combined
		// keys are still honored for backwards compatibility (REST, extensions).
		$sale_start = \NetterTechEvents\Services\SaleWindowInput::compose( $data, 'sale_start', \NetterTechEvents\Services\SaleWindowInput::DEFAULT_START_TIME );
		if ( '' !== $sale_start ) {
			$ticket_type->sale_start = $sale_start;
		}
		$sale_end = \NetterTechEvents\Services\SaleWindowInput::compose( $data, 'sale_end', \NetterTechEvents\Services\SaleWindowInput::DEFAULT_END_TIME );
		if ( '' !== $sale_end ) {
			$ticket_type->sale_end = $sale_end;
		}

		return $ticket_type;
	}


	/**
	 * Save buffer stock for a ticket type.
	 *
	 * Validates that buffer stock does not exceed capacity.
	 *
	 * @param TicketType          $ticket_type Saved ticket type with ID populated.
	 * @param array<string,mixed> $data        Form data containing buffer_stock.
	 * @return void
	 */
	private function save_buffer_stock( TicketType $ticket_type, array $data ): void {
		$buffer = absint( $data['buffer_stock'] ?? 0 );

		// Buffer must be less than capacity (if capacity is set).
		if ( null !== $ticket_type->capacity && $buffer >= $ticket_type->capacity ) {
			$buffer = max( 0, $ticket_type->capacity - 1 );
		}

		if ( null !== $this->capacity_service && null !== $ticket_type->id ) {
			$this->capacity_service->set_buffer_stock( $ticket_type->id, $buffer );
		}
	}
}
