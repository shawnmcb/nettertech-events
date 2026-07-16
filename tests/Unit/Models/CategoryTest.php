<?php
/**
 * Category model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use Brain\Monkey\Functions;
use NetterTechEvents\Models\Category;

/**
 * Test Category model functionality.
 */
class CategoryTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates Category from valid data.
	 *
	 * @covers \NetterTechEvents\Models\Category::from_row
	 * @return void
	 */
	public function test_from_row_creates_category_from_valid_data(): void {
		$row = (object) array(
			'id'                => 3,
			'name'              => 'Concerts',
			'slug'              => 'concerts',
			'description'       => 'Live music events',
			'parent_id'         => 1,
			'featured_image_id' => 42,
			'sort_order'        => 5,
			'created_at'        => '2026-01-10 09:00:00',
			'updated_at'        => '2026-01-15 11:30:00',
		);

		$category = Category::from_row( $row );

		$this->assertSame( 3, $category->id );
		$this->assertSame( 'Concerts', $category->name );
		$this->assertSame( 'concerts', $category->slug );
		$this->assertSame( 'Live music events', $category->description );
		$this->assertSame( 1, $category->parent_id );
		$this->assertSame( 42, $category->featured_image_id );
		$this->assertSame( 5, $category->sort_order );
		$this->assertSame( '2026-01-10 09:00:00', $category->created_at );
		$this->assertSame( '2026-01-15 11:30:00', $category->updated_at );
	}

	/**
	 * Test from_row handles missing fields with defaults.
	 *
	 * @covers \NetterTechEvents\Models\Category::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_fields_with_defaults(): void {
		$row = (object) array();

		$category = Category::from_row( $row );

		$this->assertNull( $category->id );
		$this->assertSame( '', $category->name );
		$this->assertSame( '', $category->slug );
		$this->assertSame( '', $category->description );
		$this->assertNull( $category->parent_id );
		$this->assertNull( $category->featured_image_id );
		$this->assertSame( 0, $category->sort_order );
		$this->assertNull( $category->created_at );
		$this->assertNull( $category->updated_at );
	}

	/**
	 * Test from_row handles null parent_id.
	 *
	 * @covers \NetterTechEvents\Models\Category::from_row
	 * @return void
	 */
	public function test_from_row_handles_null_parent_id(): void {
		$row = (object) array(
			'id'        => 1,
			'parent_id' => null,
		);

		$category = Category::from_row( $row );

		$this->assertNull( $category->parent_id );
	}

	// =========================================================================
	// to_array Tests
	// =========================================================================

	/**
	 * Test to_array round-trip preserves values.
	 *
	 * @covers \NetterTechEvents\Models\Category::to_array
	 * @return void
	 */
	public function test_to_array_round_trip_preserves_values(): void {
		$category                    = new Category();
		$category->name              = 'Workshops';
		$category->slug              = 'workshops';
		$category->description       = 'Hands-on learning events';
		$category->parent_id         = 2;
		$category->featured_image_id = 99;
		$category->sort_order        = 3;

		$array = $category->to_array();

		$this->assertSame( 'Workshops', $array['name'] );
		$this->assertSame( 'workshops', $array['slug'] );
		$this->assertSame( 'Hands-on learning events', $array['description'] );
		$this->assertSame( 2, $array['parent_id'] );
		$this->assertSame( 99, $array['featured_image_id'] );
		$this->assertSame( 3, $array['sort_order'] );
		$this->assertCount( 6, $array );
	}

	// =========================================================================
	// validate Tests
	// =========================================================================

	/**
	 * Test validate returns empty array for valid category.
	 *
	 * @covers \NetterTechEvents\Models\Category::validate
	 * @return void
	 */
	public function test_validate_returns_empty_for_valid_category(): void {
		$category       = new Category();
		$category->name = 'Concerts';
		$category->slug = 'concerts';

		$errors = $category->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate returns error for missing name.
	 *
	 * @covers \NetterTechEvents\Models\Category::validate
	 * @return void
	 */
	public function test_validate_returns_error_for_missing_name(): void {
		$category       = new Category();
		$category->slug = 'concerts';

		$errors = $category->validate();

		$this->assertContains( 'Category name is required.', $errors );
	}

	/**
	 * Test validate returns error for missing slug.
	 *
	 * @covers \NetterTechEvents\Models\Category::validate
	 * @return void
	 */
	public function test_validate_returns_error_for_missing_slug(): void {
		$category       = new Category();
		$category->name = 'Concerts';

		$errors = $category->validate();

		$this->assertContains( 'Category slug is required.', $errors );
	}

	/**
	 * Test validate prevents self-referencing parent.
	 *
	 * @covers \NetterTechEvents\Models\Category::validate
	 * @return void
	 */
	public function test_validate_prevents_self_referencing_parent(): void {
		$category            = new Category();
		$category->id        = 5;
		$category->name      = 'Concerts';
		$category->slug      = 'concerts';
		$category->parent_id = 5;

		$errors = $category->validate();

		$this->assertContains( 'Category cannot be its own parent.', $errors );
	}

	// =========================================================================
	// is_top_level Tests
	// =========================================================================

	/**
	 * Test is_top_level returns true when parent_id is null.
	 *
	 * @covers \NetterTechEvents\Models\Category::is_top_level
	 * @return void
	 */
	public function test_is_top_level_returns_true_when_parent_id_null(): void {
		$category            = new Category();
		$category->parent_id = null;

		$this->assertTrue( $category->is_top_level() );
	}

	/**
	 * Test is_top_level returns false when parent_id is set.
	 *
	 * @covers \NetterTechEvents\Models\Category::is_top_level
	 * @return void
	 */
	public function test_is_top_level_returns_false_when_parent_id_set(): void {
		$category            = new Category();
		$category->parent_id = 3;

		$this->assertFalse( $category->is_top_level() );
	}

	// =========================================================================
	// get_permalink Tests
	// =========================================================================

	/**
	 * Test get_permalink returns correct URL format.
	 *
	 * @covers \NetterTechEvents\Models\Category::get_permalink
	 * @return void
	 */
	public function test_get_permalink_returns_correct_url(): void {
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);

		$category       = new Category();
		$category->slug = 'concerts';

		$this->assertSame( 'http://example.com/events/category/concerts/', $category->get_permalink() );
	}

	// =========================================================================
	// get_featured_image_url Tests
	// =========================================================================

	/**
	 * Test get_featured_image_url returns URL when image exists.
	 *
	 * @covers \NetterTechEvents\Models\Category::get_featured_image_url
	 * @return void
	 */
	public function test_get_featured_image_url_returns_url_when_image_exists(): void {
		$category                    = new Category();
		$category->featured_image_id = 42;

		$url = $category->get_featured_image_url();

		$this->assertSame( 'http://example.com/wp-content/uploads/test-42.jpg', $url );
	}

	/**
	 * Test get_featured_image_url returns null when no image.
	 *
	 * @covers \NetterTechEvents\Models\Category::get_featured_image_url
	 * @return void
	 */
	public function test_get_featured_image_url_returns_null_when_no_image(): void {
		$category                    = new Category();
		$category->featured_image_id = null;

		$this->assertNull( $category->get_featured_image_url() );
	}

	// =========================================================================
	// get_formats Tests
	// =========================================================================

	/**
	 * Test get_formats returns correct count matching to_array.
	 *
	 * @covers \NetterTechEvents\Models\Category::get_formats
	 * @return void
	 */
	public function test_get_formats_returns_correct_count(): void {
		$category       = new Category();
		$category->name = 'Test';
		$category->slug = 'test';
		$formats        = $category->get_formats();
		$array          = $category->to_array();

		$this->assertCount( count( $array ), $formats );
	}
}
