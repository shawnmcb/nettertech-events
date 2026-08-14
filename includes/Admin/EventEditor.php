<?php
/**
 * Event editor class.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Services\RecurrenceService;

/**
 * Handles the event add/edit form.
 *
 * This class serves as an orchestrator:
 * - Delegates rendering to EventMetaboxHandler
 * - Delegates save operations to EventSaveHandler
 *
 * @since 0.8.0
 */
class EventEditor {

	/**
	 * Event ID (0 for new).
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Current event. Always set by the constructor: the loaded event when
	 * editing, a fresh Event when adding or when the lookup fails.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Capacity service.
	 *
	 * @var CapacityServiceInterface
	 */
	private CapacityServiceInterface $capacity_service;

	/**
	 * Metabox handler.
	 *
	 * @var EventMetaboxHandler
	 */
	private EventMetaboxHandler $metabox_handler;

	/**
	 * Constructor.
	 *
	 * @param int                               $event_id              Event ID (0 for new).
	 * @param EventRepositoryInterface          $event_repo            Event repository.
	 * @param OccurrenceRepositoryInterface     $occurrence_repo       Occurrence repository.
	 * @param TicketTypeRepositoryInterface     $ticket_type_repo      Ticket type repository.
	 * @param CapacityServiceInterface          $capacity_service      Capacity service.
	 * @param RecurrenceService                 $recurrence_service    Recurrence service.
	 * @param RevisionRepositoryInterface       $revision_repo         Revision repository.
	 * @param AttendeeFieldRepositoryInterface  $attendee_field_repo   Attendee field repository.
	 * @param LayoutService                     $layout_service        Layout service.
	 * @param CategoryRepositoryInterface       $category_repo         Category repository.
	 * @param OrganizerRepositoryInterface|null $organizer_repo       Organizer repository (organizer metabox is not wired when null).
	 * @param SpaceRepositoryInterface|null     $space_repo            Space repository (space metabox is not wired when null).
	 */
	public function __construct(
		int $event_id,
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service,
		RecurrenceService $recurrence_service,
		RevisionRepositoryInterface $revision_repo,
		AttendeeFieldRepositoryInterface $attendee_field_repo,
		LayoutService $layout_service,
		CategoryRepositoryInterface $category_repo,
		?OrganizerRepositoryInterface $organizer_repo = null,
		?SpaceRepositoryInterface $space_repo = null
	) {
		$this->event_id         = $event_id;
		$this->event_repo       = $event_repo;
		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$event       = $event_id > 0 ? $this->event_repo->find( $event_id ) : null;
		$this->event = $event ?? new Event();

		// Initialize metabox handler with dependencies.
		$this->metabox_handler = new EventMetaboxHandler(
			$this->event,
			$this->event_id,
			$this->ticket_type_repo,
			$this->capacity_service,
			$recurrence_service,
			$revision_repo,
			$layout_service,
			$category_repo
		);

		// Wire attendee fields metabox (lazy - only renders for existing events).
		$this->metabox_handler->set_attendee_field_repo( $attendee_field_repo );

		// Wire organizer metabox. Before 1.1.1 this checked isset() on a
		// parameter that did not exist, so the metabox was silently never
		// wired (found when the PHPStan gate was restored; see INV-M1).
		if ( null !== $organizer_repo ) {
			$this->metabox_handler->set_organizer_repo( $organizer_repo );
		}

		// Wire space metabox (renders nothing when no repo is provided).
		if ( null !== $space_repo ) {
			$this->metabox_handler->set_space_repo( $space_repo );
		}
	}

	/**
	 * Register hooks for form processing.
	 *
	 * @param EventSaveHandler $save_handler Save handler instance.
	 * @return void
	 */
	public static function register( EventSaveHandler $save_handler ): void {
		add_action( 'admin_post_nettertech_events_save_event', array( $save_handler, 'handle_save' ) );
	}

	/**
	 * Render the editor form.
	 *
	 * @return void
	 */
	public function render(): void {
		$is_new = 0 === $this->event_id;
		$title  = $is_new ? __( 'Add New Event', 'nettertech-events' ) : __( 'Edit Event', 'nettertech-events' );

		// Get the first occurrence for existing events (all types).
		$occurrence = null;
		if ( ! $is_new ) {
			$occurrences = $this->occurrence_repo->for_event( $this->event_id, array( 'limit' => 1 ) );
			$occurrence  = $occurrences[0] ?? null;
		}

		// Check for save errors.
		$transient_key = 'nettertech_events_save_error_' . get_current_user_id();
		$error         = get_transient( $transient_key );
		if ( $error ) {
			delete_transient( $transient_key );
		}

		// Informational save notice (derived times, skipped date rows — NTE-184).
		$notice_key = 'nettertech_events_save_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( $notice ) {
			delete_transient( $notice_key );
		}

		?>
		<a class="nte-skip-link screen-reader-text" href="#nte-main-content">
			<?php esc_html_e( 'Skip to main content', 'nettertech-events' ); ?>
		</a>
		<div id="nte-main-content" class="wrap" tabindex="-1">
			<?php Branding::render_header(); ?>
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG ) ); ?>" class="page-title-action">
				<?php esc_html_e( '← Back to Events', 'nettertech-events' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $error ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible">
					<p><?php echo esc_html( $notice ); ?></p>
				</div>
			<?php endif; ?>

			<?php settings_errors( 'nettertech_events_settings' ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="nettertech-events-editor">
				<input type="hidden" name="action" value="nettertech_events_save_event">
				<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $this->event_id ); ?>">
				<?php wp_nonce_field( 'nettertech_events_save_event', 'nettertech_events_event_nonce' ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<!-- Main content -->
						<div id="post-body-content">
							<div id="titlediv">
								<div id="titlewrap">
									<label class="screen-reader-text" for="event_title">
										<?php esc_html_e( 'Event Title', 'nettertech-events' ); ?>
									</label>
									<input type="text" name="event_title" id="event_title"
											value="<?php echo esc_attr( $this->event->title ); ?>"
											placeholder="<?php esc_attr_e( 'Enter event title', 'nettertech-events' ); ?>"
											class="widefat" required>
								</div>
								<div class="inside">
									<div id="edit-slug-box">
										<label for="event_slug">
											<strong><?php esc_html_e( 'Slug:', 'nettertech-events' ); ?></strong>
										</label>
										<input type="text" name="event_slug" id="event_slug"
												value="<?php echo esc_attr( $this->event->slug ); ?>"
												class="regular-text">
									</div>
								</div>
							</div>

							<div id="postdivrich" class="postarea">
								<label for="event_description" class="screen-reader-text">
									<?php esc_html_e( 'Event Description', 'nettertech-events' ); ?>
								</label>
								<?php
								wp_editor(
									$this->event->description,
									'event_description',
									array(
										'textarea_name' => 'event_description',
										'textarea_rows' => 15,
										'media_buttons' => true,
									)
								);
								?>
							</div>

							<div class="postbox" style="margin-top: 20px;">
								<div class="postbox-header">
									<h2><?php esc_html_e( 'Excerpt', 'nettertech-events' ); ?></h2>
								</div>
								<div class="inside">
									<textarea name="event_excerpt" id="event_excerpt" rows="3" class="widefat">
									<?php
										echo esc_textarea( $this->event->excerpt );
									?>
									</textarea>
									<p class="description">
										<?php esc_html_e( 'A short summary displayed in event listings.', 'nettertech-events' ); ?>
									</p>
								</div>
							</div>

							<?php $this->metabox_handler->render_layout_preview_box(); ?>
						</div>

						<!-- Sidebar -->
						<div id="postbox-container-1" class="postbox-container">
							<?php $this->metabox_handler->render_publish_box(); ?>
							<?php
							// One Schedule box: date/time seed, recurrence pattern, and the dates
							// list together (NTE-159). Dates shown for any saved event, not just a
							// recurring one — a date stranded by a conversion must be findable
							// rather than left live on the site with nowhere to manage it (NTE-153).
							$upcoming_occurrences = array();
							if ( ! $is_new ) {
								$upcoming_occurrences = $this->occurrence_repo->for_event(
									$this->event_id,
									array(
										'upcoming' => true,
										'limit'    => 100,
									)
								);
							}
							$this->metabox_handler->render_schedule_box(
								$occurrence,
								$upcoming_occurrences,
								$is_new ? 0 : $this->event_id
							);
							?>
							<?php $this->metabox_handler->render_ticket_types_box( $occurrence ); ?>
							<?php $this->metabox_handler->render_attendee_fields_box(); ?>
							<?php $this->metabox_handler->render_layout_box(); ?>
							<?php $this->metabox_handler->render_venue_box(); ?>
							<?php $this->metabox_handler->render_categories_box(); ?>
							<?php $this->metabox_handler->render_tags_box(); ?>
							<?php $this->metabox_handler->render_organizers_box(); ?>
							<?php $this->metabox_handler->render_space_box(); ?>
							<?php $this->metabox_handler->render_featured_image_box(); ?>
							<?php $this->metabox_handler->render_qr_code_box(); ?>
							<?php $this->metabox_handler->render_checkin_settings_box(); ?>
							<?php $this->metabox_handler->render_notification_recipients_box(); ?>
							<?php $this->metabox_handler->render_custom_fields_box(); ?>
						<?php $this->metabox_handler->render_reminder_settings_box(); ?>
						<?php $this->metabox_handler->render_revisions_box(); ?>
						<?php $this->metabox_handler->render_virtual_settings_box(); ?>
					</div>

						<!-- Main metaboxes -->
						<div id="postbox-container-2" class="postbox-container">
							<!-- Additional metaboxes will be added here -->
						</div>
					</div>
				</div>
			</form>
		</div>

		<?php
	}
}
