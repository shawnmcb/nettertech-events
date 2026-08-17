<?php
/**
 * RSVP Form Shortcode.
 *
 * @package NetterTechEvents\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Services\WaitlistAvailabilityResolver;

/**
 * RSVP form shortcode for free event registration.
 *
 * Usage: [nettertech_events_rsvp occurrence_id="123"]
 * Or embed in event template for current occurrence.
 *
 * @since 1.0.0
 */
class RSVPFormShortcode {

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repo;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepository
	 */
	private TicketTypeRepository $ticket_type_repo;

	/**
	 * Attendee repository.
	 *
	 * @var AttendeeRepository
	 */
	private AttendeeRepository $attendee_repo;

	/**
	 * Capacity calculator.
	 *
	 * @var CapacityCalculatorInterface
	 */
	private CapacityCalculatorInterface $capacity_calculator;

	/**
	 * Rate limit service.
	 *
	 * @var RateLimitService
	 */
	private RateLimitService $rate_limit_service;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepository              $occurrence_repo     Occurrence repository.
	 * @param TicketTypeRepository              $ticket_type_repo    Ticket type repository.
	 * @param AttendeeRepository                $attendee_repo       Attendee repository.
	 * @param CapacityCalculatorInterface       $capacity_calculator Capacity calculator.
	 * @param RateLimitService                  $rate_limit_service  Rate limit service.
	 * @param WaitlistAvailabilityResolver|null $waitlist_resolver Availability resolver (NTE-214); null = legacy filter-only gate.
	 */
	public function __construct(
		OccurrenceRepository $occurrence_repo,
		TicketTypeRepository $ticket_type_repo,
		AttendeeRepository $attendee_repo,
		CapacityCalculatorInterface $capacity_calculator,
		RateLimitService $rate_limit_service,
		?WaitlistAvailabilityResolver $waitlist_resolver = null
	) {
		$this->occurrence_repo     = $occurrence_repo;
		$this->ticket_type_repo    = $ticket_type_repo;
		$this->attendee_repo       = $attendee_repo;
		$this->capacity_calculator = $capacity_calculator;
		$this->rate_limit_service  = $rate_limit_service;
		$this->waitlist_resolver   = $waitlist_resolver;
	}

	/**
	 * Waitlist availability resolver (NTE-214); null = legacy filter-only gate.
	 *
	 * @var WaitlistAvailabilityResolver|null
	 */
	private ?WaitlistAvailabilityResolver $waitlist_resolver;

	/**
	 * Whether the waitlist is available for an occurrence.
	 *
	 * @param \NetterTechEvents\Models\Occurrence|null $occurrence Occurrence (null = unknown → filter default).
	 * @return bool
	 */
	private function has_waitlist( ?\NetterTechEvents\Models\Occurrence $occurrence ): bool {
		if ( null !== $this->waitlist_resolver && null !== $occurrence ) {
			return $this->waitlist_resolver->is_enabled_for_occurrence( $occurrence );
		}

		return (bool) apply_filters( 'nettertech_events_has_waitlist', true, $occurrence );
	}

	/**
	 * Whether RSVP form styles have been enqueued this page load.
	 *
	 * @var bool
	 */
	private static bool $styles_enqueued = false;

	/**
	 * Enqueue RSVP form inline styles once per page load.
	 *
	 * @return void
	 */
	private static function enqueue_styles(): void {
		if ( self::$styles_enqueued ) {
			return;
		}
		self::$styles_enqueued = true;

		$css = '
			.nte-rsvp-form-wrapper { max-width: 400px; }
			.nte-rsvp-form { display: grid; gap: 15px; }
			.nte-rsvp-form .screen-reader-text { border: 0; clip: rect(1px, 1px, 1px, 1px); clip-path: inset(50%); height: 1px; margin: -1px; overflow: hidden; padding: 0; position: absolute; width: 1px; word-wrap: normal !important; }
			.nte-rsvp-field label { display: block; margin-bottom: 5px; font-weight: 500; }
			.nte-rsvp-field .required { color: #c00; }
			.nte-rsvp-field input, .nte-rsvp-field select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
			.nte-rsvp-field input:focus, .nte-rsvp-field select:focus { border-color: #2271b1; outline: none; box-shadow: 0 0 0 1px #2271b1; }
			.nte-rsvp-button { border: none; cursor: pointer; }
			.nte-rsvp-message { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
			.nte-rsvp-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
			.nte-rsvp-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
			.nte-rsvp-closed { color: #666; font-style: italic; }
		';

		wp_add_inline_style( 'nettertech-events-base', $css );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render( array $atts = array() ): string {
		self::enqueue_styles();

		$atts = shortcode_atts(
			array(
				'occurrence_id' => 0,
				'show_party'    => 'true',
				'max_party'     => 10,
				'button_text'   => __( 'RSVP Now', 'nettertech-events' ),
				'success_text'  => __( 'Thank you! Your RSVP has been confirmed.', 'nettertech-events' ),
			),
			$atts,
			'nettertech_events_rsvp'
		);

		$occurrence_id = absint( $atts['occurrence_id'] );
		$show_party    = filter_var( $atts['show_party'], FILTER_VALIDATE_BOOLEAN );
		$max_party     = absint( $atts['max_party'] );

		// Handle form submission.
		$message = $this->handle_submission( $occurrence_id );

		// Get occurrence.
		$occurrence = $occurrence_id ? $this->occurrence_repo->find_with_event( $occurrence_id ) : null;

		if ( ! $occurrence ) {
			return '<p class="nte-rsvp-error">' . esc_html__( 'Event not found.', 'nettertech-events' ) . '</p>';
		}

		// Check if event has passed.
		if ( $occurrence->has_ended() ) {
			return '<p class="nte-rsvp-closed">' . esc_html__( 'This event has ended.', 'nettertech-events' ) . '</p>';
		}

		// Check if only free tickets (RSVP applicable).
		if ( ! $this->ticket_type_repo->occurrence_is_free( $occurrence_id ) ) {
			// Has paid tickets - don't show RSVP form.
			return '';
		}

		// Check capacity at render time (don't show form if event is full).
		// Skip if we already have a success message (user just got the last spot).
		if ( ! $message || 'error' === $message['type'] ) {
			$render_capacity = $this->check_capacity( $occurrence_id, 1 );
			if ( null !== $render_capacity ) {
				$html = '<div class="nte-rsvp-form-wrapper"><div class="nte-rsvp-message nte-rsvp-error" role="alert">'
					. esc_html( $render_capacity['text'] )
					. '</div>';

				// Render the waitlist panel for sold-out RSVP events.
				$has_waitlist = $this->has_waitlist( $occurrence );
				if ( $has_waitlist && $occurrence ) {
					ob_start();
					/**
					 * Fires after a sold-out message in RSVP context.
					 *
					 * Allows the waitlist panel to render below the sold-out message.
					 * Uses a dummy TicketType since RSVP events may not have a specific one.
					 *
					 * @since 1.0.2
					 *
					 * @param \NetterTechEvents\Models\TicketType $ticket_type Placeholder ticket type.
					 * @param \NetterTechEvents\Models\Occurrence $occurrence  The occurrence.
					 */
					do_action( 'nettertech_events_after_sold_out', new \NetterTechEvents\Models\TicketType(), $occurrence );
					$html .= (string) ob_get_clean();
				}

				$html .= '</div>';
				return $html;
			}
		}

		// Get current user email if logged in.
		$user_email = '';
		$user_name  = '';
		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			$user_email   = $current_user->user_email;
			$user_name    = $current_user->display_name;
		}

		ob_start();
		?>
		<div class="nte-rsvp-form-wrapper">
			<?php if ( $message ) : ?>
				<div class="nte-rsvp-message nte-rsvp-<?php echo esc_attr( $message['type'] ); ?>" role="alert">
					<?php echo esc_html( $message['text'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $message || 'error' === $message['type'] ) : ?>
				<form method="post" class="nte-rsvp-form" aria-label="<?php esc_attr_e( 'RSVP registration form', 'nettertech-events' ); ?>">
					<?php wp_nonce_field( 'nettertech_events_rsvp_' . $occurrence_id, 'nettertech_events_rsvp_nonce' ); ?>
					<input type="hidden" name="nettertech_events_rsvp_occurrence_id" value="<?php echo esc_attr( (string) $occurrence_id ); ?>">
					<p class="screen-reader-text"><?php esc_html_e( 'Required fields are marked with an asterisk (*).', 'nettertech-events' ); ?></p>

					<div class="nte-rsvp-field">
						<label for="nte-rsvp-name">
							<?php esc_html_e( 'Name', 'nettertech-events' ); ?>
							<span class="required" aria-hidden="true">*</span>
						</label>
						<input type="text" id="nte-rsvp-name" name="nettertech_events_rsvp_name"
								value="<?php echo esc_attr( $user_name ); ?>"
								required aria-required="true">
					</div>

					<div class="nte-rsvp-field">
						<label for="nte-rsvp-email">
							<?php esc_html_e( 'Email', 'nettertech-events' ); ?>
							<span class="required" aria-hidden="true">*</span>
						</label>
						<input type="email" id="nte-rsvp-email" name="nettertech_events_rsvp_email"
								value="<?php echo esc_attr( $user_email ); ?>"
								required aria-required="true">
					</div>

					<?php if ( $show_party ) : ?>
						<div class="nte-rsvp-field">
							<label for="nte-rsvp-quantity">
								<?php esc_html_e( 'Party Size', 'nettertech-events' ); ?>
							</label>
							<select id="nte-rsvp-quantity" name="nettertech_events_rsvp_quantity">
								<?php for ( $i = 1; $i <= $max_party; $i++ ) : ?>
									<option value="<?php echo esc_attr( (string) $i ); ?>">
										<?php echo esc_html( (string) $i ); ?>
									</option>
								<?php endfor; ?>
							</select>
						</div>
					<?php else : ?>
						<input type="hidden" name="nettertech_events_rsvp_quantity" value="1">
					<?php endif; ?>

					<div class="nte-rsvp-submit">
						<button type="submit" name="nettertech_events_rsvp_submit" class="nte-rsvp-button wp-element-button">
							<?php echo esc_html( $atts['button_text'] ); ?>
						</button>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Handle form submission.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<string, string>|null Message array with 'type' and 'text', or null.
	 */
	private function handle_submission( int $occurrence_id ): ?array {
		if ( ! isset( $_POST['nettertech_events_rsvp_submit'] ) ) {
			return null;
		}

		// Rate limiting.
		if ( ! $this->rate_limit_service->should_bypass() ) {
			$limited = $this->rate_limit_service->check_and_increment();
			if ( $limited ) {
				return array(
					'type' => 'error',
					'text' => __( 'Too many requests. Please try again later.', 'nettertech-events' ),
				);
			}
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nettertech_events_rsvp_nonce'] ?? '' ) ), 'nettertech_events_rsvp_' . $occurrence_id ) ) {
			return array(
				'type' => 'error',
				'text' => __( 'Your session has expired. Please reload the page and try again.', 'nettertech-events' ),
			);
		}

		// Verify occurrence ID matches.
		$submitted_id = absint( $_POST['nettertech_events_rsvp_occurrence_id'] ?? 0 );
		if ( $submitted_id !== $occurrence_id ) {
			return array(
				'type' => 'error',
				'text' => __( 'Invalid form submission.', 'nettertech-events' ),
			);
		}

		// Validate fields.
		$name     = sanitize_text_field( wp_unslash( $_POST['nettertech_events_rsvp_name'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['nettertech_events_rsvp_email'] ?? '' ) );
		$quantity = absint( $_POST['nettertech_events_rsvp_quantity'] ?? 1 );

		if ( empty( $name ) ) {
			return array(
				'type' => 'error',
				'text' => __( 'Please enter your name.', 'nettertech-events' ),
			);
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			return array(
				'type' => 'error',
				'text' => __( 'Please enter a valid email address.', 'nettertech-events' ),
			);
		}

		if ( $quantity < 1 ) {
			$quantity = 1;
		}

		// Check if already registered.
		if ( $this->attendee_repo->email_exists_for_occurrence( $occurrence_id, $email ) ) {
			return array(
				'type' => 'error',
				'text' => __( 'This email is already registered for this event.', 'nettertech-events' ),
			);
		}

		// Check capacity (skip_cache=true for real-time accuracy).
		$capacity_error = $this->check_capacity( $occurrence_id, $quantity );
		if ( null !== $capacity_error ) {
			return $capacity_error;
		}

		// Create attendee.
		$attendee                = new Attendee();
		$attendee->occurrence_id = $occurrence_id;
		$attendee->name          = $name;
		$attendee->email         = $email;
		$attendee->quantity      = $quantity;
		$attendee->status        = 'confirmed';

		try {
			$this->attendee_repo->save( $attendee );

			$form_data = array(
				'occurrence_id'  => $occurrence_id,
				'ticket_type_id' => 0,
				'email'          => $email,
				'name'           => $name,
				'quantity'       => $quantity,
			);

			/**
			 * Fires when an RSVP is submitted.
			 *
			 * @param int                  $attendee_id The created attendee ID.
			 * @param array<string, mixed> $form_data   Submitted form data (occurrence_id, ticket_type_id, email, name, quantity).
			 */
			do_action( 'nettertech_events_rsvp_submitted', (int) $attendee->id, $form_data );

			return array(
				'type' => 'success',
				'text' => __( 'Thank you! Your RSVP has been confirmed.', 'nettertech-events' ),
			);

		} catch ( \RuntimeException $e ) {
			return array(
				'type' => 'error',
				'text' => __( 'An error occurred. Please try again.', 'nettertech-events' ),
			);
		}
	}

	/**
	 * Check occurrence capacity before allowing RSVP.
	 *
	 * Returns null if capacity is available (or unlimited), or an error
	 * message array if the event is full.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @param int $quantity      Requested party size.
	 * @return array{type: string, text: string}|null Error message or null if OK.
	 */
	private function check_capacity( int $occurrence_id, int $quantity ): ?array {
		$capacity = $this->capacity_calculator->get_occurrence_capacity( $occurrence_id, true );

		// Unlimited capacity — no constraint.
		if ( $capacity['has_unlimited'] ) {
			return null;
		}

		// No ticket types found — no constraint (unconfigured RSVP event).
		if ( null === $capacity['total_capacity'] ) {
			return null;
		}

		// Capacity is set but zero remaining.
		$available = $capacity['total_available'] ?? 0;

		if ( $available <= 0 ) {
			return $this->build_full_message( $occurrence_id );
		}

		// Not enough capacity for the requested quantity.
		if ( $available < $quantity ) {
			return array(
				'type' => 'error',
				'text' => sprintf(
					/* translators: %d: number of spots remaining */
					__( 'Only %d spot(s) remaining for this event.', 'nettertech-events' ),
					$available
				),
			);
		}

		return null;
	}

	/**
	 * Build the "event is full" error message.
	 *
	 * If the waitlist feature is available, mentions the waitlist option.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array{type: string, text: string}
	 */
	private function build_full_message( int $occurrence_id ): array {
		// Only load the occurrence when a resolver can use it (legacy filter-only
		// gate needs none).
		$occurrence   = ( null !== $this->waitlist_resolver && $occurrence_id > 0 ) ? $this->occurrence_repo->find_with_event( $occurrence_id ) : null;
		$has_waitlist = $this->has_waitlist( $occurrence );

		if ( $has_waitlist ) {
			$text = __( 'This event is full. You may join the waitlist to be notified if a spot opens up.', 'nettertech-events' );
		} else {
			$text = __( 'This event is full.', 'nettertech-events' );
		}

		return array(
			'type' => 'error',
			'text' => $text,
		);
	}
}
