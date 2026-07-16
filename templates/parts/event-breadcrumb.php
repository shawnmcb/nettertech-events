<?php
/**
 * Template part: Event breadcrumb/back navigation.
 *
 * Displays a "Back to Events" link for single event and series pages.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Past events link back to the archive; upcoming events link to the base
// events listing. Determined from the current occurrence when available.
$nte_breadcrumb_occurrence = \NetterTechEvents\Frontend\Router::get_current_occurrence();
$nte_breadcrumb_url        = ( $nte_breadcrumb_occurrence && $nte_breadcrumb_occurrence->is_past() )
	? \NetterTechEvents\Utilities\PathHelper::get_archive_url()
	: \NetterTechEvents\Utilities\PathHelper::get_base_url();

?>

<nav class="nte-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'nettertech-events' ); ?>">
	<a href="<?php echo esc_url( $nte_breadcrumb_url ); ?>" class="nte-breadcrumb__link">
		<svg class="nte-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
			<polyline points="15 18 9 12 15 6"/>
		</svg>
		<?php esc_html_e( 'Back to Events', 'nettertech-events' ); ?>
	</a>
</nav>
