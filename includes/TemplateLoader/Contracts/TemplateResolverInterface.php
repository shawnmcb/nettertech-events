<?php
/**
 * Template Resolver Interface.
 *
 * Contract for template path resolution.
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
 * Interface for template path resolution.
 *
 * Implementations resolve template filenames to full filesystem paths,
 * searching through prioritized directories (child theme, parent theme, plugin).
 *
 * @since 1.0.0
 * @api
 */
interface TemplateResolverInterface {

	/**
	 * Resolve a template from a list of candidates.
	 *
	 * Searches for templates in priority order: child theme, parent theme, plugin.
	 * Returns the first matching template path found.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string> $templates Template filenames to search for (most specific first).
	 * @return string|null Full path to located template, or null if not found.
	 */
	public function resolve( array $templates ): ?string;

	/**
	 * Get the prioritized list of template search paths.
	 *
	 * Returns paths keyed by priority (lower numbers = higher priority).
	 * Default priorities: 1 = child theme, 10 = parent theme, 100 = plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> Paths keyed by priority.
	 */
	public function get_template_paths(): array;

	/**
	 * Clear the template path cache.
	 *
	 * Call this if templates may have changed during runtime (e.g., after theme switch).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clear_cache(): void;
}
