<?php
/**
 * Template for displaying a single space.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/single-space.php
 *
 * @version 1.7.0
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Frontend\FrontendBranding;
use NetterTechEvents\Frontend\Router;
use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;
use NetterTechEvents\Frontend\SpacePageHandler;
use NetterTechEvents\Frontend\TemplateCompat;

// Mark that NTE content is being rendered for optional frontend branding.
FrontendBranding::mark_content_rendered();

$nettertech_events_space = Router::get_current_space();

if ( ! $nettertech_events_space ) {
	wp_safe_redirect( home_url() );
	exit;
}

$nettertech_events_handler   = new SpacePageHandler();
$nettertech_events_amenities = $nettertech_events_handler->get_amenities( $nettertech_events_space );

// Collect accessibility features (decoded JSON list — preset keys map to
// translated labels, custom entries fall back to the raw key).
$nettertech_events_accessibility_features = array();
foreach ( $nettertech_events_handler->get_accessibility_features( $nettertech_events_space ) as $nettertech_events_entry ) {
	$nettertech_events_label = \NetterTechEvents\Catalog\AccessibilityFeature::label_for( $nettertech_events_entry['key'] );
	if ( ! empty( $nettertech_events_entry['count'] ) ) {
		$nettertech_events_label = sprintf( '%s (%d)', $nettertech_events_label, (int) $nettertech_events_entry['count'] );
	}
	if ( ! empty( $nettertech_events_entry['notes'] ) ) {
		$nettertech_events_label .= ' — ' . $nettertech_events_entry['notes'];
	}
	$nettertech_events_accessibility_features[] = $nettertech_events_label;
}

// Enqueue styles.
wp_enqueue_style( 'nettertech-events-space' );

TemplateCompat::header();
?>

<div class="nte-single-space nte-container">
	<article class="nte-single-space__article">

		<header class="nte-single-space__header">
			<h1 class="nte-single-space__title"><?php echo esc_html( $nettertech_events_space->name ); ?></h1>
			<?php if ( $nettertech_events_space->capacity > 0 ) : ?>
				<span class="nte-single-space__capacity">
					<?php
					printf(
						/* translators: %d: maximum capacity number */
						esc_html__( 'Capacity: %d', 'nettertech-events' ),
						esc_html( $nettertech_events_space->capacity )
					);
					?>
				</span>
			<?php endif; ?>
		</header>

		<?php if ( $nettertech_events_space->featured_image_id ) : ?>
			<div class="nte-single-space__image">
				<?php
				echo wp_get_attachment_image(
					$nettertech_events_space->featured_image_id,
					'large',
					false,
					array(
						'class' => 'nte-single-space__img',
						'alt'   => esc_attr( $nettertech_events_space->name ),
					)
				);
				?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $nettertech_events_space->description ) ) : ?>
			<div class="nte-single-space__content">
				<div class="nte-single-space__description">
					<?php echo wp_kses_post( wpautop( $nettertech_events_space->description ) ); ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $nettertech_events_space->capacity > 0 || $nettertech_events_space->square_footage ) : ?>
			<div class="nte-single-space__details">
				<?php if ( $nettertech_events_space->capacity > 0 ) : ?>
					<div class="nte-single-space__detail">
						<span class="nte-single-space__detail-label"><?php esc_html_e( 'Capacity', 'nettertech-events' ); ?></span>
						<span class="nte-single-space__detail-value">
							<?php
							printf(
								/* translators: %d: number of people */
								esc_html__( '%d people', 'nettertech-events' ),
								esc_html( $nettertech_events_space->capacity )
							);
							?>
						</span>
					</div>
				<?php endif; ?>
				<?php if ( $nettertech_events_space->square_footage ) : ?>
					<div class="nte-single-space__detail">
						<span class="nte-single-space__detail-label"><?php esc_html_e( 'Square Footage', 'nettertech-events' ); ?></span>
						<span class="nte-single-space__detail-value">
							<?php
							echo esc_html( number_format_i18n( $nettertech_events_space->square_footage ) );
							echo ' ';
							esc_html_e( 'sq ft', 'nettertech-events' );
							?>
						</span>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $nettertech_events_amenities ) ) : ?>
			<div class="nte-single-space__amenities">
				<h2 class="nte-single-space__section-title"><?php esc_html_e( 'Amenities', 'nettertech-events' ); ?></h2>
				<ul class="nte-single-space__amenities-list">
					<?php foreach ( $nettertech_events_amenities as $nettertech_events_amenity ) : ?>
						<li class="nte-single-space__amenity"><?php echo esc_html( $nettertech_events_amenity ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $nettertech_events_accessibility_features ) ) : ?>
			<div class="nte-single-space__accessibility">
				<h2 class="nte-single-space__section-title"><?php esc_html_e( 'Accessibility', 'nettertech-events' ); ?></h2>
				<ul class="nte-single-space__accessibility-list">
					<?php foreach ( $nettertech_events_accessibility_features as $nettertech_events_feature ) : ?>
						<li class="nte-single-space__accessibility-badge"><?php echo esc_html( $nettertech_events_feature ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php
		/**
		 * Hook to add content to the space detail page.
		 *
		 * Used by VER to add rental CTA button.
		 *
		 * @since 1.0.2
		 *
		 * @param object $nettertech_events_space The space data object.
		 */
		do_action( 'nettertech_events_single_space_content', $nettertech_events_space );
		?>

	</article>

	<?php
	/**
	 * Hook to add content after the space article.
	 *
	 * @since 1.0.2
	 *
	 * @param object $nettertech_events_space The space data object.
	 */
	do_action( 'nettertech_events_after_single_space_content', $nettertech_events_space );
	?>

	<?php echo wp_kses( FrontendBranding::render_badge(), ShortcodeOutput::get_allowlist() ); ?>
</div>

<?php
TemplateCompat::footer();
