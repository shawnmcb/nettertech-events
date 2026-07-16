<?php
/**
 * RRULE exception for NetterTech Events plugin.
 *
 * @package NetterTechEvents\Exceptions
 */

declare(strict_types=1);

namespace NetterTechEvents\Exceptions;

defined( 'ABSPATH' ) || exit;

/**
 * Exception thrown when RRULE parsing or validation fails.
 *
 * @since 0.9.2
 * @api
 */
class RRuleException extends NetterTechEventsException {

	/**
	 * The problematic RRULE component.
	 *
	 * @var string
	 */
	protected string $component = '';

	/**
	 * The RRULE string that failed.
	 *
	 * @var string
	 */
	protected string $rrule = '';

	/**
	 * Create exception for empty RRULE.
	 *
	 * @return self
	 */
	public static function emptyRule(): self {
		return new self( 'RRULE string cannot be empty.' );
	}

	/**
	 * Create exception for missing required component.
	 *
	 * @param string $component Component name (e.g., 'FREQ').
	 * @param string $rrule     The RRULE string.
	 * @return self
	 */
	public static function missingComponent( string $component, string $rrule = '' ): self {
		$message = sprintf( 'RRULE must have a %s component.', $component );

		$exception            = new self( $message, 0, null, array( 'rrule' => $rrule ) );
		$exception->component = $component;
		$exception->rrule     = $rrule;

		return $exception;
	}

	/**
	 * Create exception for invalid component value.
	 *
	 * @param string        $component     Component name.
	 * @param mixed         $value         Invalid value.
	 * @param array<string> $allowed Allowed values (if applicable).
	 * @param string        $rrule         The RRULE string.
	 * @return self
	 */
	public static function invalidComponent( string $component, mixed $value, array $allowed = array(), string $rrule = '' ): self {
		$message = sprintf( 'Invalid %s value: %s.', $component, is_scalar( $value ) ? (string) $value : gettype( $value ) );
		if ( ! empty( $allowed ) ) {
			$message .= sprintf( ' Allowed values: %s.', implode( ', ', $allowed ) );
		}

		$context = array(
			'rrule'   => $rrule,
			'value'   => $value,
			'allowed' => $allowed,
		);

		$exception            = new self( $message, 0, null, $context );
		$exception->component = $component;
		$exception->rrule     = $rrule;

		return $exception;
	}

	/**
	 * Create exception for invalid BYDAY format.
	 *
	 * @param string $byday The invalid BYDAY value.
	 * @param string $rrule The RRULE string.
	 * @return self
	 */
	public static function invalidByday( string $byday, string $rrule = '' ): self {
		$message = sprintf(
			'Invalid BYDAY format: %s. Expected format like MO, TU, or 2MO (second Monday).',
			$byday
		);

		$exception            = new self(
			$message,
			0,
			null,
			array(
				'rrule' => $rrule,
				'byday' => $byday,
			)
		);
		$exception->component = 'BYDAY';
		$exception->rrule     = $rrule;

		return $exception;
	}

	/**
	 * Create exception for general parsing failure.
	 *
	 * @param string $rrule  The RRULE string.
	 * @param string $reason Reason for failure.
	 * @return self
	 */
	public static function parseFailed( string $rrule, string $reason = '' ): self {
		$message = 'Failed to parse RRULE.';
		if ( $reason ) {
			$message .= ' ' . $reason;
		}

		$exception        = new self( $message, 0, null, array( 'rrule' => $rrule ) );
		$exception->rrule = $rrule;

		return $exception;
	}

	/**
	 * Get the problematic component.
	 *
	 * @return string
	 */
	public function getComponent(): string {
		return $this->component;
	}

	/**
	 * Get the RRULE string.
	 *
	 * @return string
	 */
	public function getRrule(): string {
		return $this->rrule;
	}

	/**
	 * Convert exception to array.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data              = parent::toArray();
		$data['component'] = $this->component;
		$data['rrule']     = $this->rrule;

		return $data;
	}
}
