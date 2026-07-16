<?php
/**
 * Template: Waitlist signup panel for sold-out ticket types.
 *
 * Renders the toggle button, signup form (name/email/phone), and success state.
 * Theme-overridable: copy to yourtheme/nettertech-events/parts/waitlist-panel.php.
 *
 * @package NetterTechEvents
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var int    $context->occurrence_id Occurrence ID.
 * @var string $context->prefill_name  Pre-filled name from logged-in user.
 * @var string $context->prefill_email Pre-filled email from logged-in user.
 */

declare(strict_types=1);

// Guard against direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nettertech_events_panel_id = 'nte-waitlist-panel-' . $context->get( 'occurrence_id', 0 );
$nettertech_events_name_id  = 'nte-waitlist-name-' . $context->get( 'occurrence_id', 0 );
$nettertech_events_email_id = 'nte-waitlist-email-' . $context->get( 'occurrence_id', 0 );
$nettertech_events_phone_id = 'nte-waitlist-phone-' . $context->get( 'occurrence_id', 0 );
?>
<div class="nte-waitlist" data-occurrence-id="<?php echo esc_attr( (string) $context->get( 'occurrence_id', 0 ) ); ?>">
	<button type="button"
			class="nte-waitlist__toggle"
			aria-expanded="false"
			aria-controls="<?php echo esc_attr( $nettertech_events_panel_id ); ?>">
		<?php esc_html_e( 'Join Waitlist', 'nettertech-events' ); ?>
	</button>

	<section id="<?php echo esc_attr( $nettertech_events_panel_id ); ?>"
			class="nte-waitlist__panel"
			aria-label="<?php esc_attr_e( 'Waitlist signup form', 'nettertech-events' ); ?>"
			hidden>
		<div class="nte-waitlist__form">
			<div class="nte-waitlist__field">
				<label for="<?php echo esc_attr( $nettertech_events_name_id ); ?>">
					<?php esc_html_e( 'Name', 'nettertech-events' ); ?>
				</label>
				<input type="text"
						id="<?php echo esc_attr( $nettertech_events_name_id ); ?>"
						class="nte-waitlist__input nte-waitlist__input--name"
						value="<?php echo esc_attr( $context->get( 'prefill_name', '' ) ); ?>"
						required>
			</div>

			<div class="nte-waitlist__field">
				<label for="<?php echo esc_attr( $nettertech_events_email_id ); ?>">
					<?php esc_html_e( 'Email', 'nettertech-events' ); ?>
				</label>
				<input type="email"
						id="<?php echo esc_attr( $nettertech_events_email_id ); ?>"
						class="nte-waitlist__input nte-waitlist__input--email"
						value="<?php echo esc_attr( $context->get( 'prefill_email', '' ) ); ?>"
						required>
			</div>

			<div class="nte-waitlist__field">
				<label for="<?php echo esc_attr( $nettertech_events_phone_id ); ?>">
					<?php esc_html_e( 'Phone (optional)', 'nettertech-events' ); ?>
				</label>
				<input type="tel"
						id="<?php echo esc_attr( $nettertech_events_phone_id ); ?>"
						class="nte-waitlist__input nte-waitlist__input--phone">
			</div>

			<button type="button" class="nte-waitlist__submit nte-btn">
				<?php esc_html_e( 'Join Waitlist', 'nettertech-events' ); ?>
			</button>
		</div>

		<div class="nte-waitlist__error" aria-live="polite" hidden></div>
	</section>

	<div class="nte-waitlist__success" aria-live="polite" hidden>
		<span class="nte-waitlist__check" aria-hidden="true"></span>
		<span class="nte-waitlist__success-text"></span>
		<button type="button" class="nte-waitlist__leave">
			<?php esc_html_e( 'Leave Waitlist', 'nettertech-events' ); ?>
		</button>
	</div>
</div>
