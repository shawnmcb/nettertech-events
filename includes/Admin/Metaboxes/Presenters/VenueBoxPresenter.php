<?php
/**
 * Venue Box Presenter (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Admin\Metaboxes\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes\Presenters;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data-prep value object for the venue metabox.
 *
 * Holds the venue name and address (pre-resolved by the caller, including any
 * default-venue fallback for new events) and surfaces translatable labels for
 * the template. Does NOT call `esc_*()` (template's job), does NOT output,
 * does NOT read from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class VenueBoxPresenter {

	/**
	 * Wire the resolved venue values.
	 *
	 * @param string $venue_name    Venue name (may be empty).
	 * @param string $venue_address Venue address (may be empty).
	 */
	public function __construct(
		private readonly string $venue_name,
		private readonly string $venue_address
	) {}

	/**
	 * Venue name input value.
	 *
	 * @return string
	 */
	public function venue_name(): string {
		return $this->venue_name;
	}

	/**
	 * Venue address textarea value.
	 *
	 * @return string
	 */
	public function venue_address(): string {
		return $this->venue_address;
	}

	// ---- Translatable labels (templates emit these escaped) ----

	/**
	 * Metabox title ("Venue").
	 *
	 * @return string
	 */
	public function box_title(): string {
		return __( 'Venue', 'nettertech-events' );
	}

	/**
	 * "Venue Name:" label.
	 *
	 * @return string
	 */
	public function venue_name_label(): string {
		return __( 'Venue Name:', 'nettertech-events' );
	}

	/**
	 * "Address:" label.
	 *
	 * @return string
	 */
	public function venue_address_label(): string {
		return __( 'Address:', 'nettertech-events' );
	}
}
