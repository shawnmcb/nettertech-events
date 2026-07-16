<?php
/**
 * BlockIntegration unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce\Blocks
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WC Blocks interface stub for unit testing.

namespace Automattic\WooCommerce\Blocks\Integrations {
	if ( ! interface_exists( IntegrationInterface::class ) ) {
		/**
		 * Stub for WooCommerce Blocks IntegrationInterface.
		 */
		interface IntegrationInterface {
			/**
			 * @return string
			 */
			public function get_name(): string;

			/**
			 * @return void
			 */
			public function initialize(): void;

			/**
			 * @return string[]
			 */
			public function get_script_handles(): array;

			/**
			 * @return string[]
			 */
			public function get_editor_script_handles(): array;

			/**
			 * @return array<string, mixed>
			 */
			public function get_script_data(): array;
		}
	}
}

// phpcs:enable

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce\Blocks {

use Brain\Monkey\Functions;
use NetterTechEvents\Integrations\WooCommerce\Blocks\BlockIntegration;

/**
 * Test BlockIntegration (IntegrationInterface implementation).
 *
 * @covers \NetterTechEvents\Integrations\WooCommerce\Blocks\BlockIntegration
 */
class BlockIntegrationTest extends \NetterTechEventsTestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NETTERTECH_EVENTS_PLUGIN_DIR' ) ) {
			define( 'NETTERTECH_EVENTS_PLUGIN_DIR', '/tmp/nettertech-events/' );
		}
		if ( ! defined( 'NETTERTECH_EVENTS_PLUGIN_URL' ) ) {
			define( 'NETTERTECH_EVENTS_PLUGIN_URL', 'https://example.com/wp-content/plugins/nettertech-events/' );
		}
		if ( ! defined( 'NETTERTECH_EVENTS_VERSION' ) ) {
			define( 'NETTERTECH_EVENTS_VERSION', '1.1.0' );
		}
	}

	// =========================================================================
	// Interface Contract
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_get_name_returns_nettertech_events(): void {
		$integration = new BlockIntegration();

		$this->assertSame( 'nettertech-events', $integration->get_name() );
	}

	/**
	 * @return void
	 */
	public function test_get_script_handles_returns_expected_handle(): void {
		$integration = new BlockIntegration();

		$handles = $integration->get_script_handles();

		$this->assertCount( 1, $handles );
		$this->assertSame( 'nettertech-events-wc-blocks', $handles[0] );
	}

	/**
	 * @return void
	 */
	public function test_get_editor_script_handles_returns_empty(): void {
		$integration = new BlockIntegration();

		$this->assertSame( array(), $integration->get_editor_script_handles() );
	}

	// =========================================================================
	// Script Data
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_get_script_data_contains_namespace(): void {
		$integration = new BlockIntegration();

		$data = $integration->get_script_data();

		$this->assertArrayHasKey( 'namespace', $data );
		$this->assertSame( 'nettertech-events', $data['namespace'] );
	}

	/**
	 * @return void
	 */
	public function test_get_script_data_reflects_donations_enabled(): void {
		$integration = new BlockIntegration( true );

		$data = $integration->get_script_data();

		$this->assertTrue( $data['donationsEnabled'] );
	}

	/**
	 * @return void
	 */
	public function test_get_script_data_reflects_donations_disabled(): void {
		$integration = new BlockIntegration( false );

		$data = $integration->get_script_data();

		$this->assertFalse( $data['donationsEnabled'] );
	}

	// =========================================================================
	// Initialize
	// =========================================================================

	/**
	 * @return void
	 */
	public function test_initialize_registers_script(): void {
		$registered = false;

		Functions\when( 'wp_register_script' )->alias(
			function ( $handle, $src, $deps ) use ( &$registered ) {
				$registered = true;
				$this->assertSame( 'nettertech-events-wc-blocks', $handle );
				$this->assertContains( 'wp-element', $deps );
				$this->assertContains( 'wc-blocks-checkout', $deps );
				$this->assertContains( 'wp-i18n', $deps );
			}
		);

		Functions\when( 'wp_set_script_translations' )->justReturn( true );

		$integration = new BlockIntegration();
		$integration->initialize();

		$this->assertTrue( $registered, 'wp_register_script should have been called' );
	}

	/**
	 * @return void
	 */
	public function test_initialize_sets_translations(): void {
		$translations_set = false;

		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_set_script_translations' )->alias(
			function ( $handle, $domain ) use ( &$translations_set ) {
				$translations_set = true;
				$this->assertSame( 'nettertech-events-wc-blocks', $handle );
				$this->assertSame( 'nettertech-events', $domain );
			}
		);

		$integration = new BlockIntegration();
		$integration->initialize();

		$this->assertTrue( $translations_set, 'wp_set_script_translations should have been called' );
	}
}
} // End namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce\Blocks.
