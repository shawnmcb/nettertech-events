<?php
/**
 * Shadow Post Type registration.
 *
 * Registers a hidden post type that mirrors custom-table events,
 * enabling WordPress admin bar search and Gutenberg link insertion.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Utilities\PathHelper;

/**
 * Registers the nettertech_event shadow post type.
 *
 * Shadow posts contain only title and slug — no content, no meta.
 * Their sole purpose is making custom-table events discoverable via
 * WordPress native search infrastructure (admin bar, Gutenberg link dialog).
 *
 * @since 1.7.0
 */
class ShadowPostType {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'nettertech_event';

	/**
	 * Shadow category taxonomy slug (NTE-002j).
	 *
	 * Hierarchical taxonomy attached to nettertech_event. Populated lazily by
	 * ShadowPostSyncService when events are synced, so SEO plugins,
	 * faceted search plugins, and breadcrumb generators can discover
	 * NTE categories via WP_Query + taxonomy queries.
	 *
	 * @var string
	 */
	public const CATEGORY_TAXONOMY = 'nettertech_event_category';

	/**
	 * Shadow tag taxonomy slug (NTE-002j).
	 *
	 * Flat taxonomy attached to nettertech_event. Populated lazily by
	 * ShadowPostSyncService when events are synced.
	 *
	 * @var string
	 */
	public const TAG_TAXONOMY = 'nettertech_event_tag';

	/**
	 * Register the shadow post type and permalink filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( static::class, 'register_post_type' ) );
		add_action( 'init', array( static::class, 'register_taxonomies' ) );
		add_filter( 'post_type_link', array( static::class, 'filter_permalink' ), 10, 2 );
		add_action( 'template_redirect', array( static::class, 'redirect_to_event' ) );
		add_filter( 'wp_rest_search_handlers', array( static::class, 'add_to_rest_search' ) );
	}

	/**
	 * Register shadow taxonomies for ecosystem compatibility (NTE-002j).
	 *
	 * Registers `nettertech_event_category` (hierarchical) and `nettertech_event_tag`
	 * (flat) on the `nettertech_event` shadow CPT. Both are public + queryable
	 * so SEO plugins, faceted search, and breadcrumb generators can use
	 * them via standard WP taxonomy queries. `show_ui` is false because
	 * the canonical data lives in NTE custom tables; the shadow terms
	 * are read-only from the WP admin perspective.
	 *
	 * @return void
	 */
	public static function register_taxonomies(): void {
		register_taxonomy(
			self::CATEGORY_TAXONOMY,
			self::POST_TYPE,
			array(
				'label'              => __( 'Event Categories', 'nettertech-events' ),
				'hierarchical'       => true,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'show_admin_column'  => false,
				'rewrite'            => false,
			)
		);

		register_taxonomy(
			self::TAG_TAXONOMY,
			self::POST_TYPE,
			array(
				'label'              => __( 'Event Tags', 'nettertech-events' ),
				'hierarchical'       => false,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'show_admin_column'  => false,
				'rewrite'            => false,
			)
		);
	}

	/**
	 * Register the nettertech_event post type.
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Events', 'nettertech-events' ),
				'public'              => false,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => true,
				'rest_base'           => 'nettertech-events',
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'title' ),
				'can_export'          => false,
			)
		);
	}

	/**
	 * Add nettertech_event to the REST API post search handler.
	 *
	 * The core WP_REST_Post_Search_Handler only includes post types with
	 * public=true. Since shadow posts use public=false (to avoid theme
	 * queries), we inject the post type via Reflection on the protected
	 * $subtypes property.
	 *
	 * @param \WP_REST_Search_Handler[] $handlers Search handlers.
	 * @return \WP_REST_Search_Handler[] Modified handlers.
	 */
	public static function add_to_rest_search( array $handlers ): array {
		foreach ( $handlers as $handler ) {
			if ( $handler instanceof \WP_REST_Post_Search_Handler ) {
				$ref      = new \ReflectionProperty( \WP_REST_Search_Handler::class, 'subtypes' );
				$subtypes = $ref->getValue( $handler );
				if ( ! in_array( self::POST_TYPE, $subtypes, true ) ) {
					$subtypes[] = self::POST_TYPE;
					$ref->setValue( $handler, $subtypes );
				}
				break;
			}
		}
		return $handlers;
	}

	/**
	 * Filter shadow post permalinks to point to real event URLs.
	 *
	 * @param string   $link The default permalink.
	 * @param \WP_Post $post The post object.
	 * @return string The real event URL.
	 */
	public static function filter_permalink( string $link, \WP_Post $post ): string {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $link;
		}

		return PathHelper::get_event_url( $post->post_name );
	}

	/**
	 * Redirect direct visits to shadow posts to the real event URL.
	 *
	 * @return void
	 */
	public static function redirect_to_event(): void {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$url = PathHelper::get_event_url( $post->post_name );
		wp_safe_redirect( $url, 301 );
		exit;
	}
}
