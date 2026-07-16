<?php
/**
 * IdentityMapTrait unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Traits
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Traits;

use NetterTechEvents\Traits\IdentityMapTrait;

/**
 * Test IdentityMapTrait functionality.
 *
 * Uses an anonymous class to consume the trait in isolation.
 */
class IdentityMapTraitTest extends \NetterTechEventsTestCase {

	/**
	 * Create a test consumer of the trait.
	 *
	 * @return object Anonymous class using IdentityMapTrait with public method wrappers.
	 */
	private function make_consumer(): object {
		return new class() {
			use IdentityMapTrait;

			/**
			 * Public wrapper for remember().
			 *
			 * @param int    $id     Entity ID.
			 * @param object $entity Entity instance.
			 * @return void
			 */
			public function do_remember( int $id, object $entity ): void {
				$this->remember( $id, $entity );
			}

			/**
			 * Public wrapper for recalled().
			 *
			 * @param int $id Entity ID.
			 * @return object|null
			 */
			public function do_recalled( int $id ): ?object {
				return $this->recalled( $id );
			}

			/**
			 * Public wrapper for forget().
			 *
			 * @param int $id Entity ID.
			 * @return void
			 */
			public function do_forget( int $id ): void {
				$this->forget( $id );
			}
		};
	}

	/**
	 * Test remember and recalled roundtrip.
	 *
	 * @return void
	 */
	public function test_remember_and_recalled_roundtrip(): void {
		$consumer = $this->make_consumer();
		$entity   = (object) array( 'name' => 'Test' );

		$consumer->do_remember( 1, $entity );

		$this->assertSame( $entity, $consumer->do_recalled( 1 ) );
	}

	/**
	 * Test recalled returns null for unknown ID.
	 *
	 * @return void
	 */
	public function test_recalled_returns_null_for_unknown_id(): void {
		$consumer = $this->make_consumer();

		$this->assertNull( $consumer->do_recalled( 999 ) );
	}

	/**
	 * Test forget removes single entity.
	 *
	 * @return void
	 */
	public function test_forget_removes_single_entity(): void {
		$consumer = $this->make_consumer();
		$entity_a = (object) array( 'name' => 'A' );
		$entity_b = (object) array( 'name' => 'B' );

		$consumer->do_remember( 1, $entity_a );
		$consumer->do_remember( 2, $entity_b );

		$consumer->do_forget( 1 );

		$this->assertNull( $consumer->do_recalled( 1 ) );
		$this->assertSame( $entity_b, $consumer->do_recalled( 2 ) );
	}

	/**
	 * Test remember overwrites previous entry for same ID.
	 *
	 * @return void
	 */
	public function test_remember_overwrites_previous_entry(): void {
		$consumer   = $this->make_consumer();
		$original   = (object) array( 'name' => 'Original' );
		$replacement = (object) array( 'name' => 'Replacement' );

		$consumer->do_remember( 1, $original );
		$consumer->do_remember( 1, $replacement );

		$this->assertSame( $replacement, $consumer->do_recalled( 1 ) );
	}

	/**
	 * Test forget on nonexistent ID does not error.
	 *
	 * @return void
	 */
	public function test_forget_nonexistent_id_is_noop(): void {
		$consumer = $this->make_consumer();

		// Should not throw.
		$consumer->do_forget( 999 );

		$this->assertNull( $consumer->do_recalled( 999 ) );
	}
}
