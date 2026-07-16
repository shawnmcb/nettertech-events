<?php
/**
 * QR Generator Admin Page.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\QRGenerator\Presenters\QRColorPalettePresenter;
use NetterTechEvents\Services\QRCodeService;
use NetterTechEvents\Utilities\ImageHelper;

/**
 * Renders the QR Generator admin page.
 *
 * Provides a standalone tool for generating QR codes from any text or URL.
 * Uses smart filename generation based on content type.
 *
 * @since 0.8.0
 */
class QRGeneratorPage {

	/**
	 * QR code color palette.
	 *
	 * @var array<string, string>
	 */
	public const COLOR_PALETTE = array(
		'000000' => 'Black',
		'1e3a5f' => 'Navy',
		'722f37' => 'Burgundy',
		'228b22' => 'Forest Green',
		'4b0082' => 'Deep Purple',
		'3d2314' => 'Dark Brown',
	);

	/**
	 * Handle AJAX request to get page title from URL.
	 *
	 * @return void
	 */
	public function handle_get_page_title(): void {
		// Check capability FIRST (before nonce to avoid timing attacks).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'nettertech-events' ), 403 );
		}

		check_ajax_referer( 'nettertech_events_qr_generator', '_wpnonce' );

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'No URL provided.', 'nettertech-events' ) ) );
		}

		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			$title = get_the_title( $post_id );
			wp_send_json_success( array( 'title' => $title ) );
		}

		wp_send_json_error( array( 'message' => __( 'Page not found.', 'nettertech-events' ) ) );
	}

	/**
	 * Handle AJAX request to generate a QR code.
	 *
	 * Returns a base64 data URI of the generated QR code PNG.
	 * Supports optional logo embedding via logo_mode and logo_id parameters.
	 *
	 * @return void
	 */
	public function handle_generate_qr(): void {
		// Check capability FIRST (before nonce to avoid timing attacks).
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'nettertech-events' ), 403 );
		}

		check_ajax_referer( 'nettertech_events_qr_generator', '_wpnonce' );

		// Get and validate content parameter.
		$content = isset( $_POST['content'] ) ? sanitize_text_field( wp_unslash( $_POST['content'] ) ) : '';

		if ( empty( $content ) ) {
			wp_send_json_error( array( 'message' => __( 'No content provided.', 'nettertech-events' ) ) );
		}

		// Limit content length to prevent abuse.
		if ( strlen( $content ) > 2048 ) {
			wp_send_json_error( array( 'message' => __( 'Content too long. Maximum 2048 characters.', 'nettertech-events' ) ) );
		}

		// Get optional color parameter — resolve 'default' to settings value.
		$color_raw = isset( $_POST['color'] ) ? sanitize_key( wp_unslash( $_POST['color'] ) ) : '';
		if ( 'default' === $color_raw ) {
			$qr_dto = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
			$color  = strtolower( ltrim( $qr_dto->qr->qr_foreground_color, '#' ) );
			if ( ! preg_match( '/^[0-9a-f]{6}$/', $color ) ) {
				$color = '000000';
			}
		} else {
			$color = sanitize_hex_color_no_hash( $color_raw );
		}

		// Get optional logo parameters.
		$valid_logo_modes = array( 'none', 'default', 'site', 'custom' );
		$logo_mode        = isset( $_POST['logo_mode'] ) ? sanitize_key( wp_unslash( $_POST['logo_mode'] ) ) : 'none';
		$logo_mode        = in_array( $logo_mode, $valid_logo_modes, true ) ? $logo_mode : 'none';
		$logo_id          = isset( $_POST['logo_id'] ) ? absint( wp_unslash( $_POST['logo_id'] ) ) : 0;

		// Get optional background color parameter.
		$bg_color_raw = isset( $_POST['bg_color'] ) ? sanitize_key( wp_unslash( $_POST['bg_color'] ) ) : '';
		$bg_color     = sanitize_hex_color_no_hash( $bg_color_raw );

		// Get optional scale parameter.
		$scale = isset( $_POST['scale'] ) ? absint( wp_unslash( $_POST['scale'] ) ) : 0;

		// Get optional background opacity parameter (0-100%).
		$bg_opacity = isset( $_POST['bg_opacity'] ) ? absint( wp_unslash( $_POST['bg_opacity'] ) ) : 100;
		$bg_opacity = min( 100, $bg_opacity );

		// Generate QR code using the service.
		$qr_service = new QRCodeService( \NetterTechEvents\Core\NetterTechEventsSettings::from_option() );

		if ( ! empty( $color ) ) {
			$qr_service->set_foreground_color( $color );
		}

		if ( ! empty( $bg_color ) ) {
			$qr_service->set_background_color( $bg_color );
		}

		if ( $scale > 0 ) {
			$qr_service->set_size( $scale );
		}

		$qr_service->set_background_opacity( $bg_opacity );

		// Get optional dot style parameter.
		$dot_style = isset( $_POST['dot_style'] ) ? sanitize_key( wp_unslash( $_POST['dot_style'] ) ) : '';
		if ( ! empty( $dot_style ) ) {
			$qr_service->set_dot_style( $dot_style );
		}

		// Get optional finder style parameter.
		$finder_style = isset( $_POST['finder_style'] ) ? sanitize_key( wp_unslash( $_POST['finder_style'] ) ) : '';
		if ( ! empty( $finder_style ) ) {
			$qr_service->set_finder_style( $finder_style );
		}

		// Configure logo based on mode.
		$use_logo = $this->configure_logo_for_service( $qr_service, $logo_mode, $logo_id );

		try {
			if ( $use_logo && $qr_service->has_logo() ) {
				$data_uri = $qr_service->generate_data_uri_with_logo( $content );
			} else {
				$data_uri = $qr_service->generate_data_uri( $content );
			}
			wp_send_json_success( array( 'dataUri' => $data_uri ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => __( 'Failed to generate QR code.', 'nettertech-events' ) ) );
		}
	}

	/**
	 * Configure logo settings for the QR code service.
	 *
	 * Resolves the logo path based on the mode and validates custom logo attachments.
	 *
	 * @param QRCodeService $qr_service The QR code service instance.
	 * @param string        $logo_mode  Logo mode: 'none', 'default', 'site', or 'custom'.
	 * @param int           $logo_id    Attachment ID for custom logo (when mode is 'custom').
	 * @return bool True if logo should be embedded, false otherwise.
	 */
	private function configure_logo_for_service( QRCodeService $qr_service, string $logo_mode, int $logo_id ): bool {
		// Handle 'default' mode - use plugin settings.
		if ( 'default' === $logo_mode ) {
			$dto       = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
			$logo_mode = $dto->qr->qr_default_logo_mode;
			$logo_id   = $dto->qr->qr_default_logo_id;
		}

		// Set the logo mode on the service.
		$qr_service->set_logo_mode( $logo_mode );

		// Handle each mode.
		switch ( $logo_mode ) {
			case 'site':
				// Site logo is resolved dynamically by the service.
				return true;

			case 'custom':
				// Validate custom logo attachment.
				if ( $logo_id > 0 && wp_attachment_is_image( $logo_id ) ) {
					$logo_path = get_attached_file( $logo_id );
					if ( $logo_path && file_exists( $logo_path ) ) {
						$qr_service->set_logo_path( $logo_path );
						return true;
					}
				}
				// Invalid custom logo, fall back to no logo.
				$qr_service->set_logo_mode( 'none' );
				return false;

			case 'none':
			default:
				return false;
		}
	}

	/**
	 * Render the QR Generator page.
	 *
	 * @return void
	 */
	public function render(): void {
		// Enqueue media library for custom logo selection.
		wp_enqueue_media();

		// Enqueue QR Generator styles.
		wp_enqueue_style(
			'nettertech-events-qr-generator',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/css/admin/qr-generator.css',
			array(),
			NETTERTECH_EVENTS_VERSION
		);

		// Enqueue QR Generator scripts.
		wp_enqueue_script(
			'nettertech-events-qr-generator',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/qr-generator.js',
			array( 'wp-i18n' ),
			NETTERTECH_EVENTS_VERSION,
			true
		);

		// Get plugin default settings for display.
		$dto = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
		$qr  = $dto->qr;

		// Resolve default color from settings (accepts any valid hex).
		$default_color = strtolower( ltrim( $qr->qr_foreground_color, '#' ) );
		if ( ! preg_match( '/^[0-9a-f]{6}$/', $default_color ) ) {
			$default_color = '000000';
		}

		// Resolve background defaults from settings.
		$default_bg_color     = $qr->qr_background_color;
		$default_bg_opacity   = $qr->qr_bg_opacity;
		$default_scale        = $qr->qr_scale;
		$default_dot_style    = $qr->qr_dot_style;
		$default_finder_style = $qr->qr_finder_style;

		// Localize script data.
		wp_localize_script(
			'nettertech-events-qr-generator',
			'nettertechEventsQRGenerator',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'nettertech_events_qr_generator' ),
				'siteUrl'          => home_url( '/' ),
				'defaultColor'     => $default_color,
				'defaultBgColor'   => $default_bg_color,
				'defaultBgOpacity' => $default_bg_opacity,
				'defaultScale'     => $default_scale,
				'i18n'             => array(
					'selectLogo'     => __( 'Select Logo', 'nettertech-events' ),
					'useLogo'        => __( 'Use this logo', 'nettertech-events' ),
					'change'         => __( 'Change', 'nettertech-events' ),
					'select'         => __( 'Select', 'nettertech-events' ),
					'noContent'      => __( 'Please enter content to generate a QR code.', 'nettertech-events' ),
					'generating'     => __( 'Generating...', 'nettertech-events' ),
					'placeholder'    => __( 'QR code preview will appear here', 'nettertech-events' ),
					'generateFailed' => __( 'Failed to generate QR code. Please try again.', 'nettertech-events' ),
					'downloadFailed' => __( 'Download failed.', 'nettertech-events' ),
				),
			)
		);

		$default_logo_mode = $qr->qr_default_logo_mode;
		$default_logo_id   = $qr->qr_default_logo_id;

		// Resolve default logo description.
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
		?>
		<a class="nte-skip-link screen-reader-text" href="#nte-main-content">
			<?php esc_html_e( 'Skip to main content', 'nettertech-events' ); ?>
		</a>
		<div id="nte-main-content" class="wrap" tabindex="-1">
			<?php Branding::render_header(); ?>
			<h1><?php esc_html_e( 'QR Code Generator', 'nettertech-events' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Generate QR codes for any text or URL.', 'nettertech-events' ); ?></p>

			<div class="nte-qr-generator" id="nte-qr-generator">
				<div class="nte-qr-generator__form">
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="nte-qr-content"><?php esc_html_e( 'Content', 'nettertech-events' ); ?></label>
							</th>
							<td>
								<input type="text"
									id="nte-qr-content"
									class="large-text"
									placeholder="<?php esc_attr_e( 'Enter URL or text...', 'nettertech-events' ); ?>"
									autocomplete="off">
								<p class="description"><?php esc_html_e( 'Enter any URL or text to encode in the QR code.', 'nettertech-events' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Color', 'nettertech-events' ); ?></th>
							<td>
								<?php
								$presenter = new QRColorPalettePresenter( $default_color, self::COLOR_PALETTE );
								include dirname( __DIR__, 2 ) . '/templates/admin/qr-generator/color-palette.php';
								?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="nte-qr-bg-color"><?php esc_html_e( 'Background Color', 'nettertech-events' ); ?></label>
							</th>
							<td>
								<input type="color"
									id="nte-qr-bg-color"
									value="<?php echo esc_attr( $default_bg_color ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="nte-qr-bg-opacity"><?php esc_html_e( 'Background Opacity', 'nettertech-events' ); ?></label>
							</th>
							<td>
								<div style="display: flex; align-items: center; gap: 10px;">
									<input type="range"
										id="nte-qr-bg-opacity"
										min="0" max="100" step="1"
										value="<?php echo esc_attr( (string) $default_bg_opacity ); ?>"
										style="width: 200px;">
									<span id="nte-qr-bg-opacity-value" style="font-weight: 500; min-width: 40px;"><?php echo esc_html( $default_bg_opacity . '%' ); ?></span>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="nte-qr-scale"><?php esc_html_e( 'Scale', 'nettertech-events' ); ?></label>
							</th>
							<td>
								<select id="nte-qr-scale">
									<?php
									for ( $i = 3; $i <= 20; $i++ ) {
										printf(
											'<option value="%d"%s>%d px</option>',
											(int) $i,
											selected( $default_scale, $i, false ),
											(int) $i
										);
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Dot Shape', 'nettertech-events' ); ?></th>
							<td>
								<fieldset>
									<label style="margin-right: 16px;">
										<input type="radio" name="nte-qr-dot-style" value="rounded" <?php checked( $default_dot_style, 'rounded' ); ?>>
										<?php esc_html_e( 'Rounded', 'nettertech-events' ); ?>
									</label>
									<label>
										<input type="radio" name="nte-qr-dot-style" value="square" <?php checked( $default_dot_style, 'square' ); ?>>
										<?php esc_html_e( 'Square', 'nettertech-events' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Finder Corners', 'nettertech-events' ); ?></th>
							<td>
								<fieldset>
									<label style="margin-right: 16px;">
										<input type="radio" name="nte-qr-finder-style" value="square" <?php checked( $default_finder_style, 'square' ); ?>>
										<?php esc_html_e( 'Square', 'nettertech-events' ); ?>
									</label>
									<label>
										<input type="radio" name="nte-qr-finder-style" value="rounded" <?php checked( $default_finder_style, 'rounded' ); ?>>
										<?php esc_html_e( 'Rounded', 'nettertech-events' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Logo', 'nettertech-events' ); ?></th>
							<td>
								<div class="nte-qr-logo-options" role="radiogroup" aria-label="<?php esc_attr_e( 'QR Code Logo', 'nettertech-events' ); ?>">
									<!-- Plugin Default Option -->
									<label class="nte-qr-logo-option nte-qr-logo-option--selected" data-mode="default" tabindex="0" role="radio" aria-checked="true">
										<input type="radio" name="nte-qr-logo-mode" value="default" checked>
										<span class="nte-qr-logo-option__icon">
											<span class="dashicons dashicons-admin-settings"></span>
										</span>
										<span class="nte-qr-logo-option__label">
											<?php esc_html_e( 'Plugin Default', 'nettertech-events' ); ?>
											<span class="nte-qr-logo-option__desc"><?php echo esc_html( $default_description ); ?></span>
										</span>
									</label>

									<!-- No Logo Option -->
									<label class="nte-qr-logo-option" data-mode="none" tabindex="0" role="radio" aria-checked="false">
										<input type="radio" name="nte-qr-logo-mode" value="none">
										<span class="nte-qr-logo-option__icon">
											<span class="dashicons dashicons-no"></span>
										</span>
										<span class="nte-qr-logo-option__label">
											<?php esc_html_e( 'No Logo', 'nettertech-events' ); ?>
										</span>
									</label>

									<!-- Site Logo Option -->
									<label class="nte-qr-logo-option <?php echo empty( $site_logo_url ) ? 'nte-qr-logo-option--disabled' : ''; ?>" data-mode="site" tabindex="0" role="radio" aria-checked="false" <?php echo empty( $site_logo_url ) ? 'aria-disabled="true"' : ''; ?>>
										<input type="radio" name="nte-qr-logo-mode" value="site" <?php disabled( empty( $site_logo_url ) ); ?>>
										<span class="nte-qr-logo-option__icon nte-qr-logo-option__thumb">
											<?php if ( $site_logo_url ) : ?>
												<img src="<?php echo esc_url( $site_logo_url ); ?>" alt="<?php esc_attr_e( 'Site Logo', 'nettertech-events' ); ?>">
											<?php else : ?>
												<span class="dashicons dashicons-format-image"></span>
											<?php endif; ?>
										</span>
										<span class="nte-qr-logo-option__label">
											<?php esc_html_e( 'Site Logo', 'nettertech-events' ); ?>
											<?php if ( empty( $site_logo_url ) ) : ?>
												<span class="nte-qr-logo-option__desc"><?php esc_html_e( 'Not configured', 'nettertech-events' ); ?></span>
											<?php endif; ?>
										</span>
									</label>

									<!-- Custom Logo Option -->
									<label class="nte-qr-logo-option" data-mode="custom" tabindex="0" role="radio" aria-checked="false">
										<input type="radio" name="nte-qr-logo-mode" value="custom">
										<span class="nte-qr-logo-option__icon nte-qr-logo-option__thumb" id="nte-qr-custom-thumb">
											<span class="dashicons dashicons-plus-alt2"></span>
										</span>
										<span class="nte-qr-logo-option__label">
											<?php esc_html_e( 'Custom Logo', 'nettertech-events' ); ?>
											<span class="nte-qr-logo-option__actions">
												<button type="button" id="nte-qr-select-logo" class="button-link"><?php esc_html_e( 'Select', 'nettertech-events' ); ?></button>
											</span>
										</span>
									</label>
								</div>
								<input type="hidden" id="nte-qr-logo-id" value="0">
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="button"
							id="nte-qr-generate"
							class="button button-primary">
							<?php esc_html_e( 'Generate QR Code', 'nettertech-events' ); ?>
						</button>
					</p>
				</div>

				<div class="nte-qr-generator__preview">
					<div id="nte-qr-preview" class="nte-qr-preview">
						<p class="nte-qr-preview__placeholder">
							<?php esc_html_e( 'QR code preview will appear here', 'nettertech-events' ); ?>
						</p>
					</div>
					<p id="nte-qr-dimensions" class="description" style="text-align: center; margin-top: 8px;"></p>
					<p class="submit" id="nte-qr-download-wrap" style="display: none;">
						<button type="button"
							id="nte-qr-download"
							class="button button-secondary">
							<?php esc_html_e( 'Download QR Code', 'nettertech-events' ); ?>
						</button>
					</p>
				</div>
			</div>
		</div>

		<?php
	}
}
