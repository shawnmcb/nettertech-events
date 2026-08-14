<?php
/**
 * Per-occurrence editor class.
 *
 * Renders the admin screen for editing a single occurrence of a recurring
 * event, with a scope selector (this instance / this and following / all).
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\Metaboxes\TicketsMetabox;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Handles the per-occurrence edit form.
 *
 * Mirrors {@see EventEditor}: this class is an orchestrator that loads the
 * occurrence and its parent event, then delegates markup to the
 * templates/admin/occurrence-edit.php template. The save flow is handled by
 * {@see OccurrenceSaveHandler} via the admin-post action.
 *
 * @since 1.0.4
 */
class OccurrenceEditor {

	/**
	 * Nonce action for the occurrence save form.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'nettertech_events_save_occurrence';

	/**
	 * Nonce field name for the occurrence save form.
	 *
	 * @var string
	 */
	public const NONCE_FIELD = 'nettertech_events_occurrence_nonce';

	/**
	 * Admin-post action name for the occurrence save form.
	 *
	 * @var string
	 */
	public const SAVE_ACTION = 'nettertech_events_save_occurrence';

	/**
	 * Admin-post action for removing one date from an event.
	 *
	 * @var string
	 */
	public const DELETE_ACTION = 'nettertech_events_delete_occurrence';

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Occurrence being edited (null when not found).
	 *
	 * @var Occurrence|null
	 */
	private ?Occurrence $occurrence = null;

	/**
	 * Parent event (null when not found).
	 *
	 * @var Event|null
	 */
	private ?Event $event = null;

	/**
	 * Ticket types for this date.
	 *
	 * @var TicketsMetabox
	 */
	private TicketsMetabox $tickets_metabox;

	/**
	 * Constructor.
	 *
	 * @param int                           $occurrence_id    Occurrence ID.
	 * @param OccurrenceRepositoryInterface $occurrence_repo  Occurrence repository.
	 * @param EventRepositoryInterface      $event_repo       Event repository.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 * @param CapacityServiceInterface      $capacity_service Capacity service.
	 */
	public function __construct(
		int $occurrence_id,
		OccurrenceRepositoryInterface $occurrence_repo,
		EventRepositoryInterface $event_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service
	) {
		$this->occurrence_repo = $occurrence_repo;
		$this->event_repo      = $event_repo;
		$this->tickets_metabox = new TicketsMetabox( $ticket_type_repo, $capacity_service );

		if ( $occurrence_id > 0 ) {
			$this->occurrence = $this->occurrence_repo->find( $occurrence_id );
		}

		// Load the full parent event (find_with_event() returns only a partial
		// event missing event_type / recurrence_rule / venue / virtual fields,
		// all of which the editor needs).
		if ( null !== $this->occurrence && $this->occurrence->event_id > 0 ) {
			$this->event = $this->event_repo->find( $this->occurrence->event_id );
			if ( null !== $this->event ) {
				$this->occurrence->set_event( $this->event );
			}
		}
	}

	/**
	 * Register the save-handler hook.
	 *
	 * @param OccurrenceSaveHandler $save_handler Save handler instance.
	 * @return void
	 */
	public static function register( OccurrenceSaveHandler $save_handler ): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $save_handler, 'handle_save' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $save_handler, 'handle_delete' ) );
	}

	/**
	 * Render the editor screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( null === $this->occurrence || null === $this->event ) {
			$this->render_not_found();
			return;
		}

		$this->enqueue_assets();

		$occurrence = $this->occurrence;
		$event      = $this->event;
		$nte_editor = $this;

		// Surface save feedback carried across the post-save redirect: validation errors
		// (e.g. a rejected zero-length span — NTE-189) and informational notices such as the
		// hand-picked dates skipped by an "apply to all" (R2).
		$nte_save_error  = $this->take_transient( 'nettertech_events_save_error_' . get_current_user_id() );
		$nte_save_notice = $this->take_transient( 'nettertech_events_save_notice_' . get_current_user_id() );

		require dirname( __DIR__, 2 ) . '/templates/admin/occurrence-edit.php';
	}

	/**
	 * Read and delete a one-shot transient, returning its string value or ''.
	 *
	 * @param string $key Transient key.
	 * @return string
	 */
	private function take_transient( string $key ): string {
		$value = get_transient( $key );
		if ( false === $value ) {
			return '';
		}
		delete_transient( $key );
		return (string) $value;
	}

	/**
	 * Enqueue the media picker behavior and form styles for this screen.
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		wp_enqueue_media();

		// Shared suggest-and-type time combobox + inline validation (NTE-190).
		Metaboxes\DateTimeMetaboxHandler::enqueue_time_combobox_assets();

		$style_handle = 'nettertech-events-occurrence-editor';
		wp_register_style( $style_handle, false, array(), NETTERTECH_EVENTS_VERSION );
		wp_enqueue_style( $style_handle );
		wp_add_inline_style(
			$style_handle,
			'.nte-occurrence-edit__context { margin: 12px 0; }
.nte-occurrence-edit__image { max-width: 200px; height: auto; display: block; margin-bottom: 8px; }
.nte-occurrence-edit__image--inherited { opacity: 0.6; }
.nte-occurrence-edit__scope { margin: 16px 0; padding: 12px 16px; border: 1px solid #c3c4c7; border-radius: 4px; max-width: 600px; }
.nte-occurrence-edit__scope-legend { font-weight: 600; padding: 0 4px; }
.nte-occurrence-edit__scope p { margin: 6px 0; }'
		);

		$script_handle = 'nettertech-events-occurrence-editor';
		$config        = array(
			'title'        => __( 'Select Featured Image', 'nettertech-events' ),
			'buttonText'   => __( 'Use Image', 'nettertech-events' ),
			'changeText'   => __( 'Change Image', 'nettertech-events' ),
			'setImageText' => __( 'Set Featured Image', 'nettertech-events' ),
			'selectedMsg'  => __( 'Featured image override set.', 'nettertech-events' ),
			'removedMsg'   => __( 'Featured image override removed.', 'nettertech-events' ),
		);

		wp_register_script( $script_handle, false, array( 'media-editor', 'wp-a11y' ), NETTERTECH_EVENTS_VERSION, true );
		wp_enqueue_script( $script_handle );
		wp_add_inline_script(
			$script_handle,
			'window.nettertechEventsOccurrenceEditor = ' . wp_json_encode( $config ) . ";\n" . <<<'JS'
(function () {
	'use strict';

	var config = window.nettertechEventsOccurrenceEditor || {};
	var hiddenInput = document.getElementById('nte-occurrence-featured-image-id');
	var preview = document.getElementById('nte-occurrence-image-preview');
	var previewImg = preview ? preview.querySelector('img') : null;
	var inherited = document.querySelector('.nte-occurrence-edit__image-inherited');
	var selectBtn = document.getElementById('nte-occurrence-select-image');
	var removeBtn = document.getElementById('nte-occurrence-remove-image');
	var frame;

	function announce(message) {
		if (window.wp && window.wp.a11y && message) {
			window.wp.a11y.speak(message, 'polite');
		}
	}

	if (selectBtn) {
		selectBtn.addEventListener('click', function () {
			if (frame) {
				frame.open();
				return;
			}

			frame = window.wp.media({
				title: config.title,
				button: { text: config.buttonText },
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;

				if (hiddenInput) {
					hiddenInput.value = attachment.id;
				}
				if (previewImg) {
					previewImg.src = url;
				}
				if (preview) {
					preview.style.display = '';
				}
				if (inherited) {
					inherited.style.display = 'none';
				}
				if (removeBtn) {
					removeBtn.style.display = '';
				}
				if (selectBtn) {
					selectBtn.textContent = config.changeText;
				}
				announce(config.selectedMsg);
			});

			frame.open();
		});
	}

	if (removeBtn) {
		removeBtn.addEventListener('click', function () {
			if (hiddenInput) {
				hiddenInput.value = '';
			}
			if (preview) {
				preview.style.display = 'none';
			}
			if (inherited) {
				inherited.style.display = '';
			}
			removeBtn.style.display = 'none';
			if (selectBtn) {
				selectBtn.textContent = config.setImageText;
			}
			announce(config.removedMsg);
		});
	}
})();
JS
		);
	}

	/**
	 * Render the ticket types that belong to this date alone.
	 *
	 * Called from the occurrence-edit template, inside its form, so the tickets save with the date.
	 *
	 * @since 1.1.2
	 *
	 * @return void
	 */
	public function render_tickets_box(): void {
		$this->tickets_metabox->set_context( $this->event, $this->occurrence )->render_for_occurrence();
	}

	/**
	 * Render a "not found" notice when the occurrence cannot be loaded.
	 *
	 * @return void
	 */
	private function render_not_found(): void {
		?>
		<div class="wrap">
			<?php Branding::render_header(); ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Edit Date', 'nettertech-events' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG ) ); ?>" class="page-title-action">
				<?php esc_html_e( '← Back to Events', 'nettertech-events' ); ?>
			</a>
			<hr class="wp-header-end">
			<div class="notice notice-error">
				<p><?php esc_html_e( 'That occurrence could not be found. It may have been deleted or regenerated.', 'nettertech-events' ); ?></p>
			</div>
		</div>
		<?php
	}
}
