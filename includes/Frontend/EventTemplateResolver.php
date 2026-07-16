<?php
/**
 * Event Template Resolver.
 *
 * Locates template files for event pages and provides event routing utilities.
 * Extracted from Router via Extract Class + Delegation (ADR-007).
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\TemplateLoader\Templates;

/**
 * Locates template files for event pages and provides event routing utilities.
 *
 * @since 1.0.0
 */
class EventTemplateResolver {

	/**
	 * Templates service.
	 *
	 * @since 1.6.0
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 *
	 * @param Templates $templates Templates service.
	 */
	public function __construct( Templates $templates ) {
		$this->templates = $templates;
	}

	/**
	 * Get the single event template.
	 *
	 * Template hierarchy:
	 * 1. Theme: nettertech-events/single-event.php
	 * 2. Theme: single-nte-event.php (legacy: single-venue-event.php)
	 * 3. Plugin: templates/single-event.php
	 *
	 * @param string $default Default template.
	 * @return string
	 */
	public function get_single_template( string $default ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- 'default' accurately describes fallback template parameter.
		$templates = array(
			'nettertech-events/single-event.php',
			'single-nte-event.php',
		);

		$theme_template = locate_template( $templates );

		if ( $theme_template ) {
			return $theme_template;
		}

		$plugin_template = NETTERTECH_EVENTS_PLUGIN_DIR . 'templates/single-event.php';

		if ( $this->templates->template_file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $default;
	}

	/**
	 * Get the events archive template.
	 *
	 * @param string $default Default template.
	 * @return string
	 */
	public function get_archive_template( string $default ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- 'default' accurately describes fallback template parameter.
		$templates = array(
			'nettertech-events/archive-events.php',
			'archive-nettertech-events.php',
		);

		$theme_template = locate_template( $templates );

		if ( $theme_template ) {
			return $theme_template;
		}

		$plugin_template = NETTERTECH_EVENTS_PLUGIN_DIR . 'templates/archive-events.php';

		if ( $this->templates->template_file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $default;
	}

	/**
	 * Get the past events archive template.
	 *
	 * @param string $default Default template.
	 * @return string
	 */
	public function get_past_archive_template( string $default ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- 'default' accurately describes fallback template parameter.
		$templates = array(
			'nettertech-events/archive-past-events.php',
			'archive-past-nettertech-events.php',
		);

		$theme_template = locate_template( $templates );

		if ( $theme_template ) {
			return $theme_template;
		}

		$plugin_template = NETTERTECH_EVENTS_PLUGIN_DIR . 'templates/archive-past-events.php';

		if ( $this->templates->template_file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		// Fall back to the regular archive template.
		return $this->get_archive_template( $default );
	}

	/**
	 * Get the series page template for recurring events.
	 *
	 * Template hierarchy:
	 * 1. Theme: nettertech-events/series-page.php
	 * 2. Theme: series-nte-event.php (legacy: series-venue-event.php)
	 * 3. Plugin: templates/series-page.php
	 * 4. Fallback to single-event template
	 *
	 * @param string $default Default template.
	 * @return string
	 */
	public function get_series_template( string $default ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- 'default' accurately describes fallback template parameter.
		$templates = array(
			'nettertech-events/series-page.php',
			'series-nte-event.php',
		);

		$theme_template = locate_template( $templates );

		if ( $theme_template ) {
			return $theme_template;
		}

		$plugin_template = NETTERTECH_EVENTS_PLUGIN_DIR . 'templates/series-page.php';

		if ( $this->templates->template_file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		// Fall back to single-event template if series template doesn't exist.
		return $this->get_single_template( $default );
	}

	/**
	 * Get the space detail page template.
	 *
	 * Template hierarchy:
	 * 1. Theme: nettertech-events/single-space.php
	 * 2. Plugin: templates/single-space.php
	 *
	 * @since 1.7.0
	 *
	 * @param string $default Default template.
	 * @return string
	 */
	public function get_space_template( string $default ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- 'default' accurately describes fallback template parameter.
		$templates = array(
			'nettertech-events/single-space.php',
		);

		$theme_template = locate_template( $templates );

		if ( $theme_template ) {
			return $theme_template;
		}

		$plugin_template = NETTERTECH_EVENTS_PLUGIN_DIR . 'templates/single-space.php';

		if ( $this->templates->template_file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $default;
	}

	/**
	 * Parse occurrence datetime from URL format.
	 *
	 * @param string $datetime_str Datetime in YYYY-MM-DD-HHMM format.
	 * @return \DateTimeImmutable|null
	 */
	public function parse_occurrence_datetime( string $datetime_str ): ?\DateTimeImmutable {
		// Use ! prefix to reset all fields to Unix epoch first (sets seconds to 00).
		$datetime = \DateTimeImmutable::createFromFormat( '!Y-m-d-Hi', $datetime_str );

		if ( false === $datetime ) {
			return null;
		}

		return $datetime;
	}

	/**
	 * Check if the current user can preview an unpublished event.
	 *
	 * Two paths gate access:
	 *   1. A logged-in editor (edit_pages capability) viewing directly is
	 *      always allowed — the capability check already guarantees
	 *      authenticated session.
	 *   2. A `?preview=true` query parameter requires a valid
	 *      `nettertech_events_preview_{event_id}` nonce.
	 *
	 * Refactored from the v1.0.1 form, which fell through to
	 * `is_user_logged_in()` when the preview param was *set but not equal
	 * to "true"* — creating a logic gap where any malformed preview value
	 * silently bypassed the nonce check. The early-return structure below
	 * eliminates that gap: either no preview param (capability-gated only),
	 * or preview-param present (nonce mandatory).
	 *
	 * @param \NetterTechEvents\Models\Event $event The event to check.
	 * @return bool
	 */
	public function can_preview_event( \NetterTechEvents\Models\Event $event ): bool {
		// Capability check first — terminates before any superglobal read.
		if ( ! current_user_can( 'edit_pages' ) ) {
			return false;
		}

		// No preview param → logged-in editor viewing directly is allowed.
		// current_user_can( 'edit_pages' ) implies authenticated session, so
		// the prior `is_user_logged_in()` fall-through was redundant.
		$preview_param = isset( $_GET['preview'] ) ? sanitize_text_field( wp_unslash( $_GET['preview'] ) ) : '';
		if ( 'true' !== $preview_param ) {
			return true;
		}

		// Preview param set → nonce verification is mandatory.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		// wp_verify_nonce returns int|false; cast to bool.
		return (bool) wp_verify_nonce( $nonce, 'nettertech_events_preview_' . $event->id );
	}
}
