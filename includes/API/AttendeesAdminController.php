<?php
/**
 * Attendees Admin REST API controller.
 *
 * Provides full CRUD operations for attendees (admin-only).
 *
 * @package NetterTechEvents\API
 */

declare(strict_types=1);

namespace NetterTechEvents\API;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeSearchInterface;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Utilities\DebugLogger;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API controller for attendee management (admin).
 *
 * All endpoints require edit_posts capability (Editors and above).
 *
 * @since 0.9.2
 */
class AttendeesAdminController extends WP_REST_Controller {

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
	protected $rest_base = 'admin/attendees';

	/**
	 * Attendee repository for core CRUD operations.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Attendee search operations.
	 *
	 * @var AttendeeSearchInterface
	 */
	private AttendeeSearchInterface $attendee_search;

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
	 * Capacity service for manual-add validation (null in minimal wiring).
	 *
	 * @var \NetterTechEvents\Contracts\CapacityServiceInterface|null
	 */
	private ?\NetterTechEvents\Contracts\CapacityServiceInterface $capacity_service = null;

	/**
	 * Constructor.
	 *
	 * @param AttendeeRepositoryInterface                               $attendee_repo      Attendee repository.
	 * @param ActivityLogServiceInterface                               $activity_log       Activity log service.
	 * @param RateLimitService                                          $rate_limit_service Rate limit service.
	 * @param \NetterTechEvents\Contracts\CapacityServiceInterface|null $capacity_service   Capacity gate for manual adds (NTE-143 C5).
	 */
	public function __construct(
		AttendeeRepositoryInterface $attendee_repo,
		ActivityLogServiceInterface $activity_log,
		RateLimitService $rate_limit_service,
		?\NetterTechEvents\Contracts\CapacityServiceInterface $capacity_service = null
	) {
		$this->capacity_service = $capacity_service;
		// AttendeeRepository implements both AttendeeRepositoryInterface and AttendeeSearchInterface.
		$this->attendee_repo = $attendee_repo;
		// @phpstan-ignore-next-line -- AttendeeRepository implements AttendeeSearchInterface; PHPStan cannot infer dual-interface from constructor param type.
		$this->attendee_search = $attendee_repo;

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
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
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
							'type'        => 'integer',
							'required'    => true,
							'description' => __( 'Attendee ID.', 'nettertech-events' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'        => 'integer',
							'required'    => true,
							'description' => __( 'Attendee ID.', 'nettertech-events' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Check if user has admin permissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool|WP_Error
	 */
	public function admin_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage attendees.', 'nettertech-events' ),
				array( 'status' => 403 )
			);
		}

		// Check rate limit.
		$rate_limited = $this->rate_limit_service->check_and_increment();
		if ( null !== $rate_limited ) {
			$response_data = $rate_limited->get_data();
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Rate limit exceeded. Please try again later.', 'nettertech-events' ),
				array(
					'status'      => 429,
					'retry_after' => $response_data['data']['retry_after'] ?? 60,
				)
			);
		}

		return true;
	}

	/**
	 * Get collection of attendees.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$occurrence_id = $request->get_param( 'occurrence_id' );
		$status        = $request->get_param( 'status' );
		$search        = $request->get_param( 'search' );

		// Require occurrence_id for listing.
		if ( empty( $occurrence_id ) ) {
			return new WP_Error(
				'missing_occurrence_id',
				__( 'occurrence_id is required to list attendees.', 'nettertech-events' ),
				array( 'status' => 400 )
			);
		}

		$args = array();
		if ( ! empty( $status ) ) {
			$args['status'] = $status;
		}

		if ( ! empty( $search ) ) {
			$attendees = $this->attendee_search->search( (int) $occurrence_id, $search );
		} else {
			$attendees = $this->attendee_repo->for_occurrence( (int) $occurrence_id, $args );
		}

		$data = array();
		foreach ( $attendees as $attendee ) {
			$data[] = $this->prepare_item_for_response( $attendee, $request )->get_data();
		}

		/** This filter is documented in includes/Core/Hooks.php */
		$data = apply_filters( 'nettertech_events_rest_admin_attendees_list_response', $data, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Create an attendee.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$validated = $this->validate_attendee_data( $request, false );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$attendee                = new Attendee();
		$attendee->occurrence_id = (int) $request->get_param( 'occurrence_id' );
		$attendee->name          = sanitize_text_field( $request->get_param( 'name' ) );
		$attendee->email         = sanitize_email( $request->get_param( 'email' ) );

		if ( $request->has_param( 'phone' ) ) {
			$attendee->phone = sanitize_text_field( $request->get_param( 'phone' ) );
		}

		if ( $request->has_param( 'quantity' ) ) {
			$attendee->quantity = max( 1, (int) $request->get_param( 'quantity' ) );
		}

		if ( $request->has_param( 'status' ) ) {
			$status = sanitize_text_field( $request->get_param( 'status' ) );
			if ( in_array( $status, Attendee::STATUSES, true ) ) {
				$attendee->status = $status;
			}
		}

		if ( $request->has_param( 'ticket_type_id' ) ) {
			$attendee->ticket_type_id = (int) $request->get_param( 'ticket_type_id' );
		}

		if ( $request->has_param( 'notes' ) ) {
			$attendee->notes = sanitize_textarea_field( $request->get_param( 'notes' ) );
		}

		if ( $request->has_param( 'accessibility_notes' ) ) {
			$attendee->accessibility_notes = sanitize_textarea_field( $request->get_param( 'accessibility_notes' ) );
		}

		try {
			$attendee = $this->attendee_repo->save( $attendee );
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'AttendeesAdminController::create_item' );

			return new WP_Error(
				'attendee_create_failed',
				__( 'An internal error occurred. Please try again.', 'nettertech-events' ),
				array( 'status' => 500 )
			);
		}

		// Log the creation.
		if ( null !== $attendee->id ) {
			$this->activity_log->log(
				'create',
				'attendee',
				$attendee->id,
				$attendee->name,
				array(
					'occurrence_id' => $attendee->occurrence_id,
					'email'         => $attendee->email,
					'quantity'      => $attendee->quantity,
				)
			);
		}

		$response = $this->prepare_item_for_response( $attendee, $request );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->rest_base . '/' . $attendee->id ) );

		return $response;
	}

	/**
	 * Get single attendee.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$id       = (int) $request->get_param( 'id' );
		$attendee = $this->attendee_repo->find( $id );

		if ( ! $attendee ) {
			return new WP_Error(
				'attendee_not_found',
				__( 'Attendee not found.', 'nettertech-events' ),
				array( 'status' => 404 )
			);
		}

		$response      = $this->prepare_item_for_response( $attendee, $request );
		$response_data = $response->get_data();

		/** This filter is documented in includes/Core/Hooks.php */
		$response_data = apply_filters( 'nettertech_events_rest_admin_attendees_get_response', $response_data, $request );

		return rest_ensure_response( $response_data );
	}

	/**
	 * Update an attendee.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$id       = (int) $request->get_param( 'id' );
		$attendee = $this->attendee_repo->find( $id );

		if ( ! $attendee ) {
			return new WP_Error(
				'attendee_not_found',
				__( 'Attendee not found.', 'nettertech-events' ),
				array( 'status' => 404 )
			);
		}

		$validated = $this->validate_attendee_data( $request, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$changes = array();

		if ( $request->has_param( 'name' ) ) {
			$new_name = sanitize_text_field( $request->get_param( 'name' ) );
			if ( $new_name !== $attendee->name ) {
				$changes['name'] = array(
					'old' => $attendee->name,
					'new' => $new_name,
				);
				$attendee->name  = $new_name;
			}
		}

		if ( $request->has_param( 'email' ) ) {
			$new_email = sanitize_email( $request->get_param( 'email' ) );
			if ( $new_email !== $attendee->email ) {
				$changes['email'] = array(
					'old' => $attendee->email,
					'new' => $new_email,
				);
				$attendee->email  = $new_email;
			}
		}

		if ( $request->has_param( 'phone' ) ) {
			$new_phone = sanitize_text_field( $request->get_param( 'phone' ) );
			if ( $new_phone !== $attendee->phone ) {
				$changes['phone'] = array(
					'old' => $attendee->phone,
					'new' => $new_phone,
				);
				$attendee->phone  = $new_phone;
			}
		}

		if ( $request->has_param( 'quantity' ) ) {
			$new_quantity = max( 1, (int) $request->get_param( 'quantity' ) );
			if ( $new_quantity !== $attendee->quantity ) {
				$changes['quantity'] = array(
					'old' => $attendee->quantity,
					'new' => $new_quantity,
				);
				$attendee->quantity  = $new_quantity;
			}
		}

		if ( $request->has_param( 'status' ) ) {
			$new_status = sanitize_text_field( $request->get_param( 'status' ) );
			if ( in_array( $new_status, Attendee::STATUSES, true ) && $new_status !== $attendee->status ) {
				$changes['status'] = array(
					'old' => $attendee->status,
					'new' => $new_status,
				);
				$attendee->status  = $new_status;
			}
		}

		if ( $request->has_param( 'notes' ) ) {
			$new_notes = sanitize_textarea_field( $request->get_param( 'notes' ) );
			if ( $new_notes !== $attendee->notes ) {
				$changes['notes'] = array(
					'old' => $attendee->notes,
					'new' => $new_notes,
				);
				$attendee->notes  = $new_notes;
			}
		}

		if ( $request->has_param( 'accessibility_notes' ) ) {
			$new_access_notes = sanitize_textarea_field( $request->get_param( 'accessibility_notes' ) );
			if ( $new_access_notes !== $attendee->accessibility_notes ) {
				$changes['accessibility_notes'] = array(
					'old' => $attendee->accessibility_notes,
					'new' => $new_access_notes,
				);
				$attendee->accessibility_notes  = $new_access_notes;
			}
		}

		if ( empty( $changes ) ) {
			return $this->prepare_item_for_response( $attendee, $request );
		}

		try {
			$attendee = $this->attendee_repo->save( $attendee );
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'AttendeesAdminController::update_item' );

			return new WP_Error(
				'attendee_update_failed',
				__( 'An internal error occurred. Please try again.', 'nettertech-events' ),
				array( 'status' => 500 )
			);
		}

		// Log the update.
		if ( null !== $attendee->id ) {
			$this->activity_log->log(
				'update',
				'attendee',
				$attendee->id,
				$attendee->name,
				array( 'changes' => $changes )
			);
		}

		return $this->prepare_item_for_response( $attendee, $request );
	}

	/**
	 * Delete an attendee.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$id       = (int) $request->get_param( 'id' );
		$attendee = $this->attendee_repo->find( $id );

		if ( ! $attendee ) {
			return new WP_Error(
				'attendee_not_found',
				__( 'Attendee not found.', 'nettertech-events' ),
				array( 'status' => 404 )
			);
		}

		// Store data for logging before deletion.
		$attendee_name = $attendee->name;
		$attendee_data = array(
			'occurrence_id' => $attendee->occurrence_id,
			'email'         => $attendee->email,
			'quantity'      => $attendee->quantity,
		);

		$deleted = $this->attendee_repo->delete( $id );

		if ( ! $deleted ) {
			return new WP_Error(
				'attendee_delete_failed',
				__( 'Failed to delete attendee.', 'nettertech-events' ),
				array( 'status' => 500 )
			);
		}

		// Log the deletion.
		$this->activity_log->log(
			'delete',
			'attendee',
			$id,
			$attendee_name,
			$attendee_data
		);

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Validate attendee data from request.
	 *
	 * @param WP_REST_Request $request   Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param bool            $is_update Whether this is an update (fields optional).
	 * @return true|WP_Error
	 */
	private function validate_attendee_data( WP_REST_Request $request, bool $is_update ): bool|WP_Error {
		// For create, occurrence_id is required.
		if ( ! $is_update ) {
			$occurrence_id = $request->get_param( 'occurrence_id' );
			if ( empty( $occurrence_id ) ) {
				return new WP_Error(
					'missing_occurrence_id',
					__( 'occurrence_id is required.', 'nettertech-events' ),
					array( 'status' => 400 )
				);
			}

			$name = $request->get_param( 'name' );
			if ( empty( $name ) ) {
				return new WP_Error(
					'missing_name',
					__( 'Attendee name is required.', 'nettertech-events' ),
					array( 'status' => 400 )
				);
			}

			$email = $request->get_param( 'email' );
			if ( empty( $email ) || ! is_email( $email ) ) {
				return new WP_Error(
					'invalid_email',
					__( 'Valid email address is required.', 'nettertech-events' ),
					array( 'status' => 400 )
				);
			}

			// A manual add is still a seat: it must clear the same capacity gate a
			// purchase does, or the door count exceeds the room (NTE-143 C5).
			$ticket_type_id = (int) ( $request->get_param( 'ticket_type_id' ) ?? 0 );
			$quantity       = max( 1, (int) ( $request->get_param( 'quantity' ) ?? 1 ) );
			if ( $ticket_type_id > 0 && null !== $this->capacity_service
				&& ! $this->capacity_service->has_availability( $ticket_type_id, $quantity ) ) {
				return new WP_Error(
					'insufficient_capacity',
					__( 'Not enough capacity remains for that ticket type.', 'nettertech-events' ),
					array( 'status' => 409 )
				);
			}
		}

		// Validate status if provided.
		if ( $request->has_param( 'status' ) ) {
			$status = $request->get_param( 'status' );
			if ( ! in_array( $status, Attendee::STATUSES, true ) ) {
				return new WP_Error(
					'invalid_status',
					sprintf(
						/* translators: %s: list of valid statuses */
						__( 'Invalid status. Must be one of: %s', 'nettertech-events' ),
						implode( ', ', Attendee::STATUSES )
					),
					array( 'status' => 400 )
				);
			}
		}

		// Validate quantity if provided.
		if ( $request->has_param( 'quantity' ) ) {
			$quantity = $request->get_param( 'quantity' );
			if ( ! is_numeric( $quantity ) || (int) $quantity < 1 ) {
				return new WP_Error(
					'invalid_quantity',
					__( 'Quantity must be at least 1.', 'nettertech-events' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Prepare attendee for response.
	 *
	 * @param Attendee        $attendee Attendee object.
	 * @param WP_REST_Request $request  Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $attendee, $request ): WP_REST_Response {
		$data = array(
			'id'                  => $attendee->id,
			'occurrence_id'       => $attendee->occurrence_id,
			'ticket_type_id'      => $attendee->ticket_type_id,
			'wc_order_id'         => $attendee->wc_order_id,
			'name'                => $attendee->name,
			'email'               => $attendee->email,
			'phone'               => $attendee->phone,
			'quantity'            => $attendee->quantity,
			'status'              => $attendee->status,
			'checked_in'          => $attendee->checked_in,
			'checked_in_count'    => $attendee->checked_in_count,
			'checked_in_at'       => $attendee->checked_in_at,
			'notes'               => $attendee->notes,
			'accessibility_notes' => $attendee->accessibility_notes,
			'created_at'          => $attendee->created_at,
			'updated_at'          => $attendee->updated_at,
			'_links'              => array(
				'self'       => array(
					array( 'href' => rest_url( $this->namespace . '/' . $this->rest_base . '/' . $attendee->id ) ),
				),
				'collection' => array(
					array( 'href' => rest_url( $this->namespace . '/' . $this->rest_base . '?occurrence_id=' . $attendee->occurrence_id ) ),
				),
			),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Get collection parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		return array(
			'occurrence_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'description'       => __( 'Filter attendees by occurrence ID.', 'nettertech-events' ),
				'sanitize_callback' => 'absint',
			),
			'status'        => array(
				'type'              => 'string',
				'default'           => '',
				'enum'              => array_merge( array( '' ), Attendee::STATUSES ),
				'description'       => __( 'Filter by status.', 'nettertech-events' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'        => array(
				'type'              => 'string',
				'default'           => '',
				'description'       => __( 'Search term (name or email).', 'nettertech-events' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get item schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'attendee',
			'type'       => 'object',
			'properties' => array(
				'id'                  => array(
					'type'        => 'integer',
					'description' => __( 'Unique identifier.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'occurrence_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Parent occurrence ID.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'ticket_type_id'      => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Ticket type ID.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'wc_order_id'         => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'WooCommerce order ID.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'name'                => array(
					'type'        => 'string',
					'description' => __( 'Attendee name.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'email'               => array(
					'type'        => 'string',
					'format'      => 'email',
					'description' => __( 'Email address.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'phone'               => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Phone number.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'quantity'            => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Party size.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'status'              => array(
					'type'        => 'string',
					'enum'        => Attendee::STATUSES,
					'default'     => 'confirmed',
					'description' => __( 'Registration status.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'checked_in'          => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Whether attendee is checked in.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'checked_in_count'    => array(
					'type'        => 'integer',
					'default'     => 0,
					'description' => __( 'Number of guests checked in.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'checked_in_at'       => array(
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'description' => __( 'Check-in timestamp.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'notes'               => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Notes about attendee.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'accessibility_notes' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Accessibility notes.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
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
}
