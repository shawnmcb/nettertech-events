<?php
/**
 * Tag model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents an event tag.
 *
 * Tags are flat (no hierarchy).
 * Events can have multiple tags.
 *
 * @since 0.9.0
 * @api
 */
class Tag {

	/**
	 * Tag ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Tag name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * URL-friendly slug.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * Created timestamp.
	 *
	 * @var string|null
	 */
	public ?string $created_at = null;

	/**
	 * Updated timestamp.
	 *
	 * @var string|null
	 */
	public ?string $updated_at = null;

	/**
	 * Create a Tag from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row = (object) $row;
		$tag = new self();

		$tag->id         = isset( $row->id ) ? (int) $row->id : null;
		$tag->name       = $row->name ?? '';
		$tag->slug       = $row->slug ?? '';
		$tag->created_at = $row->created_at ?? null;
		$tag->updated_at = $row->updated_at ?? null;

		return $tag;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'name' => $this->name,
			'slug' => $this->slug,
		);
	}

	/**
	 * Get format specifiers for database operations.
	 *
	 * @return array<string>
	 */
	public function get_formats(): array {
		return array(
			'%s', // Name.
			'%s', // Slug.
		);
	}

	/**
	 * Validate the tag data.
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		if ( empty( $this->name ) ) {
			$errors[] = __( 'Tag name is required.', 'nettertech-events' );
		}

		if ( empty( $this->slug ) ) {
			$errors[] = __( 'Tag slug is required.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Get the permalink for this tag.
	 *
	 * @return string
	 */
	public function get_permalink(): string {
		return home_url( '/events/tag/' . $this->slug . '/' );
	}
}
