<?php
/**
 * Snapshot test for the attendee-field row template (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Metaboxes\Presenters\AttendeeFieldRowPresenter;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the attendee-field row template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\Metaboxes\Presenters\AttendeeFieldRowPresenter
 */
final class AttendeeFieldRowSnapshotTest extends TestCase {

	use SnapshotTestTrait;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// WP-core `selected()` / `checked()` helpers used by the template.
		Functions\when( 'selected' )->alias(
			static function ( $value, $compare = true, $echo = true ): string {
				$out = ( (string) $value === (string) $compare ) ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				return $out;
			}
		);
		Functions\when( 'checked' )->alias(
			static function ( $value, $compare = true, $echo = true ): string {
				$out = ( (string) $value === (string) $compare ) ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				return $out;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * New (empty) text field at index 0 renders with default values and no options panel.
	 *
	 * @group decision
	 */
	public function test_renders_empty_text_field(): void {
		$field             = new AttendeeField();
		$field->field_type = 'text';
		$field->label      = '';

		$presenter = new AttendeeFieldRowPresenter( $field, 0 );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/attendee-field-row.php',
			$presenter,
			'empty_text_field'
		);
	}

	/**
	 * Saved select field with options shows the options panel and persisted values.
	 *
	 * @group decision
	 */
	public function test_renders_populated_select_field(): void {
		$field              = new AttendeeField();
		$field->id          = 42;
		$field->field_type  = 'select';
		$field->label       = 'T-Shirt Size';
		$field->is_required = true;
		$field->placeholder = 'Pick a size';
		$field->set_options( array( 'Small', 'Medium', 'Large' ) );

		$presenter = new AttendeeFieldRowPresenter( $field, 1 );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/attendee-field-row.php',
			$presenter,
			'populated_select_field'
		);
	}

	/**
	 * Email field at numeric index 2 has no options panel (display:none) and is non-required.
	 *
	 * @group decision
	 */
	public function test_renders_email_field_without_options(): void {
		$field              = new AttendeeField();
		$field->field_type  = 'email';
		$field->label       = 'Email Address';
		$field->is_required = false;

		$presenter = new AttendeeFieldRowPresenter( $field, 2 );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/attendee-field-row.php',
			$presenter,
			'email_field_no_options'
		);
	}
}
