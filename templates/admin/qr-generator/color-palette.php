<?php
/**
 * Admin template: QR Generator color palette section.
 *
 * Rendered from `QRGeneratorPage::render()` after building a
 * `QRColorPalettePresenter`. Template MUST NOT call repositories, $wpdb,
 * $_GET/$_POST, or business logic — those are in the presenter. Template
 * MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin
 *
 * @var \NetterTechEvents\Admin\QRGenerator\Presenters\QRColorPalettePresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<div class="nte-qr-color-palette" role="radiogroup"
	aria-label="<?php echo esc_attr( $presenter->radiogroup_aria_label() ); ?>">
	<label class="nte-qr-color-option">
		<input type="radio"
			name="nte-qr-color"
			value="default"
			checked>
		<span class="nte-qr-color-swatch nte-qr-color-swatch--default"
			title="<?php echo esc_attr( $presenter->default_label() ); ?>">
			<span class="dashicons dashicons-admin-settings"></span>
		</span>
		<span class="screen-reader-text"><?php echo esc_html( $presenter->default_label() ); ?></span>
	</label>
	<?php foreach ( $presenter->palette_rows() as $nettertech_events_row ) : ?>
		<label class="nte-qr-color-option">
			<input type="radio"
				name="nte-qr-color"
				value="<?php echo esc_attr( $nettertech_events_row['hex'] ); ?>"
				<?php checked( $nettertech_events_row['checked'] ); ?>>
			<span class="nte-qr-color-swatch"
				style="background-color: #<?php echo esc_attr( $nettertech_events_row['hex'] ); ?>;"
				title="<?php echo esc_attr( $nettertech_events_row['label'] ); ?>"></span>
			<span class="screen-reader-text"><?php echo esc_html( $nettertech_events_row['label'] ); ?></span>
		</label>
	<?php endforeach; ?>
	<label class="nte-qr-color-option">
		<input type="radio"
			name="nte-qr-color"
			value=""
			class="nte-qr-custom-color-radio">
		<span class="nte-qr-color-swatch nte-qr-color-swatch--custom"
			title="<?php echo esc_attr( $presenter->custom_label() ); ?>">
			<span class="dashicons dashicons-color-picker"></span>
		</span>
		<span class="screen-reader-text"><?php echo esc_html( $presenter->custom_label() ); ?></span>
		<input type="color" value="#000000" class="nte-qr-custom-color-picker"
			style="position: absolute; opacity: 0; pointer-events: none;"
			aria-hidden="true" tabindex="-1">
	</label>
</div>
