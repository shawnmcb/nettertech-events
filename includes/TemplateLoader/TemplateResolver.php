<?php
/**
 * Template Resolver.
 *
 * Resolves template filenames to full paths with caching.
 *
 * @package    NetterTechEvents\TemplateLoader
 * @author     NetterTechEvents
 * @copyright  2025 NetterTechEvents
 * @license    GPL-2.0-or-later
 *
 * @inspiration Gamajo Template Loader by Gary Jones (https://github.com/GaryJones/Gamajo-Template-Loader)
 */

declare(strict_types=1);

namespace NetterTechEvents\TemplateLoader;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\TemplateLoader\Contracts\TemplateResolverInterface;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Resolves template paths with caching.
 *
 * Searches for templates in priority order:
 * 1. Child theme (if active)
 * 2. Parent theme
 * 3. Plugin templates directory
 *
 * Results are cached per instance to avoid repeated filesystem checks.
 *
 * @since 1.0.0
 */
final class TemplateResolver implements TemplateResolverInterface {

	/**
	 * Cache of resolved template paths.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, string|null>
	 */
	private array $path_cache = array();

	/**
	 * Cache of file_exists() results.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, bool>
	 */
	private array $file_exists_cache = array();

	/**
	 * Whether hooks have been registered.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Instances that need cache clearing on theme switch.
	 *
	 * @since 1.0.0
	 *
	 * @var array<TemplateResolver>
	 */
	private static array $instances = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param TemplateLoaderConfig $config Configuration object.
	 */
	public function __construct(
		private readonly TemplateLoaderConfig $config,
	) {
		$this->register_hooks();
		self::$instances[] = $this;
	}

	/**
	 * Register theme-switch hooks for cache invalidation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'switch_theme', array( self::class, 'clear_all_caches' ) );
		add_action( 'after_switch_theme', array( self::class, 'clear_all_caches' ) );

		self::$hooks_registered = true;
	}

	/**
	 * Clear caches on all resolver instances.
	 *
	 * Called on theme switch to ensure stale paths are not used.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function clear_all_caches(): void {
		foreach ( self::$instances as $instance ) {
			$instance->clear_cache();
		}
	}

	/**
	 * Resolve a template from candidates.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string> $templates Template filenames to search for.
	 * @return string|null Full path to located template, or null if not found.
	 */
	public function resolve( array $templates ): ?string {
		$templates = array_filter( $templates );

		if ( empty( $templates ) ) {
			return null;
		}

		// Use all templates in cache key to avoid collisions.
		// e.g., ['event-single.php', 'event.php'] vs ['event-single.php'] are different lookups.
		$cache_key = implode( '|', $templates );

		if ( array_key_exists( $cache_key, $this->path_cache ) ) {
			$this->log_debug( 'Template resolved from cache', array( 'cache_key' => $cache_key ) );
			return $this->path_cache[ $cache_key ];
		}

		$located = null;
		$paths   = $this->get_template_paths();

		foreach ( $templates as $template ) {
			$template = ltrim( $template, '/' );

			foreach ( $paths as $path ) {
				$full_path = trailingslashit( $path ) . $template;

				if ( $this->file_exists_cached( $full_path ) ) {
					$located = $full_path;
					$this->log_debug( 'Template found', array( 'path' => $full_path ) );
					break 2;
				}
			}
		}

		$this->path_cache[ $cache_key ] = $located;

		if ( null === $located ) {
			$this->log_debug( 'Template not found', array( 'templates' => $templates ) );
		}

		return $located;
	}

	/**
	 * Get prioritized template paths.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> Paths keyed by priority (lower = higher priority).
	 */
	public function get_template_paths(): array {
		$theme_dir = trailingslashit( $this->config->theme_template_directory );

		$paths = array(
			10  => trailingslashit( get_template_directory() ) . $theme_dir,
			100 => $this->config->get_plugin_templates_path(),
		);

		// Add child theme path if active (higher priority than parent).
		if ( get_stylesheet_directory() !== get_template_directory() ) {
			$paths[1] = trailingslashit( get_stylesheet_directory() ) . $theme_dir;
		}

		/**
		 * Filters the template search paths.
		 *
		 * Paths are keyed by priority (lower number = higher priority).
		 * Default: 1 = child theme, 10 = parent theme, 100 = plugin.
		 *
		 * @since 1.0.2
		 *
		 * @param array<int, string>   $paths  Template paths keyed by priority.
		 * @param TemplateLoaderConfig $config Configuration object.
		 */
		$paths = apply_filters( 'nettertech_events_template_paths', $paths, $this->config );

		ksort( $paths, SORT_NUMERIC );

		return array_map( 'trailingslashit', $paths );
	}

	/**
	 * Clear all caches.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->path_cache        = array();
		$this->file_exists_cache = array();
		$this->log_debug( 'Template cache cleared' );
	}

	/**
	 * Check file existence with caching.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path File path to check.
	 * @return bool True if file exists.
	 */
	private function file_exists_cached( string $path ): bool {
		if ( ! isset( $this->file_exists_cache[ $path ] ) ) {
			$this->file_exists_cache[ $path ] = file_exists( $path );
		}

		return $this->file_exists_cache[ $path ];
	}

	/**
	 * Log debug message if WP_DEBUG is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	private function log_debug( string $message, array $context = array() ): void {
		$context_string = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
		DebugLogger::log( $message . $context_string, 'TemplateResolver' );
	}
}
