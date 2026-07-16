<?php
/**
 * Beaver Builder Integration.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\ThemerIntegration;

/**
 * Beaver Builder integration class.
 *
 * Registers custom Beaver Builder modules for NetterTechEvents.
 *
 * @since 0.8.0
 */
class BeaverBuilderIntegration {

	/**
	 * Module directory path.
	 *
	 * @var string
	 */
	private string $modules_dir;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->modules_dir = NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/Integrations/BeaverBuilder/Modules/';
	}

	/**
	 * Initialize the integration.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_modules' ) );
		add_filter( 'fl_builder_module_categories', array( $this, 'add_module_category' ) );
		add_filter( 'nettertech_events_detected_views', array( $this, 'detect_builder_views' ) );

		( new ThemerIntegration() )->init();
	}

	/**
	 * Register Beaver Builder modules.
	 *
	 * @return void
	 */
	public function register_modules(): void {
		if ( ! class_exists( 'FLBuilder' ) ) {
			return;
		}

		// Event Carousel module - load class BEFORE registering.
		$carousel_file = $this->modules_dir . 'EventCarousel/event-carousel.php';
		if ( file_exists( $carousel_file ) ) {
			require_once $carousel_file;
			\FLBuilder::register_module(
				'NetterTechEvents\\Integrations\\BeaverBuilder\\Modules\\EventCarousel\\EventCarouselModule',
				array(
					'general' => array(
						'title'    => __( 'General', 'nettertech-events' ),
						'sections' => array(
							'content' => array(
								'title'  => __( 'Content', 'nettertech-events' ),
								'fields' => array(
									'limit'         => array(
										'type'    => 'unit',
										'label'   => __( 'Number of Events', 'nettertech-events' ),
										'default' => '6',
										'units'   => array( '' ),
									),
									'columns'       => array(
										'type'    => 'select',
										'label'   => __( 'Columns', 'nettertech-events' ),
										'default' => '3',
										'options' => array(
											'1' => __( '1 Column', 'nettertech-events' ),
											'2' => __( '2 Columns', 'nettertech-events' ),
											'3' => __( '3 Columns', 'nettertech-events' ),
											'4' => __( '4 Columns', 'nettertech-events' ),
											'5' => __( '5 Columns', 'nettertech-events' ),
											'6' => __( '6 Columns', 'nettertech-events' ),
										),
									),
									'autoplay'      => array(
										'type'    => 'select',
										'label'   => __( 'Autoplay', 'nettertech-events' ),
										'default' => '0',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'interval'      => array(
										'type'    => 'unit',
										'label'   => __( 'Autoplay Interval (ms)', 'nettertech-events' ),
										'default' => '5000',
										'units'   => array( 'ms' ),
									),
									'playback_mode' => array(
										'type'    => 'select',
										'label'   => __( 'Playback Mode', 'nettertech-events' ),
										'default' => 'rewind',
										'options' => array(
											'rewind' => __( 'Play and rewind', 'nettertech-events' ),
											'loop'   => __( 'Continuous loop', 'nettertech-events' ),
										),
										'help'    => __( 'Rewind snaps back to start; loop advances seamlessly. Reduced-motion users always see rewind.', 'nettertech-events' ),
									),
									'max_tags'      => array(
										'type'    => 'unit',
										'label'   => __( 'Max Tags Per Card', 'nettertech-events' ),
										'default' => '3',
										'units'   => array( '' ),
										'help'    => __( 'Maximum tags shown per card. Set to 0 to show all. Extras collapse into ...and N more.', 'nettertech-events' ),
									),
									'show_image'    => array(
										'type'    => 'select',
										'label'   => __( 'Show Image', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_date'     => array(
										'type'    => 'select',
										'label'   => __( 'Show Date', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_time'     => array(
										'type'    => 'select',
										'label'   => __( 'Show Time', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_venue'    => array(
										'type'    => 'select',
										'label'   => __( 'Show Venue', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_year'     => array(
										'type'    => 'select',
										'label'   => __( 'Show Year', 'nettertech-events' ),
										'default' => '0',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'image_ratio'   => array(
										'type'    => 'select',
										'label'   => __( 'Image Ratio', 'nettertech-events' ),
										'default' => '',
										'options' => array(
											''         => __( 'Use Default', 'nettertech-events' ),
											'16:9'     => '16:9',
											'3:2'      => '3:2',
											'4:3'      => '4:3',
											'1:1'      => '1:1',
											'original' => __( 'Original', 'nettertech-events' ),
										),
										'help'    => __( 'Override the default image aspect ratio.', 'nettertech-events' ),
									),
								),
							),
						),
					),
				)
			);
		}

		// Event List module - load class BEFORE registering.
		$list_file = $this->modules_dir . 'EventList/event-list.php';
		if ( file_exists( $list_file ) ) {
			require_once $list_file;
			\FLBuilder::register_module(
				'NetterTechEvents\\Integrations\\BeaverBuilder\\Modules\\EventList\\EventListModule',
				array(
					'general'  => array(
						'title'    => __( 'Layout', 'nettertech-events' ),
						'sections' => array(
							'layout' => array(
								'title'  => __( 'Layout Settings', 'nettertech-events' ),
								'fields' => array(
									'layout'  => array(
										'type'    => 'select',
										'label'   => __( 'Layout', 'nettertech-events' ),
										'default' => 'grid',
										'options' => array(
											'grid'  => __( 'Grid', 'nettertech-events' ),
											'list'  => __( 'List', 'nettertech-events' ),
											'cards' => __( 'Cards', 'nettertech-events' ),
										),
									),
									'columns' => array(
										'type'    => 'select',
										'label'   => __( 'Columns', 'nettertech-events' ),
										'default' => '3',
										'options' => array(
											'1' => __( '1 Column', 'nettertech-events' ),
											'2' => __( '2 Columns', 'nettertech-events' ),
											'3' => __( '3 Columns', 'nettertech-events' ),
											'4' => __( '4 Columns', 'nettertech-events' ),
											'5' => __( '5 Columns', 'nettertech-events' ),
											'6' => __( '6 Columns', 'nettertech-events' ),
										),
									),
									'limit'   => array(
										'type'    => 'unit',
										'label'   => __( 'Events Per Page', 'nettertech-events' ),
										'default' => '12',
										'units'   => array( '' ),
									),
								),
							),
						),
					),
					'filters'  => array(
						'title'    => __( 'Filters', 'nettertech-events' ),
						'sections' => array(
							'filter_options' => array(
								'title'  => __( 'Filter Options', 'nettertech-events' ),
								'fields' => array(
									'show_filters'  => array(
										'type'    => 'select',
										'label'   => __( 'Show Filters', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_search'   => array(
										'type'    => 'select',
										'label'   => __( 'Show Search', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_category' => array(
										'type'    => 'select',
										'label'   => __( 'Show Category Filter', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'category'      => array(
										'type'        => 'text',
										'label'       => __( 'Pre-filter by Category IDs', 'nettertech-events' ),
										'default'     => '',
										'placeholder' => __( 'e.g., 1,2,3', 'nettertech-events' ),
										'help'        => __( 'Comma-separated category IDs to pre-filter events.', 'nettertech-events' ),
									),
									'past'          => array(
										'type'    => 'select',
										'label'   => __( 'Show Past Events', 'nettertech-events' ),
										'default' => '0',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
										'help'    => __( 'Show past events instead of upcoming.', 'nettertech-events' ),
									),
								),
							),
						),
					),
					'display'  => array(
						'title'    => __( 'Display', 'nettertech-events' ),
						'sections' => array(
							'display_options' => array(
								'title'  => __( 'Display Options', 'nettertech-events' ),
								'fields' => array(
									'show_image'   => array(
										'type'    => 'select',
										'label'   => __( 'Show Image', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_date'    => array(
										'type'    => 'select',
										'label'   => __( 'Show Date', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_time'    => array(
										'type'    => 'select',
										'label'   => __( 'Show Time', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_venue'   => array(
										'type'    => 'select',
										'label'   => __( 'Show Venue', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_excerpt' => array(
										'type'    => 'select',
										'label'   => __( 'Show Excerpt', 'nettertech-events' ),
										'default' => '0',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'image_ratio'  => array(
										'type'    => 'select',
										'label'   => __( 'Image Ratio', 'nettertech-events' ),
										'default' => '',
										'options' => array(
											''         => __( 'Use Default', 'nettertech-events' ),
											'16:9'     => '16:9',
											'3:2'      => '3:2',
											'4:3'      => '4:3',
											'1:1'      => '1:1',
											'original' => __( 'Original', 'nettertech-events' ),
										),
										'help'    => __( 'Override the default image aspect ratio.', 'nettertech-events' ),
									),
								),
							),
						),
					),
					'advanced' => array(
						'title'    => __( 'Pagination', 'nettertech-events' ),
						'sections' => array(
							'pagination_options' => array(
								'title'  => __( 'Pagination Options', 'nettertech-events' ),
								'fields' => array(
									'pagination' => array(
										'type'    => 'select',
										'label'   => __( 'Show Pagination', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'ajax'       => array(
										'type'    => 'select',
										'label'   => __( 'AJAX Pagination', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
										'help'    => __( 'Load pages without full page reload.', 'nettertech-events' ),
									),
								),
							),
						),
					),
				)
			);
		}

		// Event Calendar module - load class BEFORE registering.
		$calendar_file = $this->modules_dir . 'EventCalendar/event-calendar.php';
		if ( file_exists( $calendar_file ) ) {
			require_once $calendar_file;
			\FLBuilder::register_module(
				'NetterTechEvents\\Integrations\\BeaverBuilder\\Modules\\EventCalendar\\EventCalendarModule',
				array(
					'general' => array(
						'title'    => __( 'General', 'nettertech-events' ),
						'sections' => array(
							'content' => array(
								'title'  => __( 'Content', 'nettertech-events' ),
								'fields' => array(
									'view'               => array(
										'type'    => 'select',
										'label'   => __( 'Default View', 'nettertech-events' ),
										'default' => 'month',
										'options' => array(
											'month' => __( 'Month', 'nettertech-events' ),
											'week'  => __( 'Week', 'nettertech-events' ),
											'day'   => __( 'Day', 'nettertech-events' ),
										),
									),
									'show_view_switcher' => array(
										'type'    => 'select',
										'label'   => __( 'Show View Switcher', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
									'show_navigation'    => array(
										'type'    => 'select',
										'label'   => __( 'Show Navigation', 'nettertech-events' ),
										'default' => '1',
										'options' => array(
											'1' => __( 'Yes', 'nettertech-events' ),
											'0' => __( 'No', 'nettertech-events' ),
										),
									),
								),
							),
						),
					),
				)
			);
		}
	}

	/**
	 * Add NetterTechEvents module category.
	 *
	 * @param array<string> $categories Existing categories.
	 * @return array<string> Modified categories.
	 */
	public function add_module_category( array $categories ): array {
		$categories[] = __( 'NetterTech Events', 'nettertech-events' );
		return $categories;
	}

	/**
	 * Detect NetterTechEvents modules in BB layout and add their views.
	 *
	 * @param array<string> $views Currently detected views.
	 * @return array<string> Updated views with BB module views added.
	 */
	public function detect_builder_views( array $views ): array {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return $views;
		}

		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return $views;
		}

		$data = \FLBuilderModel::get_layout_data( 'published', $post->ID );

		// Also check draft/unsaved layout when BB editor is active.
		if ( \FLBuilderModel::is_builder_active() ) {
			$draft_data = \FLBuilderModel::get_layout_data( 'draft', $post->ID );
			if ( ! empty( $draft_data ) ) {
				$data = array_merge( is_array( $data ) ? $data : array(), $draft_data );
			}
		}

		if ( empty( $data ) ) {
			return $views;
		}

		// Map BB module types to view types.
		$module_to_view = array(
			'event-carousel' => 'carousel',
			'event-list'     => 'grid',
			'event-calendar' => 'calendar',
		);

		foreach ( $data as $node ) {
			if ( isset( $node->type ) && 'module' === $node->type ) {
				if ( isset( $node->settings->type ) && isset( $module_to_view[ $node->settings->type ] ) ) {
					$views[] = $module_to_view[ $node->settings->type ];
				}
			}
		}

		return $views;
	}
}
