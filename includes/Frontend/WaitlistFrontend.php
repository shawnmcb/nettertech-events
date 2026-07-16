<?php
/**
 * Waitlist Frontend.
 *
 * Renders the customer-facing waitlist UI on sold-out events.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Handles frontend rendering and asset loading for the waitlist join flow.
 *
 * @since 2.1.0
 */
class WaitlistFrontend {

	/**
	 * Whether assets have been enqueued for this request.
	 *
	 * @var bool
	 */
	private bool $assets_enqueued = false;

	/**
	 * Initialize frontend hooks.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'nettertech_events_after_sold_out', array( $this, 'render_waitlist_panel' ), 10, 2 );
	}

	/**
	 * Register frontend assets.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register_assets(): void {
		$script_debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$waitlist_js  = $script_debug ? 'assets/js/waitlist-frontend.js' : 'assets/js/waitlist-frontend.min.js';
		$waitlist_css = $script_debug ? 'assets/css/waitlist-frontend.css' : 'assets/css/waitlist-frontend.min.css';

		wp_register_script(
			'nettertech-events-waitlist',
			NETTERTECH_EVENTS_PLUGIN_URL . $waitlist_js,
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_style(
			'nettertech-events-waitlist',
			NETTERTECH_EVENTS_PLUGIN_URL . $waitlist_css,
			array( 'nettertech-events-base' ),
			NETTERTECH_EVENTS_VERSION
		);
	}

	/**
	 * Render the waitlist panel below a sold-out ticket type.
	 *
	 * Hooked to `nettertech_events_after_sold_out`.
	 *
	 * @since 2.1.0
	 *
	 * @param TicketType $ticket_type The sold-out ticket type.
	 * @param Occurrence $occurrence  The occurrence.
	 * @return void
	 */
	public function render_waitlist_panel( TicketType $ticket_type, Occurrence $occurrence ): void {
		/**
		 * Filters whether the waitlist feature is available.
		 *
		 * @since 1.0.2
		 *
		 * @param bool $has_waitlist Whether waitlist is enabled. Default true in Base.
		 */
		if ( ! apply_filters( 'nettertech_events_has_waitlist', true ) ) {
			return;
		}

		$this->enqueue_assets();

		$current_user = wp_get_current_user();

		// Render using Base's template system (theme-overridable).
		// Template handles its own escaping (esc_attr, esc_html_e, etc.).
		$panel_html = Templates::get_part(
			'waitlist-panel',
			array(
				'occurrence_id' => $occurrence->id,
				'prefill_name'  => $current_user->ID ? $current_user->display_name : '',
				'prefill_email' => $current_user->ID ? $current_user->user_email : '',
			)
		);
		echo wp_kses( $panel_html, ShortcodeOutput::get_allowlist() );
	}

	/**
	 * Enqueue assets on first render.
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		if ( $this->assets_enqueued ) {
			return;
		}

		wp_enqueue_script( 'nettertech-events-waitlist' );
		wp_enqueue_style( 'nettertech-events-waitlist' );

		wp_localize_script(
			'nettertech-events-waitlist',
			'nettertechEventsWaitlist',
			array(
				'restUrl' => rest_url( 'nettertech-events/v1/waitlist/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'joining'        => __( 'Joining...', 'nettertech-events' ),
					'joinWaitlist'   => __( 'Join Waitlist', 'nettertech-events' ),
					'close'          => __( 'Close', 'nettertech-events' ),
					'leaving'        => __( 'Leaving...', 'nettertech-events' ),
					'leaveWaitlist'  => __( 'Leave Waitlist', 'nettertech-events' ),
					'leaveConfirm'   => __( 'Are you sure you want to leave the waitlist?', 'nettertech-events' ),
					'networkError'   => __( 'A network error occurred. Please try again.', 'nettertech-events' ),
					'requiredFields' => __( 'Please fill in all required fields.', 'nettertech-events' ),
					'invalidEmail'   => __( 'Please enter a valid email address.', 'nettertech-events' ),
					/* translators: %d: position number on the waitlist */
					'position'       => __( 'You are #%d on the waitlist.', 'nettertech-events' ),
				),
			)
		);

		$this->assets_enqueued = true;
	}
}
