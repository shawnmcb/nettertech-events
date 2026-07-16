<?php
/**
 * Save-First Notice Presenter (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Admin\Metaboxes\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes\Presenters;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data-prep value object for the Tickets metabox "save event first" notice.
 *
 * The notice renders when `TicketsMetabox::render()` is invoked on a not-yet-saved
 * event (no event row, no event ID). The presenter holds the translatable strings
 * so the template stays markup-only.
 *
 * Does NOT call `esc_*()` (template's job), does NOT output, does NOT read
 * from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class SaveFirstNoticePresenter {

	/**
	 * Constructor (no state — all data is translatable labels).
	 */
	public function __construct() {}

	/**
	 * Notice body text.
	 *
	 * @return string
	 */
	public function notice_text(): string {
		return __( 'Save the event first to configure ticket types.', 'nettertech-events' );
	}
}
