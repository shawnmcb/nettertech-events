<?php
/**
 * Beaver Themer integration orchestrator.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder\Themer;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the NetterTech Events ↔ Beaver Themer integration.
 *
 * Beaver Themer's location matcher (FLThemeBuilderRulesLocation) is keyed on
 * WordPress conditional tags (is_post_type_archive, is_singular, ...) which
 * never fire on NTE's virtual URLs because the shadow `nte_event` CPT is
 * registered with `public=false`, `has_archive=false`. Themer exposes one
 * supported extension seam — the `fl_theme_builder_current_page_layouts`
 * filter — and this class wires NTE into that seam so admins can assign any
 * published Themer layout to any of NTE's virtual contexts (see Context).
 *
 * Subsystems are wired in init() and gated on FLThemeBuilder being loaded:
 *
 * - LayoutAssignments / AdminPage : option-backed dropdowns under Settings →
 *   Beaver Themer mapping each {@see Context} key to a Themer layout post ID.
 * - LayoutInjector                : hooks `fl_theme_builder_current_page_layouts`
 *   to inject the chosen layouts into Themer's render pipeline.
 * - FieldConnections              : registers FLPageData groups so Themer
 *   layouts targeting NTE contexts can bind to event/space data.
 *
 * @since 1.1.0
 */
class ThemerIntegration {

	/**
	 * Initialize the integration.
	 *
	 * Hooks subsystems on the `init` action so they run before Themer evaluates
	 * the current page (which happens during template rendering, after `wp`).
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! self::is_themer_active() ) {
			return;
		}

		( new AdminPage() )->init();
		( new LayoutInjector() )->init();
		( new FieldConnections() )->init();
	}

	/**
	 * Whether Beaver Themer is loaded.
	 *
	 * The marker class `FLThemeBuilder` is registered by bb-theme-builder on
	 * plugin load. Beaver Builder itself (`FLBuilder`) is a prerequisite of
	 * Themer, so this check implicitly covers both.
	 *
	 * @return bool
	 */
	public static function is_themer_active(): bool {
		return class_exists( 'FLThemeBuilder' );
	}
}
