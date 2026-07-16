<?php
/**
 * Admin template: Date & Time metabox (T4.2.4 Metaboxes cluster).
 *
 * Rendered by `DateTimeMetaboxHandler::render()` after building a
 * `DateTimeBoxPresenter` and including this file. The field markup itself
 * lives in `datetime-fields.php` so the consolidated Schedule box (NTE-159)
 * can render the same fields without the postbox wrapper.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 *
 * @var \NetterTechEvents\Admin\Metaboxes\Presenters\DateTimeBoxPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<div class="postbox">
	<div class="postbox-header">
		<h2><?php echo esc_html( $presenter->box_title() ); ?></h2>
	</div>
	<div class="inside">
		<?php require __DIR__ . '/datetime-fields.php'; ?>
	</div>
</div>
