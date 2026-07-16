<?php
/**
 * Tests for EventSitemapProvider.
 *
 * @package NetterTechEvents\Tests\Unit\SEO
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\SEO;

use NetterTechEvents\SEO\EventSitemapProvider;
use Brain\Monkey\Functions;

/**
 * @coversDefaultClass \NetterTechEvents\SEO\EventSitemapProvider
 */
class EventSitemapProviderTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \wpdb&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $db;

	/**
	 * Provider under test.
	 *
	 * @var EventSitemapProvider
	 */
	private EventSitemapProvider $provider;

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

		$this->provider = new EventSitemapProvider( $this->db );

		Functions\when( 'home_url' )->alias( function ( $path = '' ) {
			return 'http://example.test' . $path;
		} );

		Functions\when( 'wp_sitemaps_get_max_urls' )->justReturn( 2000 );
	}

	// =========================================================================
	// Constructor & Properties
	// =========================================================================

	/**
	 * @covers ::__construct
	 */
	public function test_provider_name_is_nettertech_events(): void {
		$this->assertEquals( 'nettertechevents', $this->provider->name );
	}

	/**
	 * @covers ::__construct
	 */
	public function test_provider_object_type_is_nte_event(): void {
		$this->assertEquals( 'nettertech_event', $this->provider->object_type );
	}

	// =========================================================================
	// get_object_subtypes()
	// =========================================================================

	/**
	 * @covers ::get_object_subtypes
	 */
	public function test_get_object_subtypes_returns_empty_array(): void {
		$this->assertSame( array(), $this->provider->get_object_subtypes() );
	}

	// =========================================================================
	// get_url_list()
	// =========================================================================

	/**
	 * @covers ::get_url_list
	 */
	public function test_get_url_list_returns_event_urls(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$row             = new \stdClass();
		$row->slug       = 'jazz-night';
		$row->updated_at = '2026-03-01 12:00:00';
		$row->entry_type = 'event';
		$row->start_datetime = null;
		$row->timezone   = null;

		$this->db->method( 'prepare' )->willReturn( 'SQL' );
		$this->db->method( 'get_results' )->willReturn( array( $row ) );

		Functions\when( 'get_option' )->justReturn( 'events' );

		$urls = $this->provider->get_url_list( 1 );

		$this->assertCount( 1, $urls );
		$this->assertStringContainsString( '/events/jazz-night/', $urls[0]['loc'] );
		$this->assertArrayHasKey( 'lastmod', $urls[0] );
	}

	/**
	 * @covers ::get_url_list
	 */
	public function test_get_url_list_returns_occurrence_urls_for_recurring(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$event_row             = new \stdClass();
		$event_row->slug       = 'weekly-yoga';
		$event_row->updated_at = '2026-03-01 10:00:00';
		$event_row->entry_type = 'event';
		$event_row->start_datetime = null;
		$event_row->timezone   = null;

		$occ_row             = new \stdClass();
		$occ_row->slug       = 'weekly-yoga';
		$occ_row->updated_at = '2026-03-03 10:00:00';
		$occ_row->entry_type = 'occurrence';
		$occ_row->start_datetime = '2026-03-03 18:00:00';
		$occ_row->timezone   = 'America/Chicago';

		$this->db->method( 'prepare' )->willReturn( 'SQL' );
		$this->db->method( 'get_results' )->willReturn( array( $event_row, $occ_row ) );

		Functions\when( 'get_option' )->justReturn( 'events' );

		$urls = $this->provider->get_url_list( 1 );

		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( '/events/weekly-yoga/', $urls[0]['loc'] );
		$this->assertStringContainsString( '/events/weekly-yoga/2026-03-03-1800/', $urls[1]['loc'] );
	}

	/**
	 * @covers ::get_url_list
	 */
	public function test_get_url_list_returns_empty_when_no_results(): void {
		$this->db->method( 'prepare' )->willReturn( 'SQL' );
		$this->db->method( 'get_results' )->willReturn( array() );

		$urls = $this->provider->get_url_list( 1 );

		$this->assertSame( array(), $urls );
	}

	/**
	 * @covers ::get_url_list
	 */
	public function test_lastmod_is_w3c_format(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Chicago' ) );

		$row             = new \stdClass();
		$row->slug       = 'concert';
		$row->updated_at = '2026-03-01 15:30:00';
		$row->entry_type = 'event';
		$row->start_datetime = null;
		$row->timezone   = null;

		$this->db->method( 'prepare' )->willReturn( 'SQL' );
		$this->db->method( 'get_results' )->willReturn( array( $row ) );

		Functions\when( 'get_option' )->justReturn( 'events' );

		$urls = $this->provider->get_url_list( 1 );

		// W3C format: YYYY-MM-DDTHH:MM:SS+HH:MM.
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			$urls[0]['lastmod']
		);
	}

	// =========================================================================
	// get_max_num_pages()
	// =========================================================================

	/**
	 * @covers ::get_max_num_pages
	 */
	public function test_get_max_num_pages_with_events(): void {
		$this->db->method( 'get_var' )->willReturn( '5' );

		$pages = $this->provider->get_max_num_pages();

		$this->assertEquals( 1, $pages );
	}

	/**
	 * @covers ::get_max_num_pages
	 */
	public function test_get_max_num_pages_with_many_events(): void {
		// 2500 entries, 2000 per page = 2 pages.
		$this->db->method( 'get_var' )->willReturn( '2500' );

		$pages = $this->provider->get_max_num_pages();

		$this->assertEquals( 2, $pages );
	}

	/**
	 * @covers ::get_max_num_pages
	 */
	public function test_get_max_num_pages_returns_zero_when_empty(): void {
		$this->db->method( 'get_var' )->willReturn( '0' );

		$pages = $this->provider->get_max_num_pages();

		$this->assertEquals( 0, $pages );
	}
}
