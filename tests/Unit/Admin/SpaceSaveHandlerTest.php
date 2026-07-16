<?php
/**
 * SpaceSaveHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\SpaceSaveHandler;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;

/**
 * Test SpaceSaveHandler form processing.
 *
 * Tests validation, save, and capability/nonce checks.
 */
class SpaceSaveHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock space repository.
	 *
	 * @var SpaceRepositoryInterface|Mockery\MockInterface
	 */
	private $repo;

	/**
	 * Handler instance.
	 *
	 * @var SpaceSaveHandler
	 */
	private SpaceSaveHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repo    = Mockery::mock( SpaceRepositoryInterface::class );
		$this->handler = new SpaceSaveHandler( $this->repo );
	}

	/**
	 * Invoke a private SpaceSaveHandler method by name.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke_private( string $method, mixed ...$args ): mixed {
		$reflection = new \ReflectionMethod( SpaceSaveHandler::class, $method );
		return $reflection->invoke( $this->handler, ...$args );
	}

	// =========================================================================
	// sanitize_accessibility_features() Tests
	// =========================================================================

	/**
	 * Test sanitize_accessibility_features returns null when input is empty.
	 *
	 * @return void
	 */
	public function test_sanitize_accessibility_features_returns_null_for_empty(): void {
		Functions\when( 'sanitize_key' )->returnArg();

		$this->assertNull( $this->invoke_private( 'sanitize_accessibility_features', null ) );
		$this->assertNull( $this->invoke_private( 'sanitize_accessibility_features', '' ) );
		$this->assertNull( $this->invoke_private( 'sanitize_accessibility_features', array() ) );
	}

	/**
	 * Test sanitize_accessibility_features keeps preset entries without flag.
	 *
	 * @return void
	 */
	public function test_sanitize_accessibility_features_keeps_presets(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$json = $this->invoke_private(
			'sanitize_accessibility_features',
			array(
				array( 'key' => 'wheelchair_seating', 'count' => 8, 'notes' => 'paired, distributed sight lines' ),
				array( 'key' => 'hearing_loop' ),
			)
		);

		$decoded = json_decode( (string) $json, true );

		$this->assertCount( 2, $decoded );
		$this->assertSame( 'wheelchair_seating', $decoded[0]['key'] );
		$this->assertSame( 8, $decoded[0]['count'] );
		$this->assertSame( 'paired, distributed sight lines', $decoded[0]['notes'] );
		$this->assertArrayNotHasKey( 'custom', $decoded[0] );
		$this->assertSame( 'hearing_loop', $decoded[1]['key'] );
	}

	/**
	 * Test sanitize_accessibility_features flags custom (non-preset) entries.
	 *
	 * @return void
	 */
	public function test_sanitize_accessibility_features_flags_custom_entries(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$json = $this->invoke_private(
			'sanitize_accessibility_features',
			array(
				array( 'key' => 'gravity_corrected_upper_rows' ),
			)
		);

		$decoded = json_decode( (string) $json, true );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 'gravity_corrected_upper_rows', $decoded[0]['key'] );
		$this->assertTrue( $decoded[0]['custom'] );
	}

	/**
	 * Test sanitize_accessibility_features deduplicates by key.
	 *
	 * @return void
	 */
	public function test_sanitize_accessibility_features_deduplicates(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$json = $this->invoke_private(
			'sanitize_accessibility_features',
			array(
				array( 'key' => 'hearing_loop' ),
				array( 'key' => 'hearing_loop', 'count' => 2 ),
			)
		);

		$decoded = json_decode( (string) $json, true );
		$this->assertCount( 1, $decoded );
	}

	/**
	 * Test sanitize_accessibility_features drops entries without a key.
	 *
	 * @return void
	 */
	public function test_sanitize_accessibility_features_drops_keyless(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$result = $this->invoke_private(
			'sanitize_accessibility_features',
			array(
				array( 'count' => 5 ),
				array( 'key' => '' ),
				'not-an-entry',
			)
		);

		$this->assertNull( $result );
	}

	// =========================================================================
	// sanitize_gallery_ids() Tests
	// =========================================================================

	/**
	 * Test sanitize_gallery_ids returns null for empty input.
	 *
	 * @return void
	 */
	public function test_sanitize_gallery_ids_returns_null_for_empty(): void {
		$this->assertNull( $this->invoke_private( 'sanitize_gallery_ids', '' ) );
		$this->assertNull( $this->invoke_private( 'sanitize_gallery_ids', array() ) );
	}

	/**
	 * Test sanitize_gallery_ids keeps only image attachments.
	 *
	 * @return void
	 */
	public function test_sanitize_gallery_ids_keeps_images_only(): void {
		Functions\when( 'get_post_mime_type' )->alias(
			function ( $id ) {
				return match ( (int) $id ) {
					42 => 'image/jpeg',
					43 => 'application/pdf',
					44 => 'image/png',
					default => false,
				};
			}
		);

		$json = $this->invoke_private( 'sanitize_gallery_ids', '42,43,44,99' );

		$decoded = json_decode( (string) $json, true );
		$this->assertSame( array( 42, 44 ), $decoded );
	}

	/**
	 * Test sanitize_gallery_ids deduplicates IDs.
	 *
	 * @return void
	 */
	public function test_sanitize_gallery_ids_deduplicates(): void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );

		$json = $this->invoke_private( 'sanitize_gallery_ids', '7,7,8' );

		$decoded = json_decode( (string) $json, true );
		$this->assertSame( array( 7, 8 ), $decoded );
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

		$this->assertContains( 'admin_post_nettertech_events_save_space', $registered_hooks );
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
		$_POST['_nettertech_events_space_nonce'] = 'fake_nonce';

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

		unset( $_POST['_nettertech_events_space_nonce'] );
	}

	// =========================================================================
	// handle_save() — Save Logic Tests
	// =========================================================================

	/**
	 * Test handle_save creates new space.
	 *
	 * @return void
	 */
	public function test_handle_save_creates_new_space(): void {
		$_POST['_nettertech_events_space_nonce']   = 'valid_nonce';
		$_POST['space_id']           = '0';
		$_POST['space_name']         = 'Main Hall';
		$_POST['space_slug']         = '';
		$_POST['space_description']  = 'A large event space.';
		$_POST['space_capacity']     = '200';
		$_POST['space_amenities']    = 'WiFi, Projector';
		$_POST['space_status']       = 'active';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$this->repo->shouldReceive( 'save' )->once()->with( Mockery::on(
			function ( array $data ) {
				return 'Main Hall' === $data['name']
					&& 'main-hall' === $data['slug']
					&& 200 === $data['capacity']
					&& 'active' === $data['status']
					&& ! isset( $data['id'] );
			}
		) )->andReturn( 1 );

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
			$_POST['_nettertech_events_space_nonce'],
			$_POST['space_id'],
			$_POST['space_name'],
			$_POST['space_slug'],
			$_POST['space_description'],
			$_POST['space_capacity'],
			$_POST['space_amenities'],
			$_POST['space_status']
		);
	}

	/**
	 * Test handle_save updates existing space.
	 *
	 * @return void
	 */
	public function test_handle_save_updates_existing_space(): void {
		$_POST['_nettertech_events_space_nonce']   = 'valid_nonce';
		$_POST['space_id']           = '5';
		$_POST['space_name']         = 'Updated Hall';
		$_POST['space_slug']         = 'updated-hall';
		$_POST['space_description']  = '';
		$_POST['space_capacity']     = '300';
		$_POST['space_amenities']    = '';
		$_POST['space_status']       = 'inactive';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$this->repo->shouldReceive( 'save' )->once()->with( Mockery::on(
			function ( array $data ) {
				return 'Updated Hall' === $data['name']
					&& 5 === $data['id']
					&& 300 === $data['capacity'];
			}
		) )->andReturn( 5 );

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
			$_POST['_nettertech_events_space_nonce'],
			$_POST['space_id'],
			$_POST['space_name'],
			$_POST['space_slug'],
			$_POST['space_description'],
			$_POST['space_capacity'],
			$_POST['space_amenities'],
			$_POST['space_status']
		);
	}

	/**
	 * Test handle_save rejects invalid status and defaults to active.
	 *
	 * @return void
	 */
	public function test_handle_save_rejects_invalid_status(): void {
		$_POST['_nettertech_events_space_nonce']   = 'valid_nonce';
		$_POST['space_id']           = '0';
		$_POST['space_name']         = 'Status Test';
		$_POST['space_slug']         = '';
		$_POST['space_description']  = '';
		$_POST['space_capacity']     = '50';
		$_POST['space_amenities']    = '';
		$_POST['space_status']       = 'hacked_value';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$this->repo->shouldReceive( 'save' )->once()->with( Mockery::on(
			function ( array $data ) {
				return 'active' === $data['status'];
			}
		) )->andReturn( 1 );

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
			$_POST['_nettertech_events_space_nonce'],
			$_POST['space_id'],
			$_POST['space_name'],
			$_POST['space_slug'],
			$_POST['space_description'],
			$_POST['space_capacity'],
			$_POST['space_amenities'],
			$_POST['space_status']
		);
	}

	/**
	 * Test handle_save redirects with error on exception.
	 *
	 * @return void
	 */
	public function test_handle_save_redirects_on_error(): void {
		$_POST['_nettertech_events_space_nonce']   = 'valid_nonce';
		$_POST['space_id']           = '0';
		$_POST['space_name']         = 'Failing Space';
		$_POST['space_slug']         = '';
		$_POST['space_description']  = '';
		$_POST['space_capacity']     = '0';
		$_POST['space_amenities']    = '';
		$_POST['space_status']       = 'active';

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
			$_POST['_nettertech_events_space_nonce'],
			$_POST['space_id'],
			$_POST['space_name'],
			$_POST['space_slug'],
			$_POST['space_description'],
			$_POST['space_capacity'],
			$_POST['space_amenities'],
			$_POST['space_status']
		);
	}
}
