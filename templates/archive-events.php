<?php
/**
 * Template for displaying the main events page (upcoming events).
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/archive-events.php
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

// Enqueue styles.
wp_enqueue_style( 'nettertech-events-grid' );

TemplateCompat::header();
?>

<div class="nte-archive nte-container">
	<div class="nte-archive__header">
		<h1 class="nte-archive__title"><?php esc_html_e( 'Upcoming Events', 'nettertech-events' ); ?></h1>
		<?php
		$nte_archive_intro = trim(
			(string) \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->display->events_archive_intro
		);
		if ( '' === $nte_archive_intro ) {
			$nte_archive_intro = __( 'Browse our upcoming events and find something that interests you.', 'nettertech-events' );
		}
		?>
		<p class="nte-archive__description">
			<?php echo esc_html( $nte_archive_intro ); ?>
		</p>
		<nav class="nte-archive__nav" aria-label="<?php esc_attr_e( 'Events navigation', 'nettertech-events' ); ?>">
			<span class="nte-archive__nav-current" aria-current="page"><?php esc_html_e( 'Upcoming', 'nettertech-events' ); ?></span>
			<a href="<?php echo esc_url( PathHelper::get_archive_url() ); ?>" class="nte-archive__nav-link">
				<?php esc_html_e( 'Past Events', 'nettertech-events' ); ?>
			</a>
		</nav>
	</div>

	<div class="nte-archive__content">
		<?php
		echo do_shortcode(
			sprintf(
				'[nettertech_events_list layout="%s" columns="%d" limit="%d" show_filters="%s" show_search="%s" show_category="%s" show_tag="%s" show_date_range="%s" pagination="true" ajax="true"]',
				esc_attr( $nettertech_events_archive_layout ),
				$nettertech_events_archive_columns,
				$nettertech_events_archive_limit,
				$nettertech_events_archive_filters,
				$nettertech_events_archive_search,
				$nettertech_events_archive_category,
				$nettertech_events_archive_tag,
				$nettertech_events_archive_date_rng
			)
		);
		?>
	</div>
</div>

<?php
TemplateCompat::footer();
