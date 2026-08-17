<?php
/**
 * QR Code Metabox Handler.
 *
 * Renders QR code metabox for the event editor.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\QRGeneratorPage;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Utilities\ImageHelper;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Handles rendering of event QR code metabox.
 *
 * @since 1.1.0
 */
class QRCodeMetaboxHandler {

	/**
	 * Event being edited.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Constructor.
	 *
	 * @param Event $event Event being edited.
	 */
	public function __construct( Event $event ) {
		$this->event = $event;
	}

	/**
	 * Render event QR code metabox.
	 *
	 * @return void
	 */
	public function render(): void {
		// Only show for existing events with a slug.
		if ( ! $this->event->id || empty( $this->event->slug ) ) {
			return;
		}

		// Enqueue media library for custom logo selection.
		wp_enqueue_media();

		$event_url = PathHelper::get_event_url( $this->event->slug );
		$colors    = QRGeneratorPage::COLOR_PALETTE;

		// Get plugin default settings.
		$dto = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
		$qr  = $dto->qr;

		// Resolve default color from settings (accepts any valid hex).
		$default_color = strtolower( ltrim( $qr->qr_foreground_color, '#' ) );
		if ( ! preg_match( '/^[0-9a-f]{6}$/', $default_color ) ) {
			$default_color = '000000';
		}

		$default_logo_mode   = $qr->qr_default_logo_mode;
		$default_logo_id     = $qr->qr_default_logo_id;
		$default_description = __( 'No Logo', 'nettertech-events' );
		if ( 'site' === $default_logo_mode ) {
			$default_description = __( 'Site Logo', 'nettertech-events' );
		} elseif ( 'custom' === $default_logo_mode && $default_logo_id > 0 ) {
			$default_description = __( 'Custom Logo', 'nettertech-events' );
		}

		// Get site logo URL (custom_logo → site_logo → site_icon fallback).
		$site_logo_url = '';
		$site_logo_id  = get_theme_mod( 'custom_logo' );
		if ( empty( $site_logo_id ) ) {
			$site_logo_id = get_option( 'site_logo' );
		}
		if ( empty( $site_logo_id ) ) {
			$site_logo_id = get_option( 'site_icon' );
		}
		if ( $site_logo_id ) {
			$site_logo_url = ImageHelper::get_attachment_image_url( (int) $site_logo_id, 'thumbnail' ) ?? '';
		}

		// Get saved event QR logo settings. These live on the events-table row
		// (`qr_logo_mode` / `qr_logo_attachment_id`, migrated off post meta) —
		// `Event->id` is not a WP post ID, so post meta by that id reads nothing
		// or an unrelated post's meta (NTE-210 sweep).
		$event_logo_mode = (string) ( $this->event->qr_logo_mode ?? '' );
		$event_logo_id   = absint( $this->event->qr_logo_attachment_id ?? 0 );
		$event_logo_url  = '';
		if ( 'custom' === $event_logo_mode && $event_logo_id > 0 ) {
			$event_logo_url = ImageHelper::get_attachment_image_url( $event_logo_id, 'thumbnail' ) ?? '';
		}

		// Default to 'default' if not set.
		if ( empty( $event_logo_mode ) ) {
			$event_logo_mode = 'default';
		}

		// Enqueue QR code generator script.
		wp_enqueue_script(
			'nettertech-events-event-qr',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/event-qr.js',
			array( 'jquery' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_localize_script(
			'nettertech-events-event-qr',
			'nettertechEventsEventQr',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'nettertech_events_qr_generator' ),
				'eventUrl'           => $event_url,
				'eventTitle'         => $this->event->title,
				'eventSlug'          => $this->event->slug,
				'defaultColor'       => $default_color,
				'logoMode'           => $event_logo_mode,
				'logoId'             => $event_logo_id,
				'logoUrl'            => $event_logo_url,
				'defaultLogoMode'    => $default_logo_mode,
				'defaultLogoId'      => $default_logo_id,
				'defaultDescription' => $default_description,
				'siteLogoUrl'        => $site_logo_url,
				'i18n'               => array(
					'downloadFailed' => __( 'Download failed.', 'nettertech-events' ),
					'selectLogo'     => __( 'Select Logo', 'nettertech-events' ),
					'useLogo'        => __( 'Use this logo', 'nettertech-events' ),
					'change'         => __( 'Change', 'nettertech-events' ),
					'select'         => __( 'Select', 'nettertech-events' ),
				),
			)
		);

		?>
		<div class="postbox" id="nte-qr-postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Event QR Code', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<div id="nte-event-qr-generator">
					<div id="nte-event-qr-preview" style="text-align: center; margin-bottom: 10px;">
						<img src="" alt="<?php esc_attr_e( 'QR Code', 'nettertech-events' ); ?>"
							style="max-width: 100%; height: auto;">
					</div>

					<div id="nte-event-qr-stale-warning" style="display: none; margin-bottom: 10px;">
						<div class="notice notice-warning inline" style="margin: 0; padding: 8px 12px;">
							<p style="margin: 0;">
								<span class="dashicons dashicons-warning" style="color: #dba617;"></span>
								<?php esc_html_e( 'Title or slug changed. Regenerate QR code to update.', 'nettertech-events' ); ?>
							</p>
						</div>
					</div>

					<div class="nte-qr-color-palette" role="radiogroup" aria-label="<?php esc_attr_e( 'QR Code Color', 'nettertech-events' ); ?>" style="display: flex; gap: 5px; justify-content: center; margin-bottom: 10px;">
						<label class="nte-qr-color-option" style="cursor: pointer;">
							<input type="radio" name="nte-event-qr-color" value="default"
									aria-label="<?php esc_attr_e( 'Default', 'nettertech-events' ); ?>"
									style="position: absolute; opacity: 0; pointer-events: none;"
									checked>
							<span class="nte-qr-color-swatch nte-qr-color-swatch--default"
									style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 3px;
										background: #f0f0f1; border: 2px solid transparent; transition: border-color 0.15s, transform 0.15s;"
									title="<?php esc_attr_e( 'Default', 'nettertech-events' ); ?>" aria-hidden="true">
								<span class="dashicons dashicons-admin-settings" style="font-size: 12px; width: 12px; height: 12px; color: #646970;"></span>
							</span>
						</label>
						<?php foreach ( $colors as $hex => $label ) : ?>
							<label class="nte-qr-color-option" style="cursor: pointer;">
								<input type="radio" name="nte-event-qr-color" value="<?php echo esc_attr( $hex ); ?>"
										aria-label="<?php echo esc_attr( $label ); ?>"
										style="position: absolute; opacity: 0; pointer-events: none;">
								<span class="nte-qr-color-swatch"
										style="display: inline-block; width: 20px; height: 20px; border-radius: 3px;
											background-color: #<?php echo esc_attr( $hex ); ?>;
											border: 2px solid transparent; transition: border-color 0.15s, transform 0.15s;"
										title="<?php echo esc_attr( $label ); ?>" aria-hidden="true"></span>
							</label>
						<?php endforeach; ?>
						<label class="nte-qr-color-option" style="cursor: pointer;">
							<input type="radio" name="nte-event-qr-color" value=""
									aria-label="<?php esc_attr_e( 'Custom', 'nettertech-events' ); ?>"
									class="nte-qr-custom-color-radio"
									style="position: absolute; opacity: 0; pointer-events: none;">
							<span class="nte-qr-color-swatch nte-qr-color-swatch--custom"
									style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 3px;
										background: conic-gradient(red, yellow, lime, aqua, blue, magenta, red);
										border: 2px solid transparent; transition: border-color 0.15s, transform 0.15s;"
									title="<?php esc_attr_e( 'Custom', 'nettertech-events' ); ?>" aria-hidden="true">
								<span class="dashicons dashicons-color-picker" style="font-size: 10px; width: 10px; height: 10px; color: #fff; text-shadow: 0 0 2px rgba(0,0,0,0.5);"></span>
							</span>
							<input type="color" value="#000000" class="nte-qr-custom-color-picker"
								style="position: absolute; opacity: 0; pointer-events: none;"
								aria-hidden="true" tabindex="-1">
						</label>
					</div>

					<?php $this->render_logo_options( $event_logo_mode, $event_logo_id, $event_logo_url, $site_logo_url, $default_description ); ?>

					<p style="text-align: center; margin: 0;">
						<button type="button" class="button" id="nte-event-qr-download">
							<?php esc_html_e( 'Download QR Code', 'nettertech-events' ); ?>
						</button>
					</p>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Render logo options section.
	 *
	 * @param string $event_logo_mode      Current logo mode.
	 * @param int    $event_logo_id        Custom logo attachment ID.
	 * @param string $event_logo_url       Custom logo URL.
	 * @param string $site_logo_url        Site logo URL.
	 * @param string $default_description  Default logo description.
	 * @return void
	 */
	private function render_logo_options(
		string $event_logo_mode,
		int $event_logo_id,
		string $event_logo_url,
		string $site_logo_url,
		string $default_description
	): void {
		$presenter = new Presenters\QRLogoOptionsPresenter(
			$event_logo_mode,
			$event_logo_id,
			$event_logo_url,
			$site_logo_url,
			$default_description
		);
		include dirname( __DIR__, 3 ) . '/templates/admin/metaboxes/qr-logo-options.php';
	}
}
