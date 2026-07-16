<?php
/**
 * AccessibilityFeature catalog unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Catalog
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Catalog;

use NetterTechEvents\Catalog\AccessibilityFeature;
use Brain\Monkey\Functions;

/**
 * Test AccessibilityFeature catalog.
 *
 * @coversDefaultClass \NetterTechEvents\Catalog\AccessibilityFeature
 */
class AccessibilityFeatureTest extends \NetterTechEventsTestCase {

	/**
	 * Set up __() so labels resolve in test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Test presets() returns the full curated set.
	 *
	 * @return void
	 */
	public function test_presets_returns_curated_catalog(): void {
		$presets = AccessibilityFeature::presets();

		$this->assertCount( 18, $presets );
		$this->assertArrayHasKey( AccessibilityFeature::WHEELCHAIR_SEATING, $presets );
		$this->assertArrayHasKey( AccessibilityFeature::HEARING_LOOP, $presets );
		$this->assertArrayHasKey( AccessibilityFeature::SCENT_REDUCED_POLICY, $presets );
	}

	/**
	 * Test preset_keys returns all keys.
	 *
	 * @return void
	 */
	public function test_preset_keys_returns_all_keys(): void {
		$keys = AccessibilityFeature::preset_keys();

		$this->assertCount( 18, $keys );
		$this->assertContains( 'wheelchair_seating', $keys );
		$this->assertContains( 'asl_interpreter', $keys );
	}

	/**
	 * Test is_preset recognises a known key.
	 *
	 * @return void
	 */
	public function test_is_preset_recognises_known_key(): void {
		$this->assertTrue( AccessibilityFeature::is_preset( AccessibilityFeature::WHEELCHAIR_SEATING ) );
	}

	/**
	 * Test is_preset rejects unknown key.
	 *
	 * @return void
	 */
	public function test_is_preset_rejects_unknown_key(): void {
		$this->assertFalse( AccessibilityFeature::is_preset( 'gravity_corrected_upper_rows' ) );
	}

	/**
	 * Test label_for returns translated label for preset.
	 *
	 * @return void
	 */
	public function test_label_for_returns_label_for_preset(): void {
		$label = AccessibilityFeature::label_for( AccessibilityFeature::HEARING_LOOP );
		$this->assertStringContainsString( 'Hearing loop', $label );
	}

	/**
	 * Test label_for falls back to raw key for custom entries.
	 *
	 * @return void
	 */
	public function test_label_for_returns_raw_key_for_custom(): void {
		$this->assertSame( 'foxfire_warnings', AccessibilityFeature::label_for( 'foxfire_warnings' ) );
	}
}
