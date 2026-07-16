<?php
/**
 * Template for displaying past events archive.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/archive-past-events.php
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Frontend\FrontendBranding;
use NetterTechEvents\Frontend\TemplateCompat;
use NetterTechEvents\Utilities\PathHelper;

// Mark that NTE content is being rendered for optional frontend branding.
FrontendBranding::mark_content_rendered();

// Get archive settings.
$nettertech_events_dto              = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
$nettertech_events_archive_layout   = $nettertech_events_dto->display->archive_layout;
$nettertech_events_archive_columns  = $nettertech_events_dto->display->archive_columns;
$nettertech_events_archive_limit    = $nettertech_events_dto->display->archive_limit;
$nettertech_events_archive_filters  = $nettertech_events_dto->display->archive_show_filters ? 'true' : 'false';
$nettertech_events_archive_search   = $nettertech_events_dto->display->archive_show_search ? 'true' : 'false';
$nettertech_events_archive_category = $nettertech_events_dto->display->archive_show_category ? 'true' : 'false';
$nettertech_events_archive_tag      = $nettertech_events_dto->display->archive_show_tag ? 'true' : 'false';
$nettertech_events_archive_date_rng = $nettertech_events_dto->display->archive_show_date_range ? 'true' : 'false';

// Year-scoped past archive (NTE-113): when /{archive}/{YYYY}/ is requested,
// Router validates the year and exposes it here. Scope the listing to that
// calendar year; otherwise show all past events as before.
$nettertech_events_past_year = \NetterTechEvents\Frontend\Router::get_past_year();
$nettertech_events_date_from = '';
$nettertech_events_date_to   = '';
if ( null !== $nettertech_events_past_year ) {
	$nettertech_events_date_from = sprintf( '%04d-01-01', $nettertech_events_past_year );
	$nettertech_events_date_to   = sprintf( '%04d-12-31 23:59:59', $nettertech_events_past_year );
}

// Enqueue styles.
wp_enqueue_style( 'nettertech-events-grid' );

TemplateCompat::header();
?>

<div class="nte-archive nte-archive--past nte-container">
	<div class="nte-archive__header">
		<h1 class="nte-archive__title">
			<?php
			if ( null !== $nettertech_events_past_year ) {
				printf(
					/* translators: %d: four-digit year */
					esc_html__( 'Past Events - %d', 'nettertech-events' ),
					(int) $nettertech_events_past_year
				);
			} else {
				esc_html_e( 'Past Events', 'nettertech-events' );
			}
			?>
		</h1>
		<p class="nte-archive__description">
			<?php esc_html_e( 'Browse our archive of past events.', 'nettertech-events' ); ?>
		</p>
		<nav class="nte-archive__nav" aria-label="<?php esc_attr_e( 'Events navigation', 'nettertech-events' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' . PathHelper::get_base_path() . '/' ) ); ?>" class="nte-archive__nav-link">
				<?php esc_html_e( 'Upcoming Events', 'nettertech-events' ); ?>
			</a>
			<span class="nte-archive__nav-current" aria-current="page"><?php esc_html_e( 'Past', 'nettertech-events' ); ?></span>
		</nav>
	</div>

	<div class="nte-archive__content">
		<?php
		echo do_shortcode(
			sprintf(
				'[nettertech_events_list layout="%s" columns="%d" limit="%d" show_filters="%s" show_search="%s" show_category="%s" show_tag="%s" show_date_range="%s" date_from="%s" date_to="%s" pagination="true" ajax="true" past="true"]',
				esc_attr( $nettertech_events_archive_layout ),
				$nettertech_events_archive_columns,
				$nettertech_events_archive_limit,
				$nettertech_events_archive_filters,
				$nettertech_events_archive_search,
				$nettertech_events_archive_category,
				$nettertech_events_archive_tag,
				$nettertech_events_archive_date_rng,
				esc_attr( $nettertech_events_date_from ),
				esc_attr( $nettertech_events_date_to )
			)
		);
		?>
	</div>
</div>

<?php
TemplateCompat::footer();
