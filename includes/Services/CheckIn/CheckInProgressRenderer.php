<?php
/**
 * Sticky check-in progress row.
 *
 * @package NetterTechEvents\Services\CheckIn
 */

declare(strict_types=1);

namespace NetterTechEvents\Services\CheckIn;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the compact progress row shared by every check-in screen.
 *
 * Base owns the markup, stylesheet and script so the Attendees screen and Pro's
 * admin and volunteer screens draw one component. Screens refresh it by
 * dispatching `nettertech-events:check-in-progress` with the `progress` payload
 * from their check-in response (CheckInProgress::to_array()), or with
 * `progress: null` when a refresh failed, which marks the figure as out of date.
 *
 * @since 1.5.3
 */
final class CheckInProgressRenderer {

	/**
	 * Stylesheet and script handle (registered in Core\Assets::register()).
	 *
	 * @var string
	 */
	public const HANDLE = 'nettertech-events-checkin-progress';

	/**
	 * Enqueue the component's stylesheet and script.
	 *
	 * Screens that know they will render the row call this from their enqueue
	 * hook so the style lands in <head>; render() calls it too as a fallback.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Echo the progress row.
	 *
	 * The row is one progressbar: its children are presentational, so the
	 * visible percentage is not read twice beside aria-valuetext. It carries no
	 * live region; screens already announce the check-in action itself.
	 *
	 * @param CheckInProgress $progress Occurrence progress.
	 * @return void
	 */
	public static function render( CheckInProgress $progress ): void {
		self::enqueue_assets();

		$percent = $progress->percent();
		$state   = 'partial';
		if ( $progress->is_empty() ) {
			$state = 'empty';
		} elseif ( $progress->is_complete() ) {
			$state = 'complete';
		}
		?>
		<div class="nte-checkin-progress"
			data-nte-checkin-progress
			data-state="<?php echo esc_attr( $state ); ?>"
			data-stale-text="<?php esc_attr_e( 'Not updated. Reload the page to refresh.', 'nettertech-events' ); ?>">
			<div class="nte-checkin-progress__meter"
				role="progressbar"
				aria-label="<?php esc_attr_e( 'Check-in progress', 'nettertech-events' ); ?>"
				aria-valuemin="0"
				aria-valuemax="100"
				<?php if ( null !== $percent ) : ?>
					aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>"
				<?php endif; ?>
				aria-valuetext="<?php echo esc_attr( $progress->description() ); ?>"
				data-description="<?php echo esc_attr( $progress->description() ); ?>">
				<span class="nte-checkin-progress__percent"><?php echo esc_html( $progress->percent_label() ); ?></span>
				<span class="nte-checkin-progress__track">
					<span class="nte-checkin-progress__fill" style="<?php echo esc_attr( '--nte-checkin-progress-value: ' . (int) $percent . '%' ); ?>"></span>
				</span>
				<span class="nte-checkin-progress__status"><?php echo $progress->is_empty() ? esc_html( $progress->description() ) : ''; ?></span>
			</div>
		</div>
		<?php
	}
}
