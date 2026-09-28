<?php
/**
 * Plugin Name: NetterTech Events
 * Plugin URI: https://nettertech.com/plugins/events/
 * Description: A clean, performant WordPress events plugin with ticketing, recurring events, and WooCommerce integration.
 * Version: 1.4.9
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: NetterTech
 * Author URI: https://nettertech.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nettertech-events
 * Domain Path: /languages
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'NETTERTECH_EVENTS_VERSION', '1.4.9' );
define( 'NETTERTECH_EVENTS_PLUGIN_FILE', __FILE__ );
define( 'NETTERTECH_EVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NETTERTECH_EVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NETTERTECH_EVENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader.
if ( file_exists( NETTERTECH_EVENTS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once NETTERTECH_EVENTS_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	// Simple PSR-4 autoloader for development without Composer.
	spl_autoload_register(
		function ( string $class ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- PSR-4 autoloader convention.
			$prefix   = 'NetterTechEvents\\';
			$base_dir = NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/';

			$len = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
					return;
			}

			$relative_class = substr( $class, $len );
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

/**
 * Returns the main plugin instance.
 *
 * @return Core\Plugin
 */
function nettertech_events(): Core\Plugin {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Core\Plugin();
	}

	return $instance;
}

/**
 * Returns the DI container for service resolution.
 *
 * Intended for use in templates and view files where constructor
 * injection is not possible. Business logic classes should use
 * constructor injection instead.
 *
 * @since 2.0.0
 * @see ADR-013
 *
 * @return Core\Container
 */
function nettertech_events_container(): Core\Container {
	return nettertech_events()->get_container();
}

// Activation hook.
register_activation_hook(
	__FILE__,
	function (): void {
		require_once NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/Core/Activator.php';
		Core\Activator::activate();
	}
);

// Deactivation hook.
register_deactivation_hook(
	__FILE__,
	function (): void {
		require_once NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/Core/Deactivator.php';
		Core\Deactivator::deactivate();
	}
);

// Declare WooCommerce feature compatibility.
add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			// HPOS (High-Performance Order Storage) compatibility.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);

			// Cart/Checkout Blocks compatibility.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				true
			);
		}
	}
);

// Load deprecated function stubs (opt-in only).
if ( defined( 'NETTERTECH_EVENTS_ENABLE_DEPRECATED' ) && NETTERTECH_EVENTS_ENABLE_DEPRECATED ) {
	require_once NETTERTECH_EVENTS_PLUGIN_DIR . 'deprecated/functions.php';
}

// Initialize plugin. Priority 10 (default) — all satellite plugins load after this.
// See docs/CROSS-PLUGIN-MATRIX.md for load order rationale.
add_action(
	'plugins_loaded',
	function (): void {
		nettertech_events()->init();
	},
	10
);
