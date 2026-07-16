<?php
/**
 * BlockPatterns unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use Brain\Monkey\Functions;
use NetterTechEvents\Core\BlockPatterns;

/**
 * Test BlockPatterns class.
 *
 * Verifies the static registration entry points emit the expected
 * register_block_pattern* / register_block_pattern_category calls.
 *
 * @coversDefaultClass \NetterTechEvents\Core\BlockPatterns
 */
class BlockPatternsTest extends \NetterTechEventsTestCase {

	/**
	 * Track register_* calls per test.
	 *
	 * @var array<int, array{name: string, args: array<mixed>}>
	 */
	private array $registered = array();

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registered = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		Functions\when( 'register_block_pattern_category' )->alias(
			function ( $slug, $args ) {
				$this->registered[] = array(
					'name' => $slug,
					'args' => $args,
				);
			}
		);
		Functions\when( 'register_block_pattern' )->alias(
			function ( $slug, $args ) {
				$this->registered[] = array(
					'name' => $slug,
					'args' => $args,
				);
			}
		);
	}

	/**
	 * Test register() does not throw.
	 *
	 * @return void
	 */
	public function test_register_does_not_throw(): void {
		BlockPatterns::register();
		$this->assertTrue( true );
	}

	/**
	 * Test register_category creates the nettertech-events category.
	 *
	 * @return void
	 */
	public function test_register_category_registers_pattern_category(): void {
		BlockPatterns::register_category();

		$names = array_column( $this->registered, 'name' );
		$this->assertContains( 'nettertech-events', $names );
	}

	/**
	 * Test register_patterns registers multiple patterns.
	 *
	 * @return void
	 */
	public function test_register_patterns_registers_multiple_patterns(): void {
		BlockPatterns::register_patterns();

		$this->assertGreaterThan( 0, count( $this->registered ) );
		foreach ( $this->registered as $entry ) {
			$this->assertStringStartsWith( 'nettertech-events/', $entry['name'] );
		}
	}

	/**
	 * Test each registered pattern includes a content key.
	 *
	 * @return void
	 */
	public function test_each_pattern_has_content(): void {
		BlockPatterns::register_patterns();

		$this->assertGreaterThan( 0, count( $this->registered ) );
		foreach ( $this->registered as $entry ) {
			$this->assertArrayHasKey( 'content', $entry['args'] );
			$this->assertNotEmpty( $entry['args']['content'] );
		}
	}
}
