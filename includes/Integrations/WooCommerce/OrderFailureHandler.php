<?php
/**
 * WooCommerce Order Failure Handler.
 *
 * Handles attendee creation failures by notifying admins, logging the
 * failure, and placing the order on hold. P0 billing integrity safeguard.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\MetaKeys;

/**
 * Handles attendee creation failures from WooCommerce orders.
 *
 * When OrderAttendeeCreator fails for a line item, this handler:
 * 1. Adds admin and customer order notes
 * 2. Sets order status to on-hold
 * 3. Sends admin email notification
 * 4. Logs to ActivityLogService
 * 5. Fires extensibility hook for downstream consumers
 *
 * Does NOT attempt auto-refund — that is too risky without per-gateway
 * testing and is deferred to a future version.
 *
 * @since 1.6.0
 */
readonly class OrderFailureHandler {

	/**
	 * Activity log service.
	 *
	 * @var ActivityLogServiceInterface
	 */
	private ActivityLogServiceInterface $activity_log;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 *
	 * @param ActivityLogServiceInterface $activity_log Activity log service.
	 */
	public function __construct( ActivityLogServiceInterface $activity_log ) {
		$this->activity_log = $activity_log;
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nettertech_events_attendee_creation_failed', array( $this, 'handle_attendee_failure' ), 10, 3 );
	}

	/**
	 * Handle attendee creation failure.
	 *
	 * Orchestrates all failure-response steps. Each step is wrapped in
	 * try/catch to ensure one failure does not prevent subsequent steps.
	 * The outer try/catch ensures the handler never breaks order processing.
	 *
	 * @since 1.6.0
	 *
	 * @param \RuntimeException      $exception The exception that was thrown.
	 * @param int                    $order_id  WooCommerce order ID.
	 * @param \WC_Order_Item_Product $item      The order item that failed.
	 * @return void
	 */
	public function handle_attendee_failure( \RuntimeException $exception, int $order_id, \WC_Order_Item_Product $item ): void {
		try {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$actions_taken    = array();
			$item_name        = $item->get_name();
			$quantity         = $item->get_quantity();
			$item_description = $this->build_item_description( $item );

			// Step 1: Add admin-visible order note.
			$actions_taken = $this->add_admin_order_note( $order, $item_name, $quantity, $exception, $actions_taken );

			// Step 2: Add customer-visible order note.
			$actions_taken = $this->add_customer_order_note( $order, $item_name, $actions_taken );

			// Step 3: Set order status to on-hold if appropriate.
			$actions_taken = $this->maybe_set_on_hold( $order, $actions_taken );

			// Step 4: Send admin email notification.
			$actions_taken = $this->send_admin_email( $order, $order_id, $item_description, $quantity, $exception, $actions_taken );

			// Step 5: Log to ActivityLogService.
			$actions_taken = $this->log_failure( $order_id, $item, $item_description, $exception, $actions_taken );

			// Step 6: Fire extensibility hook.
			do_action( 'nettertech_events_attendee_failure_handled', $order_id, $item, $exception, $actions_taken );

		} catch ( \Throwable $e ) {
			$this->log_handler_step_failure( 'handle_attendee_failure', $order_id, $e );
		}
	}

	/**
	 * Build a human-readable description of the failed line item.
	 *
	 * @since 1.6.0
	 *
	 * @param \WC_Order_Item_Product $item Order item.
	 * @return string Description string.
	 */
	private function build_item_description( \WC_Order_Item_Product $item ): string {
		$ticket_type_id = $item->get_meta( MetaKeys::TICKET_TYPE_ID );
		$occurrence_id  = $item->get_meta( MetaKeys::OCCURRENCE_ID );
		$name           = $item->get_name();
		$quantity       = $item->get_quantity();

		return sprintf(
			'%s (ticket type #%s) x %d for occurrence #%s',
			$name,
			$ticket_type_id ? $ticket_type_id : 'unknown',
			$quantity,
			$occurrence_id ? $occurrence_id : 'unknown'
		);
	}

	/**
	 * Add admin-visible order note with failure details.
	 *
	 * @since 1.6.0
	 *
	 * @param \WC_Order         $order         Order object.
	 * @param string            $item_name     Item display name.
	 * @param int               $quantity      Item quantity.
	 * @param \RuntimeException $exception     The exception.
	 * @param array<string>     $actions_taken Actions taken so far.
	 * @return array<string> Updated actions taken.
	 */
	private function add_admin_order_note(
		\WC_Order $order,
		string $item_name,
		int $quantity,
		\RuntimeException $exception,
		array $actions_taken
	): array {
		try {
			$note = sprintf(
				/* translators: 1: item name, 2: quantity, 3: error message */
				"ALERT: Attendee creation failed for %1\$s \u{00d7} %2\$d.\nError: %3\$s\nThis item was billed but not fulfilled \u{2014} manual intervention required.",
				$item_name,
				$quantity,
				$exception->getMessage()
			);

			$order->add_order_note( $note, 0, true );
			$actions_taken[] = 'order_note';
		} catch ( \Throwable $e ) {
			$this->log_handler_step_failure( 'admin_order_note', $order->get_id(), $e );
		}

		return $actions_taken;
	}

	/**
	 * Add customer-visible order note.
	 *
	 * @since 1.6.0
	 *
	 * @param \WC_Order     $order         Order object.
	 * @param string        $item_name     Item display name.
	 * @param array<string> $actions_taken Actions taken so far.
	 * @return array<string> Updated actions taken.
	 */
	private function add_customer_order_note( \WC_Order $order, string $item_name, array $actions_taken ): array {
		try {
			$note = sprintf(
				/* translators: %s: item name */
				'There was an issue processing one of your tickets (%s). Our team has been notified and will reach out shortly.',
				$item_name
			);

			$order->add_order_note( $note, 1, true );
			$actions_taken[] = 'customer_note';
		} catch ( \Throwable $e ) {
			$this->log_handler_step_failure( 'customer_order_note', $order->get_id(), $e );
		}

		return $actions_taken;
	}

	/**
	 * Set order status to on-hold if not already terminal.
	 *
	 * Skips status change for orders that are already on-hold or in a
	 * terminal state (cancelled, refunded, failed).
	 *
	 * @since 1.6.0
	 *
	 * @param \WC_Order     $order         Order object.
	 * @param array<string> $actions_taken Actions taken so far.
	 * @return array<string> Updated actions taken.
	 */
	private function maybe_set_on_hold( \WC_Order $order, array $actions_taken ): array {
		try {
			$terminal_statuses = array( 'on-hold', 'cancelled', 'refunded', 'failed' );

			if ( ! in_array( $order->get_status(), $terminal_statuses, true ) ) {
				$order->set_status( 'on-hold', 'Attendee creation failed for one or more items.' );
				$order->save();
				$actions_taken[] = 'status_changed';
			}
		} catch ( \Throwable $e ) {
			$this->log_handler_step_failure( 'set_on_hold', $order->get_id(), $e );
		}

		return $actions_taken;
	}

	/**
	 * Send admin email notification about the failure.
	 *
	 * Uses plain wp_mail() — a full WC_Email subclass is not warranted for v1.
	 *
	 * @since 1.6.0
	 *
	 * @param \WC_Order         $order            Order object.
	 * @param int               $order_id         WooCommerce order ID.
	 * @param string            $item_description Human-readable item description.
	 * @param int               $quantity          Item quantity.
	 * @param \RuntimeException $exception        The exception.
	 * @param array<string>     $actions_taken    Actions taken so far.
	 * @return array<string> Updated actions taken.
	 */
	private function send_admin_email(
		\WC_Order $order,
		int $order_id,
		string $item_description,
		int $quantity,
		\RuntimeException $exception,
		array $actions_taken
	): array {
		try {
			$admin_email = get_option( 'admin_email' );

			if ( ! $admin_email ) {
				return $actions_taken;
			}

			$site_name = wp_specialchars_decode( get_option( 'blogname', '' ), ENT_QUOTES );
			$order_url = $order->get_edit_order_url();

			$subject = sprintf(
				/* translators: 1: site name, 2: order ID */
				'[%1$s] Attendee creation failed — Order #%2$d',
				$site_name,
				$order_id
			);

			$body = sprintf(
				"Attendee creation failed during order processing.\n\n"
				. "Order: #%d\n"
				. "Failed item: %s\n"
				. "Quantity: %d\n"
				. "Error: %s\n\n"
				. "This item was billed but attendee/ticket records were not created.\n"
				. "Manual intervention is required.\n\n"
				. "View order: %s\n",
				$order_id,
				$item_description,
				$quantity,
				$exception->getMessage(),
				$order_url
			);

			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

			wp_mail( $admin_email, $subject, $body, $headers );
			$actions_taken[] = 'admin_email';
		} catch ( \Throwable $e ) {
			$this->log_handler_step_failure( 'admin_email', $order_id, $e );
		}

		return $actions_taken;
	}

	/**
	 * Log the failure to the activity log service.
	 *
	 * @since 1.6.0
	 *
	 * @param int                    $order_id         WooCommerce order ID.
	 * @param \WC_Order_Item_Product $item             Failed order item.
	 * @param string                 $item_description Human-readable item description.
	 * @param \RuntimeException      $exception        The exception.
	 * @param array<string>          $actions_taken    Actions taken so far.
	 * @return array<string> Updated actions taken.
	 */
	private function log_failure(
		int $order_id,
		\WC_Order_Item_Product $item,
		string $item_description,
		\RuntimeException $exception,
		array $actions_taken
	): array {
		try {
			$this->activity_log->log(
				'attendee_creation_failed',
				'attendee',
				$order_id,
				$item_description,
				array(
					'order_id'       => $order_id,
					'item_id'        => $item->get_id(),
					'ticket_type_id' => $item->get_meta( MetaKeys::TICKET_TYPE_ID ),
					'occurrence_id'  => $item->get_meta( MetaKeys::OCCURRENCE_ID ),
					'quantity'       => $item->get_quantity(),
					'error_message'  => $exception->getMessage(),
					'item_total'     => $item->get_total(),
				)
			);
			$actions_taken[] = 'activity_log';
		} catch ( \Throwable $e ) {
			$this->log_handler_step_failure( 'activity_log', $order_id, $e );
		}

		return $actions_taken;
	}

	/**
	 * Log a failure within the failure handler itself.
	 *
	 * Uses error_log as a last-resort fallback when a handler step fails.
	 * This ensures the failure handler never throws, while still leaving
	 * a trace for debugging.
	 *
	 * @since 1.6.0
	 *
	 * @param string     $step     Handler step that failed.
	 * @param int        $order_id WooCommerce order ID.
	 * @param \Throwable $e        The exception.
	 * @return void
	 */
	private function log_handler_step_failure( string $step, int $order_id, \Throwable $e ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Last-resort logging when the failure handler itself fails.
		error_log(
			sprintf(
				'[NTE] OrderFailureHandler: step "%s" failed for order #%d: %s',
				$step,
				$order_id,
				$e->getMessage()
			)
		);
	}
}
