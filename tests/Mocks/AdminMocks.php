<?php
/**
 * Mock classes for Admin tests.
 *
 * These mocks are loaded BEFORE the autoloader for classes that have WordPress
 * dependencies (like WP_List_Table) or need test-specific behavior.
 *
 * @package NetterTechEvents\Tests\Mocks
 */

declare(strict_types=1);

/**
 * Mock WP_Screen class for unit testing.
 *
 * The real class is loaded from wp-admin/includes/class-wp-screen.php
 * which isn't available in unit tests.
 *
 * @since 0.9.0
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WordPress core class mock.
class WP_Screen {

	/**
	 * Screen ID.
	 *
	 * @var string
	 */
	public $id = 'test_screen';

	/**
	 * Render screen reader content.
	 *
	 * @param string $key The content key.
	 * @return void
	 */
	public function render_screen_reader_content( $key ) {
		// Mock implementation - outputs nothing for tests.
	}

	/**
	 * Set option.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return void
	 */
	public function set_screen_reader_content( $option, $value = null ) {
		// Mock implementation.
	}
}

/**
 * Mock WP_List_Table class for unit testing.
 *
 * The real class is loaded from wp-admin/includes/class-wp-list-table.php
 * which isn't available in unit tests.
 *
 * @since 0.9.0
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WordPress core class mock.
class WP_List_Table {

	/**
	 * Args array.
	 *
	 * @var array
	 */
	protected $_args = array( // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- WordPress core style.
		'plural'   => 'items',
		'singular' => 'item',
		'ajax'     => false,
	);

	/**
	 * Screen object.
	 *
	 * @var object
	 */
	public $screen;

	/**
	 * Items array.
	 *
	 * @var array
	 */
	public $items = array();

	/**
	 * Column headers.
	 *
	 * @var array
	 */
	protected $_column_headers = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- WordPress core style.

	/**
	 * Constructor.
	 *
	 * @param array $args Arguments.
	 */
	public function __construct( $args = array() ) {
		$this->_args  = wp_parse_args( $args, $this->_args );
		$this->screen = new WP_Screen();
	}

	/**
	 * Get table classes.
	 *
	 * @return array
	 */
	protected function get_table_classes() {
		return array( 'widefat', 'fixed', 'striped', $this->_args['plural'] );
	}

	/**
	 * Get page number.
	 *
	 * @return int
	 */
	public function get_pagenum() {
		return 1;
	}

	/**
	 * Get current action.
	 *
	 * @return string|false
	 */
	public function current_action() {
		return false;
	}

	/**
	 * Row actions.
	 *
	 * @param array $actions Actions.
	 * @return string
	 */
	protected function row_actions( $actions ) {
		$html = '<div class="row-actions">';
		foreach ( $actions as $action => $link ) {
			$html .= "<span class=\"{$action}\">{$link}</span> ";
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Set pagination args.
	 *
	 * @param array $args Arguments.
	 * @return void
	 */
	protected function set_pagination_args( $args ) {
		// Mock implementation.
	}

	/**
	 * Display tablenav.
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		// Mock implementation.
	}

	/**
	 * Print column headers.
	 *
	 * @param bool $with_id Whether to include ID.
	 * @return void
	 */
	public function print_column_headers( $with_id = true ) {
		// Mock implementation.
	}

	/**
	 * Display rows or placeholder.
	 *
	 * @return void
	 */
	public function display_rows_or_placeholder() {
		// Mock implementation.
	}

	/**
	 * Get columns - to be overridden by child classes.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array();
	}

	/**
	 * Get sortable columns - to be overridden by child classes.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array();
	}

	/**
	 * Get bulk actions - to be overridden by child classes.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array();
	}

	/**
	 * Prepare items - to be overridden by child classes.
	 *
	 * @return void
	 */
	public function prepare_items() {
		// To be implemented by child class.
	}

	/**
	 * Generate the columns for a single row of the table.
	 *
	 * @param object $item The current item.
	 * @return void
	 */
	protected function single_row_columns( $item ) {
		list( $columns, $hidden, $sortable ) = $this->get_column_info();

		foreach ( $columns as $column_name => $column_display_name ) {
			$classes = "column-$column_name";
			echo "<td class=\"$classes\">";
			if ( method_exists( $this, "column_$column_name" ) ) {
				echo call_user_func( array( $this, "column_$column_name" ), $item );
			}
			echo '</td>';
		}
	}

	/**
	 * Get column information.
	 *
	 * @return array
	 */
	public function get_column_info() {
		if ( ! empty( $this->_column_headers ) ) {
			return $this->_column_headers;
		}
		return array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Search box.
	 *
	 * @param string $text     Search text.
	 * @param string $input_id Input ID.
	 * @return void
	 */
	public function search_box( $text, $input_id ) {
		echo '<input type="search" id="' . esc_attr( $input_id ) . '" name="s" />';
	}

	/**
	 * Get items per page from screen option.
	 *
	 * @param string $option  Screen option name.
	 * @param int    $default Default value.
	 * @return int
	 */
	public function get_items_per_page( $option, $default = 20 ) {
		return $default;
	}

	/**
	 * Display the table.
	 *
	 * @return void
	 */
	public function display() {
		// To be implemented by child class.
	}
}
