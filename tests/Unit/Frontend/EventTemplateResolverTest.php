<?php
/**
 * EventTemplateResolver unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Frontend\EventTemplateResolver;
use NetterTechEvents\Models\Event;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Test EventTemplateResolver template hierarchy and utility methods.
 *
 * Covers single/archive/past/series/space template resolution priority,
 * theme override detection, fallback behavior, datetime parsing,
 * and preview capability checking.
 */
class EventTemplateResolverTest extends \NetterTechEventsTestCase {

	/**
	 * Mock templates service.
	 *
	 * @var Templates|Mockery\MockInterface
	 */
	private $templates;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->templates = Mockery::mock( Templates::class );

		Functions\when( 'locate_template' )->justReturn( '' );

		if ( ! defined( 'NETTERTECH_EVENTS_PLUGIN_DIR' ) ) {
			define( 'NETTERTECH_EVENTS_PLUGIN_DIR', '/plugin/' );
		}
	}

	/**
	 * Create resolver instance.
	 *
	 * @return EventTemplateResolver
	 */
	private function create_resolver(): EventTemplateResolver {
		return new EventTemplateResolver( $this->templates );
	}

	// =========================================================================
	// get_single_template() Tests
	// =========================================================================

	/**
	 * Test get_single_template returns theme template when theme overrides.
	 *
	 * @return void
	 */
	public function test_get_single_template_uses_theme_override(): void {
		Functions\when( 'locate_template' )->justReturn( '/theme/nettertech-events/single-event.php' );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_single_template( '/default.php' );

		$this->assertSame( '/theme/nettertech-events/single-event.php', $result );
	}

	/**
	 * Test get_single_template returns plugin template when no theme override.
	 *
	 * @return void
	 */
	public function test_get_single_template_falls_back_to_plugin(): void {
		$this->templates
			->shouldReceive( 'template_file_exists' )
			->andReturn( true );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_single_template( '/default.php' );

		$this->assertStringContainsString( 'single-event.php', $result );
	}

	/**
	 * Test get_single_template returns default when no template found.
	 *
	 * @return void
	 */
	public function test_get_single_template_falls_back_to_default(): void {
		$this->templates
			->shouldReceive( 'template_file_exists' )
			->andReturn( false );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_single_template( '/default.php' );

		$this->assertSame( '/default.php', $result );
	}

	// =========================================================================
	// get_archive_template() Tests
	// =========================================================================

	/**
	 * Test get_archive_template uses theme override.
	 *
	 * @return void
	 */
	public function test_get_archive_template_uses_theme_override(): void {
		Functions\when( 'locate_template' )->justReturn( '/theme/archive-events.php' );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_archive_template( '/default.php' );

		$this->assertSame( '/theme/archive-events.php', $result );
	}

	/**
	 * Test get_archive_template falls back to plugin template.
	 *
	 * @return void
	 */
	public function test_get_archive_template_uses_plugin_template(): void {
		$this->templates
			->shouldReceive( 'template_file_exists' )
			->andReturn( true );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_archive_template( '/default.php' );

		$this->assertStringContainsString( 'archive-events.php', $result );
	}

	/**
	 * Test get_archive_template returns default when nothing found.
	 *
	 * @return void
	 */
	public function test_get_archive_template_returns_default(): void {
		$this->templates
			->shouldReceive( 'template_file_exists' )
			->andReturn( false );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_archive_template( '/default.php' );

		$this->assertSame( '/default.php', $result );
	}

	// =========================================================================
	// get_past_archive_template() Tests
	// =========================================================================

	/**
	 * Test get_past_archive_template falls back to archive template.
	 *
	 * When no specific past-archive template exists, it falls back to
	 * get_archive_template() which itself may return default.
	 *
	 * @return void
	 */
	public function test_get_past_archive_template_falls_back_to_archive(): void {
		$this->templates
			->shouldReceive( 'template_file_exists' )
			->andReturn( false );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_past_archive_template( '/default.php' );

		// Should reach the archive fallback, which also finds nothing, so returns default.
		$this->assertSame( '/default.php', $result );
	}

	/**
	 * Test get_past_archive_template returns own plugin template when it exists.
	 *
	 * @return void
	 */
	public function test_get_past_archive_template_uses_own_plugin_template(): void {
		$call_count = 0;
		$this->templates
			->shouldReceive( 'template_file_exists' )
			->andReturnUsing( function ( string $path ) use ( &$call_count ) {
				++$call_count;
				// First call is for archive-past-events.php.
				return $call_count === 1;
			} );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_past_archive_template( '/default.php' );

		$this->assertStringContainsString( 'archive-past-events.php', $result );
	}

	// =========================================================================
	// get_series_template() Tests
	// =========================================================================

	/**
	 * Test get_series_template falls back to single template when series not found.
	 *
	 * @return void
	 */
	public function test_get_series_template_falls_back_to_single(): void {
		$call_count = 0;
		$this->templates
			->shouldReceive( 'template_file_exists' )
			->andReturnUsing( function () use ( &$call_count ) {
				++$call_count;
				// First call = series template missing, second call = single template present.
				return $call_count === 2;
			} );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_series_template( '/default.php' );

		$this->assertStringContainsString( 'single-event.php', $result );
	}

	/**
	 * Test get_series_template uses own plugin template when it exists.
	 *
	 * @return void
	 */
	public function test_get_series_template_uses_own_plugin_template(): void {
		$call_count = 0;
		$this->templates
			->shouldReceive( 'template_file_exists' )
			->andReturnUsing( function () use ( &$call_count ) {
				++$call_count;
				return $call_count === 1;
			} );

		$resolver = $this->create_resolver();
		$result   = $resolver->get_series_template( '/default.php' );

		$this->assertStringContainsString( 'series-page.php', $result );
	}

	// =========================================================================
	// parse_occurrence_datetime() Tests
	// =========================================================================

	/**
	 * Test parse_occurrence_datetime returns DateTimeImmutable for valid input.
	 *
	 * @return void
	 */
	public function test_parse_occurrence_datetime_valid(): void {
		$resolver = $this->create_resolver();
		$result   = $resolver->parse_occurrence_datetime( '2026-05-15-1930' );

		$this->assertInstanceOf( \DateTimeImmutable::class, $result );
		$this->assertSame( '2026-05-15 19:30:00', $result->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Test parse_occurrence_datetime returns null for invalid format.
	 *
	 * @return void
	 */
	public function test_parse_occurrence_datetime_invalid_returns_null(): void {
		$resolver = $this->create_resolver();
		$result   = $resolver->parse_occurrence_datetime( 'not-a-date' );

		$this->assertNull( $result );
	}

	/**
	 * Test parse_occurrence_datetime returns null for empty string.
	 *
	 * @return void
	 */
	public function test_parse_occurrence_datetime_empty_returns_null(): void {
		$resolver = $this->create_resolver();
		$result   = $resolver->parse_occurrence_datetime( '' );

		$this->assertNull( $result );
	}

	/**
	 * Test parse_occurrence_datetime midnight parses correctly.
	 *
	 * @return void
	 */
	public function test_parse_occurrence_datetime_midnight(): void {
		$resolver = $this->create_resolver();
		$result   = $resolver->parse_occurrence_datetime( '2026-01-01-0000' );

		$this->assertInstanceOf( \DateTimeImmutable::class, $result );
		$this->assertSame( '2026-01-01 00:00:00', $result->format( 'Y-m-d H:i:s' ) );
	}

	// =========================================================================
	// can_preview_event() Tests
	// =========================================================================

	/**
	 * Test can_preview_event returns false when user lacks capability.
	 *
	 * @return void
	 */
	public function test_can_preview_event_false_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$event     = new Event();
		$event->id = 1;

		$resolver = $this->create_resolver();
		$result   = $resolver->can_preview_event( $event );

		$this->assertFalse( $result );
	}

	/**
	 * Test can_preview_event returns true when user is logged in with capability.
	 *
	 * @return void
	 */
	public function test_can_preview_event_true_when_logged_in_editor(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		// No ?preview=true in $_GET.
		unset( $_GET['preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$event     = new Event();
		$event->id = 1;

		$resolver = $this->create_resolver();
		$result   = $resolver->can_preview_event( $event );

		$this->assertTrue( $result );
	}

	/**
	 * Test can_preview_event verifies nonce when preview param set.
	 *
	 * @return void
	 */
	public function test_can_preview_event_verifies_nonce_with_preview_param(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_GET['preview']  = 'true'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['_wpnonce'] = 'test-nonce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$event     = new Event();
		$event->id = 42;

		$resolver = $this->create_resolver();
		$result   = $resolver->can_preview_event( $event );

		$this->assertTrue( $result );

		unset( $_GET['preview'], $_GET['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Test can_preview_event returns false when nonce invalid.
	 *
	 * @return void
	 */
	public function test_can_preview_event_false_on_invalid_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_GET['preview']  = 'true'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['_wpnonce'] = 'bad-nonce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$event     = new Event();
		$event->id = 42;

		$resolver = $this->create_resolver();
		$result   = $resolver->can_preview_event( $event );

		$this->assertFalse( $result );

		unset( $_GET['preview'], $_GET['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
