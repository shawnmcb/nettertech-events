<?php
/**
 * Ticket Row Header Presenter (T4.2.4 Pages cluster).
 *
 * @package NetterTechEvents\Admin\Metaboxes\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes\Presenters;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Enums\TicketTypeScope;

/**
 * Pure data-prep value object for the header strip of a single ticket-type row.
 *
 * The header is drag handle + move up/down buttons + name + scope badge +
 * collapse/remove buttons. Owns the display logic for "show ticket name or
 * 'New Ticket' placeholder" and surfaces the scope as a value+label pair.
 *
 * Does NOT call `esc_*()` (template's job), does NOT output, does NOT read
 * from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class TicketRowHeaderPresenter {

	/**
	 * Wire the ticket name and scope.
	 *
	 * @param string          $ticket_name Current ticket name (may be empty).
	 * @param TicketTypeScope $scope       Ticket scope enum.
	 */
	public function __construct(
		private readonly string $ticket_name,
		private readonly TicketTypeScope $scope
	) {}

	/**
	 * Display name: either the ticket's name or the "New Ticket" placeholder.
	 *
	 * @return string
	 */
	public function display_name(): string {
		return '' !== $this->ticket_name
			? $this->ticket_name
			: __( 'New Ticket', 'nettertech-events' );
	}

	/**
	 * Scope value (used in the data-scope attribute and badge CSS class).
	 *
	 * @return string
	 */
	public function scope_value(): string {
		return $this->scope->value;
	}

	/**
	 * Scope label (shown inside the badge).
	 *
	 * @return string
	 */
	public function scope_label(): string {
		return $this->scope->label();
	}

	// ---- Translatable button titles / labels ----

	/**
	 * "Move up" button title.
	 *
	 * @return string
	 */
	public function move_up_label(): string {
		return __( 'Move up', 'nettertech-events' );
	}

	/**
	 * "Move down" button title.
	 *
	 * @return string
	 */
	public function move_down_label(): string {
		return __( 'Move down', 'nettertech-events' );
	}

	/**
	 * "Remove" button title.
	 *
	 * @return string
	 */
	public function remove_label(): string {
		return __( 'Remove', 'nettertech-events' );
	}
}
