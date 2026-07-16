<?php
/**
 * Per-Attendee Checkout Handler.
 *
 * Renders individual attendee forms when collect_individual_attendees is enabled
 * and cart quantity > 1 for an event.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Models\AttendeeField;

/**
 * Handles per-attendee data collection at WooCommerce checkout.
 *
 * When an event has collect_individual_attendees enabled and the cart
 * quantity > 1, this handler renders N sets of name/email/phone fields
 * (one per ticket). The first attendee defaults to billing info.
 *
 * @since 3.6.0
 */
class PerAttendeeCheckoutHandler {

	/**
	 * Maximum quantity for per-attendee expansion.
	 */
	private const MAX_PER_ATTENDEE_QTY = 10;

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
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Attendee field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface
	 */
	private AttendeeFieldRepositoryInterface $field_repo;

	/**
	 * Constructor.
	 *
	 * @param CartHandler                      $cart_handler    Cart handler.
	 * @param ProductManager                   $product_manager Product manager.
	 * @param EventRepositoryInterface         $event_repo      Event repository.
	 * @param AttendeeFieldRepositoryInterface $field_repo      Attendee field repository.
	 */
	public function __construct(
		CartHandler $cart_handler,
		ProductManager $product_manager,
		EventRepositoryInterface $event_repo,
		AttendeeFieldRepositoryInterface $field_repo
	) {
		$this->cart_handler    = $cart_handler;
		$this->product_manager = $product_manager;
		$this->event_repo      = $event_repo;
		$this->field_repo      = $field_repo;
	}

	/**
	 * Render per-attendee forms on checkout.
	 *
	 * Only renders for cart items where the event has collect_individual_attendees
	 * enabled and quantity > 1 (and <= MAX_PER_ATTENDEE_QTY).
	 *
	 * @param \WC_Checkout $checkout Checkout instance.
	 * @return void
	 */
	public function render_forms( $checkout ): void {
		if ( ! $this->cart_handler->cart_has_tickets() ) {
			return;
		}

		$eligible_items = $this->get_eligible_cart_items();
		if ( empty( $eligible_items ) ) {
			return;
		}

		echo '<div class="nte-per-attendee-checkout">';
		echo '<h3>' . esc_html__( 'Attendee Details', 'nettertech-events' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Please provide details for each ticket holder.', 'nettertech-events' ) . '</p>';

		foreach ( $eligible_items as $cart_item_key => $item_data ) {
			$this->render_item_attendee_forms( $cart_item_key, $item_data, $checkout );
		}

		echo '</div>';
	}

	/**
	 * Validate per-attendee form data.
	 *
	 * @param array<string, mixed> $data   Checkout data.
	 * @param \WP_Error            $errors Validation errors.
	 * @return void
	 */
	public function validate_forms( array $data, \WP_Error $errors ): void {
		$submitted = $this->read_submitted_attendee_data();
		if ( empty( $submitted ) ) {
			return;
		}

		foreach ( $submitted as $cart_item_key => $attendees ) {
			if ( ! is_array( $attendees ) ) {
				continue;
			}

			foreach ( $attendees as $index => $attendee_data ) {
				if ( ! is_array( $attendee_data ) ) {
					continue;
				}

				$name  = sanitize_text_field( wp_unslash( $attendee_data['name'] ?? '' ) );
				$email = sanitize_email( wp_unslash( $attendee_data['email'] ?? '' ) );

				$label = sprintf(
					/* translators: %d: attendee number */
					__( 'Attendee #%d', 'nettertech-events' ),
					(int) $index + 1
				);

				if ( empty( $name ) ) {
					$errors->add(
						'nettertech_events_attendee_name',
						/* translators: %s: attendee label */
						sprintf( __( '%s: Name is required.', 'nettertech-events' ), $label )
					);
				}

				if ( empty( $email ) ) {
					$errors->add(
						'nettertech_events_attendee_email',
						/* translators: %s: attendee label */
						sprintf( __( '%s: Email is required.', 'nettertech-events' ), $label )
					);
				} elseif ( ! is_email( $email ) ) {
					$errors->add(
						'nettertech_events_attendee_email',
						/* translators: %s: attendee label */
						sprintf( __( '%s: Invalid email address.', 'nettertech-events' ), $label )
					);
				}
			}
		}
	}

	/**
	 * Save per-attendee data to order item meta.
	 *
	 * @param \WC_Order_Item_Product $item     Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array<string, mixed>   $values   Cart item values (unused, required by WC hook signature).
	 * @return void
	 */
	public function save_order_item_data( \WC_Order_Item_Product $item, string $cart_item_key, array $values ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		$submitted = $this->read_submitted_attendee_data();
		if ( ! isset( $submitted[ $cart_item_key ] ) || ! is_array( $submitted[ $cart_item_key ] ) ) {
			return;
		}

		$attendees = array();
		foreach ( $submitted[ $cart_item_key ] as $attendee_data ) {
			if ( ! is_array( $attendee_data ) ) {
				continue;
			}

			$attendee = array(
				'name'  => sanitize_text_field( wp_unslash( $attendee_data['name'] ?? '' ) ),
				'email' => sanitize_email( wp_unslash( $attendee_data['email'] ?? '' ) ),
				'phone' => sanitize_text_field( wp_unslash( $attendee_data['phone'] ?? '' ) ),
			);

			// Include custom field values if submitted.
			if ( isset( $attendee_data['custom_fields'] ) && is_array( $attendee_data['custom_fields'] ) ) {
				$attendee['custom_fields'] = array_map(
					'sanitize_text_field',
					array_map( 'wp_unslash', $attendee_data['custom_fields'] )
				);
			}

			$attendees[] = $attendee;
		}

		if ( ! empty( $attendees ) ) {
			$encoded = wp_json_encode( $attendees );
			if ( is_string( $encoded ) ) {
				$item->add_meta_data( MetaKeys::ATTENDEE_DATA, $encoded, true );
			}
		}
	}

	/**
	 * Read submitted per-attendee data from the checkout POST.
	 *
	 * Submitted as nested array
	 * `nettertech_events_attendee_data[<cartKey>][<index>][name|email|phone|custom_fields[...]]`,
	 * so `WC_Checkout::get_value()` cannot resolve it. We verify
	 * WooCommerce's checkout nonce inline as defense-in-depth (WC has
	 * already verified it before firing the validation / save hooks) so
	 * the bulk array read is gated in the same scope — no PHPCS
	 * suppression needed.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Cart key => list of attendee arrays.
	 */
	private function read_submitted_attendee_data(): array {
		$nonce = isset( $_POST['woocommerce-process-checkout-nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'woocommerce-process_checkout' ) ) {
			return array();
		}

		if ( ! isset( $_POST['nettertech_events_attendee_data'] ) || ! is_array( $_POST['nettertech_events_attendee_data'] ) ) {
			return array();
		}

		return map_deep( wp_unslash( $_POST['nettertech_events_attendee_data'] ), 'sanitize_textarea_field' );
	}

	/**
	 * Get cart items eligible for per-attendee collection.
	 *
	 * @return array<string, array{quantity: int, event_id: int, fields: array<AttendeeField>}> Cart item key => data.
	 */
	private function get_eligible_cart_items(): array {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return array();
		}

		$eligible = array();

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = $cart_item['product_id'] ?? 0;
			$quantity   = $cart_item['quantity'] ?? 1;

			if ( ! $product_id || $quantity <= 1 || $quantity > self::MAX_PER_ATTENDEE_QTY ) {
				continue;
			}

			if ( ! $this->product_manager->is_event_ticket( $product_id ) ) {
				continue;
			}

			$ticket_type = $this->product_manager->get_ticket_type_from_product( $product_id );
			if ( ! $ticket_type || ! $ticket_type->event_id ) {
				continue;
			}

			$event = $this->event_repo->find( (int) $ticket_type->event_id );
			if ( ! $event || empty( $event->collect_individual_attendees ) ) {
				continue;
			}

			$fields = $this->field_repo->for_event( (int) $ticket_type->event_id );

			$eligible[ $cart_item_key ] = array(
				'quantity' => (int) $quantity,
				'event_id' => (int) $ticket_type->event_id,
				'fields'   => $fields,
			);
		}

		return $eligible;
	}

	/**
	 * Render attendee forms for a single cart item.
	 *
	 * @param string                                                            $cart_item_key Cart item key.
	 * @param array{quantity: int, event_id: int, fields: array<AttendeeField>} $item_data     Item data.
	 * @param \WC_Checkout                                                      $checkout      Checkout instance.
	 * @return void
	 */
	private function render_item_attendee_forms( string $cart_item_key, array $item_data, $checkout ): void {
		$quantity = $item_data['quantity'];
		$fields   = $item_data['fields'];

		for ( $i = 0; $i < $quantity; $i++ ) {
			$prefix = "nettertech_events_attendee_data[{$cart_item_key}][{$i}]";
			$label  = sprintf(
				/* translators: %d: attendee number */
				__( 'Attendee #%d', 'nettertech-events' ),
				$i + 1
			);

			echo '<div class="nte-attendee-form" style="border: 1px solid #ddd; padding: 12px; margin-bottom: 12px; background: #fafafa;">';
			echo '<h4 style="margin-top: 0;">' . esc_html( $label ) . '</h4>';

			// Default first attendee to billing info hint.
			$name_default  = 0 === $i ? $checkout->get_value( 'billing_first_name' ) . ' ' . $checkout->get_value( 'billing_last_name' ) : '';
			$email_default = 0 === $i ? $checkout->get_value( 'billing_email' ) : '';
			$phone_default = 0 === $i ? $checkout->get_value( 'billing_phone' ) : '';

			woocommerce_form_field(
				$prefix . '[name]',
				array(
					'type'        => 'text',
					'class'       => array( 'form-row-wide' ),
					'label'       => __( 'Full Name', 'nettertech-events' ),
					'required'    => true,
					'placeholder' => __( 'Full name', 'nettertech-events' ),
				),
				trim( $name_default )
			);

			woocommerce_form_field(
				$prefix . '[email]',
				array(
					'type'        => 'email',
					'class'       => array( 'form-row-first' ),
					'label'       => __( 'Email', 'nettertech-events' ),
					'required'    => true,
					'placeholder' => __( 'Email address', 'nettertech-events' ),
				),
				$email_default
			);

			woocommerce_form_field(
				$prefix . '[phone]',
				array(
					'type'        => 'tel',
					'class'       => array( 'form-row-last' ),
					'label'       => __( 'Phone', 'nettertech-events' ),
					'required'    => false,
					'placeholder' => __( 'Phone (optional)', 'nettertech-events' ),
				),
				$phone_default
			);

			// Render custom fields per attendee.
			foreach ( $fields as $field ) {
				$field_name = $prefix . '[custom_fields][' . $field->field_key . ']';
				$field_type = $field->get_field_type();

				$args = array(
					'type'        => $this->map_field_type( $field->field_type ),
					'class'       => array( 'form-row-wide' ),
					'label'       => $field->label,
					'required'    => $field->is_required,
					'placeholder' => $field->placeholder ?? '',
				);

				if ( null !== $field_type && $field_type->has_options() ) {
					$options      = array( '' => __( 'Select an option', 'nettertech-events' ) );
					$field_values = $field->get_options();
					foreach ( $field_values as $option ) {
						$options[ $option ] = $option;
					}
					$args['type']    = 'select';
					$args['options'] = $options;
				}

				woocommerce_form_field( $field_name, $args, '' );
			}

			echo '</div>';
		}
	}

	/**
	 * Map field type to WooCommerce form field type.
	 *
	 * @param string $field_type Field type value.
	 * @return string WooCommerce field type.
	 */
	private function map_field_type( string $field_type ): string {
		return match ( $field_type ) {
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
}
