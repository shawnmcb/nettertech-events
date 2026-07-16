<?php
/**
 * ShadowPostType unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\ShadowPostType;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test ShadowPostType registration and permalink filtering.
 */
class ShadowPostTypeTest extends \NetterTechEventsTestCase {

	/**
	 * @test
	 */
	public function test_register_adds_hooks(): void {
		$hooks_added   = array();
		$filters_added = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$hooks_added ) {
				$hooks_added[] = $hook;
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) use ( &$filters_added ) {
				$filters_added[] = $hook;
				return true;
			}
		);

		ShadowPostType::register();

		$this->assertContains( 'init', $hooks_added );
		$this->assertContains( 'template_redirect', $hooks_added );
		$this->assertContains( 'post_type_link', $filters_added );
	}

	/**
	 * @test
	 */
	public function test_post_type_constant(): void {
		$this->assertSame( 'nettertech_event', ShadowPostType::POST_TYPE );
	}

	/**
	 * @test
	 */
	public function test_register_post_type_calls_wp(): void {
		$registered_args = null;

		Functions\when( 'register_post_type' )->alias(
			function ( $post_type, $args ) use ( &$registered_args ) {
				$registered_args = array( 'post_type' => $post_type, 'args' => $args );
			}
		);

		ShadowPostType::register_post_type();

		$this->assertNotNull( $registered_args );
		$this->assertSame( 'nettertech_event', $registered_args['post_type'] );
		$this->assertFalse( $registered_args['args']['public'] );
		$this->assertTrue( $registered_args['args']['publicly_queryable'] );
		$this->assertFalse( $registered_args['args']['exclude_from_search'] );
		$this->assertFalse( $registered_args['args']['show_ui'] );
		$this->assertTrue( $registered_args['args']['show_in_rest'] );
		$this->assertSame( 'nettertech-events', $registered_args['args']['rest_base'] );
		$this->assertFalse( $registered_args['args']['has_archive'] );
		$this->assertFalse( $registered_args['args']['rewrite'] );
		$this->assertSame( array( 'title' ), $registered_args['args']['supports'] );
	}

	/**
	 * @test
	 */
	public function test_filter_permalink_returns_event_url_for_shadow_post(): void {
		$post              = Mockery::mock( 'WP_Post' );
		$post->post_type   = 'nettertech_event';
		$post->post_name   = 'summer-concert';

		// Override home_url to actually pass through the path.
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.test' . $path;
			}
		);

		$result = ShadowPostType::filter_permalink( 'http://example.test/?nettertech_events_event=summer-concert', $post );

		$this->assertSame( 'http://example.test/events/summer-concert/', $result );
	}

	/**
	 * @test
	 */
	public function test_filter_permalink_passes_through_non_shadow_posts(): void {
		$post            = Mockery::mock( 'WP_Post' );
		$post->post_type = 'post';
		$post->post_name = 'hello-world';

		$original = 'http://example.test/hello-world/';
		$result   = ShadowPostType::filter_permalink( $original, $post );

		$this->assertSame( $original, $result );
	}

	/**
	 * @test
	 */
	public function test_register_includes_rest_search_filter(): void {
		$filters_added = array();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) use ( &$filters_added ) {
				$filters_added[] = $hook;
				return true;
			}
		);

		ShadowPostType::register();

		$this->assertContains( 'wp_rest_search_handlers', $filters_added );
	}

	/**
	 * @test
	 */
	public function test_add_to_rest_search_injects_nte_event_subtype(): void {
		$handler = new \WP_REST_Post_Search_Handler();

		$ref      = new \ReflectionProperty( \WP_REST_Search_Handler::class, 'subtypes' );
		$original = $ref->getValue( $handler );

		$this->assertNotContains( 'nettertech_event', $original );

		$handlers = ShadowPostType::add_to_rest_search( array( $handler ) );

		$subtypes = $ref->getValue( $handlers[0] );
		$this->assertContains( 'nettertech_event', $subtypes );
	}

	/**
	 * @test
	 */
	public function test_add_to_rest_search_does_not_duplicate(): void {
		$handler = new \WP_REST_Post_Search_Handler();

		// Call twice.
		ShadowPostType::add_to_rest_search( array( $handler ) );
		$handlers = ShadowPostType::add_to_rest_search( array( $handler ) );

		$ref      = new \ReflectionProperty( \WP_REST_Search_Handler::class, 'subtypes' );
		$subtypes = $ref->getValue( $handlers[0] );

		$count = array_count_values( $subtypes );
		$this->assertSame( 1, $count['nettertech_event'] );
	}

	/**
	 * @test
	 */
	public function test_add_to_rest_search_passes_through_non_post_handlers(): void {
		$mock_handler = Mockery::mock( 'WP_REST_Search_Handler' );

		$handlers = ShadowPostType::add_to_rest_search( array( $mock_handler ) );

		$this->assertCount( 1, $handlers );
		$this->assertSame( $mock_handler, $handlers[0] );
	}

	/**
	 * Taxonomy slug constants are stable (NTE-002j).
	 *
	 * @test
	 */
	public function test_taxonomy_constants(): void {
		$this->assertSame( 'nettertech_event_category', ShadowPostType::CATEGORY_TAXONOMY );
		$this->assertSame( 'nettertech_event_tag', ShadowPostType::TAG_TAXONOMY );
	}

	/**
	 * register() also hooks register_taxonomies on init (NTE-002j).
	 *
	 * @test
	 */
	public function test_register_hooks_register_taxonomies(): void {
		$init_callbacks = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$init_callbacks ) {
				if ( 'init' === $hook ) {
					$init_callbacks[] = $callback;
				}
				return true;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );

		ShadowPostType::register();

		// Two init hooks: register_post_type and register_taxonomies.
		$this->assertCount( 2, $init_callbacks );
	}

	/**
	 * register_taxonomies() registers both category and tag taxonomies
	 * against the nettertech_event shadow CPT (NTE-002j).
	 *
	 * @test
	 */
	public function test_register_taxonomies_registers_both_taxonomies(): void {
		$registered = array();

		Functions\when( 'register_taxonomy' )->alias(
			function ( $taxonomy, $object_type, $args ) use ( &$registered ) {
				$registered[ $taxonomy ] = array(
					'object_type' => $object_type,
					'args'        => $args,
				);
			}
		);

		ShadowPostType::register_taxonomies();

		$this->assertArrayHasKey( 'nettertech_event_category', $registered );
		$this->assertArrayHasKey( 'nettertech_event_tag', $registered );

		// Both attach to nettertech_event shadow CPT.
		$this->assertSame( 'nettertech_event', $registered['nettertech_event_category']['object_type'] );
		$this->assertSame( 'nettertech_event', $registered['nettertech_event_tag']['object_type'] );

		// Category is hierarchical; tag is not.
		$this->assertTrue( $registered['nettertech_event_category']['args']['hierarchical'] );
		$this->assertFalse( $registered['nettertech_event_tag']['args']['hierarchical'] );

		// Both are public + queryable so SEO plugins can discover them.
		$this->assertTrue( $registered['nettertech_event_category']['args']['public'] );
		$this->assertTrue( $registered['nettertech_event_category']['args']['publicly_queryable'] );
		$this->assertTrue( $registered['nettertech_event_tag']['args']['public'] );
		$this->assertTrue( $registered['nettertech_event_tag']['args']['publicly_queryable'] );

		// Both have show_ui=false — read-only, canonical data is in NTE tables.
		$this->assertFalse( $registered['nettertech_event_category']['args']['show_ui'] );
		$this->assertFalse( $registered['nettertech_event_tag']['args']['show_ui'] );
	}
}
