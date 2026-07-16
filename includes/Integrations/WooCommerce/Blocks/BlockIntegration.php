<?php
/**
 * WooCommerce Blocks Integration.
 *
 * Implements IntegrationInterface to register frontend scripts
 * for rendering event ticket data in Block-based Cart and Checkout.
 *
 * @package NetterTechEvents\Integrations\WooCommerce\Blocks
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce\Blocks;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Registers nettertech-events scripts and data for WC Blocks.
 *
 * Uses IIFE pattern (no build step) consistent with the plugin's
 * existing Gutenberg block architecture.
 *
 * @since 1.1.0
 */
class BlockIntegration implements IntegrationInterface {

	/**
	 * Integration name.
	 */
	private const NAME = 'nettertech-events';

	/**
	 * Script handle for the frontend integration.
	 */
	private const SCRIPT_HANDLE = 'nettertech-events-wc-blocks';

	/**
	 * Whether donations are enabled.
	 *
	 * @var bool
	 */
	private bool $donations_enabled;

	/**
	 * Constructor.
	 *
	 * @param bool $donations_enabled Whether donations feature is enabled.
	 */
	public function __construct( bool $donations_enabled = false ) {
		$this->donations_enabled = $donations_enabled;
	}

	/**
	 * Get the integration name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Initialize the integration.
	 *
	 * Registers the frontend script that renders event ticket metadata
	 * in Block cart/checkout via slot fills.
	 *
	 * @return void
	 */
	public function initialize(): void {
		$script_path = NETTERTECH_EVENTS_PLUGIN_DIR . 'assets/js/blocks/nte-wc-blocks.js';
		$script_url  = NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/blocks/nte-wc-blocks.js';

		// Use file modification time for cache busting in development.
		$version = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG
			? (string) filemtime( $script_path )
			: NETTERTECH_EVENTS_VERSION;

		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_url,
			array(
				'wp-element',
				'wp-i18n',
				'wp-html-entities',
				'wc-blocks-checkout',
			),
			$version,
			true
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'nettertech-events',
			NETTERTECH_EVENTS_PLUGIN_DIR . 'languages'
		);
	}

	/**
	 * Get frontend script handles.
	 *
	 * @return string[]
	 */
	public function get_script_handles(): array {
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Get editor script handles.
	 *
	 * No editor scripts needed — this integration only affects the frontend.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles(): array {
		return array();
	}

	/**
	 * Get data to pass to client-side scripts.
	 *
	 * Available in JS via `wc.wcSettings.getSetting('nettertech-events_data')`.
	 *
	 * @return array<string, mixed>
	 */
	public function get_script_data(): array {
		return array(
			'namespace'        => StoreApiExtension::NAMESPACE,
			'donationsEnabled' => $this->donations_enabled,
		);
	}
}
