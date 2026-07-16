<?php
/**
 * Email configuration value object.
 *
 * Provides shared email settings and venue notification recipient resolution.
 * Extracted from EmailService to break the circular dependency between
 * EmailService and its handler delegates.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Models\Ticket;

/**
 * Holds shared email settings and provides venue notification recipient resolution.
 *
 * Injected into OrderEmailHandler, RsvpEmailHandler, and WaitlistEmailHandler
 * instead of the full EmailService, eliminating the circular dependency.
 *
 * @since 2.1.0
 * @api
 */
class EmailConfig {

	/**
	 * Option key for email settings.
	 */
	public const SETTINGS_KEY = 'nettertech_events_email_settings';

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Cached settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $settings = null;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param EventRepositoryInterface      $event_repo      Event repository.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		EventRepositoryInterface $event_repo
	) {
		$this->occurrence_repo = $occurrence_repo;
		$this->event_repo      = $event_repo;
	}

	/**
	 * Get email settings.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$settings = $this->settings;
		if ( null === $settings ) {
			$stored         = get_option( self::SETTINGS_KEY, array() );
			$settings       = is_array( $stored ) ? $stored : array();
			$this->settings = $settings;
		}

		return wp_parse_args(
			$settings,
			array(
				'venue_logo'             => '',
				'venue_contacts'         => array(),
				'disable_qr_codes'       => false,
				'disable_customer_email' => false,
				'cancellation_policy'    => '',
			)
		);
	}

	/**
	 * Check if customer email is disabled.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function is_customer_email_disabled(): bool {
		$settings = $this->get_settings();
		return (bool) $settings['disable_customer_email'];
	}

	/**
	 * Check if QR codes are disabled.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function is_qr_disabled(): bool {
		$settings = $this->get_settings();
		return (bool) $settings['disable_qr_codes'];
	}

	/**
	 * Get venue logo URL.
	 *
	 * @since 2.1.0
	 *
	 * @return string Logo URL or empty string.
	 */
	public function get_venue_logo(): string {
		$settings = $this->get_settings();
		return $settings['venue_logo'] ?? '';
	}

	/**
	 * Get global venue contact emails.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string>
	 */
	public function get_venue_contacts(): array {
		$settings = $this->get_settings();
		$contacts = $settings['venue_contacts'] ?? array();

		if ( is_string( $contacts ) ) {
			$contacts = array_map( 'trim', explode( ',', $contacts ) );
		}

		return array_filter(
			$contacts,
			static fn( $contact ): bool => is_string( $contact ) && false !== is_email( $contact )
		);
	}

	/**
	 * Get cancellation policy text.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function get_cancellation_policy(): string {
		$settings = $this->get_settings();
		return $settings['cancellation_policy'] ?? '';
	}

	/**
	 * Get the email accent color.
	 *
	 * Returns the admin-configured accent color, or falls back to the
	 * theme's primary color for brand consistency.
	 *
	 * @since 2.1.0
	 *
	 * @return string Hex color with # prefix.
	 */
	public function get_accent_color(): string {
		$settings = $this->get_settings();
		$color    = $settings['accent_color'] ?? '';

		if ( ! empty( $color ) ) {
			return $color;
		}

		// Fall back to theme primary color.
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			if ( is_array( $palette ) ) {
				foreach ( $palette as $entry ) {
					$slug = $entry['slug'] ?? '';
					if ( in_array( $slug, array( 'primary', 'accent', 'theme-palette1', 'palette1' ), true ) ) {
						$raw = $entry['color'] ?? '';
						if ( $raw && 0 !== strpos( $raw, 'var(' ) ) {
							return $raw;
						}
						// Resolve CSS var() for Kadence/Astra.
						$resolved = $this->resolve_palette_var( $raw );
						if ( $resolved ) {
							return $resolved;
						}
					}
				}
			}
		}

		return '#333333';
	}

	/**
	 * Get template settings for renderer.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_template_settings(): array {
		return array(
			'venue_logo'          => $this->get_venue_logo(),
			'show_qr_codes'       => ! $this->is_qr_disabled(),
			'cancellation_policy' => $this->get_cancellation_policy(),
			'accent_color'        => $this->get_accent_color(),
		);
	}

	/**
	 * Get venue notification recipients for tickets.
	 *
	 * Implements inheritance model: global + event + occurrence contacts.
	 *
	 * @since 2.1.0
	 *
	 * @param Ticket[] $tickets Tickets.
	 * @return array<string> Unique email addresses.
	 */
	public function get_venue_notification_recipients( array $tickets ): array {
		$recipients = array();

		// Global contacts always included.
		$recipients = array_merge( $recipients, $this->get_venue_contacts() );

		// Get unique occurrences and events.
		$occurrence_ids = array_unique( array_column( $tickets, 'occurrence_id' ) );

		foreach ( $occurrence_ids as $occurrence_id ) {
			$occurrence = $this->occurrence_repo->find( $occurrence_id );
			if ( ! $occurrence ) {
				continue;
			}

			// Event-level notification recipients.
			$event = $this->event_repo->find( $occurrence->event_id );
			if ( $event && ! empty( $event->notification_emails ) ) {
				$event_emails = array_map( 'trim', explode( ',', $event->notification_emails ) );
				$recipients   = array_merge(
					$recipients,
					array_filter(
						$event_emails,
						static function ( string $email ): bool {
							return (bool) is_email( $email );
						}
					)
				);
			}
		}

		return array_unique( $recipients );
	}

	/**
	 * Resolve a CSS var() palette reference to a hex color.
	 *
	 * Handles Kadence (var(--global-palette1)) and Astra (var(--ast-global-color-0)).
	 *
	 * @since 2.1.0
	 *
	 * @param string $var_ref CSS var() reference.
	 * @return string|null Hex color, or null if unresolvable.
	 */
	private function resolve_palette_var( string $var_ref ): ?string {
		if ( ! preg_match( '/var\(\s*--([^)]+)\s*\)/', $var_ref, $matches ) ) {
			return null;
		}
		$prop     = trim( $matches[1] );
		$template = wp_get_theme()->get_template();

		// Kadence: var(--global-palette1) = accent/primary.
		if ( 0 === strpos( $template, 'kadence' ) && preg_match( '/^global-palette(\d)$/', $prop, $slot ) ) {
			$option = get_option( 'kadence_global_palette' );
			$data   = is_string( $option ) ? json_decode( $option, true ) : $option;
			if ( is_array( $data ) ) {
				$active = $data['active'] ?? 'palette';
				$colors = $data[ $active ] ?? array();
				$index  = (int) $slot[1] - 1;
				$hex    = $colors[ $index ]['color'] ?? null;
				if ( is_string( $hex ) && '' !== $hex ) {
					return $hex;
				}
			}
		}

		// Astra: var(--ast-global-color-0).
		if ( 'astra' === $template && preg_match( '/^ast-global-color-(\d+)$/', $prop, $slot ) ) {
			$option = get_option( 'astra-settings' );
			if ( is_array( $option ) && isset( $option['global-color-palette']['palette'][ (int) $slot[1] ] ) ) {
				return $option['global-color-palette']['palette'][ (int) $slot[1] ];
			}
		}

		return null;
	}
}
