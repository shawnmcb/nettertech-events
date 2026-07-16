<?php
/**
 * AttendeeFieldsMetaboxHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Metaboxes\AttendeeFieldsMetaboxHandler;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Enums\FieldType;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Models\Event;

/**
 * Test AttendeeFieldsMetaboxHandler rendering.
 *
 * Covers field list rendering, individual field row output,
 * field type options visibility, collect-individual-attendees toggle,
 * and the early return when event ID is absent.
 */
class AttendeeFieldsMetaboxHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface|Mockery\MockInterface
	 */
	private $field_repo;

	/**
	 * Test event.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->field_repo = Mockery::mock( AttendeeFieldRepositoryInterface::class );

		$this->event     = new Event();
		$this->event->id = 1;

		Functions\when( 'wp_enqueue_script' )->justReturn( null );
	}

	/**
	 * Create handler instance.
	 *
	 * @param Event|null $event Optional event override.
	 * @return AttendeeFieldsMetaboxHandler
	 */
	private function create_handler( ?Event $event = null ): AttendeeFieldsMetaboxHandler {
		return new AttendeeFieldsMetaboxHandler(
			$event ?? $this->event,
			$this->field_repo
		);
	}

	/**
	 * Create a minimal AttendeeField.
	 *
	 * @param string $field_type Field type value.
	 * @param string $label      Field label.
	 * @param bool   $required   Required flag.
	 * @return AttendeeField
	 */
	private function make_field(
		string $field_type = 'text',
		string $label = 'Test Field',
		bool $required = false
	): AttendeeField {
		$field             = new AttendeeField();
		$field->label      = $label;
		$field->field_type = $field_type;
		$field->is_required = $required;
		return $field;
	}

	// =========================================================================
	// render() — early return Tests
	// =========================================================================

	/**
	 * Test render returns early when event has no ID.
	 *
	 * @return void
	 */
	public function test_render_early_return_when_no_event_id(): void {
		$event     = new Event();
		$event->id = 0;

		// Repository should NOT be called.
		$this->field_repo->shouldNotReceive( 'for_event' );

		$handler = $this->create_handler( $event );

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Test render queries repository with event ID.
	 *
	 * @return void
	 */
	public function test_render_queries_repository_for_event(): void {
		$this->field_repo
			->shouldReceive( 'for_event' )
			->once()
			->with( 1 )
			->andReturn( array() );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		ob_get_clean();

		// Expectation verified by Mockery via assertPostConditions.
		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// render() — structure Tests
	// =========================================================================

	/**
	 * Test render outputs registration fields postbox.
	 *
	 * @return void
	 */
	public function test_render_outputs_postbox_structure(): void {
		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array() );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-attendee-fields-postbox', $output );
	}

	/**
	 * Test render outputs collect_individual_attendees checkbox.
	 *
	 * @return void
	 */
	public function test_render_outputs_collect_individual_checkbox(): void {
		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array() );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="collect_individual_attendees"', $output );
	}

	/**
	 * Test collect_individual_attendees checkbox is checked when event has flag.
	 *
	 * @return void
	 */
	public function test_render_checks_collect_individual_when_enabled(): void {
		$this->event->collect_individual_attendees = true;

		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array() );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checked', $output );
	}

	/**
	 * Test render outputs add field button.
	 *
	 * @return void
	 */
	public function test_render_outputs_add_field_button(): void {
		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array() );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-add-attendee-field', $output );
	}

	/**
	 * Test render outputs JS template for new fields.
	 *
	 * @return void
	 */
	public function test_render_outputs_js_field_template(): void {
		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array() );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'tmpl-nte-attendee-field', $output );
	}

	// =========================================================================
	// Field row rendering Tests
	// =========================================================================

	/**
	 * Test render outputs label input for each field.
	 *
	 * @return void
	 */
	public function test_render_outputs_label_input_for_field(): void {
		$field = $this->make_field( 'text', 'Dietary Requirement' );

		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array( $field ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Dietary Requirement', $output );
	}

	/**
	 * Test render outputs field_type select for each field.
	 *
	 * @return void
	 */
	public function test_render_outputs_field_type_select(): void {
		$field = $this->make_field( 'text', 'Size' );

		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array( $field ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '[field_type]', $output );
	}

	/**
	 * Test render shows all field types as options.
	 *
	 * @return void
	 */
	public function test_render_lists_all_field_type_options(): void {
		$field = $this->make_field();

		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array( $field ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		// Every FieldType enum value must appear in the output.
		foreach ( FieldType::cases() as $type ) {
			$this->assertStringContainsString( $type->value, $output );
		}
	}

	/**
	 * Test render outputs required checkbox for each field.
	 *
	 * @return void
	 */
	public function test_render_outputs_required_checkbox(): void {
		$field = $this->make_field( 'text', 'Email', false );

		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array( $field ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '[is_required]', $output );
	}

	/**
	 * Test render shows options textarea for select field type.
	 *
	 * @return void
	 */
	public function test_render_shows_options_textarea_for_select_type(): void {
		$field             = $this->make_field( 'select', 'T-Shirt Size' );
		$field->options    = json_encode( array( 'Small', 'Medium', 'Large' ) );

		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array( $field ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		// Options textarea visible (no display:none) for select type.
		$this->assertStringContainsString( '[options]', $output );
		// The field row section (before the JS template) should not hide the options wrap.
		// Split at the tmpl script to isolate rendered field rows from the JS template.
		$field_section = explode( 'tmpl-nte-attendee-field', $output )[0];
		$this->assertStringNotContainsString( 'nte-field-options-wrap" style="display:none;', $field_section );
	}

	/**
	 * Test render hides options textarea for text field type.
	 *
	 * FieldType::TEXT has_options() returns false, so the options-wrap div
	 * gets the inline style display:none applied.
	 *
	 * @return void
	 */
	public function test_render_hides_options_textarea_for_text_type(): void {
		$field = $this->make_field( 'text', 'Name' );

		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array( $field ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		// The field row for a text field has its options-wrap with display:none.
		// The pattern: class="nte-field-options-wrap" style="display:none;"
		$this->assertStringContainsString( 'nte-field-options-wrap', $output );
		// Text field has_options() = false, so display:none must appear at least once
		// in the field rows section (before the JS template).
		$field_rows_section = explode( 'tmpl-nte-attendee-field', $output )[0];
		$this->assertStringContainsString( 'display:none', $field_rows_section );
	}

	/**
	 * Test render uses indexed names for multiple fields.
	 *
	 * @return void
	 */
	public function test_render_uses_indexed_names_for_multiple_fields(): void {
		$field1 = $this->make_field( 'text', 'Name' );
		$field2 = $this->make_field( 'email', 'Email' );

		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array( $field1, $field2 ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'attendee_fields[0]', $output );
		$this->assertStringContainsString( 'attendee_fields[1]', $output );
	}

	/**
	 * Test render outputs remove button for each field row.
	 *
	 * @return void
	 */
	public function test_render_outputs_remove_button(): void {
		$field = $this->make_field();

		$this->field_repo
			->shouldReceive( 'for_event' )
			->andReturn( array( $field ) );

		$handler = $this->create_handler();

		ob_start();
		$handler->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-remove-field', $output );
	}
}
