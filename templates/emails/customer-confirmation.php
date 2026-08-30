<?php
/**
 * Customer confirmation email template.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/emails/customer-confirmation.php
 *
 * @package NetterTechEvents\Templates\Emails
 * @version 0.8.0
 *
 * Available variables (read via $context):
 * @var \NetterTechEvents\TemplateLoader\EmailContext $context             Email template context.
 * @var WC_Order                                      $context->order               WooCommerce order object.
 * @var \NetterTechEvents\Models\Ticket[]             $context->tickets             Array of Ticket objects.
 * @var array                                         $context->grouped_tickets     Tickets grouped by occurrence.
 * @var string                                        $context->venue_logo          Logo URL.
 * @var string                                        $context->cancellation_policy Cancellation policy text.
 * @var string                                        $context->site_name           Site name.
 * @var string                                        $context->site_url            Site URL.
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

// Heading is built by EmailTemplateRenderer::get_customer_email_heading() (real
// _n() plural forms, event name, multi-event fallback); the string below only
// covers theme overrides that render this template without the renderer.
$nettertech_events_ticket_count = count( $context->get( 'tickets', array() ) );
$nettertech_events_heading      = (string) $context->get( 'heading', '' );
if ( '' === $nettertech_events_heading ) {
	$nettertech_events_heading = _n( 'Your ticket is confirmed', 'Your tickets are confirmed', $nettertech_events_ticket_count, 'nettertech-events' );
}
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( $nettertech_events_heading ); ?></title>
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
								<?php echo esc_html( $nettertech_events_heading ); ?>
							</h2>
						</td>
					</tr>

					<!-- Order Summary -->
					<tr>
						<td style="padding: 30px 40px;">
							<p style="margin: 0 0 20px; font-size: 16px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; line-height: 1.6;">
								<?php
								printf(
									/* translators: %s: recipient name */
									esc_html__( 'Hi %s,', 'nettertech-events' ),
									esc_html( $context->order->get_billing_first_name() )
								);
								?>
							</p>
							<p style="margin: 0 0 20px; font-size: 16px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; line-height: 1.6;">
								<?php
								echo esc_html(
									_n(
										'Thank you for your purchase! Your ticket is below and ready to use.',
										'Thank you for your purchase! Your tickets are below and ready to use.',
										$nettertech_events_ticket_count,
										'nettertech-events'
									)
								);
								?>
							</p>
							<p style="margin: 0 0 30px; font-size: 14px; color: #666666;">
								<?php
								printf(
									/* translators: %s: Order number */
									esc_html__( 'Order #%s', 'nettertech-events' ),
									esc_html( $context->order->get_order_number() )
								);
								?>
							</p>
						</td>
					</tr>

					<!-- Tickets -->
					<?php foreach ( $context->get( 'grouped_tickets', array() ) as $nettertech_events_occ_id => $nettertech_events_group ) : ?>
						<tr>
							<td style="padding: 0 40px 30px;">
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; border-radius: 8px; overflow: hidden;">
									<tr>
										<td style="padding: 20px;">
											<!-- Event Title -->
											<h3 style="margin: 0 0 15px; font-size: 20px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
												<?php echo $nettertech_events_group['event'] ? esc_html( $nettertech_events_group['event']->title ) : esc_html__( 'Event', 'nettertech-events' ); ?>
											</h3>

											<!-- Event Details -->
											<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
												<?php if ( $nettertech_events_group['occurrence'] ) : ?>
													<tr>
														<td style="padding: 5px 0; font-size: 14px; color: #666666; width: 100px;">
															<?php esc_html_e( 'Date:', 'nettertech-events' ); ?>
														</td>
														<td style="padding: 5px 0; font-size: 14px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
															<?php echo esc_html( $nettertech_events_group['occurrence']->get_formatted_date() ); ?>
														</td>
													</tr>
													<tr>
														<td style="padding: 5px 0; font-size: 14px; color: #666666;">
															<?php esc_html_e( 'Time:', 'nettertech-events' ); ?>
														</td>
														<td style="padding: 5px 0; font-size: 14px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
															<?php echo esc_html( $nettertech_events_group['occurrence']->get_formatted_time() ); ?>
														</td>
													</tr>
												<?php endif; ?>

												<?php if ( $nettertech_events_group['event'] && $nettertech_events_group['event']->venue_name ) : ?>
													<tr>
														<td style="padding: 5px 0; font-size: 14px; color: #666666;">
															<?php esc_html_e( 'Venue:', 'nettertech-events' ); ?>
														</td>
														<td style="padding: 5px 0; font-size: 14px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
															<?php echo esc_html( $nettertech_events_group['event']->venue_name ); ?>
															<?php if ( $nettertech_events_group['event']->venue_address ) : ?>
																<br><span style="color: #666666;"><?php echo esc_html( $nettertech_events_group['event']->venue_address ); ?></span>
																<br><a href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $nettertech_events_group['event']->venue_address ) ); ?>" style="color: <?php echo esc_attr( $context->accent_color() ); ?>; text-decoration: none; font-size: 13px;" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Directions', 'nettertech-events' ); ?> &rarr;</a>
															<?php endif; ?>
														</td>
													</tr>
												<?php endif; ?>

												<?php if ( $nettertech_events_group['ticket_type'] ) : ?>
													<tr>
														<td style="padding: 5px 0; font-size: 14px; color: #666666;">
															<?php esc_html_e( 'Ticket:', 'nettertech-events' ); ?>
														</td>
														<td style="padding: 5px 0; font-size: 14px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
															<?php echo esc_html( $nettertech_events_group['ticket_type']->name ); ?>
														</td>
													</tr>
												<?php endif; ?>

												<tr>
													<td style="padding: 5px 0; font-size: 14px; color: #666666;">
														<?php esc_html_e( 'Quantity:', 'nettertech-events' ); ?>
													</td>
													<td style="padding: 5px 0; font-size: 14px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; font-weight: 600;">
														<?php echo esc_html( count( $nettertech_events_group['tickets'] ) ); ?>
													</td>
												</tr>
											</table>

											<?php
											// QR code section provided by Pro via hook.
											do_action( 'nettertech_events_email_qr_codes', $nettertech_events_group['tickets'] );
											?>
										</td>
									</tr>
								</table>
							</td>
						</tr>
					<?php endforeach; ?>

					<!-- Cancellation Policy -->
					<?php if ( $context->get( 'cancellation_policy', '' ) ) : ?>
						<tr>
							<td style="padding: 0 40px 30px;">
								<div style="padding: 15px; background-color: #fff3cd; border-radius: 4px; border-left: 4px solid #ffc107;">
									<h4 style="margin: 0 0 10px; font-size: 14px; color: #856404;">
										<?php esc_html_e( 'Cancellation Policy', 'nettertech-events' ); ?>
									</h4>
									<p style="margin: 0; font-size: 13px; color: #856404; line-height: 1.5;">
										<?php echo esc_html( $context->get( 'cancellation_policy', '' ) ); ?>
									</p>
								</div>
							</td>
						</tr>
					<?php endif; ?>

					<!-- Footer -->
					<tr>
						<td style="padding: 30px 40px; background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; border-top: 1px solid #eeeeee; text-align: center;">
							<p style="margin: 0 0 10px; font-size: 14px; color: #666666;">
								<?php esc_html_e( 'Questions? Contact the event organizer.', 'nettertech-events' ); ?>
							</p>
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
