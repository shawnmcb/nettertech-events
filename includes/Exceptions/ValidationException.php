<?php
/**
 * Validation exception for NetterTech Events plugin.
 *
 * @package NetterTechEvents\Exceptions
 */

declare(strict_types=1);

namespace NetterTechEvents\Exceptions;

defined( 'ABSPATH' ) || exit;

/**
 * Exception thrown when model or input validation fails.
 *
 * @since 0.9.2
 * @api
 */
class ValidationException extends NetterTechEventsException {

	/**
	 * Validation errors.
	 *
	 * @var array<string>
	 */
	protected array $errors = array();

	/**
	 * Create a new validation exception.
	 *
	 * @param array<string>        $errors   Validation error messages.
	 * @param array<string, mixed> $context Additional context data.
	 * @return self
	 */
	public static function fromErrors( array $errors, array $context = array() ): self {
		$message           = implode( ' ', $errors );
		$exception         = new self( $message, 0, null, $context );
		$exception->errors = $errors;

		return $exception;
	}

	/**
	 * Create validation exception for a required field.
	 *
	 * @param string $field Field name.
	 * @return self
	 */
	public static function requiredField( string $field ): self {
		return self::fromErrors(
			array( sprintf( '%s is required.', ucfirst( $field ) ) ),
			array( 'field' => $field )
		);
	}

	/**
	 * Create validation exception for an invalid field value.
	 *
	 * @param string $field   Field name.
	 * @param mixed  $value   Invalid value.
	 * @param string $reason  Reason why it's invalid.
	 * @return self
	 */
	public static function invalidField( string $field, mixed $value, string $reason = '' ): self {
		$message = sprintf( 'Invalid value for %s.', $field );
		if ( $reason ) {
			$message .= ' ' . $reason;
		}

		return self::fromErrors(
			array( $message ),
			array(
				'field' => $field,
				'value' => $value,
			)
		);
	}

	/**
	 * Get validation errors.
	 *
	 * @return array<string>
	 */
	public function getErrors(): array {
		return $this->errors;
	}

	/**
	 * Convert exception to array.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data           = parent::toArray();
		$data['errors'] = $this->errors;

		return $data;
	}
}
