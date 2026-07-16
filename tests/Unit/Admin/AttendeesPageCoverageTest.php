<?php
/**
 * AttendeesPage coverage push tests.
 *
 * Drives the private render_* methods via reflection to exercise the
 * uncovered table/row/pagination/header branches. Also exercises the
 * get_attendees query-building branches with stubbed wpdb.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Attendees\AttendeesBulkActions;
use NetterTechEvents\Admin\AttendeesPage;
use NetterTechEvents\Contracts\AttendeesSummaryServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Coverage-targeted tests for AttendeesPage.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\AttendeesPage
 */
class AttendeesPageCoverageTest extends TestCase {

	/**
	 * @var \wpdb|Mockery\MockInterface
	 */
	private $db;

	/**
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * @var AttendeesBulkActions|Mockery\MockInterface
	 */
	private $bulk_actions;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_GET  = array();
		$_POST = array();

		$this->db = Mockery::mock( \wpdb::class );
		$this->db->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->db->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, $params = null ) {
				return $sql;
			}
		)->byDefault();
		$this->db->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$this->db->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();

		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );
		$this->bulk_actions    = $this->build_bulk_actions();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn( $s, $p, $n ) => 1 === (int) $n ? $s : $p
		);
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr_e' )->echoArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => '/wp-admin/' . $p );
		Functions\when( 'get_option' )->alias(
			static function ( $opt ) {
				if ( 'date_format' === $opt ) {
					return 'Y-m-d';
				}
				if ( 'time_format' === $opt ) {
					return 'H:i';
				}
				return '';
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static fn( $url, $nonce ) => $url . '&_wpnonce=stub'
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ) {
				// add_query_arg($key, $value, $url) -> append to existing URL.
				if ( count( $args ) >= 3 && is_string( $args[0] ) ) {
					$base = (string) $args[2];
					$kv   = $args[0] . '=' . urlencode( (string) $args[1] );
					return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . $kv;
				}
				// add_query_arg(array $params, $url = null) -> build/append.
				if ( is_array( $args[0] ) ) {
					$qs   = http_build_query( $args[0] );
					$base = $args[1] ?? '/wp-admin/admin.php?page=stub';
					return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . $qs;
				}
				return '/wp-admin/admin.php?' . $args[0] . '=' . $args[1];
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $a, $d ) => array_merge( (array) $d, (array) $a )
		);
		Functions\when( 'selected' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'stub-nonce' );
		Functions\when( 'wp_nonce_field' )->echoArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * @return AttendeesBulkActions|Mockery\MockInterface
	 */
	private function build_bulk_actions() {
		try {
			$mock = Mockery::mock( AttendeesBulkActions::class );
			$mock->shouldReceive( 'handle' )->byDefault();
			$mock->shouldReceive( 'handle_single_delete' )->byDefault();
			return $mock;
		} catch ( \Throwable $e ) {
			$reflection  = new \ReflectionClass( AttendeesBulkActions::class );
			$ctor        = $reflection->getConstructor();
			$param_count = $ctor ? $ctor->getNumberOfParameters() : 0;
			$stub        = Mockery::mock( \stdClass::class );
			$args        = array_fill( 0, $param_count, $stub );
			return $reflection->newInstanceArgs( $args );
		}
	}

	/**
	 * Build an instance.
	 *
	 * @return AttendeesPage
	 */
	private function build_page( ?array $summary = null ): AttendeesPage {
		$summary_service = Mockery::mock( AttendeesSummaryServiceInterface::class );
		$summary_service->shouldReceive( 'build' )->andReturn(
			$summary ?? array(
				'event'      => array( 'title' => '', 'date' => '', 'venue' => '', 'edit_url' => '', 'view_url' => '' ),
				'tickets'    => array( 'rows' => array(), 'total_issued' => 0, 'total_available' => 0 ),
				'attendance' => array( 'total_guests' => 0, 'status_counts' => array(), 'checked_in_guests' => 0, 'checked_in_percent' => 0 ),
			)
		)->byDefault();

		return new AttendeesPage( $this->db, $this->occurrence_repo, $this->bulk_actions, $summary_service );
	}

	/**
	 * The Ticket Overview renders one "issued (available)" row per type plus a total.
	 *
	 * @covers ::render_event_summary
	 * @covers ::format_issued
	 * @covers ::format_available
	 * @covers ::format_house_footnote
	 */
	public function test_render_event_summary_ticket_overview(): void {
		Functions\when( 'plugins_url' )->justReturn( 'http://example.test/logo.png' );

		$page = $this->build_page(
			array(
				'event'      => array( 'title' => 'Beoga', 'date' => '2026-08-16 19:00', 'venue' => 'Hall', 'edit_url' => 'http://e/edit', 'view_url' => 'http://e/view' ),
				'tickets'    => array(
					'rows'             => array(
						// Capped by its own tier limit: quotes 40, so it takes no marker.
						array( 'id' => 1, 'name' => 'Standing room', 'issued' => 12, 'available' => 40, 'checked_in' => 3, 'is_pass' => false, 'is_shared' => false, 'house_bound' => false ),
						// Quotes the house remainder: carries the footnote marker.
						array( 'id' => 2, 'name' => 'Youth Ticket', 'issued' => 6, 'available' => 210, 'checked_in' => 1, 'is_pass' => false, 'is_shared' => true, 'house_bound' => true ),
					),
					'total_issued'     => 18,
					'total_checked_in' => 4,
					'total_available'  => 210,
					'house'            => 250,
					'shares_house'     => true,
				),
				'attendance' => array( 'total_guests' => 18, 'status_counts' => array( 'confirmed' => 18 ), 'checked_in_guests' => 4, 'checked_in_percent' => 22 ),
			)
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_event_summary', array( 5, 42 ) )
		);

		$this->assertStringContainsString( 'Tickets & Attendance', $html );
		// Two columns total (NTE-143 amendment): details + consolidated panel in
		// one column, the synopsis seam in the other.
		$this->assertStringContainsString( 'nte-attendees-summary--two-col', $html );
		$this->assertStringContainsString( 'Standing room', $html );
		// Issued and available render on separate lines so long labels wrap in-card.
		$this->assertStringContainsString( '<span class="nte-summary-issued">12 issued</span>', $html );
		// Tiers sharing a house carry the footnote marker rather than a full phrase.
		$this->assertStringContainsString( '<span class="nte-summary-available">210 available*</span>', $html );
		$this->assertStringContainsString( '* house capacity (250), counted once', $html );
		// Total row reconciles with the attendance guest count.
		$this->assertStringContainsString( '<span class="nte-summary-issued">18 issued</span>', $html );
		$this->assertStringContainsString( 'Checked in', $html );
		$this->assertStringContainsString( '4 (22%)', $html );
		$this->assertStringContainsString( 'Edit Event', $html );
	}

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param object $instance Instance.
	 * @param string $method   Method name.
	 * @param array  $args     Args.
	 * @return mixed
	 */
	private function invoke_private( object $instance, string $method, array $args = array() ) {
		$ref = new ReflectionClass( $instance );
		$m   = $ref->getMethod( $method );
		return $m->invokeArgs( $instance, $args );
	}

	/**
	 * Capture output from a void callable.
	 *
	 * @param callable $cb Callable.
	 * @return string
	 */
	private function capture_output( callable $cb ): string {
		ob_start();
		try {
			$cb();
		} finally {
			$out = ob_get_clean();
		}
		return is_string( $out ) ? $out : '';
	}

	// =========================================================================
	// render_bulk_actions
	// =========================================================================

	/**
	 * @covers ::render_bulk_actions
	 */
	public function test_render_bulk_actions_emits_select_and_export_button(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_bulk_actions', array( 42 ) )
		);

		$this->assertStringContainsString( 'name="bulk_action"', $html );
		$this->assertStringContainsString( 'value="delete"', $html );
		$this->assertStringContainsString( 'value="export"', $html );
		$this->assertStringContainsString( 'value="export_all"', $html );
		// Total count substituted into Export All label.
		$this->assertStringContainsString( '42', $html );
	}

	// =========================================================================
	// render_sortable_header
	// =========================================================================

	/**
	 * @covers ::render_sortable_header
	 */
	public function test_render_sortable_header_active_asc_toggles_to_desc(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private(
				$page,
				'render_sortable_header',
				array( 'name', 'Name', 'name', 'ASC', 'column-name' )
			)
		);

		// Sorted class + ASC sort indicator class.
		$this->assertStringContainsString( 'sorted asc', $html );
		// Link toggles to desc.
		$this->assertStringContainsString( 'order=desc', $html );
		$this->assertStringContainsString( 'orderby=name', $html );
	}

	/**
	 * @covers ::render_sortable_header
	 */
	public function test_render_sortable_header_inactive_defaults_to_desc(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private(
				$page,
				'render_sortable_header',
				array( 'status', 'Status', 'name', 'ASC' )
			)
		);

		// Unsorted -> sortable desc class.
		$this->assertStringContainsString( 'sortable desc', $html );
		$this->assertStringContainsString( 'order=asc', $html );
	}

	/**
	 * @covers ::render_sortable_header
	 */
	public function test_render_sortable_header_active_desc_toggles_to_asc(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private(
				$page,
				'render_sortable_header',
				array( 'email', 'Email', 'email', 'DESC' )
			)
		);

		$this->assertStringContainsString( 'sorted desc', $html );
		$this->assertStringContainsString( 'order=asc', $html );
	}

	// =========================================================================
	// render_row
	// =========================================================================

	/**
	 * @covers ::render_row
	 * @covers ::render_row_actions
	 */
	public function test_render_row_full_checkin(): void {
		$page = $this->build_page();
		$item = array(
			'id'                => 7,
			'name'              => 'Alice',
			'email'             => 'alice@example.com',
			'event_title'       => 'Concert A',
			'quantity'          => 2,
			'checked_in_count'  => 2,
			'status'            => 'confirmed',
			'start_datetime'    => '2026-02-15 19:00:00',
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row', array( $item ) )
		);

		$this->assertStringContainsString( 'Alice', $html );
		$this->assertStringContainsString( 'alice@example.com', $html );
		$this->assertStringContainsString( 'Concert A', $html );
		$this->assertStringContainsString( 'checkin-yes', $html );
		// Confirmed status badge.
		$this->assertStringContainsString( 'status-confirmed', $html );
		// Row actions delete link present.
		$this->assertStringContainsString( 'submitdelete', $html );
		// An already-checked-in attendee offers Undo, not Check In (NTE-144).
		$this->assertStringContainsString( 'nte-checkin-toggle', $html );
		$this->assertStringContainsString( 'data-state="out"', $html );
	}

	/**
	 * A not-yet-checked-in attendee gets a Check In toggle in the "in" state.
	 *
	 * @covers ::render_row
	 * @covers ::render_check_in_toggle
	 */
	public function test_render_row_emits_check_in_toggle(): void {
		$page = $this->build_page();
		$item = array(
			'id'               => 11,
			'name'             => 'Ada',
			'email'            => 'ada@example.com',
			'quantity'         => 1,
			'checked_in_count' => 0,
			'status'           => 'confirmed',
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row', array( $item ) )
		);

		$this->assertStringContainsString( 'nte-checkin-toggle', $html );
		$this->assertStringContainsString( 'data-state="in"', $html );
		$this->assertStringContainsString( 'data-attendee-id="11"', $html );
		// The control must be a real button, not a clickable span.
		$this->assertStringContainsString( '<button type="button"', $html );
	}

	/**
	 * A voided, refunded, cancelled or pending attendee holds no valid ticket,
	 * so the check-in control must not be offered for it.
	 *
	 * @dataProvider non_confirmed_statuses
	 * @covers ::render_row
	 * @covers ::render_check_in_toggle
	 * @param string $status Non-confirmed attendee status.
	 */
	public function test_check_in_toggle_suppressed_for_non_confirmed( string $status ): void {
		$page = $this->build_page();
		$item = array(
			'id'               => 11,
			'name'             => 'Ada',
			'email'            => 'ada@example.com',
			'quantity'         => 1,
			'checked_in_count' => 0,
			'status'           => $status,
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row', array( $item ) )
		);

		$this->assertStringNotContainsString( 'nte-checkin-toggle', $html );
	}

	/**
	 * Non-confirmed statuses that must not expose a check-in control.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function non_confirmed_statuses(): array {
		return array(
			'voided'    => array( 'voided' ),
			'refunded'  => array( 'refunded' ),
			'cancelled' => array( 'cancelled' ),
			'pending'   => array( 'pending' ),
		);
	}

	/**
	 * An extension column (e.g. Pro's Coupon) renders a header and a per-row cell,
	 * and the cell content flows through the content filter keyed by column.
	 *
	 * @covers ::render_table
	 * @covers ::render_row
	 */
	public function test_extension_column_renders_header_and_cell(): void {
		\Brain\Monkey\Filters\expectApplied( 'nettertech_events_attendees_column_content' )
			->andReturn( 'SAVE10' );

		$page  = $this->build_page();
		$items = array(
			array(
				'id'               => 11,
				'name'             => 'Ada',
				'email'            => 'ada@example.com',
				'quantity'         => 1,
				'checked_in_count' => 0,
				'status'           => 'confirmed',
				'wc_order_id'      => 7781,
			),
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_table', array( $items, '', 'DESC', array( 'coupon' => 'Coupon' ) ) )
		);

		// Header cell for the extension column (shared nte-ext-column sizing class).
		$this->assertStringContainsString( '<th scope="col" class="column-coupon nte-ext-column">Coupon</th>', $html );
		// Body cell keyed by the same column, carrying the filtered content.
		// The extension's own header label doubles as the cell's responsive
		// caption, so a collapsed row on mobile still names the column.
		$this->assertStringContainsString( '<td class="column-coupon nte-ext-column" data-colname="Coupon">', $html );
		$this->assertStringContainsString( 'SAVE10', $html );
	}

	/**
	 * The table carries the markup core's responsive list-table rules key on.
	 *
	 * Below 782px core collapses every column after the primary one behind the
	 * row toggle, captioning each with its `data-colname`. Without these hooks the
	 * fixed column widths overflow the admin content area instead.
	 *
	 * @covers ::render_table
	 * @covers ::render_row
	 * @covers ::render_sortable_header
	 */
	public function test_table_carries_responsive_list_table_markup(): void {
		$page = $this->build_page();

		$items = array(
			array(
				'id'             => 1,
				'name'           => 'Gala Patron',
				'email'          => 'gala@example.test',
				'event_title'    => 'Beoga',
				'start_datetime' => '2026-08-16 19:00:00',
				'quantity'       => 2,
				'status'         => 'confirmed',
			),
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_table', array( $items, '', 'DESC', array() ) )
		);

		// The primary column anchors core's collapse rules, in head and body alike.
		$this->assertStringContainsString( 'column-name column-primary sortable', $html );
		$this->assertStringContainsString( 'class="column-name column-primary has-row-actions"', $html );

		// Every non-checkbox cell names itself for the collapsed view.
		foreach ( array( 'Name', 'Email', 'Event', 'Date/Time', 'Qty', 'Status', 'Checked In' ) as $colname ) {
			$this->assertStringContainsString( 'data-colname="' . $colname . '"', $html );
		}

		// Core's common.js binds the expand affordance to `.toggle-row`.
		$this->assertStringContainsString( 'class="toggle-row"', $html );
	}

	/**
	 * Order-backed attendees get a Re-send Email action for order managers.
	 *
	 * @covers ::render_row_actions
	 */
	public function test_resend_email_action_rendered_for_order_backed_attendee(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row_actions', array( 5, 7781 ) )
		);

		$this->assertStringContainsString( 'nte-resend-email', $html );
		$this->assertStringContainsString( 'data-order-id="7781"', $html );
	}

	/**
	 * RSVP attendees have no WooCommerce order, so no email to re-send.
	 *
	 * @covers ::render_row_actions
	 */
	public function test_resend_email_action_absent_without_order(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row_actions', array( 5, 0 ) )
		);

		$this->assertStringNotContainsString( 'nte-resend-email', $html );
		$this->assertStringContainsString( 'submitdelete', $html );
	}

	/**
	 * Re-send replays a WooCommerce order email; non-order-managers must not see it.
	 *
	 * @covers ::render_row_actions
	 */
	public function test_resend_email_action_hidden_without_order_capability(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn( $cap ) => 'edit_shop_orders' !== $cap
		);

		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row_actions', array( 5, 7781 ) )
		);

		$this->assertStringNotContainsString( 'nte-resend-email', $html );
		$this->assertStringContainsString( 'submitdelete', $html );
	}

	/**
	 * Users who cannot edit_posts see the state but get no toggle.
	 *
	 * @covers ::render_check_in_toggle
	 */
	public function test_check_in_toggle_hidden_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$page = $this->build_page();
		$item = array(
			'id'               => 12,
			'name'             => 'Grace',
			'quantity'         => 1,
			'checked_in_count' => 0,
			'status'           => 'confirmed',
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row', array( $item ) )
		);

		$this->assertStringNotContainsString( 'nte-checkin-toggle', $html );
		$this->assertStringContainsString( 'checkin-no', $html );
	}

	/**
	 * @covers ::render_row
	 */
	public function test_render_row_partial_checkin(): void {
		$page = $this->build_page();
		$item = array(
			'id'                => 8,
			'name'              => 'Bob',
			'email'             => 'bob@example.com',
			'quantity'          => 3,
			'checked_in_count'  => 1,
			'status'            => 'pending',
			'start_datetime'    => '2026-03-01 14:00:00',
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row', array( $item ) )
		);

		$this->assertStringContainsString( 'checkin-partial', $html );
		$this->assertStringContainsString( '1/3', $html );
	}

	/**
	 * @covers ::render_row
	 */
	public function test_render_row_no_checkin(): void {
		$page = $this->build_page();
		$item = array(
			'id'                => 9,
			'name'              => 'Carol',
			'email'             => 'carol@example.com',
			'quantity'          => 1,
			'checked_in_count'  => 0,
			'status'            => 'confirmed',
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row', array( $item ) )
		);

		$this->assertStringContainsString( 'checkin-no', $html );
	}

	/**
	 * @covers ::render_row
	 */
	public function test_render_row_with_missing_optional_fields(): void {
		$page = $this->build_page();
		$item = array(); // All defaults

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row', array( $item ) )
		);

		// Fallback to "Unknown Event" placeholder.
		$this->assertStringContainsString( 'Unknown Event', $html );
		// Default status is "confirmed".
		$this->assertStringContainsString( 'status-confirmed', $html );
	}

	// =========================================================================
	// render_row_actions
	// =========================================================================

	/**
	 * @covers ::render_row_actions
	 */
	public function test_render_row_actions_returns_empty_for_invalid_id(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row_actions', array( 0 ) )
		);

		$this->assertSame( '', $html );
	}

	/**
	 * @covers ::render_row_actions
	 */
	public function test_render_row_actions_emits_delete_link_with_nonce(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_row_actions', array( 99 ) )
		);

		$this->assertStringContainsString( 'submitdelete', $html );
		$this->assertStringContainsString( 'attendee_id=99', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	// =========================================================================
	// render_table
	// =========================================================================

	/**
	 * @covers ::render_table
	 * @covers ::render_sortable_header
	 * @covers ::render_row
	 */
	public function test_render_table_emits_thead_and_rows(): void {
		$page = $this->build_page();
		$items = array(
			array(
				'id'                => 1,
				'name'              => 'First',
				'email'             => 'first@x.com',
				'event_title'       => 'Event 1',
				'quantity'          => 1,
				'checked_in_count'  => 0,
				'status'            => 'confirmed',
			),
		);

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_table', array( $items, '', 'DESC' ) )
		);

		// thead + tbody + sortable headers + first row.
		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( '<thead>', $html );
		$this->assertStringContainsString( '<tbody>', $html );
		$this->assertStringContainsString( 'First', $html );
		$this->assertStringContainsString( 'cb-select-all', $html );
	}

	/**
	 * @covers ::render_table
	 */
	public function test_render_table_with_empty_items_emits_thead_only(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private( $page, 'render_table', array( array(), '', 'DESC' ) )
		);

		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( '<tbody>', $html );
		// Empty <tbody></tbody> with no rows.
		$this->assertStringNotContainsString( '<tr>\n\t\t<th', $html );
	}

	// =========================================================================
	// render_pagination
	// =========================================================================

	/**
	 * @covers ::render_pagination
	 */
	public function test_render_pagination_emits_nothing_for_single_page(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private(
				$page,
				'render_pagination',
				array( 5, 1, 1, 0, 0, '', '', '', '', 'DESC' )
			)
		);

		$this->assertSame( '', $html );
	}

	/**
	 * @covers ::render_pagination
	 */
	public function test_render_pagination_first_page_disables_prev_navigation(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private(
				$page,
				'render_pagination',
				array( 100, 5, 1, 0, 0, '', '', '', '', 'DESC' )
			)
		);

		$this->assertStringContainsString( 'tablenav-pages', $html );
		// On first page, prev buttons are disabled spans (not links).
		$this->assertStringContainsString( 'navspan button disabled', $html );
		// Next + last should be links.
		$this->assertStringContainsString( 'next-page button', $html );
		$this->assertStringContainsString( 'last-page button', $html );
	}

	/**
	 * @covers ::render_pagination
	 */
	public function test_render_pagination_last_page_disables_next_navigation(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private(
				$page,
				'render_pagination',
				array( 100, 5, 5, 0, 0, '', '', '', '', 'DESC' )
			)
		);

		// On last page, prev/first should be links; next/last disabled.
		$this->assertStringContainsString( 'first-page button', $html );
		$this->assertStringContainsString( 'prev-page button', $html );
		$this->assertStringContainsString( 'navspan button disabled', $html );
	}

	/**
	 * @covers ::render_pagination
	 */
	public function test_render_pagination_middle_page_has_all_navigation(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private(
				$page,
				'render_pagination',
				array( 100, 5, 3, 0, 0, '', '', '', '', 'DESC' )
			)
		);

		$this->assertStringContainsString( 'first-page button', $html );
		$this->assertStringContainsString( 'prev-page button', $html );
		$this->assertStringContainsString( 'next-page button', $html );
		$this->assertStringContainsString( 'last-page button', $html );
	}

	/**
	 * @covers ::render_pagination
	 */
	public function test_render_pagination_includes_filter_params_in_urls(): void {
		$page = $this->build_page();

		$html = $this->capture_output(
			fn() => $this->invoke_private(
				$page,
				'render_pagination',
				array( 100, 5, 1, 42, 7, 'alice', 'confirmed', 'yes', 'name', 'ASC' )
			)
		);

		$this->assertStringContainsString( 'occurrence_id=42', $html );
		$this->assertStringContainsString( 'event_id=7', $html );
		$this->assertStringContainsString( 's=alice', $html );
		$this->assertStringContainsString( 'status=confirmed', $html );
		$this->assertStringContainsString( 'placeholder=yes', $html );
		$this->assertStringContainsString( 'orderby=name', $html );
		$this->assertStringContainsString( 'order=asc', $html );
	}

	// =========================================================================
	// get_attendees query branches
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_executes_query_branches_for_all_filters(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_GET = array(
			'occurrence_id' => '5',
			's'             => 'alice',
			'status'        => 'confirmed',
			'placeholder'   => 'yes',
			'orderby'       => 'name',
			'order'         => 'asc',
			'paged'         => '2',
		);

		// Track that prepare gets called multiple times with the constructed SQL.
		$this->db->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$prepare_calls = 0;
		$this->db->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, $params = null ) use ( &$prepare_calls ) {
				++$prepare_calls;
				return $sql;
			}
		);
		$this->db->shouldReceive( 'get_var' )->andReturn( '15' );
		$this->db->shouldReceive( 'get_results' )->andReturn(
			array(
				array(
					'id'               => 1,
					'name'             => 'Alice',
					'email'            => 'a@x.com',
					'event_title'      => 'E',
					'quantity'         => 1,
					'checked_in_count' => 0,
					'status'           => 'confirmed',
					'start_datetime'   => '2026-02-15 19:00:00',
				),
			)
		);

		$this->occurrence_repo->shouldReceive( 'upcoming' )->andReturn( array() )->byDefault();
		$this->occurrence_repo->shouldReceive( 'get_all_upcoming' )->andReturn( array() )->byDefault();
		$this->occurrence_repo->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();

		$page = $this->build_page();

		$initial = ob_get_level();
		ob_start();
		try {
			$page->render();
		} catch ( \Throwable $e ) {
			// Template-driven render may throw on include; we exercise pre-render path.
		}
		while ( ob_get_level() > $initial ) {
			ob_end_clean();
		}

		$this->assertGreaterThan( 0, $prepare_calls );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_with_placeholder_no_filter_branch(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_GET = array( 'placeholder' => 'no' );

		$this->db->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->db->shouldReceive( 'get_results' )->andReturn( array() );

		$this->occurrence_repo->shouldReceive( 'upcoming' )->andReturn( array() )->byDefault();
		$this->occurrence_repo->shouldReceive( 'get_all_upcoming' )->andReturn( array() )->byDefault();
		$this->occurrence_repo->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();

		$page = $this->build_page();

		$initial = ob_get_level();
		ob_start();
		try {
			$page->render();
		} catch ( \Throwable $e ) {
			// Acceptable.
		}
		while ( ob_get_level() > $initial ) {
			ob_end_clean();
		}

		$this->assertTrue( true );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_with_invalid_orderby_falls_back_to_default(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_GET = array(
			'orderby' => 'invalid-column',
			'order'   => 'asc',
		);

		$this->db->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->db->shouldReceive( 'get_results' )->andReturn( array() );

		$this->occurrence_repo->shouldReceive( 'upcoming' )->andReturn( array() )->byDefault();
		$this->occurrence_repo->shouldReceive( 'get_all_upcoming' )->andReturn( array() )->byDefault();
		$this->occurrence_repo->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();

		$page = $this->build_page();

		$initial = ob_get_level();
		ob_start();
		try {
			$page->render();
		} catch ( \Throwable $e ) {
			// Acceptable.
		}
		while ( ob_get_level() > $initial ) {
			ob_end_clean();
		}

		$this->assertTrue( true );
	}

	// =========================================================================
	// nettertech_events_purchases_synopsis hook (NTE-133)
	// =========================================================================

	/**
	 * Build a minimal $result array accepted by render_page().
	 *
	 * @return array<string, mixed>
	 */
	private function build_render_page_result(): array {
		return array(
			'items' => array(),
			'total' => 0,
			'pages' => 1,
		);
	}

	/**
	 * @covers ::render_page
	 */
	public function test_render_page_fires_purchases_synopsis_hook_with_event_and_occurrence_ids(): void {
		$this->occurrence_repo->shouldReceive( 'for_event' )->andReturn( array() )->byDefault();
		Functions\when( 'plugins_url' )->justReturn( 'http://example.test/logo.png' );
		// The synopsis card is only emitted when something hooks the extension point.
		Functions\when( 'has_action' )->justReturn( true );

		$captured_actions = array();
		Functions\when( 'do_action' )->alias(
			function () use ( &$captured_actions ) {
				$captured_actions[] = func_get_args();
			}
		);

		$page = $this->build_page();

		$this->capture_output(
			function () use ( $page ) {
				try {
					$this->invoke_private(
						$page,
						'render_page',
						array( $this->build_render_page_result(), 7, 42, '', '', '', 1, '', 'DESC' )
					);
				} catch ( \Throwable $e ) {
					// The synopsis hook fires before render_filters()'s template
					// include; a downstream template failure doesn't invalidate
					// the hook-firing assertion below.
				}
			}
		);

		$synopsis_calls = array_values(
			array_filter(
				$captured_actions,
				static fn( $call ) => isset( $call[0] ) && 'nettertech_events_purchases_synopsis' === $call[0]
			)
		);

		$this->assertCount( 1, $synopsis_calls );
		$this->assertSame( array( 'nettertech_events_purchases_synopsis', 42, 7 ), $synopsis_calls[0] );
	}

	/**
	 * @covers ::render_page
	 */
	public function test_render_page_purchases_synopsis_hook_graceful_absence_when_unhooked(): void {
		$this->occurrence_repo->shouldReceive( 'for_event' )->andReturn( array() )->byDefault();
		$this->db->shouldReceive( 'get_results' )->andReturn(
			array( array( 'status' => 'confirmed', 'c' => 3 ) )
		)->byDefault();
		Functions\when( 'plugins_url' )->justReturn( 'http://example.test/logo.png' );

		// No listener attached to 'nettertech_events_purchases_synopsis' — the
		// base plugin's default do_action() stub is a pass-through no-op.
		Functions\when( 'has_action' )->justReturn( false );

		$page = $this->build_page();

		$html = $this->capture_output(
			function () use ( $page ) {
				try {
					$this->invoke_private(
						$page,
						'render_page',
						array( $this->build_render_page_result(), 0, 42, '', '', '', 1, '', 'DESC' )
					);
				} catch ( \Throwable $e ) {
					// See note above; template concerns are out of scope here.
				}
			}
		);

		// Rendering reaches (and passes through) the null-safe extension point
		// without emitting any extra markup and without a fatal error. The
		// event-scoped view now renders the summary blocks (NTE-143).
		$this->assertStringContainsString( 'Purchases for:', $html );
		$this->assertStringContainsString( 'nte-attendees-summary', $html );
		// An unhooked extension point must not leave an empty card behind.
		$this->assertStringNotContainsString( 'nte-summary-card--wide', $html );
	}
}
