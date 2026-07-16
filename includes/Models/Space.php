<?php
/**
 * Space model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a physical space/venue where events take place.
 *
 * Core space properties only. Rental-specific fields (rates, buffers,
 * booking durations) live in the Rentals add-on.
 *
 * @since 2.1.0
 * @api
 */
class Space {

	/**
	 * Space ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Space name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * URL-safe slug identifier.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * Short tagline / subtitle (e.g., "Main Hall — 400 Seats — Premium").
	 *
	 * @var string|null
	 */
	public ?string $tagline = null;

	/**
	 * Maximum occupancy.
	 *
	 * @var int
	 */
	public int $capacity = 0;

	/**
	 * Physical size in square feet.
	 *
	 * @var int|null
	 */
	public ?int $square_footage = null;

	/**
	 * Full description.
	 *
	 * @var string|null
	 */
	public ?string $description = null;

	/**
	 * WordPress attachment ID for featured image.
	 *
	 * @var int|null
	 */
	public ?int $featured_image_id = null;

	/**
	 * Display ordering.
	 *
	 * @var int
	 */
	public int $sort_order = 0;

	/**
	 * Status (active, inactive, archived, maintenance).
	 *
	 * @var string
	 */
	public string $status = 'active';

	/**
	 * Seating model — see {@see \NetterTechEvents\Enums\SeatingModel}.
	 *
	 * @var string
	 */
	public string $seating_model = 'free';

	/**
	 * Accessibility features as a JSON-encoded array of `{key, count?, notes?, custom?}` entries.
	 *
	 * Stored as JSON text; consumers should decode via {@see self::get_accessibility_features()}.
	 *
	 * @var string|null
	 */
	public ?string $accessibility_features = null;

	/**
	 * JSON-encoded amenity / feature list.
	 *
	 * @var string|null
	 */
	public ?string $amenities = null;

	/**
	 * JSON array of WordPress attachment IDs for gallery.
	 *
	 * @var string|null
	 */
	public ?string $gallery_image_ids = null;

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
	 * Valid statuses.
	 *
	 * @var array<string>
	 */
	public const STATUSES = array( 'active', 'inactive', 'archived', 'maintenance' );

	/**
	 * Create a Space from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row   = (object) $row;
		$space = new self();

		$space->id                     = isset( $row->id ) ? (int) $row->id : null;
		$space->name                   = $row->name ?? '';
		$space->slug                   = $row->slug ?? '';
		$space->tagline                = $row->tagline ?? null;
		$space->capacity               = (int) ( $row->capacity ?? 0 );
		$space->square_footage         = isset( $row->square_footage ) ? (int) $row->square_footage : null;
		$space->description            = $row->description ?? null;
		$space->featured_image_id      = isset( $row->featured_image_id ) ? (int) $row->featured_image_id : null;
		$space->sort_order             = (int) ( $row->sort_order ?? 0 );
		$space->status                 = $row->status ?? 'active';
		$space->seating_model          = $row->seating_model ?? 'free';
		$space->accessibility_features = $row->accessibility_features ?? null;
		$space->amenities              = $row->amenities ?? null;
		$space->gallery_image_ids      = $row->gallery_image_ids ?? null;
		$space->created_at             = $row->created_at ?? null;
		$space->updated_at             = $row->updated_at ?? null;

		return $space;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'name'                   => $this->name,
			'slug'                   => $this->slug,
			'tagline'                => $this->tagline,
			'capacity'               => $this->capacity,
			'square_footage'         => $this->square_footage,
			'description'            => $this->description,
			'featured_image_id'      => $this->featured_image_id,
			'sort_order'             => $this->sort_order,
			'status'                 => $this->status,
			'seating_model'          => $this->seating_model,
			'accessibility_features' => $this->accessibility_features,
			'amenities'              => $this->amenities,
			'gallery_image_ids'      => $this->gallery_image_ids,
		);
	}

	/**
	 * Get format specifiers for database operations.
	 *
	 * @return array<string>
	 */
	public function get_formats(): array {
		return array(
			'%s', // name.
			'%s', // slug.
			'%s', // tagline.
			'%d', // capacity.
			'%d', // square_footage.
			'%s', // description.
			'%d', // featured_image_id.
			'%d', // sort_order.
			'%s', // status.
			'%s', // seating_model.
			'%s', // accessibility_features.
			'%s', // amenities.
			'%s', // gallery_image_ids.
		);
	}

	/**
	 * Decode accessibility_features into a typed array of entries.
	 *
	 * Returns an empty array on null/invalid JSON. Each entry has at least
	 * a `key`; optional fields are `count` (int), `notes` (string), and
	 * `custom` (bool, true for non-preset entries).
	 *
	 * @return array<int, array{key: string, count?: int, notes?: string, custom?: bool}>
	 */
	public function get_accessibility_features(): array {
		if ( null === $this->accessibility_features || '' === $this->accessibility_features ) {
			return array();
		}
		$decoded = json_decode( $this->accessibility_features, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $entry ) {
			if ( is_array( $entry ) && isset( $entry['key'] ) && is_string( $entry['key'] ) && '' !== $entry['key'] ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Validate the space data.
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		if ( empty( $this->name ) ) {
			$errors[] = __( 'Space name is required.', 'nettertech-events' );
		}

		if ( empty( $this->slug ) ) {
			$errors[] = __( 'Space slug is required.', 'nettertech-events' );
		}

		if ( $this->capacity < 0 ) {
			$errors[] = __( 'Capacity cannot be negative.', 'nettertech-events' );
		}

		if ( null !== $this->square_footage && $this->square_footage < 0 ) {
			$errors[] = __( 'Square footage cannot be negative.', 'nettertech-events' );
		}

		if ( ! in_array( $this->status, self::STATUSES, true ) ) {
			$errors[] = __( 'Invalid space status.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Check if the space is currently active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}

	/**
	 * Check if the space is archived (soft-deleted).
	 *
	 * @return bool
	 */
	public function is_archived(): bool {
		return 'archived' === $this->status;
	}
}
