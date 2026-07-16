<?php
/**
 * Template Loader Interface.
 *
 * Contract for template loading with theme override support.
 *
 * @package    NetterTechEvents\TemplateLoader\Contracts
 * @author     NetterTechEvents
 * @copyright  2025 NetterTechEvents
 * @license    GPL-2.0-or-later
 */

declare(strict_types=1);

namespace NetterTechEvents\TemplateLoader\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for template loaders.
 *
 * Provides methods for loading template parts with fallback through
 * child theme > parent theme > plugin directories.
 *
 * @since 1.0.0
 * @api
 */
interface TemplateLoaderInterface {

	/**
	 * Retrieve and optionally load a template part.
	 *
	 * Works like WordPress's get_template_part() but with plugin fallback.
	 * Searches: child theme > parent theme > plugin templates directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $slug Template slug (e.g., 'event' for event.php).
	 * @param string|null          $name Optional template variation name (e.g., 'single' for event-single.php).
	 * @param array<string, mixed> $args Data to pass to the template. Available as $args in template.
	 * @param bool                 $load Whether to load the template. Default true.
	 * @return string Path to located template, or empty string if not found.
	 */
	public function get_template_part(
		string $slug,
		?string $name = null,
		array $args = array(),
		bool $load = true
	): string;

	/**
	 * Locate a template file from a list of candidates.
	 *
	 * Lower-level method than get_template_part(). Searches for the first
	 * matching template from the provided list.
	 *
	 * @since 1.0.0
	 *
	 * @param string|array<string> $template_names Template file(s) to search for, in order of preference.
	 * @param bool                 $load           Whether to load the template if found. Default false.
	 * @param bool                 $require_once   Whether to use require_once vs require. Default true.
	 * @param array<string, mixed> $args           Data to pass to the template.
	 * @return string Path to located template, or empty string if not found.
	 */
	public function locate_template(
		string|array $template_names,
		bool $load = false,
		bool $require_once = true, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.require_onceFound -- Matches WordPress core locate_template() signature.
		array $args = array()
	): string;

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
	public function template_exists( string $slug, ?string $name = null ): bool;
}
