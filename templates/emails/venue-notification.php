<?php
/**
 * Venue notification email template.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/emails/venue-notification.php
 *
 * @package NetterTechEvents\Templates\Emails
 * @version 0.8.0
 *
 * Available variables (read via $context):
 * @var \NetterTechEvents\TemplateLoader\EmailContext $context Email template context.
 * @var WC_Order                                      $context->order           WooCommerce order object.
 * @var \NetterTechEvents\Models\Ticket[]             $context->tickets         Array of Ticket objects.
 * @var array                                         $context->grouped_tickets Tickets grouped by occurrence.
 * @var float                                         $context->total_revenue   Total revenue from tickets.
 * @var string                                        $context->buyer_name      Customer name.
 * @var string                                        $context->buyer_email     Customer email.
 * @var string                                        $context->site_name       Site name.
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
	<title><?php esc_html_e( 'New Ticket Purchase', 'nettertech-events' ); ?></title>
</head>
<body style="margin: 0; padding: 0; background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>;">
		<tr>
			<td align="center" style="padding: 40px 20px;">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
					<!-- Header -->
					<tr>
						<td style="padding: 30px 40px; background-color: <?php echo esc_attr( $context->accent_color() ); ?>; border-radius: 8px 8px 0 0;">
							<h1 style="margin: 0; font-size: 24px; color: #ffffff; font-weight: 600;">
								<?php esc_html_e( 'New Ticket Purchase', 'nettertech-events' ); ?>
							</h1>
							<p style="margin: 10px 0 0; font-size: 14px; color: rgba(255,255,255,0.9);">
								<?php echo esc_html( $context->get( 'site_name', '' ) ); ?>
							</p>
						</td>
					</tr>

					<!-- Summary Box -->
					<tr>
						<td style="padding: 30px 40px;">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; border-radius: 8px;">
								<tr>
									<td style="padding: 20px;">
										<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
											<tr>
												<td style="width: 50%; padding: 10px; text-align: center; border-right: 1px solid #dddddd;">
													<p style="margin: 0 0 5px; font-size: 12px; color: #666666; text-transform: uppercase; letter-spacing: 1px;">
														<?php esc_html_e( 'Total Tickets', 'nettertech-events' ); ?>
													</p>
													<p style="margin: 0; font-size: 32px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; font-weight: 700;">
														<?php echo esc_html( count( $context->get( 'tickets', array() ) ) ); ?>
													</p>
												</td>
												<td style="width: 50%; padding: 10px; text-align: center;">
													<p style="margin: 0 0 5px; font-size: 12px; color: #666666; text-transform: uppercase; letter-spacing: 1px;">
														<?php esc_html_e( 'Revenue', 'nettertech-events' ); ?>
													</p>
													<p style="margin: 0; font-size: 32px; color: <?php echo esc_attr( $context->accent_color() ); ?>; font-weight: 700;">
														<?php echo wp_kses_post( wc_price( $context->get( 'total_revenue', 0 ) ) ); ?>
													</p>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<!-- Customer Details -->
					<tr>
						<td style="padding: 0 40px 30px;">
							<h2 style="margin: 0 0 15px; font-size: 18px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; border-bottom: 2px solid <?php echo esc_attr( $context->accent_color() ); ?>; padding-bottom: 10px;">
								<?php esc_html_e( 'Customer Details', 'nettertech-events' ); ?>
							</h2>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
								<tr>
									<td style="padding: 8px 0; font-size: 14px; color: #666666; width: 120px;">
										<?php esc_html_e( 'Name:', 'nettertech-events' ); ?>
									</td>
									<td style="padding: 8px 0; font-size: 14px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; font-weight: 600;">
										<?php echo esc_html( $context->get( 'buyer_name', '' ) ); ?>
									</td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-size: 14px; color: #666666;">
										<?php esc_html_e( 'Email:', 'nettertech-events' ); ?>
									</td>
									<td style="padding: 8px 0; font-size: 14px;">
										<a href="mailto:<?php echo esc_attr( $context->get( 'buyer_email', '' ) ); ?>" style="color: <?php echo esc_attr( $context->accent_color() ); ?>; text-decoration: none;">
											<?php echo esc_html( $context->get( 'buyer_email', '' ) ); ?>
										</a>
									</td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-size: 14px; color: #666666;">
										<?php esc_html_e( 'Order:', 'nettertech-events' ); ?>
									</td>
									<td style="padding: 8px 0; font-size: 14px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
										#<?php echo esc_html( $context->order->get_order_number() ); ?>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<!-- Event Details -->
					<tr>
						<td style="padding: 0 40px 30px;">
							<h2 style="margin: 0 0 15px; font-size: 18px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; border-bottom: 2px solid <?php echo esc_attr( $context->accent_color() ); ?>; padding-bottom: 10px;">
								<?php esc_html_e( 'Event Details', 'nettertech-events' ); ?>
							</h2>

							<?php foreach ( $context->get( 'grouped_tickets', array() ) as $nettertech_events_occ_id => $nettertech_events_group ) : ?>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 15px; border: 1px solid #eeeeee; border-radius: 4px;">
									<tr>
										<td style="padding: 15px;">
											<h3 style="margin: 0 0 10px; font-size: 16px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
												<?php echo $nettertech_events_group['event'] ? esc_html( $nettertech_events_group['event']->title ) : esc_html__( 'Event', 'nettertech-events' ); ?>
											</h3>
											<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
												<?php if ( $nettertech_events_group['occurrence'] ) : ?>
													<tr>
														<td style="padding: 3px 0; font-size: 13px; color: #666666; width: 80px;">
															<?php esc_html_e( 'Date:', 'nettertech-events' ); ?>
														</td>
														<td style="padding: 3px 0; font-size: 13px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
															<?php echo esc_html( $nettertech_events_group['occurrence']->get_formatted_date() ); ?>
														</td>
													</tr>
													<tr>
														<td style="padding: 3px 0; font-size: 13px; color: #666666;">
															<?php esc_html_e( 'Time:', 'nettertech-events' ); ?>
														</td>
														<td style="padding: 3px 0; font-size: 13px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
															<?php echo esc_html( $nettertech_events_group['occurrence']->get_formatted_time() ); ?>
														</td>
													</tr>
												<?php endif; ?>
												<?php if ( $nettertech_events_group['ticket_type'] ) : ?>
													<tr>
														<td style="padding: 3px 0; font-size: 13px; color: #666666;">
															<?php esc_html_e( 'Type:', 'nettertech-events' ); ?>
														</td>
														<td style="padding: 3px 0; font-size: 13px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>;">
															<?php echo esc_html( $nettertech_events_group['ticket_type']->name ); ?>
														</td>
													</tr>
												<?php endif; ?>
												<tr>
													<td style="padding: 3px 0; font-size: 13px; color: #666666;">
														<?php esc_html_e( 'Qty:', 'nettertech-events' ); ?>
													</td>
													<td style="padding: 3px 0; font-size: 13px; color: <?php echo esc_attr( $nettertech_events_text_color ); ?>; font-weight: 600;">
														<?php echo esc_html( count( $nettertech_events_group['tickets'] ) ); ?>
													</td>
												</tr>
											</table>
										</td>
									</tr>
								</table>
							<?php endforeach; ?>
						</td>
					</tr>

					<!-- View Order Button -->
					<tr>
						<td style="padding: 0 40px 30px; text-align: center;">
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $context->order->get_id() . '&action=edit' ) ); ?>" style="display: inline-block; padding: 12px 30px; background-color: <?php echo esc_attr( $context->accent_color() ); ?>; color: #ffffff; text-decoration: none; border-radius: 4px; font-size: 14px; font-weight: 600;">
								<?php esc_html_e( 'View Order in Admin', 'nettertech-events' ); ?>
							</a>
						</td>
					</tr>

					<!-- Footer -->
					<tr>
						<td style="padding: 20px 40px; background-color: <?php echo esc_attr( $nettertech_events_bg_color ); ?>; border-top: 1px solid #eeeeee; text-align: center; border-radius: 0 0 8px 8px;">
							<p style="margin: 0; font-size: 12px; color: #999999;">
								<?php
								printf(
									/* translators: %s: Current time */
									esc_html__( 'This notification was sent at %s', 'nettertech-events' ),
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
