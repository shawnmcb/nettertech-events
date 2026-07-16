<?php
/**
 * AdminRequest unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use NetterTechEvents\Admin\AdminRequest;

/**
 * Test AdminRequest read-boundary behavior.
 *
 * Regression guard for NTE-078: the events list form submits via method="get",
 * and EventsListTable::prepare_items() reads its filter, search, and sort state
 * through AdminRequest. AdminRequest must read from $_GET exclusively. If a
 * change ever routes the list form back to POST without updating these reads,
 * filtering and search silently become no-ops (the original 68d0a05
 * regression). These tests assert the GET-only contract directly.
 */
class AdminRequestTest extends \NetterTechEventsTestCase {

	/**
	 * Clear superglobals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$_GET  = array();
		$_POST = array();
	}

	/**
	 * Clear superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Test get_text reads the value from $_GET.
	 *
	 * @return void
	 */
	public function test_get_text_reads_from_get(): void {
		$_GET['s'] = 'concert';

		$this->assertSame( 'concert', AdminRequest::get_text( 's' ) );
	}

	/**
	 * Test get_text ignores $_POST entirely.
	 *
	 * This is the core NTE-078 regression assertion: a value present only in
	 * $_POST (as it would be under a POST form) must NOT be read by the filter
	 * boundary, which is GET-only.
	 *
	 * @return void
	 */
	public function test_get_text_ignores_post(): void {
		$_POST['s']          = 'concert';
		$_POST['status']     = 'draft';
		$_POST['event_type'] = 'recurring';

		$this->assertSame( '', AdminRequest::get_text( 's' ) );
		$this->assertSame( '', AdminRequest::get_text( 'status' ) );
		$this->assertSame( '', AdminRequest::get_text( 'event_type' ) );
	}

	/**
	 * Test the filter keys prepare_items() relies on are read from $_GET.
	 *
	 * Mirrors EventsListTable::prepare_items() which reads s/status/event_type
	 * via AdminRequest::get_text(). With the values in $_GET, each must surface.
	 *
	 * @return void
	 */
	public function test_filter_keys_read_from_get(): void {
		$_GET['s']          = 'jazz night';
		$_GET['status']     = 'published';
		$_GET['event_type'] = 'single';

		$this->assertSame( 'jazz night', AdminRequest::get_text( 's' ) );
		$this->assertSame( 'published', AdminRequest::get_text( 'status' ) );
		$this->assertSame( 'single', AdminRequest::get_text( 'event_type' ) );
	}

	/**
	 * Test get_text returns the fallback when the key is absent.
	 *
	 * @return void
	 */
	public function test_get_text_returns_fallback_when_absent(): void {
		$this->assertSame( 'created_at', AdminRequest::get_text( 'orderby', 'created_at' ) );
	}

	/**
	 * Test has() reports presence in $_GET only.
	 *
	 * @return void
	 */
	public function test_has_reflects_get_only(): void {
		$_POST['message'] = 'created';
		$this->assertFalse( AdminRequest::has( 'message' ) );

		$_GET['message'] = 'created';
		$this->assertTrue( AdminRequest::has( 'message' ) );
	}

	/**
	 * Test get_absint reads and casts from $_GET.
	 *
	 * @return void
	 */
	public function test_get_absint_reads_from_get(): void {
		$_GET['event_id'] = '42';
		$this->assertSame( 42, AdminRequest::get_absint( 'event_id' ) );

		$_POST['paged'] = '3';
		$this->assertSame( 0, AdminRequest::get_absint( 'paged' ) );
	}
}
