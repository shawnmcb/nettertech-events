<?php
/**
 * RSVP venue notification email template.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/emails/rsvp-venue-notification.php
 *
 * @package NetterTechEvents\Templates\Emails
 * @version 0.8.0
 *
 * Available variables (read via $context):
 * @var \NetterTechEvents\TemplateLoader\EmailContext $context Email template context.
 * @var \NetterTechEvents\Models\Ticket     $context->ticket         Ticket object.
 * @var \NetterTechEvents\Models\Occurrence $context->occurrence     Occurrence object.
 * @var \NetterTechEvents\Models\Event      $context->event          Event object.
 * @var string                              $context->attendee_name  Attendee name.
 * @var string                              $context->attendee_email Attendee email.
 * @var string                              $context->site_name      Site name.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$nettertech_events_text_color = '#333333';
$nettertech_events_bg_color   = '#f5f5f5';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php esc_html_e( 'New RSVP Received', 'nettertech-events' ); ?></title>
</head>
<body style="margin: 0; padding: 0; background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>;">
		<tr>
			<td align="center" style="padding: 40px 20px;">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
					<!-- Header -->
					<tr>
						<td style="padding: 25px 40px; background-color: <?php echo esc_attr( $context->accent_color() ); ?>; border-radius: 8px 8px 0 0;">
							<h1 style="margin: 0; font-size: 22px; color: #ffffff; font-weight: 600;">
								<?php esc_html_e( 'New RSVP Received', 'nettertech-events' ); ?>
							</h1>
							<p style="margin: 8px 0 0; font-size: 14px; color: rgba(255,255,255,0.9);">
								<?php echo esc_html( $context->get( 'site_name', '' ) ); ?>
							</p>
						</td>
					</tr>

					<!-- RSVP Summary -->
					<tr>
						<td style="padding: 30px 40px;">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; border-radius: 8px;">
								<tr>
									<td style="padding: 20px; text-align: center;">
										<p style="margin: 0 0 5px; font-size: 12px; color: #666666; text-transform: uppercase; letter-spacing: 1px;">
											<?php esc_html_e( 'New Guest', 'nettertech-events' ); ?>
										</p>
										<p style="margin: 0; font-size: 24px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; font-weight: 700;">
											<?php echo esc_html( $context->get( 'attendee_name', '' ) ? $context->get( 'attendee_name', '' ) : __( 'Guest', 'nettertech-events' ) ); ?>
										</p>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<!-- Guest Details -->
					<tr>
						<td style="padding: 0 40px 25px;">
							<h2 style="margin: 0 0 15px; font-size: 16px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; border-bottom: 2px solid <?php echo esc_attr( $context->accent_color() ); ?>; padding-bottom: 8px;">
								<?php esc_html_e( 'Guest Details', 'nettertech-events' ); ?>
							</h2>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
								<tr>
									<td style="padding: 6px 0; font-size: 14px; color: #666666; width: 100px;">
										<?php esc_html_e( 'Name:', 'nettertech-events' ); ?>
									</td>
									<td style="padding: 6px 0; font-size: 14px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; font-weight: 600;">
										<?php echo esc_html( $context->get( 'attendee_name', '' ) ? $context->get( 'attendee_name', '' ) : __( 'Not provided', 'nettertech-events' ) ); ?>
									</td>
								</tr>
								<tr>
									<td style="padding: 6px 0; font-size: 14px; color: #666666;">
										<?php esc_html_e( 'Email:', 'nettertech-events' ); ?>
									</td>
									<td style="padding: 6px 0; font-size: 14px;">
										<?php if ( $context->get( 'attendee_email', '' ) ) : ?>
											<a href="mailto:<?php echo esc_attr( $context->get( 'attendee_email', '' ) ); ?>" style="color: <?php echo esc_attr( $context->accent_color() ); ?>; text-decoration: none;">
												<?php echo esc_html( $context->get( 'attendee_email', '' ) ); ?>
											</a>
										<?php else : ?>
											<span style="color: #999999;"><?php esc_html_e( 'Not provided', 'nettertech-events' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<!-- Event Details -->
					<tr>
						<td style="padding: 0 40px 25px;">
							<h2 style="margin: 0 0 15px; font-size: 16px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; border-bottom: 2px solid <?php echo esc_attr( $context->accent_color() ); ?>; padding-bottom: 8px;">
								<?php esc_html_e( 'Event Details', 'nettertech-events' ); ?>
							</h2>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #eeeeee; border-radius: 4px;">
								<tr>
									<td style="padding: 15px;">
										<h3 style="margin: 0 0 10px; font-size: 16px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
											<?php echo $context->event ? esc_html( $context->event->title ) : esc_html__( 'Event', 'nettertech-events' ); ?>
										</h3>
										<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
											<?php if ( $context->occurrence ) : ?>
												<tr>
													<td style="padding: 4px 0; font-size: 13px; color: #666666; width: 70px;">
														<?php esc_html_e( 'Date:', 'nettertech-events' ); ?>
													</td>
													<td style="padding: 4px 0; font-size: 13px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
														<?php echo esc_html( $context->occurrence->get_formatted_date() ); ?>
													</td>
												</tr>
												<tr>
													<td style="padding: 4px 0; font-size: 13px; color: #666666;">
														<?php esc_html_e( 'Time:', 'nettertech-events' ); ?>
													</td>
													<td style="padding: 4px 0; font-size: 13px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
														<?php echo esc_html( $context->occurrence->get_formatted_time() ); ?>
													</td>
												</tr>
											<?php endif; ?>
											<?php if ( $context->event && $context->event->venue_name ) : ?>
												<tr>
													<td style="padding: 4px 0; font-size: 13px; color: #666666;">
														<?php esc_html_e( 'Venue:', 'nettertech-events' ); ?>
													</td>
													<td style="padding: 4px 0; font-size: 13px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
														<?php echo esc_html( $context->event->venue_name ); ?>
													</td>
												</tr>
											<?php endif; ?>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<!-- Confirmation Code -->
					<?php if ( $context->ticket && $context->ticket->ticket_code ) : ?>
						<tr>
							<td style="padding: 0 40px 25px;">
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #e8f5e9; border-radius: 4px;">
									<tr>
										<td style="padding: 15px; text-align: center;">
											<p style="margin: 0 0 5px; font-size: 12px; color: #2e7d32; text-transform: uppercase; letter-spacing: 1px;">
												<?php esc_html_e( 'Confirmation Code', 'nettertech-events' ); ?>
											</p>
											<p style="margin: 0; font-size: 18px; color: #1b5e20; font-weight: 700; font-family: 'Courier New', monospace;">
												<?php echo esc_html( $context->ticket->ticket_code ); ?>
											</p>
										</td>
									</tr>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<!-- Footer -->
					<tr>
						<td style="padding: 20px 40px; background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; border-top: 1px solid #eeeeee; text-align: center; border-radius: 0 0 8px 8px;">
							<p style="margin: 0; font-size: 12px; color: #999999;">
								<?php
								printf(
									/* translators: %s: Current time */
									esc_html__( 'RSVP received at %s', 'nettertech-events' ),
									esc_html( current_time( 'F j, Y g:i a' ) )
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
