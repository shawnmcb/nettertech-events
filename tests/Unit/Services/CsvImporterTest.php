<?php
/**
 * CsvImporter unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\Organizer;
use NetterTechEvents\Services\CsvColumnMapper;
use NetterTechEvents\Services\CsvImporter;
use NetterTechEvents\Services\CsvParser;
use NetterTechEvents\Services\CsvValidator;

/**
 * Test CsvImporter orchestration.
 *
 * Mocks parser/mapper/validator/repositories to verify the dry_run and import
 * pipelines without I/O.
 *
 * @coversDefaultClass \NetterTechEvents\Services\CsvImporter
 */
class CsvImporterTest extends \NetterTechEventsTestCase {

	/**
	 * Mock collaborators.
	 *
	 * @var array<string, Mockery\MockInterface>
	 */
	private array $mocks;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_args' )->alias(
			static fn( $a, $d ) => array_merge( (array) $d, (array) $a )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_title' )->alias(
			static function ( $t ) {
				return strtolower( preg_replace( '/[^a-z0-9-]/i', '-', (string) $t ) );
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '__' )->returnArg();

		$this->mocks = array(
			'parser'          => Mockery::mock( CsvParser::class ),
			'mapper'          => Mockery::mock( CsvColumnMapper::class ),
			'validator'       => Mockery::mock( CsvValidator::class ),
			'event_repo'      => Mockery::mock( EventRepositoryInterface::class ),
			'occurrence_repo' => Mockery::mock( OccurrenceRepositoryInterface::class ),
			'organizer_repo'  => Mockery::mock( OrganizerRepositoryInterface::class ),
			'category_repo'   => Mockery::mock( CategoryRepositoryInterface::class ),
		);
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Build a CsvImporter with the prepared mocks.
	 *
	 * @return CsvImporter
	 */
	private function build_importer(): CsvImporter {
		return new CsvImporter(
			$this->mocks['parser'],
			$this->mocks['mapper'],
			$this->mocks['validator'],
			$this->mocks['event_repo'],
			$this->mocks['occurrence_repo'],
			$this->mocks['organizer_repo'],
			$this->mocks['category_repo']
		);
	}

	/**
	 * Test dry_run returns counts and preview.
	 *
	 * @return void
	 */
	public function test_dry_run_returns_validation_summary(): void {
		$this->mocks['parser']->shouldReceive( 'parse' )->andReturn( array( 'rows' => array( array( 'a' => '1' ) ) ) );
		$this->mocks['mapper']->shouldReceive( 'apply' )->andReturn( array( 'title' => 'Hello' ) );
		$this->mocks['validator']->shouldReceive( 'validate' )->andReturn(
			array(
				'valid'  => array( 2 => array( 'title' => 'Hello' ) ),
				'errors' => array(),
			)
		);

		$result = $this->build_importer()->dry_run( '/tmp/x.csv', array( 'a' => 'title' ) );

		$this->assertSame( 1, $result['valid_count'] );
		$this->assertSame( 0, $result['error_count'] );
		$this->assertCount( 1, $result['preview'] );
	}

	/**
	 * Test import reports errors and skipped count.
	 *
	 * @return void
	 */
	public function test_import_reports_validation_errors(): void {
		$this->mocks['parser']->shouldReceive( 'parse' )->andReturn( array( 'rows' => array( array() ) ) );
		$this->mocks['mapper']->shouldReceive( 'apply' )->andReturn( array() );
		$this->mocks['validator']->shouldReceive( 'validate' )->andReturn(
			array(
				'valid'  => array(),
				'errors' => array( 2 => array( 'Missing title' ) ),
			)
		);

		$result = $this->build_importer()->import( '/tmp/x.csv', array() );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'Row 2', $result['errors'][0] );
	}

	/**
	 * Test import creates events for valid rows.
	 *
	 * @return void
	 */
	public function test_import_creates_events_for_valid_rows(): void {
		$this->mocks['parser']->shouldReceive( 'parse' )->andReturn( array( 'rows' => array( array() ) ) );
		$this->mocks['mapper']->shouldReceive( 'apply' )->andReturn(
			array(
				'title'      => 'Concert',
				'start_date' => '2026-06-01',
				'start_time' => '20:00',
			)
		);
		$this->mocks['validator']->shouldReceive( 'validate' )->andReturn(
			array(
				'valid'  => array(
					2 => array(
						'title'      => 'Concert',
						'start_date' => '2026-06-01',
						'start_time' => '20:00',
					),
				),
				'errors' => array(),
			)
		);
		$this->mocks['validator']->shouldReceive( 'parse_date' )->andReturn( new \DateTimeImmutable( '2026-06-01' ) );
		$this->mocks['validator']->shouldReceive( 'parse_time' )->andReturn( new \DateTimeImmutable( '20:00' ) );
		$this->mocks['validator']->shouldReceive( 'build_datetime' )
			->andReturn( new \DateTimeImmutable( '2026-06-01 20:00' ) );

		$this->mocks['event_repo']->shouldReceive( 'find_by_slug' )->andReturn( null );
		$this->mocks['event_repo']->shouldReceive( 'generate_unique_slug' )->andReturn( 'concert' );

		$saved_event     = new Event();
		$saved_event->id = 7;
		$this->mocks['event_repo']->shouldReceive( 'save' )->andReturn( $saved_event );
		$this->mocks['occurrence_repo']->shouldReceive( 'save' )->andReturn( new Occurrence() );

		$result = $this->build_importer()->import( '/tmp/x.csv', array() );

		$this->assertSame( 1, $result['imported'] );
		$this->assertCount( 1, $result['events'] );
		$this->assertSame( $saved_event, $result['events'][0] );
	}

	/**
	 * Test import skips duplicate when title+date match.
	 *
	 * @return void
	 */
	public function test_import_skips_duplicates(): void {
		$this->mocks['parser']->shouldReceive( 'parse' )->andReturn( array( 'rows' => array( array() ) ) );
		$this->mocks['mapper']->shouldReceive( 'apply' )->andReturn(
			array(
				'title'      => 'X',
				'start_date' => '2026-06-01',
				'start_time' => '20:00',
			)
		);
		$this->mocks['validator']->shouldReceive( 'validate' )->andReturn(
			array(
				'valid'  => array(
					2 => array(
						'title'      => 'X',
						'start_date' => '2026-06-01',
						'start_time' => '20:00',
					),
				),
				'errors' => array(),
			)
		);
		$this->mocks['validator']->shouldReceive( 'parse_date' )->andReturn( new \DateTimeImmutable( '2026-06-01' ) );
		$this->mocks['validator']->shouldReceive( 'parse_time' )->andReturn( new \DateTimeImmutable( '20:00' ) );
		$this->mocks['validator']->shouldReceive( 'build_datetime' )->andReturn( new \DateTimeImmutable( '2026-06-01 20:00' ) );

		$existing     = new Event();
		$existing->id = 99;
		$this->mocks['event_repo']->shouldReceive( 'find_by_slug' )->andReturn( $existing );

		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-01 20:00:00';
		$this->mocks['occurrence_repo']->shouldReceive( 'for_event' )->andReturn( array( $occurrence ) );

		$result = $this->build_importer()->import( '/tmp/x.csv', array( 'skip_duplicates' => true ) );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * Test import surfaces exceptions per-row without aborting.
	 *
	 * @return void
	 */
	public function test_import_collects_exceptions_per_row(): void {
		$this->mocks['parser']->shouldReceive( 'parse' )->andReturn( array( 'rows' => array( array() ) ) );
		$this->mocks['mapper']->shouldReceive( 'apply' )->andReturn(
			array(
				'title'      => 'X',
				'start_date' => '2026-06-01',
			)
		);
		$this->mocks['validator']->shouldReceive( 'validate' )->andReturn(
			array(
				'valid'  => array(
					2 => array(
						'title'      => 'X',
						'start_date' => '2026-06-01',
					),
				),
				'errors' => array(),
			)
		);
		$this->mocks['validator']->shouldReceive( 'parse_date' )->andReturn( new \DateTimeImmutable( '2026-06-01' ) );
		$this->mocks['validator']->shouldReceive( 'parse_time' )->andReturn( null );
		$this->mocks['validator']->shouldReceive( 'build_datetime' )->andReturn( new \DateTimeImmutable( '2026-06-01' ) );

		$this->mocks['event_repo']->shouldReceive( 'find_by_slug' )->andReturn( null );
		$this->mocks['event_repo']->shouldReceive( 'generate_unique_slug' )->andReturn( 'x' );
		$this->mocks['event_repo']->shouldReceive( 'save' )->andThrow( new \RuntimeException( 'db down' ) );

		$result = $this->build_importer()->import( '/tmp/x.csv', array() );

		$this->assertSame( 0, $result['imported'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'db down', $result['errors'][0] );
	}

	/**
	 * Test import attaches new organizer when none exists.
	 *
	 * @return void
	 */
	public function test_import_attaches_new_organizer(): void {
		$this->mocks['parser']->shouldReceive( 'parse' )->andReturn( array( 'rows' => array( array() ) ) );
		$this->mocks['mapper']->shouldReceive( 'apply' )->andReturn(
			array(
				'title'           => 'X',
				'start_date'      => '2026-06-01',
				'organizer_name'  => 'Alice',
				'organizer_email' => 'alice@example.test',
			)
		);
		$this->mocks['validator']->shouldReceive( 'validate' )->andReturn(
			array(
				'valid'  => array(
					2 => array(
						'title'           => 'X',
						'start_date'      => '2026-06-01',
						'organizer_name'  => 'Alice',
						'organizer_email' => 'alice@example.test',
					),
				),
				'errors' => array(),
			)
		);
		$this->mocks['validator']->shouldReceive( 'parse_date' )->andReturn( new \DateTimeImmutable( '2026-06-01' ) );
		$this->mocks['validator']->shouldReceive( 'parse_time' )->andReturn( null );
		$this->mocks['validator']->shouldReceive( 'build_datetime' )->andReturn( new \DateTimeImmutable( '2026-06-01' ) );

		$this->mocks['event_repo']->shouldReceive( 'find_by_slug' )->andReturn( null );
		$this->mocks['event_repo']->shouldReceive( 'generate_unique_slug' )->andReturn( 'x' );
		$saved     = new Event();
		$saved->id = 5;
		$this->mocks['event_repo']->shouldReceive( 'save' )->andReturn( $saved );
		$this->mocks['occurrence_repo']->shouldReceive( 'save' )->andReturn( new Occurrence() );

		$this->mocks['organizer_repo']->shouldReceive( 'find_by_slug' )->andReturn( null );
		$organizer     = new Organizer();
		$organizer->id = 30;
		$this->mocks['organizer_repo']->shouldReceive( 'save' )->andReturn( $organizer );
		$this->mocks['organizer_repo']->shouldReceive( 'attach_to_event' )
			->with( 5, 30, true )
			->once();

		$result = $this->build_importer()->import( '/tmp/x.csv', array() );

		$this->assertSame( 1, $result['imported'] );
	}
}
