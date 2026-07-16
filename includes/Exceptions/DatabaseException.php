<?php
/**
 * Database exception for NetterTech Events plugin.
 *
 * @package NetterTechEvents\Exceptions
 */

declare(strict_types=1);

namespace NetterTechEvents\Exceptions;

defined( 'ABSPATH' ) || exit;

/**
 * Exception thrown when database operations fail.
 *
 * @since 0.9.2
 * @api
 */
class DatabaseException extends NetterTechEventsException {

	/**
	 * Database operation type.
	 *
	 * @var string
	 */
	protected string $operation = '';

	/**
	 * Entity type involved.
	 *
	 * @var string
	 */
	protected string $entity_type = '';

	/**
	 * Create exception for insert failure.
	 *
	 * @param string               $entity_type Entity type (e.g., 'event', 'attendee').
	 * @param string               $error       Database error message.
	 * @param array<string, mixed> $context Additional context.
	 * @return self
	 */
	public static function insertFailed( string $entity_type, string $error = '', array $context = array() ): self {
		$message = sprintf( 'Failed to insert %s.', $entity_type );
		if ( $error ) {
			$message .= ' ' . $error;
		}

		$exception              = new self( $message, 0, null, $context );
		$exception->operation   = 'insert';
		$exception->entity_type = $entity_type;

		return $exception;
	}

	/**
	 * Create exception for update failure.
	 *
	 * @param string               $entity_type Entity type.
	 * @param int                  $id          Entity ID.
	 * @param string               $error       Database error message.
	 * @param array<string, mixed> $context Additional context.
	 * @return self
	 */
	public static function updateFailed( string $entity_type, int $id, string $error = '', array $context = array() ): self {
		$message = sprintf( 'Failed to update %s (ID: %d).', $entity_type, $id );
		if ( $error ) {
			$message .= ' ' . $error;
		}

		$context['id']          = $id;
		$exception              = new self( $message, 0, null, $context );
		$exception->operation   = 'update';
		$exception->entity_type = $entity_type;

		return $exception;
	}

	/**
	 * Create exception for delete failure.
	 *
	 * @param string               $entity_type Entity type.
	 * @param int                  $id          Entity ID.
	 * @param string               $error       Database error message.
	 * @param array<string, mixed> $context Additional context.
	 * @return self
	 */
	public static function deleteFailed( string $entity_type, int $id, string $error = '', array $context = array() ): self {
		$message = sprintf( 'Failed to delete %s (ID: %d).', $entity_type, $id );
		if ( $error ) {
			$message .= ' ' . $error;
		}

		$context['id']          = $id;
		$exception              = new self( $message, 0, null, $context );
		$exception->operation   = 'delete';
		$exception->entity_type = $entity_type;

		return $exception;
	}

	/**
	 * Create exception for query failure.
	 *
	 * @param string               $error   Database error message.
	 * @param string               $query   SQL query that failed.
	 * @param array<string, mixed> $context Additional context.
	 * @return self
	 */
	public static function queryFailed( string $error, string $query = '', array $context = array() ): self {
		$message = 'Database query failed.';
		if ( $error ) {
			$message .= ' ' . $error;
		}

		if ( $query ) {
			$context['query'] = $query;
		}

		$exception            = new self( $message, 0, null, $context );
		$exception->operation = 'query';

		return $exception;
	}

	/**
	 * Get the operation type.
	 *
	 * @return string
	 */
	public function getOperation(): string {
		return $this->operation;
	}

	/**
	 * Get the entity type.
	 *
	 * @return string
	 */
	public function getEntityType(): string {
		return $this->entity_type;
	}

	/**
	 * Convert exception to array.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data                = parent::toArray();
		$data['operation']   = $this->operation;
		$data['entity_type'] = $this->entity_type;

		return $data;
	}

	/**
	 * Convert exception to array with sensitive SQL data stripped.
	 *
	 * Defense-in-depth: strips query/sql/last_error from context
	 * to prevent SQL leakage in non-debug contexts.
	 *
	 * @return array<string, mixed>
	 */
	public function toSafeArray(): array {
		$data           = $this->toArray();
		$sensitive_keys = array( 'query', 'sql', 'last_error' );

		foreach ( $sensitive_keys as $key ) {
			unset( $data['context'][ $key ] );
		}

		return $data;
	}
}
