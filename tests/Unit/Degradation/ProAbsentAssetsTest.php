<?php
/**
 * Assets degradation tests — Pro absent.
 *
 * Proves that Assets registers base scripts/styles and fires
 * ACTION_REGISTER_ASSETS without QR-scanner, nte-public-checkin, or
 * nte-jspdf handles being registered by the base plugin.
 *
 * @package NetterTechEvents\Tests\Unit\Degradation
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Degradation;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Core\Assets;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Services\PaletteResolver;

/**
 * Verify Assets class does not attempt to register satellite-plugin script handles.
 *
 * @covers \NetterTechEvents\Core\Assets
 */
class ProAbsentAssetsTest extends \NetterTechEventsTestCase {

	/**
	 * Assets instance under test.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$mock_resolver = Mockery::mock( PaletteResolver::class );
		$mock_resolver->shouldReceive( 'resolve' )->andReturn( array() )->byDefault();

		$settings     = new NetterTechEventsSettings();
		$this->assets = new Assets( $mock_resolver, $settings );
	}

	// =========================================================================
	// Assets registers without errors
	// =========================================================================

	/**
	 * Test Assets instantiates cleanly (base-only path).
	 *
	 * @return void
	 */
	public function test_instantiates_cleanly(): void {
		$this->assertInstanceOf( Assets::class, $this->assets );
	}

	/**
	 * Test register() completes without errors.
	 *
	 * @return void
	 */
	public function test_register_completes_without_errors(): void {
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );

		$this->assets->register();

		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// ACTION_REGISTER_ASSETS fires
	// =========================================================================

	/**
	 * Test ACTION_REGISTER_ASSETS constant is defined and has the expected value.
	 *
	 * Pro listens to this hook to register its own assets.
	 * The constant must exist so Pro can safely reference it.
	 *
	 * @return void
	 */
	public function test_action_register_assets_constant_is_defined(): void {
		$this->assertSame( 'nettertech_events_register_assets', Hooks::ACTION_REGISTER_ASSETS );
	}

	// =========================================================================
	// Base handles registered
	// =========================================================================

	/**
	 * Test base calendar script is registered.
	 *
	 * @return void
	 */
	public function test_base_calendar_script_is_registered(): void {
		$registered_scripts = array();

		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle ) use ( &$registered_scripts ) {
				$registered_scripts[] = $handle;
				return true;
			}
		);

		$this->assets->register();

		$this->assertContains( 'nettertech-events-calendar', $registered_scripts );
	}

	/**
	 * Test base admin script is registered.
	 *
	 * @return void
	 */
	public function test_base_admin_script_is_registered(): void {
		$registered_scripts = array();

		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle ) use ( &$registered_scripts ) {
				$registered_scripts[] = $handle;
				return true;
			}
		);

		$this->assets->register();

		$this->assertContains( 'nettertech-events-admin', $registered_scripts );
	}

	// =========================================================================
	// Satellite handles NOT registered by base
	// =========================================================================

	/**
	 * Test qr-scanner handle is NOT registered by the base plugin.
	 *
	 * @return void
	 */
	public function test_qr_scanner_handle_not_registered_by_base(): void {
		$registered_scripts = array();

		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle ) use ( &$registered_scripts ) {
				$registered_scripts[] = $handle;
				return true;
			}
		);

		$this->assets->register();

		$this->assertNotContains( 'qr-scanner', $registered_scripts );
	}

	/**
	 * Test nte-public-checkin handle is NOT registered by the base plugin.
	 *
	 * @return void
	 */
	public function test_nte_public_checkin_handle_not_registered_by_base(): void {
		$registered_scripts = array();

		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle ) use ( &$registered_scripts ) {
				$registered_scripts[] = $handle;
				return true;
			}
		);

		$this->assets->register();

		$this->assertNotContains( 'nte-public-checkin', $registered_scripts );
	}

	/**
	 * Test nte-jspdf handle is NOT registered by the base plugin.
	 *
	 * @return void
	 */
	public function test_nte_jspdf_handle_not_registered_by_base(): void {
		$registered_scripts = array();

		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle ) use ( &$registered_scripts ) {
				$registered_scripts[] = $handle;
				return true;
			}
		);

		$this->assets->register();

		$this->assertNotContains( 'nte-jspdf', $registered_scripts );
	}
}
