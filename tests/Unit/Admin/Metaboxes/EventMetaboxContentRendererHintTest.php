<?php
/**
 * Tests for the NTE-132 cards aspect-ratio upload hint.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Metaboxes\EventMetaboxContentRenderer;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Verifies EventMetaboxContentRenderer::resolve_cards_image_hint() resolves the
 * Events-page (cards) aspect ratio and recommended pixel dimensions the same way
 * InlineCssGenerator resolves the cards ratio.
 */
class EventMetaboxContentRendererHintTest extends \NetterTechEventsTestCase {

	/**
	 * Invoke the private hint resolver with a stubbed settings option.
	 *
	 * @param array<string, mixed> $settings Settings option array.
	 * @return array{ratio_label: string, dimensions: string}
	 */
	private function invoke_hint( array $settings ): array {
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( $settings ) {
				return 'nettertech_events_settings' === $key ? $settings : $default;
			}
		);

		$renderer = new EventMetaboxContentRenderer(
			new Event(),
			0,
			$this->createMock( CategoryRepositoryInterface::class )
		);

		$method = new \ReflectionMethod( $renderer, 'resolve_cards_image_hint' );

		/** @var array{ratio_label: string, dimensions: string} $result */
		$result = $method->invoke( $renderer );
		return $result;
	}

	/**
	 * The cards-view override ratio is used when set.
	 *
	 * @return void
	 */
	public function test_uses_cards_override_ratio(): void {
		$hint = $this->invoke_hint( array( 'image_aspect_ratio_cards' => '4:3' ) );

		$this->assertSame( '4:3', $hint['ratio_label'] );
		$this->assertSame( '1200 × 900 px', $hint['dimensions'] );
	}

	/**
	 * Falls back to the site default ratio when no cards override exists.
	 *
	 * @return void
	 */
	public function test_falls_back_to_default_ratio(): void {
		$hint = $this->invoke_hint( array( 'image_aspect_ratio' => '1:1' ) );

		$this->assertSame( '1:1', $hint['ratio_label'] );
		$this->assertSame( '1200 × 1200 px', $hint['dimensions'] );
	}

	/**
	 * Defaults to 16:9 / 1200×675 when nothing is configured.
	 *
	 * @return void
	 */
	public function test_defaults_to_16_9(): void {
		$hint = $this->invoke_hint( array() );

		$this->assertSame( '16:9', $hint['ratio_label'] );
		$this->assertSame( '1200 × 675 px', $hint['dimensions'] );
	}

	/**
	 * The 'original' preset (no crop) recommends a minimum width only.
	 *
	 * @return void
	 */
	public function test_original_preset_recommends_min_width(): void {
		$hint = $this->invoke_hint( array( 'image_aspect_ratio_cards' => 'original' ) );

		$this->assertSame( 'Original (no crop)', $hint['ratio_label'] );
		$this->assertSame( '1200px wide', $hint['dimensions'] );
	}

	/**
	 * Build an occurrence with a start datetime and override flag.
	 *
	 * @param string $start_datetime Y-m-d H:i:s.
	 * @param bool   $is_override    Hand-picked flag.
	 * @return Occurrence
	 */
	private function make_occurrence( string $start_datetime, bool $is_override ): Occurrence {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = $start_datetime;
		$occurrence->timezone       = 'UTC';
		$occurrence->is_override    = $is_override;
		return $occurrence;
	}

	/**
	 * Invoke the private override-label collector.
	 *
	 * @param array<Occurrence> $occurrences Occurrences to pass in.
	 * @return array<int, string>
	 */
	private function invoke_override_labels( array $occurrences ): array {
		Functions\when( 'get_option' )->justReturn( 'Y-m-d H:i' );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $timestamp ) => 'D' . $timestamp
		);

		$renderer = new EventMetaboxContentRenderer(
			new Event(),
			0,
			$this->createMock( CategoryRepositoryInterface::class )
		);

		$method = new \ReflectionMethod( $renderer, 'collect_override_date_labels' );

		/** @var array<int, string> $result */
		$result = $method->invoke( $renderer, $occurrences );
		return $result;
	}

	/**
	 * Only hand-picked (is_override) dates are named for the conversion warning (R4).
	 *
	 * @return void
	 */
	public function test_collect_override_labels_returns_only_overrides(): void {
		$generated = $this->make_occurrence( '2026-09-01 19:00:00', false );
		$hand_one  = $this->make_occurrence( '2026-09-05 14:00:00', true );
		$hand_two  = $this->make_occurrence( '2026-09-12 20:00:00', true );

		$labels = $this->invoke_override_labels( array( $generated, $hand_one, $hand_two ) );

		$this->assertCount( 2, $labels );
		$this->assertSame( 'D' . $hand_one->get_start()->getTimestamp(), $labels[0] );
		$this->assertSame( 'D' . $hand_two->get_start()->getTimestamp(), $labels[1] );
	}

	/**
	 * A series with no hand-picked dates yields no override labels.
	 *
	 * @return void
	 */
	public function test_collect_override_labels_empty_without_overrides(): void {
		$labels = $this->invoke_override_labels(
			array(
				$this->make_occurrence( '2026-09-01 19:00:00', false ),
				$this->make_occurrence( '2026-09-08 19:00:00', false ),
			)
		);

		$this->assertSame( array(), $labels );
	}
}
