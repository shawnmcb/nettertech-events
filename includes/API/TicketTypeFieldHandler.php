<?php
/**
 * Ticket type field handler for REST API operations.
 *
 * Handles field application from REST requests to TicketType models
 * using a data-driven approach to reduce cyclomatic complexity.
 *
 * @package NetterTechEvents\API
 */

declare(strict_types=1);

namespace NetterTechEvents\API;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\TicketType;
use WP_REST_Request;

/**
 * Handles field mapping and change tracking for ticket type REST operations.
 *
 * @since 0.9.3
 */
final class TicketTypeFieldHandler {

	/**
	 * Field configurations for ticket type properties.
	 *
	 * Each field defines:
	 * - sanitizer: The sanitization function name
	 * - type: The cast type (string, int, float, nullable_int)
	 * - validator: Optional array validator (enum values to check against)
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const FIELD_CONFIGS = array(
		'name'          => array(
			'sanitizer' => 'sanitize_text_field',
			'type'      => 'string',
		),
		'description'   => array(
			'sanitizer' => 'sanitize_textarea_field',
			'type'      => 'string',
		),
		'price'         => array(
			'sanitizer' => null,
			'type'      => 'float',
		),
		'capacity_type' => array(
			'sanitizer' => 'sanitize_text_field',
			'type'      => 'string',
			'validator' => array( 'fixed', 'unlimited', 'shared' ),
		),
		'capacity'      => array(
			'sanitizer' => null,
			'type'      => 'nullable_int',
		),
		'sale_start'    => array(
			'sanitizer' => 'sanitize_text_field',
			'type'      => 'string',
		),
		'sale_end'      => array(
			'sanitizer' => 'sanitize_text_field',
			'type'      => 'string',
		),
		'min_per_order' => array(
			'sanitizer' => null,
			'type'      => 'positive_int',
		),
		'max_per_order' => array(
			'sanitizer' => null,
			'type'      => 'positive_int',
		),
		'sort_order'    => array(
			'sanitizer' => null,
			'type'      => 'int',
		),
		'status'        => array(
			'sanitizer' => 'sanitize_text_field',
			'type'      => 'string',
			'validator' => null, // Uses TicketType::STATUSES dynamically.
		),
	);

	/**
	 * Apply fields from request to a new ticket type (create operation).
	 *
	 * @param WP_REST_Request $request     The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param TicketType      $ticket_type The ticket type to populate.
	 * @return void
	 */
	public function apply_create_fields( WP_REST_Request $request, TicketType $ticket_type ): void {
		foreach ( self::FIELD_CONFIGS as $field_name => $config ) {
			if ( ! $request->has_param( $field_name ) ) {
				continue;
			}

			$value = $this->sanitize_value( $request->get_param( $field_name ), $config );

			// Skip if validator exists and value doesn't pass.
			if ( ! $this->passes_validation( $value, $field_name, $config ) ) {
				continue;
			}

			$ticket_type->$field_name = $value;
		}
	}

	/**
	 * Apply fields from request to an existing ticket type with change tracking (update operation).
	 *
	 * @param WP_REST_Request $request     The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param TicketType      $ticket_type The ticket type to update.
	 * @return array<string, array{old: mixed, new: mixed}> Array of changes made.
	 */
	public function apply_update_fields( WP_REST_Request $request, TicketType $ticket_type ): array {
		$changes = array();

		foreach ( self::FIELD_CONFIGS as $field_name => $config ) {
			if ( ! $request->has_param( $field_name ) ) {
				continue;
			}

			$new_value = $this->sanitize_value( $request->get_param( $field_name ), $config );

			// Skip if validator exists and value doesn't pass.
			if ( ! $this->passes_validation( $new_value, $field_name, $config ) ) {
				continue;
			}

			$old_value = $ticket_type->$field_name;

			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Intentional loose comparison for type coercion between mixed types.
			if ( $new_value != $old_value ) {
				$changes[ $field_name ]   = array(
					'old' => $old_value,
					'new' => $new_value,
				);
				$ticket_type->$field_name = $new_value;
			}
		}

		return $changes;
	}

	/**
	 * Sanitize a value based on field configuration.
	 *
	 * @param mixed                $value  The raw value from request.
	 * @param array<string, mixed> $config The field configuration.
	 * @return mixed The sanitized value.
	 */
	private function sanitize_value( mixed $value, array $config ): mixed {
		// Apply sanitizer function if defined.
		if ( ! empty( $config['sanitizer'] ) && is_callable( $config['sanitizer'] ) ) {
			$value = call_user_func( $config['sanitizer'], $value );
		}

		// Apply type casting.
		return match ( $config['type'] ) {
			'int'          => (int) $value,
			'float'        => (float) $value,
			'positive_int' => max( 1, (int) $value ),
			'nullable_int' => null === $value ? null : (int) $value,
			default        => $value,
		};
	}

	/**
	 * Check if a value passes validation for the field.
	 *
	 * @param mixed                $value      The sanitized value.
	 * @param string               $field_name The field name.
	 * @param array<string, mixed> $config     The field configuration.
	 * @return bool True if validation passes.
	 */
	private function passes_validation( mixed $value, string $field_name, array $config ): bool {
		// Get validator values.
		$validator = $config['validator'] ?? null;

		// Special case: status uses TicketType::STATUSES.
		if ( 'status' === $field_name ) {
			$validator = TicketType::STATUSES;
		}

		// No validator means always passes.
		if ( null === $validator ) {
			return true;
		}

		return in_array( $value, $validator, true );
	}
}
