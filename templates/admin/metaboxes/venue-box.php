<?php
/**
 * Admin template: Venue metabox (T4.2.4 Metaboxes cluster).
 *
 * Rendered by `EventMetaboxHandler::render_venue_box()` after building a
 * `VenueBoxPresenter` and including this file. Template MUST NOT call
 * repositories, $wpdb, $_GET/$_POST, or business logic — those are in the
 * presenter. Template MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin
 *
 * @var \NetterTechEvents\Admin\Metaboxes\Presenters\VenueBoxPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<div class="postbox">
	<div class="postbox-header">
		<h2><?php echo esc_html( $presenter->box_title() ); ?></h2>
	</div>
	<div class="inside">
		<p>
			<label for="venue_name"><strong><?php echo esc_html( $presenter->venue_name_label() ); ?></strong></label>
			<input type="text" name="venue_name" id="venue_name"
					value="<?php echo esc_attr( $presenter->venue_name() ); ?>"
					class="widefat">
		</p>

		<p>
			<label for="venue_address"><strong><?php echo esc_html( $presenter->venue_address_label() ); ?></strong></label>
			<textarea name="venue_address" id="venue_address" rows="3" class="widefat"><?php echo esc_textarea( $presenter->venue_address() ); ?></textarea>
		</p>
	</div>
</div>
