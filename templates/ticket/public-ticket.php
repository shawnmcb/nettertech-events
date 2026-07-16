<?php
/**
 * Public ticket template (attendee view).
 *
 * Displayed when an attendee scans their own ticket QR code
 * (no volunteer cookie present).
 *
 * Available via:
 * - Router::get_current_ticket_data() - Ticket and attendee data
 * - Router::is_volunteer_scan() - Always false on this template
 *
 * @package NetterTechEvents\Templates
 */

declare(strict_types=1);

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Frontend\Router;

// Get ticket data from Router.
$nettertech_events_ticket_data = Router::get_current_ticket_data();

if ( ! $nettertech_events_ticket_data ) {
	wp_die( esc_html__( 'Ticket not found.', 'nettertech-events' ) );
}

// Extract data for easier access.
$nettertech_events_attendee_name    = $nettertech_events_ticket_data->attendee_name ?? __( 'Guest', 'nettertech-events' );
$nettertech_events_quantity         = (int) ( $nettertech_events_ticket_data->quantity ?? 1 );
$nettertech_events_checked_in_count = (int) ( $nettertech_events_ticket_data->checked_in_count ?? 0 );
$nettertech_events_ticket_status    = $nettertech_events_ticket_data->ticket_status ?? 'confirmed';
$nettertech_events_ticket_code      = $nettertech_events_ticket_data->ticket_code ?? '';
$nettertech_events_event_title      = $nettertech_events_ticket_data->occurrence_title ? $nettertech_events_ticket_data->occurrence_title : $nettertech_events_ticket_data->event_title;
$nettertech_events_venue_name       = $nettertech_events_ticket_data->venue_name ?? '';

// Format event datetime.
$nettertech_events_start_datetime = $nettertech_events_ticket_data->start_datetime ?? '';
$nettertech_events_end_datetime   = $nettertech_events_ticket_data->end_datetime ?? '';
$nettertech_events_event_date     = '';
$nettertech_events_event_time     = '';

if ( $nettertech_events_start_datetime ) {
	$nettertech_events_date_format = get_option( 'date_format' );
	$nettertech_events_time_format = get_option( 'time_format' );
	$nettertech_events_event_date  = wp_date( $nettertech_events_date_format, strtotime( $nettertech_events_start_datetime ) );
	$nettertech_events_event_time  = wp_date( $nettertech_events_time_format, strtotime( $nettertech_events_start_datetime ) );

	if ( $nettertech_events_end_datetime ) {
		$nettertech_events_event_time .= ' - ' . wp_date( $nettertech_events_time_format, strtotime( $nettertech_events_end_datetime ) );
	}
}

// Determine ticket state.
$nettertech_events_is_fully_checked_in = $nettertech_events_checked_in_count >= $nettertech_events_quantity;
$nettertech_events_is_ticket_cancelled = in_array( $nettertech_events_ticket_status, array( 'cancelled', 'refunded' ), true );

// Enqueue ticket page styles via wp_add_inline_style before wp_head().
wp_enqueue_style( 'nettertech-events-public-ticket' );
wp_add_inline_style(
	'nettertech-events-public-ticket',
	'* { box-sizing: border-box; }
	body.nte-public-ticket-page { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
	.nte-public-ticket { width: 100%; max-width: 380px; margin: 20px; background: #fff; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden; }
	.nte-public-ticket__header { padding: 16px 20px; text-align: center; }
	.nte-public-ticket__status { display: inline-block; padding: 8px 20px; border-radius: 20px; font-size: 0.85em; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
	.nte-public-ticket__status--valid { background: #e8f5e9; color: #2e7d32; }
	.nte-public-ticket__status--checked-in { background: #fff3e0; color: #bf360c; }
	.nte-public-ticket__status--cancelled { background: #ffebee; color: #c62828; }
	.nte-public-ticket__event { padding: 20px; text-align: center; border-bottom: 1px dashed #e0e0e0; }
	.nte-public-ticket__title { margin: 0 0 16px; font-size: 1.5em; font-weight: 700; color: #1e1e1e; line-height: 1.3; }
	.nte-public-ticket__detail { display: flex; align-items: center; justify-content: center; gap: 8px; margin: 8px 0; color: #555; }
	.nte-public-ticket__detail-icon { font-size: 1.2em; }
	.nte-public-ticket__detail-text { font-size: 1em; }
	.nte-public-ticket__info { display: flex; padding: 20px; border-bottom: 1px dashed #e0e0e0; }
	.nte-public-ticket__attendee { flex: 1; text-align: center; }
	.nte-public-ticket__quantity { flex: 0 0 80px; text-align: center; border-left: 1px solid #e0e0e0; }
	.nte-public-ticket__label { margin: 0; font-size: 0.75em; color: #595959; text-transform: uppercase; letter-spacing: 0.5px; }
	.nte-public-ticket__name { margin: 8px 0 0; font-size: 1.1em; font-weight: 600; color: #1e1e1e; }
	.nte-public-ticket__value { margin: 8px 0 0; font-size: 1.5em; font-weight: 700; color: #2271b1; }
	.nte-public-ticket__message { padding: 24px 20px; text-align: center; background: #f8f9fa; }
	.nte-public-ticket__message-icon { font-size: 2.5em; margin-bottom: 8px; }
	.nte-public-ticket__message-text { margin: 0; font-size: 1.1em; font-weight: 500; color: #333; }
	.nte-public-ticket__checkin-info { padding: 16px 20px; text-align: center; background: #f8f9fa; }
	.nte-public-ticket__checkin-label { margin: 0; font-size: 0.75em; color: #595959; text-transform: uppercase; letter-spacing: 0.5px; }
	.nte-public-ticket__checkin-status { margin: 8px 0 0; font-size: 1em; font-weight: 500; color: #2e7d32; }
	.nte-public-ticket__checkin-status--partial { color: #e65100; }
	.nte-public-ticket__code { padding: 20px; text-align: center; background: #f0f0f1; }
	.nte-public-ticket__code-label { margin: 0; font-size: 0.75em; color: #595959; text-transform: uppercase; letter-spacing: 0.5px; }
	.nte-public-ticket__code-value { margin: 8px 0 0; font-size: 1.1em; font-weight: 600; font-family: "SF Mono", Monaco, "Cascadia Code", monospace; color: #555; letter-spacing: 1px; }'
);

// Status display.
if ( $nettertech_events_is_ticket_cancelled ) {
	$nettertech_events_status_class   = 'cancelled';
	$nettertech_events_status_message = __( 'Ticket Cancelled', 'nettertech-events' );
} elseif ( $nettertech_events_is_fully_checked_in ) {
	$nettertech_events_status_class   = 'checked-in';
	$nettertech_events_status_message = __( 'Checked In', 'nettertech-events' );
} else {
	$nettertech_events_status_class   = 'valid';
	$nettertech_events_status_message = __( 'Valid Ticket', 'nettertech-events' );
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
	<title><?php echo esc_html( $nettertech_events_event_title ); ?> - <?php esc_html_e( 'Ticket', 'nettertech-events' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="nte-public-ticket-page">
<main>
<div class="nte-public-ticket">
	<div class="nte-public-ticket__header">
		<div class="nte-public-ticket__status nte-public-ticket__status--<?php echo esc_attr( $nettertech_events_status_class ); ?>">
			<?php echo esc_html( $nettertech_events_status_message ); ?>
		</div>
	</div>

	<div class="nte-public-ticket__event">
		<h1 class="nte-public-ticket__title"><?php echo esc_html( $nettertech_events_event_title ); ?></h1>

		<?php if ( $nettertech_events_event_date ) : ?>
			<div class="nte-public-ticket__detail">
				<span class="nte-public-ticket__detail-icon" aria-hidden="true">&#x1F4C5;</span>
				<span class="nte-public-ticket__detail-text"><?php echo esc_html( $nettertech_events_event_date ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $nettertech_events_event_time ) : ?>
			<div class="nte-public-ticket__detail">
				<span class="nte-public-ticket__detail-icon" aria-hidden="true">&#x1F551;</span>
				<span class="nte-public-ticket__detail-text"><?php echo esc_html( $nettertech_events_event_time ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $nettertech_events_venue_name ) : ?>
			<div class="nte-public-ticket__detail">
				<span class="nte-public-ticket__detail-icon" aria-hidden="true">&#x1F4CD;</span>
				<span class="nte-public-ticket__detail-text"><?php echo esc_html( $nettertech_events_venue_name ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<div class="nte-public-ticket__info">
		<div class="nte-public-ticket__attendee">
			<p class="nte-public-ticket__label"><?php esc_html_e( 'Ticket Holder', 'nettertech-events' ); ?></p>
			<p class="nte-public-ticket__name"><?php echo esc_html( $nettertech_events_attendee_name ); ?></p>
		</div>

		<?php if ( $nettertech_events_quantity > 1 ) : ?>
			<div class="nte-public-ticket__quantity">
				<p class="nte-public-ticket__label"><?php esc_html_e( 'Guests', 'nettertech-events' ); ?></p>
				<p class="nte-public-ticket__value"><?php echo esc_html( (string) $nettertech_events_quantity ); ?></p>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! $nettertech_events_is_ticket_cancelled && ! $nettertech_events_is_fully_checked_in ) : ?>
		<div class="nte-public-ticket__message">
			<div class="nte-public-ticket__message-icon" aria-hidden="true">&#x1F44B;</div>
			<p class="nte-public-ticket__message-text">
				<?php esc_html_e( 'Please present this ticket at the door', 'nettertech-events' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $nettertech_events_is_fully_checked_in ) : ?>
		<div class="nte-public-ticket__checkin-info">
			<p class="nte-public-ticket__checkin-label"><?php esc_html_e( 'Check-in Status', 'nettertech-events' ); ?></p>
			<p class="nte-public-ticket__checkin-status">
				<?php
				printf(
					/* translators: 1: checked in count, 2: total quantity */
					esc_html__( '%1$d of %2$d guests checked in', 'nettertech-events' ),
					(int) $nettertech_events_checked_in_count,
					(int) $nettertech_events_quantity
				);
				?>
			</p>
		</div>
	<?php elseif ( $nettertech_events_checked_in_count > 0 ) : ?>
		<div class="nte-public-ticket__checkin-info">
			<p class="nte-public-ticket__checkin-label"><?php esc_html_e( 'Check-in Status', 'nettertech-events' ); ?></p>
			<p class="nte-public-ticket__checkin-status nte-public-ticket__checkin-status--partial">
				<?php
				printf(
					/* translators: 1: checked in count, 2: total quantity */
					esc_html__( '%1$d of %2$d guests checked in', 'nettertech-events' ),
					(int) $nettertech_events_checked_in_count,
					(int) $nettertech_events_quantity
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="nte-public-ticket__code">
		<p class="nte-public-ticket__code-label"><?php esc_html_e( 'Ticket Code', 'nettertech-events' ); ?></p>
		<p class="nte-public-ticket__code-value"><?php echo esc_html( $nettertech_events_ticket_code ); ?></p>
	</div>
</div>
</main>

<?php wp_footer(); ?>
</body>
</html>
