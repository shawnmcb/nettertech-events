<?php
/**
 * Event Carousel Beaver Builder Module.
 *
 * Module fields are registered in BeaverBuilderIntegration.php.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Modules\EventCarousel
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder\Modules\EventCarousel;

defined( 'ABSPATH' ) || exit;

/**
 * Event Carousel module class.
 *
 * @since 0.8.0
 */
class EventCarouselModule extends \FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'Event Carousel', 'nettertech-events' ),
				'description'     => __( 'Display upcoming events in a carousel slider.', 'nettertech-events' ),
				'category'        => __( 'NetterTech Events', 'nettertech-events' ),
				'dir'             => NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/Integrations/BeaverBuilder/Modules/EventCarousel/',
				'url'             => NETTERTECH_EVENTS_PLUGIN_URL . 'includes/Integrations/BeaverBuilder/Modules/EventCarousel/',
				'icon'            => 'slides.svg',
				'partial_refresh' => true,
			)
		);
	}
}
