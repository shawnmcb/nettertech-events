<?php
/**
 * WooCommerce ticket-thumbnail filter.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Filters cart-item and admin-order-item thumbnails for NTE-managed
 * event-ticket products, substituting a ticket placeholder for the
 * default product thumbnail.
 *
 * Extracted from WooCommerceIntegration as part of the god-class
 * decomposition. Internal collaborator only; not registered in the DI
 * container. WooCommerceIntegration's public filter callbacks remain
 * on WooCommerceIntegration so the
 * `add_filter('woocommerce_cart_item_thumbnail', array($this, ...))`
 * registrations are byte-identical.
 *
 * @since 2.2.0
 * @internal
 */
class WCTicketThumbnailFilter {

	/**
	 * Plugin entry-point file path (passed through to plugins_url()).
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Product manager for is-event-ticket lookups.
	 *
	 * @var ProductManager
	 */
	private ProductManager $product_manager;

	/**
	 * Constructor.
	 *
	 * @param ProductManager $product_manager Product manager service.
	 * @param string         $plugin_file     Plugin entry-point file path
	 *                                        (e.g. `.../nettertech-events.php`).
	 */
	public function __construct( ProductManager $product_manager, string $plugin_file ) {
		$this->product_manager = $product_manager;
		$this->plugin_file     = $plugin_file;
	}

	/**
	 * Filter cart item thumbnail for event tickets.
	 *
	 * Displays a ticket placeholder image for ticket products in the cart.
	 *
	 * @param string               $thumbnail     Default thumbnail HTML.
	 * @param array<string, mixed> $cart_item     Cart item data.
	 * @param string               $cart_item_key Cart item key.
	 * @return string Filtered thumbnail HTML.
	 */
	public function filter_ticket_thumbnail( string $thumbnail, array $cart_item, string $cart_item_key ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		$product_id = $cart_item['product_id'] ?? 0;

		if ( ! $product_id || ! $this->product_manager->is_event_ticket( $product_id ) ) {
			return $thumbnail;
		}

		return $this->get_ticket_placeholder_img();
	}

	/**
	 * Filter admin order item thumbnail for event tickets.
	 *
	 * Displays a ticket placeholder image in admin order view.
	 *
	 * @param string               $thumbnail Default thumbnail HTML.
	 * @param int                  $item_id   Order item ID.
	 * @param \WC_Order_Item|mixed $item      Order item object.
	 * @return string Filtered thumbnail HTML.
	 */
	public function filter_admin_order_item_thumbnail( string $thumbnail, int $item_id, $item ): string {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $thumbnail;
		}

		$product_id = $item->get_product_id();

		if ( ! $product_id || ! $this->product_manager->is_event_ticket( $product_id ) ) {
			return $thumbnail;
		}

		return $this->get_ticket_placeholder_img( 'woocommerce_gallery_thumbnail' );
	}

	/**
	 * Get the ticket placeholder image HTML.
	 *
	 * @param string $size Image size name.
	 * @return string Image HTML.
	 */
	private function get_ticket_placeholder_img( string $size = 'woocommerce_thumbnail' ): string {
		$image_url = plugins_url( 'assets/images/ticket-placeholder.svg', $this->plugin_file );

		// Get dimensions based on WooCommerce image size.
		$dimensions = wc_get_image_size( $size );
		$width      = $dimensions['width'] ?? 100;
		$height     = $dimensions['height'] ?? 100;

		return sprintf(
			'<img src="%s" alt="%s" width="64" height="64" class="nte-ticket-placeholder" style="width: 64px !important; height: 64px !important; object-fit: contain;" />',
			esc_url( $image_url ),
			esc_attr__( 'Event Ticket', 'nettertech-events' )
		);
	}
}
