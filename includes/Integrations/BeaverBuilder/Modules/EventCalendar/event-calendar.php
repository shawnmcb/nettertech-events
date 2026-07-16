<?php
/**
 * Event Calendar Beaver Builder Module.
 *
 * Module fields are registered in BeaverBuilderIntegration.php.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Modules\EventCalendar
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder\Modules\EventCalendar;

defined( 'ABSPATH' ) || exit;

/**
 * Event Calendar module class.
 *
 * @since 0.8.0
 */
class EventCalendarModule extends \FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'Event Calendar', 'nettertech-events' ),
				'description'     => __( 'Display events in an interactive calendar with month, week, and day views.', 'nettertech-events' ),
				'category'        => __( 'NetterTech Events', 'nettertech-events' ),
				'dir'             => NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/Integrations/BeaverBuilder/Modules/EventCalendar/',
				'url'             => NETTERTECH_EVENTS_PLUGIN_URL . 'includes/Integrations/BeaverBuilder/Modules/EventCalendar/',
				'icon'            => 'calendar.svg',
				'partial_refresh' => true,
			)
		);
	}
}
