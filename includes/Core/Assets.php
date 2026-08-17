<?php
/**
 * Asset management class.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\AdminRequest;
use NetterTechEvents\Services\PaletteResolver;

/**
 * Handles conditional loading of scripts and styles.
 *
 * Key design decision: Assets are ONLY loaded on pages that
 * contain NetterTechEvents content, avoiding the performance issues
 * seen in The Events Calendar.
 *
 * @api
 */
class Assets {

	/**
	 * Subresource Integrity (SRI) hashes for vendor scripts.
	 *
	 * SHA-384 hashes for third-party scripts to ensure integrity.
	 * Regenerate with: openssl dgst -sha384 -binary [file] | openssl base64 -A
	 *
	 * @var array<string, string>
	 */
	private const SRI_HASHES = array();

	/**
	 * Whether assets have been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Page context detector.
	 *
	 * @var PageContextDetector
	 */
	private PageContextDetector $context_detector;

	/**
	 * Inline CSS generator.
	 *
	 * @var InlineCssGenerator
	 */
	private InlineCssGenerator $css_generator;

	/**
	 * Constructor.
	 *
	 * @param PaletteResolver          $palette_resolver Palette resolver.
	 * @param NetterTechEventsSettings $settings         Plugin settings.
	 */
	public function __construct( PaletteResolver $palette_resolver, NetterTechEventsSettings $settings ) {
		$this->context_detector = new PageContextDetector();
		$this->css_generator    = new InlineCssGenerator( $palette_resolver, $settings );
	}

	/**
	 * Register all plugin assets.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		// Frontend styles.
		wp_register_style(
			'nettertech-events-base',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/base.css',
			array(),
			NETTERTECH_EVENTS_VERSION
		);

		// Add theme-specific CSS variable overrides.
		$this->css_generator->add_theme_overrides();

		// Add theme palette color token overrides.
		$this->css_generator->add_palette_overrides();

		// Add image aspect ratio CSS custom properties.
		$this->css_generator->add_image_ratio_styles();

		// Add date badge color from settings.
		$this->css_generator->add_date_badge_styles();

		wp_register_style(
			'nettertech-events-carousel',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/carousel.css',
			array( 'nettertech-events-base' ),
			NETTERTECH_EVENTS_VERSION
		);

		wp_register_style(
			'nettertech-events-calendar',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/calendar.css',
			array( 'nettertech-events-base' ),
			NETTERTECH_EVENTS_VERSION
		);

		wp_register_style(
			'nettertech-events-grid',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/grid.css',
			array( 'nettertech-events-base' ),
			NETTERTECH_EVENTS_VERSION
		);

		wp_register_style(
			'nettertech-events-single',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/single.css',
			array( 'nettertech-events-base' ),
			NETTERTECH_EVENTS_VERSION
		);

		wp_register_style(
			'nettertech-events-series',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/series.css',
			array( 'nettertech-events-base', 'nettertech-events-grid' ),
			NETTERTECH_EVENTS_VERSION
		);

		wp_register_style(
			'nettertech-events-space',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/space.css',
			array( 'nettertech-events-base' ),
			NETTERTECH_EVENTS_VERSION
		);

		// Frontend scripts (WordPress 6.3+ defer strategy for non-blocking load).
		wp_register_script(
			'nettertech-events-carousel',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/carousel.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			'nettertech-events-calendar',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/calendar.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			'nettertech-events-filters',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/filters.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			'nettertech-events-multiselect',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/nte-multiselect.js',
			array( 'wp-a11y' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			'nettertech-events-grid',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/event-grid.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Add to Calendar dropdown (single event pages).
		wp_register_script(
			'nettertech-events-add-to-calendar',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/add-to-calendar.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Ticket quantity selector (progressive enhancement for ticket purchase form).
		wp_register_script(
			'nettertech-events-ticket-quantity',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/ticket-quantity.js',
			array( 'wp-a11y' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Checkout assets (WooCommerce donations).
		wp_register_style(
			'nettertech-events-checkout',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/checkout.css',
			array(),
			NETTERTECH_EVENTS_VERSION
		);

		wp_register_script(
			'nettertech-events-checkout',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/checkout.js',
			array( 'jquery' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Admin assets.
		wp_register_style(
			'nettertech-events-admin',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/admin.css',
			array(),
			NETTERTECH_EVENTS_VERSION
		);

		wp_register_script(
			'nettertech-events-admin',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/admin.js',
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-a11y' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Quick edit script (events list page only).
		wp_register_script(
			'nettertech-events-quick-edit',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/event-quick-edit.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Revision viewer (event edit page only).
		wp_register_script(
			'nettertech-events-revisions',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/event-revisions.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// SKUs dialog + copy buttons on the events list and editor (NTE-114).
		wp_register_script(
			'nettertech-events-event-skus',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/event-skus.js',
			array( 'wp-a11y' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Attendees screen check-in toggle (NTE-144).
		wp_register_script(
			'nettertech-events-attendee-checkin',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/attendee-checkin.js',
			array( 'wp-a11y' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Attendees screen Edit / Add dialogs (NTE-143).
		wp_register_script(
			'nettertech-events-attendee-editor',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/attendee-editor.js',
			array( 'wp-a11y' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Attendees screen "Re-send Email" row action (NTE-145).
		wp_register_script(
			'nettertech-events-attendee-resend-email',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/attendee-resend-email.js',
			array( 'wp-a11y' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Layout editor assets (admin).
		wp_register_style(
			'nettertech-events-layout-editor',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/css/layout-editor.css',
			array( 'nettertech-events-admin' ),
			NETTERTECH_EVENTS_VERSION
		);

		wp_register_script(
			'nettertech-events-layout-editor',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/dist/js/layout-editor.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Spaces admin form widgets (media pickers + accessibility editor).
		wp_register_style(
			'nettertech-events-space-form',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/css/admin/space-form.css',
			array(),
			NETTERTECH_EVENTS_VERSION
		);

		wp_register_script(
			'nettertech-events-space-form',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/space-form.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Public ticket page styles (standalone template, inline only).
		wp_register_style(
			'nettertech-events-public-ticket',
			false,
			array(),
			NETTERTECH_EVENTS_VERSION
		);

		// Add SRI (Subresource Integrity) filter for vendor scripts.
		add_filter( 'script_loader_tag', array( $this, 'add_sri_attributes' ), 10, 3 );

		/**
		 * Fires after base scripts and styles are registered.
		 *
		 * @since 1.0.2
		 */
		do_action( 'nettertech_events_register_assets' );
	}

	/**
	 * Enqueue checkout assets for WooCommerce donations.
	 *
	 * Should be called by WooCommerceIntegration when donations are enabled.
	 *
	 * @return void
	 */
	public function enqueue_checkout(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$this->register();

		wp_enqueue_style( 'nettertech-events-checkout' );
		wp_enqueue_script( 'nettertech-events-checkout' );

		wp_localize_script(
			'nettertech-events-checkout',
			'nettertechEventsDonation',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'nettertech_events_donation_nonce' ),
			)
		);
	}

	/**
	 * Conditionally enqueue frontend assets.
	 *
	 * Only loads assets on pages that contain our content.
	 *
	 * @return void
	 */
	public function maybe_enqueue_frontend(): void {
		$this->register();

		if ( ! $this->context_detector->page_has_nettertech_events_content() ) {
			return;
		}

		// Always load base styles (includes theme overrides via inline CSS).
		wp_enqueue_style( 'nettertech-events-base' );

		// Load assets for ALL detected view types.
		$views = $this->context_detector->get_detected_views();

		foreach ( $views as $view ) {
			switch ( $view ) {
				case 'carousel':
					wp_enqueue_style( 'nettertech-events-carousel' );
					wp_enqueue_script( 'nettertech-events-carousel' );
					break;

				case 'grid':
					wp_enqueue_style( 'nettertech-events-carousel' ); // Reuse card styles.
					wp_enqueue_style( 'nettertech-events-grid' );
					wp_enqueue_script( 'nettertech-events-grid' );
					wp_enqueue_script( 'nettertech-events-multiselect' );
					break;

				case 'calendar':
				case 'month':
				case 'week':
				case 'day':
					wp_enqueue_style( 'nettertech-events-calendar' );
					wp_enqueue_script( 'nettertech-events-calendar' );

					/**
					 * Fires after the calendar script and styles are enqueued.
					 *
					 * Extensions can use this to enqueue their own scripts with the
					 * calendar script handle as a dependency.
					 *
					 * @since 1.0.2
					 *
					 * @param string $handle The calendar script handle.
					 */
					do_action( 'nettertech_events_calendar_enqueue_scripts', 'nettertech-events-calendar' );
					break;

				case 'single':
					wp_enqueue_style( 'nettertech-events-single' );
					wp_enqueue_script( 'nettertech-events-add-to-calendar' );
					break;
			}
		}

		// Localize scripts with common data.
		$this->localize_scripts();
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin( string $hook_suffix ): void {
		// Only load on our admin pages.
		if ( ! $this->context_detector->is_nettertech_events_admin_page( $hook_suffix ) ) {
			return;
		}

		$this->register();

		wp_enqueue_style( 'nettertech-events-admin' );
		wp_enqueue_script( 'nettertech-events-admin' );

		// Enqueue wp-color-picker on the settings page for the Email Accent
		// Color field (NTE-006). Gated on the settings hook suffix to avoid
		// loading on every NTE admin page.
		if ( str_contains( $hook_suffix, 'nettertech-events-settings' ) ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_add_inline_script(
				'wp-color-picker',
				"jQuery(function($){ $('.nte-color-picker').wpColorPicker(); });"
			);
		}

		$localize_data = array(
			'apiUrl'   => rest_url( 'nettertech-events/v1/' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'adminUrl' => admin_url(),
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		);

		// Enqueue the SKUs dialog/copy script on the events list + editor (NTE-114).
		if ( 'toplevel_page_nettertech-events' === $hook_suffix && class_exists( 'WooCommerce' ) ) {
			wp_enqueue_script( 'nettertech-events-event-skus' );
			wp_localize_script(
				'nettertech-events-event-skus',
				'nettertechEventsSkus',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => Hooks::AJAX_EVENT_SKUS,
					'nonce'   => wp_create_nonce( Hooks::AJAX_EVENT_SKUS ),
					'i18n'    => array(
						'copied'    => __( 'SKU copied to clipboard.', 'nettertech-events' ),
						'copiedAll' => __( 'All SKUs copied to clipboard.', 'nettertech-events' ),
						'noSkus'    => __( 'This event has no ticket SKUs yet.', 'nettertech-events' ),
						'error'     => __( 'Could not load SKUs. Please try again.', 'nettertech-events' ),
						'copyError' => __( 'Copy failed. Select the text and copy manually.', 'nettertech-events' ),
						'none'      => __( '(none)', 'nettertech-events' ),
					),
				)
			);
		}

		// Enqueue the check-in toggle on the Attendees screen (NTE-144).
		if ( str_ends_with( $hook_suffix, '_page_' . AdminMenu::SUBMENU_ATTENDEES ) && current_user_can( 'edit_posts' ) ) {
			wp_enqueue_script( 'nettertech-events-attendee-editor' );
			wp_localize_script(
				'nettertech-events-attendee-editor',
				'nettertechEventsAttendeeEditor',
				array(
					'restUrl' => esc_url_raw( rest_url( 'nettertech-events/v1/admin/attendees' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'saved'        => __( 'Attendee saved.', 'nettertech-events' ),
						'added'        => __( 'Attendee added.', 'nettertech-events' ),
						'genericError' => __( 'Could not save. Please try again.', 'nettertech-events' ),
					),
				)
			);
			wp_enqueue_script( 'nettertech-events-attendee-checkin' );
			wp_localize_script(
				'nettertech-events-attendee-checkin',
				'nettertechEventsCheckIn',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => Hooks::AJAX_ATTENDEE_CHECK_IN,
					'nonce'   => wp_create_nonce( Hooks::AJAX_ATTENDEE_CHECK_IN ),
					'i18n'    => array(
						'checkIn'           => __( 'Check In', 'nettertech-events' ),
						'undo'              => __( 'Undo', 'nettertech-events' ),
						/* translators: %s: attendee name. */
						'checkInFor'        => __( 'Check in %s', 'nettertech-events' ),
						/* translators: %s: attendee name. */
						'undoFor'           => __( 'Undo check-in for %s', 'nettertech-events' ),
						'checkedInAnnounce' => __( 'Attendee checked in.', 'nettertech-events' ),
						'undoneAnnounce'    => __( 'Check-in undone.', 'nettertech-events' ),
						'error'             => __( 'Could not update check-in status. Please try again.', 'nettertech-events' ),
					),
				)
			);
		}

		// Enqueue the "Re-send Email" row action on the Attendees screen (NTE-145).
		// Gated on the WooCommerce capability the AJAX handler itself enforces.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- edit_shop_orders is a WooCommerce capability.
		if ( str_ends_with( $hook_suffix, '_page_' . AdminMenu::SUBMENU_ATTENDEES ) && current_user_can( 'edit_shop_orders' ) ) {
			wp_enqueue_script( 'nettertech-events-attendee-resend-email' );
			wp_localize_script(
				'nettertech-events-attendee-resend-email',
				'nettertechEventsResendEmail',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => 'nettertech_events_resend_confirmation_email',
					'nonce'   => wp_create_nonce( 'nettertech_events_resend_email' ),
					'i18n'    => array(
						'resend'  => __( 'Re-send Email', 'nettertech-events' ),
						'confirm' => __( 'Click again to confirm', 'nettertech-events' ),
						'sending' => __( 'Sending…', 'nettertech-events' ),
						'sent'    => __( 'Email sent.', 'nettertech-events' ),
						'error'   => __( 'Could not re-send the email. Please try again.', 'nettertech-events' ),
					),
				)
			);
		}

		// Enqueue quick-edit script on the events list page.
		if ( 'toplevel_page_nettertech-events' === $hook_suffix ) {
			wp_enqueue_script( 'nettertech-events-quick-edit' );

			$localize_data['quickEditNonce'] = wp_create_nonce( \NetterTechEvents\Admin\EventQuickEditHandler::NONCE_ACTION );
			$localize_data['statuses']       = array(
				'draft'     => __( 'Draft', 'nettertech-events' ),
				'published' => __( 'Published', 'nettertech-events' ),
				'cancelled' => __( 'Cancelled', 'nettertech-events' ),
				'postponed' => __( 'Postponed', 'nettertech-events' ),
			);
			// Enqueue revisions script on event edit pages.
			$event_id = AdminRequest::get_absint( 'event_id' );
			if ( 'edit' === AdminRequest::get_key( 'action' ) && $event_id > 0 ) {
				wp_enqueue_script( 'nettertech-events-revisions' );
				$localize_data['revisionNonce'] = wp_create_nonce( 'nettertech_events_revisions' );
			}

			$localize_data['strings'] = array(
				/* translators: %d: total number of matching events */
				'selectAllMatching' => __( 'All %d matching events on all pages?', 'nettertech-events' ),
				'selectAll'         => __( 'Select All', 'nettertech-events' ),
				/* translators: %d: total number of selected events */
				'allSelected'       => __( 'All %d matching events selected.', 'nettertech-events' ),
			);
		}

		wp_localize_script( 'nettertech-events-admin', 'nettertechEventsAdmin', $localize_data );
	}

	/**
	 * Localize frontend scripts with common data.
	 *
	 * Localizes to the primary script to avoid duplicate data in page source.
	 *
	 * @return void
	 */
	private function localize_scripts(): void {
		$data = array(
			'apiUrl'     => rest_url( 'nettertech-events/v1/' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'timezone'   => wp_timezone_string(),
			'dateFormat' => get_option( 'date_format' ),
			'timeFormat' => get_option( 'time_format' ),
			'strings'    => array(
				'loading'   => __( 'Loading...', 'nettertech-events' ),
				'noEvents'  => __( 'No events found.', 'nettertech-events' ),
				'soldOut'   => __( 'Sold Out', 'nettertech-events' ),
				'available' => __( 'Available', 'nettertech-events' ),
				'limited'   => __( 'Limited Availability', 'nettertech-events' ),
				'free'      => __( 'Free', 'nettertech-events' ),
				'cancelled' => __( 'Cancelled', 'nettertech-events' ),
				'completed' => __( 'Completed', 'nettertech-events' ),
			),
		);

		/**
		 * Filter the localized script data.
		 *
		 * @param array $data The data to localize.
		 */
		$data = apply_filters( 'nettertech_events_localize_data', $data );

		/**
		 * Filter calendar-specific JS configuration.
		 *
		 * Extensions can add configuration keys that will be available to
		 * calendar JS via `window.nettertechEvents`.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string, mixed> $data JS config data.
		 */
		$data = apply_filters( 'nettertech_events_calendar_js_config', $data );

		// Localize to the primary view's script.
		$view = $this->context_detector->get_primary_view();

		switch ( $view ) {
			case 'carousel':
				wp_localize_script( 'nettertech-events-carousel', 'nettertechEvents', $data );
				break;

			case 'calendar':
			case 'month':
			case 'week':
			case 'day':
				wp_localize_script( 'nettertech-events-calendar', 'nettertechEvents', $data );
				break;

			case 'grid':
			default:
				wp_localize_script( 'nettertech-events-grid', 'nettertechEvents', $data );
				break;
		}
	}

	/**
	 * Add Subresource Integrity (SRI) attributes to script tags.
	 *
	 * Filters the script tag HTML to add integrity and crossorigin attributes
	 * for vendor scripts with known hashes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tag    The script tag HTML.
	 * @param string $handle The script handle.
	 * @param string $src    The script source URL (unused, required by filter signature).
	 * @return string Modified script tag with integrity attribute.
	 */
	public function add_sri_attributes( string $tag, string $handle, string $src ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $src required by WordPress filter signature.
		// Only add SRI for scripts with known hashes.
		if ( ! isset( self::SRI_HASHES[ $handle ] ) ) {
			return $tag;
		}

		$integrity = self::SRI_HASHES[ $handle ];

		// Add integrity and crossorigin attributes.
		// crossorigin="anonymous" is required for SRI to work.
		$tag = str_replace(
			' src=',
			sprintf( ' integrity="%s" crossorigin="anonymous" src=', esc_attr( $integrity ) ),
			$tag
		);

		return $tag;
	}
}
