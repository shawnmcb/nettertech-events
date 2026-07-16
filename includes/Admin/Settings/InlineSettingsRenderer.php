<?php
/**
 * Inline settings section renderer.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\Settings\Presenters\GeneralSettingsPresenter;
use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;
use NetterTechEvents\Services\LayoutService;

/**
 * Renders the inline (not-yet-extracted-to-section-classes) tab content
 * for SettingsPage.
 *
 * Extracted from SettingsPage as part of the god-class decomposition.
 * Each public render method matches the previous private SettingsPage
 * method 1:1 — output byte-identity is preserved so existing snapshot-style
 * tests on SettingsPage::render() pass unchanged.
 *
 * Internal collaborator only; not registered in the DI container.
 *
 * @since 2.2.0
 * @internal
 */
class InlineSettingsRenderer {

	/**
	 * Layout service for the event-layout editor block.
	 *
	 * @var LayoutService
	 */
	private LayoutService $layout_service;

	/**
	 * Constructor.
	 *
	 * @param LayoutService $layout_service Layout service.
	 */
	public function __construct( LayoutService $layout_service ) {
		$this->layout_service = $layout_service;
	}

	/**
	 * Render the General Settings section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render_general_settings_section( array $settings ): void {
		// T4.2 pilot: extracted to presenter + template..
		$presenter = new GeneralSettingsPresenter( $settings );
		include dirname( __DIR__, 3 ) . '/templates/admin/settings/general-section.php';
	}

	/**
	 * Render the Default Venue section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render_default_venue_section( array $settings ): void {
		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php esc_html_e( 'Default Venue', 'nettertech-events' ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e( 'Set a default venue to pre-populate when creating new events. Useful for venues with a fixed location.', 'nettertech-events' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Default Venue', 'nettertech-events' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nettertech_events_settings[default_venue_enabled]" value="1"
									<?php checked( $settings['default_venue_enabled'] ?? false ); ?>>
								<?php esc_html_e( 'Pre-populate venue fields when creating new events', 'nettertech-events' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_venue_name"><?php esc_html_e( 'Venue Name', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="text" name="nettertech_events_settings[default_venue_name]" id="default_venue_name"
									value="<?php echo esc_attr( $settings['default_venue_name'] ?? '' ); ?>"
									class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_venue_address"><?php esc_html_e( 'Venue Address', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<textarea name="nettertech_events_settings[default_venue_address]" id="default_venue_address"
									rows="3" class="large-text"><?php echo esc_textarea( $settings['default_venue_address'] ?? '' ); ?></textarea>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the URL Settings section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render_url_settings_section( array $settings ): void {
		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php esc_html_e( 'URL Settings', 'nettertech-events' ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="events_base_path"><?php esc_html_e( 'Events Base Path', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<code><?php echo esc_html( home_url( '/' ) ); ?></code>
							<input type="text" name="nettertech_events_settings[events_base_path]" id="events_base_path"
									value="<?php echo esc_attr( $settings['events_base_path'] ?? 'events' ); ?>"
									class="regular-text" style="width: 150px;">
							<code>/</code>
							<p class="description">
								<?php esc_html_e( 'Base path for event URLs. Default: events', 'nettertech-events' ); ?>
							</p>
							<?php
							// PathConflictDetector resolved via container (closes SA-19 DI-bypass); registered as singleton in AdminServiceProvider.
							$conflict_detector      = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Services\PathConflictDetector::class );
							$events_conflict_status = $conflict_detector->get_status_for_settings( 'events' );
							if ( $events_conflict_status['has_conflicts'] ) :
								?>
								<p class="nte-path-conflict-warning" style="color: #d63638; margin-top: 8px;">
									<span class="dashicons dashicons-warning" style="color: #d63638;"></span>
									<strong><?php esc_html_e( 'Conflict detected:', 'nettertech-events' ); ?></strong>
									<?php echo esc_html( $events_conflict_status['message'] ); ?>
									<br>
									<em><?php esc_html_e( 'Consider using a different path like "events-calendar" or "calendar".', 'nettertech-events' ); ?></em>
								</p>
							<?php else : ?>
								<p class="nte-path-ok" style="color: #00a32a; margin-top: 8px;">
									<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
									<?php esc_html_e( 'No conflicts detected.', 'nettertech-events' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="events_archive_path"><?php esc_html_e( 'Archive Path', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<code><?php echo esc_html( home_url( '/' ) ); ?></code>
							<input type="text" name="nettertech_events_settings[events_archive_path]" id="events_archive_path"
									value="<?php echo esc_attr( $settings['events_archive_path'] ?? '' ); ?>"
									class="regular-text" style="width: 200px;"
									placeholder="<?php echo esc_attr( ( $settings['events_base_path'] ?? 'events' ) . '/archive' ); ?>">
							<code>/</code>
							<p class="description">
								<?php esc_html_e( 'Path for past events archive. Leave empty to use {base}/archive pattern.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="spaces_base_path"><?php esc_html_e( 'Spaces Base Path', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<code><?php echo esc_html( home_url( '/' ) ); ?></code>
							<input type="text" name="nettertech_events_settings[spaces_base_path]" id="spaces_base_path"
									value="<?php echo esc_attr( $settings['spaces_base_path'] ?? 'spaces' ); ?>"
									class="regular-text" style="width: 150px;">
							<code>/</code>
							<p class="description">
								<?php esc_html_e( 'Base path for space URLs. Default: spaces', 'nettertech-events' ); ?>
							</p>
							<?php
							$spaces_conflict_status = $conflict_detector->get_status_for_settings( 'spaces' );
							if ( $spaces_conflict_status['has_conflicts'] ) :
								?>
								<p class="nte-path-conflict-warning" style="color: #d63638; margin-top: 8px;">
									<span class="dashicons dashicons-warning" style="color: #d63638;"></span>
									<strong><?php esc_html_e( 'Conflict detected:', 'nettertech-events' ); ?></strong>
									<?php echo esc_html( $spaces_conflict_status['message'] ); ?>
									<br>
									<em><?php esc_html_e( 'Consider using a different path like "our-spaces" or "venues".', 'nettertech-events' ); ?></em>
								</p>
							<?php else : ?>
								<p class="nte-path-ok" style="color: #00a32a; margin-top: 8px;">
									<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
									<?php esc_html_e( 'No conflicts detected.', 'nettertech-events' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Event Page Layout section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render_event_layout_section( array $settings ): void {
		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php esc_html_e( 'Event Page Layout', 'nettertech-events' ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e( 'Configure the default layout for single event pages. Drag components to reorder and toggle visibility.', 'nettertech-events' ); ?>
				</p>
				<?php $this->render_layout_editor(); ?>

				<table class="form-table" style="margin-top: 20px;">
					<tr>
						<th scope="row">
							<label for="description_heading"><?php esc_html_e( 'Description Heading', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="text" name="nettertech_events_settings[description_heading]" id="description_heading"
									value="<?php echo esc_attr( $settings['description_heading'] ?? __( 'About This Event', 'nettertech-events' ) ); ?>"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'About This Event', 'nettertech-events' ); ?>">
							<p class="description">
								<?php esc_html_e( 'Heading text above the event description. Leave empty to hide the heading.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="events_archive_intro"><?php esc_html_e( 'Events Page Intro', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<textarea name="nettertech_events_settings[events_archive_intro]" id="events_archive_intro"
									class="large-text"
									rows="2"
									placeholder="<?php esc_attr_e( 'Browse our upcoming events and find something that interests you.', 'nettertech-events' ); ?>"><?php echo esc_textarea( $settings['events_archive_intro'] ?? '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Intro paragraph displayed beneath the Upcoming Events heading on the public events archive. Leave empty to use the default.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_event_start_time"><?php esc_html_e( 'Default Start Time', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="time" name="nettertech_events_settings[default_event_start_time]" id="default_event_start_time"
									value="<?php echo esc_attr( $settings['default_event_start_time'] ?? '19:00' ); ?>">
							<p class="description">
								<?php esc_html_e( 'Pre-fills new events when the author picks a date but leaves the time blank. Default 7:00 PM (typical evening event).', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_event_duration_minutes"><?php esc_html_e( 'Default Duration (minutes)', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="number" name="nettertech_events_settings[default_event_duration_minutes]" id="default_event_duration_minutes"
									value="<?php echo esc_attr( (string) ( $settings['default_event_duration_minutes'] ?? 120 ) ); ?>"
									min="10" step="5" style="width: 80px;">
							<p class="description">
								<?php esc_html_e( 'Default event length when the author leaves the end time blank. Default 120 minutes (2 hours).', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Features section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render_features_section( array $settings ): void {
		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php esc_html_e( 'Features', 'nettertech-events' ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable RSVP', 'nettertech-events' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nettertech_events_settings[enable_rsvp]" value="1"
									<?php checked( $settings['enable_rsvp'] ?? true ); ?>>
								<?php esc_html_e( 'Allow free RSVP registrations for events', 'nettertech-events' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Tickets', 'nettertech-events' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nettertech_events_settings[enable_tickets]" value="1"
									<?php checked( $settings['enable_tickets'] ?? true ); ?>>
								<?php esc_html_e( 'Enable paid ticketing via WooCommerce', 'nettertech-events' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'End Time', 'nettertech-events' ); ?></th>
						<td>
							<label style="display: block; margin-bottom: 8px;">
								<input type="checkbox" name="nettertech_events_settings[show_end_time_by_default]" value="1"
									<?php checked( $settings['show_end_time_by_default'] ?? true ); ?>>
								<?php esc_html_e( 'Show end time fields expanded by default', 'nettertech-events' ); ?>
							</label>
							<label>
								<input type="checkbox" name="nettertech_events_settings[require_end_time]" value="1"
									<?php checked( $settings['require_end_time'] ?? false ); ?>>
								<?php esc_html_e( 'Require end time when visible', 'nettertech-events' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Tickets & Capacity section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render_tickets_capacity_section( array $settings ): void {
		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php esc_html_e( 'Tickets & Capacity', 'nettertech-events' ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e( 'Configure default settings for ticket types. These values are used when creating new ticket types.', 'nettertech-events' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="default_min_per_order"><?php esc_html_e( 'Default Min Per Order', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="number" name="nettertech_events_settings[default_min_per_order]" id="default_min_per_order"
									value="<?php echo esc_attr( $settings['default_min_per_order'] ?? 1 ); ?>"
									min="1" max="100" class="small-text">
							<p class="description">
								<?php esc_html_e( 'Minimum tickets per order for new ticket types.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_max_per_order"><?php esc_html_e( 'Default Max Per Order', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="number" name="nettertech_events_settings[default_max_per_order]" id="default_max_per_order"
									value="<?php echo esc_attr( $settings['default_max_per_order'] ?? 10 ); ?>"
									min="1" max="100" class="small-text">
							<p class="description">
								<?php esc_html_e( 'Maximum tickets per order for new ticket types.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="low_stock_threshold"><?php esc_html_e( 'Low Stock Threshold', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="number" name="nettertech_events_settings[low_stock_threshold]" id="low_stock_threshold"
									value="<?php echo esc_attr( $settings['low_stock_threshold'] ?? 10 ); ?>"
									min="1" max="50" class="small-text">
							<span>%</span>
							<p class="description">
								<?php esc_html_e( 'Show "low stock" indicator when remaining capacity falls below this percentage.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Check-In section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render_checkin_section( array $settings ): void {
		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php esc_html_e( 'Check-In', 'nettertech-events' ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="checkin_counters"><?php esc_html_e( 'Demographic Counters', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<?php
							$counters     = $settings['checkin_counters'] ?? array();
							$counters_str = implode( ', ', $counters );
							?>
							<input type="text" name="nettertech_events_settings[checkin_counters]" id="checkin_counters"
									value="<?php echo esc_attr( $counters_str ); ?>"
									class="regular-text">
							<p class="description">
								<?php esc_html_e( 'Comma-separated counter labels (e.g., BIPOC, Youth, Accessibility). Leave empty to disable counters.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="checkin_completion_email"><?php esc_html_e( 'Completion Email', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="email" name="nettertech_events_settings[checkin_completion_email]" id="checkin_completion_email"
									value="<?php echo esc_attr( $settings['checkin_completion_email'] ?? '' ); ?>"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'reports@example.com', 'nettertech-events' ); ?>">
							<p class="description">
								<?php esc_html_e( 'Email address to receive completed check-in reports. Leave empty to disable.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the layout editor for event page configuration.
	 *
	 * @return void
	 */
	public function render_layout_editor(): void {
		$components     = $this->layout_service->get_components();
		$current_layout = $this->layout_service->get_layout();

		// Enqueue the layout editor assets.
		wp_enqueue_style( 'nettertech-events-layout-editor' );
		wp_enqueue_script( 'nettertech-events-layout-editor' );

		?>
		<div class="nte-layout-editor" id="nte-layout-editor" data-context="settings">
			<input type="hidden" name="nettertech_events_settings[event_layout_order]" id="nte-layout-order"
				value="<?php echo esc_attr( implode( ',', $current_layout['order'] ) ); ?>">
			<input type="hidden" name="nettertech_events_settings[event_layout_visibility]" id="nte-layout-visibility"
				value="<?php echo esc_attr( (string) wp_json_encode( $current_layout['visibility'] ) ); ?>">

			<ul class="nte-layout-editor__list" id="nte-layout-list" role="listbox" aria-label="<?php esc_attr_e( 'Drag to reorder components', 'nettertech-events' ); ?>">
				<?php foreach ( $current_layout['order'] as $component_id ) : ?>
					<?php if ( isset( $components[ $component_id ] ) ) : ?>
						<?php $component = $components[ $component_id ]; ?>
						<?php $is_visible = $current_layout['visibility'][ $component_id ] ?? true; ?>
						<li class="nte-layout-editor__item <?php echo $is_visible ? '' : 'nte-layout-editor__item--hidden'; ?>"
							data-component-id="<?php echo esc_attr( $component_id ); ?>"
							draggable="true"
							role="option"
							aria-selected="<?php echo $is_visible ? 'true' : 'false'; ?>"
							tabindex="0">
							<span class="nte-layout-editor__handle" aria-hidden="true">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<line x1="8" y1="6" x2="16" y2="6"></line>
									<line x1="8" y1="12" x2="16" y2="12"></line>
									<line x1="8" y1="18" x2="16" y2="18"></line>
								</svg>
							</span>
							<label class="nte-layout-editor__toggle">
								<input type="checkbox"
									class="nte-layout-editor__checkbox"
									data-component-id="<?php echo esc_attr( $component_id ); ?>"
									<?php checked( $is_visible ); ?>>
								<span class="nte-layout-editor__toggle-indicator" aria-hidden="true"></span>
							</label>
							<span class="nte-layout-editor__label">
								<?php echo esc_html( $component['label'] ); ?>
							</span>
							<span class="nte-layout-editor__description">
								<?php echo esc_html( $component['description'] ); ?>
							</span>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>

			<div class="nte-layout-editor__actions">
				<button type="button" class="button nte-layout-editor__reset" id="nte-layout-reset">
					<?php esc_html_e( 'Reset to Default', 'nettertech-events' ); ?>
				</button>
			</div>
		</div>
		<?php
	}
}
