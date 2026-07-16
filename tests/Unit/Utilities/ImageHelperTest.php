<?php
/**
 * Tests for ImageHelper utility class.
 *
 * @package NetterTechEvents\Tests\Unit\Utilities
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Utilities;

use Brain\Monkey\Functions;
use NetterTechEvents\Utilities\ImageHelper;

/**
 * Unit tests for ImageHelper.
 *
 * Tests SEC-M001 validation wrapper functions for WordPress attachment images.
 *
 * @coversDefaultClass \NetterTechEvents\Utilities\ImageHelper
 */
class ImageHelperTest extends \NetterTechEventsTestCase {

	/**
	 * @covers ::is_valid_image_attachment
	 */
	public function test_is_valid_image_attachment_returns_false_for_zero(): void {
		$result = ImageHelper::is_valid_image_attachment( 0 );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::is_valid_image_attachment
	 */
	public function test_is_valid_image_attachment_returns_false_for_negative(): void {
		$result = ImageHelper::is_valid_image_attachment( -1 );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::is_valid_image_attachment
	 */
	public function test_is_valid_image_attachment_returns_false_for_non_image(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );

		$result = ImageHelper::is_valid_image_attachment( 123 );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::is_valid_image_attachment
	 */
	public function test_is_valid_image_attachment_returns_true_for_valid_image(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );

		$result = ImageHelper::is_valid_image_attachment( 123 );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::get_attachment_image
	 */
	public function test_get_attachment_image_returns_empty_for_invalid_id(): void {
		$result = ImageHelper::get_attachment_image( 0 );

		$this->assertSame( '', $result );
	}

	/**
	 * @covers ::get_attachment_image
	 */
	public function test_get_attachment_image_returns_empty_for_non_image(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );

		$result = ImageHelper::get_attachment_image( 999 );

		$this->assertSame( '', $result );
	}

	/**
	 * @covers ::get_attachment_image
	 */
	public function test_get_attachment_image_returns_html_for_valid_image(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image' )->justReturn( '<img src="test.jpg" alt="">' );

		$result = ImageHelper::get_attachment_image( 123 );

		$this->assertSame( '<img src="test.jpg" alt="">', $result );
	}

	/**
	 * @covers ::get_attachment_image
	 */
	public function test_get_attachment_image_with_different_size(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image' )->justReturn( '<img src="large.jpg">' );

		$result = ImageHelper::get_attachment_image( 123, 'large' );

		$this->assertNotEmpty( $result );
	}

	/**
	 * @covers ::get_attachment_image
	 */
	public function test_get_attachment_image_with_icon_true(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image' )->justReturn( '<img src="icon.jpg">' );

		$result = ImageHelper::get_attachment_image( 123, 'thumbnail', true );

		$this->assertNotEmpty( $result );
	}

	/**
	 * @covers ::get_attachment_image
	 */
	public function test_get_attachment_image_with_attributes(): void {
		$attr = array( 'class' => 'my-image', 'loading' => 'lazy' );

		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image' )->justReturn( '<img src="test.jpg" class="my-image">' );

		$result = ImageHelper::get_attachment_image( 123, 'medium', false, $attr );

		$this->assertNotEmpty( $result );
	}

	/**
	 * @covers ::get_attachment_image_url
	 */
	public function test_get_attachment_image_url_returns_null_for_invalid_id(): void {
		$result = ImageHelper::get_attachment_image_url( 0 );

		$this->assertNull( $result );
	}

	/**
	 * @covers ::get_attachment_image_url
	 */
	public function test_get_attachment_image_url_returns_null_for_non_image(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );

		$result = ImageHelper::get_attachment_image_url( 456 );

		$this->assertNull( $result );
	}

	/**
	 * @covers ::get_attachment_image_url
	 */
	public function test_get_attachment_image_url_returns_url_for_valid_image(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/image.jpg' );

		$result = ImageHelper::get_attachment_image_url( 123 );

		$this->assertSame( 'https://example.com/image.jpg', $result );
	}

	/**
	 * @covers ::get_attachment_image_url
	 */
	public function test_get_attachment_image_url_returns_null_when_wp_returns_false(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );

		$result = ImageHelper::get_attachment_image_url( 123 );

		$this->assertNull( $result );
	}

	/**
	 * @covers ::get_attachment_image_url
	 */
	public function test_get_attachment_image_url_with_different_size(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/full.jpg' );

		$result = ImageHelper::get_attachment_image_url( 123, 'full' );

		$this->assertNotNull( $result );
	}

	/**
	 * @covers ::get_attachment_image_src
	 */
	public function test_get_attachment_image_src_returns_false_for_invalid_id(): void {
		$result = ImageHelper::get_attachment_image_src( 0 );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::get_attachment_image_src
	 */
	public function test_get_attachment_image_src_returns_false_for_non_image(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );

		$result = ImageHelper::get_attachment_image_src( 789 );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::get_attachment_image_src
	 */
	public function test_get_attachment_image_src_returns_array_for_valid_image(): void {
		$expected = array( 'https://example.com/image.jpg', 800, 600, true );

		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( $expected );

		$result = ImageHelper::get_attachment_image_src( 123 );

		$this->assertSame( $expected, $result );
	}

	/**
	 * @covers ::get_attachment_image_src
	 */
	public function test_get_attachment_image_src_with_array_size(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn(
			array( 'https://example.com/150x150.jpg', 150, 150, true )
		);

		$result = ImageHelper::get_attachment_image_src( 123, array( 150, 150 ) );

		$this->assertIsArray( $result );
	}

	/**
	 * @covers ::get_attachment_image_src
	 */
	public function test_get_attachment_image_src_returns_false_when_wp_returns_false(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		$result = ImageHelper::get_attachment_image_src( 123 );

		$this->assertFalse( $result );
	}
}
