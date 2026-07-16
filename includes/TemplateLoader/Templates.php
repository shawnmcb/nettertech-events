<?php
/**
 * Template Facade.
 *
 * Static facade for the TemplateLoader package providing a clean, simple API.
 *
 * @package    NetterTechEvents\TemplateLoader
 * @author     NetterTechEvents
 * @copyright  2025 NetterTechEvents
 * @license    GPL-2.0-or-later
 */

declare(strict_types=1);

namespace NetterTechEvents\TemplateLoader;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;

/**
 * Template facade — injectable service with static backward compatibility.
 *
 * Provides both instance methods (preferred, via DI) and static methods
 * (deprecated, kept for backward compatibility).
 *
 * Example usage (DI — preferred):
 * ```php
 * public function __construct( Templates $templates ) {
 *     $this->templates = $templates;
 * }
 * $this->templates->get_template_part( 'event-card', [ 'event' => $event ] );
 * ```
 *
 * Example usage (static — deprecated):
 * ```php
 * Templates::get_part( 'event-card', [ 'event' => $event ] );
 * ```
 *
 * @since 1.0.0
 * @api
 */
class Templates {

	/**
	 * Singleton loader instance.
	 *
	 * @since 1.0.0
	 *
	 * @var TemplateLoader|null
	 */
	private static ?TemplateLoader $instance = null;

	/**
	 * Configuration instance.
	 *
	 * @since 1.0.0
	 *
	 * @var TemplateLoaderConfig|null
	 */
	private static ?TemplateLoaderConfig $config = null;

	/**
	 * Singleton Templates instance for DI.
	 *
	 * @since 1.6.0
	 *
	 * @var self|null
	 */
	private static ?self $singleton = null;

	/**
	 * Private constructor — use get_instance() for DI.
	 */
	private function __construct() {}

	/**
	 * Initialize the template system.
	 *
	 * Must be called once during plugin initialization, after constants are defined.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$config = new TemplateLoaderConfig(
			theme_template_directory: 'nettertech-events',
			plugin_directory: NETTERTECH_EVENTS_PLUGIN_DIR,
			plugin_template_directory: 'templates',
		);

		self::$instance = new TemplateLoader( self::$config );
	}

	/**
	 * Get the singleton Templates instance for dependency injection.
	 *
	 * Returns the same object that backs the static facade, so static
	 * callers and DI consumers share identical state.
	 *
	 * @since 1.6.0
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$singleton ) {
			self::$singleton = new self();
		}

		return self::$singleton;
	}

	/**
	 * Get the loader instance.
	 *
	 * @since 1.0.0
	 *
	 * @return TemplateLoader
	 * @throws \RuntimeException If init() has not been called.
	 */
	public static function instance(): TemplateLoader {
		if ( null === self::$instance && defined( 'NETTERTECH_EVENTS_PLUGIN_DIR' ) ) {
			// Auto-initialize if constants are available.
			self::init();
		}

		$instance = self::$instance;
		if ( null === $instance ) {
			throw new \RuntimeException(
				'Templates::init() must be called before using template methods.'
			);
		}

		return $instance;
	}

	/**
	 * Load and render a template part from the parts/ subdirectory.
	 *
	 * Searches for templates in order:
	 * 1. Child theme: yourtheme/nettertech-events/parts/{name}.php
	 * 2. Parent theme: yourtheme/nettertech-events/parts/{name}.php
	 * 3. Plugin: nettertech-events/templates/parts/{name}.php
	 *
	 * @since 1.0.0
	 * @deprecated 1.6.0 Use instance method get_template_part() via DI.
	 *
	 * @param string               $name Template name (e.g., 'event-card').
	 * @param array<string, mixed> $args Data passed to template as extracted variables.
	 * @return string Rendered template content, or empty string if not found.
	 */
	public static function get_part( string $name, array $args = array() ): string {
		ob_start();
		self::instance()->get_template_part( 'parts/' . $name, null, $args );
		return (string) ob_get_clean();
	}

	/**
	 * Locate a template part without loading it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Template part name (e.g., 'event-card').
	 * @return string Template path or empty string if not found.
	 */
	public static function locate_part( string $name ): string {
		return self::instance()->get_template_part( 'parts/' . $name, null, array(), false );
	}

	/**
	 * Load a full template.
	 *
	 * Searches for templates in order:
	 * 1. Child theme: yourtheme/nettertech-events/{slug}-{name}.php
	 * 2. Parent theme: yourtheme/nettertech-events/{slug}.php
	 * 3. Plugin: nettertech-events/templates/{slug}-{name}.php
	 *
	 * @since 1.0.0
	 *
	 * @param string               $slug Template slug (e.g., 'single-event').
	 * @param string|null          $name Template variation (e.g., 'featured').
	 * @param array<string, mixed> $args Data passed to template as $args.
	 * @return string Template path or empty string if not found.
	 */
	public static function get_template(
		string $slug,
		?string $name = null,
		array $args = array()
	): string {
		return self::instance()->get_template_part( $slug, $name, $args );
	}

	/**
	 * Check if a template part exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Template part name.
	 * @return bool True if template exists.
	 */
	public static function part_exists( string $name ): bool {
		return self::instance()->template_exists( 'parts/' . $name );
	}

	/**
	 * Check if a template exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $slug Template slug.
	 * @param string|null $name Template variation.
	 * @return bool True if template exists.
	 */
	public static function template_exists( string $slug, ?string $name = null ): bool {
		return self::instance()->template_exists( $slug, $name );
	}

	/**
	 * Locate a template without loading it.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $slug Template slug.
	 * @param string|null $name Template variation.
	 * @return string Template path or empty string if not found.
	 */
	public static function locate( string $slug, ?string $name = null ): string {
		return self::instance()->get_template_part( $slug, $name, array(), false );
	}

	/**
	 * Check if a file exists (cached).
	 *
	 * Utility method for checking arbitrary file paths with caching.
	 * Primarily used by Router for checking plugin template files.
	 *
	 * @since 1.0.0
	 * @deprecated 1.6.0 Use instance method template_file_exists() via DI.
	 *
	 * @param string $path Absolute file path.
	 * @return bool True if file exists.
	 */
	public static function file_exists( string $path ): bool {
		static $cache = array();

		if ( ! isset( $cache[ $path ] ) ) {
			$cache[ $path ] = file_exists( $path );
		}

		return $cache[ $path ];
	}

	/**
	 * Clear all template caches.
	 *
	 * Call this when templates may have changed (e.g., theme switch).
	 *
	 * @since 1.0.0
	 * @deprecated 1.6.0 Use instance method clear_template_cache() via DI.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		// Clear resolver caches (handles all instances).
		TemplateResolver::clear_all_caches();
	}

	/**
	 * Get the theme directory name for template overrides.
	 *
	 * @since 1.0.0
	 *
	 * @return string Theme subdirectory name (e.g., 'nettertech-events').
	 */
	public static function get_theme_directory(): string {
		// @phpstan-ignore-next-line nullsafe.neverNull -- $config is null before init() is called.
		return self::$config?->theme_template_directory ?? 'nettertech-events';
	}

	/**
	 * Get the full path to a template in the plugin.
	 *
	 * Does not check if file exists - just builds the path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $template Template path relative to templates directory.
	 * @return string Full path to template file.
	 */
	public static function plugin_path( string $template ): string {
		return NETTERTECH_EVENTS_PLUGIN_DIR . 'templates/' . ltrim( $template, '/' );
	}

	/**
	 * Get container CSS classes for template wrappers.
	 *
	 * Returns the appropriate container classes, allowing themes to customize
	 * via the 'nettertech_events_container_class' filter.
	 *
	 * Usage in templates:
	 * ```php
	 * <div class="<?php echo esc_attr( Templates::container_class() ); ?>">
	 * ```
	 *
	 * @since 1.0.0
	 *
	 * @param string        $context Context for the container (e.g., 'single', 'archive', 'series').
	 * @param string        $variant Container variant: '' (default), 'wide', 'full'.
	 * @param array<string> $extra   Additional CSS classes to include.
	 * @return string Space-separated CSS class string.
	 */
	public static function container_class(
		string $context = '',
		string $variant = '',
		array $extra = array()
	): string {
		$classes = array( 'nte-container' );

		// Add variant class.
		if ( 'wide' === $variant ) {
			$classes[] = 'nte-container--wide';
		} elseif ( 'full' === $variant ) {
			$classes[] = 'nte-container--full';
		}

		// Add context-specific class.
		if ( $context ) {
			$classes[] = 'nte-container--' . sanitize_html_class( $context );
		}

		// Merge extra classes.
		$classes = array_merge( $classes, array_map( 'sanitize_html_class', $extra ) );

		/**
		 * Filter the container CSS classes.
		 *
		 * Themes can use this to add their own container classes or replace ours entirely.
		 *
		 * Example - Add theme's container class:
		 * ```php
		 * add_filter( 'nettertech_events_container_class', function( $classes, $context ) {
		 *     $classes[] = 'my-theme-container';
		 *     return $classes;
		 * }, 10, 2 );
		 * ```
		 *
		 * Example - Use theme's container instead:
		 * ```php
		 * add_filter( 'nettertech_events_container_class', function( $classes, $context ) {
		 *     return array( 'ast-container' ); // Astra theme
		 * }, 10, 2 );
		 * ```
		 *
		 * @since 1.0.2
		 *
		 * @param array  $classes Array of CSS classes.
		 * @param string $context Context identifier (e.g., 'single', 'archive').
		 * @param string $variant Container variant (e.g., 'wide', 'full').
		 */
		$classes = apply_filters( 'nettertech_events_container_class', $classes, $context, $variant );

		return implode( ' ', array_unique( array_filter( $classes ) ) );
	}

	// =========================================================================
	// Instance methods (preferred — use via DI)
	// =========================================================================

	/**
	 * Load and render a template part from the parts/ subdirectory (instance method).
	 *
	 * Searches for templates in order:
	 * 1. Child theme: yourtheme/nettertech-events/parts/{name}.php
	 * 2. Parent theme: yourtheme/nettertech-events/parts/{name}.php
	 * 3. Plugin: nettertech-events/templates/parts/{name}.php
	 *
	 * @since 1.6.0
	 *
	 * @param string               $name Template name (e.g., 'event-card').
	 * @param array<string, mixed> $args Data passed to template as extracted variables.
	 * @return string Rendered template content, or empty string if not found.
	 */
	public function get_template_part( string $name, array $args = array() ): string {
		ob_start();
		self::instance()->get_template_part( 'parts/' . $name, null, $args );
		return (string) ob_get_clean();
	}

	/**
	 * Check if a file exists — cached (instance method).
	 *
	 * Utility method for checking arbitrary file paths with caching.
	 * Primarily used by Router for checking plugin template files.
	 *
	 * @since 1.6.0
	 *
	 * @param string $path Absolute file path.
	 * @return bool True if file exists.
	 */
	public function template_file_exists( string $path ): bool {
		static $cache = array();

		if ( ! isset( $cache[ $path ] ) ) {
			$cache[ $path ] = file_exists( $path );
		}

		return $cache[ $path ];
	}

	/**
	 * Clear all template caches (instance method).
	 *
	 * Call this when templates may have changed (e.g., theme switch).
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function clear_template_cache(): void {
		TemplateResolver::clear_all_caches();
	}

	/**
	 * Detect common theme frameworks and return compatibility info.
	 *
	 * Useful for themes that want to auto-configure their container classes.
	 *
	 * @since 1.0.0
	 *
	 * @return array{
	 *     theme: string,
	 *     framework: string,
	 *     container_class: string,
	 *     has_theme_json: bool
	 * }
	 */
	public static function detect_theme(): array {
		static $detected = null;

		if ( null !== $detected ) {
			return $detected;
		}

		$theme    = wp_get_theme();
		$template = $theme->get_template();
		$has_json = wp_theme_has_theme_json();

		// Detect common theme frameworks.
		$framework       = 'unknown';
		$container_class = '';

		if ( class_exists( 'FLTheme' ) || 'bb-theme' === $template ) {
			// Beaver Builder Theme.
			$framework       = 'beaver-builder';
			$container_class = 'fl-page-content-wrap';
		} elseif ( 'astra' === $template || class_exists( 'Astra_Theme_Options' ) ) {
			// Astra.
			$framework       = 'astra';
			$container_class = 'ast-container';
		} elseif ( 'generatepress' === $template || function_exists( 'generate_get_option' ) ) {
			// GeneratePress.
			$framework       = 'generatepress';
			$container_class = 'grid-container';
		} elseif ( 'storefront' === $template ) {
			// Storefront (WooCommerce).
			$framework       = 'storefront';
			$container_class = 'site-main';
		} elseif ( $has_json ) {
			// Theme.json based themes use WordPress block alignment.
			$framework = 'theme-json';
		}

		$detected = array(
			'theme'           => $template,
			'framework'       => $framework,
			'container_class' => $container_class,
			'has_theme_json'  => $has_json,
		);

		return $detected;
	}
}
