<?php
/**
 * PrivacyPolicyContent unit tests.
 *
 * Tests that wp_add_privacy_policy_content is called with the correct
 * plugin name and non-empty policy content on admin_init.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\PrivacyHooks;
use NetterTechEvents\Services\PrivacyService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test PrivacyHooks::register_privacy_policy_content().
 */
class PrivacyPolicyContentTest extends \NetterTechEventsTestCase {

	/**
	 * Mock privacy service.
	 *
	 * @var PrivacyService|Mockery\MockInterface
	 */
	private $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = Mockery::mock( PrivacyService::class );
	}

	/**
	 * Count Mockery expectations as assertions.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( Mockery::getContainer() ) {
			$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		}
		parent::tearDown();
	}

	/**
	 * Test that register hooks admin_init action for privacy policy content.
	 *
	 * @return void
	 */
	public function test_register_adds_admin_init_action_for_privacy_policy(): void {
		$actions_added = array();

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$actions_added ) {
				$actions_added[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		$hooks = new PrivacyHooks( $this->service );
		$hooks->register();

		$this->assertContains( 'admin_init', $actions_added );
	}

	/**
	 * Test that register_privacy_policy_content calls wp_add_privacy_policy_content
	 * with the plugin name and non-empty content.
	 *
	 * @return void
	 */
	public function test_register_privacy_policy_content_calls_wp_function(): void {
		$called_with = null;

		Functions\when( 'esc_html__' )->alias(
			function ( $text, $domain ) {
				return $text;
			}
		);
		Functions\when( 'esc_html' )->alias(
			function ( $text ) {
				return $text;
			}
		);
		Functions\when( 'wp_kses_post' )->alias(
			function ( $content ) {
				return $content;
			}
		);
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			function ( $plugin_name, $content ) use ( &$called_with ) {
				$called_with = array(
					'plugin_name' => $plugin_name,
					'content'     => $content,
				);
			}
		);

		$hooks = new PrivacyHooks( $this->service );
		$hooks->register_privacy_policy_content();

		$this->assertNotNull( $called_with, 'wp_add_privacy_policy_content was not called.' );
		$this->assertSame( 'NetterTech Events', $called_with['plugin_name'] );
		$this->assertNotEmpty( $called_with['content'] );
	}

	/**
	 * Test that policy content mentions key data types.
	 *
	 * @return void
	 */
	public function test_policy_content_describes_collected_data(): void {
		$captured_content = '';

		Functions\when( 'esc_html__' )->alias(
			function ( $text, $domain ) {
				return $text;
			}
		);
		Functions\when( 'esc_html' )->alias(
			function ( $text ) {
				return $text;
			}
		);
		Functions\when( 'wp_kses_post' )->alias(
			function ( $content ) {
				return $content;
			}
		);
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			function ( $plugin_name, $content ) use ( &$captured_content ) {
				$captured_content = $content;
			}
		);

		$hooks = new PrivacyHooks( $this->service );
		$hooks->register_privacy_policy_content();

		// Verify policy mentions the key data types described in the task brief.
		// Use case-insensitive checks since translations may capitalize differently.
		$lower = strtolower( $captured_content );
		$this->assertStringContainsString( 'email', $lower );
		$this->assertStringContainsString( 'ticket', $lower );
		$this->assertStringContainsString( 'waitlist', $lower );
		$this->assertStringContainsString( 'check-in', $lower );
		$this->assertStringContainsString( 'activity log', $lower );
	}
}
