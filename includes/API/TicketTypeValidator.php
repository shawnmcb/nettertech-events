<?php
/**
 * Ticket type request validator for REST API operations.
 *
 * Validates request data for ticket type create/update operations
 * using a data-driven approach to reduce cyclomatic complexity.
 *
 * @package NetterTechEvents\API
 */

declare(strict_types=1);

namespace NetterTechEvents\API;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\TicketType;
use WP_Error;
use WP_REST_Request;

/**
 * Validates ticket type data from REST API requests.
 *
 * @since 0.9.3
 */
final class TicketTypeValidator {

	/**
	 * Valid scopes for ticket types.
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
	 * Enum validation configurations.
	 *
	 * Maps field names to their valid values and error messages.
	 *
	 * @var array<string, array{values: array<string>|null, error_code: string, message: string}>
	 */
	private const ENUM_VALIDATIONS = array(
		'scope'         => array(
			'values'     => null, // Will use VALID_SCOPES.
			'error_code' => 'invalid_scope',
			'message'    => 'Invalid scope. Must be one of: %s',
		),
		'capacity_type' => array(
			'values'     => null, // Will use VALID_CAPACITY_TYPES.
			'error_code' => 'invalid_capacity_type',
			'message'    => 'Invalid capacity type. Must be one of: %s',
		),
		'status'        => array(
			'values'     => null, // Will use TicketType::STATUSES.
			'error_code' => 'invalid_status',
			'message'    => 'Invalid status. Must be one of: %s',
		),
	);

	/**
	 * Numeric validation configurations.
	 *
	 * Maps field names to their validation rules.
	 *
	 * @var array<string, array{min: float|int, error_code: string, message: string}>
	 */
	private const NUMERIC_VALIDATIONS = array(
		'price'         => array(
			'min'        => 0,
			'error_code' => 'invalid_price',
			'message'    => 'Price must be a non-negative number.',
		),
		'min_per_order' => array(
			'min'        => 1,
			'error_code' => 'invalid_min_per_order',
			'message'    => 'Minimum per order must be at least 1.',
		),
		'max_per_order' => array(
			'min'        => 1,
			'error_code' => 'invalid_max_per_order',
			'message'    => 'Maximum per order must be at least 1.',
		),
	);

	/**
	 * Validate ticket type data from a request.
	 *
	 * @param WP_REST_Request $request   The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param bool            $is_update Whether this is an update operation.
	 * @return true|WP_Error True if valid, WP_Error if validation fails.
	 */
	public function validate( WP_REST_Request $request, bool $is_update ): bool|WP_Error {
		// For create operations, validate required fields first.
		if ( ! $is_update ) {
			$required_error = $this->validate_required_fields( $request );
			if ( is_wp_error( $required_error ) ) {
				return $required_error;
			}
		}

		// Validate enum fields.
		$enum_error = $this->validate_enum_fields( $request );
		if ( is_wp_error( $enum_error ) ) {
			return $enum_error;
		}

		// Validate numeric fields.
		$numeric_error = $this->validate_numeric_fields( $request );
		if ( is_wp_error( $numeric_error ) ) {
			return $numeric_error;
		}

		return true;
	}

	/**
	 * Validate required fields for create operations.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error True if valid, WP_Error if validation fails.
	 */
	private function validate_required_fields( WP_REST_Request $request ): bool|WP_Error {
		// Name is required.
		$name = $request->get_param( 'name' );
		if ( empty( $name ) ) {
			return new WP_Error(
				'missing_name',
				__( 'Ticket type name is required.', 'nettertech-events' ),
				array( 'status' => 400 )
			);
		}

		// Validate scope-specific parent requirements.
		$scope         = $request->get_param( 'scope' ) ?? 'occurrence';
		$occurrence_id = $request->get_param( 'occurrence_id' );
		$event_id      = $request->get_param( 'event_id' );

		if ( 'occurrence' === $scope && empty( $occurrence_id ) ) {
			return new WP_Error(
				'missing_occurrence_id',
				__( 'occurrence_id is required for occurrence-scoped ticket types.', 'nettertech-events' ),
				array( 'status' => 400 )
			);
		}

		if ( in_array( $scope, array( 'event', 'template' ), true ) && empty( $event_id ) ) {
			return new WP_Error(
				'missing_event_id',
				__( 'event_id is required for event/template-scoped ticket types.', 'nettertech-events' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate enum fields against allowed values.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error True if valid, WP_Error if validation fails.
	 */
	private function validate_enum_fields( WP_REST_Request $request ): bool|WP_Error {
		foreach ( self::ENUM_VALIDATIONS as $field_name => $config ) {
			if ( ! $request->has_param( $field_name ) ) {
				continue;
			}

			$value   = $request->get_param( $field_name );
			$allowed = $this->get_enum_values( $field_name );

			if ( ! in_array( $value, $allowed, true ) ) {
				return new WP_Error(
					$config['error_code'],
					sprintf(
						$this->translate_message( $config['error_code'] ),
						implode( ', ', $allowed )
					),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Validate numeric fields.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error True if valid, WP_Error if validation fails.
	 */
	private function validate_numeric_fields( WP_REST_Request $request ): bool|WP_Error {
		foreach ( self::NUMERIC_VALIDATIONS as $field_name => $config ) {
			if ( ! $request->has_param( $field_name ) ) {
				continue;
			}

			$value = $request->get_param( $field_name );

			if ( ! is_numeric( $value ) ) {
				return new WP_Error(
					$config['error_code'],
					$this->translate_message( $config['error_code'] ),
					array( 'status' => 400 )
				);
			}

			// Cast to appropriate type for comparison.
			$numeric_value = 'price' === $field_name ? (float) $value : (int) $value;

			if ( $numeric_value < $config['min'] ) {
				return new WP_Error(
					$config['error_code'],
					$this->translate_message( $config['error_code'] ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Get the allowed values for an enum field.
	 *
	 * @param string $field_name The field name.
	 * @return array<string> The allowed values.
	 */
	private function get_enum_values( string $field_name ): array {
		return match ( $field_name ) {
			'scope'         => self::VALID_SCOPES,
			'capacity_type' => self::VALID_CAPACITY_TYPES,
			'status'        => TicketType::STATUSES,
			default         => array(),
		};
	}

	/**
	 * Translate a validation error message by error code.
	 *
	 * Required by WordPress.org i18n rules: gettext text parameters must be
	 * literal strings, not variables — the parser cannot extract dynamic text.
	 *
	 * @param string $error_code The error code from the validation config.
	 * @return string Translated message (with sprintf placeholder where applicable).
	 */
	private function translate_message( string $error_code ): string {
		return match ( $error_code ) {
			/* translators: %s: list of valid values */
			'invalid_scope'         => __( 'Invalid scope. Must be one of: %s', 'nettertech-events' ),
			/* translators: %s: list of valid values */
			'invalid_capacity_type' => __( 'Invalid capacity type. Must be one of: %s', 'nettertech-events' ),
			/* translators: %s: list of valid values */
			'invalid_status'        => __( 'Invalid status. Must be one of: %s', 'nettertech-events' ),
			'invalid_price'         => __( 'Price must be a non-negative number.', 'nettertech-events' ),
			'invalid_min_per_order' => __( 'Minimum per order must be at least 1.', 'nettertech-events' ),
			'invalid_max_per_order' => __( 'Maximum per order must be at least 1.', 'nettertech-events' ),
			default                 => '',
		};
	}
}
