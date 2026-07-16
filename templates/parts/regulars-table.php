<?php
/**
 * Template part: Weekly Regulars Table
 *
 * Displays a table of weekly recurring events grouped by day of week.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/parts/regulars-table.php
 *
 * Available variables (read via $context):
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var array  $context->rows       Array of row data, each with: day_name, event_title, event_url, venue_name, time.
 * @var bool   $context->show_venue Whether to show the venue column (default: true).
 * @var bool   $context->show_time  Whether to show the time column (default: true).
 * @var bool   $context->show_day   Whether to show the day column (default: true).
 * @var string $context->class      Additional CSS class for the wrapper.
 * @var string $context->heading    Optional section heading above the table.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$nettertech_events_wrapper_class = 'nte-regulars';
if ( ! empty( $context->get( 'class', '' ) ) ) {
	$nettertech_events_wrapper_class .= ' ' . $context->get( 'class', '' );
}

$nettertech_events_prev_day = '';
?>
<div class="<?php echo esc_attr( $nettertech_events_wrapper_class ); ?>">
	<?php if ( ! empty( $context->get( 'heading', '' ) ) ) : ?>
		<h2 class="nte-regulars__heading"><?php echo esc_html( $context->get( 'heading', '' ) ); ?></h2>
	<?php endif; ?>

	<table class="nte-regulars__table">
		<thead>
			<tr>
				<?php if ( $context->get( 'show_day', true ) ) : ?>
					<th class="nte-regulars__th nte-regulars__th--day"><?php esc_html_e( 'Day', 'nettertech-events' ); ?></th>
				<?php endif; ?>
				<th class="nte-regulars__th nte-regulars__th--event"><?php esc_html_e( 'Event', 'nettertech-events' ); ?></th>
				<?php if ( $context->get( 'show_venue', true ) ) : ?>
					<th class="nte-regulars__th nte-regulars__th--venue"><?php esc_html_e( 'Venue', 'nettertech-events' ); ?></th>
				<?php endif; ?>
				<?php if ( $context->get( 'show_time', true ) ) : ?>
					<th class="nte-regulars__th nte-regulars__th--time"><?php esc_html_e( 'Time', 'nettertech-events' ); ?></th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $context->get( 'rows', array() ) as $nettertech_events_row ) : ?>
				<?php
				$nettertech_events_is_new_day = $nettertech_events_row['day_name'] !== $nettertech_events_prev_day;
				$nettertech_events_prev_day   = $nettertech_events_row['day_name'];
				?>
				<tr class="nte-regulars__row<?php echo $nettertech_events_is_new_day ? ' nte-regulars__row--day-start' : ''; ?>">
					<?php if ( $context->get( 'show_day', true ) ) : ?>
						<td class="nte-regulars__td nte-regulars__td--day">
							<?php if ( $nettertech_events_is_new_day ) : ?>
								<?php echo esc_html( $nettertech_events_row['day_name'] ); ?>
							<?php endif; ?>
						</td>
					<?php endif; ?>
					<td class="nte-regulars__td nte-regulars__td--event">
						<a href="<?php echo esc_url( $nettertech_events_row['event_url'] ); ?>" class="nte-regulars__link">
							<?php echo esc_html( $nettertech_events_row['event_title'] ); ?>
						</a>
					</td>
					<?php if ( $context->get( 'show_venue', true ) ) : ?>
						<td class="nte-regulars__td nte-regulars__td--venue">
							<?php echo esc_html( $nettertech_events_row['venue_name'] ); ?>
						</td>
					<?php endif; ?>
					<?php if ( $context->get( 'show_time', true ) ) : ?>
						<td class="nte-regulars__td nte-regulars__td--time">
							<?php echo esc_html( $nettertech_events_row['time'] ); ?>
						</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
