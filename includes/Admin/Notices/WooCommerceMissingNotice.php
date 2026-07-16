<?php
/**
 * WooCommerce Missing Notice.
 *
 * @package NetterTechEvents\Admin\Notices
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Notices;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\AdminRequest;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces a warning on the event editor when paid tickets are configured
 * but WooCommerce is not active.
 *
 * Without this notice, an admin who has configured paid ticket types and
 * then deactivated WooCommerce would see ticket cards on the frontend with
 * no purchase mechanism and no explanation. This class detects that
 * configuration and warns on the event editor screen.
 *
 * @since 1.0.1
 */
class WooCommerceMissingNotice {

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 */
	public function __construct( TicketTypeRepositoryInterface $ticket_type_repo ) {
		$this->ticket_type_repo = $ticket_type_repo;
	}

	/**
	 * Register the admin_notices hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
	}

	/**
	 * Conditionally render the notice if all conditions are met.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		if ( $this->is_woocommerce_active() ) {
			return;
		}

		if ( ! $this->is_event_editor_screen() ) {
			return;
		}

		$event_id = $this->get_current_event_id();
		if ( null === $event_id ) {
			return;
		}

		if ( ! $this->event_has_paid_tickets( $event_id ) ) {
			return;
		}

		$this->render_notice();
	}

	/**
	 * Check whether WooCommerce is loaded.
	 *
	 * Wrapped in a protected method so tests can override it without
	 * polluting the global class table via eval().
	 *
	 * @return bool
	 */
	protected function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Check whether the current admin screen is the event editor.
	 *
	 * @return bool
	 */
	private function is_event_editor_screen(): bool {
		return AdminMenu::SUBMENU_EDIT === AdminRequest::get_text( 'page' );
	}

	/**
	 * Get the event ID currently being edited from the query string.
	 *
	 * @return int|null Event ID, or null when not editing a specific event.
	 */
	private function get_current_event_id(): ?int {
		$event_id = AdminRequest::get_absint( 'event_id' );
		return $event_id > 0 ? $event_id : null;
	}

	/**
	 * Check whether the given event has any non-free ticket types configured.
	 *
	 * @param int $event_id Event ID.
	 * @return bool True if at least one ticket type has a price greater than zero.
	 */
	private function event_has_paid_tickets( int $event_id ): bool {
		$ticket_types = $this->ticket_type_repo->for_event( $event_id );

		foreach ( $ticket_types as $ticket_type ) {
			if ( ! $ticket_type->is_free() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the warning notice.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'NetterTech Events', 'nettertech-events' ); ?>:</strong>
				<?php esc_html_e( 'This event has paid tickets configured, but WooCommerce is not active. Activate WooCommerce to enable ticket purchases.', 'nettertech-events' ); ?>
			</p>
		</div>
		<?php
	}
}
