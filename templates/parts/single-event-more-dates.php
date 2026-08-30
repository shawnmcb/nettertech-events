<?php
/**
 * Template part: Single event more dates navigation.
 *
 * Displays navigation between sibling occurrences of the same event.
 * Only shown when viewing a specific occurrence that has siblings.
 *
 * @package NetterTechEvents
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var \NetterTechEvents\Models\Event                   $context->event         The event.
 * @var \NetterTechEvents\Models\Occurrence              $context->occurrence    Current occurrence.
 * @var array                                            $context->siblings      Sibling occurrence data.
 * @var int                                              $context->sibling_count Total sibling count.
 */

declare(strict_types=1);

use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;
use NetterTechEvents\TemplateLoader\Templates;

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

// This component only renders for specific occurrence views with siblings.
if ( ! $context->occurrence || ! $context->siblings || $context->get( 'sibling_count', 0 ) <= 1 ) {
	return;
}

echo wp_kses(
	Templates::get_part(
		'more-dates',
		array(
			'occurrence'    => $context->occurrence,
			'event'         => $context->event,
			'siblings'      => $context->siblings,
			'sibling_count' => $context->get( 'sibling_count', 0 ),
			'show_view_all' => true,
		)
	),
	ShortcodeOutput::get_allowlist()
);
