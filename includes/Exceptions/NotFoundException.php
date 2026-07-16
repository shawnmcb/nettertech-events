<?php
/**
 * Not Found exception for NetterTech Events plugin.
 *
 * @package NetterTechEvents\Exceptions
 */

declare(strict_types=1);

namespace NetterTechEvents\Exceptions;

defined( 'ABSPATH' ) || exit;

/**
 * Exception thrown when a requested entity is not found.
 *
 * @since 0.9.2
 * @api
 */
class NotFoundException extends NetterTechEventsException {

	/**
	 * Entity type.
	 *
	 * @var string
	 */
	protected string $entity_type = '';

	/**
	 * Entity identifier.
	 *
	 * @var int|string
	 */
	protected int|string $identifier = 0;

	/**
	 * Create exception for entity not found by ID.
	 *
	 * @param string $entity_type Entity type (e.g., 'event', 'attendee').
	 * @param int    $id          Entity ID.
	 * @return self
	 */
	public static function byId( string $entity_type, int $id ): self {
		$message = sprintf( '%s not found (ID: %d).', ucfirst( $entity_type ), $id );

		$exception              = new self( $message, 0, null, array( 'id' => $id ) );
		$exception->entity_type = $entity_type;
		$exception->identifier  = $id;

		return $exception;
	}

	/**
	 * Create exception for entity not found by slug.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $slug        Entity slug.
	 * @return self
	 */
	public static function bySlug( string $entity_type, string $slug ): self {
		$message = sprintf( '%s not found (slug: %s).', ucfirst( $entity_type ), $slug );

		$exception              = new self( $message, 0, null, array( 'slug' => $slug ) );
		$exception->entity_type = $entity_type;
		$exception->identifier  = $slug;

		return $exception;
	}

	/**
	 * Create exception for entity not found by custom criteria.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $criteria    Criteria description.
	 * @param mixed  $value       Criteria value.
	 * @return self
	 */
	public static function byCriteria( string $entity_type, string $criteria, mixed $value ): self {
		$message = sprintf(
			'%s not found (%s: %s).',
			ucfirst( $entity_type ),
			$criteria,
			is_scalar( $value ) ? (string) $value : gettype( $value )
		);

		$exception              = new self( $message, 0, null, array( $criteria => $value ) );
		$exception->entity_type = $entity_type;
		if ( is_int( $value ) || is_string( $value ) ) {
			$exception->identifier = $value;
		}

		return $exception;
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
	 * Get the identifier.
	 *
	 * @return int|string
	 */
	public function getIdentifier(): int|string {
		return $this->identifier;
	}

	/**
	 * Convert exception to array.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data                = parent::toArray();
		$data['entity_type'] = $this->entity_type;
		$data['identifier']  = $this->identifier;

		return $data;
	}
}
