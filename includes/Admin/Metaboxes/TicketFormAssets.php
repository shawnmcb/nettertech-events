<?php
/**
 * Ticket form assets (scripts and styles).
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

/**
 * Handles JavaScript and CSS for the tickets metabox.
 *
 * Extracted from TicketsMetabox to reduce god class size.
 *
 * @since 0.9.5
 */
class TicketFormAssets {

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Render metabox scripts.
	 *
	 * @return void
	 */
	public function render_scripts(): void {
		$handle = 'nettertech-events-ticket-form-assets';

		wp_enqueue_script(
			$handle,
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/ticket-form-assets.js',
			array( 'jquery' ),
			NETTERTECH_EVENTS_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'nettertechEventsTicketFormAssets',
			array(
				'strings' => array(
					'removeTicket' => __( 'Remove this ticket type?', 'nettertech-events' ),
					'newTicket'    => __( 'New Ticket', 'nettertech-events' ),
				),
			)
		);
	}

	/**
	 * Render metabox styles.
	 *
	 * @return void
	 */
	public function render_styles(): void {
		wp_enqueue_style(
			'nettertech-events-ticket-form-assets',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/css/admin/ticket-form-assets.css',
			array(),
			NETTERTECH_EVENTS_VERSION
		);
	}
}
