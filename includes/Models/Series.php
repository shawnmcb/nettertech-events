<?php
/**
 * Series model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Utilities\ImageHelper;

/**
 * Represents an event series (e.g., a class series).
 *
 * A series groups related events together and can offer
 * a series pass for discounted multi-event access.
 *
 * @since 0.8.0
 * @api
 */
class Series {

	/**
	 * Series ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Series title.
	 *
	 * @var string
	 */
	public string $title = '';

	/**
	 * URL-friendly slug.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * Series description.
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * Featured image attachment ID.
	 *
	 * @var int|null
	 */
	public ?int $featured_image_id = null;

	/**
	 * Whether series pass is enabled.
	 *
	 * @var bool
	 */
	public bool $pass_enabled = false;

	/**
	 * Series pass price.
	 *
	 * @var float|null
	 */
	public ?float $pass_price = null;

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
	 * Create a Series from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row    = (object) $row;
		$series = new self();

		$series->id                = isset( $row->id ) ? (int) $row->id : null;
		$series->title             = $row->title ?? '';
		$series->slug              = $row->slug ?? '';
		$series->description       = $row->description ?? '';
		$series->featured_image_id = isset( $row->featured_image_id ) ? (int) $row->featured_image_id : null;
		$series->pass_enabled      = (bool) ( $row->pass_enabled ?? false );
		$series->pass_price        = isset( $row->pass_price ) ? (float) $row->pass_price : null;
		$series->created_at        = $row->created_at ?? null;
		$series->updated_at        = $row->updated_at ?? null;

		return $series;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'title'             => $this->title,
			'slug'              => $this->slug,
			'description'       => $this->description,
			'featured_image_id' => $this->featured_image_id,
			'pass_enabled'      => $this->pass_enabled ? 1 : 0,
			'pass_price'        => $this->pass_price,
		);
	}

	/**
	 * Get format specifiers for database operations.
	 *
	 * @return array<string>
	 */
	public function get_formats(): array {
		return array(
			'%s', // Title.
			'%s', // Slug.
			'%s', // Description.
			'%d', // Featured image ID.
			'%d', // Pass enabled.
			'%f', // Pass price.
		);
	}

	/**
	 * Validate the series data.
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		if ( empty( $this->title ) ) {
			$errors[] = __( 'Series title is required.', 'nettertech-events' );
		}

		if ( empty( $this->slug ) ) {
			$errors[] = __( 'Series slug is required.', 'nettertech-events' );
		}

		if ( $this->pass_enabled && ( null === $this->pass_price || $this->pass_price < 0 ) ) {
			$errors[] = __( 'Series pass requires a valid price.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Check if series pass is available for purchase.
	 *
	 * @return bool
	 */
	public function has_pass(): bool {
		return $this->pass_enabled && null !== $this->pass_price;
	}

	/**
	 * Get the formatted pass price.
	 *
	 * @return string
	 */
	public function get_formatted_pass_price(): string {
		$pass_price = $this->pass_price;
		if ( ! $this->has_pass() || null === $pass_price ) {
			return '';
		}

		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $pass_price );
		}

		return '$' . number_format( $pass_price, 2 );
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
	 * Get the permalink for this series.
	 *
	 * @return string
	 */
	public function get_permalink(): string {
		return home_url( '/series/' . $this->slug . '/' );
	}
}
