<?php
/**
 * Waitlist REST API Controller.
 *
 * Public REST endpoints for joining, checking, and leaving the waitlist.
 *
 * @package NetterTechEvents\API
 */

declare(strict_types=1);

namespace NetterTechEvents\API;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Contracts\WaitlistServiceInterface;
use NetterTechEvents\Services\RateLimitService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST API controller for public waitlist operations.
 *
 * @since 2.1.0
 */
class WaitlistRestController extends WP_REST_Controller {

	/**
	 * Default waitlist leave token time-to-live: 30 days.
	 *
	 * @var int
	 */
	private const LEAVE_TOKEN_TTL = 2592000;

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'nettertech-events/v1';

	/**
	 * Resource name.
	 *
	 * @var string
	 */
	protected $rest_base = 'waitlist';

	/**
	 * Waitlist service.
	 *
	 * @var WaitlistServiceInterface
	 */
	private WaitlistServiceInterface $service;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Rate limit service.
	 *
	 * @var RateLimitService
	 */
	private RateLimitService $rate_limit_service;

	/**
	 * Constructor.
	 *
	 * @param WaitlistServiceInterface      $service            Waitlist service.
	 * @param OccurrenceRepositoryInterface $occurrence_repo    Occurrence repository.
	 * @param RateLimitService              $rate_limit_service Rate limit service.
	 */
	public function __construct(
		WaitlistServiceInterface $service,
		OccurrenceRepositoryInterface $occurrence_repo,
		RateLimitService $rate_limit_service
	) {
		$this->service            = $service;
		$this->occurrence_repo    = $occurrence_repo;
		$this->rate_limit_service = $rate_limit_service;
	}

	/**
	 * Register routes.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/join',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_join' ),
					'permission_callback' => array( $this, 'public_waitlist_permission_check' ),
					'args'                => $this->get_join_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_status' ),
					'permission_callback' => array( $this, 'public_waitlist_permission_check' ),
					'args'                => $this->get_status_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/leave',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_leave' ),
					'permission_callback' => array( $this, 'leave_permission_check' ),
					'args'                => $this->get_leave_args(),
				),
			)
		);
	}

	/**
	 * Permission check for the waitlist /leave endpoint.
	 *
	 * Defaults to requiring a signed base-plugin token. Extension plugins can
	 * hook `nettertech_events_waitlist_leave_authorized` to authorize another proof, such as
	 * a Pro cancellation token or confirmation-email workflow.
	 *
	 * @since 2.2.0
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param WP_REST_Request $request Request object.
	 * @return bool|\WP_Error True if authorized, WP_Error to deny with status code.
	 */
	public function leave_permission_check( WP_REST_Request $request ) {
		$occurrence_id = (int) $request->get_param( 'occurrence_id' );
		$email         = (string) $request->get_param( 'email' );
		$token         = (string) $request->get_param( 'token' );
		$authorized    = $this->verify_leave_token( $occurrence_id, $email, $token );

		/**
		 * Filters whether a waitlist /leave request is authorized.
		 *
		 * Default: result of the base plugin's signed leave-token check.
		 * Extension plugins (e.g., NetterTech Events Pro) can return true or
		 * WP_Error after validating their own cancellation token.
		 *
		 * @since 1.0.2
		 *
		 * @param bool|\WP_Error  $authorized    True if authorized; WP_Error to deny.
		 * @param int             $occurrence_id Occurrence ID being left.
		 * @param string          $email         Email address requesting leave.
		 * @param WP_REST_Request $request       Full REST request.
		 */
		$authorized = apply_filters( 'nettertech_events_waitlist_leave_authorized', $authorized, $occurrence_id, $email, $request );

		if ( true === $authorized || $authorized instanceof WP_Error ) {
			return $authorized;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'A valid waitlist removal token is required.', 'nettertech-events' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Handle waitlist join request.
	 *
	 * @since 2.1.0
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_join( WP_REST_Request $request ): WP_REST_Response {
		// Rate limiting.
		if ( ! $this->rate_limit_service->should_bypass() ) {
			$limited = $this->rate_limit_service->check_and_increment();
			if ( $limited ) {
				return $limited;
			}
		}

		$occurrence_id = $request->get_param( 'occurrence_id' );
		$email         = $request->get_param( 'email' );
		$name          = $request->get_param( 'name' );
		$phone         = $request->get_param( 'phone' );

		// Verify occurrence exists.
		$occurrence = $this->occurrence_repo->find( $occurrence_id );
		if ( ! $occurrence ) {
			return new WP_REST_Response(
				array(
					'code'    => 'occurrence_not_found',
					'message' => __( 'Event not found.', 'nettertech-events' ),
				),
				404
			);
		}

		try {
			$entry = $this->service->join( $occurrence_id, $email, $name, $phone );
		} catch ( ValidationException $e ) {
			return new WP_REST_Response(
				array(
					'code'    => 'already_on_waitlist',
					'message' => $e->getMessage(),
				),
				409
			);
		}

		/**
		 * Fires after a waitlist entry is successfully created.
		 *
		 * Extension plugins (e.g., NetterTech Events Pro) can hook this to
		 * mint a cancellation token, send a confirmation email, or perform
		 * other post-join workflows without modifying the base plugin.
		 *
		 * @since 1.0.2
		 *
		 * @param \NetterTechEvents\Models\WaitlistEntry $entry         Newly created waitlist entry.
		 * @param int                                    $occurrence_id Occurrence ID joined.
		 */
		do_action( 'nettertech_events_waitlist_entry_joined', $entry, $occurrence_id );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'position'    => $entry->position,
				'leave_token' => $this->create_leave_token( (int) $occurrence_id, (string) $email ),
				'message'     => sprintf(
				/* translators: %d: position number on the waitlist */
					__( 'You are #%d on the waitlist.', 'nettertech-events' ),
					$entry->position
				),
			),
			201
		);
	}

	/**
	 * Handle waitlist status check.
	 *
	 * Mitigates email enumeration (SEC-007): by default, the response does NOT
	 * reveal whether the email is on the waitlist. Extension plugins (e.g.,
	 * NetterTech Events Pro) can hook `nettertech_events_waitlist_status_authorized` to
	 * return true when a valid cancellation token is presented, in which case
	 * the real position is included in the response.
	 *
	 * Response shape is preserved (`on_waitlist`, `position`) for backwards
	 * compatibility; values are simply suppressed when the caller is not
	 * authorized to see the real state.
	 *
	 * @since 2.1.0
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_status( WP_REST_Request $request ): WP_REST_Response {
		// Rate limiting (two layers: per-IP burst protection + per-email enumeration protection).
		if ( ! $this->rate_limit_service->should_bypass() ) {
			$limited = $this->rate_limit_service->check_and_increment();
			if ( $limited ) {
				return $limited;
			}

			$per_email_id = sprintf(
				'waitlist_status_email:%s:%d',
				sha1( (string) $request->get_param( 'email' ) ),
				(int) $request->get_param( 'occurrence_id' )
			);
			$limited      = $this->rate_limit_service->check_and_increment( $per_email_id );
			if ( $limited ) {
				return $limited;
			}
		}

		$occurrence_id = $request->get_param( 'occurrence_id' );
		$email         = $request->get_param( 'email' );

		/**
		 * Filters whether a waitlist /status request is authorized to see the
		 * real on_waitlist/position values.
		 *
		 * Default: false (base plugin returns a uniform empty response so
		 * `/status` cannot be used to enumerate which emails are on a given
		 * waitlist). Extension plugins (e.g., NetterTech Events Pro) can
		 * return true when a valid cancellation token is presented, allowing
		 * legitimate users to look up their own position.
		 *
		 * @since 1.0.2
		 *
		 * @param bool            $authorized    False by default; true to reveal real status.
		 * @param int             $occurrence_id Occurrence ID being queried.
		 * @param string          $email         Email address being queried.
		 * @param WP_REST_Request $request       Full REST request.
		 */
		$authorized = (bool) apply_filters(
			'nettertech_events_waitlist_status_authorized',
			false,
			(int) $occurrence_id,
			(string) $email,
			$request
		);

		if ( $authorized ) {
			$position = $this->service->get_position( $occurrence_id, $email );

			return new WP_REST_Response(
				array(
					'on_waitlist' => null !== $position,
					'position'    => $position,
				),
				200
			);
		}

		// Unauthorized callers receive a uniform empty response that does not
		// reveal whether the email is on the waitlist.
		return new WP_REST_Response(
			array(
				'on_waitlist' => false,
				'position'    => null,
			),
			200
		);
	}

	/**
	 * Handle waitlist leave request.
	 *
	 * @since 2.1.0
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_leave( WP_REST_Request $request ): WP_REST_Response {
		$occurrence_id = $request->get_param( 'occurrence_id' );
		$email         = $request->get_param( 'email' );

		// Rate limiting (two layers: per-IP burst protection + per-email enumeration protection).
		if ( ! $this->rate_limit_service->should_bypass() ) {
			$limited = $this->rate_limit_service->check_and_increment();
			if ( $limited ) {
				return $limited;
			}

			$per_email_id = sprintf( 'waitlist_leave_email:%s:%d', sha1( (string) $email ), (int) $occurrence_id );
			$limited      = $this->rate_limit_service->check_and_increment( $per_email_id );
			if ( $limited ) {
				return $limited;
			}
		}

		$removed = $this->service->leave( $occurrence_id, $email );

		if ( ! $removed ) {
			return new WP_REST_Response(
				array(
					'code'    => 'not_on_waitlist',
					'message' => __( 'No waitlist entry found for this email.', 'nettertech-events' ),
				),
				404
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'You have been removed from the waitlist.', 'nettertech-events' ),
			),
			200
		);
	}

	/**
	 * Permission check for public waitlist endpoints.
	 *
	 * Waitlist join/leave/status are public operations (no auth required).
	 * Explicit method for auditability per project security convention.
	 *
	 * @since 2.1.0
	 *
	 * @return bool Always true (public endpoint).
	 */
	public function public_waitlist_permission_check(): bool {
		return true;
	}

	/**
	 * Get item schema.
	 *
	 * Describes the join response shape, which is the primary structured
	 * response for this controller. Status and leave responses share the
	 * same envelope structure with context-specific fields.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'waitlist_entry',
			'type'       => 'object',
			'properties' => array(
				'success'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the operation succeeded.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'position'    => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Position on the waitlist (1-based). Null if not on waitlist.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'leave_token' => array(
					'type'        => 'string',
					'description' => __( 'Signed token required to remove this email from the waitlist.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'on_waitlist' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the email address is currently on the waitlist (status check only).', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'message'     => array(
					'type'        => 'string',
					'description' => __( 'Human-readable confirmation or status message.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Get join endpoint arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_join_args(): array {
		return array(
			'occurrence_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
			'email'         => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => function ( $value ) {
					return is_email( $value );
				},
			),
			'name'          => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					return is_string( $value ) && '' !== trim( $value );
				},
			),
			'phone'         => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			),
		);
	}

	/**
	 * Get status endpoint arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_status_args(): array {
		return array(
			'occurrence_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
			'email'         => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => function ( $value ) {
					return is_email( $value );
				},
			),
		);
	}

	/**
	 * Get leave endpoint arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_leave_args(): array {
		return array(
			'occurrence_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
			'email'         => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => function ( $value ) {
					return is_email( $value );
				},
			),
			'token'         => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			),
		);
	}

	/**
	 * Create a signed token for later waitlist removal.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @return string Signed token.
	 */
	private function create_leave_token( int $occurrence_id, string $email ): string {
		$timestamp = time();
		$payload   = $this->get_leave_token_payload( $occurrence_id, $email, $timestamp );
		$signature = hash_hmac( 'sha256', $payload, $this->get_leave_token_secret() );

		return $timestamp . ':' . $signature;
	}

	/**
	 * Verify a signed waitlist removal token.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @param string $token         Token in timestamp:signature format.
	 * @return bool True when valid.
	 */
	private function verify_leave_token( int $occurrence_id, string $email, string $token ): bool {
		if ( $occurrence_id <= 0 || '' === $email || '' === $token || ! str_contains( $token, ':' ) ) {
			return false;
		}

		list( $timestamp_raw, $signature ) = explode( ':', $token, 2 );
		if ( ! ctype_digit( $timestamp_raw ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return false;
		}

		$timestamp = (int) $timestamp_raw;
		$age       = time() - $timestamp;
		$ttl       = $this->get_leave_token_ttl();
		if ( $age < 0 || $age > $ttl ) {
			return false;
		}

		$expected = hash_hmac(
			'sha256',
			$this->get_leave_token_payload( $occurrence_id, $email, $timestamp ),
			$this->get_leave_token_secret()
		);

		return hash_equals( $expected, $signature );
	}

	/**
	 * Build the canonical token payload.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @param int    $timestamp     Token creation timestamp.
	 * @return string Payload.
	 */
	private function get_leave_token_payload( int $occurrence_id, string $email, int $timestamp ): string {
		return implode(
			'|',
			array(
				'waitlist-leave',
				(string) $occurrence_id,
				strtolower( sanitize_email( $email ) ),
				(string) $timestamp,
			)
		);
	}

	/**
	 * Get the waitlist leave token TTL.
	 *
	 * @return int TTL in seconds.
	 */
	private function get_leave_token_ttl(): int {
		/**
		 * Filters the base waitlist leave-token lifetime.
		 *
		 * @since 1.0.2
		 *
		 * @param int $ttl Token lifetime in seconds.
		 */
		return max( 300, (int) apply_filters( 'nettertech_events_waitlist_leave_token_ttl', self::LEAVE_TOKEN_TTL ) );
	}

	/**
	 * Get the secret used for waitlist leave tokens.
	 *
	 * @return string Secret key.
	 */
	private function get_leave_token_secret(): string {
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			return (string) AUTH_KEY;
		}

		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			return (string) SECURE_AUTH_KEY;
		}

		$key = get_option( 'nettertech_events_waitlist_leave_token_key' );
		if ( ! is_string( $key ) || '' === $key ) {
			// Normal WordPress installs use AUTH_KEY above. This fallback only
			// supports stripped-down test/dev environments; a parallel first-use
			// race can rotate this option and invalidate an immediately issued
			// token, but it avoids persisting a weak static default.
			$key = wp_generate_password( 64, true, true );
			update_option( 'nettertech_events_waitlist_leave_token_key', $key, false );
		}

		return $key;
	}
}
