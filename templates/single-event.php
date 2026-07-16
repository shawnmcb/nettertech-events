<?php
/**
 * Template for displaying a single event.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/single-event.php
 *
 * @version 2.0.0
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Frontend\FrontendBranding;
use NetterTechEvents\Frontend\Router;
use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;
use NetterTechEvents\Frontend\SingleEventPageHandler;
use NetterTechEvents\Frontend\TemplateCompat;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\TemplateLoader\Templates;

// Mark that NTE content is being rendered for optional frontend branding.
FrontendBranding::mark_content_rendered();

// Get the current event and occurrence from the router.
$nettertech_events_event      = Router::get_current_event();
$nettertech_events_occurrence = Router::get_current_occurrence();

if ( ! $nettertech_events_event ) {
	wp_safe_redirect( home_url() );
	exit;
}

// Resolve page data via handler.
$nettertech_events_container = \NetterTechEvents\nettertech_events_container();
$nettertech_events_handler   = new SingleEventPageHandler(
	$nettertech_events_container->get( OccurrenceRepositoryInterface::class ),
	$nettertech_events_container->get( TicketTypeRepositoryInterface::class ),
	new LayoutService()
);

$nettertech_events_page_data          = $nettertech_events_handler->resolve( $nettertech_events_event, $nettertech_events_occurrence );
$nettertech_events_template_context   = array(
	'event'                 => $nettertech_events_page_data['event'],
	'occurrence'            => $nettertech_events_page_data['occurrence'],
	'occurrences'           => $nettertech_events_page_data['occurrences'],
	'featured_image_id'     => $nettertech_events_page_data['featured_image_id'],
	'image_vertical_anchor' => $nettertech_events_page_data['image_vertical_anchor'],
	'display_title'         => $nettertech_events_page_data['display_title'],
	'date_subtitle'         => $nettertech_events_page_data['date_subtitle'],
	'price_display'         => $nettertech_events_page_data['price_display'],
	'target_occ'            => $nettertech_events_page_data['target_occ'],
	'siblings'              => $nettertech_events_page_data['siblings'],
	'sibling_count'         => $nettertech_events_page_data['sibling_count'],
	'is_cancelled'          => $nettertech_events_page_data['is_cancelled'],
	'visible_components'    => $nettertech_events_page_data['visible_components'],
);
$nettertech_events_visible_components = $nettertech_events_page_data['visible_components'];

// Enqueue styles.
wp_enqueue_style( 'nettertech-events-single' );

TemplateCompat::header();
?>

<div class="nte-single-event nte-container">
	<?php
	echo wp_kses( Templates::get_part( 'event-breadcrumb' ), ShortcodeOutput::get_allowlist() );
	?>
	<article class="nte-single-event__article">

		<?php
		// Render components in configured order.
		foreach ( $nettertech_events_visible_components as $nettertech_events_component_id ) {
			switch ( $nettertech_events_component_id ) {
				case 'header':
					echo wp_kses( Templates::get_part( 'single-event-header', $nettertech_events_template_context ), ShortcodeOutput::get_allowlist() );
					break;

				case 'featured_image':
					echo wp_kses( Templates::get_part( 'single-event-image', $nettertech_events_template_context ), ShortcodeOutput::get_allowlist() );
					break;

				case 'occurrence_date':
					echo wp_kses( Templates::get_part( 'single-event-occurrence', $nettertech_events_template_context ), ShortcodeOutput::get_allowlist() );
					break;

				case 'upcoming_dates':
					echo wp_kses( Templates::get_part( 'single-event-upcoming', $nettertech_events_template_context ), ShortcodeOutput::get_allowlist() );
					break;

				case 'description':
					echo wp_kses( Templates::get_part( 'single-event-description', $nettertech_events_template_context ), ShortcodeOutput::get_allowlist() );
					break;

				case 'more_dates':
					echo wp_kses( Templates::get_part( 'single-event-more-dates', $nettertech_events_template_context ), ShortcodeOutput::get_allowlist() );
					break;
			}
		}
		?>

		<?php
		/**
		 * Hook to add additional content to single event pages.
		 *
		 * @param \NetterTechEvents\Models\Event $nettertech_events_event The event.
		 */
		do_action( 'nettertech_events_after_single_content', $nettertech_events_event );
		?>

	</article>
	<?php echo wp_kses( FrontendBranding::render_badge(), ShortcodeOutput::get_allowlist() ); ?>
</div>

<?php
TemplateCompat::footer();
