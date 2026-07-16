<?php
/**
 * LayoutService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Services\LayoutService;

/**
 * Test LayoutService functionality.
 *
 * Tests layout configuration, validation, and merging.
 */
class LayoutServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Service instance.
	 *
	 * @var LayoutService
	 */
	private LayoutService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new LayoutService();
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test COMPONENTS constant has expected keys.
	 *
	 * @return void
	 */
	public function test_components_constant_has_expected_keys(): void {
		$expected = array(
			'header',
			'featured_image',
			'occurrence_date',
			'upcoming_dates',
			'description',
			'more_dates',
		);

		$this->assertEquals( $expected, array_keys( LayoutService::COMPONENTS ) );
	}

	/**
	 * Test each component has required metadata fields.
	 *
	 * @return void
	 */
	public function test_each_component_has_required_metadata(): void {
		foreach ( LayoutService::COMPONENTS as $id => $meta ) {
			$this->assertArrayHasKey( 'label', $meta, "Component {$id} missing label" );
			$this->assertArrayHasKey( 'description', $meta, "Component {$id} missing description" );
			$this->assertArrayHasKey( 'default_visible', $meta, "Component {$id} missing default_visible" );
			$this->assertIsBool( $meta['default_visible'], "Component {$id} default_visible should be bool" );
		}
	}

	/**
	 * Test DEFAULT_ORDER constant.
	 *
	 * @return void
	 */
	public function test_default_order_constant(): void {
		$this->assertCount( 6, LayoutService::DEFAULT_ORDER );
		$this->assertEquals( 'header', LayoutService::DEFAULT_ORDER[0] );
		$this->assertEquals( 'more_dates', LayoutService::DEFAULT_ORDER[5] );
	}

	// =========================================================================
	// get_hardcoded_default() Tests
	// =========================================================================

	/**
	 * Test get_hardcoded_default returns array with order and visibility.
	 *
	 * @return void
	 */
	public function test_get_hardcoded_default_structure(): void {
		$default = $this->service->get_hardcoded_default();

		$this->assertIsArray( $default );
		$this->assertArrayHasKey( 'order', $default );
		$this->assertArrayHasKey( 'visibility', $default );
	}

	/**
	 * Test get_hardcoded_default order matches DEFAULT_ORDER.
	 *
	 * @return void
	 */
	public function test_get_hardcoded_default_order(): void {
		$default = $this->service->get_hardcoded_default();

		$this->assertEquals( LayoutService::DEFAULT_ORDER, $default['order'] );
	}

	/**
	 * Test get_hardcoded_default visibility includes all components.
	 *
	 * @return void
	 */
	public function test_get_hardcoded_default_visibility_includes_all_components(): void {
		$default = $this->service->get_hardcoded_default();

		foreach ( array_keys( LayoutService::COMPONENTS ) as $id ) {
			$this->assertArrayHasKey( $id, $default['visibility'] );
		}
	}

	/**
	 * Test get_hardcoded_default visibility matches component defaults.
	 *
	 * @return void
	 */
	public function test_get_hardcoded_default_visibility_values(): void {
		$default = $this->service->get_hardcoded_default();

		foreach ( LayoutService::COMPONENTS as $id => $meta ) {
			$this->assertEquals(
				$meta['default_visible'],
				$default['visibility'][ $id ],
				"Visibility for {$id} should match default"
			);
		}
	}

	/**
	 * Test get_hardcoded_default for a recurring event places description above upcoming_dates.
	 *
	 * Recurring events use DEFAULT_ORDER_RECURRING so visitors read the series
	 * description before scanning the upcoming-dates list.
	 *
	 * @return void
	 */
	public function test_get_hardcoded_default_for_recurring_event_puts_description_above_upcoming(): void {
		$event             = new Event();
		$event->event_type = 'recurring';

		$default = $this->service->get_hardcoded_default( $event );

		$order             = $default['order'];
		$description_pos   = array_search( 'description', $order, true );
		$upcoming_dates_pos = array_search( 'upcoming_dates', $order, true );

		$this->assertNotFalse( $description_pos, 'description must appear in order' );
		$this->assertNotFalse( $upcoming_dates_pos, 'upcoming_dates must appear in order' );
		$this->assertLessThan(
			$upcoming_dates_pos,
			$description_pos,
			'description must precede upcoming_dates for recurring events'
		);

		$this->assertEquals( LayoutService::DEFAULT_ORDER_RECURRING, $order );
	}

	/**
	 * Test get_hardcoded_default for a single-occurrence event is unchanged (back-compat).
	 *
	 * Single-occurrence events must continue to receive the original DEFAULT_ORDER
	 * so existing page layouts are unaffected by the recurring-default feature.
	 *
	 * @return void
	 */
	public function test_get_hardcoded_default_for_single_occurrence_event_unchanged(): void {
		$event             = new Event();
		$event->event_type = 'single';

		$default = $this->service->get_hardcoded_default( $event );

		$this->assertEquals(
			LayoutService::DEFAULT_ORDER,
			$default['order'],
			'Single-occurrence events must use DEFAULT_ORDER unchanged'
		);
	}

	/**
	 * Test that a per-event layout override wins over the recurring default.
	 *
	 * When an admin has saved a custom layout for a recurring event, that stored
	 * order must win; the recurring-aware default must not clobber it.
	 *
	 * @return void
	 */
	public function test_per_event_layout_override_wins_over_recurring_default(): void {
		$event             = new Event();
		$event->event_type = 'recurring';

		$custom_config = array(
			'order'      => array( 'header', 'upcoming_dates', 'description', 'featured_image', 'occurrence_date', 'more_dates' ),
			'visibility' => array(
				'header'          => true,
				'upcoming_dates'  => true,
				'description'     => true,
				'featured_image'  => true,
				'occurrence_date' => true,
				'more_dates'      => true,
			),
		);

		$event->layout_config = $custom_config;

		Functions\when( 'get_option' )->justReturn( array() );

		$result = $this->service->get_layout( $event->layout_config, $event );

		// Custom order starts with upcoming_dates before description — override wins.
		$upcoming_dates_pos = array_search( 'upcoming_dates', $result['order'], true );
		$description_pos    = array_search( 'description', $result['order'], true );

		$this->assertLessThan(
			$description_pos,
			$upcoming_dates_pos,
			'Per-event layout override must win over recurring-aware default: upcoming_dates should precede description'
		);
	}

	// =========================================================================
	// validate_config() Tests
	// =========================================================================

	/**
	 * Test validate_config returns true for valid config.
	 *
	 * @return void
	 */
	public function test_validate_config_valid(): void {
		$config = array(
			'order'      => array( 'header', 'description' ),
			'visibility' => array( 'header' => true, 'description' => false ),
		);

		$this->assertTrue( $this->service->validate_config( $config ) );
	}

	/**
	 * Test validate_config returns false without order.
	 *
	 * @return void
	 */
	public function test_validate_config_missing_order(): void {
		$config = array(
			'visibility' => array( 'header' => true ),
		);

		$this->assertFalse( $this->service->validate_config( $config ) );
	}

	/**
	 * Test validate_config returns false with non-array order.
	 *
	 * @return void
	 */
	public function test_validate_config_non_array_order(): void {
		$config = array(
			'order'      => 'header',
			'visibility' => array( 'header' => true ),
		);

		$this->assertFalse( $this->service->validate_config( $config ) );
	}

	/**
	 * Test validate_config returns false without visibility.
	 *
	 * @return void
	 */
	public function test_validate_config_missing_visibility(): void {
		$config = array(
			'order' => array( 'header' ),
		);

		$this->assertFalse( $this->service->validate_config( $config ) );
	}

	/**
	 * Test validate_config returns false with non-array visibility.
	 *
	 * @return void
	 */
	public function test_validate_config_non_array_visibility(): void {
		$config = array(
			'order'      => array( 'header' ),
			'visibility' => true,
		);

		$this->assertFalse( $this->service->validate_config( $config ) );
	}

	/**
	 * Test validate_config returns false with invalid order ID.
	 *
	 * @return void
	 */
	public function test_validate_config_invalid_order_id(): void {
		$config = array(
			'order'      => array( 'header', 'invalid_component' ),
			'visibility' => array( 'header' => true ),
		);

		$this->assertFalse( $this->service->validate_config( $config ) );
	}

	/**
	 * Test validate_config returns false with invalid visibility key.
	 *
	 * @return void
	 */
	public function test_validate_config_invalid_visibility_key(): void {
		$config = array(
			'order'      => array( 'header' ),
			'visibility' => array( 'invalid_component' => true ),
		);

		$this->assertFalse( $this->service->validate_config( $config ) );
	}

	// =========================================================================
	// sanitize_config() Tests
	// =========================================================================

	/**
	 * Test sanitize_config filters invalid order IDs.
	 *
	 * @return void
	 */
	public function test_sanitize_config_filters_invalid_order(): void {
		$config = array(
			'order'      => array( 'header', 'invalid', 'description' ),
			'visibility' => array( 'header' => true ),
		);

		$result = $this->service->sanitize_config( $config );

		$this->assertEquals( array( 'header', 'description' ), $result['order'] );
	}

	/**
	 * Test sanitize_config filters invalid visibility keys.
	 *
	 * @return void
	 */
	public function test_sanitize_config_filters_invalid_visibility(): void {
		$config = array(
			'order'      => array( 'header' ),
			'visibility' => array(
				'header'  => true,
				'invalid' => false,
			),
		);

		$result = $this->service->sanitize_config( $config );

		$this->assertArrayHasKey( 'header', $result['visibility'] );
		$this->assertArrayNotHasKey( 'invalid', $result['visibility'] );
	}

	/**
	 * Test sanitize_config converts visibility to boolean.
	 *
	 * @return void
	 */
	public function test_sanitize_config_converts_visibility_to_bool(): void {
		$config = array(
			'order'      => array( 'header', 'description' ),
			'visibility' => array(
				'header'      => 1,
				'description' => 0,
			),
		);

		$result = $this->service->sanitize_config( $config );

		$this->assertTrue( $result['visibility']['header'] );
		$this->assertFalse( $result['visibility']['description'] );
	}

	/**
	 * Test sanitize_config handles empty order.
	 *
	 * @return void
	 */
	public function test_sanitize_config_handles_empty_order(): void {
		$config = array(
			'visibility' => array( 'header' => true ),
		);

		$result = $this->service->sanitize_config( $config );

		$this->assertEquals( array(), $result['order'] );
	}

	/**
	 * Test sanitize_config handles empty visibility.
	 *
	 * @return void
	 */
	public function test_sanitize_config_handles_empty_visibility(): void {
		$config = array(
			'order' => array( 'header' ),
		);

		$result = $this->service->sanitize_config( $config );

		$this->assertEquals( array(), $result['visibility'] );
	}

	// =========================================================================
	// merge_with_defaults() Tests
	// =========================================================================

	/**
	 * Test merge_with_defaults adds missing components to order.
	 *
	 * @return void
	 */
	public function test_merge_with_defaults_adds_missing_order(): void {
		$config = array(
			'order'      => array( 'header' ),
			'visibility' => array( 'header' => true ),
		);

		$result = $this->service->merge_with_defaults( $config );

		// Should have all components in order.
		$this->assertCount( 6, $result['order'] );
		$this->assertEquals( 'header', $result['order'][0] );
	}

	/**
	 * Test merge_with_defaults preserves custom order.
	 *
	 * @return void
	 */
	public function test_merge_with_defaults_preserves_custom_order(): void {
		$config = array(
			'order'      => array( 'description', 'header' ),
			'visibility' => array(),
		);

		$result = $this->service->merge_with_defaults( $config );

		// Custom order items should be first.
		$this->assertEquals( 'description', $result['order'][0] );
		$this->assertEquals( 'header', $result['order'][1] );
	}

	/**
	 * Test merge_with_defaults adds missing visibility.
	 *
	 * @return void
	 */
	public function test_merge_with_defaults_adds_missing_visibility(): void {
		$config = array(
			'order'      => array( 'header' ),
			'visibility' => array( 'header' => false ),
		);

		$result = $this->service->merge_with_defaults( $config );

		// All components should have visibility.
		foreach ( array_keys( LayoutService::COMPONENTS ) as $id ) {
			$this->assertArrayHasKey( $id, $result['visibility'] );
		}
	}

	/**
	 * Test merge_with_defaults overrides visibility with custom value.
	 *
	 * @return void
	 */
	public function test_merge_with_defaults_overrides_visibility(): void {
		$config = array(
			'order'      => array( 'header' ),
			'visibility' => array( 'header' => false ),
		);

		$result = $this->service->merge_with_defaults( $config );

		$this->assertFalse( $result['visibility']['header'] );
	}

	// =========================================================================
	// get_global_default() Tests
	// =========================================================================

	/**
	 * Test get_global_default returns null when no settings.
	 *
	 * @return void
	 */
	public function test_get_global_default_returns_null_when_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$result = $this->service->get_global_default();

		$this->assertNull( $result );
	}

	/**
	 * Test get_global_default returns null when event_layout not set.
	 *
	 * @return void
	 */
	public function test_get_global_default_returns_null_when_not_set(): void {
		Functions\when( 'get_option' )->justReturn( array( 'other_setting' => 'value' ) );

		$result = $this->service->get_global_default();

		$this->assertNull( $result );
	}

	/**
	 * Test get_global_default returns layout config.
	 *
	 * @return void
	 */
	public function test_get_global_default_returns_layout(): void {
		$layout = array(
			'order'      => array( 'header' ),
			'visibility' => array( 'header' => true ),
		);

		Functions\when( 'get_option' )->justReturn( array( 'event_layout' => $layout ) );

		$result = $this->service->get_global_default();

		$this->assertEquals( $layout, $result );
	}

	/**
	 * Test get_global_default returns null for non-array layout.
	 *
	 * @return void
	 */
	public function test_get_global_default_returns_null_for_non_array(): void {
		Functions\when( 'get_option' )->justReturn( array( 'event_layout' => 'invalid' ) );

		$result = $this->service->get_global_default();

		$this->assertNull( $result );
	}

	// =========================================================================
	// save_global_default() Tests
	// =========================================================================

	/**
	 * Test save_global_default returns false for invalid config.
	 *
	 * @return void
	 */
	public function test_save_global_default_rejects_invalid(): void {
		$config = array( 'invalid' => 'config' );

		$result = $this->service->save_global_default( $config );

		$this->assertFalse( $result );
	}

	/**
	 * Test save_global_default calls update_option.
	 *
	 * @return void
	 */
	public function test_save_global_default_calls_update_option(): void {
		$config = array(
			'order'      => array( 'header' ),
			'visibility' => array( 'header' => true ),
		);

		Functions\when( 'get_option' )->justReturn( array() );

		$update_called = false;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$update_called ) {
				$update_called = true;
				return true;
			}
		);

		$this->service->save_global_default( $config );

		$this->assertTrue( $update_called );
	}

	/**
	 * Test save_global_default sanitizes config before saving.
	 *
	 * @return void
	 */
	public function test_save_global_default_sanitizes_config(): void {
		$config = array(
			'order'      => array( 'header', 'invalid' ),
			'visibility' => array( 'header' => true, 'invalid' => false ),
		);

		// Test sanitize_config directly - already tested separately.
		$sanitized = $this->service->sanitize_config( $config );

		$this->assertEquals( array( 'header' ), $sanitized['order'] );
		$this->assertArrayNotHasKey( 'invalid', $sanitized['visibility'] );
	}

	// =========================================================================
	// get_layout() Tests
	// =========================================================================

	/**
	 * Test get_layout uses event config when valid.
	 *
	 * @return void
	 */
	public function test_get_layout_uses_event_config(): void {
		$event_config = array(
			'order'      => array( 'description', 'header' ),
			'visibility' => array( 'header' => false, 'description' => true ),
		);

		$result = $this->service->get_layout( $event_config );

		// Should use event config with first items.
		$this->assertEquals( 'description', $result['order'][0] );
		$this->assertEquals( 'header', $result['order'][1] );
	}

	/**
	 * Test get_layout falls back to global when event invalid.
	 *
	 * @return void
	 */
	public function test_get_layout_falls_back_to_global(): void {
		$global_config = array(
			'order'      => array( 'featured_image', 'header' ),
			'visibility' => array( 'featured_image' => true, 'header' => true ),
		);

		Functions\when( 'get_option' )->justReturn( array( 'event_layout' => $global_config ) );

		$result = $this->service->get_layout( null );

		$this->assertEquals( 'featured_image', $result['order'][0] );
	}

	/**
	 * Test get_layout falls back to hardcoded when both invalid.
	 *
	 * @return void
	 */
	public function test_get_layout_falls_back_to_hardcoded(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$result = $this->service->get_layout( null );

		$this->assertEquals( LayoutService::DEFAULT_ORDER, $result['order'] );
	}

	/**
	 * Test get_layout with empty array uses global.
	 *
	 * @return void
	 */
	public function test_get_layout_empty_array_uses_global(): void {
		$global_config = array(
			'order'      => array( 'description' ),
			'visibility' => array( 'description' => true ),
		);

		Functions\when( 'get_option' )->justReturn( array( 'event_layout' => $global_config ) );

		$result = $this->service->get_layout( array() );

		$this->assertEquals( 'description', $result['order'][0] );
	}

	// =========================================================================
	// get_visible_components() Tests
	// =========================================================================

	/**
	 * Test get_visible_components returns only visible.
	 *
	 * @return void
	 */
	public function test_get_visible_components_filters_hidden(): void {
		$config = array(
			'order'      => array( 'header', 'description', 'featured_image' ),
			'visibility' => array(
				'header'         => true,
				'description'    => false,
				'featured_image' => true,
			),
		);

		$result = $this->service->get_visible_components( $config );

		$this->assertContains( 'header', $result );
		$this->assertContains( 'featured_image', $result );
		$this->assertNotContains( 'description', $result );
	}

	/**
	 * Test get_visible_components preserves order.
	 *
	 * @return void
	 */
	public function test_get_visible_components_preserves_order(): void {
		$config = array(
			'order'      => array( 'featured_image', 'header' ),
			'visibility' => array(
				'header'         => true,
				'featured_image' => true,
			),
		);

		$result = array_values( $this->service->get_visible_components( $config ) );

		$this->assertEquals( 'featured_image', $result[0] );
		$this->assertEquals( 'header', $result[1] );
	}

	// =========================================================================
	// get_components() Tests
	// =========================================================================

	/**
	 * Test get_components returns component registry.
	 *
	 * @return void
	 */
	public function test_get_components_returns_registry(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$result = $this->service->get_components();

		$this->assertEquals( LayoutService::COMPONENTS, $result );
	}

	/**
	 * Test get_components applies filter.
	 *
	 * @return void
	 */
	public function test_get_components_applies_filter(): void {
		$filter_called = false;
		$filter_name   = '';

		Functions\when( 'apply_filters' )->alias(
			function ( $name, $value ) use ( &$filter_called, &$filter_name ) {
				$filter_called = true;
				$filter_name   = $name;
				return $value;
			}
		);

		$this->service->get_components();

		$this->assertTrue( $filter_called );
		$this->assertEquals( 'nettertech_events_layout_components', $filter_name );
	}

	// =========================================================================
	// get_preview_config() Tests
	// =========================================================================

	/**
	 * Test get_preview_config returns null without query param.
	 *
	 * @return void
	 */
	public function test_get_preview_config_null_without_param(): void {
		unset( $_GET['nettertech_events_preview_layout'] );

		$result = $this->service->get_preview_config();

		$this->assertNull( $result );
	}

	/**
	 * Test get_preview_config returns null without capability.
	 *
	 * @return void
	 */
	public function test_get_preview_config_null_without_capability(): void {
		$_GET['nettertech_events_preview_layout'] = 'test';

		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $this->service->get_preview_config();

		$this->assertNull( $result );
	}

	/**
	 * Test get_preview_config returns null for invalid base64.
	 *
	 * @return void
	 */
	public function test_get_preview_config_null_for_invalid_base64(): void {
		$_GET['nettertech_events_preview_layout'] = '!!!invalid!!!';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$result = $this->service->get_preview_config();

		$this->assertNull( $result );
	}

	/**
	 * Test get_preview_config returns config for valid input.
	 *
	 * @return void
	 */
	public function test_get_preview_config_returns_valid_config(): void {
		$config = array(
			'order'      => array( 'header' ),
			'visibility' => array( 'header' => true ),
		);

		$_GET['nettertech_events_preview_layout'] = base64_encode( wp_json_encode( $config ) );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = $this->service->get_preview_config();

		$this->assertIsArray( $result );
		$this->assertEquals( array( 'header' ), $result['order'] );
	}

	/**
	 * Test get_preview_config returns null for invalid JSON.
	 *
	 * @return void
	 */
	public function test_get_preview_config_null_for_invalid_json(): void {
		$_GET['nettertech_events_preview_layout'] = base64_encode( 'not valid json' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$result = $this->service->get_preview_config();

		$this->assertNull( $result );
	}

	/**
	 * Test get_preview_config returns null for invalid config structure.
	 *
	 * @return void
	 */
	public function test_get_preview_config_null_for_invalid_structure(): void {
		$config = array( 'invalid' => 'structure' );

		$_GET['nettertech_events_preview_layout'] = base64_encode( wp_json_encode( $config ) );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = $this->service->get_preview_config();

		$this->assertNull( $result );
	}

	// =========================================================================
	// encode_for_preview() Tests
	// =========================================================================

	/**
	 * Test encode_for_preview returns base64 string.
	 *
	 * @return void
	 */
	public function test_encode_for_preview_returns_base64(): void {
		$config = array(
			'order'      => array( 'header' ),
			'visibility' => array( 'header' => true ),
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = $this->service->encode_for_preview( $config );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test encode_for_preview is reversible.
	 *
	 * @return void
	 */
	public function test_encode_for_preview_is_reversible(): void {
		$config = array(
			'order'      => array( 'header', 'description' ),
			'visibility' => array( 'header' => true, 'description' => false ),
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$encoded = $this->service->encode_for_preview( $config );
		$decoded = json_decode( base64_decode( $encoded ), true );

		$this->assertEquals( $config, $decoded );
	}

	// =========================================================================
	// Cleanup
	// =========================================================================

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_GET['nettertech_events_preview_layout'] );
		parent::tearDown();
	}
}
