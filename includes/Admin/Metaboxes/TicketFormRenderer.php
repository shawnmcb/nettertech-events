<?php
/**
 * Ticket form row renderer.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\Metaboxes\Presenters\TicketRowHeaderPresenter;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Models\TicketType;

/**
 * Renders individual ticket type form rows.
 *
 * Extracted from TicketsMetabox to reduce god class size.
 *
 * @since 0.9.5
 */
class TicketFormRenderer {

	/**
	 * Capacity service.
	 *
	 * @var CapacityServiceInterface
	 */
	private CapacityServiceInterface $capacity_service;

	/**
	 * Constructor.
	 *
	 * @param CapacityServiceInterface $capacity_service Capacity service.
	 */
	public function __construct( CapacityServiceInterface $capacity_service ) {
		$this->capacity_service = $capacity_service;
	}

	/**
	 * Render a single ticket type row.
	 *
	 * @param TicketType      $ticket Ticket type object.
	 * @param int|string      $index  Row index.
	 * @param TicketTypeScope $scope  Ticket scope.
	 * @return void
	 */
	public function render_ticket_row( TicketType $ticket, int|string $index, TicketTypeScope $scope ): void {
		$prefix = $this->get_field_prefix( $scope, $index );

		// Get capacity summary.
		$capacity_summary = null;
		if ( $ticket->id ) {
			$capacity_summary = $this->capacity_service->get_capacity_summary( $ticket->id );
		}
		?>
		<div class="nte-ticket-row" data-scope="<?php echo esc_attr( $scope->value ); ?>">
			<?php
			$presenter = new TicketRowHeaderPresenter( (string) $ticket->name, $scope );
			include dirname( __DIR__, 3 ) . '/templates/admin/metaboxes/ticket-row-header.php';
			?>

			<div class="nte-ticket-body">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]"
						value="<?php echo esc_attr( (string) ( $ticket->id ?? '' ) ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[scope]"
						value="<?php echo esc_attr( $scope->value ); ?>">

				<div class="nte-ticket-fields">
					<!-- Name -->
					<div class="nte-field nte-field-full">
						<label><?php esc_html_e( 'Ticket Name', 'nettertech-events' ); ?> <span class="required">*</span></label>
						<input type="text" name="<?php echo esc_attr( $prefix ); ?>[name]"
								value="<?php echo esc_attr( $ticket->name ); ?>"
								placeholder="<?php esc_attr_e( 'e.g., General Admission', 'nettertech-events' ); ?>"
								class="nte-ticket-name-input"
								required>
					</div>

					<!-- Price & Capacity -->
					<div class="nte-field">
						<label><?php esc_html_e( 'Price', 'nettertech-events' ); ?></label>
						<div class="nte-input-group">
							<span class="nte-input-prefix"><?php echo esc_html( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$' ); ?></span>
							<input type="number" name="<?php echo esc_attr( $prefix ); ?>[price]"
									value="<?php echo esc_attr( (string) $ticket->price ); ?>"
									min="0" step="0.01" placeholder="0.00">
						</div>
						<span class="nte-field-hint"><?php esc_html_e( 'Enter 0 for free tickets', 'nettertech-events' ); ?></span>
					</div>

					<div class="nte-field">
						<label><?php esc_html_e( 'Capacity Type', 'nettertech-events' ); ?></label>
						<?php
						$allowed_types    = CapacityType::for_scope( $scope );
						$current_cap_type = $ticket->capacity_type ?? 'fixed';
						?>
						<select name="<?php echo esc_attr( $prefix ); ?>[capacity_type]"
								class="nte-capacity-type-select">
							<?php foreach ( $allowed_types as $cap_type ) : ?>
								<option value="<?php echo esc_attr( $cap_type->value ); ?>"
										<?php selected( $current_cap_type, $cap_type->value ); ?>
										data-description="<?php echo esc_attr( $cap_type->description() ); ?>">
									<?php echo esc_html( $cap_type->label() ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="nte-field-hint nte-capacity-type-hint">
							<?php echo esc_html( CapacityType::from( $current_cap_type )->description() ); ?>
						</span>
					</div>

					<div class="nte-field nte-capacity-field" data-show-for="fixed">
						<label><?php esc_html_e( 'Capacity', 'nettertech-events' ); ?></label>
						<input type="number" name="<?php echo esc_attr( $prefix ); ?>[capacity]"
								value="<?php echo esc_attr( (string) ( $ticket->capacity ?? '' ) ); ?>"
								min="0" placeholder="<?php esc_attr_e( 'Unlimited', 'nettertech-events' ); ?>">
						<?php if ( $capacity_summary && ! $capacity_summary['is_unlimited'] ) : ?>
							<span class="nte-field-hint nte-capacity-status">
								<?php
								printf(
									/* translators: 1: sold count, 2: available count */
									esc_html__( '%1$d sold, %2$d available', 'nettertech-events' ),
									(int) $capacity_summary['sold'],
									(int) $capacity_summary['effective_available']
								);
								?>
								<?php if ( $capacity_summary['is_low_stock'] ) : ?>
									<span class="nte-low-stock"><?php esc_html_e( '(Low stock)', 'nettertech-events' ); ?></span>
								<?php endif; ?>
								<?php if ( $capacity_summary['is_sold_out'] ) : ?>
									<span class="nte-sold-out"><?php esc_html_e( '(Sold out)', 'nettertech-events' ); ?></span>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</div>

					<div class="nte-field nte-capacity-field" data-show-for="fixed">
						<label>
							<?php esc_html_e( 'Buffer Stock', 'nettertech-events' ); ?>
							<span class="dashicons dashicons-editor-help nte-tooltip-trigger"
									title="<?php esc_attr_e( 'Number of tickets held in reserve. These tickets are not available for public purchase but remain available for admin allocation.', 'nettertech-events' ); ?>"></span>
						</label>
						<?php
						$buffer_stock = 0;
						if ( $ticket->id ) {
							$buffer_stock = $this->capacity_service->get_buffer_stock( $ticket->id );
						}
						?>
						<input type="number" name="<?php echo esc_attr( $prefix ); ?>[buffer_stock]"
								value="<?php echo esc_attr( (string) $buffer_stock ); ?>"
								min="0" placeholder="0"
								class="nte-buffer-stock-input">
						<?php if ( $capacity_summary && ! $capacity_summary['is_unlimited'] ) : ?>
							<span class="nte-field-hint nte-buffer-status">
								<?php
								printf(
									/* translators: 1: total capacity, 2: buffer stock, 3: available for sale */
									esc_html__( 'Capacity: %1$d | Buffer: %2$d | Available: %3$d', 'nettertech-events' ),
									(int) $capacity_summary['capacity'],
									(int) $capacity_summary['buffer'],
									(int) $capacity_summary['effective_available']
								);
								?>
							</span>
						<?php endif; ?>
					</div>

					<!-- Order Limits -->
					<?php
					$ticket_dto  = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
					$default_min = $ticket_dto->tickets->default_min_per_order;
					$default_max = $ticket_dto->tickets->default_max_per_order;
					?>
					<div class="nte-field">
						<label><?php esc_html_e( 'Min per Order', 'nettertech-events' ); ?></label>
						<input type="number" name="<?php echo esc_attr( $prefix ); ?>[min_per_order]"
								value="<?php echo esc_attr( (string) ( $ticket->min_per_order ?? $default_min ) ); ?>"
								min="1">
					</div>

					<div class="nte-field">
						<label><?php esc_html_e( 'Max per Order', 'nettertech-events' ); ?></label>
						<input type="number" name="<?php echo esc_attr( $prefix ); ?>[max_per_order]"
								value="<?php echo esc_attr( (string) ( $ticket->max_per_order ?? $default_max ) ); ?>"
								min="1">
					</div>

					<?php
					// Sale window (NTE-190): split date + suggest-and-type time replaces
					// the combined datetime spinner control. Values recombine to the
					// legacy wire format server-side, so storage semantics are unchanged.
					// Labels wrap their inputs (no for/id) because rows clone via
					// {{INDEX}} templating and cannot share ids; same as Add-a-date.
					?>
					<fieldset class="nte-field nte-sale-window">
						<legend><?php esc_html_e( 'Sale starts', 'nettertech-events' ); ?></legend>
						<label class="nte-sale-window__part">
							<span class="screen-reader-text"><?php esc_html_e( 'Sale start date', 'nettertech-events' ); ?></span>
							<input type="date" name="<?php echo esc_attr( $prefix ); ?>[sale_start_date]"
									value="<?php echo esc_attr( $ticket->sale_start ? gmdate( 'Y-m-d', (int) strtotime( $ticket->sale_start ) ) : '' ); ?>">
						</label>
						<label class="nte-sale-window__part">
							<span class="screen-reader-text"><?php esc_html_e( 'Sale start time', 'nettertech-events' ); ?></span>
							<input type="time" name="<?php echo esc_attr( $prefix ); ?>[sale_start_time]"
									value="<?php echo esc_attr( $ticket->sale_start ? gmdate( 'H:i', (int) strtotime( $ticket->sale_start ) ) : '' ); ?>"
									data-nte-time-combobox>
						</label>
						<button type="button" class="button-link nte-sale-window__preset" data-nte-sale-preset="now">
							<?php esc_html_e( 'Now', 'nettertech-events' ); ?>
						</button>
						<span class="nte-field-hint"><?php esc_html_e( 'Leave blank for immediately', 'nettertech-events' ); ?></span>
					</fieldset>

					<fieldset class="nte-field nte-sale-window">
						<legend><?php esc_html_e( 'Sale ends', 'nettertech-events' ); ?></legend>
						<label class="nte-sale-window__part">
							<span class="screen-reader-text"><?php esc_html_e( 'Sale end date', 'nettertech-events' ); ?></span>
							<input type="date" name="<?php echo esc_attr( $prefix ); ?>[sale_end_date]"
									value="<?php echo esc_attr( $ticket->sale_end ? gmdate( 'Y-m-d', (int) strtotime( $ticket->sale_end ) ) : '' ); ?>">
						</label>
						<label class="nte-sale-window__part">
							<span class="screen-reader-text"><?php esc_html_e( 'Sale end time', 'nettertech-events' ); ?></span>
							<input type="time" name="<?php echo esc_attr( $prefix ); ?>[sale_end_time]"
									value="<?php echo esc_attr( $ticket->sale_end ? gmdate( 'H:i', (int) strtotime( $ticket->sale_end ) ) : '' ); ?>"
									data-nte-time-combobox>
						</label>
						<button type="button" class="button-link nte-sale-window__preset" data-nte-sale-preset="event-start">
							<?php esc_html_e( 'At event start', 'nettertech-events' ); ?>
						</button>
						<span class="nte-field-hint"><?php esc_html_e( 'Leave blank for no end', 'nettertech-events' ); ?></span>
						<span class="nte-timezone-hint">
							<?php
							/* translators: %s: timezone name, e.g. America/Chicago. */
							echo esc_html( sprintf( __( 'Times are in %s', 'nettertech-events' ), wp_timezone_string() ) );
							?>
						</span>
					</fieldset>

					<?php
					/**
					 * Filters whether a dedicated sale-schedule control renders on this form.
					 *
					 * An extension that renders its own price-schedule UI (chained sales on a
					 * tier) returns true to suppress the plain-window guidance below. Without
					 * it, operators hand-build one ticket per price window — parallel tiers
					 * that don't inherit capacity and all sell at once (NTE-157, CJAC 2546).
					 *
					 * @since 1.1.2
					 *
					 * @param bool $available Whether a sale-schedule control is present. Default false.
					 */
					if ( ! apply_filters( 'nettertech_events_sale_schedule_ui_available', false ) ) :
						?>
						<p class="description nte-field-full">
							<?php esc_html_e( 'These dates set when this one ticket is on sale. For a price that changes over time (early bird, last minute), avoid creating one ticket per price — the prices won\'t share a capacity pool. Ticket sales handle the switchover from one price to the next automatically.', 'nettertech-events' ); ?>
						</p>
					<?php endif; ?>

					<!-- Description -->
					<div class="nte-field nte-field-full">
						<label><?php esc_html_e( 'Description', 'nettertech-events' ); ?></label>
						<textarea name="<?php echo esc_attr( $prefix ); ?>[description]"
									rows="2"
									placeholder="<?php esc_attr_e( 'Optional description shown to customers', 'nettertech-events' ); ?>"><?php echo esc_textarea( $ticket->description ?? '' ); ?></textarea>
					</div>
				</div>

				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
					<?php
					// SKU surfacing (NTE-114). Read the linked WC product's SKU;
					// editable while empty, locked (read-only + copy) once set.
					$current_sku = '';
					if ( $ticket->wc_product_id ) {
						$wc_product  = wc_get_product( $ticket->wc_product_id );
						$current_sku = $wc_product ? (string) $wc_product->get_sku() : '';
					}
					?>
					<div class="nte-field nte-field-sku">
						<label><?php esc_html_e( 'SKU', 'nettertech-events' ); ?></label>
						<?php if ( '' !== $current_sku ) : ?>
							<span class="nte-sku-display">
								<code class="nte-sku-value"><?php echo esc_html( $current_sku ); ?></code>
								<button type="button" class="button button-small nte-sku-copy" data-sku="<?php echo esc_attr( $current_sku ); ?>">
									<?php esc_html_e( 'Copy', 'nettertech-events' ); ?>
								</button>
							</span>
							<span class="nte-field-hint"><?php esc_html_e( 'Locked. To use a different SKU, create a new ticket type.', 'nettertech-events' ); ?></span>
						<?php else : ?>
							<input type="text" name="<?php echo esc_attr( $prefix ); ?>[sku]" value="" autocomplete="off">
							<span class="nte-field-hint"><?php esc_html_e( 'Leave blank to auto-generate from the event and ticket name. Locked once saved.', 'nettertech-events' ); ?></span>
						<?php endif; ?>
					</div>

					<?php if ( $ticket->wc_product_id ) : ?>
						<div class="nte-wc-link">
							<a href="<?php echo esc_url( get_edit_post_link( $ticket->wc_product_id ) ?? '' ); ?>" target="_blank">
								<span class="dashicons dashicons-admin-links"></span>
								<?php esc_html_e( 'Edit WooCommerce Product', 'nettertech-events' ); ?>
							</a>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<?php
				/**
				 * Fires inside a ticket-type row, after the tier's own fields.
				 *
				 * The seam an extension hangs per-tier configuration on. A new row is built by
				 * calling this same method with an empty tier (see render_ticket_template()), so
				 * fields added here appear on rows the operator adds in the browser too — not only
				 * on rows that already existed when the page was drawn. An extension therefore does
				 * not need to watch the DOM to keep up with the form.
				 *
				 * The tier is empty, and $index is the literal '{{INDEX}}' placeholder, when the
				 * new-row template is being rendered. Listeners must tolerate both.
				 *
				 * @since 1.1.2
				 *
				 * @param TicketType      $ticket The tier. Empty, with a null id, in the new-row template.
				 * @param int|string      $index  Row index, or '{{INDEX}}' in the new-row template.
				 * @param TicketTypeScope $scope  The tier's scope.
				 * @param string          $prefix Field-name prefix, e.g. `ticket_types[occurrence][0]`.
				 */
				do_action( 'nettertech_events_ticket_row_fields', $ticket, $index, $scope, $prefix );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render JavaScript template for new tickets.
	 *
	 * @param TicketTypeScope $scope Ticket scope.
	 * @return void
	 */
	public function render_ticket_template( TicketTypeScope $scope ): void {
		?>
		<script type="text/template" id="nte-ticket-template-<?php echo esc_attr( $scope->value ); ?>">
			<?php $this->render_ticket_row( new TicketType(), '{{INDEX}}', $scope ); ?>
		</script>
		<?php
	}

	/**
	 * Get field name prefix for a ticket scope.
	 *
	 * @param TicketTypeScope $scope Ticket scope.
	 * @param int|string      $index Row index.
	 * @return string Field prefix.
	 */
	public function get_field_prefix( TicketTypeScope $scope, int|string $index ): string {
		return "ticket_types[{$scope->value}][{$index}]";
	}
}
