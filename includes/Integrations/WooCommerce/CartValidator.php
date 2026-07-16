<?php
/**
 * Cart Validator.
 *
 * Validates ticket purchases against business rules and queries cart contents.
 * Extracted from CartHandler via Extract Class + Delegation (ADR-007).
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;

/**
 * Validates ticket purchases against business rules and queries cart contents.
 */
class CartValidator {

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
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 * @param CapacityServiceInterface      $capacity_service Capacity service.
	 * @param OccurrenceRepositoryInterface $occurrence_repo  Occurrence repository.
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service,
		OccurrenceRepositoryInterface $occurrence_repo
	) {
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;
		$this->occurrence_repo  = $occurrence_repo;
	}

	/**
	 * Validate ticket purchase without side effects.
	 *
	 * This method performs all validation checks and returns a result array
	 * instead of calling wc_add_notice(). This enables unit testing with
	 * PHPUnit mocks without requiring full WooCommerce.
	 *
	 * When called from validate_add_to_cart(), $user_pending provides the
	 * session-aware pending reservation count for accurate net capacity checks.
	 * When called standalone (e.g., validate_batch), $user_pending defaults to
	 * $cart_quantity for backward-compatible behavior.
	 *
	 * @param TicketType $ticket_type    Ticket type being purchased.
	 * @param Occurrence $occurrence     Event occurrence.
	 * @param int        $quantity       Quantity being added.
	 * @param int        $cart_quantity  Quantity already in cart for this ticket type.
	 * @param int|null   $user_pending   User's existing pending reservation count, or null
	 *                                   to fall back to $cart_quantity for net calculation.
	 * @return array{valid: bool, error: string|null, error_code: string|null}
	 */
	public function validate_ticket_purchase(
		TicketType $ticket_type,
		Occurrence $occurrence,
		int $quantity,
		int $cart_quantity = 0,
		?int $user_pending = null
	): array {
		$total_quantity = $cart_quantity + $quantity;

		// Use user_pending for net capacity check when provided (session-aware),
		// otherwise fall back to cart_quantity (standalone/batch usage).
		$reserved_quantity = $user_pending ?? $cart_quantity;

		// Check if event has passed.
		if ( $occurrence->has_ended() ) {
			return array(
				'valid'      => false,
				'error'      => __( 'This event has already ended.', 'nettertech-events' ),
				'error_code' => 'event_ended',
			);
		}

		// Check if ticket type is on sale, in the event's timezone rather than the site's.
		if ( ! $ticket_type->is_on_sale( $occurrence->get_timezone() ) ) {
			return array(
				'valid'      => false,
				'error'      => __( 'Tickets for this event are not currently on sale.', 'nettertech-events' ),
				'error_code' => 'not_on_sale',
			);
		}

		// Check minimum per order.
		if ( $total_quantity < $ticket_type->min_per_order ) {
			return array(
				'valid'      => false,
				'error'      => sprintf(
					/* translators: %d: minimum quantity */
					__( 'You must purchase at least %d tickets.', 'nettertech-events' ),
					$ticket_type->min_per_order
				),
				'error_code' => 'below_minimum',
			);
		}

		// Check maximum per order.
		if ( $total_quantity > $ticket_type->max_per_order ) {
			return array(
				'valid'      => false,
				'error'      => sprintf(
					/* translators: %d: maximum quantity */
					__( 'You can purchase a maximum of %d tickets per order.', 'nettertech-events' ),
					$ticket_type->max_per_order
				),
				'error_code' => 'above_maximum',
			);
		}

		// Check capacity availability.
		// Net quantity excludes what the user already has reserved.
		$net_quantity   = $total_quantity - $reserved_quantity;
		$ticket_type_id = $ticket_type->id;

		if ( $net_quantity > 0 && null !== $ticket_type_id && ! $this->capacity_service->has_availability( $ticket_type_id, $net_quantity, true ) ) {
			$summary = $this->capacity_service->get_capacity_summary( $ticket_type_id );

			if ( $summary['is_sold_out'] && 0 === $reserved_quantity ) {
				return array(
					'valid'      => false,
					'error'      => __( 'Sorry, this ticket type is sold out.', 'nettertech-events' ),
					'error_code' => 'sold_out',
				);
			}

			// Show true max: what is actually left + what the shopper already holds.
			// This must come from the same figure the gate above refused on, or the
			// message contradicts the refusal — quoting 0 seats to someone who could
			// still buy one, and losing the sale.
			$available = ( $this->capacity_service->get_available_count( $ticket_type_id ) ?? 0 ) + $reserved_quantity;
			return array(
				'valid'      => false,
				'error'      => sprintf(
					/* translators: %d: available quantity */
					_n(
						'Sorry, only %d ticket is available for this event.',
						'Sorry, only %d tickets are available for this event.',
						$available,
						'nettertech-events'
					),
					$available
				),
				'error_code' => 'insufficient_capacity',
			);
		}

		return array(
			'valid'      => true,
			'error'      => null,
			'error_code' => null,
		);
	}

	/**
	 * Validate multiple ticket types for batch cart addition.
	 *
	 * Validates all tickets in a single pass, checking capacity, on-sale status,
	 * and per-order limits. For tickets sharing capacity (same occurrence),
	 * validates combined quantities don't exceed availability.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $tickets Map of ticket_type_id => quantity.
	 * @return array{valid: bool, errors: array<int, string>, validated: array<int, int>}
	 */
	public function validate_batch( array $tickets ): array {
		$errors    = array();
		$validated = array();

		// Load all ticket types in batch to avoid N+1 queries.
		$ticket_types = array();
		$occurrences  = array();

		foreach ( array_keys( $tickets ) as $ticket_type_id ) {
			$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

			if ( ! $ticket_type ) {
				$errors[ $ticket_type_id ] = __( 'Ticket type not found.', 'nettertech-events' );
				continue;
			}

			if ( ! $ticket_type->wc_product_id ) {
				$errors[ $ticket_type_id ] = __( 'This ticket is not available for purchase.', 'nettertech-events' );
				continue;
			}

			$ticket_types[ $ticket_type_id ] = $ticket_type;

			// Load occurrence if not already loaded.
			$occurrence_id = $ticket_type->occurrence_id;
			if ( $occurrence_id && ! isset( $occurrences[ $occurrence_id ] ) ) {
				$occurrence = $this->occurrence_repo->find( $occurrence_id );
				if ( $occurrence ) {
					$occurrences[ $occurrence_id ] = $occurrence;
				}
			}
		}

		// Validate each ticket type.
		foreach ( $tickets as $ticket_type_id => $quantity ) {
			if ( isset( $errors[ $ticket_type_id ] ) ) {
				continue; // Already has an error.
			}

			$ticket_type = $ticket_types[ $ticket_type_id ];

			// Get occurrence (may be null for event-scoped tickets).
			$occurrence = null;
			if ( $ticket_type->occurrence_id ) {
				$occurrence = $occurrences[ $ticket_type->occurrence_id ] ?? null;
			}

			// An event-scoped ticket (series pass) anchors its date checks to the next
			// upcoming occurrence: on sale while any date remains, ended when none does.
			// Capacity is NOT this occurrence's — the calculator bounds a pass by its
			// tightest date's room across the whole event (NTE-156).
			if ( ! $occurrence && $ticket_type->event_id ) {
				$occurrence = $this->occurrence_repo->next_for_event( $ticket_type->event_id );
			}

			if ( ! $occurrence ) {
				$errors[ $ticket_type_id ] = __( 'No valid event date found for this ticket.', 'nettertech-events' );
				continue;
			}

			// Get current cart quantity for this ticket type.
			$cart_quantity = $this->get_cart_quantity_for_ticket_type( $ticket_type_id );

			// Use existing validation method.
			$validation = $this->validate_ticket_purchase(
				$ticket_type,
				$occurrence,
				$quantity,
				$cart_quantity
			);

			if ( ! $validation['valid'] ) {
				$errors[ $ticket_type_id ] = $validation['error'] ?? __( 'Invalid ticket selection.', 'nettertech-events' );
				continue;
			}

			$validated[ $ticket_type_id ] = $quantity;
		}

		return array(
			'valid'     => empty( $errors ),
			'errors'    => $errors,
			'validated' => $validated,
		);
	}

	/**
	 * Get quantity of a ticket type currently in cart.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int
	 */
	public function get_cart_quantity_for_ticket_type( int $ticket_type_id ): int {
		if ( ! WC()->cart ) {
			return 0;
		}

		return $this->get_cart_quantity_for_ticket_type_from_cart( WC()->cart, $ticket_type_id );
	}

	/**
	 * Get quantity of a ticket type from a cart object.
	 *
	 * This method accepts a WC_Cart object directly, enabling unit testing
	 * with PHPUnit mocks without requiring full WooCommerce.
	 *
	 * @param \WC_Cart $cart           Cart object.
	 * @param int      $ticket_type_id Ticket type ID.
	 * @return int
	 */
	public function get_cart_quantity_for_ticket_type_from_cart( \WC_Cart $cart, int $ticket_type_id ): int {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type || ! $ticket_type->wc_product_id ) {
			return 0;
		}

		$quantity = 0;
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( (int) $cart_item['product_id'] === $ticket_type->wc_product_id ) {
				$quantity += (int) $cart_item['quantity'];
			}
		}

		return $quantity;
	}
}
