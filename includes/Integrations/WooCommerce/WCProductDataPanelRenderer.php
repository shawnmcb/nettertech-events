<?php
/**
 * Renderer for the WooCommerce product-data panel and stock notice.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;

/**
 * Renders the admin-side product-data panel for NTE-managed event-ticket
 * products and the stock-management notice that replaces WooCommerce's
 * own stock fields for those products.
 *
 * Extracted from WooCommerceIntegration as part of the god-class
 * decomposition. Internal collaborator only; not registered in the DI
 * container — WooCommerceIntegration constructs it on demand and the
 * public hook callbacks remain on WooCommerceIntegration so the
 * `array( $this, 'render_product_data_panel' )` style add_action()
 * registrations stay byte-identical.
 *
 * @since 2.2.0
 * @internal
 */
class WCProductDataPanelRenderer {

	/**
	 * Product manager for is-event-ticket / get-ticket-type lookups.
	 *
	 * @var ProductManager
	 */
	private ProductManager $product_manager;

	/**
	 * Constructor.
	 *
	 * @param ProductManager $product_manager Product manager service.
	 */
	public function __construct( ProductManager $product_manager ) {
		$this->product_manager = $product_manager;
	}

	/**
	 * Add nettertech events tab to product data.
	 *
	 * @param array<string, array<string, mixed>> $tabs Existing tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_product_data_tab( array $tabs ): array {
		$tabs['nettertech_events'] = array(
			'label'    => __( 'Event Ticket', 'nettertech-events' ),
			'target'   => 'nettertech_events_product_data',
			'class'    => array( 'show_if_nettertech_events_event_ticket' ),
			'priority' => 21,
		);

		return $tabs;
	}

	/**
	 * Render product data panel.
	 *
	 * @return void
	 */
	public function render_product_data_panel(): void {
		global $post;

		$wc_product     = wc_get_product( $post->ID );
		$occurrence_id  = $wc_product ? $wc_product->get_meta( '_nettertech_events_occurrence_id', true ) : '';
		$ticket_type_id = $wc_product ? $wc_product->get_meta( '_nettertech_events_ticket_type_id', true ) : '';
		?>
		<div id="nettertech_events_product_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<p class="form-field">
					<label><?php esc_html_e( 'Occurrence ID', 'nettertech-events' ); ?></label>
					<span class="description"><?php echo esc_html( $occurrence_id ? $occurrence_id : __( 'Not linked', 'nettertech-events' ) ); ?></span>
				</p>
				<p class="form-field">
					<label><?php esc_html_e( 'Ticket Type ID', 'nettertech-events' ); ?></label>
					<span class="description"><?php echo esc_html( $ticket_type_id ? $ticket_type_id : __( 'Not linked', 'nettertech-events' ) ); ?></span>
				</p>
				<?php if ( $occurrence_id ) : ?>
				<p class="form-field">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=nettertech-events-check-in&occurrence_id=' . $occurrence_id ) ); ?>" class="button">
						<?php esc_html_e( 'View Check-In List', 'nettertech-events' ); ?>
					</a>
				</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a stock management notice for NTE-managed products.
	 *
	 * Hides WC stock fields and displays an informational notice with a link
	 * to the NTE event editor where capacity is actually managed.
	 *
	 * Hooked to `woocommerce_product_options_stock`.
	 *
	 * @return void
	 */
	public function render_stock_notice_for_managed_products(): void {
		global $post;

		if ( ! $post || ! $post->ID ) {
			return;
		}

		if ( ! $this->product_manager->is_event_ticket( $post->ID ) ) {
			return;
		}

		$wc_product     = wc_get_product( $post->ID );
		$ticket_type_id = $wc_product ? (int) $wc_product->get_meta( '_nettertech_events_ticket_type_id', true ) : 0;
		$event_id       = $wc_product ? (int) $wc_product->get_meta( '_nettertech_events_event_id', true ) : 0;

		// Build capacity display from ticket type.
		$capacity_text = __( 'Unlimited', 'nettertech-events' );
		if ( $ticket_type_id ) {
			$ticket_type = $this->product_manager->get_ticket_type_from_product( $post->ID );
			if ( $ticket_type && null !== $ticket_type->capacity ) {
				$capacity_text = (string) $ticket_type->capacity;
			}
		}

		// Build the edit link if we have an event ID.
		$edit_link = '';
		if ( $event_id > 0 ) {
			$edit_url  = admin_url( 'admin.php?page=nettertech-events&action=edit&event_id=' . $event_id );
			$edit_link = sprintf(
				' <a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit capacity &rarr;', 'nettertech-events' )
			);
		}

		wp_add_inline_style(
			'woocommerce_admin_styles',
			'._manage_stock_field,
._stock_field,
._backorders_field,
._low_stock_amount_field {
	display: none !important;
}'
		);

		// Display capacity notice for ticket products managed by NTE.
		?>
		<div class="inline notice notice-info" style="margin: 12px;">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: current capacity value wrapped in <strong> */
						__( 'Stock is managed by NetterTech Events (current capacity: %s).', 'nettertech-events' ),
						'<strong>' . esc_html( $capacity_text ) . '</strong>'
					),
					array( 'strong' => array() )
				);
				echo wp_kses( $edit_link, ShortcodeOutput::get_allowlist() );
				?>
			</p>
		</div>
		<?php
	}
}
