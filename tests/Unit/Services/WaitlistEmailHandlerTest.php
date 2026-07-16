<?php
/**
 * Tests for WaitlistEmailHandler.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\EmailTemplateRendererInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\WaitlistEntry;
use NetterTechEvents\Services\EmailConfig;
use NetterTechEvents\Services\WaitlistEmailHandler;

/**
 * @coversDefaultClass \NetterTechEvents\Services\WaitlistEmailHandler
 */
class WaitlistEmailHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock waitlist repository.
	 *
	 * @var WaitlistRepositoryInterface&Mockery\MockInterface
	 */
	private $waitlist_repo;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface&Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Mock renderer.
	 *
	 * @var EmailTemplateRendererInterface&Mockery\MockInterface
	 */
	private $renderer;

	/**
	 * Mock email configuration.
	 *
	 * @var EmailConfig&Mockery\MockInterface
	 */
	private $email_config;

	/**
	 * Handler under test.
	 *
	 * @var WaitlistEmailHandler
	 */
	private WaitlistEmailHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->waitlist_repo   = Mockery::mock( WaitlistRepositoryInterface::class );
		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );
		$this->renderer        = Mockery::mock( EmailTemplateRendererInterface::class );
		$this->email_config    = Mockery::mock( EmailConfig::class );

		$this->handler = new WaitlistEmailHandler(
			$this->waitlist_repo,
			$this->occurrence_repo,
			$this->renderer,
			$this->email_config
		);
	}

	// =========================================================================
	// register() Tests
	// =========================================================================

	/**
	 * @covers ::register
	 */
	public function test_register_hooks_waitlist_promoted(): void {
		$registered_hooks = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$registered_hooks ) {
				$registered_hooks[] = $hook;
				return true;
			}
		);

		$this->handler->register();

		$this->assertContains( 'nettertech_events_waitlist_promoted', $registered_hooks );
	}

	// =========================================================================
	// send_promotion_notification() Tests
	// =========================================================================

	/**
	 * @covers ::send_promotion_notification
	 */
	public function test_sends_email_on_promotion(): void {
		$entry         = new WaitlistEntry();
		$entry->id     = 42;
		$entry->email  = 'customer@example.com';
		$entry->name   = 'Jane Doe';
		$entry->status = 'notified';

		$occurrence           = new Occurrence();
		$occurrence->id       = 100;
		$occurrence->event_id = 200;
		$occurrence->start_datetime = '2026-04-15 19:00:00';
		$occurrence->end_datetime   = '2026-04-15 21:00:00';

		$this->occurrence_repo
			->shouldReceive( 'find' )
			->with( 100 )
			->once()
			->andReturn( $occurrence );

		Functions\when( 'get_the_title' )->justReturn( 'Jazz Night' );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/events/jazz-night/' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'current_time' )->justReturn( '2026-04-01 12:00:00' );

		$this->email_config
			->shouldReceive( 'get_template_settings' )
			->once()
			->andReturn( array( 'venue_logo' => '' ) );

		$this->renderer
			->shouldReceive( 'render_email_template' )
			->with( 'emails/waitlist-promotion', Mockery::type( 'array' ) )
			->once()
			->andReturn( '<html>email body</html>' );

		$this->renderer
			->shouldReceive( 'get_email_headers' )
			->once()
			->andReturn( array( 'Content-Type: text/html; charset=UTF-8' ) );

		Functions\when( 'wp_mail' )->justReturn( true );

		$this->waitlist_repo
			->shouldReceive( 'save' )
			->with( Mockery::on( function ( WaitlistEntry $e ) {
				return '2026-04-01 12:00:00' === $e->notified_at;
			} ) )
			->once()
			->andReturn( $entry );

		$result = $this->handler->send_promotion_notification( $entry, 100 );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::send_promotion_notification
	 */
	public function test_returns_false_when_occurrence_not_found(): void {
		$entry     = new WaitlistEntry();
		$entry->id = 42;

		$this->occurrence_repo
			->shouldReceive( 'find' )
			->with( 999 )
			->once()
			->andReturnNull();

		$result = $this->handler->send_promotion_notification( $entry, 999 );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::send_promotion_notification
	 */
	public function test_does_not_update_notified_at_on_send_failure(): void {
		$entry         = new WaitlistEntry();
		$entry->id     = 42;
		$entry->email  = 'customer@example.com';
		$entry->name   = 'Jane Doe';

		$occurrence           = new Occurrence();
		$occurrence->id       = 100;
		$occurrence->event_id = 200;
		$occurrence->start_datetime = '2026-04-15 19:00:00';
		$occurrence->end_datetime   = '2026-04-15 21:00:00';

		$this->occurrence_repo
			->shouldReceive( 'find' )
			->with( 100 )
			->once()
			->andReturn( $occurrence );

		Functions\when( 'get_the_title' )->justReturn( 'Jazz Night' );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/events/jazz-night/' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );

		$this->email_config
			->shouldReceive( 'get_template_settings' )
			->once()
			->andReturn( array( 'venue_logo' => '' ) );

		$this->renderer
			->shouldReceive( 'render_email_template' )
			->once()
			->andReturn( '<html>email body</html>' );

		$this->renderer
			->shouldReceive( 'get_email_headers' )
			->once()
			->andReturn( array( 'Content-Type: text/html; charset=UTF-8' ) );

		Functions\when( 'wp_mail' )->justReturn( false );

		// save() should NOT be called when email fails.
		$this->waitlist_repo->shouldNotReceive( 'save' );

		$result = $this->handler->send_promotion_notification( $entry, 100 );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::send_promotion_notification
	 */
	public function test_fires_notification_sent_action(): void {
		$entry         = new WaitlistEntry();
		$entry->id     = 42;
		$entry->email  = 'customer@example.com';
		$entry->name   = 'Jane Doe';

		$occurrence           = new Occurrence();
		$occurrence->id       = 100;
		$occurrence->event_id = 200;
		$occurrence->start_datetime = '2026-04-15 19:00:00';
		$occurrence->end_datetime   = '2026-04-15 21:00:00';

		$this->occurrence_repo
			->shouldReceive( 'find' )
			->with( 100 )
			->once()
			->andReturn( $occurrence );

		Functions\when( 'get_the_title' )->justReturn( 'Jazz Night' );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/events/jazz-night/' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'current_time' )->justReturn( '2026-04-01 12:00:00' );

		$this->email_config
			->shouldReceive( 'get_template_settings' )
			->once()
			->andReturn( array( 'venue_logo' => '' ) );

		$this->renderer
			->shouldReceive( 'render_email_template' )
			->once()
			->andReturn( '<html>email body</html>' );

		$this->renderer
			->shouldReceive( 'get_email_headers' )
			->once()
			->andReturn( array( 'Content-Type: text/html; charset=UTF-8' ) );

		Functions\when( 'wp_mail' )->justReturn( true );

		$this->waitlist_repo
			->shouldReceive( 'save' )
			->once()
			->andReturn( $entry );

		$captured_actions = array();
		Functions\when( 'do_action' )->alias(
			function () use ( &$captured_actions ) {
				$captured_actions[] = func_get_args();
			}
		);

		$this->handler->send_promotion_notification( $entry, 100 );

		$notification_actions = array_filter(
			$captured_actions,
			fn( $args ) => 'nettertech_events_waitlist_notification_sent' === $args[0]
		);
		$this->assertCount( 1, $notification_actions );

		$action_args = array_values( $notification_actions )[0];
		$this->assertSame( $entry, $action_args[1] );
		$this->assertSame( 100, $action_args[2] );
		$this->assertTrue( $action_args[3] );
	}

	// =========================================================================
	// handle_promoted() Tests
	// =========================================================================

	/**
	 * @covers ::handle_promoted
	 */
	public function test_handle_promoted_catches_exceptions(): void {
		$entry         = new WaitlistEntry();
		$entry->id     = 42;
		$entry->email  = 'customer@example.com';

		// Force an exception from occurrence_repo.
		$this->occurrence_repo
			->shouldReceive( 'find' )
			->with( 100 )
			->once()
			->andThrow( new \RuntimeException( 'DB error' ) );

		// Should not propagate — handler catches it.
		$this->handler->handle_promoted( $entry, 100 );

		$this->assertTrue( true, 'Exception was caught and did not propagate' );
	}

	/**
	 * @covers ::send_promotion_notification
	 */
	public function test_uses_fallback_event_name_when_title_empty(): void {
		$entry         = new WaitlistEntry();
		$entry->id     = 42;
		$entry->email  = 'customer@example.com';
		$entry->name   = 'Jane Doe';

		$occurrence           = new Occurrence();
		$occurrence->id       = 100;
		$occurrence->event_id = 200;
		$occurrence->start_datetime = '2026-04-15 19:00:00';
		$occurrence->end_datetime   = '2026-04-15 21:00:00';

		$this->occurrence_repo
			->shouldReceive( 'find' )
			->with( 100 )
			->once()
			->andReturn( $occurrence );

		Functions\when( 'get_the_title' )->justReturn( '' );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/events/200/' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'current_time' )->justReturn( '2026-04-01 12:00:00' );

		$this->email_config
			->shouldReceive( 'get_template_settings' )
			->once()
			->andReturn( array( 'venue_logo' => '' ) );

		$this->renderer
			->shouldReceive( 'render_email_template' )
			->with(
				'emails/waitlist-promotion',
				Mockery::on( function ( array $data ) {
					// Fallback name should be 'Event'.
					return 'Event' === $data['event_name'];
				} )
			)
			->once()
			->andReturn( '<html>email</html>' );

		$this->renderer
			->shouldReceive( 'get_email_headers' )
			->once()
			->andReturn( array( 'Content-Type: text/html; charset=UTF-8' ) );

		Functions\when( 'wp_mail' )->justReturn( true );

		$this->waitlist_repo
			->shouldReceive( 'save' )
			->once()
			->andReturn( $entry );

		$result = $this->handler->send_promotion_notification( $entry, 100 );

		$this->assertTrue( $result );
	}
}
