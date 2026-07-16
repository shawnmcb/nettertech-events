<?php
/**
 * EventQuery unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Database\Queries
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Database\Queries;

use NetterTechEvents\Database\Queries\EventQuery;
use Brain\Monkey\Functions;

/**
 * Test EventQuery fluent query builder.
 */
class EventQueryTest extends \NetterTechEventsTestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset wpdb mock.
		$GLOBALS['wpdb'] = new \wpdb();
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( EventQuery::class ) );
	}

	/**
	 * Test can instantiate.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		global $wpdb;
		$query = new EventQuery( $wpdb );

		$this->assertInstanceOf( EventQuery::class, $query );
	}

	/**
	 * Test create static factory.
	 *
	 * @return void
	 */
	public function test_create_returns_instance(): void {
		$query = EventQuery::create();

		$this->assertInstanceOf( EventQuery::class, $query );
	}

	// =========================================================================
	// Method Chaining Tests
	// =========================================================================

	/**
	 * Test all methods return self for chaining.
	 *
	 * @return void
	 */
	public function test_methods_return_self(): void {
		$query = EventQuery::create();

		$this->assertSame( $query, $query->select( 'id' ) );
		$this->assertSame( $query, $query->where_status( 'published' ) );
		$this->assertSame( $query, $query->where_type( 'single' ) );
		$this->assertSame( $query, $query->in_series( 1 ) );
		$this->assertSame( $query, $query->with_category( 1 ) );
		$this->assertSame( $query, $query->with_tag( 1 ) );
		$this->assertSame( $query, $query->where_has_ticket_types() );
		$this->assertSame( $query, $query->search( 'test' ) );
		$this->assertSame( $query, $query->search_full( 'test' ) );
		$this->assertSame( $query, $query->where_id( 1 ) );
		$this->assertSame( $query, $query->where_ids( array( 1, 2 ) ) );
		$this->assertSame( $query, $query->exclude_ids( array( 3 ) ) );
		$this->assertSame( $query, $query->created_after( '2026-01-01' ) );
		$this->assertSame( $query, $query->created_before( '2026-12-31' ) );
		$this->assertSame( $query, $query->order_by( 'title' ) );
		$this->assertSame( $query, $query->order_by_next_occurrence() );
		$this->assertSame( $query, $query->order_by_first_occurrence() );
		$this->assertSame( $query, $query->limit( 10 ) );
		$this->assertSame( $query, $query->offset( 5 ) );
		$this->assertSame( $query, $query->page( 2 ) );
	}

	// =========================================================================
	// Select Tests
	// =========================================================================

	/**
	 * Test select with string column.
	 *
	 * @return void
	 */
	public function test_select_with_string(): void {
		$query = EventQuery::create()->select( 'id' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'SELECT id FROM', $sql );
	}

	/**
	 * Test select with array columns.
	 *
	 * @return void
	 */
	public function test_select_with_array(): void {
		$query = EventQuery::create()->select( array( 'id', 'title' ) );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'SELECT id, title FROM', $sql );
	}

	/**
	 * Test default select is *.
	 *
	 * @return void
	 */
	public function test_default_select_is_all(): void {
		$query = EventQuery::create();
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'SELECT * FROM', $sql );
	}

	// =========================================================================
	// Status Filter Tests
	// =========================================================================

	/**
	 * Test where_status adds clause.
	 *
	 * @return void
	 */
	public function test_where_status_adds_clause(): void {
		$query = EventQuery::create()->where_status( 'published' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'status =', $sql );
		$this->assertStringContainsString( 'published', $sql );
	}

	/**
	 * Test published convenience method.
	 *
	 * @return void
	 */
	public function test_published_filters_status(): void {
		$query = EventQuery::create()->published();
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'status =', $sql );
		$this->assertStringContainsString( 'published', $sql );
	}

	/**
	 * Test drafts convenience method.
	 *
	 * @return void
	 */
	public function test_drafts_filters_status(): void {
		$query = EventQuery::create()->drafts();
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'status =', $sql );
		$this->assertStringContainsString( 'draft', $sql );
	}

	// =========================================================================
	// Type Filter Tests
	// =========================================================================

	/**
	 * Test where_type adds clause.
	 *
	 * @return void
	 */
	/**
	 * The type filter derives Recurring from the schedule, not the column alone.
	 *
	 * NTE-159 ruling: an event with more than one scheduled date cannot
	 * logically be single. Recurring pulls in stored-single multi-date events;
	 * Single excludes them; series_parent stays a plain column match.
	 *
	 * @return void
	 */
	public function test_where_type_derives_recurring_from_occurrence_count(): void {
		$recurring_sql = EventQuery::create()->where_type( 'recurring' )->to_sql();
		$this->assertStringContainsString( "event_type = 'single' AND", $recurring_sql );
		$this->assertStringContainsString( 'COUNT(*)', $recurring_sql );
		$this->assertStringContainsString( "status = 'scheduled'", $recurring_sql );
		$this->assertStringContainsString( '> 1', $recurring_sql );

		$single_sql = EventQuery::create()->where_type( 'single' )->to_sql();
		$this->assertStringContainsString( 'AND NOT (', $single_sql );
		$this->assertStringContainsString( 'COUNT(*)', $single_sql );

		$series_sql = EventQuery::create()->where_type( 'series_parent' )->to_sql();
		$this->assertStringNotContainsString( 'COUNT(*)', $series_sql );
	}

	public function test_where_type_adds_clause(): void {
		$query = EventQuery::create()->where_type( 'recurring' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'event_type =', $sql );
		$this->assertStringContainsString( 'recurring', $sql );
	}

	/**
	 * Test single convenience method.
	 *
	 * @return void
	 */
	public function test_single_filters_type(): void {
		$query = EventQuery::create()->single();
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'event_type =', $sql );
		$this->assertStringContainsString( 'single', $sql );
	}

	/**
	 * Test recurring convenience method.
	 *
	 * @return void
	 */
	public function test_recurring_filters_type(): void {
		$query = EventQuery::create()->recurring();
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'event_type =', $sql );
		$this->assertStringContainsString( 'recurring', $sql );
	}

	// =========================================================================
	// Relation Filter Tests
	// =========================================================================

	/**
	 * Test in_series adds clause.
	 *
	 * @return void
	 */
	public function test_in_series_adds_clause(): void {
		$query = EventQuery::create()->in_series( 42 );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'series_id =', $sql );
	}

	/**
	 * Test with_category adds EXISTS subquery.
	 *
	 * @return void
	 */
	public function test_with_category_adds_exists(): void {
		$query = EventQuery::create()->with_category( 5 );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'EXISTS', $sql );
		$this->assertStringContainsString( 'category_id', $sql );
	}

	/**
	 * Test with_tag adds EXISTS subquery.
	 *
	 * @return void
	 */
	public function test_with_tag_adds_exists(): void {
		$query = EventQuery::create()->with_tag( 10 );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'EXISTS', $sql );
		$this->assertStringContainsString( 'tag_id', $sql );
	}

	/**
	 * Test where_has_ticket_types adds a set-level EXISTS subquery.
	 *
	 * @return void
	 */
	public function test_where_has_ticket_types_adds_exists(): void {
		$query = EventQuery::create()->where_has_ticket_types();
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'EXISTS', $sql );
	}

	/**
	 * Test where_has_ticket_types links ticket types via BOTH scopes.
	 *
	 * Mirrors EventQueryRepository::has_ticket_types(): an active ticket type
	 * qualifies an event whether it is event-scoped (tt.event_id = event,
	 * occurrence_id NULL) or occurrence-scoped (tt.occurrence_id -> o.id ->
	 * o.event_id). Regression guard: counting only the occurrence linkage hid
	 * every event-scoped-ticketed event from the "ticketed" filter.
	 *
	 * @return void
	 */
	public function test_where_has_ticket_types_joins_via_occurrence_and_active(): void {
		$query = EventQuery::create()->where_has_ticket_types();
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'LEFT JOIN', $sql );
		$this->assertStringContainsString( 'tt.occurrence_id = o.id', $sql );
		$this->assertStringContainsString( 'tt.event_id =', $sql ); // event-scoped linkage.
		$this->assertStringContainsString( 'o.event_id =', $sql );  // occurrence-scoped linkage.
		$this->assertStringContainsString( "tt.status = 'active'", $sql );
	}

	/**
	 * Test where_has_ticket_types is absent from an unfiltered query.
	 *
	 * Guards the "empty when none selected" branch: a query without the ticketed
	 * filter must not carry the ticket-types EXISTS clause.
	 *
	 * @return void
	 */
	public function test_unfiltered_query_has_no_ticket_types_clause(): void {
		$sql = EventQuery::create()->to_sql();

		$this->assertStringNotContainsString( 'tt.occurrence_id', $sql );
	}

	// =========================================================================
	// Search Tests
	// =========================================================================

	/**
	 * Test search adds LIKE clause.
	 *
	 * @return void
	 */
	public function test_search_adds_like(): void {
		$query = EventQuery::create()->search( 'concert' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'title LIKE', $sql );
	}

	/**
	 * Test search_full searches title and description.
	 *
	 * @return void
	 */
	public function test_search_full_searches_both_fields(): void {
		$query = EventQuery::create()->search_full( 'music' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'title LIKE', $sql );
		$this->assertStringContainsString( 'description LIKE', $sql );
	}

	// =========================================================================
	// ID Filter Tests
	// =========================================================================

	/**
	 * Test where_id adds clause.
	 *
	 * @return void
	 */
	public function test_where_id_adds_clause(): void {
		$query = EventQuery::create()->where_id( 123 );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'id =', $sql );
	}

	/**
	 * Test where_ids adds IN clause.
	 *
	 * @return void
	 */
	public function test_where_ids_adds_in_clause(): void {
		$query = EventQuery::create()->where_ids( array( 1, 2, 3 ) );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'id IN', $sql );
	}

	/**
	 * Test where_ids with empty array returns no results.
	 *
	 * @return void
	 */
	public function test_where_ids_empty_returns_impossible_condition(): void {
		$query = EventQuery::create()->where_ids( array() );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( '1 = 0', $sql );
	}

	/**
	 * Test exclude_ids adds NOT IN clause.
	 *
	 * @return void
	 */
	public function test_exclude_ids_adds_not_in_clause(): void {
		$query = EventQuery::create()->exclude_ids( array( 4, 5 ) );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'id NOT IN', $sql );
	}

	/**
	 * Test exclude_ids with empty array is no-op.
	 *
	 * @return void
	 */
	public function test_exclude_ids_empty_is_noop(): void {
		$query     = EventQuery::create()->exclude_ids( array() );
		$sql       = $query->to_sql();
		$clean_sql = EventQuery::create()->to_sql();

		// Should have same WHERE clause.
		$this->assertStringNotContainsString( 'NOT IN', $sql );
	}

	// =========================================================================
	// Date Filter Tests
	// =========================================================================

	/**
	 * Test created_after adds clause.
	 *
	 * @return void
	 */
	public function test_created_after_adds_clause(): void {
		$query = EventQuery::create()->created_after( '2026-01-01' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'created_at >=', $sql );
		$this->assertStringContainsString( '00:00:00', $sql );
	}

	/**
	 * Test created_before adds clause.
	 *
	 * @return void
	 */
	public function test_created_before_adds_clause(): void {
		$query = EventQuery::create()->created_before( '2026-12-31' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'created_at <=', $sql );
		$this->assertStringContainsString( '23:59:59', $sql );
	}

	// =========================================================================
	// Order Tests
	// =========================================================================

	/**
	 * Test order_by with allowed column.
	 *
	 * @return void
	 */
	public function test_order_by_allowed_column(): void {
		$query = EventQuery::create()->order_by( 'title', 'ASC' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'ORDER BY title ASC', $sql );
	}

	/**
	 * Test order_by with DESC direction.
	 *
	 * @return void
	 */
	public function test_order_by_desc(): void {
		$query = EventQuery::create()->order_by( 'created_at', 'DESC' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'ORDER BY created_at DESC', $sql );
	}

	/**
	 * Test order_by with disallowed column falls back to created_at.
	 *
	 * @return void
	 */
	public function test_order_by_disallowed_column_defaults(): void {
		$query = EventQuery::create()->order_by( 'invalid_column', 'ASC' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'ORDER BY created_at ASC', $sql );
	}

	/**
	 * Test order_by rejects SQL-like column and direction input.
	 *
	 * @return void
	 */
	public function test_order_by_rejects_sql_like_identifier_input(): void {
		$query = EventQuery::create()->order_by( 'title DESC; DROP TABLE wp_users', 'DESC; DROP TABLE wp_users' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'ORDER BY created_at ASC', $sql );
		$this->assertStringNotContainsString( 'DROP TABLE', $sql );
	}

	/**
	 * Test order_by_next_occurrence adds subquery.
	 *
	 * @return void
	 */
	public function test_order_by_next_occurrence_asc_adds_exists(): void {
		$query = EventQuery::create()->order_by_next_occurrence( 'ASC' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'EXISTS', $sql );

		// Bounded on the end: an event on stage right now is still upcoming, and must not drop
		// out of the list an operator is watching it from. It is still *ordered* by its start.
		$this->assertStringContainsString( 'end_utc >= UTC_TIMESTAMP()', $sql );
		$this->assertStringNotContainsString( 'start_datetime >= NOW()', $sql );
		$this->assertStringContainsString( 'MIN(o.start_datetime)', $sql );
	}

	/**
	 * Test order_by_next_occurrence DESC does not add EXISTS.
	 *
	 * @return void
	 */
	public function test_order_by_next_occurrence_desc_no_exists(): void {
		$query = EventQuery::create()->order_by_next_occurrence( 'DESC' );
		$sql   = $query->to_sql();

		$this->assertStringNotContainsString( 'EXISTS', $sql );
		$this->assertStringContainsString( 'SELECT MIN', $sql );
	}

	/**
	 * Test order_by_first_occurrence sorts by MIN(start_datetime).
	 *
	 * @return void
	 */
	public function test_order_by_first_occurrence_uses_min_start(): void {
		$query = EventQuery::create()->order_by_first_occurrence( 'ASC' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'SELECT MIN(o.start_datetime)', $sql );
		$this->assertStringContainsString( 'ORDER BY', $sql );
	}

	/**
	 * Test order_by_first_occurrence does not exclude past events.
	 *
	 * Unlike order_by_next_occurrence ASC, the Date column is a historical span,
	 * so first-occurrence sorting must not add a NOW()-based EXISTS guard that
	 * would drop events whose occurrences are all in the past.
	 *
	 * @return void
	 */
	public function test_order_by_first_occurrence_does_not_filter_past(): void {
		$query = EventQuery::create()->order_by_first_occurrence( 'ASC' );
		$sql   = $query->to_sql();

		$this->assertStringNotContainsString( 'EXISTS', $sql );
		$this->assertStringNotContainsString( 'NOW()', $sql );
	}

	/**
	 * Test order_by_first_occurrence honors DESC direction.
	 *
	 * @return void
	 */
	public function test_order_by_first_occurrence_desc(): void {
		$query = EventQuery::create()->order_by_first_occurrence( 'DESC' );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( ') DESC', $sql );
	}

	/**
	 * Test default order is created_at DESC.
	 *
	 * @return void
	 */
	public function test_default_order(): void {
		$query = EventQuery::create();
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'ORDER BY created_at DESC', $sql );
	}

	// =========================================================================
	// Limit/Offset Tests
	// =========================================================================

	/**
	 * Test limit adds LIMIT clause.
	 *
	 * @return void
	 */
	public function test_limit_adds_clause(): void {
		$query = EventQuery::create()->limit( 10 );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'LIMIT 10', $sql );
	}

	/**
	 * Test limit enforces minimum of 1.
	 *
	 * @return void
	 */
	public function test_limit_minimum_is_one(): void {
		$query = EventQuery::create()->limit( -5 );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'LIMIT 1', $sql );
	}

	/**
	 * Test offset adds OFFSET clause.
	 *
	 * @return void
	 */
	public function test_offset_adds_clause(): void {
		$query = EventQuery::create()->limit( 10 )->offset( 20 );
		$sql   = $query->to_sql();

		$this->assertStringContainsString( 'OFFSET 20', $sql );
	}

	/**
	 * Test offset enforces minimum of 0.
	 *
	 * @return void
	 */
	public function test_offset_minimum_is_zero(): void {
		$query = EventQuery::create()->limit( 10 )->offset( -5 );
		$sql   = $query->to_sql();

		$this->assertStringNotContainsString( 'OFFSET -5', $sql );
	}

	/**
	 * Test page calculates offset.
	 *
	 * @return void
	 */
	public function test_page_calculates_offset(): void {
		$query = EventQuery::create()->limit( 10 )->page( 3 );
		$sql   = $query->to_sql();

		// Page 3 with limit 10 = offset 20.
		$this->assertStringContainsString( 'OFFSET 20', $sql );
	}

	/**
	 * Test page without limit does nothing.
	 *
	 * @return void
	 */
	public function test_page_without_limit_is_noop(): void {
		$query = EventQuery::create()->page( 5 );
		$sql   = $query->to_sql();

		$this->assertStringNotContainsString( 'OFFSET', $sql );
	}

	/**
	 * Test page minimum is 1.
	 *
	 * @return void
	 */
	public function test_page_minimum_is_one(): void {
		$query = EventQuery::create()->limit( 10 )->page( 0 );
		$sql   = $query->to_sql();

		// Page 1 = offset 0, so no OFFSET clause.
		$this->assertStringNotContainsString( 'OFFSET', $sql );
	}

	// =========================================================================
	// Combined Query Tests
	// =========================================================================

	/**
	 * Test complex query with multiple filters.
	 *
	 * @return void
	 */
	public function test_complex_query(): void {
		$query = EventQuery::create()
			->select( array( 'id', 'title' ) )
			->published()
			->recurring()
			->search( 'concert' )
			->order_by( 'title', 'ASC' )
			->limit( 5 );

		$sql = $query->to_sql();

		$this->assertStringContainsString( 'SELECT id, title FROM', $sql );
		$this->assertStringContainsString( 'status =', $sql );
		$this->assertStringContainsString( 'event_type =', $sql );
		$this->assertStringContainsString( 'title LIKE', $sql );
		$this->assertStringContainsString( 'ORDER BY title ASC', $sql );
		$this->assertStringContainsString( 'LIMIT 5', $sql );
	}

	/**
	 * Test to_sql method exists and returns string.
	 *
	 * @return void
	 */
	public function test_to_sql_returns_string(): void {
		$query = EventQuery::create();
		$sql   = $query->to_sql();

		$this->assertIsString( $sql );
		$this->assertNotEmpty( $sql );
	}

	// =========================================================================
	// Execution Method Signature Tests
	// =========================================================================

	/**
	 * Test get method exists.
	 *
	 * @return void
	 */
	public function test_get_method_exists(): void {
		$this->assertTrue( method_exists( EventQuery::class, 'get' ) );
	}

	/**
	 * Test first method exists.
	 *
	 * @return void
	 */
	public function test_first_method_exists(): void {
		$this->assertTrue( method_exists( EventQuery::class, 'first' ) );
	}

	/**
	 * Test count method exists.
	 *
	 * @return void
	 */
	public function test_count_method_exists(): void {
		$this->assertTrue( method_exists( EventQuery::class, 'count' ) );
	}

	/**
	 * Test exists method exists.
	 *
	 * @return void
	 */
	public function test_exists_method_exists(): void {
		$this->assertTrue( method_exists( EventQuery::class, 'exists' ) );
	}

	/**
	 * Test get_ids method exists.
	 *
	 * @return void
	 */
	public function test_get_ids_method_exists(): void {
		$this->assertTrue( method_exists( EventQuery::class, 'get_ids' ) );
	}

	/**
	 * Test paginate method exists.
	 *
	 * @return void
	 */
	public function test_paginate_method_exists(): void {
		$this->assertTrue( method_exists( EventQuery::class, 'paginate' ) );
	}
}
