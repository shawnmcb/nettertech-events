<?php
/**
 * Space page handler.
 *
 * Resolves space data from slug for the public space detail page.
 * Uses direct $wpdb queries (no model class) consistent with VE's
 * DeferredSchema philosophy — business logic lives in extension plugins.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Database\Schema;

/**
 * Resolves space data for public detail pages.
 *
 * @since 1.7.0
 * @api
 */
class SpacePageHandler {

	/**
	 * Resolve a space by slug.
	 *
	 * Returns a stdClass with base space columns or null if not found.
	 *
	 * @param string $slug Space slug.
	 * @return object|null Space data or null.
	 */
	public function resolve( string $slug ): ?object {
		global $wpdb;

		$table = Schema::table( 'spaces' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Public page, no persistent cache needed for single read.
		$space = $wpdb->get_row(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from Schema::table(), not user input.
			$wpdb->prepare(
				"SELECT id, name, slug, tagline, description, featured_image_id, capacity,
						square_footage, amenities, accessibility_features, seating_model, status
				FROM {$table}
				WHERE slug = %s AND status = 'active'",
				$slug
			)
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( ! $space ) {
			return null;
		}

		// Cast numeric fields.
		$space->id                = (int) $space->id;
		$space->featured_image_id = $space->featured_image_id ? (int) $space->featured_image_id : null;
		$space->capacity          = (int) $space->capacity;
		$space->square_footage    = $space->square_footage ? (int) $space->square_footage : null;
		$space->seating_model     = $space->seating_model ?? 'free';

		return $space;
	}

	/**
	 * Decode the accessibility_features JSON field.
	 *
	 * @param object $space Space data from resolve().
	 * @return array<int, array{key: string, count?: int, notes?: string, custom?: bool}>
	 */
	public function get_accessibility_features( object $space ): array {
		if ( empty( $space->accessibility_features ) ) {
			return array();
		}

		$decoded = json_decode( $space->accessibility_features, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['key'] ) && is_string( $entry['key'] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Decode the amenities JSON field.
	 *
	 * @param object $space Space data from resolve().
	 * @return array<string> List of amenity strings.
	 */
	public function get_amenities( object $space ): array {
		if ( empty( $space->amenities ) ) {
			return array();
		}

		$decoded = json_decode( $space->amenities, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
