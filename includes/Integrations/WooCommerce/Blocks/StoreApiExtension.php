<?php
/**
 * Store API Extension for WooCommerce Blocks.
 *
 * Extends the Store API cart item responses with event ticket metadata
 * (date, time, venue, ticket type) so Block-based cart and checkout
 * can render event details.
 *
 * @package NetterTechEvents\Integrations\WooCommerce\Blocks
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce\Blocks;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Services\AttendeeFieldService;

/**
 * Registers Store API endpoint data for cart items containing event tickets.
 *
 * @since 1.1.0
 */
class StoreApiExtension {

	/**
	 * Namespace for the extension data in API responses.
	 */
	public const NAMESPACE = 'nettertech-events';

	/**
	 * Public attendee field keys exposed through the Store API.
	 *
	 * Store API responses are visible to cart clients. Keep this list explicit
	 * so internal fields such as validation rules, descriptions, timestamps, and
	 * database IDs cannot leak by accidental model serialization.
	 *
	 * @var array<string>
	 */
	private const PUBLIC_ATTENDEE_FIELD_KEYS = array(
		'field_key',
		'field_type',
		'label',
		'placeholder',
		'is_required',
		'options',
	);

	/**
	 * Product manager.
	 *
	 * @var ProductManager
	 */
	private ProductManager $product_manager;

	/**
	 * Attendee field service.
	 *
	 * @var AttendeeFieldService|null
	 */
	private ?AttendeeFieldService $field_service;

	/**
	 * Constructor.
	 *
	 * @param ProductManager            $product_manager Product manager.
	 * @param AttendeeFieldService|null $field_service   Attendee field service.
	 */
	public function __construct( ProductManager $product_manager, ?AttendeeFieldService $field_service = null ) {
		$this->product_manager = $product_manager;
		$this->field_service   = $field_service;
	}

	/**
	 * Register the endpoint data extension.
	 *
	 * Must be called within the `woocommerce_blocks_loaded` action.
	 *
	 * @return void
	 */
	public function register(): void {
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartItemSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'get_cart_item_data' ),
				'schema_callback' => array( $this, 'get_cart_item_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Get event ticket data for a cart item.
	 *
	 * Returns empty array for non-ticket products.
	 *
	 * @param array<string, mixed> $cart_item Cart item data from WC_Cart.
	 * @return array<string, mixed> Extension data.
	 */
	public function get_cart_item_data( array $cart_item ): array {
		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof \WC_Product || ! $this->product_manager->is_event_ticket( $product ) ) {
			return array(
				'is_event_ticket'              => false,
				'event_date'                   => '',
				'event_time'                   => '',
				'venue_name'                   => '',
				'ticket_type'                  => '',
				'event_permalink'              => '',
				'event_title'                  => '',
				'event_id'                     => 0,
				'attendee_fields'              => array(),
				'collect_individual_attendees' => false,
			);
		}

		$occurrence  = $this->product_manager->get_occurrence_from_product( $product );
		$ticket_type = $this->product_manager->get_ticket_type_from_product( $product );

		$data = array(
			'is_event_ticket'              => true,
			'event_date'                   => '',
			'event_time'                   => '',
			'venue_name'                   => '',
			'ticket_type'                  => '',
			'event_permalink'              => '',
			'event_title'                  => '',
			'event_id'                     => 0,
			'attendee_fields'              => array(),
			'collect_individual_attendees' => false,
		);

		if ( $occurrence ) {
			$data['event_date'] = $occurrence->get_formatted_date();
			$data['event_time'] = $occurrence->get_formatted_time();

			$event = $occurrence->get_event();
			if ( $event ) {
				$data['event_permalink'] = $event->get_permalink();
				$data['event_title']     = $event->title ?? '';

				if ( $event->venue_name ) {
					$data['venue_name'] = $event->venue_name;
				}
			}
		}

		if ( $ticket_type ) {
			$data['ticket_type'] = $ticket_type->name;

			if ( $ticket_type->event_id ) {
				$data['event_id'] = (int) $ticket_type->event_id;

				// Include custom attendee field definitions for Block checkout.
				if ( null !== $this->field_service ) {
					$fields_map                  = $this->field_service->get_fields_for_events( array( (int) $ticket_type->event_id ) );
					$event_fields                = $fields_map[ (int) $ticket_type->event_id ] ?? array();
						$data['attendee_fields'] = array_map( array( $this, 'prepare_public_attendee_field' ), $event_fields );
				}
			}
		}

		// Check collect_individual_attendees on the event.
		if ( $occurrence ) {
			$event = $occurrence->get_event();
			if ( $event && ! empty( $event->collect_individual_attendees ) ) {
				$data['collect_individual_attendees'] = true;
			}
		}

		return $data;
	}

	/**
	 * Get the JSON schema for the cart item extension data.
	 *
	 * @return array<string, array<string, mixed>> Schema definition.
	 */
	public function get_cart_item_schema(): array {
		return array(
			'is_event_ticket'              => array(
				'description' => __( 'Whether this cart item is an event ticket.', 'nettertech-events' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'event_date'                   => array(
				'description' => __( 'Formatted event date.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'event_time'                   => array(
				'description' => __( 'Formatted event time.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'venue_name'                   => array(
				'description' => __( 'Venue name.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'ticket_type'                  => array(
				'description' => __( 'Ticket type name.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'event_permalink'              => array(
				'description' => __( 'URL to the event page.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'event_title'                  => array(
				'description' => __( 'Event title.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'event_id'                     => array(
				'description' => __( 'Event ID.', 'nettertech-events' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'attendee_fields'              => array(
				'description' => __( 'Custom attendee registration fields for this event.', 'nettertech-events' ),
				'type'        => 'array',
				'readonly'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => $this->get_public_attendee_field_schema(),
				),
			),
			'collect_individual_attendees' => array(
				'description' => __( 'Whether to collect per-attendee details when qty > 1.', 'nettertech-events' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
		);
	}

	/**
	 * Prepare an attendee field for public Store API output.
	 *
	 * @param \NetterTechEvents\Models\AttendeeField $field Attendee field definition.
	 * @return array<string, mixed> Public field schema only.
	 */
	private function prepare_public_attendee_field( \NetterTechEvents\Models\AttendeeField $field ): array {
		$public_field = array(
			'field_key'   => $field->field_key,
			'field_type'  => $field->field_type,
			'label'       => $field->label,
			'placeholder' => $field->placeholder ?? '',
			'is_required' => $field->is_required,
			'options'     => $field->get_options(),
		);

		return array_intersect_key( $public_field, array_flip( self::PUBLIC_ATTENDEE_FIELD_KEYS ) );
	}

	/**
	 * Get the public attendee field schema exposed to cart clients.
	 *
	 * @return array<string, array<string, mixed>> Schema properties.
	 */
	private function get_public_attendee_field_schema(): array {
		return array(
			'field_key'   => array(
				'description' => __( 'Stable attendee field key.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'field_type'  => array(
				'description' => __( 'Public field type used to render the checkout control.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'label'       => array(
				'description' => __( 'Public field label.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'placeholder' => array(
				'description' => __( 'Public field placeholder.', 'nettertech-events' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'is_required' => array(
				'description' => __( 'Whether the field is required at checkout.', 'nettertech-events' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'options'     => array(
				'description' => __( 'Public choices for select, radio, or checkbox controls.', 'nettertech-events' ),
				'type'        => 'array',
				'readonly'    => true,
			),
		);
	}
}
