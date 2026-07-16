<?php
/**
 * Themer Settings admin tab.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder\Themer;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Beaver Themer" tab under Settings → NetterTech Events that maps each
 * NTE virtual context to a Beaver Themer header/footer layout.
 *
 * Plugs into the existing settings page via three extension seams:
 *
 * - `nettertech_events_settings_tabs` filter      : registers the tab.
 * - `nettertech_events_settings_tab_render` action : renders the tab body.
 * - `nettertech_events_settings_tab_save` action   : persists the assignments.
 *
 * The form rides the parent page's nonce + capability check (verified in
 * {@see \NetterTechEvents\Admin\Settings\SettingsSaveHandler::maybe_handle_save()})
 * before the save action fires; this class re-checks the capability inside
 * its own save handler for defense-in-depth.
 *
 * @since 1.1.0
 */
class AdminPage {

	/**
	 * Tab slug as it appears in the URL `tab=` parameter.
	 *
	 * @var string
	 */
	public const TAB_SLUG = 'themer';

	/**
	 * Form field name carrying the assignments array.
	 *
	 * @var string
	 */
	private const FIELD_NAME = 'nettertech_events_themer_assignments';

	/**
	 * Capability required to mutate the assignments option.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Hook the tab into the settings page extension seams.
	 *
	 * Safe to call regardless of FLThemeBuilder presence — the orchestrator
	 * gates this call. We intentionally don't add the tab when Themer is
	 * absent so admins aren't presented with non-functional UI.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'nettertech_events_settings_tabs', array( $this, 'register_tab' ) );
		add_action( 'nettertech_events_settings_tab_render', array( $this, 'render_tab' ), 10, 1 );
		add_action( 'nettertech_events_settings_tab_save', array( $this, 'save_tab' ), 10, 1 );
	}

	/**
	 * Register the "Beaver Themer" tab.
	 *
	 * @param array<string, string> $tabs Existing tab slug => label map.
	 * @return array<string, string>
	 */
	public function register_tab( array $tabs ): array {
		$tabs[ self::TAB_SLUG ] = __( 'Beaver Themer', 'nettertech-events' );

		return $tabs;
	}

	/**
	 * Render the tab body when active.
	 *
	 * @param string $active_tab Active tab slug fired by the parent action.
	 * @return void
	 */
	public function render_tab( string $active_tab ): void {
		if ( self::TAB_SLUG !== $active_tab ) {
			return;
		}

		$assignments    = LayoutAssignments::all();
		$header_choices = $this->layout_choices( 'header' );
		$footer_choices = $this->layout_choices( 'footer' );

		echo '<h2>' . esc_html__( 'Beaver Themer Layouts', 'nettertech-events' ) . '</h2>';
		echo '<p>' . esc_html__(
			'NetterTech Events serves its archive and detail pages via custom URLs that Beaver Themer cannot target through its built-in location rules. Use this tab to assign a Themer header and footer layout to each NTE context. Layouts are listed by their published post status; create them under Beaver Builder → Themer Layouts.',
			'nettertech-events'
		) . '</p>';

		if ( empty( $header_choices ) && empty( $footer_choices ) ) {
			$this->render_no_layouts_notice();
			return;
		}

		echo '<table class="form-table" role="presentation">';
		echo '<tbody>';

		foreach ( Context::ALL_KEYS as $context_key ) {
			$this->render_context_row( $context_key, $assignments[ $context_key ], $header_choices, $footer_choices );
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Persist tab submissions.
	 *
	 * Defense-in-depth: nonce + capability are already verified by the parent
	 * SettingsSaveHandler before this action fires, but we re-check the
	 * capability so this method is safe to call from any future surface.
	 *
	 * @param string $active_tab Active tab slug fired by the parent action.
	 * @return void
	 */
	public function save_tab( string $active_tab ): void {
		if ( self::TAB_SLUG !== $active_tab ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Parent SettingsSaveHandler::maybe_handle_save() verifies nettertech_events_settings_nonce before this action fires.
		$has_field = isset( $_POST[ self::FIELD_NAME ] ) && is_array( $_POST[ self::FIELD_NAME ] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Parent SettingsSaveHandler::maybe_handle_save() verifies nettertech_events_settings_nonce before this action fires.
		$raw = $has_field ? wp_unslash( $_POST[ self::FIELD_NAME ] ) : array();

		LayoutAssignments::save( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Render a single context row with header + footer selects.
	 *
	 * @param string             $context_key    Context key.
	 * @param array<string, int> $current        Currently-assigned IDs for this context.
	 * @param array<int, string> $header_choices Header layout ID => title.
	 * @param array<int, string> $footer_choices Footer layout ID => title.
	 * @return void
	 */
	private function render_context_row(
		string $context_key,
		array $current,
		array $header_choices,
		array $footer_choices
	): void {
		$header_id = sprintf( 'nettertech-events-themer-%s-header', $context_key );
		$footer_id = sprintf( 'nettertech-events-themer-%s-footer', $context_key );

		echo '<tr>';
		echo '<th scope="row">' . esc_html( Context::label( $context_key ) ) . '</th>';
		echo '<td>';

		echo '<p>';
		echo '<label for="' . esc_attr( $header_id ) . '"><strong>' . esc_html__( 'Header layout', 'nettertech-events' ) . '</strong></label><br />';
		$this->render_select(
			$header_id,
			sprintf( '%s[%s][%s]', self::FIELD_NAME, $context_key, LayoutAssignments::SLOT_HEADER ),
			$header_choices,
			(int) ( $current[ LayoutAssignments::SLOT_HEADER ] ?? 0 )
		);
		echo '</p>';

		echo '<p>';
		echo '<label for="' . esc_attr( $footer_id ) . '"><strong>' . esc_html__( 'Footer layout', 'nettertech-events' ) . '</strong></label><br />';
		$this->render_select(
			$footer_id,
			sprintf( '%s[%s][%s]', self::FIELD_NAME, $context_key, LayoutAssignments::SLOT_FOOTER ),
			$footer_choices,
			(int) ( $current[ LayoutAssignments::SLOT_FOOTER ] ?? 0 )
		);
		echo '</p>';

		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Render a single <select> populated from a choice map.
	 *
	 * @param string             $id       DOM id (also used as label target).
	 * @param string             $name     Form field name (bracketed array path).
	 * @param array<int, string> $choices  Layout ID => layout title.
	 * @param int                $selected Currently selected ID.
	 * @return void
	 */
	private function render_select( string $id, string $name, array $choices, int $selected ): void {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="regular-text">';
		echo '<option value="0">' . esc_html__( '— None (fall through) —', 'nettertech-events' ) . '</option>';

		foreach ( $choices as $layout_id => $title ) {
			$attr_selected = ( $layout_id === $selected ) ? ' selected="selected"' : '';
			echo '<option value="' . esc_attr( (string) $layout_id ) . '"' . $attr_selected . '>' . esc_html( $title ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attr_selected is a static literal.
		}

		echo '</select>';
	}

	/**
	 * Render the empty-state notice when no Themer layouts exist yet.
	 *
	 * @return void
	 */
	private function render_no_layouts_notice(): void {
		$layouts_url = admin_url( 'edit.php?post_type=fl-theme-layout' );

		echo '<div class="notice notice-info inline"><p>';
		echo wp_kses(
			sprintf(
				/* translators: %s: anchor opening tag for the Themer Layouts page. */
				__( 'No published Beaver Themer header or footer layouts were found. %1$sCreate a Themer layout%2$s, then return here to assign it.', 'nettertech-events' ),
				'<a href="' . esc_url( $layouts_url ) . '">',
				'</a>'
			),
			array( 'a' => array( 'href' => array() ) )
		);
		echo '</p></div>';
	}

	/**
	 * Build the layout ID => title map for a given Themer layout type.
	 *
	 * @param string $layout_type Themer `_fl_theme_layout_type` meta value ('header' or 'footer').
	 * @return array<int, string>
	 */
	private function layout_choices( string $layout_type ): array {
		$posts = get_posts(
			array(
				'post_type'              => 'fl-theme-layout',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => '_fl_theme_layout_type', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Themer-published layouts are bounded (typically <50 per site).
				'meta_value'             => $layout_type,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);

		if ( ! is_array( $posts ) ) {
			return array();
		}

		$choices = array();
		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof \WP_Post ) ) {
				continue;
			}

			$title                = $post->post_title;
			$choices[ $post->ID ] = '' !== $title ? $title : sprintf( '(#%d)', $post->ID );
		}

		return $choices;
	}
}
