<?php
/**
 * Organizer model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Utilities\ImageHelper;

/**
 * Represents an event organizer.
 *
 * An organizer can be associated with multiple events.
 * Events can have multiple organizers.
 *
 * @since 0.9.0
 * @api
 */
class Organizer {

	/**
	 * Organizer ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Organizer name.
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
	 * Organizer description.
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * Contact email address.
	 *
	 * @var string|null
	 */
	public ?string $email = null;

	/**
	 * Contact phone number.
	 *
	 * @var string|null
	 */
	public ?string $phone = null;

	/**
	 * Website URL.
	 *
	 * @var string|null
	 */
	public ?string $website = null;

	/**
	 * Featured image attachment ID.
	 *
	 * @var int|null
	 */
	public ?int $featured_image_id = null;

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
	 * Create an Organizer from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row       = (object) $row;
		$organizer = new self();

		$organizer->id                = isset( $row->id ) ? (int) $row->id : null;
		$organizer->name              = $row->name ?? '';
		$organizer->slug              = $row->slug ?? '';
		$organizer->description       = $row->description ?? '';
		$organizer->email             = $row->email ?? null;
		$organizer->phone             = $row->phone ?? null;
		$organizer->website           = $row->website ?? null;
		$organizer->featured_image_id = isset( $row->featured_image_id ) ? (int) $row->featured_image_id : null;
		$organizer->created_at        = $row->created_at ?? null;
		$organizer->updated_at        = $row->updated_at ?? null;

		return $organizer;
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
			'email'             => $this->email,
			'phone'             => $this->phone,
			'website'           => $this->website,
			'featured_image_id' => $this->featured_image_id,
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
			'%s', // Email.
			'%s', // Phone.
			'%s', // Website.
			'%d', // Featured image ID.
		);
	}

	/**
	 * Validate the organizer data.
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		if ( empty( $this->name ) ) {
			$errors[] = __( 'Organizer name is required.', 'nettertech-events' );
		}

		if ( empty( $this->slug ) ) {
			$errors[] = __( 'Organizer slug is required.', 'nettertech-events' );
		}

		if ( ! empty( $this->email ) && ! is_email( $this->email ) ) {
			$errors[] = __( 'Invalid email address.', 'nettertech-events' );
		}

		if ( ! empty( $this->website ) && ! filter_var( $this->website, FILTER_VALIDATE_URL ) ) {
			$errors[] = __( 'Invalid website URL.', 'nettertech-events' );
		}

		return $errors;
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
	 * Get the permalink for this organizer.
	 *
	 * @return string
	 */
	public function get_permalink(): string {
		return home_url( '/organizer/' . $this->slug . '/' );
	}
}
