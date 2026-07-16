<?php
/**
 * Volunteer scan result template.
 *
 * Displayed when a volunteer with a valid cookie scans a ticket QR code
 * using their phone's native camera app.
 *
 * Available via:
 * - Router::get_current_ticket_data() - Ticket and attendee data
 * - Router::is_volunteer_scan() - Always true on this template
 *
 * @package NetterTechEvents\Templates
 */

declare(strict_types=1);

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Frontend\Router;
use NetterTechEvents\Services\CheckInTokenService;

// Get ticket data from Router.
$nettertech_events_ticket_data = Router::get_current_ticket_data();

if ( ! $nettertech_events_ticket_data ) {
	wp_die( esc_html__( 'Ticket not found.', 'nettertech-events' ) );
}

// Extract data for easier access.
$nettertech_events_attendee_name    = $nettertech_events_ticket_data->attendee_name ?? __( 'Guest', 'nettertech-events' );
$nettertech_events_attendee_email   = $nettertech_events_ticket_data->attendee_email ?? '';
$nettertech_events_quantity         = (int) ( $nettertech_events_ticket_data->quantity ?? 1 );
$nettertech_events_checked_in_count = (int) ( $nettertech_events_ticket_data->checked_in_count ?? 0 );
$nettertech_events_ticket_status    = $nettertech_events_ticket_data->ticket_status ?? 'confirmed';
$nettertech_events_occurrence_id    = (int) $nettertech_events_ticket_data->occurrence_id;
$nettertech_events_attendee_id      = (int) $nettertech_events_ticket_data->attendee_id;
$nettertech_events_event_title      = $nettertech_events_ticket_data->occurrence_title ? $nettertech_events_ticket_data->occurrence_title : $nettertech_events_ticket_data->event_title;
$nettertech_events_venue_name       = $nettertech_events_ticket_data->venue_name ?? '';

// Format event datetime.
$nettertech_events_start_datetime = $nettertech_events_ticket_data->start_datetime ?? '';
$nettertech_events_event_datetime = '';
if ( $nettertech_events_start_datetime ) {
	$nettertech_events_date_format    = get_option( 'date_format' );
	$nettertech_events_time_format    = get_option( 'time_format' );
	$nettertech_events_event_datetime = wp_date( $nettertech_events_date_format . ' ' . $nettertech_events_time_format, strtotime( $nettertech_events_start_datetime ) );
}

// Build filterable scan data array for extension plugins.
$nettertech_events_scan_data = array(
	'attendee_id'      => $nettertech_events_attendee_id,
	'attendee_name'    => $nettertech_events_attendee_name,
	'attendee_email'   => $nettertech_events_attendee_email,
	'quantity'         => $nettertech_events_quantity,
	'checked_in_count' => $nettertech_events_checked_in_count,
	'ticket_status'    => $nettertech_events_ticket_status,
	'occurrence_id'    => $nettertech_events_occurrence_id,
	'event_title'      => $nettertech_events_event_title,
	'venue_name'       => $nettertech_events_venue_name,
	'event_datetime'   => $nettertech_events_event_datetime,
);

/**
 * Filters the ticket scan result data before template rendering.
 *
 * Allows extension plugins to add data (e.g., seat assignments)
 * to the scan result display.
 *
 * @since 1.0.2
 *
 * @param array<string, mixed> $nettertech_events_scan_data Scan result template data.
 */
$nettertech_events_scan_data = apply_filters( 'nettertech_events_ticket_scan_data', $nettertech_events_scan_data );

// Determine check-in state.
$nettertech_events_is_fully_checked_in  = $nettertech_events_checked_in_count >= $nettertech_events_quantity;
$nettertech_events_is_partially_checked = $nettertech_events_checked_in_count > 0 && $nettertech_events_checked_in_count < $nettertech_events_quantity;
$nettertech_events_is_ticket_valid      = 'confirmed' === $nettertech_events_ticket_status;
$nettertech_events_is_ticket_checked_in = 'checked_in' === $nettertech_events_ticket_status;
$nettertech_events_is_ticket_cancelled  = in_array( $nettertech_events_ticket_status, array( 'cancelled', 'refunded' ), true );

// Determine status message and styling.
if ( $nettertech_events_is_ticket_cancelled ) {
	$nettertech_events_status_class   = 'error';
	$nettertech_events_status_icon    = '&#x2716;'; // X mark.
	$nettertech_events_status_message = __( 'Ticket Cancelled', 'nettertech-events' );
	$nettertech_events_can_check_in   = false;
} elseif ( $nettertech_events_is_fully_checked_in || $nettertech_events_is_ticket_checked_in ) {
	$nettertech_events_status_class   = 'warning';
	$nettertech_events_status_icon    = '&#x2714;'; // Check mark.
	$nettertech_events_status_message = __( 'Already Checked In', 'nettertech-events' );
	$nettertech_events_can_check_in   = false;
} elseif ( $nettertech_events_is_partially_checked ) {
	$nettertech_events_status_class   = 'partial';
	$nettertech_events_status_icon    = '&#x25D4;'; // Circle with quadrant.
	$nettertech_events_status_message = sprintf(
		/* translators: 1: checked in count, 2: total quantity */
		__( '%1$d of %2$d Checked In', 'nettertech-events' ),
		$nettertech_events_checked_in_count,
		$nettertech_events_quantity
	);
	$nettertech_events_can_check_in = true;
} else {
	$nettertech_events_status_class   = 'success';
	$nettertech_events_status_icon    = '&#x2714;'; // Check mark.
	$nettertech_events_status_message = __( 'Ready to Check In', 'nettertech-events' );
	$nettertech_events_can_check_in   = true;
}

// Auto check-in flag from URL. This is a read-only optional behavior hint, no nonce needed.
$nettertech_events_auto_checkin = '1' === \NetterTechEvents\Admin\AdminRequest::get_text( 'auto' );

// Get the public check-in page URL for this occurrence.
$nettertech_events_checkin_token_service = \NetterTechEvents\nettertech_events_container()->get( CheckInTokenService::class );
$nettertech_events_checkin_page_url      = $nettertech_events_checkin_token_service->get_public_url( $nettertech_events_occurrence_id );

wp_enqueue_style(
	'nettertech-events-ticket-scan-result',
	NETTERTECH_EVENTS_PLUGIN_URL . 'assets/css/ticket-scan-result.css',
	array(),
	NETTERTECH_EVENTS_VERSION
);
wp_enqueue_script(
	'nettertech-events-ticket-scan-result',
	NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/ticket-scan-result.js',
	array(),
	NETTERTECH_EVENTS_VERSION,
	array(
		'in_footer' => true,
		'strategy'  => 'defer',
	)
);
wp_localize_script(
	'nettertech-events-ticket-scan-result',
	'nettertechEventsTicketScanResult',
	array(
		'apiBase'     => rest_url( 'nettertech-events/v1/check-in/' ),
		'nonce'       => wp_create_nonce( 'wp_rest' ),
		'attendeeId'  => (string) $nettertech_events_attendee_id,
		'autoCheckin' => $nettertech_events_auto_checkin,
		'canCheckIn'  => $nettertech_events_can_check_in,
		'quantity'    => $nettertech_events_quantity,
		'strings'     => array(
			'fullyCheckedIn' => __( 'Fully Checked In', 'nettertech-events' ),
			'of'             => __( 'of', 'nettertech-events' ),
			'checkedIn'      => __( 'Checked In', 'nettertech-events' ),
			'checkInAll'     => __( 'Check In All', 'nettertech-events' ),
			'readyToCheckIn' => __( 'Ready to Check In', 'nettertech-events' ),
		),
	)
);

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
	<title><?php echo esc_html( $nettertech_events_attendee_name ); ?> - <?php esc_html_e( 'Check-In', 'nettertech-events' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="nte-scan-result-page nte-scan-result--<?php echo esc_attr( $nettertech_events_status_class ); ?>">
<main>
<div class="nte-scan-result">
	<div class="nte-scan-result__status nte-scan-result__status--<?php echo esc_attr( $nettertech_events_status_class ); ?>">
		<span class="nte-scan-result__status-icon" aria-hidden="true"><?php echo esc_html( $nettertech_events_status_icon ); ?></span>
		<span class="nte-scan-result__status-text"><?php echo esc_html( $nettertech_events_status_message ); ?></span>
	</div>

	<div class="nte-scan-result__attendee">
		<h1 class="nte-scan-result__name"><?php echo esc_html( $nettertech_events_attendee_name ); ?></h1>
		<?php if ( $nettertech_events_attendee_email ) : ?>
			<p class="nte-scan-result__email"><?php echo esc_html( $nettertech_events_attendee_email ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $nettertech_events_scan_data['seats'] ) ) : ?>
			<p class="nte-scan-result__seats">
				<strong><?php esc_html_e( 'Seats:', 'nettertech-events' ); ?></strong>
				<?php echo esc_html( $nettertech_events_scan_data['seats'] ); ?>
			</p>
		<?php endif; ?>
	</div>

	<div class="nte-scan-result__count" role="status" aria-live="polite">
		<span class="nte-scan-result__count-value" id="checked-count"><?php echo esc_html( (string) $nettertech_events_checked_in_count ); ?></span>
		<span class="nte-scan-result__count-separator">/</span>
		<span class="nte-scan-result__count-total"><?php echo esc_html( (string) $nettertech_events_quantity ); ?></span>
		<span class="nte-scan-result__count-label"><?php esc_html_e( 'guests', 'nettertech-events' ); ?></span>
	</div>

	<?php if ( $nettertech_events_can_check_in ) : ?>
		<div class="nte-scan-result__actions">
			<?php if ( 1 === $nettertech_events_quantity ) : ?>
				<button type="button" class="nte-scan-result__btn nte-scan-result__btn--primary" id="checkin-btn" data-attendee-id="<?php echo esc_attr( (string) $nettertech_events_attendee_id ); ?>">
					<?php esc_html_e( 'Check In', 'nettertech-events' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="nte-scan-result__btn nte-scan-result__btn--primary" id="checkin-one-btn" data-attendee-id="<?php echo esc_attr( (string) $nettertech_events_attendee_id ); ?>">
					<?php esc_html_e( 'Check In +1', 'nettertech-events' ); ?>
				</button>
				<button type="button" class="nte-scan-result__btn nte-scan-result__btn--secondary" id="checkin-all-btn" data-attendee-id="<?php echo esc_attr( (string) $nettertech_events_attendee_id ); ?>">
					<?php
					printf(
						/* translators: %d: remaining guests to check in */
						esc_html__( 'Check In All (%d)', 'nettertech-events' ),
						(int) ( $nettertech_events_quantity - $nettertech_events_checked_in_count )
					);
					?>
				</button>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="nte-scan-result__event">
		<p class="nte-scan-result__event-title"><?php echo esc_html( $nettertech_events_event_title ); ?></p>
		<?php if ( $nettertech_events_event_datetime ) : ?>
			<p class="nte-scan-result__event-datetime"><?php echo esc_html( $nettertech_events_event_datetime ); ?></p>
		<?php endif; ?>
		<?php if ( $nettertech_events_venue_name ) : ?>
			<p class="nte-scan-result__event-venue"><?php echo esc_html( $nettertech_events_venue_name ); ?></p>
		<?php endif; ?>
	</div>

	<div class="nte-scan-result__footer">
		<?php if ( $nettertech_events_checkin_page_url ) : ?>
			<a href="<?php echo esc_url( $nettertech_events_checkin_page_url ); ?>" class="nte-scan-result__link">
				<?php esc_html_e( 'View Full List', 'nettertech-events' ); ?>
			</a>
		<?php endif; ?>
		<p class="nte-scan-result__hint">
			<?php esc_html_e( 'Open camera to scan next ticket', 'nettertech-events' ); ?>
		</p>
	</div>
</div>
</main>

<?php wp_footer(); ?>
</body>
</html>
