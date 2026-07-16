<?php
/**
 * Space metabox handler class.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\SpacesPage;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;

/**
 * Renders the space assignment metabox on the event editor.
 *
 * The selection posts as `event_space_id` and is persisted to
 * events.space_id by EventSaveHandler.
 *
 * @since 2.1.0
 */
class SpaceMetaboxHandler {

	/**
	 * Space repository.
	 *
	 * @var SpaceRepositoryInterface
	 */
	private SpaceRepositoryInterface $repo;

	/**
	 * Currently assigned space ID (null when unassigned or new event).
	 *
	 * @var int|null
	 */
	private ?int $current_space_id;

	/**
	 * Constructor.
	 *
	 * @param SpaceRepositoryInterface $repo             Space repository.
	 * @param int|null                 $current_space_id Currently assigned space ID.
	 */
	public function __construct( SpaceRepositoryInterface $repo, ?int $current_space_id = null ) {
		$this->repo             = $repo;
		$this->current_space_id = $current_space_id;
	}

	/**
	 * Render the space assignment metabox.
	 *
	 * @return void
	 */
	public function render(): void {
		$all_spaces = $this->repo->paginate(
			array(
				'status'  => 'active',
				'orderby' => 'name',
				'order'   => 'ASC',
				'limit'   => 100,
				'offset'  => 0,
			)
		);
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Space / Venue', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<?php if ( empty( $all_spaces ) ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: URL to spaces admin page */
							esc_html__( 'No spaces found. %s to create one.', 'nettertech-events' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=' . SpacesPage::PAGE_SLUG . '&action=add' ) ) . '">'
								. esc_html__( 'Go to Spaces', 'nettertech-events' ) . '</a>'
						);
						?>
					</p>
				<?php else : ?>
					<select name="event_space_id" id="event_space_id" style="width: 100%;">
						<option value="0"><?php esc_html_e( '&mdash; No space assigned &mdash;', 'nettertech-events' ); ?></option>
						<?php foreach ( $all_spaces as $space ) : ?>
							<option value="<?php echo esc_attr( (string) $space->id ); ?>"<?php selected( $this->current_space_id ?? 0, (int) $space->id ); ?>>
								<?php
								echo esc_html( $space->name );
								if ( $space->capacity > 0 ) {
									echo ' (' . esc_html(
										sprintf(
											/* translators: %d: space capacity */
											__( 'Capacity: %d', 'nettertech-events' ),
											$space->capacity
										)
									) . ')';
								}
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select a space for this event.', 'nettertech-events' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
