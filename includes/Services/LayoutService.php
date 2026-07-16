<?php
/**
 * Layout Service.
 *
 * Manages event page layout configuration including component order and visibility.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Event;

/**
 * Service for managing event page layout configuration.
 *
 * Handles layout resolution with fallback chain:
 * 1. Per-event configuration (from event's layout_config)
 * 2. Global settings (from nettertech_events_settings['event_layout'])
 * 3. Hardcoded defaults (component registry)
 *
 * @since 1.0.0
 * @api
 */
class LayoutService {

	/**
	 * Nonce action used by the live layout preview flow.
	 *
	 * Verified by {@see self::get_preview_config()}; emitted by
	 * {@see \NetterTechEvents\Admin\Metaboxes\LayoutMetaboxHandler}
	 * via wp_localize_script() and forwarded into the iframe URL by
	 * the layout-editor JS.
	 *
	 * @var string
	 */
	public const PREVIEW_NONCE_ACTION = 'nettertech_events_preview_layout';

	/**
	 * Component registry with metadata.
	 *
	 * @var array<string, array{label: string, description: string, default_visible: bool}>
	 */
	public const COMPONENTS = array(
		'header'          => array(
			'label'           => 'Header (Title, Date, Price, Venue)',
			'description'     => 'Event title, subtitle with date/time/price, and venue info',
			'default_visible' => true,
		),
		'featured_image'  => array(
			'label'           => 'Featured Image',
			'description'     => 'Main event image',
			'default_visible' => true,
		),
		'occurrence_date' => array(
			'label'           => 'Current Date/Time',
			'description'     => 'Specific occurrence date with ticket actions',
			'default_visible' => true,
		),
		'upcoming_dates'  => array(
			'label'           => 'Upcoming Dates',
			'description'     => 'List of upcoming occurrences for series events',
			'default_visible' => true,
		),
		'description'     => array(
			'label'           => 'Description',
			'description'     => 'Full event description content',
			'default_visible' => true,
		),
		'more_dates'      => array(
			'label'           => 'More Dates Navigation',
			'description'     => 'Navigation between sibling occurrences',
			'default_visible' => true,
		),
	);

	/**
	 * Default component order for single-occurrence events.
	 *
	 * @var array<string>
	 */
	public const DEFAULT_ORDER = array(
		'header',
		'featured_image',
		'occurrence_date',
		'upcoming_dates',
		'description',
		'more_dates',
	);

	/**
	 * Default component order for recurring events.
	 *
	 * Places description above upcoming_dates so visitors read what a series is
	 * before scanning the date list.
	 *
	 * @var array<string>
	 * @since 1.1.0
	 */
	public const DEFAULT_ORDER_RECURRING = array(
		'header',
		'featured_image',
		'occurrence_date',
		'description',
		'upcoming_dates',
		'more_dates',
	);

	/**
	 * Get layout configuration for an event.
	 *
	 * Resolves layout with fallback chain:
	 * 1. Event-specific layout_config
	 * 2. Global settings
	 * 3. Hardcoded defaults (recurring-aware when event context is provided)
	 *
	 * @param array<string, mixed>|null $event_layout_config Event's layout_config (JSON decoded array or null).
	 * @param Event|null                $event               Optional event context for recurring-aware defaults.
	 * @return array{order: array<string>, visibility: array<string, bool>}
	 */
	public function get_layout( ?array $event_layout_config = null, ?Event $event = null ): array {
		// Try event-specific config first.
		if ( ! empty( $event_layout_config ) && $this->validate_config( $event_layout_config ) ) {
			return $this->merge_with_defaults( $event_layout_config );
		}

		// Fall back to global settings.
		$global_config = $this->get_global_default();
		if ( ! empty( $global_config ) && $this->validate_config( $global_config ) ) {
			return $this->merge_with_defaults( $global_config );
		}

		// Return hardcoded defaults (recurring-aware).
		return $this->get_hardcoded_default( $event );
	}

	/**
	 * Get global default layout from settings.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_global_default(): ?array {
		return \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->display->event_layout;
	}

	/**
	 * Save global default layout to settings.
	 *
	 * @param array<string, mixed> $config Layout configuration.
	 * @return bool True on success.
	 */
	public function save_global_default( array $config ): bool {
		if ( ! $this->validate_config( $config ) ) {
			return false;
		}

		$settings                 = get_option( 'nettertech_events_settings', array() );
		$settings['event_layout'] = $this->sanitize_config( $config );

		return update_option( 'nettertech_events_settings', $settings, false );
	}

	/**
	 * Get hardcoded default layout.
	 *
	 * When an event context is provided and the event is recurring, returns
	 * {@see self::DEFAULT_ORDER_RECURRING} so the description appears above
	 * the upcoming-dates list by default. Per-event and global-settings overrides
	 * still take precedence via {@see self::get_layout()}.
	 *
	 * @param Event|null $event Optional event context for recurring-aware order selection.
	 * @return array{order: array<string>, visibility: array<string, bool>}
	 * @since 1.1.0 Added $event parameter for recurring-aware defaults.
	 */
	public function get_hardcoded_default( ?Event $event = null ): array {
		$visibility = array();
		foreach ( self::COMPONENTS as $id => $meta ) {
			$visibility[ $id ] = $meta['default_visible'];
		}

		$order = ( null !== $event && $event->is_recurring() )
			? self::DEFAULT_ORDER_RECURRING
			: self::DEFAULT_ORDER;

		return array(
			'order'      => $order,
			'visibility' => $visibility,
		);
	}

	/**
	 * Validate a layout configuration.
	 *
	 * @param array<string, mixed> $config Configuration to validate.
	 * @return bool True if valid.
	 */
	public function validate_config( array $config ): bool {
		// Must have order array.
		if ( ! isset( $config['order'] ) || ! is_array( $config['order'] ) ) {
			return false;
		}

		// Must have visibility array.
		if ( ! isset( $config['visibility'] ) || ! is_array( $config['visibility'] ) ) {
			return false;
		}

		// All order items must be valid component IDs.
		$valid_ids = array_keys( self::COMPONENTS );
		foreach ( $config['order'] as $id ) {
			if ( ! in_array( $id, $valid_ids, true ) ) {
				return false;
			}
		}

		// All visibility keys must be valid component IDs.
		foreach ( array_keys( $config['visibility'] ) as $id ) {
			if ( ! in_array( $id, $valid_ids, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Merge configuration with defaults.
	 *
	 * Ensures all components are present in order and visibility,
	 * adding any missing ones at the end with default visibility.
	 *
	 * @param array<string, mixed> $config User configuration.
	 * @return array{order: array<string>, visibility: array<string, bool>}
	 */
	public function merge_with_defaults( array $config ): array {
		$defaults = $this->get_hardcoded_default();

		// Start with provided order.
		$order = $config['order'] ?? array();

		// Add any missing components at the end.
		foreach ( self::DEFAULT_ORDER as $id ) {
			if ( ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}

		// Merge visibility with defaults.
		$visibility = $defaults['visibility'];
		if ( isset( $config['visibility'] ) && is_array( $config['visibility'] ) ) {
			foreach ( $config['visibility'] as $id => $visible ) {
				if ( array_key_exists( $id, $visibility ) ) {
					$visibility[ $id ] = (bool) $visible;
				}
			}
		}

		return array(
			'order'      => $order,
			'visibility' => $visibility,
		);
	}

	/**
	 * Sanitize a layout configuration.
	 *
	 * @param array<string, mixed> $config Configuration to sanitize.
	 * @return array{order: array<string>, visibility: array<string, bool>}
	 */
	public function sanitize_config( array $config ): array {
		$valid_ids = array_keys( self::COMPONENTS );

		// Filter order to only valid IDs.
		$order = array();
		if ( isset( $config['order'] ) && is_array( $config['order'] ) ) {
			foreach ( $config['order'] as $id ) {
				if ( is_string( $id ) && in_array( $id, $valid_ids, true ) ) {
					$order[] = $id;
				}
			}
		}

		// Filter visibility to only valid IDs with boolean values.
		$visibility = array();
		if ( isset( $config['visibility'] ) && is_array( $config['visibility'] ) ) {
			foreach ( $config['visibility'] as $id => $visible ) {
				if ( is_string( $id ) && in_array( $id, $valid_ids, true ) ) {
					$visibility[ $id ] = (bool) $visible;
				}
			}
		}

		return array(
			'order'      => $order,
			'visibility' => $visibility,
		);
	}

	/**
	 * Get list of visible components in order.
	 *
	 * @param array<string, mixed> $config Layout configuration.
	 * @return array<string> List of visible component IDs in display order.
	 */
	public function get_visible_components( array $config ): array {
		$merged = $this->merge_with_defaults( $config );

		return array_filter(
			$merged['order'],
			fn( $id ) => ! empty( $merged['visibility'][ $id ] )
		);
	}

	/**
	 * Get component registry.
	 *
	 * @return array<string, array{label: string, description: string, default_visible: bool}>
	 */
	public function get_components(): array {
		/**
		 * Filter the available layout components.
		 *
		 * Allows plugins/themes to add custom components.
		 *
		 * @since 1.0.2
		 *
		 * @param array $components Component registry.
		 */
		return apply_filters( 'nettertech_events_layout_components', self::COMPONENTS );
	}

	/**
	 * Check if a preview layout is requested via query param.
	 *
	 * @return array<string, mixed>|null Preview config or null if not previewing.
	 */
	public function get_preview_config(): ?array {
		// Require manage_options capability for preview before reading any request data.
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		// Verify the preview nonce inline. The nonce is emitted by
		// LayoutMetaboxHandler::render_preview() via wp_localize_script()
		// and forwarded into the iframe URL by the layout-editor JS. An
		// admin without a valid nonce falls through to the event's persisted
		// layout (the iframe just renders the normal public page).
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::PREVIEW_NONCE_ACTION ) ) {
			return null;
		}

		$encoded = isset( $_GET['nettertech_events_preview_layout'] )
			? sanitize_text_field( wp_unslash( $_GET['nettertech_events_preview_layout'] ) )
			: '';

		if ( '' === $encoded ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for preview param encoding.
		$decoded = base64_decode( $encoded, true );

		if ( false === $decoded ) {
			return null;
		}

		$config = json_decode( $decoded, true );

		if ( ! is_array( $config ) || ! $this->validate_config( $config ) ) {
			return null;
		}

		return $this->sanitize_config( $config );
	}

	/**
	 * Encode a config for preview URL.
	 *
	 * @param array<string, mixed> $config Layout configuration.
	 * @return string Base64-encoded JSON.
	 */
	public function encode_for_preview( array $config ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for preview param encoding.
		return base64_encode( (string) wp_json_encode( $config ) );
	}
}
