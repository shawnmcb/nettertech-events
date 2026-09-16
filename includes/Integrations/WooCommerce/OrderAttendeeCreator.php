<?php
/**
 * WooCommerce Order Attendee Creator.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Frontend\AccessibilityNotesField;
use NetterTechEvents\Contracts\AttendeeOrderInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Enums\AttendeeStatus;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Contracts\TicketCodeGeneratorInterface;
use NetterTechEvents\Services\AttendeeFieldService;

/**
 * Handles attendee and ticket creation from WooCommerce orders.
 *
 * Extracted from OrderHandler to reduce class complexity.
 * Creates attendee records for occurrence-scoped tickets and series passes.
 *
 * @since 1.1.0
 */
class OrderAttendeeCreator {

	/**
	 * Product manager.
	 *
	 * @var ProductManager
	 */
	private readonly ProductManager $product_manager;

	/**
	 * Attendee repository for core CRUD operations.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private readonly AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Attendee order operations.
	 *
	 * @var AttendeeOrderInterface
	 */
	private readonly AttendeeOrderInterface $attendee_order;

	/**
	 * Ticket repository.
	 *
	 * @var TicketRepositoryInterface
	 */
	private readonly TicketRepositoryInterface $ticket_repo;

	/**
	 * Ticket code generator.
	 *
	 * @var TicketCodeGeneratorInterface
	 */
	private readonly TicketCodeGeneratorInterface $code_generator;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private readonly OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Attendee field service for custom registration fields.
	 *
	 * @var AttendeeFieldService|null
	 */
	private readonly ?AttendeeFieldService $field_service;

	/**
	 * Custom field data for the current order being processed.
	 *
	 * Set during process() and read during create_attendee_for_occurrence().
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $current_custom_field_data = array();

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param ProductManager                $product_manager Product manager.
	 * @param AttendeeRepositoryInterface   $attendee_repo   Attendee repository.
	 * @param AttendeeOrderInterface        $attendee_order  Attendee order operations.
	 * @param TicketRepositoryInterface     $ticket_repo     Ticket repository.
	 * @param TicketCodeGeneratorInterface  $code_generator  Ticket code generator.
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param AttendeeFieldService|null     $field_service   Attendee field service.
	 */
	public function __construct(
		ProductManager $product_manager,
		AttendeeRepositoryInterface $attendee_repo,
		AttendeeOrderInterface $attendee_order,
		TicketRepositoryInterface $ticket_repo,
		TicketCodeGeneratorInterface $code_generator,
		OccurrenceRepositoryInterface $occurrence_repo,
		?AttendeeFieldService $field_service = null,
	) {
		$this->product_manager = $product_manager;
		$this->attendee_repo   = $attendee_repo;
		$this->attendee_order  = $attendee_order;
		$this->ticket_repo     = $ticket_repo;
		$this->code_generator  = $code_generator;
		$this->occurrence_repo = $occurrence_repo;
		$this->field_service   = $field_service;
	}

	/**
	 * Process an order to create attendee records.
	 *
	 * Handles both occurrence-scoped tickets and series passes.
	 * Series passes create attendees for all occurrences in the event.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return array<int> Ticket type IDs that were processed (for stock sync).
	 */
	public function process( \WC_Order $order ): array {
		// Prepare order context for attendee creation.
		$context = $this->build_order_context( $order );

		// Store custom field data for consumption in create_attendee_for_occurrence().
		$this->current_custom_field_data = $context['custom_field_data'];

		// Track ticket types to sync stock at end.
		$ticket_types_to_sync = array();

		// Process each ticket line item.
		foreach ( $this->get_ticket_items( $order ) as $item ) {
			$ticket_type_id = (int) $item->get_meta( MetaKeys::TICKET_TYPE_ID );
			$is_series_pass = 'yes' === $item->get_meta( MetaKeys::IS_SERIES_PASS );

			if ( $is_series_pass ) {
				$this->process_series_pass_item( $item, $ticket_type_id, $context );
			} else {
				$this->process_occurrence_item( $item, $ticket_type_id, $context );
			}

			// Track ticket type.
			if ( $ticket_type_id ) {
				$ticket_types_to_sync[ $ticket_type_id ] = $item->get_quantity();
			}
		}

		return $ticket_types_to_sync;
	}

	/**
	 * Build order context for attendee creation.
	 *
	 * Extracts billing details and pre-fetches existing attendees.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return array{order: \WC_Order, order_id: int, billing_name: string, billing_email: string, billing_phone: string|null, accessibility_notes: string|null, notes: string|null, custom_field_data: array<int, array<string, string>>, existing_occurrence_ids: array<int, bool>}
	 */
	private function build_order_context( \WC_Order $order ): array {
		$order_id = $order->get_id();

		// Get billing details for attendee info.
		$billing_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$billing_email = $order->get_billing_email();
		$billing_phone = $order->get_billing_phone();

		// Get accessibility notes from order meta (HPOS-compatible).
		$accessibility_notes = $order->get_meta( MetaKeys::ACCESSIBILITY_NOTES, true );

		// The buyer's checkout "Order notes" become each attendee's plain notes (NTE-226).
		$notes = self::sanitize_customer_note( $order->get_customer_note() );

		// Get custom field data from order meta (Phase 9).
		$custom_field_json = $order->get_meta( MetaKeys::CUSTOM_FIELD_DATA, true );
		$custom_field_data = ! empty( $custom_field_json ) ? json_decode( (string) $custom_field_json, true ) : array();

		// Pre-fetch existing attendees for this order (single query).
		$existing_attendees      = $this->attendee_order->find_all_by_order( $order_id );
		$existing_occurrence_ids = array();
		foreach ( $existing_attendees as $existing ) {
			$existing_occurrence_ids[ $existing->occurrence_id ] = true;
		}

		return array(
			'order'                   => $order,
			'order_id'                => $order_id,
			'billing_name'            => $billing_name,
			'billing_email'           => $billing_email,
			'billing_phone'           => $billing_phone ? $billing_phone : null,
			'accessibility_notes'     => $accessibility_notes ? $accessibility_notes : null,
			'notes'                   => $notes,
			'custom_field_data'       => is_array( $custom_field_data ) ? $custom_field_data : array(),
			'existing_occurrence_ids' => $existing_occurrence_ids,
		);
	}

	/**
	 * Process a series pass line item.
	 *
	 * Creates attendees for all occurrences in the event.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order_Item_Product                                                                                                                                                                                                                                                 $item            Order item.
	 * @param int                                                                                                                                                                                                                                                                    $ticket_type_id  Ticket type ID.
	 * @param array{order: \WC_Order, order_id: int, billing_name: string, billing_email: string, billing_phone: string|null, accessibility_notes: string|null, notes: string|null, custom_field_data: array<int, array<string, string>>, existing_occurrence_ids: array<int, bool>} $context         Order context.
	 * @return void
	 */
	private function process_series_pass_item(
		\WC_Order_Item_Product $item,
		int $ticket_type_id,
		array $context
	): void {
		$event_id = (int) $item->get_meta( MetaKeys::EVENT_ID );

		if ( ! $event_id ) {
			return;
		}

		$occurrences = $this->occurrence_repo->for_event( $event_id );

		foreach ( $occurrences as $occurrence ) {
			$occurrence_id = $occurrence->id;

			// Skip unsaved occurrences and those that already have an attendee.
			if ( null === $occurrence_id || isset( $context['existing_occurrence_ids'][ $occurrence_id ] ) ) {
				continue;
			}

			$this->create_attendee_for_occurrence(
				$occurrence_id,
				$ticket_type_id,
				$context['order_id'],
				$item,
				$context['billing_name'],
				$context['billing_email'],
				$context['billing_phone'],
				$context['accessibility_notes'],
				$context['order'],
				true, // is_series_pass.
				$context['notes']
			);
		}
	}

	/**
	 * Process an occurrence-scoped ticket line item.
	 *
	 * Creates an attendee for the specific occurrence.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order_Item_Product                                                                                                                                                                                                                                                 $item            Order item.
	 * @param int                                                                                                                                                                                                                                                                    $ticket_type_id  Ticket type ID.
	 * @param array{order: \WC_Order, order_id: int, billing_name: string, billing_email: string, billing_phone: string|null, accessibility_notes: string|null, notes: string|null, custom_field_data: array<int, array<string, string>>, existing_occurrence_ids: array<int, bool>} $context         Order context.
	 * @return void
	 */
	private function process_occurrence_item(
		\WC_Order_Item_Product $item,
		int $ticket_type_id,
		array $context
	): void {
		$occurrence_id = (int) $item->get_meta( MetaKeys::OCCURRENCE_ID );

		if ( ! $occurrence_id ) {
			return;
		}

		// Skip if attendee already exists for this occurrence.
		if ( isset( $context['existing_occurrence_ids'][ $occurrence_id ] ) ) {
			return;
		}

		// Check for per-attendee data (Phase 9: collect_individual_attendees).
		$attendee_data_json = $item->get_meta( MetaKeys::ATTENDEE_DATA );
		$attendee_data      = ! empty( $attendee_data_json ) ? json_decode( (string) $attendee_data_json, true ) : null;

		if ( is_array( $attendee_data ) && ! empty( $attendee_data ) ) {
			$this->create_individual_attendees(
				$occurrence_id,
				$ticket_type_id,
				$context,
				$item,
				$attendee_data
			);
			return;
		}

		// Legacy mode: single attendee with billing info.
		$this->create_attendee_for_occurrence(
			$occurrence_id,
			$ticket_type_id,
			$context['order_id'],
			$item,
			$context['billing_name'],
			$context['billing_email'],
			$context['billing_phone'],
			$context['accessibility_notes'],
			$context['order'],
			false,
			$context['notes']
		);
	}

	/**
	 * Create individual attendee records from per-attendee checkout data.
	 *
	 * Each entry in $attendee_data becomes a separate Attendee record with qty=1
	 * and its own Ticket with a unique QR code.
	 *
	 * @since 3.6.0
	 *
	 * @param int                                                                                                                                                                                                                                                                    $occurrence_id  Occurrence ID.
	 * @param int                                                                                                                                                                                                                                                                    $ticket_type_id Ticket type ID.
	 * @param array{order: \WC_Order, order_id: int, billing_name: string, billing_email: string, billing_phone: string|null, accessibility_notes: string|null, notes: string|null, custom_field_data: array<int, array<string, string>>, existing_occurrence_ids: array<int, bool>} $context        Order context.
	 * @param \WC_Order_Item_Product                                                                                                                                                                                                                                                 $item           Order item.
	 * @param array<int, array{name: string, email: string, phone?: string, custom_fields?: array<string, string>}>                                                                                                                                                                  $attendee_data  Per-attendee data array.
	 * @return void
	 */
	private function create_individual_attendees(
		int $occurrence_id,
		int $ticket_type_id,
		array $context,
		\WC_Order_Item_Product $item,
		array $attendee_data
	): void {
		$order_id           = $context['order_id'];
		$order              = $context['order'];
		$price_per_ticket   = $item->get_quantity() > 0
			? (float) $item->get_total() / $item->get_quantity()
			: 0.0;
		$created_ticket_ids = array();

		foreach ( $attendee_data as $person ) {
			$attendee                      = new Attendee();
			$attendee->occurrence_id       = $occurrence_id;
			$attendee->ticket_type_id      = $ticket_type_id ? $ticket_type_id : null;
			$attendee->wc_order_id         = $order_id;
			$attendee->name                = $person['name'] ?? '';
			$attendee->email               = $person['email'] ?? '';
			$attendee->phone               = ! empty( $person['phone'] ) ? $person['phone'] : null;
			$attendee->quantity            = 1;
			$attendee->status              = AttendeeStatus::CONFIRMED->value;
			$attendee->accessibility_notes = $context['accessibility_notes'];
			$attendee->notes               = $context['notes'];

			try {
				$this->attendee_repo->save( $attendee );

				// Create one ticket per individual attendee.
				$ticket                   = new Ticket();
				$ticket->ticket_type_id   = $ticket_type_id ? $ticket_type_id : 0;
				$ticket->occurrence_id    = $occurrence_id;
				$ticket->attendee_id      = $attendee->id;
				$ticket->wc_order_id      = $order_id;
				$ticket->wc_order_item_id = $item->get_id();
				$ticket->ticket_code      = $this->code_generator->generate();
				$ticket->status           = 'confirmed';
				$ticket->price_paid       = $price_per_ticket;

				$this->ticket_repo->save( $ticket );
				$created_ticket_ids[] = $ticket->id;

				// Save per-attendee custom field values.
				if ( null !== $this->field_service && null !== $attendee->id && ! empty( $person['custom_fields'] ) ) {
					$cf_event_id = (int) $item->get_meta( MetaKeys::EVENT_ID );
					if ( ! $cf_event_id ) {
						$cf_occurrence = $this->occurrence_repo->find( $occurrence_id );
						$cf_event_id   = $cf_occurrence ? (int) $cf_occurrence->event_id : 0;
					}

					if ( $cf_event_id ) {
						$this->field_service->save_field_values(
							$attendee->id,
							$cf_event_id,
							$person['custom_fields']
						);
					}
				}

				do_action( 'nettertech_events_attendee_created', $attendee, $order, $item );

			} catch ( \RuntimeException $e ) {
				do_action( 'nettertech_events_attendee_creation_failed', $e, $order_id, $item );
			}
		}

		// Store all ticket IDs on the order item (for seating plugin).
		if ( ! empty( $created_ticket_ids ) ) {
			$item->add_meta_data( '_nettertech_events_ticket_ids', $created_ticket_ids, true );
			$item->save_meta_data();
		}
	}

	/**
	 * Yield ticket line items from an order.
	 *
	 * Filters order items to only return valid event ticket products.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return \Generator<\WC_Order_Item_Product>
	 */
	private function get_ticket_items( \WC_Order $order ): \Generator {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( ! $this->product_manager->is_event_ticket( $product_id ) ) {
				continue;
			}

			yield $item;
		}
	}

	/**
	 * Create an attendee record for an occurrence.
	 *
	 * @since 1.1.0
	 *
	 * @param int                    $occurrence_id       Occurrence ID.
	 * @param int                    $ticket_type_id      Ticket type ID.
	 * @param int                    $order_id            WooCommerce order ID.
	 * @param \WC_Order_Item_Product $item                Order item.
	 * @param string                 $billing_name        Customer name.
	 * @param string                 $billing_email       Customer email.
	 * @param string|null            $billing_phone       Customer phone.
	 * @param string|null            $accessibility_notes Accessibility notes.
	 * @param \WC_Order              $order               Order object.
	 * @param bool                   $is_series_pass      Whether this is from a series pass.
	 * @param string|null            $notes               Buyer's order notes (NTE-226).
	 * @return void
	 */
	private function create_attendee_for_occurrence(
		int $occurrence_id,
		int $ticket_type_id,
		int $order_id,
		\WC_Order_Item_Product $item,
		string $billing_name,
		string $billing_email,
		?string $billing_phone,
		?string $accessibility_notes,
		\WC_Order $order,
		bool $is_series_pass,
		?string $notes = null
	): void {
		$attendee                      = new Attendee();
		$attendee->occurrence_id       = $occurrence_id;
		$attendee->ticket_type_id      = $ticket_type_id ? $ticket_type_id : null;
		$attendee->wc_order_id         = $order_id;
		$attendee->name                = $billing_name;
		$attendee->email               = $billing_email;
		$attendee->phone               = $billing_phone ? $billing_phone : null;
		$attendee->quantity            = $item->get_quantity();
		$attendee->status              = AttendeeStatus::CONFIRMED->value;
		$attendee->accessibility_notes = $accessibility_notes ? $accessibility_notes : null;
		$attendee->notes               = $notes;

		try {
			$this->attendee_repo->save( $attendee );

			// Create individual ticket records for each unit purchased.
			$price_per_ticket = $item->get_quantity() > 0
				? (float) $item->get_total() / $item->get_quantity()
				: 0.0;

			// For series passes, split the price evenly across all occurrences.
			if ( $is_series_pass ) {
				$event_id          = (int) $item->get_meta( MetaKeys::EVENT_ID );
				$occurrence_count  = count( $this->occurrence_repo->for_event( $event_id ) );
				$price_per_ticket /= max( 1, $occurrence_count );
			}

			$created_ticket_ids = array();

			for ( $i = 0; $i < $attendee->quantity; $i++ ) {
				$ticket                   = new Ticket();
				$ticket->ticket_type_id   = $ticket_type_id ? $ticket_type_id : 0;
				$ticket->occurrence_id    = $occurrence_id;
				$ticket->attendee_id      = $attendee->id;
				$ticket->wc_order_id      = $order_id;
				$ticket->wc_order_item_id = $item->get_id();
				$ticket->ticket_code      = $this->code_generator->generate();
				$ticket->status           = 'confirmed';
				$ticket->price_paid       = $price_per_ticket;

				$this->ticket_repo->save( $ticket );
				$created_ticket_ids[] = $ticket->id;
			}

			// Store ticket IDs on the order item for seating plugin consumption.
			$item->add_meta_data( '_nettertech_events_ticket_ids', $created_ticket_ids, true );
			$item->save_meta_data();

			// Save custom field values if field service is available and data exists.
			if ( null !== $this->field_service && null !== $attendee->id && ! empty( $this->current_custom_field_data ) ) {
				$cf_event_id = (int) $item->get_meta( MetaKeys::EVENT_ID );
				if ( ! $cf_event_id ) {
					// Resolve event_id from occurrence.
					$cf_occurrence = $this->occurrence_repo->find( $occurrence_id );
					$cf_event_id   = $cf_occurrence ? (int) $cf_occurrence->event_id : 0;
				}

				if ( $cf_event_id && ! empty( $this->current_custom_field_data[ $cf_event_id ] ) ) {
					$this->field_service->save_field_values(
						$attendee->id,
						$cf_event_id,
						$this->current_custom_field_data[ $cf_event_id ]
					);
				}
			}

			/**
			 * Fires when an attendee is created from a WooCommerce order.
			 */
			do_action( 'nettertech_events_attendee_created', $attendee, $order, $item );

		} catch ( \RuntimeException $e ) {
			do_action( 'nettertech_events_attendee_creation_failed', $e, $order_id, $item );
		}
	}

	/**
	 * Normalize the buyer's checkout "Order notes" for storage on attendees.
	 *
	 * Plain text, trimmed, capped at the same length the accessibility field
	 * uses; empty becomes null so the column stays a clean "no note" signal.
	 *
	 * @since 1.4.7
	 *
	 * @param mixed $raw Raw customer note.
	 * @return string|null
	 */
	public static function sanitize_customer_note( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		// sanitize_textarea_field() already trims the ends.
		$clean = sanitize_textarea_field( $raw );
		if ( '' === $clean ) {
			return null;
		}

		if ( mb_strlen( $clean ) > AccessibilityNotesField::MAX_LENGTH ) {
			$clean = rtrim( mb_substr( $clean, 0, AccessibilityNotesField::MAX_LENGTH ) );
		}

		return $clean;
	}
}
