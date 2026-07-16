<?php
/**
 * ICalendar REST API controller.
 *
 * @package NetterTechEvents\API
 */

declare(strict_types=1);

namespace NetterTechEvents\API;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Services\ICalService;
use NetterTechEvents\Services\RateLimitService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API controller for iCal import/export.
 */
class ICalController extends WP_REST_Controller {

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
	protected $rest_base = 'ical';

	/**
	 * ICalendar service.
	 *
	 * @var ICalService
	 */
	private ICalService $ical_service;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

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
	 * @param EventRepositoryInterface      $event_repo         Event repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo    Occurrence repository.
	 * @param ICalService                   $ical_service       iCal service.
	 * @param RateLimitService              $rate_limit_service Rate limit service.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		ICalService $ical_service,
		RateLimitService $rate_limit_service
	) {
		$this->event_repo         = $event_repo;
		$this->occurrence_repo    = $occurrence_repo;
		$this->ical_service       = $ical_service;
		$this->rate_limit_service = $rate_limit_service;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Public calendar feed.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/feed',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_feed' ),
					'permission_callback' => array( $this, 'public_feed_permission_check' ),
					'args'                => $this->get_feed_args(),
				),
			)
		);

		// Export single event (all occurrences).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/event/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_event' ),
					'permission_callback' => array( $this, 'public_feed_permission_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Export single occurrence.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/occurrence/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_occurrence' ),
					'permission_callback' => array( $this, 'public_feed_permission_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Import iCal (admin only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import' ),
					'permission_callback' => array( $this, 'import_permission_check' ),
					'args'                => $this->get_import_args(),
				),
			)
		);
	}

	/**
	 * Get public calendar feed.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_feed( WP_REST_Request $request ): WP_REST_Response {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$args = array(
			'status' => 'published',
			'limit'  => $request->get_param( 'limit' ) ?? 100,
		);

		$start_from = $request->get_param( 'start_from' );
		if ( $start_from ) {
			$args['start_from'] = sanitize_text_field( $start_from );
		}

		$category = $request->get_param( 'category' );
		if ( $category ) {
			$args['category'] = is_array( $category ) ? array_map( 'absint', $category ) : array( absint( $category ) );
		}

		$ical_content = $this->ical_service->export_calendar_feed( $args );

		return $this->create_ical_response( $ical_content, 'events.ics' );
	}

	/**
	 * Get single event as iCal.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_event( WP_REST_Request $request ): WP_REST_Response {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$event_id = (int) $request->get_param( 'id' );

		// Verify event exists and is published.
		$event = $this->event_repo->find( $event_id );
		if ( ! $event ) {
			return new WP_REST_Response(
				array(
					'code'    => 'not_found',
					'message' => __( 'Event not found.', 'nettertech-events' ),
				),
				404
			);
		}

		if ( EventStatus::PUBLISHED !== $event->status ) {
			return new WP_REST_Response(
				array(
					'code'    => 'not_published',
					'message' => __( 'Event is not published.', 'nettertech-events' ),
				),
				403
			);
		}

		$ical_content = $this->ical_service->export_event( $event_id );

		if ( ! $ical_content ) {
			return new WP_REST_Response(
				array(
					'code'    => 'no_occurrences',
					'message' => __( 'No occurrences found for this event.', 'nettertech-events' ),
				),
				404
			);
		}

		$filename = sanitize_file_name( $event->slug . '.ics' );

		return $this->create_ical_response( $ical_content, $filename );
	}

	/**
	 * Get single occurrence as iCal.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_occurrence( WP_REST_Request $request ): WP_REST_Response {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$occurrence_id = (int) $request->get_param( 'id' );

		// Verify occurrence exists.
		$occurrence = $this->occurrence_repo->find( $occurrence_id );
		if ( ! $occurrence ) {
			return new WP_REST_Response(
				array(
					'code'    => 'not_found',
					'message' => __( 'Occurrence not found.', 'nettertech-events' ),
				),
				404
			);
		}

		// Verify event is published.
		$event = $this->event_repo->find( $occurrence->event_id );
		if ( ! $event || EventStatus::PUBLISHED !== $event->status ) {
			return new WP_REST_Response(
				array(
					'code'    => 'not_published',
					'message' => __( 'Event is not published.', 'nettertech-events' ),
				),
				403
			);
		}

		$ical_content = $this->ical_service->export_occurrence( $occurrence_id );

		if ( ! $ical_content ) {
			return new WP_REST_Response(
				array(
					'code'    => 'export_failed',
					'message' => __( 'Failed to export occurrence.', 'nettertech-events' ),
				),
				500
			);
		}

		$filename = sanitize_file_name( $event->slug . '-' . $occurrence_id . '.ics' );

		return $this->create_ical_response( $ical_content, $filename );
	}

	/**
	 * Import events from iCal content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function import( WP_REST_Request $request ): WP_REST_Response {
		$ical_content = $request->get_param( 'content' );
		if ( empty( $ical_content ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'missing_content',
					'message' => __( 'iCal content is required.', 'nettertech-events' ),
				),
				400
			);
		}

		$options = array(
			'status'          => $request->get_param( 'status' ) ?? 'draft',
			'skip_duplicates' => $request->get_param( 'skip_duplicates' ) ?? true,
		);

		$results = $this->ical_service->import_ical( $ical_content, $options );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'imported' => $results['imported'],
				'skipped'  => $results['skipped'],
				'errors'   => $results['errors'],
			),
			200
		);
	}

	/**
	 * Check if user can import events.
	 *
	 * @return bool
	 */
	public function import_permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for public iCal feed endpoints.
	 *
	 * These endpoints are intentionally public to support:
	 * - Calendar subscription feeds (Google Calendar, Apple Calendar, Outlook)
	 * - "Add to Calendar" functionality on event pages
	 * - Third-party calendar integrations
	 *
	 * Security is enforced at the data layer:
	 * - Only published events are returned
	 * - Draft/private events are excluded from feeds
	 * - Event visibility is checked in get_event() and get_occurrence()
	 *
	 * @since 1.0.0
	 * @return true Always returns true for public access.
	 */
	public function public_feed_permission_check(): bool {
		return true;
	}

	/**
	 * Check rate limit for the current request.
	 *
	 * Bypasses rate limiting for authenticated admins.
	 *
	 * @return WP_REST_Response|null Returns 429 response if limited, null otherwise.
	 */
	private function check_rate_limit(): ?WP_REST_Response {
		if ( $this->rate_limit_service->should_bypass() ) {
			return null;
		}

		return $this->rate_limit_service->check_and_increment();
	}

	/**
	 * Create iCal response with proper headers.
	 *
	 * @param string $content  iCal content.
	 * @param string $filename Download filename.
	 * @return WP_REST_Response
	 */
	private function create_ical_response( string $content, string $filename ): WP_REST_Response {
		$response = new WP_REST_Response( $content, 200 );

		$response->header( 'Content-Type', ICalService::get_content_type() );
		$response->header( 'Content-Disposition', ICalService::get_content_disposition( $filename ) );
		$response->header( 'Cache-Control', 'public, max-age=3600' );

		return $this->rate_limit_service->add_headers( $response );
	}

	/**
	 * Get item schema.
	 *
	 * The iCal export endpoints return raw iCal (text/calendar) content, not
	 * JSON objects. This schema describes the import endpoint's JSON response,
	 * which is the only structured JSON this controller produces.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'ical_import_result',
			'type'       => 'object',
			'properties' => array(
				'success'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the import completed without fatal errors.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'imported' => array(
					'type'        => 'integer',
					'description' => __( 'Number of events successfully imported.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'skipped'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of events skipped (e.g. duplicates when skip_duplicates is true).', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'errors'   => array(
					'type'        => 'array',
					'description' => __( 'Array of error messages encountered during import.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
					'items'       => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * Get arguments for feed endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_feed_args(): array {
		return array(
			'limit'      => array(
				'type'              => 'integer',
				'default'           => 100,
				'minimum'           => 1,
				'maximum'           => 500,
				'sanitize_callback' => 'absint',
			),
			'start_from' => array(
				'type'              => 'string',
				'description'       => 'Start date (ISO 8601), defaults to today',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( string $value ): bool {
					return false !== strtotime( $value );
				},
			),
			'category'   => array(
				'type'              => array( 'integer', 'array' ),
				'description'       => 'Filter by category ID(s)',
				'sanitize_callback' => function ( $value ) {
					if ( is_array( $value ) ) {
						return array_map( 'absint', $value );
					}
					return absint( $value );
				},
			),
		);
	}

	/**
	 * Get arguments for import endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_import_args(): array {
		return array(
			'content'         => array(
				'type'              => 'string',
				'required'          => true,
				'description'       => 'iCal file content',
				'sanitize_callback' => static function ( $value ) {
					// RFC 5545-aware sanitizer: strip null bytes and validate UTF-8.
					// Do NOT use sanitize_textarea_field — it strips valid iCal line folding (CRLF+space).
					$value = wp_check_invalid_utf8( (string) $value );
					return preg_replace( '/\x00/', '', $value );
				},
			),
			'status'          => array(
				'type'              => 'string',
				'default'           => 'draft',
				'enum'              => array( 'draft', 'published' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'skip_duplicates' => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);
	}
}
