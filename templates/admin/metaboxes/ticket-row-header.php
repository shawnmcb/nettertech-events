<?php
/**
 * Admin template: Ticket row header strip (T4.2.4).
 *
 * Rendered from `TicketFormRenderer::render_ticket_row()` after building a
 * `TicketRowHeaderPresenter`. Template MUST NOT call repositories, $wpdb,
 * $_GET/$_POST, or business logic — those are in the presenter. Template
 * MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 *
 * @var \NetterTechEvents\Admin\Metaboxes\Presenters\TicketRowHeaderPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<div class="nte-ticket-header">
	<span class="nte-ticket-drag-handle dashicons dashicons-move" aria-hidden="true"></span>
	<button type="button" class="nte-ticket-move-up" title="<?php echo esc_attr( $presenter->move_up_label() ); ?>">
		<span class="dashicons dashicons-arrow-up-alt2"></span>
	</button>
	<button type="button" class="nte-ticket-move-down" title="<?php echo esc_attr( $presenter->move_down_label() ); ?>">
		<span class="dashicons dashicons-arrow-down-alt2"></span>
	</button>
	<span class="nte-ticket-name">
		<?php echo esc_html( $presenter->display_name() ); ?>
	</span>
	<span class="nte-ticket-scope-badge nte-scope-<?php echo esc_attr( $presenter->scope_value() ); ?>">
		<?php echo esc_html( $presenter->scope_label() ); ?>
	</span>
	<button type="button" class="nte-ticket-toggle" aria-expanded="true">
		<span class="dashicons dashicons-arrow-up-alt2"></span>
	</button>
	<button type="button" class="nte-ticket-remove" title="<?php echo esc_attr( $presenter->remove_label() ); ?>">
		<span class="dashicons dashicons-trash"></span>
	</button>
</div>
