<?php
/**
 * Field type enum for custom attendee registration fields.
 *
 * @package NetterTechEvents\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Represents the type of a custom attendee registration field.
 *
 * @since 3.6.0
 * @api
 */
enum FieldType: string {

	case TEXT     = 'text';
	case TEXTAREA = 'textarea';
	case SELECT   = 'select';
	case CHECKBOX = 'checkbox';
	case RADIO    = 'radio';
	case EMAIL    = 'email';
	case PHONE    = 'phone';
	case NUMBER   = 'number';
	case DATE     = 'date';
	case URL      = 'url';

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::TEXT     => __( 'Text', 'nettertech-events' ),
			self::TEXTAREA => __( 'Textarea', 'nettertech-events' ),
			self::SELECT   => __( 'Dropdown', 'nettertech-events' ),
			self::CHECKBOX => __( 'Checkbox', 'nettertech-events' ),
			self::RADIO    => __( 'Radio Buttons', 'nettertech-events' ),
			self::EMAIL    => __( 'Email', 'nettertech-events' ),
			self::PHONE    => __( 'Phone', 'nettertech-events' ),
			self::NUMBER   => __( 'Number', 'nettertech-events' ),
			self::DATE     => __( 'Date', 'nettertech-events' ),
			self::URL      => __( 'URL', 'nettertech-events' ),
		};
	}

	/**
	 * Check if this field type supports user-defined options.
	 *
	 * @return bool
	 */
	public function has_options(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::SELECT, self::RADIO, self::CHECKBOX => true,
			default => false,
		};
	}

	/**
	 * Get the HTML input type for rendering.
	 *
	 * @return string
	 */
	public function input_type(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::TEXT     => 'text',
			self::TEXTAREA => 'textarea',
			self::SELECT   => 'select',
			self::CHECKBOX => 'checkbox',
			self::RADIO    => 'radio',
			self::EMAIL    => 'email',
			self::PHONE    => 'tel',
			self::NUMBER   => 'number',
			self::DATE     => 'date',
			self::URL      => 'url',
		};
	}

	/**
	 * Get all field type values as an array.
	 *
	 * @return array<string>
	 */
	public static function values(): array {
		return array_column( self::cases(), 'value' );
	}
}
