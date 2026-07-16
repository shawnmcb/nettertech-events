<?php
/**
 * Organizer model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\Organizer;
use Brain\Monkey\Functions;

/**
 * Test Organizer model functionality.
 *
 * @covers \NetterTechEvents\Models\Organizer
 */
class OrganizerTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates Organizer from valid data.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::from_row
	 * @return void
	 */
	public function test_from_row_creates_organizer_from_valid_data(): void {
		$row = (object) array(
			'id'                => 1,
			'name'              => 'Test Organizer',
			'slug'              => 'test-organizer',
			'description'       => 'A test organizer.',
			'email'             => 'org@example.com',
			'phone'             => '555-0100',
			'website'           => 'https://example.com',
			'featured_image_id' => 42,
			'created_at'        => '2026-01-01 00:00:00',
			'updated_at'        => '2026-01-02 00:00:00',
		);

		$organizer = Organizer::from_row( $row );

		$this->assertSame( 1, $organizer->id );
		$this->assertSame( 'Test Organizer', $organizer->name );
		$this->assertSame( 'test-organizer', $organizer->slug );
		$this->assertSame( 'A test organizer.', $organizer->description );
		$this->assertSame( 'org@example.com', $organizer->email );
		$this->assertSame( '555-0100', $organizer->phone );
		$this->assertSame( 'https://example.com', $organizer->website );
		$this->assertSame( 42, $organizer->featured_image_id );
		$this->assertSame( '2026-01-01 00:00:00', $organizer->created_at );
		$this->assertSame( '2026-01-02 00:00:00', $organizer->updated_at );
	}

	/**
	 * Test from_row handles missing optional email.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_email(): void {
		$row = (object) array(
			'id'   => 2,
			'name' => 'No Email Org',
			'slug' => 'no-email-org',
		);

		$organizer = Organizer::from_row( $row );

		$this->assertNull( $organizer->email );
	}

	/**
	 * Test from_row handles missing optional phone.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_phone(): void {
		$row = (object) array(
			'id'   => 3,
			'name' => 'No Phone Org',
			'slug' => 'no-phone-org',
		);

		$organizer = Organizer::from_row( $row );

		$this->assertNull( $organizer->phone );
	}

	/**
	 * Test from_row handles missing optional website.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_website(): void {
		$row = (object) array(
			'id'   => 4,
			'name' => 'No Website Org',
			'slug' => 'no-website-org',
		);

		$organizer = Organizer::from_row( $row );

		$this->assertNull( $organizer->website );
	}

	/**
	 * Test from_row handles missing optional featured_image_id.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_featured_image_id(): void {
		$row = (object) array(
			'id'   => 5,
			'name' => 'No Image Org',
			'slug' => 'no-image-org',
		);

		$organizer = Organizer::from_row( $row );

		$this->assertNull( $organizer->featured_image_id );
	}

	// =========================================================================
	// to_array Tests
	// =========================================================================

	/**
	 * Test to_array round-trip preserves values.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::to_array
	 * @return void
	 */
	public function test_to_array_round_trip_preserves_values(): void {
		$row = (object) array(
			'id'                => 10,
			'name'              => 'Round Trip Org',
			'slug'              => 'round-trip-org',
			'description'       => 'Description here.',
			'email'             => 'round@example.com',
			'phone'             => '555-9999',
			'website'           => 'https://roundtrip.com',
			'featured_image_id' => 7,
		);

		$organizer = Organizer::from_row( $row );
		$array     = $organizer->to_array();

		$this->assertSame( 'Round Trip Org', $array['name'] );
		$this->assertSame( 'round-trip-org', $array['slug'] );
		$this->assertSame( 'Description here.', $array['description'] );
		$this->assertSame( 'round@example.com', $array['email'] );
		$this->assertSame( '555-9999', $array['phone'] );
		$this->assertSame( 'https://roundtrip.com', $array['website'] );
		$this->assertSame( 7, $array['featured_image_id'] );
	}

	// =========================================================================
	// validate Tests
	// =========================================================================

	/**
	 * Test validate returns empty for valid organizer.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::validate
	 * @return void
	 */
	public function test_validate_returns_empty_for_valid_organizer(): void {
		$organizer       = new Organizer();
		$organizer->name = 'Valid Organizer';
		$organizer->slug = 'valid-organizer';

		$errors = $organizer->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate requires name.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::validate
	 * @return void
	 */
	public function test_validate_requires_name(): void {
		$organizer       = new Organizer();
		$organizer->slug = 'has-slug';

		$errors = $organizer->validate();

		$this->assertContains( 'Organizer name is required.', $errors );
	}

	/**
	 * Test validate requires slug.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::validate
	 * @return void
	 */
	public function test_validate_requires_slug(): void {
		$organizer       = new Organizer();
		$organizer->name = 'Has Name';

		$errors = $organizer->validate();

		$this->assertContains( 'Organizer slug is required.', $errors );
	}

	/**
	 * Test validate rejects invalid email format.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::validate
	 * @return void
	 */
	public function test_validate_rejects_invalid_email(): void {
		$organizer        = new Organizer();
		$organizer->name  = 'Bad Email Org';
		$organizer->slug  = 'bad-email-org';
		$organizer->email = 'not-an-email';

		$errors = $organizer->validate();

		$this->assertContains( 'Invalid email address.', $errors );
	}

	/**
	 * Test validate rejects invalid website URL.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::validate
	 * @return void
	 */
	public function test_validate_rejects_invalid_website(): void {
		$organizer          = new Organizer();
		$organizer->name    = 'Bad Website Org';
		$organizer->slug    = 'bad-website-org';
		$organizer->website = 'not a url';

		$errors = $organizer->validate();

		$this->assertContains( 'Invalid website URL.', $errors );
	}

	// =========================================================================
	// get_permalink Tests
	// =========================================================================

	/**
	 * Test get_permalink builds path from slug.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::get_permalink
	 * @return void
	 */
	public function test_get_permalink_builds_path_from_slug(): void {
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);

		$organizer       = new Organizer();
		$organizer->slug = 'test-org';

		$this->assertSame( 'http://example.com/organizer/test-org/', $organizer->get_permalink() );
	}

	// =========================================================================
	// get_featured_image_url Tests
	// =========================================================================

	/**
	 * Test get_featured_image_url returns URL when image exists.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::get_featured_image_url
	 * @return void
	 */
	public function test_get_featured_image_url_returns_url_with_image(): void {
		$organizer                    = new Organizer();
		$organizer->featured_image_id = 42;

		$url = $organizer->get_featured_image_url();

		$this->assertSame( 'http://example.com/wp-content/uploads/test-42.jpg', $url );
	}

	/**
	 * Test get_featured_image_url returns null without image.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::get_featured_image_url
	 * @return void
	 */
	public function test_get_featured_image_url_returns_null_without_image(): void {
		$organizer = new Organizer();

		$this->assertNull( $organizer->get_featured_image_url() );
	}

	// =========================================================================
	// get_formats Tests
	// =========================================================================

	/**
	 * Test get_formats returns correct number of format specifiers.
	 *
	 * @covers \NetterTechEvents\Models\Organizer::get_formats
	 * @return void
	 */
	public function test_get_formats_returns_correct_count(): void {
		$organizer = new Organizer();
		$formats   = $organizer->get_formats();
		$array     = $organizer->to_array();

		$this->assertCount( count( $array ), $formats );
	}
}
