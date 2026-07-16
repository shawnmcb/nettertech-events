<?php
/**
 * Tests for YoastSitemapProvider.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\Yoast
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\Yoast;

use NetterTechEvents\Integrations\Yoast\YoastSitemapProvider;
use Brain\Monkey\Functions;

/**
 * @coversDefaultClass \NetterTechEvents\Integrations\Yoast\YoastSitemapProvider
 */
class YoastSitemapProviderTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \wpdb&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $db;

	/**
	 * Provider under test.
	 *
	 * @var YoastSitemapProvider
	 */
	private YoastSitemapProvider $provider;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'prepare', 'get_results', 'get_var' ) )
			->getMock();

		$this->db->prefix = 'wp_';

		$this->provider = new YoastSitemapProvider( $this->db );

		Functions\when( 'home_url' )->alias( function ( $path = '' ) {
			return 'http://example.test' . $path;
		} );
	}

	// =========================================================================
	// handles_type()
	// =========================================================================

	/**
	 * @covers ::handles_type
	 */
	public function test_handles_nte_events_type(): void {
		$this->assertTrue( $this->provider->handles_type( 'nettertech-events' ) );
	}

	/**
	 * @covers ::handles_type
	 */
	public function test_does_not_handle_other_types(): void {
		$this->assertFalse( $this->provider->handles_type( 'post' ) );
		$this->assertFalse( $this->provider->handles_type( 'page' ) );
	}

	// =========================================================================
	// get_index_links()
	// =========================================================================

	/**
	 * @covers ::get_index_links
	 */
	public function test_get_index_links_returns_empty_when_no_events(): void {
		$this->db->method( 'get_var' )->willReturn( '0' );

		$result = $this->provider->get_index_links( 1000 );

		$this->assertEmpty( $result );
	}

	/**
	 * @covers ::get_index_links
	 */
	public function test_get_index_links_returns_single_page_for_small_count(): void {
		// Total count query.
		$this->db->method( 'get_var' )
			->willReturnOnConsecutiveCalls( '5', '2026-04-01 12:00:00' );

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$result = $this->provider->get_index_links( 1000 );

		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'nettertech-events-sitemap', $result[0]['loc'] );
	}

	/**
	 * @covers ::get_index_links
	 */
	public function test_get_index_links_returns_multiple_pages(): void {
		$this->db->method( 'get_var' )
			->willReturnOnConsecutiveCalls( '2500', '2026-04-01 12:00:00' );

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$result = $this->provider->get_index_links( 1000 );

		$this->assertCount( 3, $result );
		$this->assertEquals( 'nettertech-events-sitemap.xml', $result[0]['loc'] );
		$this->assertEquals( 'nettertech-events-sitemap2.xml', $result[1]['loc'] );
		$this->assertEquals( 'nettertech-events-sitemap3.xml', $result[2]['loc'] );
	}

	// =========================================================================
	// get_sitemap_links()
	// =========================================================================

	/**
	 * @covers ::get_sitemap_links
	 */
	public function test_get_sitemap_links_returns_empty_when_no_results(): void {
		$this->db->method( 'prepare' )->willReturn( 'SELECT ...' );
		$this->db->method( 'get_results' )->willReturn( array() );

		$result = $this->provider->get_sitemap_links( 'nettertech-events', 1000, 1 );

		$this->assertEmpty( $result );
	}

	/**
	 * @covers ::get_sitemap_links
	 */
	public function test_get_sitemap_links_returns_event_urls(): void {
		$row1             = new \stdClass();
		$row1->slug       = 'summer-concert';
		$row1->updated_at = '2026-04-01 12:00:00';
		$row1->entry_type = 'event';
		$row1->start_datetime = null;
		$row1->timezone   = null;

		$this->db->method( 'prepare' )->willReturn( 'SELECT ...' );
		$this->db->method( 'get_results' )->willReturn( array( $row1 ) );

		$result = $this->provider->get_sitemap_links( 'nettertech-events', 1000, 1 );

		$this->assertCount( 1, $result );
		$this->assertEquals( 'http://example.test/events/summer-concert/', $result[0]['loc'] );
		$this->assertArrayHasKey( 'mod', $result[0] );
	}

	/**
	 * @covers ::get_sitemap_links
	 */
	public function test_get_sitemap_links_returns_occurrence_urls(): void {
		$row1                 = new \stdClass();
		$row1->slug           = 'weekly-jam';
		$row1->updated_at     = '2026-04-01 12:00:00';
		$row1->entry_type     = 'occurrence';
		$row1->start_datetime = '2026-06-15 19:00:00';
		$row1->timezone       = 'America/Chicago';

		$this->db->method( 'prepare' )->willReturn( 'SELECT ...' );
		$this->db->method( 'get_results' )->willReturn( array( $row1 ) );

		$result = $this->provider->get_sitemap_links( 'nettertech-events', 1000, 1 );

		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'weekly-jam/2026-06-15-1900', $result[0]['loc'] );
	}
}
