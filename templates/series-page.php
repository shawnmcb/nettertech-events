<?php
/**
 * Template for displaying a series page (recurring event with multiple occurrences).
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/series-page.php
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Frontend\FrontendBranding;
use NetterTechEvents\Frontend\Router;
use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;
use NetterTechEvents\Frontend\TemplateCompat;
use NetterTechEvents\TemplateLoader\Templates;

// Mark that NTE content is being rendered for optional frontend branding.
FrontendBranding::mark_content_rendered();

// Enqueue styles.
wp_enqueue_style( 'nettertech-events-series' );

// Get the current event from the router.
$nettertech_events_event = Router::get_current_event();

if ( ! $nettertech_events_event ) {
	wp_safe_redirect( home_url() );
	exit;
}

// Get occurrence list with transient caching (persists in database).
// Transient is invalidated when event/occurrences change via nettertech_events_cache_invalidated hook.
$nettertech_events_transient_key = 'nettertech_events_series_' . $nettertech_events_event->id;
$nettertech_events_grouped       = get_transient( $nettertech_events_transient_key );

if ( false === $nettertech_events_grouped ) {
	$nettertech_events_occurrence_repo = Router::instance()->get_occurrence_repository();
	$nettertech_events_grouped         = $nettertech_events_occurrence_repo->for_event_grouped(
		$nettertech_events_event->id,
		array(
			'status' => 'scheduled',
			'limit'  => 100,
		)
	);
	set_transient( $nettertech_events_transient_key, $nettertech_events_grouped, HOUR_IN_SECONDS );
}

// Extract past and upcoming from grouped result.
$nettertech_events_past     = $nettertech_events_grouped['past'];
$nettertech_events_upcoming = $nettertech_events_grouped['upcoming'];

$nettertech_events_has_past     = ! empty( $nettertech_events_past );
$nettertech_events_has_upcoming = ! empty( $nettertech_events_upcoming );
$nettertech_events_show_past    = filter_input( INPUT_GET, 'show_past', FILTER_VALIDATE_BOOL ) || ! $nettertech_events_has_upcoming;

// Determine which occurrences to display.
$nettertech_events_display_occurrences = $nettertech_events_show_past ? array_merge( $nettertech_events_past, $nettertech_events_upcoming ) : $nettertech_events_upcoming;

TemplateCompat::header();
?>

<div class="nte-series-page nte-container">
	<?php
	echo wp_kses( Templates::get_part( 'event-breadcrumb' ), ShortcodeOutput::get_allowlist() );
	?>
	<article class="nte-series-page__article">

		<div class="nte-series-page__header">
			<h1 class="nte-series-page__title"><?php echo esc_html( $nettertech_events_event->title ); ?></h1>

			<?php if ( ! empty( $nettertech_events_event->venue_name ) ) : ?>
				<p class="nte-series-page__venue">
					<svg class="nte-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
						<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
						<circle cx="12" cy="10" r="3"/>
					</svg>
					<?php echo esc_html( $nettertech_events_event->venue_name ); ?>
					<?php if ( ! empty( $nettertech_events_event->venue_address ) ) : ?>
						<span class="nte-series-page__address"><?php echo esc_html( $nettertech_events_event->venue_address ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( $nettertech_events_event->featured_image_id ) : ?>
			<div class="nte-series-page__image">
				<?php
				// wp_get_attachment_image() is a recognized escape primitive in PHPCS.
				echo wp_get_attachment_image( $nettertech_events_event->featured_image_id, 'large', false, array( 'class' => 'nte-series-page__img' ) );
				?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $nettertech_events_event->description ) ) : ?>
			<section class="nte-series-page__content">
				<div class="nte-series-page__description">
					<?php
					// Run the description through WordPress's content pipeline (wpautop, shortcodes,
					// and oEmbed auto-embeds such as YouTube), then sanitize the result against the
					// shared NTE allowlist. That allowlist permits oEmbed <iframe> markup, so embedded
					// videos survive; using the default post allowlist (wp_kses_post) would strip the
					// iframe and silently drop the video. The description is already wp_kses_post()-
					// sanitized at the input boundary (EventSaveHandler).
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the_content is a WordPress core filter, not a plugin-prefixed hook.
					echo wp_kses( apply_filters( 'the_content', $nettertech_events_event->description ), ShortcodeOutput::get_allowlist() );
					?>
				</div>
			</section>
		<?php endif; ?>

		<section class="nte-series-page__dates" aria-label="<?php esc_attr_e( 'All Dates', 'nettertech-events' ); ?>">
			<div class="nte-series-page__dates-header">
				<h2 class="nte-series-page__section-title">
					<?php
					if ( $nettertech_events_has_upcoming ) {
						esc_html_e( 'Upcoming Dates', 'nettertech-events' );
					} else {
						esc_html_e( 'Past Dates', 'nettertech-events' );
					}
					?>
					<span class="nte-series-page__count">(<?php echo esc_html( (string) count( $nettertech_events_display_occurrences ) ); ?>)</span>
				</h2>

				<?php if ( $nettertech_events_has_past && $nettertech_events_has_upcoming ) : ?>
					<nav class="nte-series-page__date-nav" aria-label="<?php esc_attr_e( 'Date filter', 'nettertech-events' ); ?>">
						<?php if ( $nettertech_events_show_past ) : ?>
							<a href="<?php echo esc_url( remove_query_arg( 'show_past' ) ); ?>" class="nte-series-page__nav-link">
								<?php
								printf(
									/* translators: %d: number of upcoming dates */
									esc_html__( 'Upcoming (%d)', 'nettertech-events' ),
									count( $nettertech_events_upcoming )
								);
								?>
							</a>
							<span class="nte-series-page__nav-current" aria-current="true">
								<?php
								printf(
									/* translators: %d: number of past dates */
									esc_html__( 'All (%d)', 'nettertech-events' ),
									count( $nettertech_events_past ) + count( $nettertech_events_upcoming )
								);
								?>
							</span>
						<?php else : ?>
							<span class="nte-series-page__nav-current" aria-current="true">
								<?php
								printf(
									/* translators: %d: number of upcoming dates */
									esc_html__( 'Upcoming (%d)', 'nettertech-events' ),
									count( $nettertech_events_upcoming )
								);
								?>
							</span>
							<a href="?show_past=1" class="nte-series-page__nav-link">
								<?php
								printf(
									/* translators: %d: number of past dates */
									esc_html__( 'All (%d)', 'nettertech-events' ),
									count( $nettertech_events_past ) + count( $nettertech_events_upcoming )
								);
								?>
							</a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $nettertech_events_display_occurrences ) ) : ?>
				<div class="nte-grid nte-grid--cols-3 nte-grid--grid" aria-label="<?php esc_attr_e( 'Event occurrences', 'nettertech-events' ); ?>">
					<?php foreach ( $nettertech_events_display_occurrences as $nettertech_events_occ ) : ?>
						<?php
						$nettertech_events_occ->set_event( $nettertech_events_event );
						echo wp_kses(
							Templates::get_part(
								'event-card',
								array(
									'occurrence'   => $nettertech_events_occ,
									'event'        => $nettertech_events_event,
									'show_image'   => true,
									'show_venue'   => true,
									'show_price'   => true,
									'heading_tag'  => 'h3',
									'heading_date' => true,
								)
							),
							ShortcodeOutput::get_allowlist()
						);
						?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="nte-series-page__no-dates">
					<?php esc_html_e( 'No upcoming dates scheduled.', 'nettertech-events' ); ?>
				</p>
			<?php endif; ?>
		</section>

		<?php
		/**
		 * Hook to add additional content to series pages.
		 *
		 * @param \NetterTechEvents\Models\Event $nettertech_events_event The event.
		 */
		do_action( 'nettertech_events_after_series_content', $nettertech_events_event );
		?>

	</article>
	<?php echo wp_kses( FrontendBranding::render_badge(), ShortcodeOutput::get_allowlist() ); ?>
</div>

<?php
TemplateCompat::footer();
