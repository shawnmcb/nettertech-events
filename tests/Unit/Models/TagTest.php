<?php
/**
 * Tag model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use Brain\Monkey\Functions;
use NetterTechEvents\Models\Tag;

/**
 * Test Tag model functionality.
 */
class TagTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates Tag from valid data.
	 *
	 * @covers \NetterTechEvents\Models\Tag::from_row
	 * @return void
	 */
	public function test_from_row_creates_tag_from_valid_data(): void {
		$row = (object) array(
			'id'         => 5,
			'name'       => 'Live Music',
			'slug'       => 'live-music',
			'created_at' => '2026-01-15 10:00:00',
			'updated_at' => '2026-01-20 14:30:00',
		);

		$tag = Tag::from_row( $row );

		$this->assertSame( 5, $tag->id );
		$this->assertSame( 'Live Music', $tag->name );
		$this->assertSame( 'live-music', $tag->slug );
		$this->assertSame( '2026-01-15 10:00:00', $tag->created_at );
		$this->assertSame( '2026-01-20 14:30:00', $tag->updated_at );
	}

	/**
	 * Test from_row handles missing fields with defaults.
	 *
	 * @covers \NetterTechEvents\Models\Tag::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_fields_with_defaults(): void {
		$row = (object) array();

		$tag = Tag::from_row( $row );

		$this->assertNull( $tag->id );
		$this->assertSame( '', $tag->name );
		$this->assertSame( '', $tag->slug );
		$this->assertNull( $tag->created_at );
		$this->assertNull( $tag->updated_at );
	}

	// =========================================================================
	// to_array Tests
	// =========================================================================

	/**
	 * Test to_array round-trip preserves values.
	 *
	 * @covers \NetterTechEvents\Models\Tag::to_array
	 * @return void
	 */
	public function test_to_array_round_trip_preserves_values(): void {
		$tag       = new Tag();
		$tag->name = 'Outdoor';
		$tag->slug = 'outdoor';

		$array = $tag->to_array();

		$this->assertSame( 'Outdoor', $array['name'] );
		$this->assertSame( 'outdoor', $array['slug'] );
		$this->assertCount( 2, $array );
	}

	// =========================================================================
	// validate Tests
	// =========================================================================

	/**
	 * Test validate returns empty array for valid tag.
	 *
	 * @covers \NetterTechEvents\Models\Tag::validate
	 * @return void
	 */
	public function test_validate_returns_empty_for_valid_tag(): void {
		$tag       = new Tag();
		$tag->name = 'Jazz';
		$tag->slug = 'jazz';

		$errors = $tag->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate returns error for missing name.
	 *
	 * @covers \NetterTechEvents\Models\Tag::validate
	 * @return void
	 */
	public function test_validate_returns_error_for_missing_name(): void {
		$tag       = new Tag();
		$tag->slug = 'jazz';

		$errors = $tag->validate();

		$this->assertContains( 'Tag name is required.', $errors );
	}

	/**
	 * Test validate returns error for missing slug.
	 *
	 * @covers \NetterTechEvents\Models\Tag::validate
	 * @return void
	 */
	public function test_validate_returns_error_for_missing_slug(): void {
		$tag       = new Tag();
		$tag->name = 'Jazz';

		$errors = $tag->validate();

		$this->assertContains( 'Tag slug is required.', $errors );
	}

	// =========================================================================
	// get_permalink Tests
	// =========================================================================

	/**
	 * Test get_permalink returns correct URL format.
	 *
	 * @covers \NetterTechEvents\Models\Tag::get_permalink
	 * @return void
	 */
	public function test_get_permalink_returns_correct_url(): void {
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);

		$tag       = new Tag();
		$tag->slug = 'live-music';

		$this->assertSame( 'http://example.com/events/tag/live-music/', $tag->get_permalink() );
	}

	// =========================================================================
	// get_formats Tests
	// =========================================================================

	/**
	 * Test get_formats returns correct count matching to_array.
	 *
	 * @covers \NetterTechEvents\Models\Tag::get_formats
	 * @return void
	 */
	public function test_get_formats_returns_correct_count(): void {
		$tag       = new Tag();
		$tag->name = 'Test';
		$tag->slug = 'test';
		$formats   = $tag->get_formats();
		$array     = $tag->to_array();

		$this->assertCount( count( $array ), $formats );
	}
}
