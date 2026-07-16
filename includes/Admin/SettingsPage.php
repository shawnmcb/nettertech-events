<?php
/**
 * Settings Page.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\Settings\AdvancedSettingsSection;
use NetterTechEvents\Admin\Settings\ArchiveSettingsSection;
use NetterTechEvents\Admin\Settings\DonationsSettingsSection;
use NetterTechEvents\Admin\Settings\EmailSettingsSection;
use NetterTechEvents\Admin\Settings\ImageDisplaySettingsSection;
use NetterTechEvents\Admin\Settings\InlineSettingsRenderer;
use NetterTechEvents\Admin\Settings\QRSettingsSection;
use NetterTechEvents\Admin\Settings\SettingsSaveHandler;
use NetterTechEvents\Admin\Settings\SettingsSectionInterface;
use NetterTechEvents\Admin\Settings\ThemeColorsSettingsSection;
use NetterTechEvents\Admin\SettingsSanitizer;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Services\EmailConfig;
use NetterTechEvents\Services\LayoutService;

/**
 * Handles settings page rendering and form processing.
 *
 * Extracted from AdminMenu to separate settings management concerns.
 *
 * @since 0.9.0
 */
class SettingsPage {

	/**
	 * Layout service.
	 *
	 * @var LayoutService
	 */
	private LayoutService $layout_service;

	/**
	 * Settings sanitizer.
	 *
	 * @var SettingsSanitizer
	 */
	private SettingsSanitizer $sanitizer;

	/**
	 * Email configuration.
	 *
	 * @var EmailConfig
	 */
	private EmailConfig $email_config;

	/**
	 * Constructor.
	 *
	 * @param LayoutService     $layout_service Layout service.
	 * @param SettingsSanitizer $sanitizer      Settings sanitizer.
	 * @param EmailConfig       $email_config   Email configuration.
	 */
	public function __construct( LayoutService $layout_service, SettingsSanitizer $sanitizer, EmailConfig $email_config ) {
		$this->layout_service = $layout_service;
		$this->sanitizer      = $sanitizer;
		$this->email_config   = $email_config;
	}

	/**
	 * Core tab definitions for settings page.
	 *
	 * @var array<string, string>
	 */
	private const CORE_TABS = array(
		'general'   => 'General',
		'display'   => 'Display',
		'ticketing' => 'Ticketing',
		'qr_codes'  => 'QR Codes',
		'email'     => 'Email & Advanced',
	);

	/**
	 * Get all registered tabs including extensions.
	 *
	 * @return array<string, string> Tab slug => label.
	 */
	private function get_tabs(): array {
		/*
		 * Filter: nettertech_events_settings_tabs
		 * Allows extensions (like nettertech-events-pro) to add custom tabs.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $tabs Tab slug => label pairs.
		 */
		return apply_filters( 'nettertech_events_settings_tabs', self::CORE_TABS );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->maybe_handle_save();

		$settings       = get_option( 'nettertech_events_settings', array() );
		$email_settings = get_option( 'nettertech_events_email_settings', array() );

		$all_tabs   = $this->get_tabs();
		$active_tab = AdminRequest::get_key( 'tab', 'general' );
		if ( ! array_key_exists( $active_tab, $all_tabs ) ) {
			$active_tab = 'general';
		}

		?>
		<a class="nte-skip-link screen-reader-text" href="#nte-main-content">
			<?php esc_html_e( 'Skip to main content', 'nettertech-events' ); ?>
		</a>
		<div id="nte-main-content" class="wrap" tabindex="-1">
			<?php Branding::render_header(); ?>
			<h1><?php esc_html_e( 'NetterTech Events Settings', 'nettertech-events' ); ?></h1>

			<?php $this->render_tabs( $active_tab ); ?>

			<?php $this->render_admin_notices(); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'nettertech_events_settings', 'nettertech_events_settings_nonce' ); ?>
				<input type="hidden" name="nettertech_events_active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

				<div class="nte-settings">
					<?php
					switch ( $active_tab ) {
						case 'display':
							$this->render_event_layout_section( $settings );
							( new ImageDisplaySettingsSection() )->render( $settings );
							// PaletteResolver resolved via container (closes SA-19 DI-bypass); registered as singleton in FrontendServiceProvider.
							( new ThemeColorsSettingsSection( \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Services\PaletteResolver::class ) ) )->render( $settings );
							( new ArchiveSettingsSection() )->render( $settings );
							break;

						case 'ticketing':
							$this->render_tickets_capacity_section( $settings );
							( new DonationsSettingsSection() )->render( $settings );
							$this->render_checkin_section( $settings );
							break;

						case 'qr_codes':
							( new QRSettingsSection() )->render( $settings );
							break;

						case 'email':
							( new EmailSettingsSection( $this->email_config ) )->render( $email_settings );
							( new AdvancedSettingsSection() )->render( $settings );
							break;

						case 'general':
							$this->render_general_settings_section( $settings );
							$this->render_default_venue_section( $settings );
							$this->render_url_settings_section( $settings );
							$this->render_features_section( $settings );
							break;

						default:
							/**
							 * Render custom settings tab content.
							 *
							 * Fires when rendering an extension-registered tab. Listeners
							 * receive the tab slug as an argument and can switch on it
							 * internally — no need to register against a runtime-generated
							 * hook name.
							 *
							 * @since 1.0.2
							 *
							 * @param string               $active_tab Active tab slug.
							 * @param array<string, mixed> $settings   Current settings.
							 */
							do_action( 'nettertech_events_settings_tab_render', $active_tab, $settings );
							break;
					}
					?>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the tab navigation.
	 *
	 * @param string $active_tab Currently active tab slug.
	 * @return void
	 */
	private function render_tabs( string $active_tab ): void {
		$base_url = admin_url( 'admin.php?page=nettertech-events-settings' );
		$all_tabs = $this->get_tabs();
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'nettertech-events' ); ?>">
			<?php foreach ( $all_tabs as $tab_slug => $tab_label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, $base_url ) ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>"
					<?php echo $active_tab === $tab_slug ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $tab_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_admin_notices(): void {
		// Check for success notice from redirect.
		$notice = get_transient( 'nettertech_events_settings_notice' );
		if ( 'settings_updated' === $notice ) {
			delete_transient( 'nettertech_events_settings_notice' );
			add_settings_error(
				'nettertech_events_settings',
				'settings_updated',
				__( 'Settings saved.', 'nettertech-events' ),
				'success'
			);
		}

		// Surface embed-source validation feedback (rejected entries + risk
		// warnings) set by AdvancedSettingsSection::save().
		$embed_notices = get_transient( 'nettertech_events_settings_embed_notices' );
		if ( is_array( $embed_notices ) && ! empty( $embed_notices ) ) {
			delete_transient( 'nettertech_events_settings_embed_notices' );
			foreach ( array_values( $embed_notices ) as $index => $message ) {
				add_settings_error(
					'nettertech_events_settings',
					'embed_notice_' . (int) $index,
					(string) $message,
					'warning'
				);
			}
		}

		settings_errors( 'nettertech_events_settings' );
	}

	/**
	 * Render the General Settings section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_general_settings_section( array $settings ): void {
		$this->inline_renderer()->render_general_settings_section( $settings );
	}

	/**
	 * Render the Default Venue section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_default_venue_section( array $settings ): void {
		$this->inline_renderer()->render_default_venue_section( $settings );
	}

	/**
	 * Render the URL Settings section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_url_settings_section( array $settings ): void {
		$this->inline_renderer()->render_url_settings_section( $settings );
	}

	/**
	 * Render the Event Page Layout section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_event_layout_section( array $settings ): void {
		$this->inline_renderer()->render_event_layout_section( $settings );
	}

	/**
	 * Render the Features section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_features_section( array $settings ): void {
		$this->inline_renderer()->render_features_section( $settings );
	}

	/**
	 * Render the Tickets & Capacity section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_tickets_capacity_section( array $settings ): void {
		$this->inline_renderer()->render_tickets_capacity_section( $settings );
	}

	/**
	 * Render the Check-In section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_checkin_section( array $settings ): void {
		$this->inline_renderer()->render_checkin_section( $settings );
	}

	/**
	 * Get the settings sections registered for a given tab.
	 *
	 * @param string $tab Tab slug.
	 * @return array<SettingsSectionInterface> Sections for the tab.
	 */
	private function get_sections_for_tab( string $tab ): array {
		$sections_by_tab = array(
			'display'   => array(
				new ImageDisplaySettingsSection(),
				new ThemeColorsSettingsSection( \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Services\PaletteResolver::class ) ),
				new ArchiveSettingsSection(),
			),
			'ticketing' => array(
				new DonationsSettingsSection(),
			),
			'qr_codes'  => array(
				new QRSettingsSection(),
			),
			'email'     => array(
				new EmailSettingsSection( $this->email_config ),
				new AdvancedSettingsSection(),
			),
		);

		return $sections_by_tab[ $tab ] ?? array();
	}

	/**
	 * Dispatch the settings-save handler when a POST submission is present.
	 *
	 * Thin facade over the extracted {@see SettingsSaveHandler} which owns the
	 * full nonce-verify -> sanitize -> persist -> redirect pipeline. Kept on
	 * SettingsPage so existing call sites continue to work.
	 *
	 * @return void
	 */
	private function maybe_handle_save(): void {
		$this->save_handler()->maybe_handle_save();
	}

	/**
	 * Save settings (test-facing delegate).
	 *
	 * Reflection-callable shim retained for unit tests that target the
	 * private save pipeline. Production callers go through maybe_handle_save().
	 * The pipeline itself now lives on {@see SettingsSaveHandler}; this method
	 * is a thin redirect that preserves the original cap + nonce re-check
	 * semantics.
	 *
	 * @return void
	 */
	private function save_settings(): void {
		$this->save_handler()->save_settings();
	}

	/**
	 * Lazily build the SettingsSaveHandler collaborator.
	 *
	 * Built on demand so a render-only request doesn't construct the handler.
	 *
	 * @return SettingsSaveHandler
	 */
	private function save_handler(): SettingsSaveHandler {
		return new SettingsSaveHandler(
			$this->sanitizer,
			array_fill_keys( array_keys( self::CORE_TABS ), true ),
			function ( string $tab ): array {
				return $this->get_sections_for_tab( $tab );
			},
			function (): array {
				return $this->get_tabs();
			}
		);
	}

	/**
	 * Lazily build the InlineSettingsRenderer collaborator.
	 *
	 * Built on demand so request paths that bypass these sections (e.g.
	 * the save POST handler) don't construct the renderer.
	 *
	 * @return InlineSettingsRenderer
	 */
	private function inline_renderer(): InlineSettingsRenderer {
		return new InlineSettingsRenderer( $this->layout_service );
	}
}
