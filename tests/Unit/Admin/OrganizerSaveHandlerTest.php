<?php
/**
 * OrganizerSaveHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\OrganizerSaveHandler;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Models\Organizer;

/**
 * Test OrganizerSaveHandler form processing.
 *
 * Tests validation, save, and capability/nonce checks.
 */
class OrganizerSaveHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock organizer repository.
	 *
	 * @var OrganizerRepositoryInterface|Mockery\MockInterface
	 */
	private $repo;

	/**
	 * Handler instance.
	 *
	 * @var OrganizerSaveHandler
	 */
	private OrganizerSaveHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repo    = Mockery::mock( OrganizerRepositoryInterface::class );
		$this->handler = new OrganizerSaveHandler( $this->repo );
	}

	// =========================================================================
	// register() Tests
	// =========================================================================

	/**
	 * Test register hooks admin_post action.
	 *
	 * @return void
	 */
	public function test_register_hooks_admin_post_action(): void {
		$registered_hooks = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$registered_hooks ) {
				$registered_hooks[] = $hook;
			}
		);

		$this->handler->register();

		$this->assertContains( 'admin_post_nettertech_events_save_organizer', $registered_hooks );
	}

	// =========================================================================
	// handle_save() — Permission and Nonce Tests
	// =========================================================================

	/**
	 * Test handle_save dies when user lacks capability.
	 *
	 * @return void
	 */
	public function test_handle_save_dies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->handler->handle_save();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test handle_save dies when nonce is missing.
	 *
	 * @return void
	 */
	public function test_handle_save_dies_without_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->handler->handle_save();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test handle_save dies when nonce is invalid.
	 *
	 * @return void
	 */
	public function test_handle_save_dies_with_invalid_nonce(): void {
		$_POST['_nettertech_events_organizer_nonce'] = 'fake_nonce';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$this->handler->handle_save();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );

		unset( $_POST['_nettertech_events_organizer_nonce'] );
	}

	// =========================================================================
	// handle_save() — Save Logic Tests
	// =========================================================================

	/**
	 * Test handle_save creates new organizer.
	 *
	 * @return void
	 */
	public function test_handle_save_creates_new_organizer(): void {
		$_POST['_nettertech_events_organizer_nonce']  = 'valid_nonce';
		$_POST['organizer_id']          = '0';
		$_POST['organizer_name']        = 'New Organizer';
		$_POST['organizer_slug']        = '';
		$_POST['organizer_email']       = 'test@example.com';
		$_POST['organizer_phone']       = '555-1234';
		$_POST['organizer_website']     = 'https://example.com';
		$_POST['organizer_description'] = 'A test organizer.';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$this->repo->shouldReceive( 'save' )->once()->with( Mockery::on(
			function ( Organizer $org ) {
				return 'New Organizer' === $org->name
					&& 'new-organizer' === $org->slug
					&& 'test@example.com' === $org->email;
			}
		) )->andReturnUsing(
			function ( Organizer $org ) {
				$org->id = 1;
				return $org;
			}
		);

		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \Exception( 'redirect' );
			}
		);

		try {
			$this->handler->handle_save();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'redirect', $e->getMessage() );
		}

		// Clean up.
		unset(
			$_POST['_nettertech_events_organizer_nonce'],
			$_POST['organizer_id'],
			$_POST['organizer_name'],
			$_POST['organizer_slug'],
			$_POST['organizer_email'],
			$_POST['organizer_phone'],
			$_POST['organizer_website'],
			$_POST['organizer_description']
		);
	}

	/**
	 * Test handle_save updates existing organizer.
	 *
	 * @return void
	 */
	public function test_handle_save_updates_existing_organizer(): void {
		$existing       = new Organizer();
		$existing->id   = 5;
		$existing->name = 'Old Name';
		$existing->slug = 'old-name';

		$_POST['_nettertech_events_organizer_nonce']  = 'valid_nonce';
		$_POST['organizer_id']          = '5';
		$_POST['organizer_name']        = 'Updated Name';
		$_POST['organizer_slug']        = 'updated-name';
		$_POST['organizer_email']       = '';
		$_POST['organizer_phone']       = '';
		$_POST['organizer_website']     = '';
		$_POST['organizer_description'] = '';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$this->repo->shouldReceive( 'find' )->with( 5 )->once()->andReturn( $existing );
		$this->repo->shouldReceive( 'save' )->once()->with( Mockery::on(
			function ( Organizer $org ) {
				return 'Updated Name' === $org->name
					&& 5 === $org->id;
			}
		) )->andReturn( $existing );

		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \Exception( 'redirect' );
			}
		);

		try {
			$this->handler->handle_save();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'redirect', $e->getMessage() );
		}

		unset(
			$_POST['_nettertech_events_organizer_nonce'],
			$_POST['organizer_id'],
			$_POST['organizer_name'],
			$_POST['organizer_slug'],
			$_POST['organizer_email'],
			$_POST['organizer_phone'],
			$_POST['organizer_website'],
			$_POST['organizer_description']
		);
	}

	/**
	 * Test handle_save redirects with error on exception.
	 *
	 * @return void
	 */
	public function test_handle_save_redirects_on_error(): void {
		$_POST['_nettertech_events_organizer_nonce']  = 'valid_nonce';
		$_POST['organizer_id']          = '0';
		$_POST['organizer_name']        = 'Failing Organizer';
		$_POST['organizer_slug']        = '';
		$_POST['organizer_email']       = '';
		$_POST['organizer_phone']       = '';
		$_POST['organizer_website']     = '';
		$_POST['organizer_description'] = '';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$this->repo->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'Database error' ) );

		$redirect_url = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \Exception( 'redirect' );
			}
		);

		try {
			$this->handler->handle_save();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'redirect', $e->getMessage() );
		}

		$this->assertStringContainsString( 'message=error', $redirect_url );

		unset(
			$_POST['_nettertech_events_organizer_nonce'],
			$_POST['organizer_id'],
			$_POST['organizer_name'],
			$_POST['organizer_slug'],
			$_POST['organizer_email'],
			$_POST['organizer_phone'],
			$_POST['organizer_website'],
			$_POST['organizer_description']
		);
	}
}
