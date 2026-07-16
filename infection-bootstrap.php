<?php
/**
 * Infection bootstrap file.
 *
 * Provides WordPress class stubs that Infection's reflection phase needs
 * when generating mutants for classes that extend WordPress base classes.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

// Every source file carries a `defined( 'ABSPATH' ) || exit;` guard (WordPress.org
// compliance). Infection autoloads those files while generating mutants, in a
// process that is not WordPress, so without ABSPATH defined here the guard runs a
// bare exit mid-generation: status 0, no mutants, no logs, gate silently satisfied.
// Mirrors the definition in tests/bootstrap.php.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Stub for Infection.
	class WP_List_Table {
		public $items = array();

		public function __construct( $args = array() ) {
			// Stub constructor.
		}
	}
}

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Stub for Infection.
	abstract class WP_REST_Controller {
		protected $namespace;
		protected $rest_base;

		public function get_endpoint_args_for_item_schema( $method = 'GET' ) {
			return array();
		}

		public function get_public_item_schema() {
			return $this->get_item_schema();
		}

		public function get_item_schema() {
			return array();
		}

		public function set_status( $status ) {
			// Stub method.
		}
	}
}
