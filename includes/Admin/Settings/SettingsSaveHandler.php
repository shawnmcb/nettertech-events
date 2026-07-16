<?php
/**
 * Settings save handler.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\SettingsSanitizer;
use NetterTechEvents\Core\Hooks;

/**
 * Handles the POST -> sanitize -> persist -> redirect pipeline for the settings page.
 *
 * Extracted from SettingsPage to isolate the save concern from the render concern.
 * Internal collaborator only; SettingsPage retains its public API.
 *
 * Defense-in-depth: every entry point re-verifies nonce + capability so the
 * collaborator is safe to call from any future caller without wrapping guards.
 *
 * @since 2.2.0
 * @internal
 */
class SettingsSaveHandler {

	/**
	 * Capability required to manage settings.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Boolean fields on the general tab (not yet extracted to sections).
	 *
	 * @var array<string>
	 */
	private const GENERAL_BOOL_FIELDS = array(
		'default_venue_enabled',
		'enable_rsvp',
		'enable_tickets',
		'show_end_time_by_default',
		'require_end_time',
	);

	/**
	 * Core tab slugs (used to detect extension-registered tabs).
	 *
	 * @var array<string, true>
	 */
	private array $core_tab_slugs;

	/**
	 * Settings sanitizer.
	 *
	 * @var SettingsSanitizer
	 */
	private SettingsSanitizer $sanitizer;

	/**
	 * Resolver for the sections registered to a given tab.
	 *
	 * Signature: `function(string $tab): array<SettingsSectionInterface>`.
	 *
	 * @var callable
	 */
	private $sections_for_tab;

	/**
	 * Resolver for the set of all valid (core + extension) tab slugs.
	 *
	 * Signature: `function(): array<string, string>` (slug => label).
	 *
	 * @var callable
	 */
	private $tabs_resolver;

	/**
	 * Constructor.
	 *
	 * @param SettingsSanitizer   $sanitizer        Settings sanitizer.
	 * @param array<string, true> $core_tab_slugs   Map of core tab slugs (slug => true).
	 * @param callable            $sections_for_tab Resolver returning sections for a tab.
	 * @param callable            $tabs_resolver    Resolver returning all valid tabs.
	 */
	public function __construct(
		SettingsSanitizer $sanitizer,
		array $core_tab_slugs,
		callable $sections_for_tab,
		callable $tabs_resolver
	) {
		$this->sanitizer        = $sanitizer;
		$this->core_tab_slugs   = $core_tab_slugs;
		$this->sections_for_tab = $sections_for_tab;
		$this->tabs_resolver    = $tabs_resolver;
	}

	/**
	 * Dispatch the settings-save handler when a POST submission is present.
	 *
	 * Sequential-guard structure: each precondition is its own
	 * `if ( ! cond ) return;` so there is no short-circuit-evaluation
	 * ambiguity about which gate ran. PHPCS sees the nonce verification on
	 * its own line, so the $_POST read in save_settings() carries no
	 * suppression.
	 *
	 * @return void
	 */
	public function maybe_handle_save(): void {
		if ( ! isset( $_POST['nettertech_events_settings_nonce'] ) ) {
			return;
		}

		// Capability FIRST, then nonce — project standard (cheapest rejection
		// for unauthorized callers, and consistent with save_settings()).
		// Audit 2026-06-10 CSRF-1.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$nonce_value = sanitize_text_field( wp_unslash( $_POST['nettertech_events_settings_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce_value, 'nettertech_events_settings' ) ) {
			return;
		}

		$this->save_settings();
	}

	/**
	 * Save settings.
	 *
	 * Orchestrates save by delegating to tab-specific sections and handling
	 * standard field sanitization, boolean checkbox handling, and path processing.
	 *
	 * Defense-in-depth: the public entry point maybe_handle_save() already
	 * verifies nonce + capability, but this method re-checks both via
	 * check_admin_referer + current_user_can so the function is safe to
	 * call from any future caller without the wrapping guards.
	 *
	 * @internal Visibility is `public` only so SettingsPage can delegate to
	 *           this method as part of the god-class decomposition; treat as
	 *           if `private` for production callers — go through
	 *           maybe_handle_save() instead.
	 * @return void
	 */
	public function save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! check_admin_referer( 'nettertech_events_settings', 'nettertech_events_settings_nonce' ) ) {
			return;
		}

		// Read + boundary-sanitize the settings array at the $_POST boundary.
		// map_deep( sanitize_textarea_field ) applies per-leaf sanitization
		// to satisfy WordPress.Security.ValidatedSanitizedInput; type-specific
		// tightening (int/bool/hex/enum/path) is applied downstream by
		// SettingsSanitizer. sanitize_textarea_field preserves the line
		// breaks that legitimate textareas need (e.g. default_venue_address).
		$input = ( isset( $_POST['nettertech_events_settings'] ) && is_array( $_POST['nettertech_events_settings'] ) )
			? map_deep( wp_unslash( $_POST['nettertech_events_settings'] ), 'sanitize_textarea_field' )
			: array();

		// Email settings live in a separate option key. Extract them at the
		// nonce-verified boundary and pipe them through to EmailSettingsSection
		// under a namespaced $input key so that section needs no $_POST access.
		if ( isset( $_POST['nettertech_events_email_settings'] ) && is_array( $_POST['nettertech_events_email_settings'] ) ) {
			$input['_email_settings_post'] = map_deep( wp_unslash( $_POST['nettertech_events_email_settings'] ), 'sanitize_textarea_field' );
		}

		$current_settings = get_option( 'nettertech_events_settings', array() );

		// Identify active tab once — only fields on this tab should be updated.
		$active_tab = isset( $_POST['nettertech_events_active_tab'] )
			? sanitize_key( wp_unslash( $_POST['nettertech_events_active_tab'] ) )
			: 'general';

		$settings = $this->build_settings( $input, $current_settings, $active_tab );

		update_option( 'nettertech_events_settings', $settings, false );

		$changed_keys = array();
		foreach ( $settings as $key => $value ) {
			if ( ! array_key_exists( $key, $current_settings ) || $current_settings[ $key ] !== $value ) {
				$changed_keys[] = $key;
			}
		}

		if ( ! empty( $changed_keys ) ) {
			/**
			 * Fires after plugin settings are updated.
			 *
			 * @since 1.1.2
			 *
			 * @param array<string> $changed_keys Top-level setting keys whose values changed.
			 */
			do_action( Hooks::SETTINGS_UPDATED, $changed_keys );
		}

		$this->fire_extension_save_action( $active_tab );
		$this->redirect_after_save( $active_tab );
	}

	/**
	 * Build the merged settings array from input, sections, and path processing.
	 *
	 * @param array<string, mixed> $input            Raw form input.
	 * @param array<string, mixed> $current_settings Previously stored settings.
	 * @param string               $active_tab       Active tab slug.
	 * @return array<string, mixed> Final settings to persist.
	 */
	private function build_settings( array $input, array $current_settings, string $active_tab ): array {
		$settings = $this->merge_sanitized_fields( $input, $current_settings, $active_tab );

		$paths_changed = $this->process_path_settings( $input, $settings, $current_settings, $active_tab );
		if ( $paths_changed ) {
			update_option( 'nettertech_events_flush_rewrite_rules', true );
		}

		foreach ( $this->resolve_sections( $active_tab ) as $section ) {
			$settings = $section->save( $input, $settings );
		}

		return $settings;
	}

	/**
	 * Fire extension save action for non-core tabs.
	 *
	 * @param string $active_tab Active tab slug.
	 * @return void
	 */
	private function fire_extension_save_action( string $active_tab ): void {
		if ( array_key_exists( $active_tab, $this->core_tab_slugs ) ) {
			return;
		}

		/**
		 * Save extension tab settings.
		 *
		 * Fires when a non-core (extension-registered) settings tab is saved.
		 * Listeners receive the tab slug as an argument.
		 *
		 * @since 1.0.2
		 *
		 * @param string $active_tab The active tab slug.
		 */
		do_action( 'nettertech_events_settings_tab_save', $active_tab );
	}

	/**
	 * Sanitize input fields and merge with existing settings.
	 *
	 * Handles standard field sanitization and unchecked checkbox detection
	 * by combining section-declared bool fields with general tab bools.
	 *
	 * @param array<string, mixed> $input      Raw form input.
	 * @param array<string, mixed> $settings   Current settings to merge into.
	 * @param string               $active_tab Active tab slug.
	 * @return array<string, mixed> Merged settings.
	 */
	private function merge_sanitized_fields( array $input, array $settings, string $active_tab ): array {
		$sanitized = $this->sanitizer->sanitize( $input );

		// Collect bool fields from sections on the active tab.
		$active_bool_fields = array();
		foreach ( $this->resolve_sections( $active_tab ) as $section ) {
			$active_bool_fields = array_merge( $active_bool_fields, $section->get_bool_fields() );
		}

		// General tab bools are not yet in sections.
		if ( 'general' === $active_tab ) {
			$active_bool_fields = array_merge( $active_bool_fields, self::GENERAL_BOOL_FIELDS );
		}

		foreach ( $sanitized as $key => $value ) {
			if ( array_key_exists( $key, $input ) ) {
				$settings[ $key ] = $value;
			} elseif ( in_array( $key, $active_bool_fields, true ) ) {
				$settings[ $key ] = false;
			}
		}

		return $settings;
	}

	/**
	 * Process path settings on the general tab.
	 *
	 * @param array<string, mixed> $input            Raw form input.
	 * @param array<string, mixed> $settings         Settings array to modify (by reference).
	 * @param array<string, mixed> $current_settings Previously stored settings.
	 * @param string               $active_tab       Active tab slug.
	 * @return bool Whether paths changed.
	 */
	private function process_path_settings( array $input, array &$settings, array $current_settings, string $active_tab ): bool {
		if ( 'general' !== $active_tab ) {
			return false;
		}

		$events_base_path    = \NetterTechEvents\Utilities\PathHelper::sanitize_path( $input['events_base_path'] ?? 'events' );
		$events_archive_path = \NetterTechEvents\Utilities\PathHelper::sanitize_path( $input['events_archive_path'] ?? '' );
		$spaces_base_path    = \NetterTechEvents\Utilities\PathHelper::sanitize_path( $input['spaces_base_path'] ?? 'spaces' );

		$settings['events_base_path']    = ! empty( $events_base_path ) ? $events_base_path : 'events';
		$settings['events_archive_path'] = $events_archive_path;
		$settings['spaces_base_path']    = ! empty( $spaces_base_path ) ? $spaces_base_path : 'spaces';

		return ( ( $current_settings['events_base_path'] ?? 'events' ) !== $settings['events_base_path'] )
				|| ( ( $current_settings['events_archive_path'] ?? '' ) !== $settings['events_archive_path'] )
				|| ( ( $current_settings['spaces_base_path'] ?? 'spaces' ) !== $settings['spaces_base_path'] );
	}

	/**
	 * Redirect back to the active tab after saving.
	 *
	 * @param string $active_tab Active tab slug.
	 * @return void
	 */
	private function redirect_after_save( string $active_tab ): void {
		add_settings_error(
			'nettertech_events_settings',
			'settings_updated',
			__( 'Settings saved.', 'nettertech-events' ),
			'success'
		);

		if ( ! array_key_exists( $active_tab, $this->resolve_tabs() ) ) {
			$active_tab = 'general';
		}

		set_transient( 'nettertech_events_settings_notice', 'settings_updated', 30 );

		$redirect_url = add_query_arg(
			array(
				'page' => 'nettertech-events-settings',
				'tab'  => $active_tab,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Resolve sections for a tab via the injected callable.
	 *
	 * @param string $tab Tab slug.
	 * @return array<SettingsSectionInterface>
	 */
	private function resolve_sections( string $tab ): array {
		$resolver = $this->sections_for_tab;
		$result   = $resolver( $tab );

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Resolve the set of valid tabs via the injected callable.
	 *
	 * @return array<string, string>
	 */
	private function resolve_tabs(): array {
		$resolver = $this->tabs_resolver;
		$result   = $resolver();

		return is_array( $result ) ? $result : array();
	}
}
