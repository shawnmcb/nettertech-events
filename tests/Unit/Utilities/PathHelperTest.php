<?php
/**
 * PathHelper unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Utilities
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Utilities;

use NetterTechEvents\Utilities\PathHelper;
use Brain\Monkey\Functions;

/**
 * Test PathHelper class.
 */
class PathHelperTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( PathHelper::class ) );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'get_base_path',
			'get_archive_path',
			'get_event_url',
			'get_series_url',
			'get_occurrence_url',
			'get_archive_url',
			'sanitize_path',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( PathHelper::class, $method ),
				"Method {$method} should exist"
			);
		}
	}

	/**
	 * Test all methods are static.
	 *
	 * @return void
	 */
	public function test_all_methods_are_static(): void {
		$methods = array(
			'get_base_path',
			'get_archive_path',
			'get_event_url',
			'get_series_url',
			'get_occurrence_url',
			'get_archive_url',
			'sanitize_path',
		);

		foreach ( $methods as $method ) {
			$reflection = new \ReflectionMethod( PathHelper::class, $method );
			$this->assertTrue(
				$reflection->isStatic(),
				"Method {$method} should be static"
			);
		}
	}

	// =========================================================================
	// sanitize_path Tests
	// =========================================================================

	/**
	 * Test sanitize_path removes leading/trailing slashes.
	 *
	 * @return void
	 */
	public function test_sanitize_path_removes_leading_trailing_slashes(): void {
		$this->assertSame( 'events', PathHelper::sanitize_path( '/events/' ) );
		$this->assertSame( 'events', PathHelper::sanitize_path( '///events///' ) );
		$this->assertSame( 'my-events', PathHelper::sanitize_path( '/my-events' ) );
	}

	/**
	 * Test sanitize_path removes whitespace.
	 *
	 * @return void
	 */
	public function test_sanitize_path_removes_whitespace(): void {
		$this->assertSame( 'events', PathHelper::sanitize_path( '  events  ' ) );
		$this->assertSame( 'events', PathHelper::sanitize_path( "\t\nevents\r\n" ) );
	}

	/**
	 * Test sanitize_path allows alphanumeric characters.
	 *
	 * @return void
	 */
	public function test_sanitize_path_allows_alphanumeric(): void {
		$this->assertSame( 'events2024', PathHelper::sanitize_path( 'events2024' ) );
		$this->assertSame( 'MyEvents', PathHelper::sanitize_path( 'MyEvents' ) );
	}

	/**
	 * Test sanitize_path allows hyphens and underscores.
	 *
	 * @return void
	 */
	public function test_sanitize_path_allows_hyphens_underscores(): void {
		$this->assertSame( 'my-events', PathHelper::sanitize_path( 'my-events' ) );
		$this->assertSame( 'my_events', PathHelper::sanitize_path( 'my_events' ) );
		$this->assertSame( 'my-events_2024', PathHelper::sanitize_path( 'my-events_2024' ) );
	}

	/**
	 * Test sanitize_path allows forward slashes.
	 *
	 * @return void
	 */
	public function test_sanitize_path_allows_forward_slashes(): void {
		$this->assertSame( 'events/archive', PathHelper::sanitize_path( 'events/archive' ) );
		$this->assertSame( 'my/nested/path', PathHelper::sanitize_path( 'my/nested/path' ) );
	}

	/**
	 * Test sanitize_path removes invalid characters.
	 *
	 * @return void
	 */
	public function test_sanitize_path_removes_invalid_characters(): void {
		$this->assertSame( 'events', PathHelper::sanitize_path( 'events!' ) );
		$this->assertSame( 'events', PathHelper::sanitize_path( 'events@#$%' ) );
		// Angle brackets removed, alphanumeric kept.
		$this->assertSame( 'eventsscript', PathHelper::sanitize_path( 'events<script>' ) );
		$this->assertSame( 'myevents', PathHelper::sanitize_path( 'my.events' ) );
	}

	/**
	 * Test sanitize_path removes consecutive slashes.
	 *
	 * @return void
	 */
	public function test_sanitize_path_removes_consecutive_slashes(): void {
		$this->assertSame( 'events/archive', PathHelper::sanitize_path( 'events//archive' ) );
		$this->assertSame( 'a/b/c', PathHelper::sanitize_path( 'a///b///c' ) );
	}

	/**
	 * Test sanitize_path handles empty string.
	 *
	 * @return void
	 */
	public function test_sanitize_path_handles_empty_string(): void {
		$this->assertSame( '', PathHelper::sanitize_path( '' ) );
		$this->assertSame( '', PathHelper::sanitize_path( '   ' ) );
		$this->assertSame( '', PathHelper::sanitize_path( '///' ) );
	}

	// =========================================================================
	// get_base_path Tests
	// =========================================================================

	/**
	 * Test get_base_path returns default when no option set.
	 *
	 * @return void
	 */
	public function test_get_base_path_returns_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'events', PathHelper::get_base_path() );
	}

	/**
	 * Test get_base_path returns custom path from settings.
	 *
	 * @return void
	 */
	public function test_get_base_path_returns_custom_path(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'events_base_path' => 'calendar' )
		);

		$this->assertSame( 'calendar', PathHelper::get_base_path() );
	}

	/**
	 * Test get_base_path sanitizes path.
	 *
	 * @return void
	 */
	public function test_get_base_path_sanitizes_path(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'events_base_path' => '/my-events/' )
		);

		$this->assertSame( 'my-events', PathHelper::get_base_path() );
	}

	/**
	 * Test get_base_path returns default for empty path.
	 *
	 * @return void
	 */
	public function test_get_base_path_returns_default_for_empty(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'events_base_path' => '' )
		);

		$this->assertSame( 'events', PathHelper::get_base_path() );
	}

	// =========================================================================
	// get_archive_path Tests
	// =========================================================================

	/**
	 * Test get_archive_path returns default when no option set.
	 *
	 * @return void
	 */
	public function test_get_archive_path_returns_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'events/archive', PathHelper::get_archive_path() );
	}

	/**
	 * Test get_archive_path returns custom path from settings.
	 *
	 * @return void
	 */
	public function test_get_archive_path_returns_custom_path(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'events_archive_path' => 'past-events' )
		);

		$this->assertSame( 'past-events', PathHelper::get_archive_path() );
	}

	/**
	 * Test get_archive_path sanitizes path.
	 *
	 * @return void
	 */
	public function test_get_archive_path_sanitizes_path(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'events_archive_path' => '/past-events/' )
		);

		$this->assertSame( 'past-events', PathHelper::get_archive_path() );
	}

	// =========================================================================
	// get_event_url Tests
	// =========================================================================

	/**
	 * Test get_event_url builds correct URL.
	 *
	 * @return void
	 */
	public function test_get_event_url_builds_correct_url(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'home_url' )->alias(
			function ( $path ) {
				return 'https://example.com' . $path;
			}
		);

		$url = PathHelper::get_event_url( 'my-event' );

		$this->assertSame( 'https://example.com/events/my-event/', $url );
	}

	/**
	 * Test get_event_url uses custom base path.
	 *
	 * @return void
	 */
	public function test_get_event_url_uses_custom_base_path(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'events_base_path' => 'calendar' )
		);
		Functions\when( 'home_url' )->alias(
			function ( $path ) {
				return 'https://example.com' . $path;
			}
		);

		$url = PathHelper::get_event_url( 'my-event' );

		$this->assertSame( 'https://example.com/calendar/my-event/', $url );
	}

	// =========================================================================
	// get_series_url Tests
	// =========================================================================

	/**
	 * Test get_series_url builds correct URL.
	 *
	 * @return void
	 */
	public function test_get_series_url_builds_correct_url(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'home_url' )->alias(
			function ( $path ) {
				return 'https://example.com' . $path;
			}
		);

		$url = PathHelper::get_series_url( 'recurring-event' );

		$this->assertSame( 'https://example.com/events/recurring-event/', $url );
	}

	// =========================================================================
	// get_occurrence_url Tests
	// =========================================================================

	/**
	 * Test get_occurrence_url builds correct URL.
	 *
	 * @return void
	 */
	public function test_get_occurrence_url_builds_correct_url(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'home_url' )->alias(
			function ( $path ) {
				return 'https://example.com' . $path;
			}
		);

		$url = PathHelper::get_occurrence_url( 'my-event', '2024-01-15-1400' );

		$this->assertSame( 'https://example.com/events/my-event/2024-01-15-1400/', $url );
	}

	/**
	 * Test get_occurrence_url uses custom base path.
	 *
	 * @return void
	 */
	public function test_get_occurrence_url_uses_custom_base_path(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'events_base_path' => 'calendar' )
		);
		Functions\when( 'home_url' )->alias(
			function ( $path ) {
				return 'https://example.com' . $path;
			}
		);

		$url = PathHelper::get_occurrence_url( 'my-event', '2024-01-15-1400' );

		$this->assertSame( 'https://example.com/calendar/my-event/2024-01-15-1400/', $url );
	}

	// =========================================================================
	// get_archive_url Tests
	// =========================================================================

	/**
	 * Test get_archive_url builds correct URL.
	 *
	 * @return void
	 */
	public function test_get_archive_url_builds_correct_url(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'home_url' )->alias(
			function ( $path ) {
				return 'https://example.com' . $path;
			}
		);

		$url = PathHelper::get_archive_url();

		$this->assertSame( 'https://example.com/events/archive/', $url );
	}

	/**
	 * Test get_archive_url uses custom archive path.
	 *
	 * @return void
	 */
	public function test_get_archive_url_uses_custom_path(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'events_archive_path' => 'past-events' )
		);
		Functions\when( 'home_url' )->alias(
			function ( $path ) {
				return 'https://example.com' . $path;
			}
		);

		$url = PathHelper::get_archive_url();

		$this->assertSame( 'https://example.com/past-events/', $url );
	}
}
