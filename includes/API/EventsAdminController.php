<?php
/**
 * Events Admin REST API controller.
 *
 * Provides full CRUD operations for events (admin-only).
 *
 * @package NetterTechEvents\API
 */

declare(strict_types=1);

namespace NetterTechEvents\API;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Utilities\DebugLogger;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API controller for event management (admin).
 *
 * All endpoints require manage_options capability.
 *
 * @since 0.9.1
 */
class EventsAdminController extends WP_REST_Controller {

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
	protected $rest_base = 'admin/events';

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Activity log service.
	 *
	 * @var ActivityLogServiceInterface
	 */
	private ActivityLogServiceInterface $activity_log;

	/**
	 * Rate limit service.
	 *
	 * @var RateLimitService
	 */
	private RateLimitService $rate_limit_service;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface    $event_repo    Event repository.
	 * @param ActivityLogServiceInterface $activity_log  Activity log service.
	 * @param RateLimitService            $rate_limit_service Rate limit service.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		ActivityLogServiceInterface $activity_log,
		RateLimitService $rate_limit_service
	) {
		$this->event_repo         = $event_repo;
		$this->activity_log       = $activity_log;
		$this->rate_limit_service = $rate_limit_service;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Collection routes.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_create_args(),
				),
			)
		);

		// Single item routes.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_update_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
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
	}

	/**
	 * Check admin permissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool|WP_Error
	 */
	public function admin_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage events.', 'nettertech-events' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get collection of events.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$page     = (int) ( $request->get_param( 'page' ) ?? 1 );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 20 );
		$status   = $request->get_param( 'status' );
		$search   = $request->get_param( 'search' );
		$orderby  = $request->get_param( 'orderby' ) ?? 'created_at';
		$order    = $request->get_param( 'order' ) ?? 'desc';

		$args = array(
			'page'     => $page,
			'per_page' => $per_page,
			'orderby'  => $orderby,
			'order'    => $order,
		);

		if ( $status ) {
			$args['status'] = $status;
		}

		if ( $search ) {
			$args['search'] = $search;
		}

		$result = $this->event_repo->paginate( $args );

		$data = array(
			'items'       => array_map( array( $this, 'prepare_event_response' ), $result['items'] ),
			'total'       => $result['total'],
			'total_pages' => $result['pages'],
			'page'        => $page,
			'per_page'    => $per_page,
		);

		/** This filter is documented in includes/Core/Hooks.php */
		$data = apply_filters( 'nettertech_events_rest_admin_events_list_response', $data, $request );

		return $this->rate_limit_service->add_headers( new WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Get a single event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$id    = (int) $request->get_param( 'id' );
		$event = $this->event_repo->find( $id );

		if ( ! $event ) {
			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array(
						'code'    => 'not_found',
						'message' => __( 'Event not found.', 'nettertech-events' ),
					),
					404
				)
			);
		}

		$data = $this->prepare_event_response( $event );

		/** This filter is documented in includes/Core/Hooks.php */
		$data = apply_filters( 'nettertech_events_rest_admin_events_get_response', $data, $request );

		return $this->rate_limit_service->add_headers(
			new WP_REST_Response( $data, 200 )
		);
	}

	/**
	 * Create a new event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$event = new Event();
		$this->populate_event_from_request( $event, $request );

		// Generate slug if not provided.
		if ( empty( $event->slug ) && ! empty( $event->title ) ) {
			$event->slug = $this->event_repo->generate_unique_slug( $event->title );
		}

		// Validate.
		$errors = $event->validate();
		if ( ! empty( $errors ) ) {
			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array(
						'code'    => 'validation_error',
						'message' => __( 'Validation failed.', 'nettertech-events' ),
						'errors'  => $errors,
					),
					400
				)
			);
		}

		try {
			$saved = $this->event_repo->save( $event );

			// Log activity.
			if ( null !== $saved->id ) {
				$this->activity_log->log( 'create', 'event', $saved->id, $saved->title );
			}

			return $this->rate_limit_service->add_headers(
				new WP_REST_Response( $this->prepare_event_response( $saved ), 201 )
			);
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'EventsAdminController::create_item' );

			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array(
						'code'    => 'creation_failed',
						'message' => __( 'An internal error occurred. Please try again.', 'nettertech-events' ),
					),
					500
				)
			);
		}
	}

	/**
	 * Update an existing event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$id    = (int) $request->get_param( 'id' );
		$event = $this->event_repo->find( $id );

		if ( ! $event ) {
			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array(
						'code'    => 'not_found',
						'message' => __( 'Event not found.', 'nettertech-events' ),
					),
					404
				)
			);
		}

		$this->populate_event_from_request( $event, $request );

		// Validate.
		$errors = $event->validate();
		if ( ! empty( $errors ) ) {
			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array(
						'code'    => 'validation_error',
						'message' => __( 'Validation failed.', 'nettertech-events' ),
						'errors'  => $errors,
					),
					400
				)
			);
		}

		try {
			$saved = $this->event_repo->save( $event );

			// Log activity.
			if ( null !== $saved->id ) {
				$this->activity_log->log( 'update', 'event', $saved->id, $saved->title );
			}

			return $this->rate_limit_service->add_headers(
				new WP_REST_Response( $this->prepare_event_response( $saved ), 200 )
			);
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'EventsAdminController::update_item' );

			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array(
						'code'    => 'update_failed',
						'message' => __( 'An internal error occurred. Please try again.', 'nettertech-events' ),
					),
					500
				)
			);
		}
	}

	/**
	 * Delete an event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$id    = (int) $request->get_param( 'id' );
		$event = $this->event_repo->find( $id );

		if ( ! $event ) {
			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array(
						'code'    => 'not_found',
						'message' => __( 'Event not found.', 'nettertech-events' ),
					),
					404
				)
			);
		}

		$title = $event->title;

		try {
			$deleted = $this->event_repo->delete( $id );

			if ( ! $deleted ) {
				return $this->rate_limit_service->add_headers(
					new WP_REST_Response(
						array(
							'code'    => 'delete_failed',
							'message' => __( 'Failed to delete event.', 'nettertech-events' ),
						),
						500
					)
				);
			}

			// Log activity.
			$this->activity_log->log( 'delete', 'event', $id, $title );

			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array(
						'deleted' => true,
						'id'      => $id,
					),
					200
				)
			);
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'EventsAdminController::delete_item' );

			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array(
						'code'    => 'delete_failed',
						'message' => __( 'An internal error occurred. Please try again.', 'nettertech-events' ),
					),
					500
				)
			);
		}
	}

	/**
	 * Prepare event for API response.
	 *
	 * @param Event $event Event object.
	 * @return array<string, mixed>
	 */
	private function prepare_event_response( Event $event ): array {
		return array(
			'id'                  => $event->id,
			'title'               => $event->title,
			'slug'                => $event->slug,
			'description'         => $event->description,
			'excerpt'             => $event->excerpt,
			'status'              => $event->status->value,
			'event_type'          => $event->event_type,
			'venue_name'          => $event->venue_name,
			'venue_address'       => $event->venue_address,
			'featured_image_id'   => $event->featured_image_id,
			'featured_image_url'  => $event->get_featured_image_url( 'medium' ),
			'series_id'           => $event->series_id,
			'recurrence_rule'     => $event->recurrence_rule,
			'recurrence_end_date' => $event->recurrence_end_date,
			'layout_config'       => $event->layout_config,
			'custom_fields'       => $event->custom_fields,
			'is_virtual'          => $event->is_virtual,
			'virtual_url'         => $event->virtual_url,
			'attendance_mode'     => $event->get_attendance_mode(),
			'permalink'           => $event->get_permalink(),
			'created_at'          => $event->created_at,
			'updated_at'          => $event->updated_at,
		);
	}

	/**
	 * Populate event from request data.
	 *
	 * @param Event           $event   Event to populate.
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return void
	 */
	private function populate_event_from_request( Event $event, WP_REST_Request $request ): void {
		$fields = array(
			'title',
			'slug',
			'description',
			'excerpt',
			'event_type',
			'venue_name',
			'venue_address',
			'featured_image_id',
			'series_id',
			'recurrence_rule',
			'recurrence_end_date',
			'layout_config',
			'is_virtual',
			'virtual_url',
		);

		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$event->$field = $value;
			}
		}

		// Status requires enum conversion from the string parameter.
		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			$event->status = EventStatus::tryFrom( $status ) ?? EventStatus::DRAFT;
		}
	}

	/**
	 * Check rate limit.
	 *
	 * @return WP_REST_Response|null
	 */
	private function check_rate_limit(): ?WP_REST_Response {
		if ( $this->rate_limit_service->should_bypass() ) {
			return null;
		}

		return $this->rate_limit_service->check_and_increment();
	}

	/**
	 * Get item schema.
	 *
	 * Describes the event object returned by this controller's endpoints.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'event',
			'type'       => 'object',
			'properties' => array(
				'id'                  => array(
					'type'        => 'integer',
					'description' => __( 'Unique event identifier.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'title'               => array(
					'type'        => 'string',
					'description' => __( 'Event title.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'slug'                => array(
					'type'        => 'string',
					'description' => __( 'URL-friendly slug.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'description'         => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Full event description (HTML).', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'excerpt'             => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Short excerpt.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'status'              => array(
					'type'        => 'string',
					'enum'        => Event::STATUSES,
					'description' => __( 'Event status.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'event_type'          => array(
					'type'        => 'string',
					'enum'        => Event::TYPES,
					'description' => __( 'Event type (single, series, recurring).', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'venue_name'          => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Venue name.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'venue_address'       => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Venue address.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'featured_image_id'   => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Featured image attachment ID.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'featured_image_url'  => array(
					'type'        => array( 'string', 'null' ),
					'format'      => 'uri',
					'description' => __( 'Featured image URL (medium size).', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'series_id'           => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Series ID if part of a series.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'recurrence_rule'     => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'RFC 5545 RRULE for recurring events.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'recurrence_end_date' => array(
					'type'        => array( 'string', 'null' ),
					'format'      => 'date',
					'description' => __( 'End date for recurrence (Y-m-d).', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'layout_config'       => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'Layout configuration object.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'custom_fields'       => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'Custom field values.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'is_virtual'          => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this is a virtual (online) event.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'virtual_url'         => array(
					'type'        => array( 'string', 'null' ),
					'format'      => 'uri',
					'description' => __( 'URL for virtual event access.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'attendance_mode'     => array(
					'type'        => 'string',
					'description' => __( 'Schema.org attendance mode (offline, online, mixed).', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'permalink'           => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'Public event URL.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'created_at'          => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Created timestamp.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'updated_at'          => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Updated timestamp.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Get collection parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'status'   => array(
				'type'              => 'string',
				'enum'              => Event::STATUSES,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'type'              => 'string',
				'default'           => 'created_at',
				'enum'              => array( 'id', 'title', 'created_at', 'updated_at', 'status' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'order'    => array(
				'type'              => 'string',
				'default'           => 'desc',
				'enum'              => array( 'asc', 'desc' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get create endpoint arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_create_args(): array {
		return array(
			'title'               => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Event title.', 'nettertech-events' ),
			),
			'slug'                => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
				'description'       => __( 'URL-friendly slug (auto-generated if not provided).', 'nettertech-events' ),
			),
			'description'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
				'description'       => __( 'Full description (HTML allowed).', 'nettertech-events' ),
			),
			'excerpt'             => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'description'       => __( 'Short excerpt.', 'nettertech-events' ),
			),
			'status'              => array(
				'type'        => 'string',
				'default'     => 'draft',
				'enum'        => Event::STATUSES,
				'description' => __( 'Event status.', 'nettertech-events' ),
			),
			'event_type'          => array(
				'type'        => 'string',
				'default'     => 'single',
				'enum'        => Event::TYPES,
				'description' => __( 'Event type.', 'nettertech-events' ),
			),
			'venue_name'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Venue name.', 'nettertech-events' ),
			),
			'venue_address'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'description'       => __( 'Venue address.', 'nettertech-events' ),
			),
			'featured_image_id'   => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'description'       => __( 'Featured image attachment ID.', 'nettertech-events' ),
			),
			'series_id'           => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'description'       => __( 'Series ID if part of a series.', 'nettertech-events' ),
			),
			'recurrence_rule'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'RFC 5545 RRULE for recurring events.', 'nettertech-events' ),
			),
			'recurrence_end_date' => array(
				'type'              => 'string',
				'format'            => 'date',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'End date for recurrence (Y-m-d format).', 'nettertech-events' ),
			),
			'layout_config'       => array(
				'type'              => 'object',
				'description'       => __( 'Layout configuration object.', 'nettertech-events' ),
				'sanitize_callback' => static function ( $value ) {
					$layout_service = new \NetterTechEvents\Services\LayoutService();
					return $layout_service->sanitize_config( is_array( $value ) ? $value : array() );
				},
			),
			'is_virtual'          => array(
				'type'              => 'boolean',
				'description'       => __( 'Whether this is a virtual (online) event.', 'nettertech-events' ),
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'virtual_url'         => array(
				'type'              => 'string',
				'format'            => 'uri',
				'sanitize_callback' => 'esc_url_raw',
				'description'       => __( 'URL for virtual event access.', 'nettertech-events' ),
			),
		);
	}

	/**
	 * Get update endpoint arguments.
	 *
	 * Same as create but with ID and nothing required.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_args(): array {
		$args = $this->get_create_args();

		// Add ID param.
		$args['id'] = array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		);

		// Remove required flag from title for updates.
		unset( $args['title']['required'] );

		return $args;
	}
}
