<?php
/**
 * Template part: Single event featured image.
 *
 * Displays the event's featured image.
 *
 * @package NetterTechEvents
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var int|null                                         $context->featured_image_id WordPress attachment ID.
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

if ( ! $context->get( 'featured_image_id', null ) ) {
	return;
}

// Per-event vertical crop anchor → object-position Y, from a fixed allowlist.
// center/null falls through to 50% (the same value the CSS var defaults to).
$anchor_map = array(
	'top'    => '0%',
	'bottom' => '100%',
);
$anchor_y   = $anchor_map[ (string) $context->get( 'image_vertical_anchor', '' ) ] ?? '50%';
?>

<div class="nte-single-event__image" style="--nte-single-image-anchor-y: <?php echo esc_attr( $anchor_y ); ?>;">
	<?php
	// wp_get_attachment_image() is a recognized escape primitive in PHPCS.
	echo wp_get_attachment_image( $context->get( 'featured_image_id', null ), 'large', false, array( 'class' => 'nte-single-event__img' ) );
	?>
</div>
