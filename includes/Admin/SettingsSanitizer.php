<?php
/**
 * Settings sanitizer for admin settings page.
 *
 * Sanitizes form input for plugin settings using a data-driven approach
 * to reduce cyclomatic complexity in save operations.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes plugin settings from form input.
 *
 * @since 0.9.3
 */
final class SettingsSanitizer {

	/**
	 * Settings field configurations.
	 *
	 * Defines sanitization rules for each setting:
	 * - type: The sanitization type (text, int, bool, bounded_int, enum, path)
	 * - default: Default value if not provided
	 * - min/max: Bounds for bounded_int type
	 * - values: Valid values for enum type
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const FIELD_CONFIGS = array(
		// General settings.
		'default_view'                       => array(
			'type'    => 'text',
			'default' => 'month',
		),
		'events_per_page'                    => array(
			'type'    => 'int',
			'default' => 10,
		),
		'timezone'                           => array(
			'type'    => 'text',
			'default' => null, // Will use wp_timezone_string().
		),
		// Default venue settings.
		'default_venue_enabled'              => array(
			'type'    => 'bool',
			'default' => false,
		),
		'default_venue_name'                 => array(
			'type'    => 'text',
			'default' => '',
		),
		'default_venue_address'              => array(
			'type'    => 'textarea',
			'default' => '',
		),
		// Display labels.
		'description_heading'                => array(
			'type'    => 'text',
			'default' => 'About This Event',
		),
		'events_archive_intro'               => array(
			'type'    => 'textarea',
			'default' => '',
		),
		// Feature toggles.
		'enable_rsvp'                        => array(
			'type'    => 'bool',
			'default' => false,
		),
		'enable_tickets'                     => array(
			'type'    => 'bool',
			'default' => false,
		),
		'show_end_time_by_default'           => array(
			'type'    => 'bool',
			'default' => false,
		),
		'require_end_time'                   => array(
			'type'    => 'bool',
			'default' => false,
		),
		// Tickets & Capacity settings.
		'default_min_per_order'              => array(
			'type'    => 'bounded_int',
			'default' => 1,
			'min'     => 1,
			'max'     => 100,
		),
		'default_max_per_order'              => array(
			'type'    => 'bounded_int',
			'default' => 10,
			'min'     => 1,
			'max'     => 100,
		),
		'low_stock_threshold'                => array(
			'type'    => 'bounded_int',
			'default' => 10,
			'min'     => 1,
			'max'     => 50,
		),
		// Donation settings.
		'enable_waitlist'                    => array(
			'type'    => 'bool',
			'default' => true,
		),
		'enable_donations'                   => array(
			'type'    => 'bool',
			'default' => false,
		),
		'donation_cause'                     => array(
			'type'    => 'text',
			'default' => 'Support our venue',
		),
		'roundup_to'                         => array(
			'type'    => 'enum',
			'default' => 'dollar',
			'values'  => array( 'dollar', 'five', 'ten' ),
		),
		'allow_custom_donation'              => array(
			'type'    => 'bool',
			'default' => false,
		),
		'max_donation'                       => array(
			'type'    => 'bounded_int',
			'default' => 100,
			'min'     => 1,
			'max'     => 10000,
		),
		// QR Code settings.
		'qr_scale'                           => array(
			'type'    => 'bounded_int',
			'default' => 5,
			'min'     => 3,
			'max'     => 20,
		),
		'qr_bg_opacity'                      => array(
			'type'    => 'bounded_int',
			'default' => 100,
			'min'     => 0,
			'max'     => 100,
		),
		'qr_default_logo_mode'               => array(
			'type'    => 'enum',
			'default' => 'none',
			'values'  => array( 'none', 'site', 'custom' ),
		),
		'qr_default_logo_id'                 => array(
			'type'    => 'int',
			'default' => 0,
		),
		// Check-in settings.
		'checkin_completion_email'           => array(
			'type'    => 'email',
			'default' => '',
		),
		// Advanced settings.
		'occurrence_horizon'                 => array(
			'type'    => 'bounded_int',
			'default' => 365,
			'min'     => 30,
			'max'     => 730,
		),
		'ical_feed_horizon_days'             => array(
			'type'    => 'bounded_int',
			'default' => 730,
			'min'     => 0,
			'max'     => 3650,
		),
		'ical_feed_static_mode'              => array(
			'type'    => 'bool',
			'default' => false,
		),
		'rate_limit_requests'                => array(
			'type'    => 'bounded_int',
			'default' => 60,
			'min'     => 10,
			'max'     => 1000,
		),
		'rate_limit_window'                  => array(
			'type'    => 'bounded_int',
			'default' => 60,
			'min'     => 10,
			'max'     => 3600,
		),
		'rate_limit_proxy_mode'              => array(
			'type'    => 'enum',
			'default' => 'auto',
			'values'  => array( 'auto', 'direct', 'proxied' ),
		),
		'pending_hold_time'                  => array(
			'type'    => 'bounded_int',
			'default' => 900,
			'min'     => 60,
			'max'     => 3600,
		),
		'category_cache_ttl'                 => array(
			'type'    => 'bounded_int',
			'default' => 3600,
			'min'     => 60,
			'max'     => 86400,
		),
		'activity_log_retention_days'        => array(
			'type'    => 'bounded_int',
			'default' => 90,
			'min'     => 7,
			'max'     => 365,
		),
		'delete_data_on_uninstall'           => array(
			'type'    => 'bool',
			'default' => false,
		),
		'show_frontend_branding'             => array(
			'type'    => 'bool',
			'default' => false,
		),
		// Image display settings.
		'image_aspect_ratio'                 => array(
			'type'    => 'aspect_ratio',
			'default' => '16:9',
		),
		'image_aspect_ratio_custom'          => array(
			'type'    => 'aspect_ratio_custom',
			'default' => '',
		),
		'image_aspect_ratio_single'          => array(
			'type'    => 'aspect_ratio',
			'default' => '',
		),
		'image_aspect_ratio_single_custom'   => array(
			'type'    => 'aspect_ratio_custom',
			'default' => '',
		),
		'image_aspect_ratio_cards'           => array(
			'type'    => 'aspect_ratio',
			'default' => '',
		),
		'image_aspect_ratio_cards_custom'    => array(
			'type'    => 'aspect_ratio_custom',
			'default' => '',
		),
		'image_aspect_ratio_list'            => array(
			'type'    => 'aspect_ratio',
			'default' => '',
		),
		'image_aspect_ratio_list_custom'     => array(
			'type'    => 'aspect_ratio_custom',
			'default' => '',
		),
		'image_aspect_ratio_carousel'        => array(
			'type'    => 'aspect_ratio',
			'default' => '',
		),
		'image_aspect_ratio_carousel_custom' => array(
			'type'    => 'aspect_ratio_custom',
			'default' => '',
		),
		'image_aspect_ratio_calendar'        => array(
			'type'    => 'aspect_ratio',
			'default' => '',
		),
		'image_aspect_ratio_calendar_custom' => array(
			'type'    => 'aspect_ratio_custom',
			'default' => '',
		),
		// Archive display settings.
		'archive_layout'                     => array(
			'type'    => 'enum',
			'default' => 'cards',
			'values'  => array( 'grid', 'list', 'cards' ),
		),
		'archive_columns'                    => array(
			'type'    => 'bounded_int',
			'default' => 3,
			'min'     => 1,
			'max'     => 6,
		),
		'archive_limit'                      => array(
			'type'    => 'bounded_int',
			'default' => 12,
			'min'     => 1,
			'max'     => 100,
		),
		// Archive filter visibility settings (NTE-069).
		'archive_show_filters'               => array(
			'type'    => 'bool',
			'default' => false,
		),
		'archive_show_search'                => array(
			'type'    => 'bool',
			'default' => false,
		),
		'archive_show_category'              => array(
			'type'    => 'bool',
			'default' => false,
		),
		'archive_show_tag'                   => array(
			'type'    => 'bool',
			'default' => false,
		),
		'archive_show_date_range'            => array(
			'type'    => 'bool',
			'default' => false,
		),
	);

	/**
	 * Sanitize all standard settings from input.
	 *
	 * @param array<string, mixed> $input The raw input array.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public function sanitize( array $input ): array {
		$settings = array();

		foreach ( self::FIELD_CONFIGS as $field_name => $config ) {
			$value                   = $input[ $field_name ] ?? null;
			$settings[ $field_name ] = $this->sanitize_field( $value, $config, $field_name );
		}

		return $settings;
	}

	/**
	 * Sanitize a single field based on its configuration.
	 *
	 * @param mixed                $value      The raw value.
	 * @param array<string, mixed> $config     The field configuration.
	 * @param string               $field_name The field name (for special defaults).
	 * @return mixed The sanitized value.
	 */
	private function sanitize_field( mixed $value, array $config, string $field_name ): mixed {
		$default_value = $this->get_default( $config, $field_name );

		return match ( $config['type'] ) {
			'text'        => sanitize_text_field( $value ?? $default_value ),
			'textarea'    => sanitize_textarea_field( $value ?? $default_value ),
			'int'         => absint( $value ?? $default_value ),
			'bool'        => ! empty( $value ),
			'email'       => sanitize_email( $value ?? $default_value ),
			'bounded_int' => $this->sanitize_bounded_int( $value, $config, $default_value ),
			'enum'              => $this->sanitize_enum( $value, $config, $default_value ),
			'aspect_ratio'      => $this->sanitize_aspect_ratio_preset( $value, $default_value ),
			'aspect_ratio_custom' => $this->sanitize_aspect_ratio_custom( $value ),
			default             => $value ?? $default_value,
		};
	}

	/**
	 * Get the default value for a field.
	 *
	 * @param array<string, mixed> $config     The field configuration.
	 * @param string               $field_name The field name.
	 * @return mixed The default value.
	 */
	private function get_default( array $config, string $field_name ): mixed {
		// Special case for timezone - use WordPress default.
		if ( 'timezone' === $field_name ) {
			return wp_timezone_string();
		}

		return $config['default'];
	}

	/**
	 * Sanitize a bounded integer value.
	 *
	 * @param mixed                $value   The raw value.
	 * @param array<string, mixed> $config  The field configuration.
	 * @param mixed                $default_value The default value.
	 * @return int The sanitized bounded integer.
	 */
	private function sanitize_bounded_int( mixed $value, array $config, mixed $default_value ): int {
		$int_value = absint( $value ?? $default_value );
		$min       = $config['min'] ?? 0;
		$max       = $config['max'] ?? PHP_INT_MAX;

		return min( $max, max( $min, $int_value ) );
	}

	/**
	 * Sanitize an enum value.
	 *
	 * @param mixed                $value   The raw value.
	 * @param array<string, mixed> $config  The field configuration.
	 * @param mixed                $default_value The default value.
	 * @return string The sanitized enum value.
	 */
	private function sanitize_enum( mixed $value, array $config, mixed $default_value ): string {
		$allowed = $config['values'] ?? array();

		if ( in_array( $value, $allowed, true ) ) {
			return (string) $value;
		}

		return (string) $default_value;
	}

	/**
	 * Sanitize hex color with fallback.
	 *
	 * @param string|null $value   The raw color value.
	 * @param string      $default_value The default color.
	 * @return string The sanitized hex color.
	 */
	public function sanitize_hex_color( ?string $value, string $default_value = '#000000' ): string {
		$color = sanitize_hex_color( $value ?? '' ) ?? '';

		return ( '' !== $color ) ? $color : $default_value;
	}

	/**
	 * Parse comma-separated donation presets.
	 *
	 * @param string|null $value The raw comma-separated string.
	 * @return array<float> Array of valid donation amounts.
	 */
	public function parse_donation_presets( ?string $value ): array {
		if ( empty( $value ) ) {
			return array( 5.0, 10.0, 25.0 );
		}

		$presets = array();
		$raw     = explode( ',', $value );

		foreach ( $raw as $preset ) {
			$amount = floatval( trim( $preset ) );
			if ( $amount > 0 ) {
				$presets[] = $amount;
			}
		}

		return ! empty( $presets ) ? $presets : array( 5.0, 10.0, 25.0 );
	}

	/**
	 * Parse comma-separated check-in counter labels.
	 *
	 * @param string|null $value The raw comma-separated string.
	 * @return array<string> Array of sanitized counter labels.
	 */
	public function parse_checkin_counters( ?string $value ): array {
		if ( empty( $value ) ) {
			return array();
		}

		$counters = array();
		$raw      = explode( ',', $value );

		foreach ( $raw as $counter ) {
			$label = sanitize_text_field( trim( $counter ) );
			if ( ! empty( $label ) ) {
				$counters[] = $label;
			}
		}

		return $counters;
	}

	/**
	 * Sanitize an aspect ratio preset value.
	 *
	 * @param mixed  $value         The raw value.
	 * @param string $default_value The default value.
	 * @return string The sanitized preset value.
	 */
	private function sanitize_aspect_ratio_preset( mixed $value, string $default_value ): string {
		$allowed = array( '', '16:9', '3:2', '4:3', '1:1', 'original', 'custom' );

		if ( in_array( $value, $allowed, true ) ) {
			return (string) $value;
		}

		return $default_value;
	}

	/**
	 * Sanitize a custom aspect ratio value (e.g., "5:4").
	 *
	 * @param mixed $value The raw value.
	 * @return string The sanitized custom ratio or empty string.
	 */
	private function sanitize_aspect_ratio_custom( mixed $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( (string) $value );

		// Validate format: W:H where W and H are positive integers.
		if ( preg_match( '/^(\d+):(\d+)$/', $value, $matches ) ) {
			$width  = (int) $matches[1];
			$height = (int) $matches[2];

			// Ensure non-zero dimensions.
			if ( $width > 0 && $height > 0 ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Convert aspect ratio setting to CSS value.
	 *
	 * Public method for use by Assets class.
	 *
	 * @param string $preset       The preset value (16:9, 3:2, etc.) or 'custom'.
	 * @param string $custom_value The custom value if preset is 'custom'.
	 * @return string The CSS aspect-ratio value.
	 */
	public function get_aspect_ratio_css( string $preset, string $custom_value = '' ): string {
		if ( '' === $preset ) {
			return '';
		}

		if ( 'original' === $preset ) {
			return 'auto';
		}

		if ( 'custom' === $preset && ! empty( $custom_value ) ) {
			return $this->ratio_to_css( $custom_value );
		}

		// Convert preset to CSS format.
		return $this->ratio_to_css( $preset );
	}

	/**
	 * Convert W:H format to CSS aspect-ratio value.
	 *
	 * @param string $ratio The ratio in W:H format.
	 * @return string The CSS value (e.g., "16 / 9").
	 */
	private function ratio_to_css( string $ratio ): string {
		if ( preg_match( '/^(\d+):(\d+)$/', $ratio, $matches ) ) {
			return $matches[1] . ' / ' . $matches[2];
		}

		return '';
	}
}
