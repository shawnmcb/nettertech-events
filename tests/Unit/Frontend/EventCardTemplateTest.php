<?php
/**
 * Event card template regression tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use NetterTechEventsTestCase;

/**
 * Source-level regression gate for templates/parts/event-card.php.
 *
 * NTE-177 / FR-011: a per-occurrence hero image override must be rendered by
 * the card listing, not just the single-event page. The card template must
 * therefore resolve its image via Occurrence::get_featured_image_id() (which
 * falls back to the event image) and never read the event image directly.
 * Behavioural coverage of the override/fallback lives in OccurrenceTest;
 * SingleEventPageHandlerTest covers the single-page path.
 */
class EventCardTemplateTest extends NetterTechEventsTestCase {

	/**
	 * Absolute path to the card template.
	 *
	 * @return string
	 */
	private function template_path(): string {
		return dirname( __DIR__, 3 ) . '/templates/parts/event-card.php';
	}

	/**
	 * The card must source its image from the occurrence (override-aware).
	 *
	 * @return void
	 */
	public function test_card_image_uses_occurrence_accessor(): void {
		$source = (string) file_get_contents( $this->template_path() );

		$this->assertStringContainsString(
			'$context->occurrence->get_featured_image_id()',
			$source,
			'event-card.php must resolve its image via the occurrence accessor so per-occurrence hero overrides render on cards.'
		);
	}

	/**
	 * The card must not bypass the override by reading the event image directly.
	 *
	 * @return void
	 */
	public function test_card_image_does_not_read_event_image_directly(): void {
		$source = (string) file_get_contents( $this->template_path() );

		$this->assertStringNotContainsString(
			'$context->event->featured_image_id',
			$source,
			'event-card.php must not read the event image directly; that ignores per-occurrence hero overrides (NTE-177).'
		);
	}
}
