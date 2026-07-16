<?php
/**
 * Settings Section Interface.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for settings sections.
 *
 * Allows decomposition of SettingsPage into smaller, focused sections.
 *
 * @since 1.1.0
 * @api
 */
interface SettingsSectionInterface {

	/**
	 * Render the settings section.
	 *
	 * @param array<string, mixed> $settings Current settings values.
	 * @return void
	 */
	public function render( array $settings ): void;

	/**
	 * Get the section identifier.
	 *
	 * @return string Unique section ID used for HTML attributes.
	 */
	public function get_id(): string;

	/**
	 * Get the section title.
	 *
	 * @return string Translatable section title.
	 */
	public function get_title(): string;

	/**
	 * Save the section's settings from form input.
	 *
	 * Receives the raw POST input and current stored settings,
	 * returns only the fields this section is responsible for.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, mixed> $input            Raw form input.
	 * @param array<string, mixed> $current_settings Current stored settings.
	 * @return array<string, mixed> Modified settings for this section's fields.
	 */
	public function save( array $input, array $current_settings ): array;

	/**
	 * Get the boolean field keys this section renders.
	 *
	 * Used for unchecked-checkbox handling: checkboxes absent from POST
	 * on the active tab should be set to false rather than preserved.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string> List of boolean field keys.
	 */
	public function get_bool_fields(): array;
}
