<?php
/**
 * Template part: Single event description.
 *
 * Displays the event's full description content.
 *
 * @package NetterTechEvents
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var \NetterTechEvents\Models\Event                   $context->event The event.
 */

declare(strict_types=1);

// Guard against direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHPStan reads only standalone assertions, not the header var-list
 * (audit GAP-029 rollout).
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context
 */

if ( ! $context->event || empty( $context->event->description ) ) {
	return;
}
?>

<section class="nte-single-event__content">
	<?php if ( '' !== \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->display->description_heading ) : ?>
		<h2 class="nte-single-event__section-title"><?php echo esc_html( \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->display->description_heading ); ?></h2>
	<?php endif; ?>
	<div class="nte-single-event__description">
		<?php
		// Run the description through WordPress's content pipeline (wpautop, shortcodes, and
		// oEmbed auto-embeds such as YouTube). This part's output is sanitized by the parent
		// template (single-event.php) via wp_kses() against ShortcodeOutput::get_allowlist(),
		// which permits oEmbed <iframe> markup, so embedded videos survive. Do NOT wrap this in
		// wp_kses_post() here: the default post allowlist has no <iframe>, so it would strip the
		// embed before the parent ever sees it. The description is already wp_kses_post()-
		// sanitized at the input boundary (EventSaveHandler).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content is a core filter; this part's output is sanitized by single-event.php via wp_kses( ShortcodeOutput::get_allowlist() ).
		echo apply_filters( 'the_content', $context->event->description );
		?>
	</div>
</section>
