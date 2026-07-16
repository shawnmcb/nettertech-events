<?php
/**
 * Admin template: Spaces page notices.
 *
 * Rendered by `SpacesPage::render_notices()` after building a
 * `SpacesNoticesPresenter` and including this file. Template MUST NOT call
 * repositories, $wpdb, $_GET/$_POST, or business logic — those are in the
 * presenter. Template MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin
 *
 * @var \NetterTechEvents\Admin\Spaces\Presenters\SpacesNoticesPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! $presenter->should_render() ) {
	return;
}
?>
<div class="notice notice-<?php echo esc_attr( $presenter->type() ); ?> is-dismissible">
	<p><?php echo esc_html( $presenter->message() ); ?></p>
</div>
