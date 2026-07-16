<?php
/**
 * Cart Presenter.
 *
 * Renders event ticket data in WooCommerce cart, checkout, and order views.
 * Extracted from CartHandler via Extract Class + Delegation (ADR-007).
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\MetaKeys;

/**
 * Renders event ticket data in WooCommerce cart, checkout, and order views.
 *
 * @since 1.0.0
 */
class CartPresenter {

	/**
	 * Product manager.
	 *
	 * @var ProductManager
	 */
	private ProductManager $product_manager;

	/**
	 * Constructor.
	 *
	 * @param ProductManager $product_manager Product manager.
	 */
	public function __construct( ProductManager $product_manager ) {
		$this->product_manager = $product_manager;
	}

	/**
	 * Modify cart item name to include event details.
	 *
	 * @param string               $name          Product name.
	 * @param array<string, mixed> $cart_item     Cart item data.
	 * @param string               $cart_item_key Cart item key (required by WooCommerce filter).
	 * @return string
	 */
	public function modify_cart_item_name( string $name, array $cart_item, string $cart_item_key ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		$product = $cart_item['data'] ?? null;

		if ( ! $product || ! $this->product_manager->is_event_ticket( $product ) ) {
			return $name;
		}

		$occurrence = $this->product_manager->get_occurrence_from_product( $product );

		if ( $occurrence && $occurrence->get_event() ) {
			$event = $occurrence->get_event();
			$name  = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $event->get_permalink() ),
				esc_html( wp_strip_all_tags( $name ) )
			);
		}

		return $name;
	}

	/**
	 * Display event details in cart item data.
	 *
	 * @param array<int, array<string, mixed>> $item_data Existing item data.
	 * @param array<string, mixed>             $cart_item Cart item.
	 * @return array<int, array<string, mixed>>
	 */
	public function display_cart_item_data( array $item_data, array $cart_item ): array {
		$product = $cart_item['data'] ?? null;

		if ( ! $product || ! $this->product_manager->is_event_ticket( $product ) ) {
			return $item_data;
		}

		$occurrence  = $this->product_manager->get_occurrence_from_product( $product );
		$ticket_type = $this->product_manager->get_ticket_type_from_product( $product );

		// Explicitly escape all values for defense-in-depth,
		// even though WooCommerce templates should also escape.
		if ( $occurrence ) {
			$item_data[] = array(
				'key'   => esc_html__( 'Date', 'nettertech-events' ),
				'value' => esc_html( $occurrence->get_formatted_date() ),
			);

			$item_data[] = array(
				'key'   => esc_html__( 'Time', 'nettertech-events' ),
				'value' => esc_html( $occurrence->get_formatted_time() ),
			);

			if ( $occurrence->get_event() && $occurrence->get_event()->venue_name ) {
				$item_data[] = array(
					'key'   => esc_html__( 'Venue', 'nettertech-events' ),
					'value' => esc_html( $occurrence->get_event()->venue_name ),
				);
			}
		}

		if ( $ticket_type ) {
			$item_data[] = array(
				'key'   => esc_html__( 'Ticket Type', 'nettertech-events' ),
				'value' => esc_html( $ticket_type->name ),
			);
		}

		return $item_data;
	}

	/**
	 * Add NetterTechEvents meta to order line items.
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array<string, mixed>   $values        Cart item values.
	 * @param \WC_Order              $order         Order object (required by WooCommerce action).
	 * @return void
	 */
	public function add_order_item_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		$product = $values['data'] ?? null;

		if ( ! $product || ! $this->product_manager->is_event_ticket( $product ) ) {
			return;
		}

		$occurrence  = $this->product_manager->get_occurrence_from_product( $product );
		$ticket_type = $this->product_manager->get_ticket_type_from_product( $product );

		// A series pass has no single occurrence; it carries the event and the pass
		// marker instead. OrderAttendeeCreator reads the marker to fan one attendee
		// out to every date the pass spans (NTE-156).
		if ( ! $occurrence && 'yes' === $product->get_meta( MetaKeys::IS_SERIES_PASS, true ) ) {
			$event_id = (int) $product->get_meta( MetaKeys::EVENT_ID, true );

			if ( $event_id > 0 ) {
				$item->add_meta_data( MetaKeys::IS_SERIES_PASS, 'yes' );
				$item->add_meta_data( MetaKeys::EVENT_ID, (string) $event_id );
				$item->add_meta_data( __( 'Ticket', 'nettertech-events' ), __( 'Series Pass — valid for every date of this event', 'nettertech-events' ), true );
			}
		}

		if ( $occurrence ) {
			$item->add_meta_data( '_nettertech_events_occurrence_id', (string) $occurrence->id );

			// Store human-readable event info.
			$item->add_meta_data( __( 'Event Date', 'nettertech-events' ), $occurrence->get_formatted_date(), true );
			$item->add_meta_data( __( 'Event Time', 'nettertech-events' ), $occurrence->get_formatted_time(), true );

			if ( $occurrence->get_event() && $occurrence->get_event()->venue_name ) {
				$item->add_meta_data( __( 'Venue', 'nettertech-events' ), $occurrence->get_event()->venue_name, true );
			}

			if ( $occurrence->event_id ) {
				$item->add_meta_data( MetaKeys::EVENT_ID, (string) $occurrence->event_id );
			}
		}

		if ( $ticket_type ) {
			$item->add_meta_data( '_nettertech_events_ticket_type_id', (string) $ticket_type->id );
			$item->add_meta_data( __( 'Ticket Type', 'nettertech-events' ), $ticket_type->name, true );
		}
	}
}
