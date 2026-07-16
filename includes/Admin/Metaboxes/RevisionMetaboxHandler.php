<?php
/**
 * Revision Metabox Handler.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);


namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\RevisionRepositoryInterface;

/**
 * Renders the revision history metabox on the event editor.
 *
 * @since 1.5.0
 */
class RevisionMetaboxHandler {

	/**
	 * Revision repository.
	 *
	 * @var RevisionRepositoryInterface
	 */
	private RevisionRepositoryInterface $revision_repo;

	/**
	 * Constructor.
	 *
	 * @param RevisionRepositoryInterface $revision_repo Revision repository.
	 */
	public function __construct( RevisionRepositoryInterface $revision_repo ) {
		$this->revision_repo = $revision_repo;
	}

	/**
	 * Render the revision metabox content.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public function render( int $event_id ): void {
		$revisions = $this->revision_repo->for_event( $event_id, 10 );
		$total     = $this->revision_repo->count_for_event( $event_id );
		?>
		<div class="postbox nte-revision-postbox">
			<h3 class="hndle">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: Total number of revisions. */
						__( 'Revisions (%d)', 'nettertech-events' ),
						$total
					)
				);
				?>
			</h3>
			<div class="inside">
				<?php if ( empty( $revisions ) ) : ?>
					<p class="nte-revision-empty"><?php esc_html_e( 'No revisions yet.', 'nettertech-events' ); ?></p>
				<?php else : ?>
					<div id="nte-revision-list">
						<?php foreach ( $revisions as $revision ) : ?>
							<?php
							$user        = get_user_by( 'id', (int) $revision->user_id );
							$author_name = $user ? $user->display_name : __( 'Unknown', 'nettertech-events' );
							$created_ts  = strtotime( $revision->created_at );
							$time_ago    = false === $created_ts ? '' : human_time_diff( $created_ts, time() );
							?>
							<div class="nte-revision-item" data-revision-id="<?php echo esc_attr( (string) $revision->id ); ?>">
								<div class="nte-revision-meta">
									<span class="nte-revision-author"><?php echo esc_html( $author_name ); ?></span>
									<span class="nte-revision-time">
										<?php
										echo esc_html(
											sprintf(
											/* translators: %s: Human-readable time difference. */
												__( '%s ago', 'nettertech-events' ),
												$time_ago
											)
										);
										?>
									</span>
								</div>
								<?php if ( ! empty( $revision->change_summary ) ) : ?>
									<div class="nte-revision-summary"><?php echo esc_html( $revision->change_summary ); ?></div>
								<?php endif; ?>
								<div class="nte-revision-actions">
									<button type="button" class="button button-small nte-revision-view-diff" data-revision-id="<?php echo esc_attr( (string) $revision->id ); ?>">
										<?php esc_html_e( 'View Changes', 'nettertech-events' ); ?>
									</button>
									<button type="button" class="button button-small nte-revision-restore" data-revision-id="<?php echo esc_attr( (string) $revision->id ); ?>">
										<?php esc_html_e( 'Restore', 'nettertech-events' ); ?>
									</button>
								</div>
								<div class="nte-revision-diff-panel" style="display:none;"></div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
