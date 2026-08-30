<?php
/**
 * Waitlist promotion notification email template.
 *
 * Sent when a customer is promoted from the waitlist (a spot opens up).
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/emails/waitlist-promotion.php
 *
 * @package NetterTechEvents\Templates\Emails
 * @version 1.7.0
 *
 * Available variables (read via $context):
 * @var \NetterTechEvents\TemplateLoader\EmailContext $context Email template context.
 * @var \NetterTechEvents\Models\WaitlistEntry $context->entry          Waitlist entry.
 * @var \NetterTechEvents\Models\Occurrence    $context->occurrence     Occurrence object.
 * @var string                                 $context->event_name     Event title.
 * @var string                                 $context->booking_url    URL to book the spot.
 * @var string                                 $context->venue_logo     Logo URL.
 * @var string                                 $context->site_name      Site name.
 * @var string                                 $context->site_url       Site URL.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * PHPStan reads only standalone assertions, not the header var-list
 * (audit GAP-029 rollout).
 *
 * @var \NetterTechEvents\TemplateLoader\EmailContext $context
 */

$nettertech_events_text_color = '#333333';
$nettertech_events_bg_color   = '#f7f7f7';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>
		<?php
		if ( $context->get( 'event_name', '' ) ) {
			printf(
				/* translators: %s: event title */
				esc_html__( 'A Spot is Available - %s', 'nettertech-events' ),
				esc_html( $context->get( 'event_name', '' ) )
			);
		} else {
			esc_html_e( 'A Spot is Available', 'nettertech-events' );
		}
		?>
	</title>
</head>
<body style="margin: 0; padding: 0; background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>;">
		<tr>
			<td align="center" style="padding: 40px 20px;">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
					<!-- Header -->
					<tr>
						<td style="padding: 40px 40px 20px; text-align: center; border-bottom: 3px solid <?php echo esc_attr( $context->accent_color() ); ?>;">
							<?php if ( $context->get( 'venue_logo', '' ) ) : ?>
								<img src="<?php echo esc_url( $context->get( 'venue_logo', '' ) ); ?>" alt="<?php echo esc_attr( $context->get( 'site_name', '' ) ); ?>" style="max-width: 200px; max-height: 80px; margin-bottom: 20px;">
							<?php else : ?>
								<h1 style="margin: 0 0 10px; font-size: 28px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;"><?php echo esc_html( $context->get( 'site_name', '' ) ); ?></h1>
							<?php endif; ?>
							<h2 style="margin: 0; font-size: 24px; color: <?php echo esc_attr( $context->accent_color() ); ?>; font-weight: 600;">
								<?php esc_html_e( 'A Spot is Available!', 'nettertech-events' ); ?>
							</h2>
						</td>
					</tr>

					<!-- Greeting -->
					<tr>
						<td style="padding: 30px 40px 20px;">
							<p style="margin: 0 0 20px; font-size: 16px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; line-height: 1.6;">
								<?php
								printf(
									/* translators: %s: recipient name */
									esc_html__( 'Hi %s,', 'nettertech-events' ),
									esc_html( $context->entry->name )
								);
								?>
							</p>
							<p style="margin: 0; font-size: 16px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; line-height: 1.6;">
								<?php
								printf(
									/* translators: %s: event name */
									esc_html__( 'A spot has opened up for %s.', 'nettertech-events' ),
									'<strong>' . esc_html( $context->get( 'event_name', '' ) ) . '</strong>'
								);
								?>
							</p>
						</td>
					</tr>

					<!-- Event Details -->
					<tr>
						<td style="padding: 0 40px 30px;">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; border-radius: 8px; overflow: hidden;">
								<tr>
									<td style="padding: 25px;">
										<h3 style="margin: 0 0 20px; font-size: 22px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
											<?php echo esc_html( $context->get( 'event_name', '' ) ); ?>
										</h3>

										<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
											<tr>
												<td style="padding: 8px 0; font-size: 15px; color: #666666; width: 100px; vertical-align: top;">
													<?php esc_html_e( 'Date:', 'nettertech-events' ); ?>
												</td>
												<td style="padding: 8px 0; font-size: 15px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; font-weight: 600;">
													<?php echo esc_html( $context->occurrence->get_formatted_date() ); ?>
												</td>
											</tr>
											<tr>
												<td style="padding: 8px 0; font-size: 15px; color: #666666; vertical-align: top;">
													<?php esc_html_e( 'Time:', 'nettertech-events' ); ?>
												</td>
												<td style="padding: 8px 0; font-size: 15px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; font-weight: 600;">
													<?php echo esc_html( $context->occurrence->get_formatted_time() ); ?>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<!-- Book Now Button -->
					<tr>
						<td style="padding: 0 40px 30px; text-align: center;">
							<a href="<?php echo esc_url( $context->get( 'booking_url', '' ) ); ?>" style="display: inline-block; padding: 14px 30px; background-color: <?php echo esc_attr( $context->accent_color() ); ?>; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: 600;">
								<?php esc_html_e( 'Book Your Spot Now', 'nettertech-events' ); ?>
							</a>
						</td>
					</tr>

					<!-- Urgency Note -->
					<tr>
						<td style="padding: 0 40px 30px;">
							<p style="margin: 0; font-size: 14px; color: #666666; line-height: 1.6; text-align: center;">
								<?php esc_html_e( 'This spot is held for a limited time. Book soon to secure your place.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>

					<!-- Footer -->
					<tr>
						<td style="padding: 30px 40px; background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; border-top: 1px solid #eeeeee; text-align: center;">
							<p style="margin: 0; font-size: 12px; color: #999999;">
								<?php
								printf(
									/* translators: %s: Site name with link */
									esc_html__( 'Sent by %s', 'nettertech-events' ),
									'<a href="' . esc_url( $context->get( 'site_url', '' ) ) . '" style="color: ' . esc_attr( $context->accent_color() ) . '; text-decoration: none;">' . esc_html( $context->get( 'site_name', '' ) ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
