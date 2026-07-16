<?php
/**
 * Archive Settings Section.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles archive display settings section rendering.
 *
 * Configures the events archive page (/events/) with:
 * - Layout style (grid, list, cards)
 * - Column count (1-6)
 * - Events per page limit
 *
 * @since 1.3.0
 * @api
 */
class ArchiveSettingsSection implements SettingsSectionInterface {

	/**
	 * Available layout options.
	 *
	 * @var array<string, string>
	 */
	private const LAYOUT_OPTIONS = array(
		'cards' => 'Cards',
		'grid'  => 'Grid',
		'list'  => 'List',
	);

	/**
	 * Available column options.
	 *
	 * @var array<int, string>
	 */
	private const COLUMN_OPTIONS = array(
		1 => '1 Column',
		2 => '2 Columns',
		3 => '3 Columns',
		4 => '4 Columns',
		5 => '5 Columns',
		6 => '6 Columns',
	);

	/**
	 * Get the section identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'archive-display';
	}

	/**
	 * Get the section title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Archive Display', 'nettertech-events' );
	}

	/**
	 * Render the archive display settings section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render( array $settings ): void {
		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php echo esc_html( $this->get_title() ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e( 'Configure how events are displayed on the archive pages (/events/ and past events).', 'nettertech-events' ); ?>
				</p>

				<table class="form-table">
					<?php
					$this->render_layout_field( $settings );
					$this->render_columns_field( $settings );
					$this->render_limit_field( $settings );
					$this->render_filter_visibility_fields( $settings );
					?>
				</table>
			</div>
		</div>
		<?php
		$this->render_filter_visibility_script();
	}

	/**
	 * Save archive display settings from form input.
	 *
	 * @param array<string, mixed> $input            Raw form input.
	 * @param array<string, mixed> $current_settings Current stored settings.
	 * @return array<string, mixed> Modified settings for this section's fields.
	 */
	public function save( array $input, array $current_settings ): array {
		return $current_settings;
	}

	/**
	 * Get boolean field keys for this section.
	 *
	 * Returned fields are set to false on save when their checkbox is unchecked
	 * (i.e., absent from POST input). All five archive filter visibility fields
	 * must be listed here so an unchecked box correctly persists as false rather
	 * than inheriting the previous stored value.
	 *
	 * @return array<string> List of boolean field keys.
	 */
	public function get_bool_fields(): array {
		return array(
			'archive_show_filters',
			'archive_show_search',
			'archive_show_category',
			'archive_show_tag',
			'archive_show_date_range',
		);
	}

	/**
	 * Render the layout selection field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_layout_field( array $settings ): void {
		$current = $settings['archive_layout'] ?? 'cards';
		?>
		<tr>
			<th scope="row">
				<label for="archive_layout"><?php esc_html_e( 'Layout Style', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<select
					name="nettertech_events_settings[archive_layout]"
					id="archive_layout"
				>
					<?php foreach ( self::LAYOUT_OPTIONS as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'How events appear on archive pages. Cards is recommended for most sites.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the columns selection field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_columns_field( array $settings ): void {
		$current = (int) ( $settings['archive_columns'] ?? 3 );
		?>
		<tr>
			<th scope="row">
				<label for="archive_columns"><?php esc_html_e( 'Columns', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<select
					name="nettertech_events_settings[archive_columns]"
					id="archive_columns"
				>
					<?php foreach ( self::COLUMN_OPTIONS as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'Number of columns in grid/cards layout. Automatically adjusts for smaller screens.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the events limit field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_limit_field( array $settings ): void {
		$current = (int) ( $settings['archive_limit'] ?? 12 );
		?>
		<tr>
			<th scope="row">
				<label for="archive_limit"><?php esc_html_e( 'Events Per Page', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input
					type="number"
					name="nettertech_events_settings[archive_limit]"
					id="archive_limit"
					value="<?php echo esc_attr( (string) $current ); ?>"
					min="1"
					max="100"
					class="small-text"
				>
				<p class="description">
					<?php esc_html_e( 'Number of events to show per page on archive pages.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the archive filter visibility checkboxes.
	 *
	 * Five checkboxes: one master toggle (show_filters) and four sub-controls
	 * indented beneath it. When the master is unchecked the sub-controls are
	 * visually dimmed and their inputs disabled so the state is not accidentally
	 * reset; the JS handler restores the prior checked state on re-check.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_filter_visibility_fields( array $settings ): void {
		// Default to true (on) — back-compat: sites upgrading without stored values keep the current behaviour.
		$show_filters    = isset( $settings['archive_show_filters'] ) ? (bool) $settings['archive_show_filters'] : true;
		$show_search     = isset( $settings['archive_show_search'] ) ? (bool) $settings['archive_show_search'] : true;
		$show_category   = isset( $settings['archive_show_category'] ) ? (bool) $settings['archive_show_category'] : true;
		$show_tag        = isset( $settings['archive_show_tag'] ) ? (bool) $settings['archive_show_tag'] : true;
		$show_date_range = isset( $settings['archive_show_date_range'] ) ? (bool) $settings['archive_show_date_range'] : true;
		?>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Archive Filter Visibility', 'nettertech-events' ); ?>
			</th>
			<td>
				<p class="description" style="margin-bottom: 10px;">
					<?php esc_html_e( 'Controls visibility on the events archive and past events archive pages. Custom pages built with the List block, shortcode, or Beaver Builder module remain unaffected — set those toggles per-instance.', 'nettertech-events' ); ?>
				</p>
				<fieldset>
					<legend class="screen-reader-text">
						<?php esc_html_e( 'Archive Filter Visibility', 'nettertech-events' ); ?>
					</legend>
					<label for="archive_show_filters">
						<input
							type="checkbox"
							name="nettertech_events_settings[archive_show_filters]"
							id="archive_show_filters"
							value="1"
							<?php checked( $show_filters ); ?>
						>
						<?php esc_html_e( 'Show filter bar', 'nettertech-events' ); ?>
					</label>
					<br>
					<table class="nte-settings__sub-checkboxes" style="margin-left: 20px; margin-top: 6px;">
						<tr id="nte-archive-filter-row-search">
							<td>
								<label for="archive_show_search">
									<input
										type="checkbox"
										name="nettertech_events_settings[archive_show_search]"
										id="archive_show_search"
										value="1"
										<?php checked( $show_search ); ?>
									>
									<?php esc_html_e( 'Show search input', 'nettertech-events' ); ?>
								</label>
							</td>
						</tr>
						<tr id="nte-archive-filter-row-category">
							<td>
								<label for="archive_show_category">
									<input
										type="checkbox"
										name="nettertech_events_settings[archive_show_category]"
										id="archive_show_category"
										value="1"
										<?php checked( $show_category ); ?>
									>
									<?php esc_html_e( 'Show category filter', 'nettertech-events' ); ?>
								</label>
							</td>
						</tr>
						<tr id="nte-archive-filter-row-tag">
							<td>
								<label for="archive_show_tag">
									<input
										type="checkbox"
										name="nettertech_events_settings[archive_show_tag]"
										id="archive_show_tag"
										value="1"
										<?php checked( $show_tag ); ?>
									>
									<?php esc_html_e( 'Show tag filter', 'nettertech-events' ); ?>
								</label>
							</td>
						</tr>
						<tr id="nte-archive-filter-row-date-range">
							<td>
								<label for="archive_show_date_range">
									<input
										type="checkbox"
										name="nettertech_events_settings[archive_show_date_range]"
										id="archive_show_date_range"
										value="1"
										<?php checked( $show_date_range ); ?>
									>
									<?php esc_html_e( 'Show date range filter', 'nettertech-events' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Output the inline script that drives master/sub-checkbox dependency.
	 *
	 * Vanilla JS IIFE — no jQuery dependency, no build step required.
	 * Disables + dims sub-checkboxes when the master "Show filter bar" is
	 * unchecked; re-enables on re-check, preserving each sub-checkbox's
	 * previously stored checked state.
	 *
	 * @return void
	 */
	private function render_filter_visibility_script(): void {
		?>
		<script>
		(function() {
			'use strict';

			var SUB_ROW_IDS = [
				'nte-archive-filter-row-search',
				'nte-archive-filter-row-category',
				'nte-archive-filter-row-tag',
				'nte-archive-filter-row-date-range'
			];

			var SUB_INPUT_IDS = [
				'archive_show_search',
				'archive_show_category',
				'archive_show_tag',
				'archive_show_date_range'
			];

			function applyMasterState( enabled ) {
				for ( var i = 0; i < SUB_INPUT_IDS.length; i++ ) {
					var input = document.getElementById( SUB_INPUT_IDS[ i ] );
					var row   = document.getElementById( SUB_ROW_IDS[ i ] );

					if ( ! input ) {
						continue;
					}

					input.disabled       = ! enabled;
					row.style.opacity    = enabled ? '' : '0.4';
				}
			}

			function init() {
				var master = document.getElementById( 'archive_show_filters' );

				if ( ! master ) {
					return;
				}

				// Set initial disable state based on stored value.
				applyMasterState( master.checked );

				master.addEventListener( 'change', function() {
					applyMasterState( this.checked );
				} );
			}

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', init );
			} else {
				init();
			}
		}());
		</script>
		<?php
	}
}
