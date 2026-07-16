<?php
/**
 * Organizer metabox handler class.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\OrganizerPage;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;

/**
 * Renders and handles the organizer assignment metabox on the event editor.
 *
 * @since 1.8.0
 */
class OrganizerMetaboxHandler {

	/**
	 * Event ID.
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Organizer repository.
	 *
	 * @var OrganizerRepositoryInterface
	 */
	private OrganizerRepositoryInterface $repo;

	/**
	 * Constructor.
	 *
	 * @param int                          $event_id Event ID (0 for new).
	 * @param OrganizerRepositoryInterface $repo     Organizer repository.
	 */
	public function __construct( int $event_id, OrganizerRepositoryInterface $repo ) {
		$this->event_id = $event_id;
		$this->repo     = $repo;
	}

	/**
	 * Render the organizer assignment metabox.
	 *
	 * @return void
	 */
	public function render(): void {
		$all_organizers = $this->repo->get_all(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$assigned_ids   = array();

		if ( $this->event_id > 0 ) {
			$assigned     = $this->repo->find_by_event( $this->event_id );
			$assigned_ids = array_map(
				static function ( $org ) {
					return $org->id;
				},
				$assigned
			);
		}
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Organizers', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<?php if ( empty( $all_organizers ) ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: URL to organizer admin page */
							esc_html__( 'No organizers found. %s to create one.', 'nettertech-events' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=' . OrganizerPage::PAGE_SLUG . '&action=add' ) ) . '">'
								. esc_html__( 'Go to Organizers', 'nettertech-events' ) . '</a>'
						);
						?>
					</p>
				<?php else : ?>
					<ul style="max-height: 200px; overflow-y: auto; margin: 0; padding: 0 0 0 2px;">
						<?php foreach ( $all_organizers as $organizer ) : ?>
							<li>
								<label>
									<input type="checkbox"
										name="event_organizers[]"
										value="<?php echo esc_attr( (string) $organizer->id ); ?>"
										<?php checked( in_array( $organizer->id, $assigned_ids, true ) ); ?>>
									<?php echo esc_html( $organizer->name ); ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
