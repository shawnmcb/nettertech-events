<?php
/**
 * Event List Beaver Builder Module.
 *
 * Module fields are registered in BeaverBuilderIntegration.php.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Modules\EventList
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder\Modules\EventList;

defined( 'ABSPATH' ) || exit;

/**
 * Event List module class.
 *
 * @since 0.8.0
 */
class EventListModule extends \FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'Event List', 'nettertech-events' ),
				'description'     => __( 'Display events in a grid, list, or cards layout with filtering.', 'nettertech-events' ),
				'category'        => __( 'NetterTech Events', 'nettertech-events' ),
				'dir'             => NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/Integrations/BeaverBuilder/Modules/EventList/',
				'url'             => NETTERTECH_EVENTS_PLUGIN_URL . 'includes/Integrations/BeaverBuilder/Modules/EventList/',
				'icon'            => 'layout.svg',
				'partial_refresh' => true,
			)
		);
	}
}
