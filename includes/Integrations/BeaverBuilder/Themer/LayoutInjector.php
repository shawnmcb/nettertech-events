<?php
/**
 * Beaver Themer layout injection.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder\Themer;

defined( 'ABSPATH' ) || exit;

/**
 * Injects NTE-assigned Beaver Themer layouts into Themer's render pipeline.
 *
 * Hooks the `fl_theme_builder_current_page_layouts` filter exposed by
 * FLThemeBuilderLayoutData (bb-plugin extensions/fl-theme-builder-core).
 * That filter is the only seam Themer offers that lets external code decide
 * which layouts render for the current request — Themer's own location
 * matcher is hardcoded to WordPress conditional tags (is_post_type_archive
 * et al.) which never fire on NTE's custom rewrite rules.
 *
 * Replacement strategy: when an NTE context is active and an assignment
 * exists for a given slot, the injector *replaces* that slot's array.
 * Replacing rather than appending makes the operator's explicit NTE
 * assignment win unconditionally over any Themer-rule-matched layout that
 * would otherwise apply (e.g. an "Entire site" header).
 *
 * Stale-assignment tolerance: stored IDs may point to layouts that were
 * deleted, unpublished, or had their `_fl_theme_layout_type` meta changed
 * post-assignment. {@see self::build_entry()} validates each lookup and
 * returns null on any mismatch; the injector silently skips invalid entries
 * rather than fatalling or rendering broken markup.
 *
 * User-rule scope (v1): NTE assignments are explicit operator overrides and
 * do NOT run through Themer's user-rule filter (FLThemeBuilderRulesUser).
 * If a referenced layout has per-user visibility rules, those are bypassed
 * for the NTE context. Document this on the admin tab when v2 polish lands.
 *
 * @since 1.1.0
 */
class LayoutInjector {

	/**
	 * Hook the injection filter.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'fl_theme_builder_current_page_layouts', array( $this, 'inject_layouts' ) );
	}

	/**
	 * Inject NTE-assigned layouts into the per-request layouts array.
	 *
	 * @param mixed $layouts Layout map keyed by slot ('header', 'footer', ...).
	 *                       Themer passes an array; we tolerate non-array input
	 *                       to be defensive against malformed upstream filters.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function inject_layouts( $layouts ): array {
		$layouts = is_array( $layouts ) ? $layouts : array();

		$context = Context::detect();
		if ( null === $context ) {
			return $layouts;
		}

		$assignments = LayoutAssignments::all();
		$slots       = $assignments[ $context ] ?? array();

		foreach ( LayoutAssignments::SLOTS as $slot ) {
			$layout_id = (int) ( $slots[ $slot ] ?? 0 );
			if ( $layout_id <= 0 ) {
				continue;
			}

			$entry = $this->build_entry( $layout_id, $slot, $context );
			if ( null === $entry ) {
				continue;
			}

			$layouts[ $slot ] = array( $entry );
		}

		return $layouts;
	}

	/**
	 * Hydrate and validate a Themer layout entry for a given slot.
	 *
	 * @param int    $layout_id Themer `fl-theme-layout` post ID.
	 * @param string $slot      Slot identifier (one of LayoutAssignments::SLOTS).
	 * @param string $context   NTE context key (used to label the synthetic location).
	 * @return array<string, mixed>|null Layout entry, or null if the assignment is stale.
	 */
	private function build_entry( int $layout_id, string $slot, string $context ): ?array {
		$post = get_post( $layout_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return null;
		}

		if ( 'fl-theme-layout' !== $post->post_type ) {
			return null;
		}

		if ( 'publish' !== $post->post_status ) {
			return null;
		}

		$type = get_post_meta( $layout_id, '_fl_theme_layout_type', true );
		if ( $type !== $slot ) {
			return null;
		}

		return array(
			'id'        => $layout_id,
			'type'      => $type,
			'locations' => array( 'nettertech_events:' . $context ),
			'users'     => array(),
			'hook'      => false,
			'order'     => false,
		);
	}
}
