<?php
/**
 * Accessibility Notes Field — the one definition of the "accessibility needs" input.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Shared definition of the accessibility-needs input.
 *
 * The same field is collected on three surfaces — the classic WooCommerce
 * checkout, the block checkout, and the RSVP form — and every one of them
 * writes the same `attendees.accessibility_notes` column. Wording, length
 * limit, input name and sanitization live here so the paid and free paths
 * cannot drift apart (NTE-217).
 *
 * This is special-category data (health-adjacent). The purpose statement is
 * part of the field, not decoration: it must be rendered wherever the input
 * is, and the value must stay out of emails and activity-log strings.
 *
 * @since 1.4.5
 */
final class AccessibilityNotesField {

	/**
	 * Form input name shared by every capture surface.
	 *
	 * @var string
	 */
	public const INPUT_NAME = 'nettertech_events_accessibility_notes';

	/**
	 * Maximum stored length, in characters.
	 *
	 * Long enough for a paragraph of real needs, short enough that the
	 * column cannot be used as free storage.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 1000;

	/**
	 * Field label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Accessibility Requirements', 'nettertech-events' );
	}

	/**
	 * Placeholder / example text.
	 *
	 * @return string
	 */
	public static function placeholder(): string {
		return __( 'Please let us know about any accessibility needs or accommodations we can provide (wheelchair access, ASL interpreter, etc.)', 'nettertech-events' );
	}

	/**
	 * Purpose statement shown under the field on every capture surface.
	 *
	 * @return string
	 */
	public static function purpose_text(): string {
		return __( 'Used only to arrange accommodations for this event.', 'nettertech-events' );
	}

	/**
	 * Form input name.
	 *
	 * @return string
	 */
	public static function input_name(): string {
		return self::INPUT_NAME;
	}

	/**
	 * Normalize a submitted value for storage.
	 *
	 * Strips tags and control characters (`sanitize_textarea_field()` keeps
	 * newlines), clamps to MAX_LENGTH, and collapses an empty result to null so
	 * "no answer" is stored as NULL rather than an empty string — the list
	 * badge, the filter and the retention job all test for non-empty.
	 *
	 * @param mixed $raw Raw submitted value (already unslashed by the caller).
	 * @return string|null Clean value, or null when nothing usable was submitted.
	 */
	public static function sanitize( mixed $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$clean = trim( sanitize_textarea_field( $raw ) );
		if ( '' === $clean ) {
			return null;
		}

		if ( mb_strlen( $clean ) > self::MAX_LENGTH ) {
			$clean = rtrim( mb_substr( $clean, 0, self::MAX_LENGTH ) );
		}

		return $clean;
	}

	/**
	 * Strings and limits for a JavaScript renderer (block checkout).
	 *
	 * The block checkout builds its own textarea in JS; handing it this
	 * payload keeps it on the same wording as the PHP-rendered surfaces.
	 *
	 * @return array{inputName: string, label: string, placeholder: string, purpose: string, maxLength: int}
	 */
	public static function to_script_data(): array {
		return array(
			'inputName'   => self::INPUT_NAME,
			'label'       => self::label(),
			'placeholder' => self::placeholder(),
			'purpose'     => self::purpose_text(),
			'maxLength'   => self::MAX_LENGTH,
		);
	}
}
