<?php
/**
 * Base exception for NetterTech Events plugin.
 *
 * @package NetterTechEvents\Exceptions
 */

declare(strict_types=1);

namespace NetterTechEvents\Exceptions;

defined( 'ABSPATH' ) || exit;

/**
 * Base exception class for all NetterTech Events exceptions.
 *
 * Provides a consistent exception hierarchy with context support.
 *
 * @since 0.9.2
 * @api
 */
class NetterTechEventsException extends \RuntimeException {

	/**
	 * Additional context data.
	 *
	 * @var array<string, mixed>
	 */
	protected array $context = array();

	/**
	 * Constructor.
	 *
	 * @param string               $message  Exception message.
	 * @param int                  $code     Exception code.
	 * @param \Throwable|null      $previous Previous exception.
	 * @param array<string, mixed> $context  Additional context data.
	 */
	public function __construct(
		string $message = '',
		int $code = 0,
		?\Throwable $previous = null,
		array $context = array()
	) {
		parent::__construct( $message, $code, $previous );
		$this->context = $context;
	}

	/**
	 * Get additional context data.
	 *
	 * @return array<string, mixed>
	 */
	public function getContext(): array {
		return $this->context;
	}

	/**
	 * Get a specific context value.
	 *
	 * @param string $key           Context key.
	 * @param mixed  $default_value Default value if key not found.
	 * @return mixed
	 */
	public function getContextValue( string $key, mixed $default_value = null ): mixed {
		return $this->context[ $key ] ?? $default_value;
	}

	/**
	 * Convert exception to array for logging/API responses.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'type'    => static::class,
			'message' => $this->getMessage(),
			'code'    => $this->getCode(),
			'context' => $this->context,
		);
	}
}
