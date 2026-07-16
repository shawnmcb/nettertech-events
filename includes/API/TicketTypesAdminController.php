<?php
/**
 * TicketTypes Admin REST API controller.
 *
 * Provides full CRUD operations for ticket types (admin-only).
 *
 * @package NetterTechEvents\API
 */

declare(strict_types=1);

namespace NetterTechEvents\API;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\API\TicketTypeFieldHandler;
use NetterTechEvents\API\TicketTypeValidator;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Utilities\DebugLogger;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API controller for ticket type management (admin).
 *
 * All endpoints require edit_posts capability (Editors and above).
 *
 * @since 0.9.2
 */
class TicketTypesAdminController extends WP_REST_Controller {

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
	protected $rest_base = 'admin/ticket-types';

	/**
	 * TicketType repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

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
	 * Field handler for applying request data to models.
	 *
	 * @var TicketTypeFieldHandler
	 */
	private TicketTypeFieldHandler $field_handler;

	/**
	 * Request validator.
	 *
	 * @var TicketTypeValidator
	 */
	private TicketTypeValidator $validator;

	/**
	 * Valid scopes.
	 *
	 * @var array<string>
	 */
	private const VALID_SCOPES = array( 'occurrence', 'event', 'template' );

	/**
	 * Valid capacity types.
	 *
	 * @var array<string>
	 */
	private const VALID_CAPACITY_TYPES = array( 'fixed', 'unlimited', 'shared' );

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 * @param ActivityLogServiceInterface   $activity_log     Activity log service.
	 * @param RateLimitService              $rate_limit_service Rate limit service.
	 * @param TicketTypeFieldHandler|null   $field_handler    Field handler for request data.
	 * @param TicketTypeValidator|null      $validator        Request validator.
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		ActivityLogServiceInterface $activity_log,
		RateLimitService $rate_limit_service,
		?TicketTypeFieldHandler $field_handler = null,
		?TicketTypeValidator $validator = null
	) {
		$this->ticket_type_repo   = $ticket_type_repo;
		$this->activity_log       = $activity_log;
		$this->rate_limit_service = $rate_limit_service;
		$this->field_handler      = $field_handler ?? new TicketTypeFieldHandler();
		$this->validator          = $validator ?? new TicketTypeValidator();
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
							'description' => __( 'Ticket type ID.', 'nettertech-events' ),
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
							'description' => __( 'Ticket type ID.', 'nettertech-events' ),
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
				__( 'You do not have permission to manage ticket types.', 'nettertech-events' ),
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
	 * Get collection of ticket types.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$occurrence_id = $request->get_param( 'occurrence_id' );
		$event_id      = $request->get_param( 'event_id' );
		$status        = $request->get_param( 'status' );
		$scope         = $request->get_param( 'scope' );

		$args = array();
		if ( ! empty( $status ) ) {
			$args['status'] = $status;
		}
		if ( ! empty( $scope ) ) {
			$args['scope'] = $scope;
		}

		$ticket_types = array();

		if ( ! empty( $occurrence_id ) ) {
			$ticket_types = $this->ticket_type_repo->for_occurrence( (int) $occurrence_id, $args );
		} elseif ( ! empty( $event_id ) ) {
			$ticket_types = $this->ticket_type_repo->for_event( (int) $event_id, $args );
		} else {
			return new WP_Error(
				'missing_parent',
				__( 'Either occurrence_id or event_id is required.', 'nettertech-events' ),
				array( 'status' => 400 )
			);
		}

		$data = array();
		foreach ( $ticket_types as $ticket_type ) {
			$data[] = $this->prepare_item_for_response( $ticket_type, $request )->get_data();
		}

		/** This filter is documented in includes/Core/Hooks.php */
		$data = apply_filters( 'nettertech_events_rest_admin_ticket_types_list_response', $data, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Create a ticket type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$validated = $this->validate_ticket_type_data( $request, false );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$ticket_type       = new TicketType();
		$ticket_type->name = sanitize_text_field( $request->get_param( 'name' ) );

		// Handle scope and parent.
		$scope              = $request->get_param( 'scope' ) ?? 'occurrence';
		$ticket_type->scope = $scope;

		if ( 'occurrence' === $scope ) {
			$ticket_type->occurrence_id = (int) $request->get_param( 'occurrence_id' );
		} else {
			$ticket_type->event_id = (int) $request->get_param( 'event_id' );
		}

		// Apply optional fields via field handler.
		$this->field_handler->apply_create_fields( $request, $ticket_type );

		try {
			$ticket_type = $this->ticket_type_repo->save( $ticket_type );
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'TicketTypesAdminController::create_item' );

			return new WP_Error(
				'ticket_type_create_failed',
				__( 'An internal error occurred. Please try again.', 'nettertech-events' ),
				array( 'status' => 500 )
			);
		}

		// Log the creation.
		if ( null !== $ticket_type->id ) {
			$this->activity_log->log(
				'create',
				'ticket_type',
				$ticket_type->id,
				$ticket_type->name,
				array(
					'scope'         => $ticket_type->scope,
					'occurrence_id' => $ticket_type->occurrence_id,
					'event_id'      => $ticket_type->event_id,
					'price'         => $ticket_type->price,
				)
			);
		}

		$response = $this->prepare_item_for_response( $ticket_type, $request );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->rest_base . '/' . $ticket_type->id ) );

		return $response;
	}

	/**
	 * Get single ticket type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$id          = (int) $request->get_param( 'id' );
		$ticket_type = $this->ticket_type_repo->find( $id );

		if ( ! $ticket_type ) {
			return new WP_Error(
				'ticket_type_not_found',
				__( 'Ticket type not found.', 'nettertech-events' ),
				array( 'status' => 404 )
			);
		}

		$response      = $this->prepare_item_for_response( $ticket_type, $request );
		$response_data = $response->get_data();

		/** This filter is documented in includes/Core/Hooks.php */
		$response_data = apply_filters( 'nettertech_events_rest_admin_ticket_types_get_response', $response_data, $request );

		return rest_ensure_response( $response_data );
	}

	/**
	 * Update a ticket type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$id          = (int) $request->get_param( 'id' );
		$ticket_type = $this->ticket_type_repo->find( $id );

		if ( ! $ticket_type ) {
			return new WP_Error(
				'ticket_type_not_found',
				__( 'Ticket type not found.', 'nettertech-events' ),
				array( 'status' => 404 )
			);
		}

		$validated = $this->validate_ticket_type_data( $request, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Apply field updates and track changes via field handler.
		$changes = $this->field_handler->apply_update_fields( $request, $ticket_type );

		if ( empty( $changes ) ) {
			return $this->prepare_item_for_response( $ticket_type, $request );
		}

		try {
			$ticket_type = $this->ticket_type_repo->save( $ticket_type );
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'TicketTypesAdminController::update_item' );

			return new WP_Error(
				'ticket_type_update_failed',
				__( 'An internal error occurred. Please try again.', 'nettertech-events' ),
				array( 'status' => 500 )
			);
		}

		// Log the update.
		if ( null !== $ticket_type->id ) {
			$this->activity_log->log(
				'update',
				'ticket_type',
				$ticket_type->id,
				$ticket_type->name,
				array( 'changes' => $changes )
			);
		}

		return $this->prepare_item_for_response( $ticket_type, $request );
	}

	/**
	 * Delete a ticket type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$id          = (int) $request->get_param( 'id' );
		$ticket_type = $this->ticket_type_repo->find( $id );

		if ( ! $ticket_type ) {
			return new WP_Error(
				'ticket_type_not_found',
				__( 'Ticket type not found.', 'nettertech-events' ),
				array( 'status' => 404 )
			);
		}

		// Check if tickets have been sold.
		if ( $ticket_type->sold_count > 0 ) {
			return new WP_Error(
				'ticket_type_has_sales',
				__( 'Cannot delete ticket type with existing sales. Deactivate it instead.', 'nettertech-events' ),
				array( 'status' => 400 )
			);
		}

		// Store data for logging before deletion.
		$ticket_type_name = $ticket_type->name;
		$ticket_type_data = array(
			'scope'         => $ticket_type->scope,
			'occurrence_id' => $ticket_type->occurrence_id,
			'event_id'      => $ticket_type->event_id,
			'price'         => $ticket_type->price,
		);

		$deleted = $this->ticket_type_repo->delete( $id );

		if ( ! $deleted ) {
			return new WP_Error(
				'ticket_type_delete_failed',
				__( 'Failed to delete ticket type.', 'nettertech-events' ),
				array( 'status' => 500 )
			);
		}

		// Log the deletion.
		$this->activity_log->log(
			'delete',
			'ticket_type',
			$id,
			$ticket_type_name,
			$ticket_type_data
		);

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Validate ticket type data from request.
	 *
	 * Delegates to TicketTypeValidator for data-driven validation.
	 *
	 * @param WP_REST_Request $request   Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param bool            $is_update Whether this is an update (fields optional).
	 * @return true|WP_Error
	 */
	private function validate_ticket_type_data( WP_REST_Request $request, bool $is_update ): bool|WP_Error {
		return $this->validator->validate( $request, $is_update );
	}

	/**
	 * Prepare ticket type for response.
	 *
	 * @param TicketType      $ticket_type Ticket type object.
	 * @param WP_REST_Request $request     Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $ticket_type, $request ): WP_REST_Response {
		$data = array(
			'id'              => $ticket_type->id,
			'occurrence_id'   => $ticket_type->occurrence_id,
			'event_id'        => $ticket_type->event_id,
			'scope'           => $ticket_type->scope,
			'template_id'     => $ticket_type->template_id,
			'name'            => $ticket_type->name,
			'description'     => $ticket_type->description,
			'price'           => $ticket_type->price,
			'capacity_type'   => $ticket_type->capacity_type,
			'capacity'        => $ticket_type->capacity,
			'sold_count'      => $ticket_type->sold_count,
			'stock_status'    => $ticket_type->stock_status,
			'sale_start'      => $ticket_type->sale_start,
			'sale_end'        => $ticket_type->sale_end,
			'min_per_order'   => $ticket_type->min_per_order,
			'max_per_order'   => $ticket_type->max_per_order,
			'sort_order'      => $ticket_type->sort_order,
			'status'          => $ticket_type->status,
			'wc_product_id'   => $ticket_type->wc_product_id,
			'wc_variation_id' => $ticket_type->wc_variation_id,
			'created_at'      => $ticket_type->created_at,
			'updated_at'      => $ticket_type->updated_at,
			'_links'          => array(
				'self' => array(
					array( 'href' => rest_url( $this->namespace . '/' . $this->rest_base . '/' . $ticket_type->id ) ),
				),
			),
		);

		// Add collection link based on parent.
		if ( $ticket_type->occurrence_id ) {
			$data['_links']['collection'] = array(
				array( 'href' => rest_url( $this->namespace . '/' . $this->rest_base . '?occurrence_id=' . $ticket_type->occurrence_id ) ),
			);
		} elseif ( $ticket_type->event_id ) {
			$data['_links']['collection'] = array(
				array( 'href' => rest_url( $this->namespace . '/' . $this->rest_base . '?event_id=' . $ticket_type->event_id ) ),
			);
		}

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
				'description'       => __( 'Filter by occurrence ID.', 'nettertech-events' ),
				'sanitize_callback' => 'absint',
			),
			'event_id'      => array(
				'type'              => 'integer',
				'description'       => __( 'Filter by event ID.', 'nettertech-events' ),
				'sanitize_callback' => 'absint',
			),
			'scope'         => array(
				'type'              => 'string',
				'default'           => '',
				'enum'              => array_merge( array( '' ), self::VALID_SCOPES ),
				'description'       => __( 'Filter by scope.', 'nettertech-events' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'        => array(
				'type'              => 'string',
				'default'           => '',
				'enum'              => array_merge( array( '' ), TicketType::STATUSES ),
				'description'       => __( 'Filter by status.', 'nettertech-events' ),
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
			'title'      => 'ticket_type',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'type'        => 'integer',
					'description' => __( 'Unique identifier.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'occurrence_id'   => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Parent occurrence ID.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'event_id'        => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Parent event ID.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'scope'           => array(
					'type'        => 'string',
					'enum'        => self::VALID_SCOPES,
					'default'     => 'occurrence',
					'description' => __( 'Ticket type scope.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'template_id'     => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Parent template ID.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'name'            => array(
					'type'        => 'string',
					'description' => __( 'Ticket type name.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'description'     => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Description.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'price'           => array(
					'type'        => 'number',
					'minimum'     => 0,
					'default'     => 0,
					'description' => __( 'Ticket price.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'capacity_type'   => array(
					'type'        => 'string',
					'enum'        => self::VALID_CAPACITY_TYPES,
					'default'     => 'fixed',
					'description' => __( 'Capacity type.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'capacity'        => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Capacity limit.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'sold_count'      => array(
					'type'        => 'integer',
					'default'     => 0,
					'description' => __( 'Number of tickets sold.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'stock_status'    => array(
					'type'        => 'string',
					'default'     => 'in_stock',
					'description' => __( 'Stock status.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'sale_start'      => array(
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'description' => __( 'Sale start datetime.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'sale_end'        => array(
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'description' => __( 'Sale end datetime.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'min_per_order'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Minimum tickets per order.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'max_per_order'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 10,
					'description' => __( 'Maximum tickets per order.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'sort_order'      => array(
					'type'        => 'integer',
					'default'     => 0,
					'description' => __( 'Display sort order.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'status'          => array(
					'type'        => 'string',
					'enum'        => TicketType::STATUSES,
					'default'     => 'active',
					'description' => __( 'Ticket type status.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'wc_product_id'   => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'WooCommerce product ID.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'wc_variation_id' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'WooCommerce variation ID.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'created_at'      => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Created timestamp.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'updated_at'      => array(
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
