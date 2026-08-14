<?php
/**
 * Template Loader.
 *
 * Loads template parts with fallback through child theme > parent theme > plugin.
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
use NetterTechEvents\TemplateLoader\Contracts\TemplateLoaderInterface;
use NetterTechEvents\TemplateLoader\Contracts\TemplateResolverInterface;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Template loader for WordPress plugins.
 *
 * Loads template parts with fallback through child theme > parent theme > plugin.
 * Theme developers can override plugin templates by placing files in their theme's
 * template directory (configured via TemplateLoaderConfig).
 *
 * Example usage:
 * ```php
 * $config = new TemplateLoaderConfig(
 *     theme_template_directory: 'nettertech-events',
 *     plugin_directory: NTE_DIR,
 * );
 * $loader = new TemplateLoader( $config );
 *
 * // Modern approach (recommended) - data available as $args in template.
 * $loader->get_template_part( 'event', 'single', [ 'event' => $event ] );
 * ```
 *
 * @since 1.0.0
 */
final class TemplateLoader implements TemplateLoaderInterface {

	/**
	 * Expected variables for each template.
	 *
	 * Keys are template paths (relative to templates/), values are arrays of expected variable names.
	 * Used to warn developers about unexpected variables in WP_DEBUG mode.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, array<string>>
	 */
	private const EXPECTED_TEMPLATE_VARS = array(
		// Template parts.
		'parts/event-card.php'               => array( 'occurrence', 'event', 'show_image', 'show_date', 'show_time', 'show_venue', 'show_excerpt', 'show_price', 'image_ratio', 'heading_tag', 'heading_date', 'max_tags', 'prefetched_tags', 'prefetched_ticket_types', 'prefetched_availability' ),
		'parts/occurrence-row.php'           => array( 'occurrence', 'event', 'show_actions' ),
		'parts/pagination.php'               => array( 'current_page', 'total_pages', 'instance_id', 'show_info', 'base_url' ),
		'parts/more-dates.php'               => array( 'occurrence', 'event', 'siblings', 'sibling_count', 'show_view_all' ),
		'parts/event-filters.php'            => array( 'instance_id', 'target_id', 'show_search', 'show_category', 'categories' ),
		'parts/empty-state.php'              => array( 'message', 'context' ),
		// Email templates.
		'emails/customer-confirmation.php'   => array( 'order', 'tickets', 'grouped_tickets', 'venue_logo', 'show_qr_codes', 'cancellation_policy', 'site_name', 'site_url' ),
		'emails/venue-notification.php'      => array( 'order', 'tickets', 'grouped_tickets', 'total_revenue', 'buyer_name', 'buyer_email', 'site_name' ),
		'emails/rsvp-confirmation.php'       => array( 'ticket', 'occurrence', 'event', 'attendee_name', 'venue_logo', 'show_qr_codes', 'cancellation_policy', 'site_name', 'site_url' ),
		'emails/rsvp-venue-notification.php' => array( 'ticket', 'occurrence', 'event', 'attendee_name', 'attendee_email', 'site_name' ),
	);

	/**
	 * Template resolver instance.
	 *
	 * @since 1.0.0
	 *
	 * @var TemplateResolverInterface
	 */
	private TemplateResolverInterface $resolver;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param TemplateLoaderConfig           $config   Configuration object.
	 * @param TemplateResolverInterface|null $resolver Custom resolver instance. Default creates TemplateResolver.
	 */
	public function __construct(
		private readonly TemplateLoaderConfig $config,
		?TemplateResolverInterface $resolver = null,
	) {
		$this->resolver = $resolver ?? new TemplateResolver( $config );
	}

	/**
	 * Get a template part.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $slug Template slug (e.g., 'event').
	 * @param string|null          $name Template variation name (e.g., 'single').
	 * @param array<string, mixed> $args Data for template (available as $args).
	 * @param bool                 $load Whether to load template. Default true.
	 * @return string Template path or empty string if not found.
	 */
	public function get_template_part(
		string $slug,
		?string $name = null,
		array $args = array(),
		bool $load = true
	): string {
		$this->fire_template_actions( $slug, $name );

		$templates = $this->get_template_file_names( $slug, $name );

		$this->log_debug(
			'Resolving template part',
			array(
				'slug'      => $slug,
				'name'      => $name,
				'templates' => $templates,
			)
		);

		return $this->locate_template( $templates, $load, false, $args );
	}

	/**
	 * Locate and optionally load a template.
	 *
	 * @since 1.0.0
	 *
	 * @param string|array<string> $template_names Templates to search for.
	 * @param bool                 $load           Whether to load template. Default false.
	 * @param bool                 $require_once   Use require_once vs require. Default true.
	 * @param array<string, mixed> $args           Data for template.
	 * @return string Template path or empty string if not found.
	 */
	public function locate_template(
		string|array $template_names,
		bool $load = false,
		bool $require_once = true, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.require_onceFound -- Matches WordPress core locate_template() signature.
		array $args = array()
	): string {
		$templates = (array) $template_names;
		$located   = $this->resolver->resolve( $templates );

		if ( null === $located ) {
			$this->log_debug( 'Template not found', array( 'templates' => $templates ) );
			return '';
		}

		$this->log_debug( 'Template located', array( 'path' => $located ) );

		if ( $load ) {
			$this->load_template( $located, $require_once, $args );
		}

		return $located;
	}

	/**
	 * Get template filenames for slug/name combination.
	 *
	 * Returns filenames in order of specificity (most specific first):
	 * - slug-name.php (if name provided)
	 * - slug.php
	 *
	 * @since 1.0.0
	 *
	 * @param string      $slug Template slug.
	 * @param string|null $name Template variation name.
	 * @return array<string> Template filenames to search for.
	 */
	private function get_template_file_names( string $slug, ?string $name ): array {
		$templates = array();

		if ( null !== $name && '' !== $name ) {
			$templates[] = "{$slug}-{$name}.php";
		}

		$templates[] = "{$slug}.php";

		/**
		 * Filters template filename candidates.
		 *
		 * Allows modification of which template files are searched for.
		 * Templates should be ordered from most specific to least specific.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string> $templates Template filenames (most specific first).
		 * @param string        $slug      Template slug.
		 * @param string|null   $name      Template variation name.
		 */
		return apply_filters(
			'nettertech_events_get_template_part',
			$templates,
			$slug,
			$name
		);
	}

	/**
	 * Fire template-related actions.
	 *
	 * Fires both WordPress core and plugin-specific action hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $slug Template slug.
	 * @param string|null $name Template variation name.
	 * @return void
	 */
	private function fire_template_actions( string $slug, ?string $name ): void {
		// WordPress core action for compatibility.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing WordPress core's get_template_part_{$slug} action so themes/plugins that hook into the standard WP template-loading lifecycle continue to work with our TemplateLoader-loaded parts. Not a plugin-owned hook.
		do_action( 'get_template_part_' . $slug, $slug, $name, array() );

		// Plugin-specific action — listeners receive the slug as an argument
		// rather than registering against a dynamic hook name.
		do_action( 'nettertech_events_get_template_part_rendered', $slug, $name );
	}

	/**
	 * Load a template file.
	 *
	 * The template receives a single `$context` parameter (an instance
	 * of TemplateContext, or EmailContext for files under templates/emails/).
	 * Templates reach values via property-style access — e.g. `$context->event`
	 * — eliminating the wide bag of unprefixed locals that the prior
	 * `extract( $args, EXTR_SKIP )` pattern injected (and that WP.org
	 * Plugin Check's NonPrefixedVariableFound rule was flagging).
	 *
	 * @since 1.0.0
	 *
	 * @param string               $file         Template file path.
	 * @param bool                 $require_once Use require_once vs require.
	 * @param array<string, mixed> $args         Data for template.
	 * @return void
	 */
	private function load_template( string $file, bool $require_once, array $args ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.require_onceFound -- Matches WordPress core load_template() signature.
		/**
		 * Filters the arguments passed to the template.
		 *
		 * Allows modification of template data before the template is loaded.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string, mixed> $args Template arguments.
		 * @param string               $file Template file path.
		 */
		$args = apply_filters( 'nettertech_events_template_args', $args, $file );

		/**
		 * Fires before a template is loaded.
		 *
		 * @since 1.0.2
		 *
		 * @param string               $file Template file path.
		 * @param array<string, mixed> $args Template arguments.
		 */
		do_action( 'nettertech_events_before_template_load', $file, $args );

		// Validate template variables in debug mode.
		$this->validate_template_args( $args, $file );

		// Build the typed context the template receives. Email templates get
		// an EmailContext (which adds helpers like accent_color() with the
		// default-fallback baked in); everything else gets TemplateContext.
		$context = self::is_email_template( $file )
			? new EmailContext( $args )
			: new TemplateContext( $args );

		// Include the template file. The template sees `$context` in scope.
		if ( $require_once ) {
			require_once $file;
		} else {
			require $file;
		}

		/**
		 * Fires after a template is loaded.
		 *
		 * @since 1.0.2
		 *
		 * @param string               $file Template file path.
		 * @param array<string, mixed> $args Template arguments.
		 */
		do_action( 'nettertech_events_after_template_load', $file, $args );
	}

	/**
	 * Whether the template path points at an email template.
	 *
	 * Email templates live under `templates/emails/` (in the plugin
	 * directory) or under the theme's email-template subdirectory
	 * (`{theme}/nettertech-events/emails/`). Either path receives the
	 * specialized EmailContext.
	 *
	 * @param string $file Resolved template file path.
	 * @return bool
	 */
	private static function is_email_template( string $file ): bool {
		return false !== strpos( $file, '/emails/' );
	}

	/**
	 * Check if a template exists.
	 *
	 * Useful for conditional logic before attempting to load a template.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $slug Template slug.
	 * @param string|null $name Optional template variation name.
	 * @return bool True if template exists in theme or plugin.
	 */
	public function template_exists( string $slug, ?string $name = null ): bool {
		$templates = $this->get_template_file_names( $slug, $name );

		return null !== $this->resolver->resolve( $templates );
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
		DebugLogger::log( $message . $context_string, 'TemplateLoader' );
	}

	/**
	 * Validate template arguments against expected variables.
	 *
	 * Logs warnings for unexpected variables in WP_DEBUG mode.
	 * This helps developers catch typos and misconfigurations early.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Template arguments.
	 * @param string               $file Template file path.
	 * @return void
	 */
	private function validate_template_args( array $args, string $file ): void {
		// Only validate in debug mode.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Extract relative template path from full file path.
		$template_key = $this->get_template_key( $file );

		if ( null === $template_key || ! isset( self::EXPECTED_TEMPLATE_VARS[ $template_key ] ) ) {
			// Unknown template - allow any variables (theme overrides, etc.).
			return;
		}

		$expected_vars   = self::EXPECTED_TEMPLATE_VARS[ $template_key ];
		$provided_vars   = array_keys( $args );
		$unexpected_vars = array_diff( $provided_vars, $expected_vars );

		if ( ! empty( $unexpected_vars ) ) {
			$this->log_debug(
				'Unexpected template variables',
				array(
					'template'   => $template_key,
					'unexpected' => array_values( $unexpected_vars ),
					'expected'   => $expected_vars,
				)
			);
		}
	}

	/**
	 * Extract template key from full file path.
	 *
	 * Converts absolute paths to relative template keys for lookup.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Full path to template file.
	 * @return string|null Template key (e.g., 'parts/event-card.php') or null if not determinable.
	 */
	private function get_template_key( string $file ): ?string {
		// Try to extract path relative to plugin templates directory.
		$templates_dir = $this->config->plugin_directory . $this->config->plugin_template_directory . '/';

		if ( str_starts_with( $file, $templates_dir ) ) {
			return substr( $file, strlen( $templates_dir ) );
		}

		// Theme override - extract relative path from theme directory.
		$theme_dir = get_stylesheet_directory() . '/' . $this->config->theme_template_directory . '/';
		if ( str_starts_with( $file, $theme_dir ) ) {
			return substr( $file, strlen( $theme_dir ) );
		}

		$parent_theme_dir = get_template_directory() . '/' . $this->config->theme_template_directory . '/';
		if ( str_starts_with( $file, $parent_theme_dir ) ) {
			return substr( $file, strlen( $parent_theme_dir ) );
		}

		return null;
	}
}
