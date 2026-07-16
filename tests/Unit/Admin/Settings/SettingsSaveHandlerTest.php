<?php
/**
 * SettingsSaveHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Settings\SettingsSaveHandler;
use NetterTechEvents\Admin\Settings\SettingsSectionInterface;
use NetterTechEvents\Admin\SettingsSanitizer;

/**
 * Test SettingsSaveHandler class.
 *
 * Covers nonce/capability guards and the maybe_handle_save dispatch. Uses
 * the real (final) SettingsSanitizer instance and intercepts wp_safe_redirect
 * to halt before exit().
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\SettingsSaveHandler
 */
class SettingsSaveHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Real sanitizer.
	 *
	 * @var SettingsSanitizer
	 */
	private SettingsSanitizer $sanitizer;

	/**
	 * Core tab slugs map.
	 *
	 * @var array<string, true>
	 */
	private array $core_tabs;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'absint' )->alias(
			static function ( $v ) {
				return abs( (int) $v );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( '__' )->returnArg();

		$this->sanitizer = new SettingsSanitizer();
		$this->core_tabs = array(
			'general'  => true,
			'advanced' => true,
		);
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		unset(
			$_POST['nettertech_events_settings_nonce'],
			$_POST['nettertech_events_settings'],
			$_POST['nettertech_events_active_tab'],
			$_POST['nettertech_events_email_settings']
		);
		parent::tearDown();
	}

	/**
	 * Build a handler with default resolvers.
	 *
	 * @param array<SettingsSectionInterface> $sections Sections for the active tab.
	 * @return SettingsSaveHandler
	 */
	private function build_handler( array $sections = array() ): SettingsSaveHandler {
		$sections_for_tab = static fn( string $tab ) => $sections;
		$tabs_resolver    = fn() => array_merge(
			$this->core_tabs,
			array(
				'general' => 'General',
				'ext-tab' => 'Ext',
			)
		);

		return new SettingsSaveHandler(
			$this->sanitizer,
			$this->core_tabs,
			$sections_for_tab,
			$tabs_resolver
		);
	}

	/**
	 * Common WP function stubs for entering the save pipeline.
	 *
	 * @return void
	 */
	private function stub_save_pipeline_environment(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'map_deep' )->alias( static fn( $value, $callback ) => $value );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'add_query_arg' )->alias( static fn( $a, $u ) => $u );
		Functions\when( 'admin_url' )->justReturn( '/wp-admin/admin.php' );
		Functions\when( 'do_action' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function () {
				throw new \RuntimeException( 'redirect-intercepted' );
			}
		);
	}

	/**
	 * Test constructor stores dependencies.
	 *
	 * @return void
	 */
	public function test_constructor_assigns_dependencies(): void {
		$handler = $this->build_handler();
		$this->assertInstanceOf( SettingsSaveHandler::class, $handler );
	}

	/**
	 * Test maybe_handle_save returns early when nonce field absent.
	 *
	 * @return void
	 */
	public function test_maybe_handle_save_returns_when_nonce_absent(): void {
		$handler = $this->build_handler();
		$handler->maybe_handle_save();
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_handle_save returns early when nonce is invalid.
	 *
	 * @return void
	 */
	public function test_maybe_handle_save_returns_when_nonce_invalid(): void {
		$_POST['nettertech_events_settings_nonce'] = 'bad-nonce';
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$handler = $this->build_handler();
		$handler->maybe_handle_save();
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_handle_save returns early when user lacks capability.
	 *
	 * @return void
	 */
	public function test_maybe_handle_save_returns_when_user_lacks_cap(): void {
		$_POST['nettertech_events_settings_nonce'] = 'good';
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$handler = $this->build_handler();
		$handler->maybe_handle_save();
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_handle_save proceeds when guards pass.
	 *
	 * @return void
	 */
	public function test_maybe_handle_save_proceeds_when_guards_pass(): void {
		$_POST['nettertech_events_settings_nonce'] = 'good';
		$_POST['nettertech_events_settings']       = array();
		$_POST['nettertech_events_active_tab']     = 'general';

		$this->stub_save_pipeline_environment();

		$updated_with = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$updated_with ) {
				if ( 'nettertech_events_settings' === $key ) {
					$updated_with = $value;
				}
				return true;
			}
		);

		$handler = $this->build_handler();
		try {
			$handler->maybe_handle_save();
			$this->fail( 'Expected redirect intercept.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect-intercepted', $e->getMessage() );
		}

		$this->assertIsArray( $updated_with );
	}

	/**
	 * Test SETTINGS_UPDATED fires with the changed keys on a differing save,
	 * and does not fire when a second identical save changes nothing.
	 *
	 * @return void
	 */
	public function test_save_fires_settings_updated_only_when_values_change(): void {
		$this->stub_save_pipeline_environment();

		$updated_with = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$updated_with ) {
				if ( 'nettertech_events_settings' === $key ) {
					$updated_with = $value;
				}
				return true;
			}
		);

		$fired_keys = 'not-fired';
		Functions\when( 'do_action' )->alias(
			static function ( $hook, $arg = null ) use ( &$fired_keys ) {
				if ( 'nettertech_events_settings_updated' === $hook ) {
					$fired_keys = $arg;
				}
				return true;
			}
		);

		// First save: current settings empty, so every persisted key counts
		// as changed and the hook must fire with exactly those keys.
		$_POST['nettertech_events_settings_nonce'] = 'good';
		$_POST['nettertech_events_settings']       = array();
		$_POST['nettertech_events_active_tab']     = 'general';

		$handler = $this->build_handler();
		try {
			$handler->maybe_handle_save();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertIsArray( $updated_with );
		$this->assertNotEmpty( $updated_with );
		$this->assertSame( array_keys( $updated_with ), $fired_keys );

		// Second save: current settings identical to what will be persisted,
		// so nothing changed and the hook must not fire.
		$persisted = $updated_with;
		Functions\when( 'get_option' )->justReturn( $persisted );

		$fired_keys = 'not-fired';

		$_POST['nettertech_events_settings_nonce'] = 'good';
		$_POST['nettertech_events_settings']       = array();
		$_POST['nettertech_events_active_tab']     = 'general';

		$handler = $this->build_handler();
		try {
			$handler->maybe_handle_save();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertSame( 'not-fired', $fired_keys, 'SETTINGS_UPDATED must not fire when no value changed.' );
	}

	/**
	 * Test extension tab fires extension save action.
	 *
	 * @return void
	 */
	public function test_save_fires_extension_action_for_non_core_tab(): void {
		$_POST['nettertech_events_settings_nonce'] = 'good';
		$_POST['nettertech_events_settings']       = array();
		$_POST['nettertech_events_active_tab']     = 'ext-tab';

		$this->stub_save_pipeline_environment();

		$fired = null;
		Functions\when( 'do_action' )->alias(
			static function ( $hook, $arg = null ) use ( &$fired ) {
				if ( 'nettertech_events_settings_tab_save' === $hook ) {
					$fired = $arg;
				}
				return true;
			}
		);

		$handler = $this->build_handler();
		try {
			$handler->maybe_handle_save();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertSame( 'ext-tab', $fired );
	}

	/**
	 * Test core tab does NOT fire extension save action.
	 *
	 * @return void
	 */
	public function test_save_does_not_fire_extension_action_for_core_tab(): void {
		$_POST['nettertech_events_settings_nonce'] = 'good';
		$_POST['nettertech_events_settings']       = array();
		$_POST['nettertech_events_active_tab']     = 'general';

		$this->stub_save_pipeline_environment();

		$fired = false;
		Functions\when( 'do_action' )->alias(
			static function ( $hook ) use ( &$fired ) {
				if ( 'nettertech_events_settings_tab_save' === $hook ) {
					$fired = true;
				}
				return true;
			}
		);

		$handler = $this->build_handler();
		try {
			$handler->maybe_handle_save();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertFalse( $fired );
	}

	/**
	 * Test sections on the active tab are invoked.
	 *
	 * @return void
	 */
	public function test_save_invokes_section_save_methods(): void {
		$_POST['nettertech_events_settings_nonce'] = 'good';
		$_POST['nettertech_events_settings']       = array();
		$_POST['nettertech_events_active_tab']     = 'general';

		$this->stub_save_pipeline_environment();

		$section = Mockery::mock( SettingsSectionInterface::class );
		$section->shouldReceive( 'get_bool_fields' )->andReturn( array() );
		$section->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				static function ( array $input, array $settings ) {
					$settings['section_saved'] = true;
					return $settings;
				}
			);

		$handler = $this->build_handler( array( $section ) );
		try {
			$handler->maybe_handle_save();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertTrue( true );
	}

	/**
	 * Test email settings input is namespaced into _email_settings_post.
	 *
	 * @return void
	 */
	public function test_email_settings_post_is_namespaced(): void {
		$_POST['nettertech_events_settings_nonce'] = 'good';
		$_POST['nettertech_events_settings']       = array();
		$_POST['nettertech_events_email_settings'] = array( 'subject' => 'Hi' );
		$_POST['nettertech_events_active_tab']     = 'general';

		$this->stub_save_pipeline_environment();

		$received_input = null;
		$section        = Mockery::mock( SettingsSectionInterface::class );
		$section->shouldReceive( 'get_bool_fields' )->andReturn( array() );
		$section->shouldReceive( 'save' )
			->andReturnUsing(
				static function ( array $input, array $settings ) use ( &$received_input ) {
					$received_input = $input;
					return $settings;
				}
			);

		$handler = $this->build_handler( array( $section ) );
		try {
			$handler->maybe_handle_save();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$this->assertIsArray( $received_input );
		$this->assertArrayHasKey( '_email_settings_post', $received_input );
		$this->assertSame( 'Hi', $received_input['_email_settings_post']['subject'] );
	}

	/**
	 * Test invalid active tab in redirect falls back to general.
	 *
	 * Indirectly verified by ensuring the handler still completes without throwing
	 * beyond the redirect intercept.
	 *
	 * @return void
	 */
	public function test_invalid_active_tab_redirects_to_general(): void {
		$_POST['nettertech_events_settings_nonce'] = 'good';
		$_POST['nettertech_events_settings']       = array();
		$_POST['nettertech_events_active_tab']     = 'nonsense-tab';

		$this->stub_save_pipeline_environment();

		$captured = null;
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) use ( &$captured ) {
				$captured = $args;
				return $url;
			}
		);

		$handler = $this->build_handler();
		try {
			$handler->maybe_handle_save();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// Should have been re-mapped to general since 'nonsense-tab' isn't in the tabs map.
		$this->assertSame( 'general', $captured['tab'] );
	}
}
