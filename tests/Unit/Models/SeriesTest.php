<?php
/**
 * Series model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\Series;
use Brain\Monkey\Functions;

/**
 * Test Series model functionality.
 *
 * @covers \NetterTechEvents\Models\Series
 */
class SeriesTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates Series from valid data.
	 *
	 * @covers \NetterTechEvents\Models\Series::from_row
	 * @return void
	 */
	public function test_from_row_creates_series_from_valid_data(): void {
		$row = (object) array(
			'id'                => 1,
			'title'             => 'Test Series',
			'slug'              => 'test-series',
			'description'       => 'A test series.',
			'featured_image_id' => 10,
			'pass_enabled'      => 1,
			'pass_price'        => 49.99,
			'created_at'        => '2026-01-01 00:00:00',
			'updated_at'        => '2026-01-02 00:00:00',
		);

		$series = Series::from_row( $row );

		$this->assertSame( 1, $series->id );
		$this->assertSame( 'Test Series', $series->title );
		$this->assertSame( 'test-series', $series->slug );
		$this->assertSame( 'A test series.', $series->description );
		$this->assertSame( 10, $series->featured_image_id );
		$this->assertTrue( $series->pass_enabled );
		$this->assertSame( 49.99, $series->pass_price );
		$this->assertSame( '2026-01-01 00:00:00', $series->created_at );
		$this->assertSame( '2026-01-02 00:00:00', $series->updated_at );
	}

	/**
	 * Test from_row handles missing optional fields.
	 *
	 * @covers \NetterTechEvents\Models\Series::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_optional_fields(): void {
		$row = (object) array(
			'id'    => 2,
			'title' => 'Minimal Series',
			'slug'  => 'minimal-series',
		);

		$series = Series::from_row( $row );

		$this->assertSame( 2, $series->id );
		$this->assertSame( '', $series->description );
		$this->assertNull( $series->featured_image_id );
		$this->assertFalse( $series->pass_enabled );
		$this->assertNull( $series->pass_price );
		$this->assertNull( $series->created_at );
		$this->assertNull( $series->updated_at );
	}

	// =========================================================================
	// to_array Tests
	// =========================================================================

	/**
	 * Test to_array round-trip preserves values.
	 *
	 * @covers \NetterTechEvents\Models\Series::to_array
	 * @return void
	 */
	public function test_to_array_round_trip_preserves_values(): void {
		$row = (object) array(
			'id'                => 5,
			'title'             => 'Round Trip Series',
			'slug'              => 'round-trip-series',
			'description'       => 'Description here.',
			'featured_image_id' => 20,
			'pass_enabled'      => 1,
			'pass_price'        => 25.00,
		);

		$series = Series::from_row( $row );
		$array  = $series->to_array();

		$this->assertSame( 'Round Trip Series', $array['title'] );
		$this->assertSame( 'round-trip-series', $array['slug'] );
		$this->assertSame( 'Description here.', $array['description'] );
		$this->assertSame( 20, $array['featured_image_id'] );
		$this->assertSame( 1, $array['pass_enabled'] );
		$this->assertSame( 25.00, $array['pass_price'] );
	}

	// =========================================================================
	// validate Tests
	// =========================================================================

	/**
	 * Test validate returns empty for valid series.
	 *
	 * @covers \NetterTechEvents\Models\Series::validate
	 * @return void
	 */
	public function test_validate_returns_empty_for_valid_series(): void {
		$series        = new Series();
		$series->title = 'Valid Series';
		$series->slug  = 'valid-series';

		$errors = $series->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate requires title.
	 *
	 * @covers \NetterTechEvents\Models\Series::validate
	 * @return void
	 */
	public function test_validate_requires_title(): void {
		$series       = new Series();
		$series->slug = 'has-slug';

		$errors = $series->validate();

		$this->assertContains( 'Series title is required.', $errors );
	}

	/**
	 * Test validate requires slug.
	 *
	 * @covers \NetterTechEvents\Models\Series::validate
	 * @return void
	 */
	public function test_validate_requires_slug(): void {
		$series        = new Series();
		$series->title = 'Has Title';

		$errors = $series->validate();

		$this->assertContains( 'Series slug is required.', $errors );
	}

	/**
	 * Test validate requires valid price when pass enabled.
	 *
	 * @covers \NetterTechEvents\Models\Series::validate
	 * @return void
	 */
	public function test_validate_requires_price_when_pass_enabled(): void {
		$series               = new Series();
		$series->title        = 'Pass Series';
		$series->slug         = 'pass-series';
		$series->pass_enabled = true;
		$series->pass_price   = null;

		$errors = $series->validate();

		$this->assertContains( 'Series pass requires a valid price.', $errors );
	}

	// =========================================================================
	// has_pass Tests
	// =========================================================================

	/**
	 * Test has_pass returns true when enabled with price.
	 *
	 * @covers \NetterTechEvents\Models\Series::has_pass
	 * @return void
	 */
	public function test_has_pass_returns_true_when_enabled_with_price(): void {
		$series               = new Series();
		$series->pass_enabled = true;
		$series->pass_price   = 29.99;

		$this->assertTrue( $series->has_pass() );
	}

	/**
	 * Test has_pass returns false when pass not enabled.
	 *
	 * @covers \NetterTechEvents\Models\Series::has_pass
	 * @return void
	 */
	public function test_has_pass_returns_false_when_not_enabled(): void {
		$series               = new Series();
		$series->pass_enabled = false;
		$series->pass_price   = 29.99;

		$this->assertFalse( $series->has_pass() );
	}

	/**
	 * Test has_pass returns false when pass price is null.
	 *
	 * @covers \NetterTechEvents\Models\Series::has_pass
	 * @return void
	 */
	public function test_has_pass_returns_false_when_price_null(): void {
		$series               = new Series();
		$series->pass_enabled = true;
		$series->pass_price   = null;

		$this->assertFalse( $series->has_pass() );
	}

	// =========================================================================
	// get_formatted_pass_price Tests
	// =========================================================================

	/**
	 * Test get_formatted_pass_price formats price when pass available.
	 *
	 * The wc_price function is stubbed in the base test case, so
	 * function_exists('wc_price') returns true in the test environment.
	 * The stub returns '$' . number_format(price, 2).
	 *
	 * @covers \NetterTechEvents\Models\Series::get_formatted_pass_price
	 * @return void
	 */
	public function test_get_formatted_pass_price_formats_price(): void {
		$series               = new Series();
		$series->pass_enabled = true;
		$series->pass_price   = 29.99;

		$this->assertSame( '$29.99', $series->get_formatted_pass_price() );
	}

	/**
	 * Test get_formatted_pass_price returns empty when no pass.
	 *
	 * @covers \NetterTechEvents\Models\Series::get_formatted_pass_price
	 * @return void
	 */
	public function test_get_formatted_pass_price_returns_empty_when_no_pass(): void {
		$series               = new Series();
		$series->pass_enabled = false;

		$this->assertSame( '', $series->get_formatted_pass_price() );
	}

	/**
	 * Test get_formatted_pass_price returns empty when price is null.
	 *
	 * @covers \NetterTechEvents\Models\Series::get_formatted_pass_price
	 * @return void
	 */
	public function test_get_formatted_pass_price_returns_empty_when_price_null(): void {
		$series               = new Series();
		$series->pass_enabled = true;
		$series->pass_price   = null;

		$this->assertSame( '', $series->get_formatted_pass_price() );
	}

	// =========================================================================
	// get_permalink Tests
	// =========================================================================

	/**
	 * Test get_permalink builds path from slug.
	 *
	 * @covers \NetterTechEvents\Models\Series::get_permalink
	 * @return void
	 */
	public function test_get_permalink_builds_path_from_slug(): void {
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);

		$series       = new Series();
		$series->slug = 'test-series';

		$this->assertSame( 'http://example.com/series/test-series/', $series->get_permalink() );
	}

	// =========================================================================
	// get_featured_image_url Tests
	// =========================================================================

	/**
	 * Test get_featured_image_url returns URL when image exists.
	 *
	 * @covers \NetterTechEvents\Models\Series::get_featured_image_url
	 * @return void
	 */
	public function test_get_featured_image_url_returns_url_with_image(): void {
		$series                    = new Series();
		$series->featured_image_id = 15;

		$url = $series->get_featured_image_url();

		$this->assertSame( 'http://example.com/wp-content/uploads/test-15.jpg', $url );
	}

	/**
	 * Test get_featured_image_url returns null without image.
	 *
	 * @covers \NetterTechEvents\Models\Series::get_featured_image_url
	 * @return void
	 */
	public function test_get_featured_image_url_returns_null_without_image(): void {
		$series = new Series();

		$this->assertNull( $series->get_featured_image_url() );
	}

	// =========================================================================
	// get_formats Tests
	// =========================================================================

	/**
	 * Test get_formats returns correct number of format specifiers.
	 *
	 * @covers \NetterTechEvents\Models\Series::get_formats
	 * @return void
	 */
	public function test_get_formats_returns_correct_count(): void {
		$series  = new Series();
		$formats = $series->get_formats();
		$array   = $series->to_array();

		$this->assertCount( count( $array ), $formats );
	}
}
