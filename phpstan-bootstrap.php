<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants and stubs needed for static analysis.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

// Direct-access guard. This file is loaded only by PHPStan in CLI mode; the
// guard satisfies Plugin Check while preserving the bootstrap's ability to
// define ABSPATH for static analysis (which runs without a WordPress context).
if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/' );

// Plugin constants — must match the names defined in nettertech-events.php so
// PHPStan analyses code that reads them. Values are stub-only (PHPStan never
// runs the plugin); align the version with the main file header on every bump.
define( 'NETTERTECH_EVENTS_VERSION', '1.0.1' );
define( 'NETTERTECH_EVENTS_PLUGIN_FILE', __DIR__ . '/nettertech-events.php' );
define( 'NETTERTECH_EVENTS_PLUGIN_DIR', __DIR__ . '/' );
define( 'NETTERTECH_EVENTS_PLUGIN_URL', 'https://example.com/wp-content/plugins/nettertech-events/' );
define( 'NETTERTECH_EVENTS_PLUGIN_BASENAME', 'nettertech-events/nettertech-events.php' );

// Yoast SEO stubs for optional integration.
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '0.0.0' );
}
if ( ! interface_exists( 'WPSEO_Sitemap_Provider' ) ) {
	interface WPSEO_Sitemap_Provider {
		/**
		 * @param string $type
		 * @return bool
		 */
		public function handles_type( $type );

		/**
		 * @param int $max_entries
		 * @return array<int, array<string, mixed>>
		 */
		public function get_index_links( $max_entries );

		/**
		 * @param string $type
		 * @param int    $max_entries
		 * @param int    $current_page
		 * @return array<int, array<string, mixed>>
		 */
		public function get_sitemap_links( $type, $max_entries, $current_page );
	}
}
if ( ! function_exists( 'wpseo_register_var_replacement' ) ) {
	/**
	 * @param string   $var
	 * @param callable $replace_function
	 * @param string   $type
	 * @param string   $help_text
	 * @return bool
	 */
	function wpseo_register_var_replacement( $var, $replace_function, $type = 'advanced', $help_text = '' ) {
		return true;
	}
}

// Rank Math SEO stubs for optional integration.
if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	define( 'RANK_MATH_VERSION', '0.0.0' );
}
if ( ! function_exists( 'rank_math_register_var_replacement' ) ) {
	/**
	 * @param string               $var
	 * @param array<string, mixed> $args
	 * @param callable             $callback
	 * @return bool
	 */
	function rank_math_register_var_replacement( $var, $args, $callback ) {
		return true;
	}
}
