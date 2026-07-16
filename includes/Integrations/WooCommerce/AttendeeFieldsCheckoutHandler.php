<?php
/**
 * Attendee Fields Checkout Handler.
 *
 * Renders custom registration fields on WooCommerce checkout.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Services\AttendeeFieldService;

/**
 * Handles custom attendee field rendering, validation, and saving on classic WC checkout.
 *
 * Fields are rendered per event, grouped under the event title. Only events
 * with defined custom fields are shown.
 *
 * @since 3.6.0
 */
class AttendeeFieldsCheckoutHandler {

	/**
	 * Cart handler for ticket detection.
	 *
	 * @var CartHandler
	 */
	private CartHandler $cart_handler;

	/**
	 * Product manager for event resolution.
	 *
	 * @var ProductManager
	 */
	private ProductManager $product_manager;

	/**
	 * Attendee field service.
	 *
	 * @var AttendeeFieldService
	 */
	private AttendeeFieldService $field_service;

	/**
	 * Constructor.
	 *
	 * @param CartHandler          $cart_handler    Cart handler.
	 * @param ProductManager       $product_manager Product manager.
	 * @param AttendeeFieldService $field_service   Field service.
	 */
	public function __construct(
		CartHandler $cart_handler,
		ProductManager $product_manager,
		AttendeeFieldService $field_service
	) {
		$this->cart_handler    = $cart_handler;
		$this->product_manager = $product_manager;
		$this->field_service   = $field_service;
	}

	/**
	 * Render custom fields on checkout.
	 *
	 * Only renders if cart contains event tickets with custom fields defined.
	 *
	 * @param \WC_Checkout $checkout Checkout instance.
	 * @return void
	 */
	public function render_fields( $checkout ): void {
		if ( ! $this->cart_handler->cart_has_tickets() ) {
			return;
		}

		$event_ids       = $this->get_event_ids_from_cart();
		$fields_by_event = $this->field_service->get_fields_for_events( $event_ids );

		if ( empty( $fields_by_event ) ) {
			return;
		}

		echo '<div class="nte-custom-fields-checkout">';
		echo '<h3>' . esc_html__( 'Registration Information', 'nettertech-events' ) . '</h3>';

		foreach ( $fields_by_event as $event_id => $fields ) {
			$this->render_event_fields( $event_id, $fields, $checkout );
		}

		echo '</div>';
	}

	/**
	 * Validate custom fields on checkout submission.
	 *
	 * @param array<string, mixed> $data   Checkout data.
	 * @param \WP_Error            $errors Validation errors.
	 * @return void
	 */
	public function validate_fields( array $data, \WP_Error $errors ): void {
		$submitted = $this->read_submitted_fields();
		if ( empty( $submitted ) ) {
			return;
		}

		$event_ids       = $this->get_event_ids_from_cart();
		$fields_by_event = $this->field_service->get_fields_for_events( $event_ids );

		foreach ( $fields_by_event as $event_id => $fields ) {
			// Extract values for this event from flat POST structure.
			$event_values = array();
			foreach ( $fields as $field ) {
				$post_key                          = $event_id . '_' . $field->field_key;
				$event_values[ $field->field_key ] = $submitted[ $post_key ] ?? '';
			}

			$field_errors = $this->field_service->validate_field_values( $event_id, $event_values );
			foreach ( $field_errors as $error ) {
				$errors->add( 'nettertech_events_custom_field', $error );
			}
		}
	}

	/**
	 * Save custom field data to order meta.
	 *
	 * Stores as JSON keyed by event_id for OrderAttendeeCreator consumption.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function save_fields( int $order_id ): void {
		$submitted = $this->read_submitted_fields();
		if ( empty( $submitted ) ) {
			return;
		}

		$event_ids       = $this->get_event_ids_from_cart();
		$fields_by_event = $this->field_service->get_fields_for_events( $event_ids );
		$data_by_event   = array();

		foreach ( $fields_by_event as $event_id => $fields ) {
			$event_values = array();
			foreach ( $fields as $field ) {
				$post_key = $event_id . '_' . $field->field_key;
				$value    = $submitted[ $post_key ] ?? '';
				if ( '' !== $value ) {
					$event_values[ $field->field_key ] = $value;
				}
			}

			if ( ! empty( $event_values ) ) {
				$data_by_event[ $event_id ] = $event_values;
			}
		}

		if ( ! empty( $data_by_event ) ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$encoded = wp_json_encode( $data_by_event );
				if ( is_string( $encoded ) ) {
					$order->update_meta_data( MetaKeys::CUSTOM_FIELD_DATA, $encoded );
					$order->save();
				}
			}
		}
	}

	/**
	 * Read submitted custom-field values from the checkout POST.
	 *
	 * Fields submit as the array `nettertech_events_custom_fields[<eventId>_<fieldKey>]`,
	 * so `WC_Checkout::get_value()` cannot resolve them (it reads scalar
	 * top-level keys). We instead verify WooCommerce's checkout nonce
	 * inline as defense-in-depth (WC has already verified it before
	 * firing the validation / save hooks) so the bulk array read is
	 * gated in the same scope — no PHPCS suppression needed.
	 *
	 * @return array<string, string> Flat map of `<eventId>_<fieldKey>` => sanitized value.
	 */
	private function read_submitted_fields(): array {
		$nonce = isset( $_POST['woocommerce-process-checkout-nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'woocommerce-process_checkout' ) ) {
			return array();
		}

		if ( ! isset( $_POST['nettertech_events_custom_fields'] ) || ! is_array( $_POST['nettertech_events_custom_fields'] ) ) {
			return array();
		}

		return array_map( 'sanitize_text_field', wp_unslash( $_POST['nettertech_events_custom_fields'] ) );
	}

	/**
	 * Render fields for a single event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<AttendeeField> $fields   Field definitions.
	 * @param \WC_Checkout         $checkout Checkout instance.
	 * @return void
	 */
	private function render_event_fields( int $event_id, array $fields, $checkout ): void {
		foreach ( $fields as $field ) {
			$field_name = 'nettertech_events_custom_fields[' . $event_id . '_' . $field->field_key . ']';
			$field_id   = 'nettertech_events_custom_fields_' . $event_id . '_' . $field->field_key;

			$args = array(
				'type'        => $this->map_field_type( $field ),
				'class'       => array( 'form-row-wide' ),
				'label'       => $field->label,
				'required'    => $field->is_required,
				'placeholder' => $field->placeholder ?? '',
				'id'          => $field_id,
			);

			// Add options for select fields.
			$field_type = $field->get_field_type();
			if ( null !== $field_type && $field_type->has_options() ) {
				$options      = array( '' => __( 'Select an option', 'nettertech-events' ) );
				$field_values = $field->get_options();
				foreach ( $field_values as $option ) {
					$options[ $option ] = $option;
				}
				$args['type']    = 'select';
				$args['options'] = $options;
			}

			// Add description if available.
			if ( ! empty( $field->description ) ) {
				$args['description'] = $field->description;
			}

			woocommerce_form_field(
				$field_name,
				$args,
				$checkout->get_value( $field_id )
			);
		}
	}

	/**
	 * Map AttendeeField type to WooCommerce form field type.
	 *
	 * @param AttendeeField $field Field definition.
	 * @return string WooCommerce field type.
	 */
	private function map_field_type( AttendeeField $field ): string {
		return match ( $field->field_type ) {
			'textarea' => 'textarea',
			'select'   => 'select',
			'radio'    => 'radio',
			'checkbox' => 'checkbox',
			'email'    => 'email',
			'phone'    => 'tel',
			'number'   => 'number',
			'date'     => 'date',
			'url'      => 'url',
			default    => 'text',
		};
	}

	/**
	 * Get event IDs from the current cart.
	 *
	 * @return array<int>
	 */
	private function get_event_ids_from_cart(): array {
		$wc   = function_exists( 'WC' ) ? WC() : null;
		$cart = $wc ? $wc->cart : null;
		if ( ! $cart ) {
			return array();
		}

		$event_ids = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id = $cart_item['product_id'] ?? 0;
			if ( ! $product_id || ! $this->product_manager->is_event_ticket( $product_id ) ) {
				continue;
			}

			$ticket_type = $this->product_manager->get_ticket_type_from_product( $product_id );
			if ( $ticket_type && $ticket_type->event_id ) {
				$event_ids[] = $ticket_type->event_id;
			}
		}

		return array_unique( $event_ids );
	}
}
