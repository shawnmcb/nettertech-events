<?php
/**
 * Tests for ICalFeedRegenerationListener.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Services\ICalFeedRegenerationListener;
use NetterTechEvents\Services\ICalFileWriter;

/**
 * @covers \NetterTechEvents\Services\ICalFeedRegenerationListener
 */
class ICalFeedRegenerationListenerTest extends \NetterTechEventsTestCase {

	/**
	 * Schedules a single debounced run when none is pending.
	 *
	 * @return void
	 */
	public function test_schedule_regeneration_schedules_when_none_pending(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->with( Mockery::type( 'int' ), Hooks::ICAL_STATIC_REGENERATE );

		$listener = new ICalFeedRegenerationListener( Mockery::mock( ICalFileWriter::class ) );
		$listener->schedule_regeneration();
	}

	/**
	 * Debounces: does not schedule when a run is already pending.
	 *
	 * @return void
	 */
	public function test_schedule_regeneration_debounces_when_already_pending(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 30 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$listener = new ICalFeedRegenerationListener( Mockery::mock( ICalFileWriter::class ) );
		$listener->schedule_regeneration();
	}

	/**
	 * The cron callback delegates to the file writer.
	 *
	 * @return void
	 */
	public function test_run_regenerates_the_file(): void {
		$writer = Mockery::mock( ICalFileWriter::class );
		$writer->shouldReceive( 'regenerate' )->once()->andReturn( true );

		$listener = new ICalFeedRegenerationListener( $writer );
		$listener->run();

		$this->assertTrue( true );
	}
}
