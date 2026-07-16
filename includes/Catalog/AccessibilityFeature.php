<?php
/**
 * Accessibility feature catalog.
 *
 * @package NetterTechEvents\Catalog
 */

declare(strict_types=1);

namespace NetterTechEvents\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Curated catalog of common venue accessibility features.
 *
 * The 18 preset keys cover the most widely used physical and program
 * accessibility provisions across performance, conference, and gallery
 * venues. Custom (non-preset) features are supported on space records
 * via the `custom: true` flag in the persisted JSON.
 *
 * @since 3.10.0
 * @api
 */
final class AccessibilityFeature {

	public const WHEELCHAIR_SEATING     = 'wheelchair_seating';
	public const COMPANION_SEATING      = 'companion_seating';
	public const TRANSFER_AISLE_SEATS   = 'transfer_aisle_seats';
	public const STEP_FREE_ACCESS       = 'step_free_access';
	public const ACCESSIBLE_STAGE       = 'accessible_stage';
	public const ACCESSIBLE_RESTROOMS   = 'accessible_restrooms';
	public const ACCESSIBLE_PARKING     = 'accessible_parking';
	public const HEARING_LOOP           = 'hearing_loop';
	public const ASSISTED_LISTENING     = 'assisted_listening';
	public const ASL_INTERPRETER        = 'asl_interpreter';
	public const CAPTIONING_SUPPORT     = 'captioning_support';
	public const AUDIO_DESCRIPTION      = 'audio_description';
	public const SENSORY_FRIENDLY_AREA  = 'sensory_friendly_area';
	public const QUIET_ROOM             = 'quiet_room';
	public const LARGE_PRINT_MATERIALS  = 'large_print_materials';
	public const SERVICE_ANIMAL_WELCOME = 'service_animal_welcome';
	public const STROBE_FLASH_WARNINGS  = 'strobe_flash_warnings';
	public const SCENT_REDUCED_POLICY   = 'scent_reduced_policy';

	/**
	 * Get the labelled preset catalog.
	 *
	 * Returns translated labels at call time so admin UI matches site locale.
	 *
	 * @return array<string, string> Map of preset key to label.
	 */
	public static function presets(): array {
		return array(
			self::WHEELCHAIR_SEATING     => __( 'Wheelchair-accessible seating', 'nettertech-events' ),
			self::COMPANION_SEATING      => __( 'Companion seating', 'nettertech-events' ),
			self::TRANSFER_AISLE_SEATS   => __( 'Transfer-accessible aisle seats', 'nettertech-events' ),
			self::STEP_FREE_ACCESS       => __( 'Step-free access', 'nettertech-events' ),
			self::ACCESSIBLE_STAGE       => __( 'Accessible stage / performer access', 'nettertech-events' ),
			self::ACCESSIBLE_RESTROOMS   => __( 'Accessible restrooms', 'nettertech-events' ),
			self::ACCESSIBLE_PARKING     => __( 'Accessible parking proximity', 'nettertech-events' ),
			self::HEARING_LOOP           => __( 'Hearing loop (induction loop)', 'nettertech-events' ),
			self::ASSISTED_LISTENING     => __( 'Assisted listening devices (ALDs)', 'nettertech-events' ),
			self::ASL_INTERPRETER        => __( 'ASL interpreter positioning', 'nettertech-events' ),
			self::CAPTIONING_SUPPORT     => __( 'Captioning support', 'nettertech-events' ),
			self::AUDIO_DESCRIPTION      => __( 'Audio description support', 'nettertech-events' ),
			self::SENSORY_FRIENDLY_AREA  => __( 'Sensory-friendly seating area', 'nettertech-events' ),
			self::QUIET_ROOM             => __( 'Quiet / decompression room', 'nettertech-events' ),
			self::LARGE_PRINT_MATERIALS  => __( 'Large-print materials', 'nettertech-events' ),
			self::SERVICE_ANIMAL_WELCOME => __( 'Service animal welcome', 'nettertech-events' ),
			self::STROBE_FLASH_WARNINGS  => __( 'Strobe / flash warnings posted', 'nettertech-events' ),
			self::SCENT_REDUCED_POLICY   => __( 'Scent-reduced policy', 'nettertech-events' ),
		);
	}

	/**
	 * Get the list of valid preset keys.
	 *
	 * @return array<string>
	 */
	public static function preset_keys(): array {
		return array_keys( self::presets() );
	}

	/**
	 * Check whether a key is a recognised preset.
	 *
	 * @param string $key Feature key.
	 * @return bool
	 */
	public static function is_preset( string $key ): bool {
		return in_array( $key, self::preset_keys(), true );
	}

	/**
	 * Look up a preset label, returning the raw key as fallback for custom entries.
	 *
	 * @param string $key Feature key.
	 * @return string
	 */
	public static function label_for( string $key ): string {
		return self::presets()[ $key ] ?? $key;
	}
}
