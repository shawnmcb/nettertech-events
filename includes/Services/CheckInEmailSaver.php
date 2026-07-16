<?php
/**
 * Check-In Email Saver Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Handles saving check-in email recipients for events.
 *
 * Extracted from EventEditor to reduce complexity and improve testability.
 *
 * @since 0.9.0
 * @api
 */
class CheckInEmailSaver {

	/**
	 * Option key prefix for storing check-in emails.
	 *
	 * @var string
	 */
	private const OPTION_PREFIX = 'nettertech_events_event_checkin_emails_';

	/**
	 * Save check-in email recipients for an event.
	 *
	 * Caller must pass already-unslashed POST data after verifying nonce at the
	 * request boundary. The service does not read superglobals directly.
	 *
	 * @param int                  $event_id  Event ID.
	 * @param array<string, mixed> $post_data POST data array (required).
	 * @return void
	 */
	public function save( int $event_id, array $post_data ): void {
		$emails_raw = sanitize_textarea_field( wp_unslash( $post_data['checkin_emails'] ?? '' ) );
		$option_key = self::OPTION_PREFIX . $event_id;

		if ( empty( trim( $emails_raw ) ) ) {
			delete_option( $option_key );
			return;
		}

		$emails = $this->parse_emails( $emails_raw );

		if ( empty( $emails ) ) {
			delete_option( $option_key );
		} else {
			update_option( $option_key, $emails, false );
		}
	}

	/**
	 * Get check-in email recipients for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string> Array of email addresses.
	 */
	public function get( int $event_id ): array {
		$emails = get_option( self::OPTION_PREFIX . $event_id, array() );
		return is_array( $emails ) ? $emails : array();
	}

	/**
	 * Delete check-in email recipients for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( int $event_id ): bool {
		return delete_option( self::OPTION_PREFIX . $event_id );
	}

	/**
	 * Parse and validate email addresses from raw input.
	 *
	 * @param string $raw_input Raw email input (one per line).
	 * @return array<string> Valid email addresses.
	 */
	private function parse_emails( string $raw_input ): array {
		$lines  = explode( "\n", $raw_input );
		$emails = array();

		foreach ( $lines as $line ) {
			$email = trim( $line );
			if ( ! empty( $email ) && is_email( $email ) ) {
				$emails[] = sanitize_email( $email );
			}
		}

		return $emails;
	}
}
