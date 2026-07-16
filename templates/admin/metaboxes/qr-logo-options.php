<?php
/**
 * Admin template: QR-code logo-options radiogroup (T4.2.4 Metaboxes cluster).
 *
 * Rendered by `QRCodeMetaboxHandler::render_logo_options()` after building a
 * `QRLogoOptionsPresenter` and including this file. Template MUST NOT call
 * repositories, $wpdb, $_GET/$_POST, or business logic — those are in the
 * presenter. Template MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 *
 * @var \NetterTechEvents\Admin\Metaboxes\Presenters\QRLogoOptionsPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<div class="nte-event-qr-logo-section" style="margin: 12px 0; padding-top: 10px; border-top: 1px solid #ddd;">
	<p style="margin: 0 0 8px 0; text-align: center; font-size: 12px; color: #646970;">
		<strong><?php echo esc_html( $presenter->logo_heading() ); ?></strong>
	</p>
	<div class="nte-event-qr-logo-options" role="radiogroup" aria-label="<?php echo esc_attr( $presenter->radiogroup_aria_label() ); ?>" style="display: flex; flex-wrap: wrap; gap: 6px; justify-content: center;">
		<!-- Use Default -->
		<label class="nte-event-qr-logo-opt<?php echo esc_attr( $presenter->selected_class( 'default' ) ); ?>" data-mode="default" tabindex="0" role="radio" aria-checked="<?php echo esc_attr( $presenter->aria_checked( 'default' ) ); ?>">
			<input type="hidden" name="_nettertech_events_qr_logo_mode" value="<?php echo esc_attr( $presenter->event_logo_mode() ); ?>" id="nte-event-qr-logo-mode">
			<input type="radio" name="nte-event-qr-logo-select" value="default" <?php checked( 'default', $presenter->event_logo_mode() ); ?>>
			<span class="dashicons dashicons-admin-settings"></span>
			<span class="nte-event-qr-logo-opt__text"><?php echo esc_html( $presenter->default_label() ); ?></span>
		</label>
		<!-- No Logo -->
		<label class="nte-event-qr-logo-opt<?php echo esc_attr( $presenter->selected_class( 'none' ) ); ?>" data-mode="none" tabindex="0" role="radio" aria-checked="<?php echo esc_attr( $presenter->aria_checked( 'none' ) ); ?>">
			<input type="radio" name="nte-event-qr-logo-select" value="none" <?php checked( 'none', $presenter->event_logo_mode() ); ?>>
			<span class="dashicons dashicons-no"></span>
			<span class="nte-event-qr-logo-opt__text"><?php echo esc_html( $presenter->none_label() ); ?></span>
		</label>
		<!-- Site Logo -->
		<label class="nte-event-qr-logo-opt<?php echo esc_attr( $presenter->selected_class( 'site' ) . $presenter->site_disabled_class() ); ?>" data-mode="site" tabindex="0" role="radio" aria-checked="<?php echo esc_attr( $presenter->aria_checked( 'site' ) ); ?>" aria-disabled="<?php echo esc_attr( $presenter->site_disabled() ? 'true' : 'false' ); ?>">
			<input type="radio" name="nte-event-qr-logo-select" value="site" <?php checked( 'site', $presenter->event_logo_mode() ); ?> <?php disabled( $presenter->site_disabled() ); ?>>
			<?php if ( $presenter->has_site_logo() ) : ?>
				<img src="<?php echo esc_url( $presenter->site_logo_url() ); ?>" alt="" style="width: 20px; height: 20px; object-fit: contain;">
			<?php else : ?>
				<span class="dashicons dashicons-format-image"></span>
			<?php endif; ?>
			<span class="nte-event-qr-logo-opt__text"><?php echo esc_html( $presenter->site_label() ); ?></span>
		</label>
		<!-- Custom Logo -->
		<label class="nte-event-qr-logo-opt<?php echo esc_attr( $presenter->selected_class( 'custom' ) ); ?>" data-mode="custom" tabindex="0" role="radio" aria-checked="<?php echo esc_attr( $presenter->aria_checked( 'custom' ) ); ?>">
			<input type="radio" name="nte-event-qr-logo-select" value="custom" <?php checked( 'custom', $presenter->event_logo_mode() ); ?>>
			<span id="nte-event-qr-custom-thumb" style="display: inline-flex; width: 20px; height: 20px; align-items: center; justify-content: center;">
				<?php if ( $presenter->has_custom_logo() ) : ?>
					<img src="<?php echo esc_url( $presenter->event_logo_url() ); ?>" alt="" style="width: 20px; height: 20px; object-fit: contain;">
				<?php else : ?>
					<span class="dashicons dashicons-plus-alt2" style="font-size: 16px; width: 16px; height: 16px;"></span>
				<?php endif; ?>
			</span>
			<span class="nte-event-qr-logo-opt__text"><?php echo esc_html( $presenter->custom_label() ); ?></span>
		</label>
	</div>
	<input type="hidden" name="_nettertech_events_qr_logo_id" value="<?php echo esc_attr( $presenter->event_logo_id() ); ?>" id="nte-event-qr-logo-id">
	<p style="margin: 6px 0 0 0; text-align: center; font-size: 11px; color: #888;">
		<?php
		printf(
			/* translators: %s: current default logo setting */
			esc_html__( 'Default: %s', 'nettertech-events' ),
			'<span id="nte-event-qr-default-desc">' . esc_html( $presenter->default_description() ) . '</span>'
		);
		?>
	</p>
</div>
