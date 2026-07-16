<?php
/**
 * Attendee Fields REST Controller.
 *
 * @package NetterTechEvents\API
 */

declare(strict_types=1);

namespace NetterTechEvents\API;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Services\RateLimitService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST controller for custom attendee field definitions and values.
 *
 * Endpoints:
 * - GET    /events/{event_id}/attendee-fields       (public - check-in app)
 * - POST   /events/{event_id}/attendee-fields       (admin)
 * - PUT    /events/{event_id}/attendee-fields/{id}  (admin)
 * - DELETE /events/{event_id}/attendee-fields/{id}  (admin)
 * - GET    /attendees/{attendee_id}/custom-fields    (admin)
 *
 * @since 3.6.0
 */
class AttendeeFieldsController extends WP_REST_Controller {

	/**
	 * Field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface
	 */
	private AttendeeFieldRepositoryInterface $field_repo;

	/**
	 * Field value repository.
	 *
	 * @var AttendeeFieldValueRepositoryInterface
	 */
	private AttendeeFieldValueRepositoryInterface $value_repo;

	/**
	 * Rate limit service.
	 *
	 * @var RateLimitService
	 */
	private RateLimitService $rate_limit_service;

	/**
	 * Constructor.
	 *
	 * @param AttendeeFieldRepositoryInterface      $field_repo         Field repository.
	 * @param AttendeeFieldValueRepositoryInterface $value_repo         Value repository.
	 * @param RateLimitService                      $rate_limit_service Rate limit service.
	 */
	public function __construct(
		AttendeeFieldRepositoryInterface $field_repo,
		AttendeeFieldValueRepositoryInterface $value_repo,
		RateLimitService $rate_limit_service
	) {
		$this->namespace          = 'nettertech-events/v1';
		$this->rest_base          = 'events';
		$this->field_repo         = $field_repo;
		$this->value_repo         = $value_repo;
		$this->rate_limit_service = $rate_limit_service;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Field definitions per event.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<event_id>[\d]+)/attendee-fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_fields' ),
					'permission_callback' => array( $this, 'public_read_permissions_check' ),
					'args'                => array(
						'event_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_field' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_field_creation_args(),
				),
			)
		);

		// Single field operations.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<event_id>[\d]+)/attendee-fields/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_field' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_field_creation_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_field' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		// Field values per attendee.
		register_rest_route(
			$this->namespace,
			'/attendees/(?P<attendee_id>[\d]+)/custom-fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_values' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'attendee_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Get field definitions for an event.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_fields( WP_REST_Request $request ): WP_REST_Response {
		// Rate limiting for public endpoint.
		if ( ! $this->rate_limit_service->should_bypass() ) {
			$limited = $this->rate_limit_service->check_and_increment();
			if ( $limited ) {
				return $limited;
			}
		}

		$event_id = (int) $request->get_param( 'event_id' );
		$fields   = $this->field_repo->for_event( $event_id );

		$data = array_map(
			fn( AttendeeField $field ) => $this->prepare_field_response( $field ),
			$fields
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Create a field definition.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_field( WP_REST_Request $request ) {
		$event_id = (int) $request->get_param( 'event_id' );

		$field              = new AttendeeField();
		$field->event_id    = $event_id;
		$field->label       = sanitize_text_field( $request->get_param( 'label' ) ?? '' );
		$field->field_type  = sanitize_text_field( $request->get_param( 'field_type' ) ?? 'text' );
		$field->is_required = (bool) $request->get_param( 'is_required' );
		$field->placeholder = sanitize_text_field( $request->get_param( 'placeholder' ) ?? '' );
		$field->description = sanitize_textarea_field( $request->get_param( 'description' ) ?? '' );

		$options = $request->get_param( 'options' );
		if ( is_array( $options ) ) {
			$field->set_options( array_map( 'sanitize_text_field', $options ) );
		}

		$errors = $field->validate();
		if ( ! empty( $errors ) ) {
			return new WP_Error( 'validation_failed', implode( ' ', $errors ), array( 'status' => 400 ) );
		}

		try {
			$field = $this->field_repo->save( $field );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'create_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->prepare_field_response( $field ) );
	}

	/**
	 * Update a field definition.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_field( WP_REST_Request $request ) {
		$field_id = (int) $request->get_param( 'id' );
		$field    = $this->field_repo->find( $field_id );

		if ( ! $field ) {
			return new WP_Error( 'not_found', __( 'Field not found.', 'nettertech-events' ), array( 'status' => 404 ) );
		}

		if ( null !== $request->get_param( 'label' ) ) {
			$field->label = sanitize_text_field( $request->get_param( 'label' ) );
		}
		if ( null !== $request->get_param( 'field_type' ) ) {
			$field->field_type = sanitize_text_field( $request->get_param( 'field_type' ) );
		}
		if ( null !== $request->get_param( 'is_required' ) ) {
			$field->is_required = (bool) $request->get_param( 'is_required' );
		}
		if ( null !== $request->get_param( 'placeholder' ) ) {
			$field->placeholder = sanitize_text_field( $request->get_param( 'placeholder' ) );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$field->description = sanitize_textarea_field( $request->get_param( 'description' ) );
		}

		$options = $request->get_param( 'options' );
		if ( is_array( $options ) ) {
			$field->set_options( array_map( 'sanitize_text_field', $options ) );
		}

		try {
			$field = $this->field_repo->save( $field );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'update_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->prepare_field_response( $field ) );
	}

	/**
	 * Delete a field definition.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_field( WP_REST_Request $request ) {
		$field_id = (int) $request->get_param( 'id' );

		// Delete associated values first.
		$this->value_repo->delete_for_field( $field_id );
		$deleted = $this->field_repo->delete( $field_id );

		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete field.', 'nettertech-events' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Get field values for an attendee.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_values( WP_REST_Request $request ): WP_REST_Response {
		$attendee_id = (int) $request->get_param( 'attendee_id' );
		$values      = $this->value_repo->for_attendee( $attendee_id );

		$data = array();
		foreach ( $values as $value ) {
			$field  = $this->field_repo->find( $value->field_id );
			$data[] = array(
				'field_id'    => $value->field_id,
				'field_key'   => $field ? $field->field_key : '',
				'field_label' => $field ? $field->label : '',
				'field_type'  => $field ? $field->field_type : '',
				'value'       => $value->field_value,
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Public read permission check.
	 *
	 * Field definitions are publicly readable for the check-in app.
	 *
	 * @return bool
	 */
	public function public_read_permissions_check(): bool {
		return true;
	}

	/**
	 * Check admin permissions.
	 *
	 * @return bool|WP_Error
	 */
	public function admin_permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage attendee fields.', 'nettertech-events' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Prepare a field for API response.
	 *
	 * @param AttendeeField $field Field model.
	 * @return array<string, mixed>
	 */
	private function prepare_field_response( AttendeeField $field ): array {
		return array(
			'id'          => $field->id,
			'event_id'    => $field->event_id,
			'field_key'   => $field->field_key,
			'field_type'  => $field->field_type,
			'label'       => $field->label,
			'placeholder' => $field->placeholder,
			'description' => $field->description,
			'options'     => $field->get_options(),
			'is_required' => $field->is_required,
			'sort_order'  => $field->sort_order,
		);
	}

	/**
	 * Get item schema.
	 *
	 * Describes the attendee field definition response shape.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'attendee_field',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'type'        => 'integer',
					'description' => __( 'Unique field identifier.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'event_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Parent event ID.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'field_key'   => array(
					'type'        => 'string',
					'description' => __( 'Machine-readable field key (auto-generated from label).', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'field_type'  => array(
					'type'        => 'string',
					'description' => __( 'Field input type (text, textarea, select, checkbox, radio).', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'label'       => array(
					'type'        => 'string',
					'description' => __( 'Human-readable field label shown to attendees.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'placeholder' => array(
					'type'        => 'string',
					'description' => __( 'Input placeholder text.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Help text displayed below the field.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'options'     => array(
					'type'        => 'array',
					'description' => __( 'Selectable options for select/radio/checkbox fields.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
					'items'       => array( 'type' => 'string' ),
				),
				'is_required' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the field must be completed by the attendee.', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
				'sort_order'  => array(
					'type'        => 'integer',
					'description' => __( 'Display sort order (ascending).', 'nettertech-events' ),
					'context'     => array( 'view', 'edit' ),
				),
			),
		);
	}

	/**
	 * Get field creation argument schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_field_creation_args(): array {
		return array(
			'event_id'    => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'label'       => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'field_type'  => array(
				'type'              => 'string',
				'default'           => 'text',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'is_required' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'placeholder' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'options'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
		);
	}
}
