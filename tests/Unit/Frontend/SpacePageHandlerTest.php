<?php
/**
 * SpacePageHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use NetterTechEvents\Frontend\SpacePageHandler;

/**
 * Test SpacePageHandler class.
 *
 * @coversDefaultClass \NetterTechEvents\Frontend\SpacePageHandler
 */
class SpacePageHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb instance.
	 *
	 * @var \PHPUnit\Framework\MockObject\MockObject|\wpdb
	 */
	private $mock_wpdb;

	/**
	 * Original wpdb.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->original_wpdb = $wpdb;

		$this->mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();
		$this->mock_wpdb->prefix = 'wp_';
		$this->mock_wpdb->method( 'prepare' )->willReturnCallback( static fn( $sql ) => $sql );

		$wpdb = $this->mock_wpdb;
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * Test resolve returns null when slug not found.
	 *
	 * @return void
	 */
	public function test_resolve_returns_null_when_not_found(): void {
		$this->mock_wpdb->method( 'get_row' )->willReturn( null );

		$handler = new SpacePageHandler();
		$this->assertNull( $handler->resolve( 'missing' ) );
	}

	/**
	 * Test resolve hydrates and casts row fields.
	 *
	 * @return void
	 */
	public function test_resolve_casts_numeric_and_returns_object(): void {
		$row                          = new \stdClass();
		$row->id                      = '17';
		$row->name                    = 'Studio A';
		$row->slug                    = 'studio-a';
		$row->featured_image_id       = '99';
		$row->capacity                = '40';
		$row->square_footage          = '850';
		$row->seating_model           = null;
		$row->accessibility_features  = null;
		$row->amenities               = null;

		$this->mock_wpdb->method( 'get_row' )->willReturn( $row );

		$handler = new SpacePageHandler();
		$result  = $handler->resolve( 'studio-a' );

		$this->assertNotNull( $result );
		$this->assertSame( 17, $result->id );
		$this->assertSame( 99, $result->featured_image_id );
		$this->assertSame( 40, $result->capacity );
		$this->assertSame( 850, $result->square_footage );
		// Default seating_model when null.
		$this->assertSame( 'free', $result->seating_model );
	}

	/**
	 * Test resolve casts null featured_image_id to null and zero values too.
	 *
	 * @return void
	 */
	public function test_resolve_handles_null_image_id(): void {
		$row                     = new \stdClass();
		$row->id                 = 1;
		$row->name               = '';
		$row->slug               = '';
		$row->featured_image_id  = null;
		$row->capacity           = 0;
		$row->square_footage     = null;
		$row->seating_model      = 'assigned';

		$this->mock_wpdb->method( 'get_row' )->willReturn( $row );

		$handler = new SpacePageHandler();
		$result  = $handler->resolve( 'x' );

		$this->assertNull( $result->featured_image_id );
		$this->assertNull( $result->square_footage );
		$this->assertSame( 'assigned', $result->seating_model );
	}

	/**
	 * Test get_accessibility_features returns empty for empty input.
	 *
	 * @return void
	 */
	public function test_get_accessibility_features_empty(): void {
		$space                         = new \stdClass();
		$space->accessibility_features = '';

		$handler = new SpacePageHandler();
		$this->assertSame( array(), $handler->get_accessibility_features( $space ) );
	}

	/**
	 * Test get_accessibility_features decodes JSON and filters out invalid entries.
	 *
	 * @return void
	 */
	public function test_get_accessibility_features_decodes_and_filters(): void {
		$space                         = new \stdClass();
		$space->accessibility_features = json_encode(
			array(
				array(
					'key'   => 'wheelchair',
					'count' => 3,
				),
				array( 'invalid' => 'no key' ),
				array(
					'key'   => 'hearing',
					'notes' => 'loop installed',
				),
			)
		);

		$handler = new SpacePageHandler();
		$out     = $handler->get_accessibility_features( $space );

		$this->assertCount( 2, $out );
		$this->assertSame( 'wheelchair', $out[0]['key'] );
		$this->assertSame( 'hearing', $out[1]['key'] );
	}

	/**
	 * Test get_accessibility_features returns empty on invalid JSON.
	 *
	 * @return void
	 */
	public function test_get_accessibility_features_returns_empty_on_invalid_json(): void {
		$space                         = new \stdClass();
		$space->accessibility_features = '{not json}';

		$handler = new SpacePageHandler();
		$this->assertSame( array(), $handler->get_accessibility_features( $space ) );
	}

	/**
	 * Test get_amenities returns empty when null.
	 *
	 * @return void
	 */
	public function test_get_amenities_empty(): void {
		$space            = new \stdClass();
		$space->amenities = null;

		$handler = new SpacePageHandler();
		$this->assertSame( array(), $handler->get_amenities( $space ) );
	}

	/**
	 * Test get_amenities decodes JSON array.
	 *
	 * @return void
	 */
	public function test_get_amenities_decodes_array(): void {
		$space            = new \stdClass();
		$space->amenities = json_encode( array( 'WiFi', 'Parking' ) );

		$handler = new SpacePageHandler();
		$this->assertSame( array( 'WiFi', 'Parking' ), $handler->get_amenities( $space ) );
	}

	/**
	 * Test get_amenities returns empty on invalid JSON.
	 *
	 * @return void
	 */
	public function test_get_amenities_returns_empty_on_invalid_json(): void {
		$space            = new \stdClass();
		$space->amenities = '{not json}';

		$handler = new SpacePageHandler();
		$this->assertSame( array(), $handler->get_amenities( $space ) );
	}
}
