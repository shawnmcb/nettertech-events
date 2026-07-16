<?php
/**
 * Layout Metabox Handler.
 *
 * Renders page layout metabox for the event editor.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Event;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Handles rendering of page layout metabox and preview.
 *
 * @since 1.1.0
 */
class LayoutMetaboxHandler {

	/**
	 * Event being edited.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Layout service.
	 *
	 * @var LayoutService
	 */
	private LayoutService $layout_service;

	/**
	 * Constructor.
	 *
	 * @param Event         $event          Event being edited.
	 * @param LayoutService $layout_service Layout service.
	 */
	public function __construct( Event $event, LayoutService $layout_service ) {
		$this->event          = $event;
		$this->layout_service = $layout_service;
	}

	/**
	 * Render page layout metabox.
	 *
	 * @return void
	 */
	public function render(): void {
		$components = $this->layout_service->get_components();

		// Get event-specific layout or fall back to defaults.
		$event_layout   = $this->event->layout_config;
		$use_custom     = ! empty( $event_layout );
		$current_layout = $this->layout_service->get_layout( $event_layout );
		$global_layout  = $this->layout_service->get_layout();

		// Enqueue the layout editor assets.
		wp_enqueue_style( 'nettertech-events-layout-editor' );
		wp_enqueue_script( 'nettertech-events-layout-editor' );

		// Enqueue event layout mode toggle and preview script.
		wp_enqueue_script(
			'nettertech-events-event-layout',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/event-layout.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// Expose a fresh preview nonce to both editor scripts. The JS
		// appends `_wpnonce` to the iframe URL so LayoutService can verify
		// the request before honoring the encoded layout in $_GET.
		$preview_localize_data = array(
			'nonce' => wp_create_nonce( LayoutService::PREVIEW_NONCE_ACTION ),
		);
		wp_localize_script(
			'nettertech-events-layout-editor',
			'nettertechEventsLayoutPreview',
			$preview_localize_data
		);
		wp_localize_script(
			'nettertech-events-event-layout',
			'nettertechEventsLayoutPreview',
			$preview_localize_data
		);

		?>
		<div class="postbox" id="nte-layout-postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Page Layout', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<div class="nte-layout-editor" id="nte-layout-editor-event" data-context="metabox">
					<div class="nte-layout-editor__mode">
						<label>
							<input type="radio" name="nettertech_events_layout_mode" value="global" <?php checked( ! $use_custom ); ?>>
							<?php esc_html_e( 'Use global default layout', 'nettertech-events' ); ?>
						</label>
						<label>
							<input type="radio" name="nettertech_events_layout_mode" value="custom" <?php checked( $use_custom ); ?>>
							<?php esc_html_e( 'Customize layout for this event', 'nettertech-events' ); ?>
						</label>
					</div>

					<div class="nte-layout-editor__custom-wrapper <?php echo $use_custom ? 'is-active' : ''; ?>">
						<input type="hidden" name="event_layout_order" id="nte-event-layout-order"
							value="<?php echo esc_attr( implode( ',', $current_layout['order'] ) ); ?>">
						<input type="hidden" name="event_layout_visibility" id="nte-event-layout-visibility"
							value="<?php echo esc_attr( (string) wp_json_encode( $current_layout['visibility'] ) ); ?>">

						<ul class="nte-layout-editor__list" id="nte-event-layout-list" role="listbox" aria-label="<?php esc_attr_e( 'Drag to reorder components', 'nettertech-events' ); ?>">
							<?php foreach ( $current_layout['order'] as $component_id ) : ?>
								<?php if ( isset( $components[ $component_id ] ) ) : ?>
									<?php $component = $components[ $component_id ]; ?>
									<?php $is_visible = $current_layout['visibility'][ $component_id ] ?? true; ?>
									<li class="nte-layout-editor__item <?php echo $is_visible ? '' : 'nte-layout-editor__item--hidden'; ?>"
										data-component-id="<?php echo esc_attr( $component_id ); ?>"
										draggable="true"
										role="option"
										aria-selected="<?php echo $is_visible ? 'true' : 'false'; ?>"
										tabindex="0">
										<span class="nte-layout-editor__handle" aria-hidden="true">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
												<line x1="8" y1="6" x2="16" y2="6"></line>
												<line x1="8" y1="12" x2="16" y2="12"></line>
												<line x1="8" y1="18" x2="16" y2="18"></line>
											</svg>
										</span>
										<label class="nte-layout-editor__toggle">
											<input type="checkbox"
												class="nte-layout-editor__checkbox"
												data-component-id="<?php echo esc_attr( $component_id ); ?>"
												<?php checked( $is_visible ); ?>>
											<span class="nte-layout-editor__toggle-indicator" aria-hidden="true"></span>
										</label>
										<span class="nte-layout-editor__label">
											<?php echo esc_html( $component['label'] ); ?>
										</span>
									</li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul>

						<div class="nte-layout-editor__actions">
							<button type="button" class="button nte-layout-editor__reset" id="nte-event-layout-reset">
								<?php esc_html_e( 'Reset to Global', 'nettertech-events' ); ?>
							</button>
						</div>

						<p class="nte-layout-editor__hint">
							<?php
							printf(
								/* translators: %s: keyboard shortcut keys */
								esc_html__( 'Drag to reorder. Use %1$s to move items with keyboard. Press %2$s to toggle visibility.', 'nettertech-events' ),
								'<kbd>Alt</kbd> + <kbd>↑↓</kbd>',
								'<kbd>Space</kbd>'
							);
							?>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render layout live preview in the main content area.
	 *
	 * @return void
	 */
	public function render_preview(): void {
		// Only show for existing events with a slug.
		if ( ! $this->event->id || empty( $this->event->slug ) ) {
			return;
		}

		$preview_url = PathHelper::get_event_url( $this->event->slug );

		?>
		<div class="postbox nte-layout-preview-postbox" id="nte-layout-preview-postbox" style="margin-top: 20px;">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Layout Preview', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<p class="description" style="margin-bottom: 12px;">
					<?php esc_html_e( 'Live preview of the event page. Changes to the layout in the sidebar will update here automatically.', 'nettertech-events' ); ?>
				</p>
				<div class="nte-layout-preview__frame-wrapper">
					<iframe class="nte-layout-preview__frame"
						id="nte-layout-preview-frame"
						src="<?php echo esc_url( $preview_url ); ?>"
						data-preview-url="<?php echo esc_url( $preview_url ); ?>"
						title="<?php esc_attr_e( 'Event page preview', 'nettertech-events' ); ?>">
					</iframe>
				</div>
				<div class="nte-layout-preview__actions" style="margin-top: 12px; display: flex; gap: 8px;">
					<a href="<?php echo esc_url( $preview_url ); ?>"
						class="button"
						target="_blank"
						rel="noopener noreferrer">
						<?php esc_html_e( 'Open in New Tab', 'nettertech-events' ); ?>
					</a>
					<button type="button" class="button" id="nte-layout-refresh-preview">
						<?php esc_html_e( 'Refresh Preview', 'nettertech-events' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}
}
