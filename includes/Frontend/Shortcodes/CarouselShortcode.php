<?php
/**
 * Carousel shortcode.
 *
 * @package NetterTechEvents\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Frontend\FrontendBranding;
use NetterTechEvents\Frontend\OccurrenceAvailabilityPresenter;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\TemplateLoader\Templates;
use NetterTechEvents\Utilities\ImageUtility;

/**
 * Renders an events carousel.
 *
 * Usage: [nettertech_events_carousel limit="6" columns="3" show_image="true"]
 *
 * @since 0.8.0
 */
class CarouselShortcode {

	/**
	 * Default attributes.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'limit'         => 6,
		'columns'       => 3,
		'show_image'    => true,
		'show_date'     => true,
		'show_time'     => true,
		'show_venue'    => true,
		'show_price'    => true,
		'show_year'     => false,
		'autoplay'      => false,
		'interval'      => 5000,
		'playback_mode' => 'rewind',
		'max_tags'      => 3,
		'class'         => '',
		'image_ratio'   => '', // Per-instance image aspect ratio override.
	);

	/**
	 * Allowed playback mode values.
	 *
	 * Rewind = current snap-back behavior on end-of-track.
	 * Loop   = continuous, seamless forward motion (JS clones leading cards).
	 *
	 * Invalid values fall back to 'rewind' in normalization.
	 *
	 * @since 1.1.0
	 *
	 * @var array<int, string>
	 */
	private const PLAYBACK_MODES = array( 'rewind', 'loop' );

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Templates service.
	 *
	 * @since 1.6.0
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Tag repository (used for batched tag prefetch).
	 *
	 * @since 1.0.3
	 *
	 * @var TagRepositoryInterface
	 */
	private TagRepositoryInterface $tag_repo;

	/**
	 * Ticket type repository (used for batched on-sale prefetch).
	 *
	 * @since 1.0.3
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface        $occurrence_repo  Occurrence repository.
	 * @param Templates                            $templates        Templates service.
	 * @param TagRepositoryInterface               $tag_repo         Tag repository.
	 * @param TicketTypeRepositoryInterface        $ticket_type_repo Ticket type repository.
	 * @param OccurrenceAvailabilityPresenter|null $availability   Availability presenter (sold-out badge). Optional for BC.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		Templates $templates,
		TagRepositoryInterface $tag_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		private readonly ?OccurrenceAvailabilityPresenter $availability = null
	) {
		$this->occurrence_repo  = $occurrence_repo;
		$this->templates        = $templates;
		$this->tag_repo         = $tag_repo;
		$this->ticket_type_repo = $ticket_type_repo;
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes.
	 * @param string|null                 $content Shortcode content (unused, required by WP shortcode API).
	 * @return string
	 */
	public function render( $atts = array(), ?string $content = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		FrontendBranding::mark_content_rendered();

		$atts = shortcode_atts( self::DEFAULTS, is_array( $atts ) ? $atts : array(), 'nettertech_events_carousel' );

		// Normalize boolean attributes.
		$atts['show_image'] = filter_var( $atts['show_image'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_date']  = filter_var( $atts['show_date'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_time']  = filter_var( $atts['show_time'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_venue'] = filter_var( $atts['show_venue'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_price'] = filter_var( $atts['show_price'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_year']  = filter_var( $atts['show_year'], FILTER_VALIDATE_BOOLEAN );
		$atts['autoplay']   = filter_var( $atts['autoplay'], FILTER_VALIDATE_BOOLEAN );

		// Whitelist playback mode; invalid values fall back to rewind.
		$atts['playback_mode'] = in_array( $atts['playback_mode'], self::PLAYBACK_MODES, true )
			? $atts['playback_mode']
			: 'rewind';

		// Non-negative int; 0 = no limit (show all tags). Negative input treated as 0.
		$atts['max_tags'] = max( 0, (int) $atts['max_tags'] );

		// Get upcoming occurrences.
		$occurrences = $this->occurrence_repo->upcoming( (int) $atts['limit'] );

		if ( empty( $occurrences ) ) {
			return $this->render_empty();
		}

		// Build output.
		return $this->render_carousel( $occurrences, $atts );
	}

	/**
	 * Render empty state.
	 *
	 * @return string
	 */
	private function render_empty(): string {
		$message = apply_filters(
			'nettertech_events_carousel_empty_message',
			__( 'No upcoming events.', 'nettertech-events' )
		);

		return sprintf(
			'<div class="nte-carousel nte-carousel--empty"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Render the carousel.
	 *
	 * @param array<\NetterTechEvents\Models\Occurrence> $occurrences Occurrences.
	 * @param array<string, mixed>                       $atts        Attributes.
	 * @return string
	 */
	private function render_carousel( array $occurrences, array $atts ): string {
		$class = 'nte-carousel';
		if ( ! empty( $atts['class'] ) ) {
			$class .= ' ' . sanitize_html_class( $atts['class'] );
		}

		// Prefetch tags + on-sale ticket types for all cards (SA-01 N+1 prevention).
		// Ticket types are fetched when price is shown OR an extension opted into card
		// availability (NTE-203) — free/base installs with prices hidden pay no extra query.
		$needs_availability      = (bool) apply_filters( Hooks::CARDS_NEED_AVAILABILITY, false );
		$prefetched_tags         = $this->prefetch_tags_for_occurrences( $occurrences );
		$prefetched_ticket_types = ( $atts['show_price'] || $needs_availability )
			? $this->prefetch_ticket_types_for_occurrences( $occurrences )
			: array();
		$prefetched_availability = $this->availability_verdicts( $prefetched_ticket_types, $needs_availability );

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( $class ); ?>"
			data-columns="<?php echo esc_attr( (string) (int) $atts['columns'] ); ?>"
			data-autoplay="<?php echo esc_attr( $atts['autoplay'] ? 'true' : 'false' ); ?>"
			data-interval="<?php echo esc_attr( (string) (int) $atts['interval'] ); ?>"
			data-playback-mode="<?php echo esc_attr( $atts['playback_mode'] ); ?>"
			role="region"
			aria-label="<?php esc_attr_e( 'Events carousel', 'nettertech-events' ); ?>"
		>
			<div class="nte-carousel__track">
				<?php foreach ( $occurrences as $occurrence ) : ?>
					<?php echo wp_kses( $this->render_card( $occurrence, $atts, $prefetched_tags, $prefetched_ticket_types, $prefetched_availability ), ShortcodeOutput::get_allowlist() ); ?>
				<?php endforeach; ?>
			</div>

			<?php if ( count( $occurrences ) > (int) $atts['columns'] ) : ?>
				<button class="nte-carousel__nav nte-carousel__nav--prev" aria-label="<?php esc_attr_e( 'Previous', 'nettertech-events' ); ?>">
					<svg class="nte-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
				</button>
				<button class="nte-carousel__nav nte-carousel__nav--next" aria-label="<?php esc_attr_e( 'Next', 'nettertech-events' ); ?>">
					<svg class="nte-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>
				</button>
			<?php endif; ?>

			<div class="nte-carousel__live-region" aria-live="polite" aria-atomic="true"></div>
			<?php echo wp_kses( FrontendBranding::render_badge(), ShortcodeOutput::get_allowlist() ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a single event card.
	 *
	 * Delegates to the event-card template part for consistent rendering
	 * and theme override capability. Passes prefetched tag + ticket-type
	 * maps so the template part can avoid per-card queries (SA-01 N+1).
	 *
	 * @param \NetterTechEvents\Models\Occurrence                    $occurrence              Occurrence.
	 * @param array<string, mixed>                                   $atts                    Attributes.
	 * @param array<int, array<\NetterTechEvents\Models\Tag>>        $prefetched_tags         Map of event_id => list of tags.
	 * @param array<int, array<\NetterTechEvents\Models\TicketType>> $prefetched_ticket_types Map of occurrence_id => list of on-sale ticket types.
	 * @param array<int, array{sold_out: bool}>                      $prefetched_availability Map of occurrence_id => availability verdict.
	 * @return string
	 */
	private function render_card( $occurrence, array $atts, array $prefetched_tags, array $prefetched_ticket_types, array $prefetched_availability = array() ): string {
		$event       = $occurrence->get_event();
		$event_id    = $event ? (int) $event->id : 0;
		$occ_id      = (int) $occurrence->id;
		$card_tags   = $prefetched_tags[ $event_id ] ?? array();
		$card_prices = $prefetched_ticket_types[ $occ_id ] ?? array();

		return $this->templates->get_template_part(
			'event-card',
			array(
				'occurrence'              => $occurrence,
				'event'                   => $event,
				'show_image'              => (bool) $atts['show_image'],
				'show_date'               => (bool) $atts['show_date'],
				'show_time'               => (bool) $atts['show_time'],
				'show_venue'              => (bool) $atts['show_venue'],
				'show_price'              => (bool) $atts['show_price'],
				'show_excerpt'            => false,
				'image_ratio'             => ImageUtility::sanitize_image_ratio( $atts['image_ratio'] ?? '' ),
				'heading_tag'             => 'h3',
				'max_tags'                => (int) $atts['max_tags'],
				'prefetched_tags'         => $card_tags,
				'prefetched_ticket_types' => $card_prices,
				'prefetched_availability' => $prefetched_availability[ $occ_id ] ?? array( 'sold_out' => false ),
			)
		);
	}

	/**
	 * Compute per-occurrence availability verdicts from prefetched on-sale types.
	 *
	 * Runs only when an extension opted in via Hooks::CARDS_NEED_AVAILABILITY —
	 * the per-type capacity summaries are the expensive part, and base renders
	 * no availability UI of its own (NTE-203).
	 *
	 * @param array<int, array<\NetterTechEvents\Models\TicketType>> $prefetched_ticket_types Map of occurrence_id => on-sale ticket types.
	 * @param bool                                                   $needs_availability      Whether an extension requested verdicts.
	 * @return array<int, array{sold_out: bool}> Map of occurrence_id => verdict.
	 */
	private function availability_verdicts( array $prefetched_ticket_types, bool $needs_availability ): array {
		if ( ! $needs_availability || null === $this->availability ) {
			return array();
		}

		$verdicts = array();
		foreach ( $prefetched_ticket_types as $occ_id => $ticket_types ) {
			$verdicts[ $occ_id ] = array( 'sold_out' => $this->availability->is_sold_out( $ticket_types ) );
		}

		return $verdicts;
	}

	/**
	 * Prefetch tags for every occurrence's parent event in one query.
	 *
	 * @param array<\NetterTechEvents\Models\Occurrence> $occurrences Occurrences to render.
	 * @return array<int, array<\NetterTechEvents\Models\Tag>> Map of event_id => list of tags.
	 */
	private function prefetch_tags_for_occurrences( array $occurrences ): array {
		if ( empty( $occurrences ) ) {
			return array();
		}

		$event_ids = array();
		foreach ( $occurrences as $occurrence ) {
			$event = $occurrence->get_event();
			if ( $event && $event->id ) {
				$event_ids[] = (int) $event->id;
			}
		}

		if ( empty( $event_ids ) ) {
			return array();
		}

		return $this->tag_repo->find_by_event_ids( $event_ids );
	}

	/**
	 * Prefetch on-sale ticket types for every occurrence in one query.
	 *
	 * Always fires, independent of show_price: prices need it when shown,
	 * and the sold-out badge (NTE-203) needs it on every layout.
	 *
	 * @param array<\NetterTechEvents\Models\Occurrence> $occurrences Occurrences to render.
	 * @return array<int, array<\NetterTechEvents\Models\TicketType>> Map of occurrence_id => list of on-sale ticket types.
	 */
	private function prefetch_ticket_types_for_occurrences( array $occurrences ): array {
		if ( empty( $occurrences ) ) {
			return array();
		}

		$occurrence_ids = array();
		foreach ( $occurrences as $occurrence ) {
			if ( $occurrence->id ) {
				$occurrence_ids[] = (int) $occurrence->id;
			}
		}

		if ( empty( $occurrence_ids ) ) {
			return array();
		}

		return $this->ticket_type_repo->get_on_sale_for_occurrences( $occurrence_ids );
	}
}
