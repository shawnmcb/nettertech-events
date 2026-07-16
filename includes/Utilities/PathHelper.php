<?php
/**
 * Path helper utility class.
 *
 * @package NetterTechEvents\Utilities
 */

declare(strict_types=1);

namespace NetterTechEvents\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes path and URL generation for events.
 *
 * @since 0.8.0
 * @api
 */
class PathHelper {

	/**
	 * Get the events base path from settings.
	 *
	 * @return string Base path without leading/trailing slashes.
	 */
	public static function get_base_path(): string {
		$base = \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->advanced->events_base_path;
		return self::sanitize_path( $base ) ? self::sanitize_path( $base ) : 'events';
	}

	/**
	 * Get the events archive path from settings.
	 *
	 * @return string Archive path without leading/trailing slashes.
	 */
	public static function get_archive_path(): string {
		$archive = \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->advanced->events_archive_path;

		if ( empty( $archive ) ) {
			return self::get_base_path() . '/archive';
		}

		return self::sanitize_path( $archive );
	}

	/**
	 * Get the full URL for an event.
	 *
	 * @param string $slug Event slug.
	 * @return string Full URL to the event.
	 */
	public static function get_event_url( string $slug ): string {
		return home_url( '/' . self::get_base_path() . '/' . $slug . '/' );
	}

	/**
	 * Get the full URL for an event series.
	 *
	 * @param string $slug Event slug.
	 * @return string Full URL to the event series.
	 */
	public static function get_series_url( string $slug ): string {
		return home_url( '/' . self::get_base_path() . '/' . $slug . '/' );
	}

	/**
	 * Get the full URL for a specific occurrence.
	 *
	 * @param string $event_slug   Event slug.
	 * @param string $datetime_slug Datetime slug (e.g., "2024-01-15-1400").
	 * @return string Full URL to the occurrence.
	 */
	public static function get_occurrence_url( string $event_slug, string $datetime_slug ): string {
		return home_url( '/' . self::get_base_path() . '/' . $event_slug . '/' . $datetime_slug . '/' );
	}

	/**
	 * Get the full URL for the events archive.
	 *
	 * @return string Full URL to the archive.
	 */
	public static function get_archive_url(): string {
		return home_url( '/' . self::get_archive_path() . '/' );
	}

	/**
	 * Get the full URL for the events base (upcoming-events listing).
	 *
	 * @return string Full URL to the base events listing.
	 */
	public static function get_base_url(): string {
		return home_url( '/' . self::get_base_path() . '/' );
	}

	/**
	 * Get the spaces base path from settings.
	 *
	 * Mirror of {@see self::get_base_path()} for the spaces URL space.
	 * Defaults to 'spaces' when unset or invalid after sanitization.
	 *
	 * @since 1.8.0
	 * @return string Base path without leading/trailing slashes.
	 */
	public static function get_spaces_base_path(): string {
		$base      = \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->advanced->spaces_base_path;
		$sanitized = self::sanitize_path( $base );
		return '' !== $sanitized ? $sanitized : 'spaces';
	}

	/**
	 * Get the full URL for a space detail page.
	 *
	 * @since 1.8.0
	 * @param string $slug Space slug.
	 * @return string Full URL to the space.
	 */
	public static function get_space_url( string $slug ): string {
		return home_url( '/' . self::get_spaces_base_path() . '/' . $slug . '/' );
	}

	/**
	 * Sanitize a path segment.
	 *
	 * @param string $path Path to sanitize.
	 * @return string Sanitized path.
	 */
	public static function sanitize_path( string $path ): string {
		// Trim whitespace and slashes.
		$path = trim( $path, " \t\n\r\0\x0B/" );

		// Allow only alphanumeric, hyphens, underscores, and forward slashes.
		$path = preg_replace( '/[^a-zA-Z0-9\-_\/]/', '', $path ) ?? '';

		// Remove consecutive slashes.
		$path = preg_replace( '/\/+/', '/', $path ) ?? '';

		// Remove leading/trailing slashes again after sanitization.
		return trim( $path, '/' );
	}
}
