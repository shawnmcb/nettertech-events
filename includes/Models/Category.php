<?php
/**
 * Category model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Utilities\ImageHelper;

/**
 * Represents an event category.
 *
 * Categories are hierarchical (can have parent/child relationships).
 * Events can belong to multiple categories.
 *
 * @since 0.9.0
 * @api
 */
class Category {

	/**
	 * Category ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Category name.
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
	 * Category description.
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * Parent category ID (null for top-level).
	 *
	 * @var int|null
	 */
	public ?int $parent_id = null;

	/**
	 * Featured image attachment ID.
	 *
	 * @var int|null
	 */
	public ?int $featured_image_id = null;

	/**
	 * Sort order for display.
	 *
	 * @var int
	 */
	public int $sort_order = 0;

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
	 * Create a Category from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row      = (object) $row;
		$category = new self();

		$category->id                = isset( $row->id ) ? (int) $row->id : null;
		$category->name              = $row->name ?? '';
		$category->slug              = $row->slug ?? '';
		$category->description       = $row->description ?? '';
		$category->parent_id         = isset( $row->parent_id ) ? (int) $row->parent_id : null;
		$category->featured_image_id = isset( $row->featured_image_id ) ? (int) $row->featured_image_id : null;
		$category->sort_order        = isset( $row->sort_order ) ? (int) $row->sort_order : 0;
		$category->created_at        = $row->created_at ?? null;
		$category->updated_at        = $row->updated_at ?? null;

		return $category;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'name'              => $this->name,
			'slug'              => $this->slug,
			'description'       => $this->description,
			'parent_id'         => $this->parent_id,
			'featured_image_id' => $this->featured_image_id,
			'sort_order'        => $this->sort_order,
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
			'%s', // Description.
			'%d', // Parent ID.
			'%d', // Featured image ID.
			'%d', // Sort order.
		);
	}

	/**
	 * Validate the category data.
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		if ( empty( $this->name ) ) {
			$errors[] = __( 'Category name is required.', 'nettertech-events' );
		}

		if ( empty( $this->slug ) ) {
			$errors[] = __( 'Category slug is required.', 'nettertech-events' );
		}

		// Prevent self-referencing parent.
		if ( null !== $this->id && $this->parent_id === $this->id ) {
			$errors[] = __( 'Category cannot be its own parent.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Check if this is a top-level category.
	 *
	 * @return bool
	 */
	public function is_top_level(): bool {
		return null === $this->parent_id;
	}

	/**
	 * Get the featured image URL.
	 *
	 * @param string $size Image size.
	 * @return string|null
	 */
	public function get_featured_image_url( string $size = 'large' ): ?string {
		if ( ! $this->featured_image_id ) {
			return null;
		}

		return ImageHelper::get_attachment_image_url( $this->featured_image_id, $size );
	}

	/**
	 * Get the permalink for this category.
	 *
	 * @return string
	 */
	public function get_permalink(): string {
		return home_url( '/events/category/' . $this->slug . '/' );
	}
}
