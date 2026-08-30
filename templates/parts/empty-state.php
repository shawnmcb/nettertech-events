<?php
/**
 * Template part: Empty State
 *
 * Displays a message when no events are found.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/parts/empty-state.php
 *
 * Available variables (read via $context):
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var string $context->message    Custom message to display (default: "No events found.").
 * @var string $context->context    Context for the empty state: 'list', 'search', 'filter' (default: 'list').
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * PHPStan reads only standalone assertions, not the header var-list
 * (audit GAP-029 rollout).
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context
 */

$nettertech_events_empty_state_context = $context->get( 'context', 'list' );

// Determine the default message based on context.
$nettertech_events_empty_state_message = $context->get( 'message', null );
if ( null === $nettertech_events_empty_state_message ) {
	switch ( $nettertech_events_empty_state_context ) {
		case 'search':
			$nettertech_events_empty_state_message = __( 'No events match your search.', 'nettertech-events' );
			break;
		case 'filter':
			$nettertech_events_empty_state_message = __( 'No events found for the selected category.', 'nettertech-events' );
			break;
		default:
			$nettertech_events_empty_state_message = __( 'No events found.', 'nettertech-events' );
	}
}

/**
 * Filter the empty state message.
 *
 * @param string $message The message to display.
 * @param string $context The context: 'list', 'search', 'filter'.
 */
$nettertech_events_empty_state_message = apply_filters( 'nettertech_events_empty_state_message', $nettertech_events_empty_state_message, $nettertech_events_empty_state_context );
?>
<div class="nte-empty" role="status">
	<p class="nte-empty__message"><?php echo esc_html( $nettertech_events_empty_state_message ); ?></p>
	<?php
	/**
	 * Hook to add additional content to the empty state.
	 *
	 * @param string $context The context: 'list', 'search', 'filter'.
	 */
	do_action( 'nettertech_events_empty_state_content', $nettertech_events_empty_state_context );
	?>
</div>
