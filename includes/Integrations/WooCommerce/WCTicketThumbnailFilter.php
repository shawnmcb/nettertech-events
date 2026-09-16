<?php
/**
 * WooCommerce ticket-thumbnail filter.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Utilities\ImageHelper;

/**
 * Filters cart-item and admin-order-item thumbnails for NTE-managed
 * event-ticket products.
 *
 * Resolution order (NTE-219):
 *  1. The product's own image — WooCommerce has already rendered it, so the
 *     incoming thumbnail is returned untouched. ProductManager syncs the
 *     event / occurrence featured image onto the product, so this is the
 *     event image in practice.
 *  2. The site-wide "Default ticket product image" setting.
 *  3. The bundled ticket placeholder SVG.
 *
 * Either fallback passes through `nettertech_events_ticket_thumbnail_html`
 * so a theme can substitute its own markup without CSS hacks.
 *
 * Extracted from WooCommerceIntegration as part of the god-class
 * decomposition. Internal collaborator only; not registered in the DI
 * container. WooCommerceIntegration's public filter callbacks remain
 * on WooCommerceIntegration so the
 * `add_filter('woocommerce_cart_item_thumbnail', array($this, ...))`
 * registrations are byte-identical.
 *
 * @since 2.2.0
 * @since 1.4.7 Respects the product image; default-image setting; filter hook.
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
	 * @param string               $thumbnail     Default thumbnail HTML.
	 * @param array<string, mixed> $cart_item     Cart item data.
	 * @param string               $cart_item_key Cart item key.
	 * @return string Filtered thumbnail HTML.
	 */
	public function filter_ticket_thumbnail( string $thumbnail, array $cart_item, string $cart_item_key ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		$product_id = (int) ( $cart_item['product_id'] ?? 0 );

		if ( ! $product_id || ! $this->product_manager->is_event_ticket( $product_id ) ) {
			return $thumbnail;
		}

		return $this->resolve( $thumbnail, $product_id, 'woocommerce_thumbnail', 'cart' );
	}

	/**
	 * Filter admin order item thumbnail for event tickets.
	 *
	 * @param string               $thumbnail Default thumbnail HTML.
	 * @param int                  $item_id   Order item ID.
	 * @param \WC_Order_Item|mixed $item      Order item object.
	 * @return string Filtered thumbnail HTML.
	 */
	public function filter_admin_order_item_thumbnail( string $thumbnail, int $item_id, $item ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP hook signature.
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $thumbnail;
		}

		$product_id = (int) $item->get_product_id();

		if ( ! $product_id || ! $this->product_manager->is_event_ticket( $product_id ) ) {
			return $thumbnail;
		}

		return $this->resolve( $thumbnail, $product_id, 'woocommerce_gallery_thumbnail', 'admin_order_item' );
	}

	/**
	 * Supply the default ticket image as the product image itself.
	 *
	 * Runs on `woocommerce_product_get_image_id`, so every surface that reads
	 * the product image — block cart and checkout (Store API), classic cart,
	 * admin order items, WooCommerce emails — sees the same default without a
	 * product re-sync. Products with a real image and non-ticket products are
	 * untouched.
	 *
	 * @since 1.4.7
	 *
	 * @param mixed             $image_id Stored image id.
	 * @param \WC_Product|mixed $product  Product.
	 * @return mixed
	 */
	public function filter_product_image_id( $image_id, $product ) {
		if ( (int) $image_id > 0 || ! $product instanceof \WC_Product ) {
			return $image_id;
		}

		$product_id = (int) $product->get_id();
		if ( ! $product_id || ! $this->product_manager->is_event_ticket( $product_id ) ) {
			return $image_id;
		}

		$default_id = NetterTechEventsSettings::from_option()->tickets->default_ticket_image_id;
		if ( $default_id > 0 && ImageHelper::is_valid_image_attachment( $default_id ) ) {
			return $default_id;
		}

		return $image_id;
	}

	/**
	 * Pick the thumbnail for a ticket product.
	 *
	 * @param string $thumbnail  WooCommerce's rendered thumbnail.
	 * @param int    $product_id Product ID.
	 * @param string $size       WooCommerce image size for the fallbacks.
	 * @param string $context    'cart' or 'admin_order_item'.
	 * @return string
	 */
	private function resolve( string $thumbnail, int $product_id, string $size, string $context ): string {
		// 1. A product with its own image: WooCommerce already rendered it.
		$product = wc_get_product( $product_id );
		if ( $product && (int) $product->get_image_id() > 0 ) {
			return $thumbnail;
		}

		// 2. Site-wide default ticket image.
		$html       = '';
		$default_id = NetterTechEventsSettings::from_option()->tickets->default_ticket_image_id;
		if ( $default_id > 0 ) {
			$html = ImageHelper::get_attachment_image(
				$default_id,
				$size,
				false,
				array( 'class' => 'nte-ticket-default-image' )
			);
		}

		// 3. Bundled placeholder.
		if ( '' === $html ) {
			$html = $this->get_ticket_placeholder_img( $size );
		}

		/**
		 * Filter the thumbnail HTML NetterTech Events supplies for a ticket
		 * product that has no image of its own.
		 *
		 * @since 1.4.7
		 *
		 * @param string $html       Thumbnail HTML (default image or placeholder).
		 * @param int    $product_id Ticket product ID.
		 * @param string $context    'cart' or 'admin_order_item'.
		 */
		$filtered = apply_filters( 'nettertech_events_ticket_thumbnail_html', $html, $product_id, $context );

		return is_string( $filtered ) ? $filtered : $html;
	}

	/**
	 * Get the ticket placeholder image HTML.
	 *
	 * Sized from the WooCommerce image size it stands in for, so it matches
	 * the neighbouring product thumbnails instead of a fixed 64px.
	 *
	 * @param string $size Image size name.
	 * @return string Image HTML.
	 */
	private function get_ticket_placeholder_img( string $size = 'woocommerce_thumbnail' ): string {
		$image_url = plugins_url( 'assets/images/ticket-placeholder.svg', $this->plugin_file );

		$dimensions = wc_get_image_size( $size );
		$width      = max( 1, (int) ( $dimensions['width'] ?? 100 ) );
		$height     = (int) ( $dimensions['height'] ?? 0 );
		if ( $height <= 0 ) {
			$height = $width; // Uncropped sizes report no height; the placeholder is square.
		}

		return sprintf(
			'<img src="%s" alt="%s" width="%d" height="%d" class="nte-ticket-placeholder" style="object-fit: contain;" />',
			esc_url( $image_url ),
			esc_attr__( 'Event Ticket', 'nettertech-events' ),
			$width,
			$height
		);
	}
}
