<?php
/**
 * Allowlist contract integration test (INV-R2).
 *
 * Every interactive element emitted through the template pipeline
 * (wp_kses + ShortcodeOutput::get_allowlist()) must retain the tags and
 * attributes it needs to function. Two production incidents motivated this
 * contract: oEmbed <iframe> stripping (fixed in 45ca9c0) and the RSVP form
 * container's inline display:none being silently removed (NTE-131).
 *
 * Runs in the Integration suite because the Unit bootstrap stubs wp_kses
 * as a pass-through, which would make these assertions vacuous.
 *
 * @package NetterTechEvents\Tests\Integration\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Frontend;

use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;

/**
 * Integration test: interactive markup survives the kses allowlist.
 */
class AllowlistContractTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * The RSVP toggle button must survive sanitization byte-for-byte.
	 *
	 * @return void
	 */
	public function test_rsvp_toggle_button_survives_allowlist(): void {
		$markup = '<button type="button" class="nte-btn nte-btn--primary wp-element-button nte-rsvp-toggle" data-occurrence="42" aria-expanded="false" aria-controls="rsvp-form-42">RSVP</button>';

		$this->assertSame( $markup, wp_kses( $markup, ShortcodeOutput::get_allowlist() ) );
	}

	/**
	 * The RSVP form (container, form, fields, submit) must survive sanitization.
	 *
	 * @return void
	 */
	public function test_rsvp_form_survives_allowlist(): void {
		$markup = '<div class="nte-rsvp-form-container" id="rsvp-form-42">'
			. '<div class="nte-rsvp-form-wrapper">'
			. '<form method="post" class="nte-rsvp-form" aria-label="RSVP registration form">'
			. '<input type="hidden" id="nettertech_events_rsvp_nonce" name="nettertech_events_rsvp_nonce" value="abc123">'
			. '<input type="hidden" name="nettertech_events_rsvp_occurrence_id" value="42">'
			. '<p class="screen-reader-text">Required fields are marked with an asterisk (*).</p>'
			. '<div class="nte-rsvp-field">'
			. '<label for="nte-rsvp-name">Name <span class="required" aria-hidden="true">*</span></label>'
			. '<input type="text" id="nte-rsvp-name" name="nettertech_events_rsvp_name" value="" required aria-required="true">'
			. '</div>'
			. '<select id="nte-rsvp-quantity" name="nettertech_events_rsvp_quantity"><option value="1">1</option></select>'
			. '<button type="submit" name="nettertech_events_rsvp_submit" class="nte-rsvp-button wp-element-button">RSVP Now</button>'
			. '</form>'
			. '</div>'
			. '</div>';

		$this->assertSame( $markup, wp_kses( $markup, ShortcodeOutput::get_allowlist() ) );
	}

	/**
	 * oEmbed video iframes must survive sanitization (45ca9c0 regression class).
	 *
	 * @return void
	 */
	public function test_oembed_iframe_survives_allowlist(): void {
		$markup = '<iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/abc123?feature=oembed" allowfullscreen></iframe>';

		$result = wp_kses( $markup, ShortcodeOutput::get_allowlist() );

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringContainsString( 'src="https://www.youtube.com/embed/abc123?feature=oembed"', $result );
	}

	/**
	 * The occurrence-actions surface (occurrence-row.php) merges the post
	 * allowlist with the canonical one; interactive markup must survive THAT
	 * merged list too. A previous hand-built copy in the template used an
	 * 'aria-*' wildcard key — unsupported by wp_kses (only 'data-*' is
	 * special-cased in core) — silently stripping aria-label/aria-required
	 * from the live RSVP form while this test's canonical-only assertions
	 * stayed green. Found during the 1.0.2 → 1.1.1 upgrade-path test.
	 *
	 * @return void
	 */
	public function test_rsvp_form_survives_occurrence_actions_pipeline(): void {
		$merged = array_merge( wp_kses_allowed_html( 'post' ), ShortcodeOutput::get_allowlist() );

		$markup = '<form method="post" class="nte-rsvp-form" aria-label="RSVP registration form">'
			. '<input type="text" id="nte-rsvp-name" name="nettertech_events_rsvp_name" value="" required aria-required="true">'
			. '<button type="submit" name="nettertech_events_rsvp_submit" class="nte-rsvp-button wp-element-button">RSVP Now</button>'
			. '</form>';

		$this->assertSame( $markup, wp_kses( $markup, $merged ) );
	}

	/**
	 * Inline style attributes are STRIPPED by the allowlist — pinned on purpose.
	 *
	 * This is the documented contract behind NTE-131: markup routed through
	 * the template pipeline must not rely on inline styles for behavior
	 * (e.g., display:none initial states belong to JS/CSS, not style attrs).
	 * If this test ever fails because style survives, the allowlist widened —
	 * revisit the rule in ADR-018 before accepting it.
	 *
	 * @return void
	 */
	public function test_inline_style_attribute_is_stripped(): void {
		$markup = '<div class="nte-rsvp-form-container" id="rsvp-form-42" style="display: none;">x</div>';

		$this->assertStringNotContainsString( 'style=', wp_kses( $markup, ShortcodeOutput::get_allowlist() ) );
	}
}
