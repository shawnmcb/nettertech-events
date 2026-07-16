<?php
/**
 * Admin template: Tickets metabox "save event first" notice (T4.2.4 Metaboxes cluster).
 *
 * Rendered by `TicketsMetabox::render_save_first_notice()` after building a
 * `SaveFirstNoticePresenter` and including this file. Template MUST NOT call
 * repositories, $wpdb, $_GET/$_POST, or business logic — those are in the
 * presenter. Template MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 *
 * @var \NetterTechEvents\Admin\Metaboxes\Presenters\SaveFirstNoticePresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<div class="nte-tickets-metabox">
	<p class="description">
		<span class="dashicons dashicons-info"></span>
		<?php echo esc_html( $presenter->notice_text() ); ?>
	</p>
</div>
