<?php
/**
 * Template Loader Configuration.
 *
 * Immutable configuration value object for the template loader.
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

/**
 * Immutable configuration for the template loader.
 *
 * This class holds all configuration needed by the template loader
 * and resolver. Pass an instance to the TemplateLoader constructor.
 *
 * @since 1.0.0
 */
class TemplateLoaderConfig {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $theme_template_directory  Directory name in theme for template overrides (e.g., 'nettertech-events').
	 * @param string $plugin_directory          Absolute path to the plugin root directory.
	 * @param string $plugin_template_directory Relative path to templates directory within plugin. Default 'templates'.
	 */
	public function __construct(
		public readonly string $theme_template_directory,
		public readonly string $plugin_directory,
		public readonly string $plugin_template_directory = 'templates',
	) {}

	/**
	 * Get the full path to the plugin's templates directory.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path to plugin templates directory with trailing slash.
	 */
	public function get_plugin_templates_path(): string {
		return trailingslashit( $this->plugin_directory ) . $this->plugin_template_directory;
	}
}
