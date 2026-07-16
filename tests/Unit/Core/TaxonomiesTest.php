<?php
/**
 * Taxonomies unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use Brain\Monkey\Functions;
use NetterTechEvents\Core\Taxonomies;

/**
 * Test Taxonomies functionality.
 *
 * Tests taxonomy registration, category management, and caching.
 */
class TaxonomiesTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test EVENT_CATEGORY constant is defined.
	 *
	 * @return void
	 */
	public function test_event_category_constant_exists(): void {
		$this->assertEquals( 'nettertech_event_category', Taxonomies::EVENT_CATEGORY );
	}

	// =========================================================================
	// register() Tests
	// =========================================================================

	/**
	 * Test register adds init action.
	 *
	 * @return void
	 */
	public function test_register_adds_init_action(): void {
		$action_added = false;

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$action_added ) {
				if ( 'init' === $hook ) {
					$action_added = true;
				}
				return true;
			}
		);

		Taxonomies::register();

		$this->assertTrue( $action_added );
	}

	// =========================================================================
	// register_taxonomies() Tests
	// =========================================================================

	/**
	 * Test register_taxonomies calls register_taxonomy.
	 *
	 * @return void
	 */
	public function test_register_taxonomies_calls_register_taxonomy(): void {
		$taxonomy_registered = false;
		$registered_taxonomy = '';

		Functions\when( '_x' )->returnArg();
		Functions\when( '__' )->returnArg();

		Functions\when( 'register_taxonomy' )->alias(
			function ( $taxonomy, $object_type, $args ) use ( &$taxonomy_registered, &$registered_taxonomy ) {
				$taxonomy_registered = true;
				$registered_taxonomy = $taxonomy;
				return true;
			}
		);

		Taxonomies::register_taxonomies();

		$this->assertTrue( $taxonomy_registered );
		$this->assertEquals( Taxonomies::EVENT_CATEGORY, $registered_taxonomy );
	}

	/**
	 * Test register_taxonomies sets correct object type.
	 *
	 * @return void
	 */
	public function test_register_taxonomies_sets_object_type(): void {
		$registered_object_type = '';

		Functions\when( '_x' )->returnArg();
		Functions\when( '__' )->returnArg();

		Functions\when( 'register_taxonomy' )->alias(
			function ( $taxonomy, $object_type, $args ) use ( &$registered_object_type ) {
				$registered_object_type = $object_type;
				return true;
			}
		);

		Taxonomies::register_taxonomies();

		$this->assertEquals( 'nettertech_event', $registered_object_type );
	}

	/**
	 * Test register_taxonomies sets hierarchical to true.
	 *
	 * @return void
	 */
	public function test_register_taxonomies_is_hierarchical(): void {
		$args_passed = array();

		Functions\when( '_x' )->returnArg();
		Functions\when( '__' )->returnArg();

		Functions\when( 'register_taxonomy' )->alias(
			function ( $taxonomy, $object_type, $args ) use ( &$args_passed ) {
				$args_passed = $args;
				return true;
			}
		);

		Taxonomies::register_taxonomies();

		$this->assertTrue( $args_passed['hierarchical'] );
	}

	/**
	 * Test register_taxonomies enables REST API.
	 *
	 * @return void
	 */
	public function test_register_taxonomies_enables_rest(): void {
		$args_passed = array();

		Functions\when( '_x' )->returnArg();
		Functions\when( '__' )->returnArg();

		Functions\when( 'register_taxonomy' )->alias(
			function ( $taxonomy, $object_type, $args ) use ( &$args_passed ) {
				$args_passed = $args;
				return true;
			}
		);

		Taxonomies::register_taxonomies();

		$this->assertTrue( $args_passed['show_in_rest'] );
	}

	/**
	 * Test register_taxonomies sets rewrite slug.
	 *
	 * @return void
	 */
	public function test_register_taxonomies_sets_rewrite_slug(): void {
		$args_passed = array();

		Functions\when( '_x' )->returnArg();
		Functions\when( '__' )->returnArg();

		Functions\when( 'register_taxonomy' )->alias(
			function ( $taxonomy, $object_type, $args ) use ( &$args_passed ) {
				$args_passed = $args;
				return true;
			}
		);

		Taxonomies::register_taxonomies();

		$this->assertEquals( 'event-category', $args_passed['rewrite']['slug'] );
		$this->assertFalse( $args_passed['rewrite']['with_front'] );
	}

	// =========================================================================
	// get_event_categories() Tests
	// =========================================================================

	/**
	 * Test get_event_categories returns array of terms.
	 *
	 * @return void
	 */
	public function test_get_event_categories_returns_terms(): void {
		$mock_term        = new \stdClass();
		$mock_term->term_id = 1;
		$mock_term->name    = 'Test Category';

		Functions\when( 'wp_get_object_terms' )->justReturn( array( $mock_term ) );

		$result = Taxonomies::get_event_categories( 123 );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertEquals( 'Test Category', $result[0]->name );
	}

	/**
	 * Test get_event_categories returns empty array on error.
	 *
	 * @return void
	 */
	public function test_get_event_categories_returns_empty_on_error(): void {
		$wp_error = new \WP_Error( 'test_error', 'Test error message' );

		Functions\when( 'wp_get_object_terms' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = Taxonomies::get_event_categories( 123 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_event_categories uses correct taxonomy.
	 *
	 * @return void
	 */
	public function test_get_event_categories_uses_correct_taxonomy(): void {
		$taxonomy_used = '';

		Functions\when( 'wp_get_object_terms' )->alias(
			function ( $object_id, $taxonomy ) use ( &$taxonomy_used ) {
				$taxonomy_used = $taxonomy;
				return array();
			}
		);

		Taxonomies::get_event_categories( 123 );

		$this->assertEquals( Taxonomies::EVENT_CATEGORY, $taxonomy_used );
	}

	// =========================================================================
	// set_event_categories() Tests
	// =========================================================================

	/**
	 * Test set_event_categories calls wp_set_object_terms.
	 *
	 * @return void
	 */
	public function test_set_event_categories_calls_wp_function(): void {
		$function_called = false;
		$passed_ids      = array();

		Functions\when( 'wp_set_object_terms' )->alias(
			function ( $object_id, $terms, $taxonomy ) use ( &$function_called, &$passed_ids ) {
				$function_called = true;
				$passed_ids      = $terms;
				return array( 1, 2 );
			}
		);

		$result = Taxonomies::set_event_categories( 123, array( 1, 2 ) );

		$this->assertTrue( $function_called );
		$this->assertTrue( $result );
		$this->assertEquals( array( 1, 2 ), $passed_ids );
	}

	/**
	 * Test set_event_categories converts strings to integers.
	 *
	 * @return void
	 */
	public function test_set_event_categories_converts_to_integers(): void {
		$passed_terms = array();

		Functions\when( 'wp_set_object_terms' )->alias(
			function ( $object_id, $terms, $taxonomy ) use ( &$passed_terms ) {
				$passed_terms = $terms;
				return $terms;
			}
		);

		Taxonomies::set_event_categories( 123, array( '1', '2', '3' ) );

		$this->assertEquals( array( 1, 2, 3 ), $passed_terms );
	}

	/**
	 * Test set_event_categories returns false on error.
	 *
	 * @return void
	 */
	public function test_set_event_categories_returns_false_on_error(): void {
		$wp_error = new \WP_Error( 'test_error', 'Test error' );

		Functions\when( 'wp_set_object_terms' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = Taxonomies::set_event_categories( 123, array( 1, 2 ) );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// add_event_category() Tests
	// =========================================================================

	/**
	 * Test add_event_category appends category.
	 *
	 * @return void
	 */
	public function test_add_event_category_appends(): void {
		$append_flag = false;

		Functions\when( 'wp_set_object_terms' )->alias(
			function ( $object_id, $terms, $taxonomy, $append ) use ( &$append_flag ) {
				$append_flag = $append;
				return array( 1 );
			}
		);

		$result = Taxonomies::add_event_category( 123, 5 );

		$this->assertTrue( $append_flag );
		$this->assertTrue( $result );
	}

	/**
	 * Test add_event_category returns false on error.
	 *
	 * @return void
	 */
	public function test_add_event_category_returns_false_on_error(): void {
		$wp_error = new \WP_Error( 'test_error', 'Test error' );

		Functions\when( 'wp_set_object_terms' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = Taxonomies::add_event_category( 123, 5 );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// remove_event_category() Tests
	// =========================================================================

	/**
	 * Test remove_event_category calls wp_remove_object_terms.
	 *
	 * @return void
	 */
	public function test_remove_event_category_calls_wp_function(): void {
		$function_called   = false;
		$passed_event_id   = 0;
		$passed_category   = 0;

		Functions\when( 'wp_remove_object_terms' )->alias(
			function ( $object_id, $terms, $taxonomy ) use ( &$function_called, &$passed_event_id, &$passed_category ) {
				$function_called   = true;
				$passed_event_id   = $object_id;
				$passed_category   = $terms;
				return true;
			}
		);

		$result = Taxonomies::remove_event_category( 123, 5 );

		$this->assertTrue( $function_called );
		$this->assertEquals( 123, $passed_event_id );
		$this->assertEquals( 5, $passed_category );
		$this->assertTrue( $result );
	}

	/**
	 * Test remove_event_category returns false on error.
	 *
	 * @return void
	 */
	public function test_remove_event_category_returns_false_on_error(): void {
		$wp_error = new \WP_Error( 'test_error', 'Test error' );

		Functions\when( 'wp_remove_object_terms' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = Taxonomies::remove_event_category( 123, 5 );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// get_all_categories() Tests
	// =========================================================================

	/**
	 * Test get_all_categories returns terms.
	 *
	 * @return void
	 */
	public function test_get_all_categories_returns_terms(): void {
		// Clear cache first.
		Taxonomies::clear_category_cache();

		$mock_term1        = new \stdClass();
		$mock_term1->term_id = 1;
		$mock_term1->name    = 'Category 1';

		$mock_term2        = new \stdClass();
		$mock_term2->term_id = 2;
		$mock_term2->name    = 'Category 2';

		Functions\when( 'get_terms' )->justReturn( array( $mock_term1, $mock_term2 ) );

		$result = Taxonomies::get_all_categories();

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test get_all_categories returns empty array on error.
	 *
	 * @return void
	 */
	public function test_get_all_categories_returns_empty_on_error(): void {
		Taxonomies::clear_category_cache();

		$wp_error = new \WP_Error( 'test_error', 'Test error' );

		Functions\when( 'get_terms' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = Taxonomies::get_all_categories();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_all_categories uses cache on second call.
	 *
	 * @return void
	 */
	public function test_get_all_categories_uses_cache(): void {
		Taxonomies::clear_category_cache();

		$call_count = 0;
		$mock_term  = new \stdClass();
		$mock_term->term_id = 1;
		$mock_term->name    = 'Cached Category';

		Functions\when( 'get_terms' )->alias(
			function () use ( &$call_count, $mock_term ) {
				++$call_count;
				return array( $mock_term );
			}
		);

		// First call - should hit get_terms.
		$result1 = Taxonomies::get_all_categories();

		// Second call - should use cache.
		$result2 = Taxonomies::get_all_categories();

		$this->assertEquals( 1, $call_count );
		$this->assertEquals( $result1, $result2 );
	}

	/**
	 * Test get_all_categories respects hide_empty parameter.
	 *
	 * @return void
	 */
	public function test_get_all_categories_respects_hide_empty(): void {
		Taxonomies::clear_category_cache();

		$passed_args = array();

		Functions\when( 'get_terms' )->alias(
			function ( $args ) use ( &$passed_args ) {
				$passed_args = $args;
				return array();
			}
		);

		// Call with hide_empty = true.
		Taxonomies::get_all_categories( true );

		$this->assertTrue( $passed_args['hide_empty'] );

		// Clear cache and call with hide_empty = false.
		Taxonomies::clear_category_cache();

		Taxonomies::get_all_categories( false );

		$this->assertFalse( $passed_args['hide_empty'] );
	}

	/**
	 * Test get_all_categories caches separately by hide_empty.
	 *
	 * @return void
	 */
	public function test_get_all_categories_caches_by_hide_empty(): void {
		Taxonomies::clear_category_cache();

		$call_count = 0;

		Functions\when( 'get_terms' )->alias(
			function ( $args ) use ( &$call_count ) {
				++$call_count;
				$term        = new \stdClass();
				$term->term_id = $call_count;
				$term->name    = 'Term ' . $call_count;
				return array( $term );
			}
		);

		// Call with hide_empty = false.
		$result1 = Taxonomies::get_all_categories( false );

		// Call with hide_empty = true (should be separate cache).
		$result2 = Taxonomies::get_all_categories( true );

		// Should have made 2 calls.
		$this->assertEquals( 2, $call_count );

		// Results should be different (different term_ids).
		$this->assertNotEquals( $result1[0]->term_id, $result2[0]->term_id );
	}

	// =========================================================================
	// clear_category_cache() Tests
	// =========================================================================

	/**
	 * Test clear_category_cache clears the cache.
	 *
	 * @return void
	 */
	public function test_clear_category_cache_clears_cache(): void {
		// First populate cache.
		$mock_term        = new \stdClass();
		$mock_term->term_id = 1;
		$mock_term->name    = 'Test';

		Functions\when( 'get_terms' )->justReturn( array( $mock_term ) );

		Taxonomies::get_all_categories();

		// Clear and verify cache is cleared by checking get_terms is called again.
		Taxonomies::clear_category_cache();

		$call_count = 0;
		Functions\when( 'get_terms' )->alias(
			function () use ( &$call_count, $mock_term ) {
				++$call_count;
				return array( $mock_term );
			}
		);

		Taxonomies::get_all_categories();

		$this->assertEquals( 1, $call_count );
	}

	// =========================================================================
	// get_category_by_slug() Tests
	// =========================================================================

	/**
	 * Test get_category_by_slug returns term.
	 *
	 * @return void
	 */
	public function test_get_category_by_slug_returns_term(): void {
		// Create a mock WP_Term-like object.
		$mock_term          = new \WP_Term();
		$mock_term->term_id = 1;
		$mock_term->name    = 'Test Category';
		$mock_term->slug    = 'test-slug';

		Functions\when( 'get_term_by' )->justReturn( $mock_term );

		$result = Taxonomies::get_category_by_slug( 'test-slug' );

		$this->assertInstanceOf( \WP_Term::class, $result );
	}

	/**
	 * Test get_category_by_slug returns null when not found.
	 *
	 * @return void
	 */
	public function test_get_category_by_slug_returns_null_when_not_found(): void {
		Functions\when( 'get_term_by' )->justReturn( false );

		$result = Taxonomies::get_category_by_slug( 'nonexistent' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_category_by_slug uses correct parameters.
	 *
	 * @return void
	 */
	public function test_get_category_by_slug_uses_correct_params(): void {
		$passed_field    = '';
		$passed_value    = '';
		$passed_taxonomy = '';

		Functions\when( 'get_term_by' )->alias(
			function ( $field, $value, $taxonomy ) use ( &$passed_field, &$passed_value, &$passed_taxonomy ) {
				$passed_field    = $field;
				$passed_value    = $value;
				$passed_taxonomy = $taxonomy;
				return false;
			}
		);

		Taxonomies::get_category_by_slug( 'my-slug' );

		$this->assertEquals( 'slug', $passed_field );
		$this->assertEquals( 'my-slug', $passed_value );
		$this->assertEquals( Taxonomies::EVENT_CATEGORY, $passed_taxonomy );
	}

	// =========================================================================
	// create_category() Tests
	// =========================================================================

	/**
	 * Test create_category returns term ID on success.
	 *
	 * @return void
	 */
	public function test_create_category_returns_term_id(): void {
		Functions\when( 'wp_insert_term' )->justReturn(
			array(
				'term_id'          => 42,
				'term_taxonomy_id' => 42,
			)
		);

		$result = Taxonomies::create_category( 'New Category' );

		$this->assertEquals( 42, $result );
	}

	/**
	 * Test create_category returns false on error.
	 *
	 * @return void
	 */
	public function test_create_category_returns_false_on_error(): void {
		$wp_error = new \WP_Error( 'term_exists', 'Term already exists' );

		Functions\when( 'wp_insert_term' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = Taxonomies::create_category( 'Existing Category' );

		$this->assertFalse( $result );
	}

	/**
	 * Test create_category passes slug when provided.
	 *
	 * @return void
	 */
	public function test_create_category_passes_slug(): void {
		$passed_args = array();

		Functions\when( 'wp_insert_term' )->alias(
			function ( $name, $taxonomy, $args ) use ( &$passed_args ) {
				$passed_args = $args;
				return array( 'term_id' => 1 );
			}
		);

		Taxonomies::create_category( 'Test', 'custom-slug' );

		$this->assertArrayHasKey( 'slug', $passed_args );
		$this->assertEquals( 'custom-slug', $passed_args['slug'] );
	}

	/**
	 * Test create_category does not pass empty slug.
	 *
	 * @return void
	 */
	public function test_create_category_skips_empty_slug(): void {
		$passed_args = array();

		Functions\when( 'wp_insert_term' )->alias(
			function ( $name, $taxonomy, $args ) use ( &$passed_args ) {
				$passed_args = $args;
				return array( 'term_id' => 1 );
			}
		);

		Taxonomies::create_category( 'Test', '' );

		$this->assertArrayNotHasKey( 'slug', $passed_args );
	}

	/**
	 * Test create_category passes parent when provided.
	 *
	 * @return void
	 */
	public function test_create_category_passes_parent(): void {
		$passed_args = array();

		Functions\when( 'wp_insert_term' )->alias(
			function ( $name, $taxonomy, $args ) use ( &$passed_args ) {
				$passed_args = $args;
				return array( 'term_id' => 1 );
			}
		);

		Taxonomies::create_category( 'Child Category', '', 5 );

		$this->assertArrayHasKey( 'parent', $passed_args );
		$this->assertEquals( 5, $passed_args['parent'] );
	}

	/**
	 * Test create_category does not pass zero parent.
	 *
	 * @return void
	 */
	public function test_create_category_skips_zero_parent(): void {
		$passed_args = array();

		Functions\when( 'wp_insert_term' )->alias(
			function ( $name, $taxonomy, $args ) use ( &$passed_args ) {
				$passed_args = $args;
				return array( 'term_id' => 1 );
			}
		);

		Taxonomies::create_category( 'Test', '', 0 );

		$this->assertArrayNotHasKey( 'parent', $passed_args );
	}

	/**
	 * Test create_category passes both slug and parent.
	 *
	 * @return void
	 */
	public function test_create_category_passes_slug_and_parent(): void {
		$passed_args = array();

		Functions\when( 'wp_insert_term' )->alias(
			function ( $name, $taxonomy, $args ) use ( &$passed_args ) {
				$passed_args = $args;
				return array( 'term_id' => 1 );
			}
		);

		Taxonomies::create_category( 'Child', 'child-slug', 10 );

		$this->assertEquals( 'child-slug', $passed_args['slug'] );
		$this->assertEquals( 10, $passed_args['parent'] );
	}

	// =========================================================================
	// sync_from_custom_tables() Tests (BUG-003)
	// =========================================================================

	/**
	 * Test sync_from_custom_tables method exists and uses wp_insert_term.
	 *
	 * Regression: BUG-003. Migrated categories lived only in the custom
	 * nettertech_events_categories table; the WordPress taxonomy had 0 terms.
	 *
	 * @return void
	 */
	public function test_sync_from_custom_tables_uses_wp_insert_term(): void {
		$reflection = new \ReflectionMethod( Taxonomies::class, 'sync_from_custom_tables' );
		$file       = $reflection->getFileName();
		$start      = $reflection->getStartLine();
		$end        = $reflection->getEndLine();

		$lines  = array_slice( file( $file ), $start - 1, $end - $start + 1 );
		$source = implode( '', $lines );

		$this->assertStringContainsString(
			'wp_insert_term',
			$source,
			'sync_from_custom_tables must create taxonomy terms via wp_insert_term (BUG-003)'
		);

		$this->assertStringContainsString(
			'wp_set_object_terms',
			$source,
			'sync_from_custom_tables must associate events via wp_set_object_terms (BUG-003)'
		);

		$this->assertStringContainsString(
			'nettertech_events_category_taxonomy_synced',
			$source,
			'sync_from_custom_tables must set option to prevent re-execution'
		);
	}

	/**
	 * Test sync_from_custom_tables skips if already synced.
	 *
	 * @return void
	 */
	public function test_sync_from_custom_tables_skips_if_already_synced(): void {
		Functions\when( 'get_option' )->alias(
			function ( $key ) {
				if ( 'nettertech_events_category_taxonomy_synced' === $key ) {
					return '1';
				}
				return false;
			}
		);

		$result = Taxonomies::sync_from_custom_tables();
		$this->assertFalse( $result );
	}

	// =========================================================================
	// Static Method Tests
	// =========================================================================

	/**
	 * Test all public methods are static.
	 *
	 * @return void
	 */
	public function test_all_public_methods_are_static(): void {
		$reflection = new \ReflectionClass( Taxonomies::class );
		$methods    = $reflection->getMethods( \ReflectionMethod::IS_PUBLIC );

		foreach ( $methods as $method ) {
			$this->assertTrue(
				$method->isStatic(),
				"Method {$method->getName()} should be static"
			);
		}
	}

	// =========================================================================
	// Cleanup
	// =========================================================================

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Taxonomies::clear_category_cache();
		parent::tearDown();
	}
}
