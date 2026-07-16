<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package NetterTechEvents\Tests
 */

declare(strict_types=1);

// Suppress E_DEPRECATED before loading anything to prevent thecodingmachine/safe v2.x
// implicit-nullable deprecation spam (~160KB) on PHP 8.4+.
error_reporting( E_ALL & ~E_DEPRECATED );

// Redirect error_log() away from stderr. Infection's InitialTestsRunner kills its
// PHPUnit subprocess on ANY stderr output, so application-level error_log() calls
// must not reach stderr.
ini_set( 'error_log', '/dev/null' );

// NOTE: Patchwork is NOT loaded here. Loading it causes @runInSeparateProcess tests to hang
// because Patchwork's stream wrapper interferes with PHPUnit's child process IPC.
// Tests that need to mock PHP built-ins (fopen, fclose, header) should avoid
// @runInSeparateProcess and use reflection/output buffering instead.

// Load mock classes BEFORE autoloader to prevent loading real classes with WordPress dependencies.
require_once __DIR__ . '/Mocks/AdminMocks.php';

// Composer autoloader.
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $autoloader ) ) {
	echo "Composer autoloader not found. Run 'composer install' first.\n";
	exit( 1 );
}

require_once $autoloader;

// Restore E_DEPRECATED now that autoload noise is past — actual test deprecations are still caught.
error_reporting( E_ALL );

// Yoast SEO stub interface for optional integration tests.
if ( ! interface_exists( 'WPSEO_Sitemap_Provider' ) ) {
	interface WPSEO_Sitemap_Provider {
		public function handles_type( $type );
		public function get_index_links( $max_entries );
		public function get_sitemap_links( $type, $max_entries, $current_page );
	}
}

// WP_Post stub for tests that need `instanceof WP_Post` checks against the
// global `$post`. The real WP_Post is final and only declared in wordpress-stubs
// (not autoloaded at runtime), so unit tests need this lightweight equivalent.
// AllowDynamicProperties matches WP core's WP_Post which is also annotated.
if ( ! class_exists( 'WP_Post' ) ) {
	#[\AllowDynamicProperties]
	class WP_Post {
		public int $ID = 0;
		public function __construct( $post = null ) {
			if ( is_object( $post ) ) {
				foreach ( get_object_vars( $post ) as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}
}

// Propagate E_DEPRECATED suppression to @runInSeparateProcess child processes.
// Child processes are spawned by proc_open(PHP_BINARY) with no -d flags and no ini settings
// (when @preserveGlobalState is disabled). The only way to inject error_reporting before the
// autoloader loads in child processes is via PHP_INI_SCAN_DIR, which the child inherits from
// the parent's environment and reads at PHP binary startup — before any PHP code executes.
putenv( 'PHP_INI_SCAN_DIR=' . __DIR__ . '/phpunit-ini' );

// Check for Brain Monkey.
if ( ! function_exists( 'Brain\Monkey\setUp' ) ) {
	echo "Brain Monkey not found. Run 'composer require --dev brain/monkey' first.\n";
	exit( 1 );
}

// Define WordPress constants if not already defined.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

// Create mock WordPress upgrade.php file for Schema tests.
$upgrade_dir = ABSPATH . 'wp-admin/includes';
if ( ! is_dir( $upgrade_dir ) ) {
	mkdir( $upgrade_dir, 0755, true );
}
$upgrade_file = $upgrade_dir . '/upgrade.php';
if ( ! file_exists( $upgrade_file ) ) {
	file_put_contents( $upgrade_file, "<?php\n// Mock upgrade.php for tests.\n" );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// WordPress database output constants.
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}

/**
 * Mock dbDelta function for Schema tests.
 *
 * @param string|array $queries SQL statements.
 * @return array Array of results.
 */
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		// Track called queries for testing.
		global $nettertech_events_dbdelta_queries;
		if ( ! isset( $nettertech_events_dbdelta_queries ) ) {
			$nettertech_events_dbdelta_queries = array();
		}
		if ( is_array( $queries ) ) {
			$nettertech_events_dbdelta_queries = array_merge( $nettertech_events_dbdelta_queries, $queries );
		} else {
			$nettertech_events_dbdelta_queries[] = $queries;
		}
		return array();
	}
}

/**
 * Mock wpdb class for unit testing.
 *
 * Provides minimal wpdb interface for repository tests without requiring
 * actual WordPress installation.
 */
// phpcs:ignore WordPress.Classes.ClassInstantiation.MissingClass, WordPress.Classes.ClassOpeningBraceSpacing.SpaceAfterClassName, WordPress.WhiteSpace.PrecisionAlignment.Found, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- Mock class for unit tests.
class wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Last insert ID.
	 *
	 * @var int
	 */
	public $insert_id = 1;

	/**
	 * Number of rows affected by the last query.
	 *
	 * @var int
	 */
	public $rows_affected = 0;

	/**
	 * Post meta table name.
	 *
	 * @var string
	 */
	public $postmeta = 'wp_postmeta';

	/**
	 * Options table name.
	 *
	 * @var string
	 */
	public $options = 'wp_options';

	/**
	 * Term relationships table.
	 *
	 * @var string
	 */
	public $term_relationships = 'wp_term_relationships';

	/**
	 * Posts table name.
	 *
	 * @var string
	 */
	public $posts = 'wp_posts';

	/**
	 * Term taxonomy table name.
	 *
	 * @var string
	 */
	public $term_taxonomy = 'wp_term_taxonomy';

	/**
	 * User meta table name.
	 *
	 * @var string
	 */
	public $usermeta = 'wp_usermeta';

	/**
	 * Prepare a SQL query.
	 *
	 * @param string $query  Query with placeholders.
	 * @param mixed  ...$args Arguments.
	 * @return string Prepared query.
	 */
	public function prepare( string $query, ...$args ): string {
		// Flatten array if first arg is an array.
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		// Simple placeholder replacement for tests.
		$i = 0;
		return (string) preg_replace_callback(
			'/%[sdf]/',
			function () use ( $args, &$i ) {
				return isset( $args[ $i ] ) ? "'" . addslashes( (string) $args[ $i++ ] ) . "'" : "''";
			},
			$query
		);
	}

	/**
	 * Get a single row.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output type.
	 * @return object|null
	 */
	public function get_row( string $query, string $output = OBJECT ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return null;
	}

	/**
	 * Get multiple rows.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output type.
	 * @return array
	 */
	public function get_results( string $query, string $output = OBJECT ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return array();
	}

	/**
	 * Get a single variable.
	 *
	 * @param string $query SQL query.
	 * @return string|null
	 */
	public function get_var( string $query ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return '0';
	}

	/**
	 * Insert a row.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Data to insert.
	 * @param array  $format Format specifiers.
	 * @return int|false
	 */
	public function insert( string $table, array $data, array $format = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->insert_id = mt_rand( 1, 9999 );
		return 1;
	}

	/**
	 * Update a row.
	 *
	 * @param string $table        Table name.
	 * @param array  $data         Data to update.
	 * @param array  $where        Where conditions.
	 * @param array  $format       Data formats.
	 * @param array  $where_format Where formats.
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return 1;
	}

	/**
	 * Delete a row.
	 *
	 * @param string $table        Table name.
	 * @param array  $where        Where conditions.
	 * @param array  $where_format Where formats.
	 * @return int|false
	 */
	public function delete( string $table, array $where, array $where_format = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return 1;
	}

	/**
	 * Execute a query.
	 *
	 * @param string $query SQL query.
	 * @return int|bool
	 */
	public function query( string $query ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return 1;
	}

	/**
	 * Get the database charset collation.
	 *
	 * @return string Charset collation string.
	 */
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	/**
	 * Escape a string for LIKE queries.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Get a single column from the results.
	 *
	 * @param string $query  SQL query.
	 * @param int    $column Column offset.
	 * @return array<mixed>
	 */
	public function get_col( string $query, int $column = 0 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array();
	}
}

// Set up global $wpdb.
global $wpdb;
$wpdb = new wpdb();

/**
 * Mock WP_Term class.
 */
if ( ! class_exists( 'WP_Term' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WP_Term {
		public $term_id = 0;
		public $name = '';
		public $slug = '';
		public $term_group = 0;
		public $term_taxonomy_id = 0;
		public $taxonomy = '';
		public $description = '';
		public $parent = 0;
		public $count = 0;
	}
}

/**
 * Mock WP_Error class.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WP_Error {
		public $errors = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( $code ) {
				$this->errors[ $code ][]   = $message;
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			return array_key_first( $this->errors );
		}

		public function get_error_message( $code = '' ) {
			if ( ! $code ) {
				$code = $this->get_error_code();
			}
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		public function get_error_messages( $code = '' ) {
			if ( ! $code ) {
				return array_merge( ...array_values( $this->errors ) ?: array( array() ) );
			}
			return $this->errors[ $code ] ?? array();
		}

		public function has_errors() {
			return array() !== $this->errors;
		}

		public function add( $code, $message = '', $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data && array() !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}
}

/**
 * Mock WP_REST_Server class.
 */
if ( ! class_exists( 'WP_REST_Server' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WP_REST_Server {
		const READABLE   = 'GET';
		const CREATABLE  = 'POST';
		const EDITABLE   = 'PUT, PATCH';
		const DELETABLE  = 'DELETE';
		const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

/**
 * Mock WP_REST_Request class.
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WP_REST_Request {
		private $params = array();
		private $method = 'GET';

		public function __construct( $method = 'GET', $route = '' ) {
			$this->method = $method;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function get_params() {
			return $this->params;
		}

		public function get_method() {
			return $this->method;
		}

		public function has_param( $key ) {
			return array_key_exists( $key, $this->params );
		}

		public function set_body( $body ) {
			$this->body = $body;
		}

		public function get_body() {
			return $this->body ?? '';
		}
	}
}

/**
 * Mock WP_REST_Response class.
 */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WP_REST_Response {
		public $data;
		public $status;
		protected $headers = array();

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}

		public function set_status( $status ) {
			$this->status = $status;
		}

		public function header( $key, $value, $replace = true ) {
			$this->headers[ $key ] = $value;
		}

		public function get_headers() {
			return $this->headers;
		}
	}
}

/**
 * Mock WP_REST_Controller class.
 */
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
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
			// Mock method.
		}
	}
}

/**
 * Mock WP_Sitemaps_Provider abstract class for unit testing.
 */
if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	abstract class WP_Sitemaps_Provider {
		public $name        = '';
		public $object_type = '';

		abstract public function get_url_list( $page_num, $object_subtype = '' );
		abstract public function get_max_num_pages( $object_subtype = '' );

		public function get_object_subtypes() {
			return array();
		}
	}
}

/**
 * Mock WP_REST_Search_Handler abstract class for unit testing.
 */
if ( ! class_exists( 'WP_REST_Search_Handler' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	abstract class WP_REST_Search_Handler {
		protected $type = '';
		protected $subtypes = array();

		public function get_type() {
			return $this->type;
		}

		public function get_subtypes() {
			return $this->subtypes;
		}
	}
}

/**
 * Mock WP_REST_Post_Search_Handler class for unit testing.
 */
if ( ! class_exists( 'WP_REST_Post_Search_Handler' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WP_REST_Post_Search_Handler extends WP_REST_Search_Handler {
		public function __construct() {
			$this->type     = 'post';
			$this->subtypes = array( 'post', 'page' );
		}
	}
}

/**
 * Mock WC_Order class for unit testing.
 *
 * Provides minimal WC_Order interface for handler tests without WooCommerce.
 */
if ( ! class_exists( 'WC_Order' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WC_Order {
		protected $id = 0;
		protected $status = 'pending';
		protected $meta_data = array();
		protected $items = array();

		public function get_id() {
			return $this->id;
		}
		public function get_status() {
			return $this->status;
		}
		public function get_meta( $key, $single = true ) {
			return $this->meta_data[ $key ] ?? '';
		}
		public function update_meta_data( $key, $value ) {
			$this->meta_data[ $key ] = $value;
		}
		public function get_items() {
			return $this->items;
		}
		public function get_item( $item_id ) {
			return $this->items[ $item_id ] ?? null;
		}
		public function get_billing_first_name() {
			return '';
		}
		public function get_billing_last_name() {
			return '';
		}
		public function get_billing_email() {
			return '';
		}
		public function get_billing_phone() {
			return '';
		}
		public function save() {
			return $this->id;
		}
		public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) {
			return 1; // Return mock note ID.
		}
		public function set_status( $new_status, $note = '', $manual_update = false ) {
			$this->status = $new_status;
		}
		public function get_edit_order_url() {
			return '';
		}
	}
}

/**
 * Mock WC_Order_Refund class for unit testing.
 */
if ( ! class_exists( 'WC_Order_Refund' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WC_Order_Refund extends WC_Order {
		protected $parent_id = 0;

		public function get_parent_id() {
			return $this->parent_id;
		}
	}
}

/**
 * Mock WC_Order_Item base class for unit testing.
 *
 * WC_Order_Item_Product extends this, so mocks satisfy WC_Order_Item type hints.
 */
if ( ! class_exists( 'WC_Order_Item' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WC_Order_Item {
		protected $id = 0;
		protected $name = '';
		protected $meta_data = array();

		public function get_id() {
			return $this->id;
		}
		public function get_name( $context = 'view' ) {
			return $this->name;
		}
		public function get_meta( $key, $single = true ) {
			return $this->meta_data[ $key ] ?? '';
		}
		public function add_meta_data( $key, $value, $unique = false ) {
			$this->meta_data[ $key ] = $value;
		}
		public function save_meta_data() {
			// No-op in test stub.
		}
	}
}

/**
 * Mock WC_Order_Item_Product class for unit testing.
 *
 * Extends WC_Order_Item so mocks satisfy OrderHandler return type WC_Order_Item|false|null.
 */
if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WC_Order_Item_Product extends WC_Order_Item {
		protected $product_id = 0;
		protected $quantity = 1;
		protected $total = '0.00';

		public function get_product_id() {
			return $this->product_id;
		}
		public function get_quantity() {
			return $this->quantity;
		}
		public function get_total() {
			return $this->total;
		}
	}
}

/**
 * Mock WC_Product class for unit testing.
 *
 * Provides minimal WC_Product interface for product-related tests without WooCommerce.
 */
if ( ! class_exists( 'WC_Product' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WC_Product {
		protected $id = 0;
		protected $name = '';
		protected $price = '0.00';
		protected $meta_data = array();

		public function get_id() {
			return $this->id;
		}
		public function get_name() {
			return $this->name;
		}
		public function get_price() {
			return $this->price;
		}
		public function get_meta( $key, $single = true ) {
			return $this->meta_data[ $key ] ?? '';
		}
	}
}

/**
 * Mock WC_Cart class for unit testing.
 *
 * Provides minimal WC_Cart interface for cart handler tests without WooCommerce.
 */
if ( ! class_exists( 'WC_Cart' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mock class.
	class WC_Cart {
		public $cart_contents = array();
		public $removed_cart_contents = array();

		public function get_cart() {
			return $this->cart_contents;
		}
		public function get_cart_item( $cart_item_key ) {
			return $this->cart_contents[ $cart_item_key ] ?? null;
		}
		public function add_to_cart( $product_id, $quantity = 1 ) {
			$key = md5( (string) $product_id );
			$this->cart_contents[ $key ] = array(
				'key'        => $key,
				'product_id' => $product_id,
				'quantity'   => $quantity,
				'data'       => null,
			);
			return $key;
		}
		public function remove_cart_item( $cart_item_key ) {
			if ( isset( $this->cart_contents[ $cart_item_key ] ) ) {
				$this->removed_cart_contents[ $cart_item_key ] = $this->cart_contents[ $cart_item_key ];
				unset( $this->cart_contents[ $cart_item_key ] );
				return true;
			}
			return false;
		}
		public function empty_cart() {
			$this->cart_contents = array();
		}
		public function get_cart_contents_count() {
			return array_sum( array_column( $this->cart_contents, 'quantity' ) );
		}
	}
}

// Note: WooCommerce class and WC() function are NOT defined globally.
// Tests that need to check class_exists('WooCommerce') or function_exists('WC')
// should test the actual behavior (not available = error response).
// Tests that need WC() can mock it via Brain\Monkey: Functions\when('WC')->justReturn($mock);

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'NETTERTECH_EVENTS_VERSION' ) ) {
	define( 'NETTERTECH_EVENTS_VERSION', '0.9.0-test' );
}

if ( ! defined( 'NETTERTECH_EVENTS_PLUGIN_DIR' ) ) {
	define( 'NETTERTECH_EVENTS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'NETTERTECH_EVENTS_PLUGIN_URL' ) ) {
	define( 'NETTERTECH_EVENTS_PLUGIN_URL', 'http://example.com/wp-content/plugins/nettertech-events/' );
}

// Load test factories.
require_once __DIR__ . '/Factories/EventFactory.php';
require_once __DIR__ . '/Factories/OccurrenceFactory.php';

// Global registry for block render tests to inject a mock container.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- test-only global.
$nettertech_events_test_container = null;

// Define NetterTechEvents\nettertech_events_container() as a real PHP function (permanent for
// the process). This keeps function_exists() checks in templates deterministic
// regardless of which test runs first. Block render tests set $nettertech_events_test_container
// in setUp() and clear it in tearDown().
require_once __DIR__ . '/Stubs/nettertech-events-container-stub.php';
require_once __DIR__ . '/Factories/TicketTypeFactory.php';
require_once __DIR__ . '/Factories/AttendeeFactory.php';
require_once __DIR__ . '/Factories/WooCommerceFactory.php';

/**
 * Base test case with Brain Monkey setup.
 */
abstract class NetterTechEventsTestCase extends \PHPUnit\Framework\TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		// PHPUnit shares one process across tests, so a prior test that swapped the global
		// $wpdb for a Mockery mock would leak it into this one (surfacing only under random
		// order, as an unexpected get_results/get_row call). Start every test on the
		// well-behaved bootstrap stub; tests that need a mock still install their own.
		$GLOBALS['wpdb'] = new wpdb();

		// Mock common WordPress functions.
		$this->mock_wordpress_functions();

	}

	/**
	 * Count Mockery expectations as PHPUnit assertions.
	 *
	 * Tests that verify behavior solely via Mockery (shouldReceive, shouldNotReceive)
	 * are otherwise flagged as "risky" by PHPUnit for having zero assertions.
	 *
	 * @return void
	 */
	protected function assertPostConditions(): void {
		parent::assertPostConditions();

		$container = \Mockery::getContainer();
		if ( $container ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}
	}

	/**
	 * Tear down Brain Monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Mock common WordPress functions used throughout the plugin.
	 *
	 * @return void
	 */
	protected function mock_wordpress_functions(): void {
		// WooCommerce functions.
		$this->mock_woocommerce_functions();
		// Translation functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'__'            => function ( $text, $domain = 'default' ) {
					return $text;
				},
				'_e'            => function ( $text, $domain = 'default' ) {
					echo $text;
				},
				'_n'            => function ( $single, $plural, $number, $domain = 'default' ) {
					return $number === 1 ? $single : $plural;
				},
				'_x'            => function ( $text, $context, $domain = 'default' ) {
					return $text;
				},
				'esc_html__'    => function ( $text, $domain = 'default' ) {
					return $text;
				},
				'esc_html_e'    => function ( $text, $domain = 'default' ) {
					echo $text;
				},
				'esc_attr__'    => function ( $text, $domain = 'default' ) {
					return $text;
				},
				'esc_attr_e'    => function ( $text, $domain = 'default' ) {
					echo $text;
				},
			)
		);

		// Escaping functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'esc_html'      => function ( $text ) {
					return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
				},
				'esc_attr'      => function ( $text ) {
					return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
				},
				'esc_url'       => function ( $url ) {
					return filter_var( $url, FILTER_SANITIZE_URL );
				},
				'esc_js'        => function ( $text ) {
					return addslashes( (string) $text );
				},
				'esc_textarea'  => function ( $text ) {
					return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
				},
				'esc_sql'       => function ( $data ) {
					return addslashes( (string) $data );
				},
				'wp_kses_post'  => function ( $content ) {
					// Test stub: pass through. Real wp_kses_post strips disallowed tags.
					return (string) $content;
				},
				'wp_kses'       => function ( $content, $allowed_html = array(), $allowed_protocols = array() ) {
					// Test stub: pass through. Real wp_kses strips tags/attrs not in allowlist.
					return (string) $content;
				},
				'wp_kses_allowed_html'  => function ( $context = 'post' ) {
					// Test stub: return minimal post-content-style allowlist.
					return array(
						'a'      => array( 'href' => true, 'title' => true ),
						'br'     => array(),
						'em'     => array(),
						'strong' => array(),
						'p'      => array(),
						'div'    => array(),
						'span'   => array(),
					);
				},
			)
		);

		// Sanitization functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'sanitize_text_field'     => function ( $str ) {
					return trim( strip_tags( (string) $str ) );
				},
				'sanitize_textarea_field' => function ( $str ) {
					return trim( strip_tags( (string) $str ) );
				},
				'sanitize_email'          => function ( $email ) {
					return filter_var( $email, FILTER_SANITIZE_EMAIL );
				},
				'sanitize_title'          => function ( $title ) {
					// Lowercase first, then replace non-alphanumeric with hyphens.
					$slug = strtolower( (string) $title );
					$slug = preg_replace( '/[^a-z0-9-]/', '-', $slug );
					$slug = preg_replace( '/-+/', '-', $slug ); // Collapse multiple hyphens.
					return trim( $slug, '-' );
				},
				'sanitize_key'            => function ( $key ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
				},
				'absint'                  => function ( $value ) {
					return abs( (int) $value );
				},
				'wp_unslash'              => function ( $value ) {
					return is_string( $value ) ? stripslashes( $value ) : $value;
				},
			)
		);

		// Option functions (return false by default, can be overridden in tests).
		\Brain\Monkey\Functions\stubs(
			array(
				'get_option'    => false,
				'update_option' => true,
				'delete_option' => true,
			)
		);

		// Post meta functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'get_post_meta'    => '',
				'update_post_meta' => true,
				'delete_post_meta' => true,
				'add_post_meta'    => 1,
			)
		);

		// Transient functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'get_transient'    => false,
				'set_transient'    => true,
				'delete_transient' => true,
			)
		);

		// Object cache functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'wp_cache_get'         => false,
				'wp_cache_set'         => true,
				'wp_cache_delete'      => true,
				'wp_cache_add'         => true,
				'wp_cache_flush'       => true,
				'wp_cache_flush_group' => true,
			)
		);

		// Hook registration: intentionally NOT stubbed, so Brain Monkey records it and
		// Actions\expectAdded() / Filters\expectAdded() work (NTE-150).

		// Doing actions/filters: intentionally NOT stubbed. Brain Monkey provides both,
		// and its versions honour Filters\expectApplied()->andReturn() / Actions\expectDone().
		// Stubbing them here would return the unfiltered value always, making every
		// extension seam untestable (NTE-150).

		// User functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'current_user_can' => true,
				'get_current_user_id' => 1,
				'is_admin' => false,
				'is_user_logged_in' => true,
			)
		);

		// Site functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'get_bloginfo'    => function ( $show = '' ) {
					return match ( $show ) {
						'name'     => 'Test Site',
						'url'      => 'http://example.com',
						'language' => 'en-US',
						default    => '',
					};
				},
				'home_url'        => 'http://example.com',
				'admin_url'       => 'http://example.com/wp-admin/',
				'wp_nonce_url'    => function ( $url, $action = -1, $name = '_wpnonce' ) {
					$sep = str_contains( (string) $url, '?' ) ? '&' : '?';
					return $url . $sep . $name . '=testnonce';
				},
				'plugins_url'     => 'http://example.com/wp-content/plugins/',
				'content_url'     => 'http://example.com/wp-content/',
				'site_url'        => 'http://example.com',
				'plugin_dir_path' => function ( $file = '' ) {
					return dirname( $file ) . '/';
				},
			)
		);

		// Time functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'current_time' => function ( $type = 'mysql', $gmt = 0 ) {
					return $type === 'mysql' ? gmdate( 'Y-m-d H:i:s' ) : time();
				},
				'wp_date' => function ( $format, $timestamp = null ) {
					return gmdate( $format, $timestamp ?? time() );
				},
				// The site zone. UTC here, to agree with the current_time/wp_date stubs above —
				// a test that cares about a *specific* zone overrides this with its own.
				'wp_timezone' => function () {
					return new \DateTimeZone( 'UTC' );
				},
				'wp_timezone_string' => function () {
					return 'UTC';
				},
			)
		);

		// Nonce functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'wp_verify_nonce'  => 1,
				'wp_create_nonce'  => 'test_nonce_123',
				'check_ajax_referer' => true,
			)
		);

		// Email.
		\Brain\Monkey\Functions\stubs(
			array(
				'wp_mail' => true,
				'is_email' => function ( $email ) {
					return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
				},
			)
		);

		// File functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'wp_upload_dir' => function () {
					return array(
						'basedir' => '/tmp/uploads',
						'baseurl' => 'http://example.com/wp-content/uploads',
						'path'    => '/tmp/uploads/' . gmdate( 'Y/m' ),
						'url'     => 'http://example.com/wp-content/uploads/' . gmdate( 'Y/m' ),
					);
				},
				'wp_mkdir_p'              => true,
				'wp_delete_file'          => true,
				'get_stylesheet_directory' => '/tmp/theme',
				'get_template_directory'   => '/tmp/theme',
			)
		);

		// Media attachment functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'wp_attachment_is_image'      => function ( $post = null ) {
					// Default mock: return true for any valid attachment ID.
					return is_int( $post ) && $post > 0;
				},
				'wp_get_attachment_image'     => function ( $attachment_id, $size = 'thumbnail', $icon = false, $attr = '' ) {
					if ( ! $attachment_id || $attachment_id <= 0 ) {
						return '';
					}
					$atts = is_array( $attr ) ? $attr : array();
					$class = isset( $atts['class'] ) ? $atts['class'] : '';
					return sprintf(
						'<img src="http://example.com/wp-content/uploads/test-%d.jpg" class="%s">',
						$attachment_id,
						esc_attr( $class )
					);
				},
				'wp_get_attachment_image_url' => function ( $attachment_id, $size = 'thumbnail' ) {
					if ( ! $attachment_id || $attachment_id <= 0 ) {
						return false;
					}
					return sprintf( 'http://example.com/wp-content/uploads/test-%d.jpg', $attachment_id );
				},
				'wp_get_attachment_image_src' => function ( $attachment_id, $size = 'thumbnail' ) {
					if ( ! $attachment_id || $attachment_id <= 0 ) {
						return false;
					}
					return array(
						sprintf( 'http://example.com/wp-content/uploads/test-%d.jpg', $attachment_id ),
						800,
						600,
						false,
					);
				},
			)
		);

		// Admin functions (only safe defaults - menu functions should be mocked per-test).
		\Brain\Monkey\Functions\stubs(
			array(
				'wp_enqueue_media'       => null,
				'wp_enqueue_script'      => null,
				'wp_enqueue_style'       => null,
				'wp_register_script'     => true,
				'wp_register_style'      => true,
				'wp_localize_script'     => true,
				'wp_add_inline_script'   => true,
				'wp_add_inline_style'    => true,
				'settings_fields'        => null,
				'do_settings_sections'   => null,
				'submit_button'          => function ( $text = '' ) {
					echo '<input type="submit" value="' . esc_attr( $text ) . '">';
				},
				'get_current_screen'     => null,
				'wp_nonce_field'         => function ( $action, $name ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="test_nonce">';
				},
				'checked'                => function ( $checked, $current = true, $echo = true ) {
					$result = $checked === $current ? ' checked="checked"' : '';
					if ( $echo ) {
						echo $result;
					}
					return $result;
				},
				'selected'               => function ( $selected, $current = true, $echo = true ) {
					$result = $selected === $current ? ' selected="selected"' : '';
					if ( $echo ) {
						echo $result;
					}
					return $result;
				},
				'disabled'               => function ( $disabled, $current = true, $echo = true ) {
					$result = $disabled === $current ? ' disabled="disabled"' : '';
					if ( $echo ) {
						echo $result;
					}
					return $result;
				},
			)
		);

		// URL helper functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'add_query_arg' => function ( $key, $value = '', $url = '' ) {
					if ( is_array( $key ) ) {
						$url   = $value;
						$query = http_build_query( $key );
					} else {
						$query = urlencode( (string) $key ) . '=' . urlencode( (string) $value );
					}
					$sep = str_contains( (string) $url, '?' ) ? '&' : '?';
					return $url . $sep . $query;
				},
			)
		);

		// Misc.
		\Brain\Monkey\Functions\stubs(
			array(
				'wp_generate_uuid4' => function () {
					return sprintf(
						'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
						mt_rand( 0, 0xffff ),
						mt_rand( 0, 0xffff ),
						mt_rand( 0, 0xffff ),
						mt_rand( 0, 0x0fff ) | 0x4000,
						mt_rand( 0, 0x3fff ) | 0x8000,
						mt_rand( 0, 0xffff ),
						mt_rand( 0, 0xffff ),
						mt_rand( 0, 0xffff )
					);
				},
				'wp_parse_url' => function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				},
			)
		);

		// Theme functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'wp_get_theme'      => function () {
					return new class {
						public function get_template(): string {
							return 'twentytwentyfour';
						}
					};
				},
				'get_theme_mod'     => '',
				'get_theme_support' => false,
			)
		);

		// Array utility functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'map_deep' => function ( $value, $callback ) {
					if ( is_array( $value ) ) {
						foreach ( $value as $index => $item ) {
							$value[ $index ] = map_deep( $item, $callback );
						}
					} elseif ( is_object( $value ) ) {
						$vars = get_object_vars( $value );
						foreach ( $vars as $prop => $item ) {
							$value->$prop = map_deep( $item, $callback );
						}
					} else {
						$value = call_user_func( $callback, $value );
					}
					return $value;
				},
				'wp_parse_args' => function ( $args, $defaults = array() ) {
					if ( is_object( $args ) ) {
						$args = get_object_vars( $args );
					}
					return array_merge( $defaults, $args );
				},
				'sanitize_sql_orderby' => function ( $orderby ) {
					// Simple validation - return the orderby if it looks valid.
					if ( preg_match( '/^[a-zA-Z_]+(\s+(ASC|DESC))?$/i', trim( $orderby ) ) ) {
						return $orderby;
					}
					return false;
				},
			)
		);
	}

	/**
	 * Mock WooCommerce functions.
	 *
	 * @return void
	 */
	protected function mock_woocommerce_functions(): void {
		// WooCommerce notice functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'wc_add_notice' => null,
				'wc_print_notices' => null,
				'wc_clear_notices' => null,
				'wc_has_notice' => false,
				'wc_get_notices' => array(),
			)
		);

		// WC() singleton — returns null by default (no WooCommerce environment).
		// Individual tests can override with Functions\when('WC')->justReturn($mock).
		\Brain\Monkey\Functions\stubs(
			array(
				'WC' => function () {
					return null;
				},
			)
		);

		// WooCommerce helper functions.
		\Brain\Monkey\Functions\stubs(
			array(
				'wc_get_product' => function () {
					return null;
				},
				'wc_get_order' => function () {
					return null;
				},
				'wc_price' => function ( $price ) {
					return '$' . number_format( (float) $price, 2 );
				},
				'wc_get_cart_url' => 'http://example.com/cart/',
				'wc_get_checkout_url' => 'http://example.com/checkout/',
				'get_woocommerce_currency' => 'USD',
				'get_woocommerce_currency_symbol' => '$',
			)
		);
	}
}

// ── Infection mutant preload (must stay last; requires all constants above) ──
// Brain Monkey's setUp() loads Patchwork, whose CodeManipulation stream wrapper
// replaces Infection's IncludeInterceptor on the 'file' protocol. Any class
// autoloaded after the first test's setUp() is therefore served from the real
// file on disk and every mutant escapes (0% MSI). Loading the intercepted file
// HERE — while Infection's wrapper is still registered — pulls the MUTANT
// content into memory before Patchwork can clobber the wrapper. No-op outside
// Infection runs (the interceptor class is only present in mutant processes).
if ( class_exists( 'Infection\StreamWrapper\IncludeInterceptor', false ) ) {
	$nettertech_events_infection_target = null;

	try {
		$nettertech_events_infection_prop = new ReflectionProperty( 'Infection\StreamWrapper\IncludeInterceptor', 'intercept' );
		$nettertech_events_infection_prop->setAccessible( true );
		$nettertech_events_infection_target = $nettertech_events_infection_prop->getValue();
	} catch ( ReflectionException $nettertech_events_infection_e ) {
		$nettertech_events_infection_target = null;
	}

	if ( is_string( $nettertech_events_infection_target ) && '' !== $nettertech_events_infection_target ) {
		require_once $nettertech_events_infection_target;
	}
}
