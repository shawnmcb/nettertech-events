<?php
/**
 * Custom taxonomy registration.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers custom taxonomies for nettertech events.
 *
 * Uses WordPress taxonomy system with custom object types (event IDs from custom tables).
 * Term relationships are stored in wp_term_relationships using event IDs as object_id.
 *
 * @since 0.8.0
 * @api
 */
class Taxonomies {

	/**
	 * Event category taxonomy name.
	 *
	 * @var string
	 */
	public const EVENT_CATEGORY = 'nettertech_event_category';

	/**
	 * Cache for all categories.
	 *
	 * Keys are 'hide_empty_true' and 'hide_empty_false'.
	 *
	 * @var array<string, array<\WP_Term>>
	 */
	private static array $category_cache = array();

	/**
	 * Register all taxonomies.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
	}

	/**
	 * Register the event category taxonomy.
	 *
	 * @return void
	 */
	public static function register_taxonomies(): void {
		$labels = array(
			'name'              => _x( 'Event Categories', 'taxonomy general name', 'nettertech-events' ),
			'singular_name'     => _x( 'Event Category', 'taxonomy singular name', 'nettertech-events' ),
			'search_items'      => __( 'Search Event Categories', 'nettertech-events' ),
			'all_items'         => __( 'All Event Categories', 'nettertech-events' ),
			'parent_item'       => __( 'Parent Event Category', 'nettertech-events' ),
			'parent_item_colon' => __( 'Parent Event Category:', 'nettertech-events' ),
			'edit_item'         => __( 'Edit Event Category', 'nettertech-events' ),
			'update_item'       => __( 'Update Event Category', 'nettertech-events' ),
			'add_new_item'      => __( 'Add New Event Category', 'nettertech-events' ),
			'new_item_name'     => __( 'New Event Category Name', 'nettertech-events' ),
			'menu_name'         => __( 'Categories', 'nettertech-events' ),
		);

		$args = array(
			'labels'            => $labels,
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => false,
			'show_in_nav_menus' => true,
			'show_tagcloud'     => false,
			'show_in_rest'      => true,
			'rewrite'           => array(
				'slug'       => 'event-category',
				'with_front' => false,
			),
		);

		// Register for a fake object type - we'll manage relationships manually.
		register_taxonomy( self::EVENT_CATEGORY, 'nettertech_event', $args );
	}

	/**
	 * Get categories for an event.
	 *
	 * @deprecated Use CategoryRepository::find_by_event() instead.
	 *
	 * @param int $event_id Event ID.
	 * @return array<\WP_Term>
	 */
	public static function get_event_categories( int $event_id ): array {
		$terms = wp_get_object_terms( $event_id, self::EVENT_CATEGORY );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return $terms;
	}

	/**
	 * Set categories for an event.
	 *
	 * @deprecated Use CategoryRepository::sync_event_categories() instead.
	 *
	 * @param int        $event_id     Event ID.
	 * @param array<int> $category_ids Array of term IDs.
	 * @return bool True on success.
	 */
	public static function set_event_categories( int $event_id, array $category_ids ): bool {
		$result = wp_set_object_terms( $event_id, array_map( 'intval', $category_ids ), self::EVENT_CATEGORY );

		return ! is_wp_error( $result );
	}

	/**
	 * Add a category to an event.
	 *
	 * @deprecated Use CategoryRepository::attach_to_event() instead.
	 *
	 * @param int $event_id    Event ID.
	 * @param int $category_id Term ID.
	 * @return bool True on success.
	 */
	public static function add_event_category( int $event_id, int $category_id ): bool {
		$result = wp_set_object_terms( $event_id, $category_id, self::EVENT_CATEGORY, true );

		return ! is_wp_error( $result );
	}

	/**
	 * Remove a category from an event.
	 *
	 * @deprecated Use CategoryRepository::detach_from_event() instead.
	 *
	 * @param int $event_id    Event ID.
	 * @param int $category_id Term ID.
	 * @return bool True on success.
	 */
	public static function remove_event_category( int $event_id, int $category_id ): bool {
		$result = wp_remove_object_terms( $event_id, $category_id, self::EVENT_CATEGORY );

		return ! is_wp_error( $result );
	}

	/**
	 * Get all event categories.
	 *
	 * Results are cached per-request for performance.
	 *
	 * @param bool $hide_empty Whether to hide categories with no events.
	 * @return array<\WP_Term>
	 */
	public static function get_all_categories( bool $hide_empty = false ): array {
		$cache_key = $hide_empty ? 'hide_empty_true' : 'hide_empty_false';

		if ( isset( self::$category_cache[ $cache_key ] ) ) {
			return self::$category_cache[ $cache_key ];
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::EVENT_CATEGORY,
				'hide_empty' => $hide_empty,
			)
		);

		if ( is_wp_error( $terms ) ) {
			self::$category_cache[ $cache_key ] = array();
			return array();
		}

		self::$category_cache[ $cache_key ] = $terms;

		return $terms;
	}

	/**
	 * Clear the category cache.
	 *
	 * Called when categories are modified.
	 *
	 * @return void
	 */
	public static function clear_category_cache(): void {
		self::$category_cache = array();
	}

	/**
	 * Get category by slug.
	 *
	 * @param string $slug Category slug.
	 * @return \WP_Term|null
	 */
	public static function get_category_by_slug( string $slug ): ?\WP_Term {
		$term = get_term_by( 'slug', $slug, self::EVENT_CATEGORY );

		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Create a new event category.
	 *
	 * @param string $name   Category name.
	 * @param string $slug   Optional slug.
	 * @param int    $parent Optional parent term ID.
	 * @return int|false Term ID on success, false on failure.
	 */
	public static function create_category( string $name, string $slug = '', int $parent = 0 ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- WordPress taxonomy API uses 'parent' terminology.
		$args = array();

		if ( ! empty( $slug ) ) {
			$args['slug'] = $slug;
		}

		if ( $parent > 0 ) {
			$args['parent'] = $parent;
		}

		$result = wp_insert_term( $name, self::EVENT_CATEGORY, $args );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return $result['term_id'];
	}

	/**
	 * One-time sync of categories from custom nettertech_events_categories table to WordPress taxonomy.
	 *
	 * @deprecated No longer called. Category data now lives exclusively in nettertech_events_categories
	 *             and nettertech_events_event_categories tables. The WordPress taxonomy is no longer used
	 *             for event-category assignments.
	 *
	 * @return array{created: int, linked: int, skipped: int}|false Counts on success, false if skipped.
	 */
	public static function sync_from_custom_tables() {
		global $wpdb;

		// Already synced — skip.
		if ( get_option( 'nettertech_events_category_taxonomy_synced' ) ) {
			return false;
		}

		$categories_table       = $wpdb->prefix . 'nettertech_events_categories';
		$event_categories_table = $wpdb->prefix . 'nettertech_events_event_categories';

		// Check if custom table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration check.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $categories_table )
		);

		if ( ! $table_exists ) {
			update_option( 'nettertech_events_category_taxonomy_synced', '1', true );
			return false;
		}

		// Check if custom table has data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration; table name from wpdb prefix.
		$custom_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$categories_table}" );
		if ( 0 === $custom_count ) {
			update_option( 'nettertech_events_category_taxonomy_synced', '1', true );
			return false;
		}

		$counts = array(
			'created' => 0,
			'linked'  => 0,
			'skipped' => 0,
		);

		// Read all custom categories ordered by ID (parents before children).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration.
		$categories = $wpdb->get_results( "SELECT id, name, slug, description, parent_id FROM {$categories_table} ORDER BY id ASC" );

		// Map: custom table ID → WP term ID.
		$id_map = array();

		foreach ( $categories as $cat ) {
			// Check if term already exists by slug.
			$existing = self::get_category_by_slug( $cat->slug );
			if ( $existing ) {
				$id_map[ (int) $cat->id ] = $existing->term_id;
				++$counts['skipped'];
				continue;
			}

			// Resolve parent.
			$wp_parent = 0;
			if ( $cat->parent_id && isset( $id_map[ (int) $cat->parent_id ] ) ) {
				$wp_parent = $id_map[ (int) $cat->parent_id ];
			}

			$args = array(
				'slug' => $cat->slug,
			);
			if ( ! empty( $cat->description ) ) {
				$args['description'] = $cat->description;
			}
			if ( $wp_parent > 0 ) {
				$args['parent'] = $wp_parent;
			}

			$result = wp_insert_term( $cat->name, self::EVENT_CATEGORY, $args );

			if ( is_wp_error( $result ) ) {
				// If term exists (race condition or slug collision), use existing.
				if ( $result->get_error_code() === 'term_exists' ) {
					$id_map[ (int) $cat->id ] = (int) $result->get_error_data();
					++$counts['skipped'];
				}
				continue;
			}

			$id_map[ (int) $cat->id ] = $result['term_id'];
			++$counts['created'];
		}

		// Sync event-category associations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration.
		$associations = $wpdb->get_results( "SELECT event_id, category_id FROM {$event_categories_table} ORDER BY event_id ASC" );

		// Group by event_id.
		$event_terms = array();
		foreach ( $associations as $assoc ) {
			$event_id    = (int) $assoc->event_id;
			$category_id = (int) $assoc->category_id;

			if ( ! isset( $id_map[ $category_id ] ) ) {
				continue;
			}

			if ( ! isset( $event_terms[ $event_id ] ) ) {
				$event_terms[ $event_id ] = array();
			}

			$event_terms[ $event_id ][] = $id_map[ $category_id ];
		}

		// Set taxonomy terms for each event.
		foreach ( $event_terms as $event_id => $term_ids ) {
			$result = wp_set_object_terms( $event_id, $term_ids, self::EVENT_CATEGORY );
			if ( ! is_wp_error( $result ) ) {
				$counts['linked'] += count( $term_ids );
			}
		}

		// Mark as done.
		update_option( 'nettertech_events_category_taxonomy_synced', '1', true );

		self::clear_category_cache();

		return $counts;
	}
}
