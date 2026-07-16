<?php
/**
 * Tests for ICalFileWriter.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Services\ICalFileWriter;
use NetterTechEvents\Services\ICalService;

/**
 * @covers \NetterTechEvents\Services\ICalFileWriter
 */
class ICalFileWriterTest extends \NetterTechEventsTestCase {

	/**
	 * Per-test uploads base directory.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Set up a real temp uploads dir and path stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->base_dir = sys_get_temp_dir() . '/nte-ical-writer-' . uniqid( '', true );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => $this->base_dir,
				'baseurl' => 'https://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'trailingslashit' )->alias(
			static fn( $value ) => rtrim( (string) $value, '/' ) . '/'
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			static fn( $dir ) => is_dir( $dir ) || mkdir( $dir, 0777, true )
		);
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
	}

	/**
	 * Remove the temp directory tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$dir = $this->base_dir . '/nettertech-events';
		foreach ( glob( $dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
		if ( is_dir( $this->base_dir ) ) {
			rmdir( $this->base_dir );
		}

		parent::tearDown();
	}

	/**
	 * The written file contains exactly the dynamic feed output.
	 *
	 * @return void
	 */
	public function test_regenerate_writes_feed_matching_dynamic_output(): void {
		$feed = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nRRULE:FREQ=WEEKLY;UNTIL=20280529T000000Z\r\nEND:VCALENDAR\r\n";

		$ical = Mockery::mock( ICalService::class );
		$ical->shouldReceive( 'export_calendar_feed' )
			->once()
			->with(
				Mockery::on(
					static fn( $args ) => 'published' === ( $args['status'] ?? null )
				)
			)
			->andReturn( $feed );

		$writer = new ICalFileWriter( $ical );

		$this->assertTrue( $writer->regenerate() );
		$path = ICalFileWriter::feed_path();
		$this->assertFileExists( $path );
		// Byte-for-byte: the static file IS the dynamic output (incl. the
		// NTE-014 RRULE horizon cap, which is baked in upstream).
		$this->assertSame( $feed, file_get_contents( $path ) );
		// No temp artifact left behind.
		$this->assertFileDoesNotExist( $path . '.tmp' );
	}

	/**
	 * A non-writable directory returns false without composing the feed.
	 *
	 * @return void
	 */
	public function test_regenerate_returns_false_when_directory_not_writable(): void {
		Functions\when( 'wp_mkdir_p' )->justReturn( false );

		$ical = Mockery::mock( ICalService::class );
		$ical->shouldNotReceive( 'export_calendar_feed' );

		$writer = new ICalFileWriter( $ical );

		$this->assertFalse( $writer->regenerate() );
	}

	/**
	 * feed_path() derives from the uploads location.
	 *
	 * @return void
	 */
	public function test_feed_path_derives_from_uploads(): void {
		$this->assertSame( $this->base_dir . '/nettertech-events/feed.ics', ICalFileWriter::feed_path() );
	}
}
