<?php
/**
 * Event list/grid shortcode.
 *
 * @package NetterTechEvents\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Frontend\FrontendBranding;
use NetterTechEvents\Frontend\OccurrenceAvailabilityPresenter;
use NetterTechEvents\TemplateLoader\Templates;
use NetterTechEvents\Utilities\ImageUtility;

/**
 * Renders an event grid/list with optional filtering.
 *
 * Usage: [nettertech_events_list limit="12" columns="3" show_filters="true" layout="grid"]
 *
 * @since 0.8.0
 */
class EventListShortcode {

	/**
	 * Default attributes.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'limit'           => 12,
		'columns'         => 3,
		'layout'          => 'grid', // grid, list, cards.
		'show_filters'    => true,
		'show_search'     => true,
		'show_category'   => true,
		'show_tag'        => true,
		'show_date_range' => true,
		'show_image'      => true,
		'show_date'       => true,
		'show_time'       => true,
		'show_venue'      => true,
		'show_excerpt'    => false,
		'show_price'      => true,
		'pagination'      => true,
		'ajax'            => true,
		'category'        => '', // Pre-filter by category ID(s).
		'tag'             => '', // Pre-filter by tag slug or ID.
		'date_from'       => '', // Start date for range filter (Y-m-d).
		'date_to'         => '', // End date for range filter (Y-m-d).
		'class'           => '',
		'past'            => false, // Show past events instead of upcoming.
		'image_ratio'     => '', // Per-instance image aspect ratio override.
	);

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Category repository.
	 *
	 * @var CategoryRepositoryInterface
	 */
	private CategoryRepositoryInterface $category_repo;

	/**
	 * Tag repository.
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
	 * Templates service.
	 *
	 * @since 1.6.0
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Unique instance counter.
	 *
	 * @var int
	 */
	private static int $instance_count = 0;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface        $occurrence_repo  Occurrence repository.
	 * @param Templates                            $templates        Templates service.
	 * @param CategoryRepositoryInterface          $category_repo    Category repository.
	 * @param TagRepositoryInterface               $tag_repo         Tag repository.
	 * @param TicketTypeRepositoryInterface        $ticket_type_repo Ticket type repository.
	 * @param OccurrenceAvailabilityPresenter|null $availability   Availability presenter (sold-out badge). Optional for BC.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		Templates $templates,
		CategoryRepositoryInterface $category_repo,
		TagRepositoryInterface $tag_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		private readonly ?OccurrenceAvailabilityPresenter $availability = null
	) {
		$this->occurrence_repo  = $occurrence_repo;
		$this->templates        = $templates;
		$this->category_repo    = $category_repo;
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
		$atts = shortcode_atts( self::DEFAULTS, is_array( $atts ) ? $atts : array(), 'nettertech_events_list' );

		// Normalize boolean attributes.
		$atts['show_filters']    = filter_var( $atts['show_filters'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_search']     = filter_var( $atts['show_search'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_category']   = filter_var( $atts['show_category'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_tag']        = filter_var( $atts['show_tag'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_date_range'] = filter_var( $atts['show_date_range'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_image']      = filter_var( $atts['show_image'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_date']       = filter_var( $atts['show_date'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_time']       = filter_var( $atts['show_time'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_venue']      = filter_var( $atts['show_venue'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_excerpt']    = filter_var( $atts['show_excerpt'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_price']      = filter_var( $atts['show_price'], FILTER_VALIDATE_BOOLEAN );
		$atts['pagination']      = filter_var( $atts['pagination'], FILTER_VALIDATE_BOOLEAN );
		$atts['ajax']            = filter_var( $atts['ajax'], FILTER_VALIDATE_BOOLEAN );
		$atts['past']            = filter_var( $atts['past'], FILTER_VALIDATE_BOOLEAN );

		// Generate unique ID for this instance.
		++self::$instance_count;
		$instance_id = 'nte-grid-' . self::$instance_count;

		// Get initial occurrences.
		$query_args = array(
			'per_page' => (int) $atts['limit'],
			'page'     => 1,
			'upcoming' => ! $atts['past'],
			'past'     => $atts['past'],
		);

		if ( ! empty( $atts['category'] ) ) {
			// Pre-filter accepts a comma-separated list or array of category IDs.
			$raw_categories  = is_array( $atts['category'] ) ? $atts['category'] : explode( ',', (string) $atts['category'] );
			$category_values = array_values( array_filter( array_map( 'absint', $raw_categories ) ) );
			if ( ! empty( $category_values ) ) {
				$query_args['category'] = $category_values;
			}
		}

		if ( ! empty( $atts['tag'] ) ) {
			// Pre-filter accepts a comma-separated list or array of tag slugs/IDs.
			$raw_tags   = is_array( $atts['tag'] ) ? $atts['tag'] : explode( ',', (string) $atts['tag'] );
			$tag_values = array();
			foreach ( $raw_tags as $value ) {
				$value = sanitize_text_field( (string) $value );
				$value = trim( $value );
				if ( '' !== $value ) {
					$tag_values[] = $value;
				}
			}
			if ( ! empty( $tag_values ) ) {
				// Pass as scalar for single-value (back-compat with legacy callers + scalar query
				// builder path); array triggers the multi-value IN() path in OccurrenceFilterRepository.
				$query_args['tag'] = count( $tag_values ) > 1 ? $tag_values : $tag_values[0];
			}
		}

		if ( ! empty( $atts['date_from'] ) ) {
			$query_args['date_from'] = sanitize_text_field( (string) $atts['date_from'] );
		}

		if ( ! empty( $atts['date_to'] ) ) {
			$query_args['date_to'] = sanitize_text_field( (string) $atts['date_to'] );
		}

		$result = $this->occurrence_repo->get_filtered( $query_args );

		// Prefetch tags + on-sale ticket types for all rendered cards (SA-01 N+1 prevention).
		// Ticket types are fetched when price is shown OR an extension opted into card
		// availability (NTE-203) — free/base installs with prices hidden pay no extra query.
		$needs_availability      = (bool) apply_filters( Hooks::CARDS_NEED_AVAILABILITY, false );
		$prefetched_tags         = $this->prefetch_tags_for_occurrences( $result['items'] );
		$prefetched_ticket_types = ( $atts['show_price'] || $needs_availability )
			? $this->prefetch_ticket_types_for_occurrences( $result['items'] )
			: array();
		$prefetched_availability = $this->availability_verdicts( $prefetched_ticket_types, $needs_availability );

		// Mark that NTE content is being rendered (for frontend branding).
		FrontendBranding::mark_content_rendered();

		ob_start();
		?>
		<div
			class="nte-event-list <?php echo esc_attr( $atts['class'] ); ?>"
			id="<?php echo esc_attr( $instance_id ); ?>"
			data-instance="<?php echo esc_attr( (string) self::$instance_count ); ?>"
			data-ajax="<?php echo esc_attr( $atts['ajax'] ? 'true' : 'false' ); ?>"
			data-per-page="<?php echo esc_attr( (string) (int) $atts['limit'] ); ?>"
			data-layout="<?php echo esc_attr( $atts['layout'] ); ?>"
			data-columns="<?php echo esc_attr( (string) (int) $atts['columns'] ); ?>"
			data-past="<?php echo esc_attr( $atts['past'] ? 'true' : 'false' ); ?>"
			data-show-image="<?php echo esc_attr( $atts['show_image'] ? 'true' : 'false' ); ?>"
			data-show-date="<?php echo esc_attr( $atts['show_date'] ? 'true' : 'false' ); ?>"
			data-show-time="<?php echo esc_attr( $atts['show_time'] ? 'true' : 'false' ); ?>"
			data-show-venue="<?php echo esc_attr( $atts['show_venue'] ? 'true' : 'false' ); ?>"
			data-show-price="<?php echo esc_attr( $atts['show_price'] ? 'true' : 'false' ); ?>"
			data-show-excerpt="<?php echo esc_attr( $atts['show_excerpt'] ? 'true' : 'false' ); ?>"
		>
			<?php if ( $atts['show_filters'] ) : ?>
				<?php echo wp_kses( $this->render_filters( $atts, $instance_id ), ShortcodeOutput::get_allowlist() ); ?>
			<?php endif; ?>

			<div
				class="nte-grid nte-grid--cols-<?php echo esc_attr( (string) (int) $atts['columns'] ); ?> nte-grid--<?php echo esc_attr( $atts['layout'] ); ?>"
				id="<?php echo esc_attr( $instance_id . '-grid' ); ?>"
				aria-label="<?php esc_attr_e( 'Events', 'nettertech-events' ); ?>"
				aria-live="polite"
			>
				<?php if ( empty( $result['items'] ) ) : ?>
					<?php echo wp_kses( $this->render_empty(), ShortcodeOutput::get_allowlist() ); ?>
				<?php else : ?>
					<?php foreach ( $result['items'] as $occurrence ) : ?>
						<?php echo wp_kses( $this->render_card( $occurrence, $atts, $prefetched_tags, $prefetched_ticket_types, $prefetched_availability ), ShortcodeOutput::get_allowlist() ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<?php echo wp_kses( FrontendBranding::render_badge(), ShortcodeOutput::get_allowlist() ); ?>

			<?php if ( $atts['pagination'] && $result['total_pages'] > 1 ) : ?>
				<?php echo wp_kses( $this->render_pagination( $result, $atts, $instance_id ), ShortcodeOutput::get_allowlist() ); ?>
			<?php endif; ?>

			<div class="nte-loading-overlay" aria-hidden="true" role="status" aria-live="polite">
				<span class="nte-loading-spinner"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Loading events...', 'nettertech-events' ); ?></span>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render filter controls.
	 *
	 * @param array<string, mixed> $atts        Attributes.
	 * @param string               $instance_id Instance ID.
	 * @return string
	 */
	private function render_filters( array $atts, string $instance_id ): string {
		// Compute timeframe-specific search strings before template renders.
		if ( $atts['past'] ) {
			$placeholder_text = _x( 'Search past events…', 'search input placeholder for past events archive', 'nettertech-events' );
			$label_text       = _x( 'Search past events', 'screen-reader label for past events search', 'nettertech-events' );
		} else {
			$placeholder_text = _x( 'Search upcoming events…', 'search input placeholder for upcoming events list', 'nettertech-events' );
			$label_text       = _x( 'Search upcoming events', 'screen-reader label for upcoming events search', 'nettertech-events' );
		}

		/** This filter is documented in includes/Core/Hooks.php */
		$placeholder_text = apply_filters( 'nettertech_events_search_placeholder', $placeholder_text, (bool) $atts['past'], $atts );
		/** This filter is documented in includes/Core/Hooks.php */
		$label_text = apply_filters( 'nettertech_events_search_label', $label_text, (bool) $atts['past'], $atts );

		ob_start();
		?>
		<div class="nte-filters" data-target="#<?php echo esc_attr( $instance_id . '-grid' ); ?>">
			<form class="nte-filters__form" role="search" aria-label="<?php esc_attr_e( 'Filter events', 'nettertech-events' ); ?>">
				<?php if ( $atts['show_search'] ) : ?>
					<div class="nte-filters__field nte-filters__field--search">
						<label for="<?php echo esc_attr( $instance_id . '-search' ); ?>" class="screen-reader-text">
							<?php echo esc_html( $label_text ); ?>
						</label>
						<input
							type="search"
							id="<?php echo esc_attr( $instance_id . '-search' ); ?>"
							name="search"
							class="nte-filters__input"
							placeholder="<?php echo esc_attr( $placeholder_text ); ?>"
						/>
						<span class="nte-filters__search-icon" aria-hidden="true">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<circle cx="11" cy="11" r="8"/>
								<path d="m21 21-4.3-4.3"/>
							</svg>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( $atts['show_category'] ) : ?>
					<div class="nte-filters__field nte-filters__field--category">
						<label for="<?php echo esc_attr( $instance_id . '-category' ); ?>" class="screen-reader-text">
							<?php esc_html_e( 'Filter by category', 'nettertech-events' ); ?>
						</label>
						<select
							id="<?php echo esc_attr( $instance_id . '-category' ); ?>"
							name="category[]"
							class="nte-filters__select nte-multiselect-source"
							data-nte-multiselect-label="<?php esc_attr_e( 'Category', 'nettertech-events' ); ?>"
							multiple
						>
							<?php echo wp_kses( $this->render_category_options( $atts ), ShortcodeOutput::get_allowlist() ); ?>
						</select>
					</div>
				<?php endif; ?>

				<?php if ( $atts['show_tag'] ) : ?>
					<div class="nte-filters__field nte-filters__field--tag">
						<label for="<?php echo esc_attr( $instance_id . '-tag' ); ?>" class="screen-reader-text">
							<?php esc_html_e( 'Filter by tag', 'nettertech-events' ); ?>
						</label>
						<select
							id="<?php echo esc_attr( $instance_id . '-tag' ); ?>"
							name="tag[]"
							class="nte-filters__select nte-multiselect-source"
							data-nte-multiselect-label="<?php esc_attr_e( 'Tag', 'nettertech-events' ); ?>"
							multiple
						>
							<?php echo wp_kses( $this->render_tag_options( $atts ), ShortcodeOutput::get_allowlist() ); ?>
						</select>
					</div>
				<?php endif; ?>

				<?php if ( $atts['show_date_range'] ) : ?>
					<div class="nte-filters__field nte-filters__field--date-from">
						<label for="<?php echo esc_attr( $instance_id . '-date-from' ); ?>" class="screen-reader-text">
							<?php esc_html_e( 'From date', 'nettertech-events' ); ?>
						</label>
						<input
							type="date"
							id="<?php echo esc_attr( $instance_id . '-date-from' ); ?>"
							name="date_from"
							class="nte-filters__input nte-filters__input--date"
							value="<?php echo esc_attr( (string) $atts['date_from'] ); ?>"
						/>
					</div>
					<div class="nte-filters__field nte-filters__field--date-to">
						<label for="<?php echo esc_attr( $instance_id . '-date-to' ); ?>" class="screen-reader-text">
							<?php esc_html_e( 'To date', 'nettertech-events' ); ?>
						</label>
						<input
							type="date"
							id="<?php echo esc_attr( $instance_id . '-date-to' ); ?>"
							name="date_to"
							class="nte-filters__input nte-filters__input--date"
							value="<?php echo esc_attr( (string) $atts['date_to'] ); ?>"
						/>
					</div>
				<?php endif; ?>

				<button type="button" class="nte-filters__reset" aria-label="<?php esc_attr_e( 'Reset filters', 'nettertech-events' ); ?>">
					<?php esc_html_e( 'Reset', 'nettertech-events' ); ?>
				</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render category dropdown options.
	 *
	 * Returns categories limited to the active timeframe (upcoming/past), unioned
	 * with any categories currently selected via shortcode pre-filter or URL state
	 * — so a user who selected a category and then toggled timeframes does not
	 * silently lose the selection. Selected options are marked with `selected`.
	 *
	 * The base term list is cached per-timeframe under the `nettertech_events`
	 * cache group; the selected-term overlay is applied per-request and is not
	 * cached (it varies by URL state).
	 *
	 * @since 0.8.0
	 * @since 1.1.0 Added $atts parameter; timeframe-limited results; selected-term union (NTE-068).
	 *
	 * @param array<string, mixed> $atts Shortcode attributes including 'past' and 'category'.
	 * @return string
	 */
	private function render_category_options( array $atts ): string {
		$timeframe    = $atts['past'] ? 'past' : 'upcoming';
		$categories   = $this->get_categories_for_timeframe( $timeframe );
		$selected_ids = $this->normalize_selected_category_ids( $atts );

		// Union: append any selected category not already in the timeframe set.
		if ( ! empty( $selected_ids ) ) {
			$present_ids = array();
			foreach ( $categories as $cat ) {
				$present_ids[] = (int) $cat->id;
			}
			$missing_ids = array_diff( $selected_ids, $present_ids );
			foreach ( $missing_ids as $missing_id ) {
				$extra = $this->category_repo->find( (int) $missing_id );
				if ( $extra ) {
					$categories[] = $extra;
				}
			}
		}

		if ( empty( $categories ) ) {
			return '';
		}

		$selected_set = array_flip( $selected_ids );

		$output = '';
		foreach ( $categories as $cat ) {
			$id      = (int) $cat->id;
			$sel     = isset( $selected_set[ $id ] ) ? ' selected="selected"' : '';
			$output .= sprintf(
				'<option value="%1$d"%2$s>%3$s</option>',
				$id,
				$sel,
				esc_html( $cat->name )
			);
		}

		return $output;
	}

	/**
	 * Render tag dropdown options.
	 *
	 * Returns tags limited to the active timeframe (upcoming/past), unioned with
	 * any tags currently selected via shortcode pre-filter or URL state — so a
	 * user who selected a tag and then toggled timeframes does not silently
	 * lose the selection. Selected options are marked with `selected`.
	 *
	 * The base term list is cached per-timeframe under the `nettertech_events`
	 * cache group; the selected-term overlay is applied per-request and is not
	 * cached (it varies by URL state).
	 *
	 * @since 0.8.0
	 * @since 1.1.0 Added $atts parameter; timeframe-limited results; selected-term union (NTE-068).
	 *
	 * @param array<string, mixed> $atts Shortcode attributes including 'past' and 'tag'.
	 * @return string
	 */
	private function render_tag_options( array $atts ): string {
		$timeframe      = $atts['past'] ? 'past' : 'upcoming';
		$tags           = $this->get_tags_for_timeframe( $timeframe );
		$selected_slugs = $this->normalize_selected_tag_slugs( $atts );

		// Union: append any selected tag not already in the timeframe set.
		if ( ! empty( $selected_slugs ) ) {
			$present_slugs = array();
			foreach ( $tags as $tag ) {
				$present_slugs[] = (string) $tag->slug;
			}
			$missing_slugs = array_diff( $selected_slugs, $present_slugs );
			foreach ( $missing_slugs as $missing_slug ) {
				$extra = $this->tag_repo->find_by_slug( (string) $missing_slug );
				if ( $extra ) {
					$tags[] = $extra;
				}
			}
		}

		if ( empty( $tags ) ) {
			return '';
		}

		$selected_set = array_flip( $selected_slugs );

		$output = '';
		foreach ( $tags as $tag ) {
			$slug    = (string) $tag->slug;
			$sel     = isset( $selected_set[ $slug ] ) ? ' selected="selected"' : '';
			$output .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $slug ),
				$sel,
				esc_html( $tag->name )
			);
		}

		return $output;
	}

	/**
	 * Fetch the category list for a timeframe, applying a per-timeframe cache.
	 *
	 * Cache is per-timeframe so selecting a different timeframe yields a
	 * different cached entry; `wp_cache_flush_group('nettertech_events')`
	 * (called from CacheManager save-hooks) clears all timeframe variants.
	 *
	 * @since 1.1.0
	 *
	 * @param string $timeframe 'upcoming' or 'past'.
	 * @return array<\NetterTechEvents\Models\Category>
	 */
	private function get_categories_for_timeframe( string $timeframe ): array {
		$cache_key = 'event_categories_dropdown_' . $timeframe;
		$cached    = wp_cache_get( $cache_key, 'nettertech_events' );
		if ( false !== $cached ) {
			return $cached;
		}

		$categories = $this->category_repo->get_all(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			),
			$timeframe
		);

		wp_cache_set( $cache_key, $categories, 'nettertech_events', HOUR_IN_SECONDS );
		return $categories;
	}

	/**
	 * Fetch the tag list for a timeframe, applying a per-timeframe cache.
	 *
	 * @since 1.1.0
	 *
	 * @param string $timeframe 'upcoming' or 'past'.
	 * @return array<\NetterTechEvents\Models\Tag>
	 */
	private function get_tags_for_timeframe( string $timeframe ): array {
		$cache_key = 'event_tags_dropdown_' . $timeframe;
		$cached    = wp_cache_get( $cache_key, 'nettertech_events' );
		if ( false !== $cached ) {
			return $cached;
		}

		$tags = $this->tag_repo->get_all(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			),
			$timeframe
		);

		wp_cache_set( $cache_key, $tags, 'nettertech_events', HOUR_IN_SECONDS );
		return $tags;
	}

	/**
	 * Normalize currently-selected category IDs from shortcode atts + URL state.
	 *
	 * Read-only filter state on GET — no nonce required (no state change).
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return array<int> Unique, positive integer IDs.
	 */
	private function normalize_selected_category_ids( array $atts ): array {
		$sources = array();

		if ( ! empty( $atts['category'] ) ) {
			$raw     = is_array( $atts['category'] ) ? $atts['category'] : explode( ',', (string) $atts['category'] );
			$sources = array_merge( $sources, $raw );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state on GET request; no state change.
		if ( isset( $_GET['category'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
			$raw_url = wp_unslash( $_GET['category'] );
			if ( is_array( $raw_url ) ) {
				$sources = array_merge( $sources, $raw_url );
			} elseif ( is_scalar( $raw_url ) ) {
				$sources = array_merge( $sources, explode( ',', (string) $raw_url ) );
			}
		}

		$ids = array_map( 'absint', $sources );
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		return $ids;
	}

	/**
	 * Normalize currently-selected tag slugs from shortcode atts + URL state.
	 *
	 * Numeric values are resolved to slugs via the tag repository so the
	 * markup-side `value=` comparison (slug-based, per current convention)
	 * works regardless of input form.
	 *
	 * Read-only filter state on GET — no nonce required (no state change).
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return array<string> Unique, non-empty slugs.
	 */
	private function normalize_selected_tag_slugs( array $atts ): array {
		$sources = array();

		if ( ! empty( $atts['tag'] ) ) {
			$raw     = is_array( $atts['tag'] ) ? $atts['tag'] : explode( ',', (string) $atts['tag'] );
			$sources = array_merge( $sources, $raw );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state on GET request; no state change.
		if ( isset( $_GET['tag'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
			$raw_url = wp_unslash( $_GET['tag'] );
			if ( is_array( $raw_url ) ) {
				$sources = array_merge( $sources, $raw_url );
			} elseif ( is_scalar( $raw_url ) ) {
				$sources = array_merge( $sources, explode( ',', (string) $raw_url ) );
			}
		}

		$slugs = array();
		foreach ( $sources as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			if ( ctype_digit( $value ) ) {
				$tag = $this->tag_repo->find( (int) $value );
				if ( $tag && ! empty( $tag->slug ) ) {
					$slugs[] = (string) $tag->slug;
				}
				continue;
			}
			$slug = sanitize_title( $value );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Render pagination.
	 *
	 * @param array<string, mixed> $result      Query result.
	 * @param array<string, mixed> $atts        Attributes (reserved for future use).
	 * @param string               $instance_id Instance ID (reserved for future use).
	 * @return string
	 */
	private function render_pagination( array $result, array $atts, string $instance_id ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		return $this->templates->get_template_part(
			'pagination',
			array(
				'current_page' => 1,
				'total_pages'  => $result['total_pages'],
				'instance_id'  => $instance_id,
				'show_info'    => true,
			)
		);
	}

	/**
	 * Render empty state.
	 *
	 * Delegates to the empty-state template part for consistent rendering
	 * and theme override capability.
	 *
	 * @return string
	 */
	private function render_empty(): string {
		$message = apply_filters(
			'nettertech_events_list_empty_message',
			__( 'No events found.', 'nettertech-events' )
		);

		return $this->templates->get_template_part(
			'empty-state',
			array(
				'message' => $message,
				'context' => 'list',
			)
		);
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
				'show_excerpt'            => (bool) $atts['show_excerpt'],
				'show_price'              => (bool) $atts['show_price'],
				'image_ratio'             => ImageUtility::sanitize_image_ratio( $atts['image_ratio'] ?? '' ),
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
