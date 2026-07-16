<?php
/**
 * Inline event-metabox content renderer.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\CategoryPage;
use NetterTechEvents\Admin\Metaboxes\Presenters\VenueBoxPresenter;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Utilities\ImageHelper;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Renders the inline-HTML metaboxes that were previously embedded in
 * {@see \NetterTechEvents\Admin\EventMetaboxHandler}.
 *
 * These are the metaboxes whose content lives entirely in PHP-with-HTML
 * blocks rather than in their own specialized handler class (DateTime,
 * Ticketing, Layout, QR, Revision, Organizer, Space, AttendeeFields
 * already have dedicated handlers).
 *
 * Extracted as part of the god-class decomposition. Internal
 * collaborator only; not registered in the DI container —
 * EventMetaboxHandler constructs it on demand inside each delegating
 * facade method. The public hook callbacks remain on
 * EventMetaboxHandler so the WP `add_meta_box` registrations stay
 * byte-identical.
 *
 * @since 2.2.0
 * @internal
 */
class EventMetaboxContentRenderer {

	/**
	 * Event being edited.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Event ID (0 for new).
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Category repository.
	 *
	 * @var CategoryRepositoryInterface
	 */
	private CategoryRepositoryInterface $category_repo;

	/**
	 * Memoized scheduled-date count for a stored-single event (null = unresolved).
	 *
	 * @var int|null
	 */
	private ?int $multi_date_count = null;

	/**
	 * Constructor.
	 *
	 * @param Event                       $event         Event being edited.
	 * @param int                         $event_id      Event ID (0 for new).
	 * @param CategoryRepositoryInterface $category_repo Category repository.
	 */
	public function __construct( Event $event, int $event_id, CategoryRepositoryInterface $category_repo ) {
		$this->event         = $event;
		$this->event_id      = $event_id;
		$this->category_repo = $category_repo;
	}

	/**
	 * Render publish metabox.
	 *
	 * @return void
	 */
	public function render_publish_box(): void {
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Publish', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<div class="submitbox">
					<div style="margin-bottom: 12px;">
						<label for="event_status">
							<strong><?php esc_html_e( 'Status:', 'nettertech-events' ); ?></strong>
						</label>
						<select name="event_status" id="event_status" style="width: 100%; margin-top: 4px;">
							<option value="draft" <?php selected( $this->event->status->value, 'draft' ); ?>>
								<?php esc_html_e( 'Draft', 'nettertech-events' ); ?>
							</option>
							<option value="published" <?php selected( $this->event->status->value, 'published' ); ?>>
								<?php esc_html_e( 'Published', 'nettertech-events' ); ?>
							</option>
							<option value="cancelled" <?php selected( $this->event->status->value, 'cancelled' ); ?>>
								<?php esc_html_e( 'Cancelled', 'nettertech-events' ); ?>
							</option>
							<option value="postponed" <?php selected( $this->event->status->value, 'postponed' ); ?>>
								<?php esc_html_e( 'Postponed', 'nettertech-events' ); ?>
							</option>
						</select>
					</div>

					<div style="margin-bottom: 12px;">
						<label for="event_type">
							<strong><?php esc_html_e( 'Event Type:', 'nettertech-events' ); ?></strong>
						</label>
						<?php if ( $this->multi_date_single_count() > 1 ) : ?>
							<?php
							// An event with several dates cannot present as "Single
							// Event" (NTE-159): the control locks to what the schedule
							// says, the stored type rides along unchanged, and becoming
							// single again means removing dates — which the Schedule
							// box already does, with its sold-seat guards.
							?>
							<input type="hidden" name="event_type" value="single">
							<p style="margin: 4px 0 0;">
								<strong>
									<?php
									printf(
										/* translators: %d: number of dates on the event. */
										esc_html__( 'Recurring — %d dates', 'nettertech-events' ),
										(int) $this->multi_date_single_count()
									);
									?>
								</strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'This event runs on more than one date, so it lists as recurring. To make it a single event, remove the extra dates below.', 'nettertech-events' ); ?>
							</p>
						<?php else : ?>
							<select name="event_type" id="event_type" style="width: 100%; margin-top: 4px;">
								<option value="single" <?php selected( $this->event->event_type, 'single' ); ?>>
									<?php esc_html_e( 'Single Event', 'nettertech-events' ); ?>
								</option>
								<option value="recurring" <?php selected( $this->event->event_type, 'recurring' ); ?>>
									<?php esc_html_e( 'Recurring Event', 'nettertech-events' ); ?>
								</option>
							</select>
						<?php endif; ?>
					</div>

					<?php $this->render_conversion_box(); ?>

					<?php if ( $this->event->id ) : ?>
						<div style="margin-bottom: 12px; color: #666; font-size: 12px;">
							<?php
							// created_at is stored in UTC (NTE-131); get_date_from_gmt converts
							// to the site's local timezone for display, portably across envs.
							$created_at_utc = $this->event->created_at ?? gmdate( 'Y-m-d H:i:s' );
							printf(
								/* translators: %s: date */
								esc_html__( 'Created: %s', 'nettertech-events' ),
								esc_html( get_date_from_gmt( $created_at_utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
							);
							?>
						</div>
					<?php endif; ?>

					<?php if ( $this->event->id && ! empty( $this->event->slug ) ) : ?>
						<div id="preview-action" style="margin-bottom: 12px;">
							<?php
							$preview_url = PathHelper::get_event_url( $this->event->slug );
							if ( ! $this->event->is_published() ) {
								$preview_url = add_query_arg(
									array(
										'preview'  => 'true',
										'_wpnonce' => wp_create_nonce( 'nettertech_events_preview_' . $this->event->id ),
									),
									$preview_url
								);
							}
							?>
							<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button">
								<?php echo $this->event->is_published() ? esc_html__( 'View Event', 'nettertech-events' ) : esc_html__( 'Preview', 'nettertech-events' ); ?>
							</a>
						</div>
					<?php endif; ?>

					<div id="major-publishing-actions">
						<?php if ( $this->event->id ) : ?>
							<div id="delete-action">
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&action=delete&event_id=' . $this->event->id ), 'delete_event_' . $this->event->id ) ); ?>"
									class="submitdelete deletion"
									onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this event?', 'nettertech-events' ); ?>');">
									<?php esc_html_e( 'Delete', 'nettertech-events' ); ?>
								</a>
							</div>
						<?php endif; ?>
						<div id="publishing-action">
							<input type="submit" name="publish" class="button button-primary button-large"
									value="<?php echo $this->event->id ? esc_attr__( 'Update', 'nettertech-events' ) : esc_attr__( 'Save', 'nettertech-events' ); ?>">
						</div>
						<div class="clear"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render venue metabox.
	 *
	 * @return void
	 */
	public function render_venue_box(): void {
		// Pre-populate with default venue for new events if enabled.
		$venue_name    = $this->event->venue_name ?? '';
		$venue_address = $this->event->venue_address ?? '';

		if ( 0 === $this->event_id && empty( $venue_name ) && empty( $venue_address ) ) {
			$dto = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
			if ( $dto->display->default_venue_enabled ) {
				$venue_name    = $dto->display->default_venue_name;
				$venue_address = $dto->display->default_venue_address;
			}
		}

		// VenueBoxPresenter is a per-render value object (DTO) — not a service —
		// so direct construction is correct architecturally. The `use` import above
		// satisfies the SA-19 audit pattern (no FQCN `new \NetterTechEvents\...`
		// at call sites) while preserving the value-object semantics.
		$presenter = new VenueBoxPresenter(
			(string) $venue_name,
			(string) $venue_address
		);
		include dirname( __DIR__, 3 ) . '/templates/admin/metaboxes/venue-box.php';
	}

	/**
	 * Render categories metabox.
	 *
	 * Displays a checkbox list of categories from nettertech_events_categories table
	 * for assigning categories to the event.
	 *
	 * @return void
	 */
	public function render_categories_box(): void {
		$all_categories = $this->category_repo->get_all(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$assigned_ids   = array();

		if ( $this->event_id > 0 ) {
			$assigned     = $this->category_repo->find_by_event( $this->event_id );
			$assigned_ids = array_map(
				static function ( $cat ) {
					return $cat->id;
				},
				$assigned
			);
		}
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Categories', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<?php if ( empty( $all_categories ) ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: URL to category admin page */
							esc_html__( 'No categories found. %s to create one.', 'nettertech-events' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=' . CategoryPage::PAGE_SLUG . '&action=add' ) ) . '">'
								. esc_html__( 'Go to Categories', 'nettertech-events' ) . '</a>'
						);
						?>
					</p>
				<?php else : ?>
					<ul style="max-height: 200px; overflow-y: auto; margin: 0; padding: 0 0 0 2px;">
						<?php foreach ( $all_categories as $category ) : ?>
							<li>
								<label>
									<input type="checkbox"
										name="event_categories[]"
										value="<?php echo esc_attr( (string) $category->id ); ?>"
										<?php checked( in_array( $category->id, $assigned_ids, true ) ); ?>>
									<?php echo esc_html( $category->name ); ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p style="margin-top: 10px;">
					<label for="nettertech-events-new-categories" class="screen-reader-text">
						<?php esc_html_e( 'Add new categories (comma-separated)', 'nettertech-events' ); ?>
					</label>
					<input type="text"
						id="nettertech-events-new-categories"
						name="new_category_names"
						class="widefat"
						value=""
						placeholder="<?php esc_attr_e( 'Add new categories (comma-separated)', 'nettertech-events' ); ?>">
				</p>
				<p class="description">
					<?php esc_html_e( 'New category names are created and assigned automatically on save.', 'nettertech-events' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render tags metabox.
	 *
	 * Comma-separated input mirroring WP core's post-tag UX. On save,
	 * EventSaveHandler splits the value, trims each, find_or_create()s
	 * each, then sync_event_tags() atomically replaces the event's tag set.
	 *
	 * @return void
	 */
	public function render_tags_box(): void {
		$tag_repo     = ServiceRegistry::tag_repository();
		$all_tags     = $tag_repo->get_all(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$assigned_ids = array();

		if ( $this->event_id > 0 ) {
			$assigned     = $tag_repo->find_by_event( $this->event_id );
			$assigned_ids = array_map(
				static function ( $tag ) {
					return $tag->id;
				},
				$assigned
			);
		}
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Tags', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<?php if ( empty( $all_tags ) ) : ?>
					<p>
						<?php esc_html_e( 'No tags yet. Add your first tag below.', 'nettertech-events' ); ?>
					</p>
				<?php else : ?>
					<ul style="max-height: 200px; overflow-y: auto; margin: 0; padding: 0 0 0 2px;">
						<?php foreach ( $all_tags as $tag ) : ?>
							<li>
								<label>
									<input type="checkbox"
										name="event_tags[]"
										value="<?php echo esc_attr( (string) $tag->id ); ?>"
										<?php checked( in_array( $tag->id, $assigned_ids, true ) ); ?>>
									<?php echo esc_html( $tag->name ); ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p style="margin-top: 10px;">
					<label for="nettertech-events-new-tags" class="screen-reader-text">
						<?php esc_html_e( 'Add new tags (comma-separated)', 'nettertech-events' ); ?>
					</label>
					<input type="text"
						id="nettertech-events-new-tags"
						name="new_tag_names"
						class="widefat"
						value=""
						placeholder="<?php esc_attr_e( 'Add new tags (comma-separated)', 'nettertech-events' ); ?>">
				</p>
				<p class="description">
					<?php esc_html_e( 'New tag names are created and assigned automatically on save.', 'nettertech-events' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render featured image metabox.
	 *
	 * @return void
	 */
	public function render_featured_image_box(): void {
		$image_id   = $this->event->featured_image_id ?? 0;
		$image_url  = $image_id ? ( ImageHelper::get_attachment_image_url( $image_id, 'medium' ) ?? '' ) : '';
		$anchor     = $this->event->image_vertical_anchor ?? 'center';
		$ratio_hint = $this->resolve_cards_image_hint();
		$this->enqueue_featured_image_script();

		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Featured Image', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<div id="nettertech-events-featured-image">
					<input type="hidden" name="featured_image_id" id="featured_image_id"
							value="<?php echo esc_attr( (string) $image_id ); ?>">

					<div id="featured-image-preview" style="margin-bottom: 10px; <?php echo $image_url ? '' : 'display:none;'; ?>">
						<img src="<?php echo esc_url( $image_url ); ?>" style="max-width: 100%; height: auto;">
					</div>

					<p>
						<button type="button" class="button" id="select-featured-image" style="padding-left: 12px; padding-right: 12px;">
							<?php echo $image_id ? esc_html__( 'Change Image', 'nettertech-events' ) : esc_html__( 'Set Featured Image', 'nettertech-events' ); ?>
						</button>
						<button type="button" class="button" id="remove-featured-image" style="<?php echo $image_id ? '' : 'display:none;'; ?>">
							<?php esc_html_e( 'Remove', 'nettertech-events' ); ?>
						</button>
					</p>

					<p class="description" style="margin-top: 8px;">
						<?php
						printf(
							/* translators: 1: aspect ratio label (e.g. 16:9), 2: recommended pixel dimensions (e.g. 1200 × 675 px). */
							esc_html__( 'Events page (cards) ratio: %1$s. Recommended upload: at least %2$s.', 'nettertech-events' ),
							esc_html( $ratio_hint['ratio_label'] ),
							esc_html( $ratio_hint['dimensions'] )
						);
						?>
					</p>

					<p style="margin-top: 12px;">
						<label for="image_vertical_anchor" style="display: block; margin-bottom: 4px; font-weight: 600;">
							<?php esc_html_e( 'Crop Anchor', 'nettertech-events' ); ?>
						</label>
						<select name="image_vertical_anchor" id="image_vertical_anchor" class="widefat">
							<option value="center" <?php selected( $anchor, 'center' ); ?>><?php esc_html_e( 'Center (default)', 'nettertech-events' ); ?></option>
							<option value="top" <?php selected( $anchor, 'top' ); ?>><?php esc_html_e( 'Top', 'nettertech-events' ); ?></option>
							<option value="bottom" <?php selected( $anchor, 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'nettertech-events' ); ?></option>
						</select>
						<span class="description" style="display: block; margin-top: 4px;">
							<?php esc_html_e( 'Which part of the image to keep when it is cropped to the event-page aspect ratio. Horizontal stays centered.', 'nettertech-events' ); ?>
						</span>
					</p>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Resolve the Events-page (cards) aspect ratio and a recommended upload size.
	 *
	 * Reads the cards ratio exactly as {@see \NetterTechEvents\Core\InlineCssGenerator}
	 * does: the per-view `image_aspect_ratio_cards` setting, falling back to the
	 * site default `image_aspect_ratio`, then to 16:9. The recommended pixel
	 * dimensions are derived from that ratio at a retina-friendly base width so
	 * uploads crop cleanly to the card crop box.
	 *
	 * @since 1.1.2
	 *
	 * @return array{ratio_label: string, dimensions: string}
	 */
	private function resolve_cards_image_hint(): array {
		$display   = \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->display;
		$sanitizer = new \NetterTechEvents\Admin\SettingsSanitizer();

		// Cards-view override first, then the site default (mirrors InlineCssGenerator).
		$cards = $display->get_view_aspect_ratio( 'cards' );
		$css   = '' !== $cards['preset'] ? $sanitizer->get_aspect_ratio_css( $cards['preset'], $cards['custom'] ) : '';
		if ( '' === $css ) {
			$css = $sanitizer->get_aspect_ratio_css( $display->image_aspect_ratio, $display->image_aspect_ratio_custom );
		}

		// Base width for the recommended dimensions (retina-friendly card width).
		$base_width = 1200;

		// 'auto' is the "original" preset — no crop, so recommend a minimum width only.
		if ( 'auto' === $css || '' === $css ) {
			return array(
				'ratio_label' => __( 'Original (no crop)', 'nettertech-events' ),
				/* translators: %d: pixel width. */
				'dimensions'  => sprintf( __( '%dpx wide', 'nettertech-events' ), $base_width ),
			);
		}

		// $css is "W / H"; derive the height for the base width.
		$parts = array_map( 'trim', explode( '/', $css ) );
		$w     = (float) $parts[0];
		$h     = isset( $parts[1] ) ? (float) $parts[1] : 0.0;

		if ( $w <= 0 || $h <= 0 ) {
			return array(
				'ratio_label' => '16:9',
				'dimensions'  => '1200 × 675 px',
			);
		}

		$height = (int) round( $base_width * ( $h / $w ) );

		return array(
			'ratio_label' => str_replace( ' / ', ':', $css ),
			'dimensions'  => sprintf( '%d × %d px', $base_width, $height ),
		);
	}

	/**
	 * Enqueue featured image picker behavior.
	 *
	 * @return void
	 */
	private function enqueue_featured_image_script(): void {
		wp_enqueue_media();

		$handle = 'nettertech-events-featured-image-metabox';
		$config = array(
			'title'        => __( 'Select Featured Image', 'nettertech-events' ),
			'buttonText'   => __( 'Use Image', 'nettertech-events' ),
			'changeText'   => __( 'Change Image', 'nettertech-events' ),
			'setImageText' => __( 'Set Featured Image', 'nettertech-events' ),
		);

		wp_register_script( $handle, false, array( 'jquery' ), NETTERTECH_EVENTS_VERSION, true );
		wp_enqueue_script( $handle );
		wp_add_inline_script(
			$handle,
			'window.nettertechEventsFeaturedImageMetabox = ' . wp_json_encode( $config ) . ';
jQuery(document).ready(function($) {
	var mediaFrame;

	$("#select-featured-image").on("click", function(e) {
		e.preventDefault();

		if (mediaFrame) {
			mediaFrame.open();
			return;
		}

		mediaFrame = wp.media({
			title: window.nettertechEventsFeaturedImageMetabox.title,
			button: { text: window.nettertechEventsFeaturedImageMetabox.buttonText },
			multiple: false
		});

		mediaFrame.on("select", function() {
			var attachment = mediaFrame.state().get("selection").first().toJSON();
			$("#featured_image_id").val(attachment.id);
			$("#featured-image-preview img").attr("src", attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url);
			$("#featured-image-preview").show();
			$("#remove-featured-image").show();
			$("#select-featured-image").text(window.nettertechEventsFeaturedImageMetabox.changeText);
		});

		mediaFrame.open();
	});

	$("#remove-featured-image").on("click", function(e) {
		e.preventDefault();
		$("#featured_image_id").val("");
		$("#featured-image-preview").hide();
		$(this).hide();
		$("#select-featured-image").text(window.nettertechEventsFeaturedImageMetabox.setImageText);
	});
});'
		);
	}

	/**
	 * Render check-in settings metabox.
	 *
	 * @return void
	 */
	public function render_checkin_settings_box(): void {
		// Only show for existing events.
		if ( ! $this->event->id ) {
			return;
		}

		// Get current email recipients.
		$emails      = get_option( 'nettertech_events_event_checkin_emails_' . $this->event->id, array() );
		$emails_text = is_array( $emails ) ? implode( "\n", $emails ) : '';

		// Get system default email from settings.
		$default_email = \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->checkin->checkin_completion_email;

		?>
		<div class="postbox" id="nte-checkin-settings-postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Check-In Settings', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<p>
					<label for="checkin_emails"><strong><?php esc_html_e( 'Report Recipients:', 'nettertech-events' ); ?></strong></label>
				</p>
				<?php if ( ! empty( $default_email ) ) : ?>
					<p class="description" style="margin-bottom: 8px;">
						<?php
						printf(
							/* translators: %s: system default email */
							esc_html__( 'System default: %s (always receives reports)', 'nettertech-events' ),
							'<code>' . esc_html( $default_email ) . '</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p class="description" style="margin-bottom: 8px;">
						<?php esc_html_e( 'No system default email configured.', 'nettertech-events' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nettertech-events-settings' ) ); ?>">
							<?php esc_html_e( 'Configure', 'nettertech-events' ); ?>
						</a>
					</p>
				<?php endif; ?>
				<textarea name="checkin_emails" id="checkin_emails" rows="3" class="widefat"
					placeholder="<?php esc_attr_e( 'Additional email addresses (one per line)', 'nettertech-events' ); ?>"
				><?php echo esc_textarea( $emails_text ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Additional emails that receive check-in completion reports for this event. One address per line.', 'nettertech-events' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render notification recipients metabox.
	 *
	 * @return void
	 */
	public function render_notification_recipients_box(): void {
		$emails_raw  = $this->event->notification_emails ?? '';
		$emails_text = '' !== $emails_raw
			? implode( "\n", array_map( 'trim', explode( ',', $emails_raw ) ) )
			: '';

		$global_contacts = $this->get_global_venue_contacts_display();
		$settings_url    = admin_url( 'admin.php?page=nettertech-events-settings&tab=email' );
		?>
		<div class="postbox" id="nte-notification-recipients-postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Notification Recipients', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<?php if ( ! empty( $global_contacts ) ) : ?>
					<p class="description" style="margin-bottom: 8px;">
						<?php
						printf(
							/* translators: 1: comma-separated global emails, 2: opening <a> tag, 3: closing </a> tag */
							esc_html__( 'Global recipients (%1$s) always receive notifications. %2$sConfigure%3$s', 'nettertech-events' ),
							'<code>' . esc_html( $global_contacts ) . '</code>',
							'<a href="' . esc_url( $settings_url ) . '">',
							'</a>'
						);
						?>
					</p>
				<?php else : ?>
					<p class="description" style="margin-bottom: 8px;">
						<?php
						printf(
							/* translators: 1: opening <a> tag, 2: closing </a> tag */
							esc_html__( 'No global notification recipients configured. %1$sConfigure%2$s', 'nettertech-events' ),
							'<a href="' . esc_url( $settings_url ) . '">',
							'</a>'
						);
						?>
					</p>
				<?php endif; ?>
				<textarea name="notification_emails" id="notification_emails" rows="3" class="widefat"
					placeholder="<?php esc_attr_e( 'Additional email addresses (one per line)', 'nettertech-events' ); ?>"
				><?php echo esc_textarea( $emails_text ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Additional emails that receive ticket purchase and RSVP notifications for this event. One address per line.', 'nettertech-events' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Get global venue contacts as a display string.
	 *
	 * @return string Comma-separated email addresses, or empty string.
	 */
	private function get_global_venue_contacts_display(): string {
		$settings = get_option( 'nettertech_events_email_settings', array() );
		$contacts = $settings['venue_contacts'] ?? array();

		if ( is_string( $contacts ) ) {
			$contacts = array_map( 'trim', explode( ',', $contacts ) );
		}

		$valid = array_filter(
			$contacts,
			static function ( string $email ): bool {
				return (bool) is_email( $email );
			}
		);
		return implode( ', ', $valid );
	}

	/**
	 * Render event reminder settings box.
	 *
	 * @return void
	 */
	public function render_reminder_settings_box(): void {
		$email_settings = get_option( 'nettertech_events_email_settings', array() );
		$site_default   = (bool) ( $email_settings['enable_reminders'] ?? true );

		// Resolve display state: per-event override or site default.
		$is_checked = $this->event->reminders_enabled ?? $site_default;
		$is_custom  = null !== $this->event->reminders_enabled;
		?>
		<div class="postbox" id="nte-reminder-settings-postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Reminder Emails', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<p>
					<label>
						<input type="hidden" name="reminders_enabled_set" value="1">
						<input type="checkbox" name="reminders_enabled" value="1"
							<?php checked( $is_checked ); ?>>
						<strong><?php esc_html_e( 'Send 24-hour reminder emails to attendees', 'nettertech-events' ); ?></strong>
					</label>
				</p>
				<p class="description">
					<?php if ( $is_custom ) : ?>
						<?php esc_html_e( 'This event overrides the site default.', 'nettertech-events' ); ?>
					<?php else : ?>
						<?php
						printf(
							/* translators: %s: site default state */
							esc_html__( 'Using site default (%s). Save to override.', 'nettertech-events' ),
							$site_default ? esc_html__( 'enabled', 'nettertech-events' ) : esc_html__( 'disabled', 'nettertech-events' )
						);
						?>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render virtual/hybrid event settings metabox.
	 *
	 * @return void
	 */
	public function render_virtual_settings_box(): void {
		$is_virtual  = $this->event->is_virtual ?? false;
		$virtual_url = $this->event->virtual_url ?? '';
		?>
		<div class="postbox" id="nte-virtual-settings-postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Virtual Event', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<p>
					<label>
						<input type="checkbox" name="is_virtual" id="nettertech_events_is_virtual" value="1"
							<?php checked( $is_virtual ); ?>>
						<strong><?php esc_html_e( 'This is a virtual (online) event', 'nettertech-events' ); ?></strong>
					</label>
				</p>

				<div id="nte-virtual-url-wrap" style="<?php echo $is_virtual ? '' : 'display: none;'; ?>">
					<p>
						<label for="virtual_url">
							<strong><?php esc_html_e( 'Virtual Event URL:', 'nettertech-events' ); ?></strong>
						</label>
						<input type="url" name="virtual_url" id="virtual_url"
								value="<?php echo esc_url( $virtual_url ); ?>"
								class="widefat"
								placeholder="<?php esc_attr_e( 'https://zoom.us/j/...', 'nettertech-events' ); ?>">
					</p>
					<p class="description">
						<?php esc_html_e( 'Link to the virtual event (Zoom, Teams, YouTube, etc.). If a venue is also set, the event is treated as hybrid.', 'nettertech-events' ); ?>
					</p>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Render custom fields metabox (read-only).
	 *
	 * @return void
	 */
	public function render_custom_fields_box(): void {
		if ( empty( $this->event->custom_fields ) ) {
			return;
		}
		?>
		<div class="postbox" id="nte-custom-fields-postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Custom Fields', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<p class="description" style="margin-bottom: 8px;">
					<?php esc_html_e( 'These fields were set by an integration or import tool. They are read-only.', 'nettertech-events' ); ?>
				</p>
				<table class="widefat striped" style="border: 0;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Key', 'nettertech-events' ); ?></th>
							<th><?php esc_html_e( 'Value', 'nettertech-events' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $this->event->custom_fields as $key => $value ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $key ); ?></code></td>
								<td>
									<?php
									if ( is_array( $value ) || is_object( $value ) ) {
										$encoded = wp_json_encode( $value, JSON_PRETTY_PRINT );
										echo '<pre style="margin: 0; white-space: pre-wrap;">' . esc_html( false !== $encoded ? $encoded : '' ) . '</pre>';
									} else {
										echo esc_html( (string) $value );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Scheduled-date count for a stored-single event, 0 otherwise.
	 *
	 * Decides whether the type control locks to "Recurring — N dates"
	 * (NTE-159): a stored-single event with several dates is recurring as far
	 * as the operator is concerned, whatever the column says. Memoized — the
	 * publish box reads it more than once per render.
	 *
	 * @return int Scheduled occurrence count, or 0 when not a saved single event.
	 */
	private function multi_date_single_count(): int {
		if ( null !== $this->multi_date_count ) {
			return $this->multi_date_count;
		}

		if ( ! $this->event_id || $this->event->is_recurring() ) {
			$this->multi_date_count = 0;
			return 0;
		}

		$occurrence_repo        = ServiceRegistry::get( OccurrenceRepositoryInterface::class );
		$this->multi_date_count = (int) $occurrence_repo->count_for_event( $this->event_id, 'scheduled' );

		return $this->multi_date_count;
	}

	/**
	 * Ask which date survives, when a recurring event is being made single.
	 *
	 * Hidden until the operator actually picks "Single Event" — an event that is staying recurring
	 * has no business being asked this. Rendered server-side and revealed by script, so the choice
	 * still posts if the script never runs: with no script the box is simply visible from the
	 * start, which is ugly but not wrong. Silently defaulting to "the first date" with no
	 * disclosure is what the old behaviour effectively did, and it is what we are fixing.
	 *
	 * Only for an event that already exists and already recurs. A brand-new event has no dates to
	 * choose between.
	 *
	 * @since 1.1.2
	 *
	 * @return void
	 */
	private function render_conversion_box(): void {
		if ( ! $this->event_id || ! $this->event->is_recurring() ) {
			return;
		}

		$occurrence_repo = ServiceRegistry::get( OccurrenceRepositoryInterface::class );
		$occurrences     = $occurrence_repo->for_event( $this->event_id, array( 'limit' => 1000 ) );

		if ( count( $occurrences ) < 2 ) {
			return;
		}

		wp_enqueue_script(
			'nettertech-events-event-conversion',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/event-conversion.js',
			array( 'wp-a11y' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		$style = 'nettertech-events-conversion';
		wp_register_style( $style, false, array(), NETTERTECH_EVENTS_VERSION );
		wp_enqueue_style( $style );
		wp_add_inline_style(
			$style,
			'.nettertech-events-conversion__warning { margin: 0 0 10px; padding: 8px 10px; border-left: 4px solid #dba617; background: #fcf9e8; }'
		);

		$removed = count( $occurrences ) - 1;

		/* translators: %s: number of other dates that would be removed. */
		$warning = _n(
			'Making this a single event will remove %s other date.',
			'Making this a single event will remove %s other dates.',
			$removed,
			'nettertech-events'
		);
		?>
		<div
			id="nettertech-events-conversion-box"
			class="nettertech-events-conversion"
			style="display: none; margin-bottom: 12px;"
		>
			<?php
			// Deliberately NOT class="notice": WordPress admin JS hoists every .notice out of
			// wherever it was written and drops it at the top of the page. A warning about the
			// control directly below it is useless a metre away from that control.
			?>
			<p class="nettertech-events-conversion__warning">
				<?php echo esc_html( sprintf( $warning, number_format_i18n( $removed ) ) ); ?>
				<?php esc_html_e( 'A date that has sold tickets will not be removed — the save is refused instead, and tells you which.', 'nettertech-events' ); ?>
			</p>

			<label for="nettertech_events_keep_occurrence">
				<strong><?php esc_html_e( 'Date to keep:', 'nettertech-events' ); ?></strong>
			</label>
			<select
				name="nettertech_events_keep_occurrence"
				id="nettertech_events_keep_occurrence"
				style="width: 100%; margin-top: 4px;"
			>
				<?php foreach ( $occurrences as $occurrence ) : ?>
					<option value="<?php echo esc_attr( (string) $occurrence->id ); ?>">
						<?php
						echo esc_html(
							(string) wp_date(
								get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
								$occurrence->get_start()->getTimestamp()
							)
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}
}
