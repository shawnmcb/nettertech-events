<?php
/**
 * Template part: Event Filters
 *
 * Displays filter controls (search, category, tag, date range) for event grids.
 *
 * This template can be overridden by copying it to:
 * yourtheme/nettertech-events/parts/event-filters.php
 *
 * Available variables (read via $context):
 *
 * @var \NetterTechEvents\TemplateLoader\TemplateContext $context Template context.
 * @var string $context->instance_id     Unique ID for this filter instance.
 * @var string $context->target_id       Grid element ID to update when filtering.
 * @var bool   $context->show_search     Whether to show search input (default: true).
 * @var bool   $context->show_category   Whether to show category dropdown (default: true).
 * @var bool   $context->show_tag        Whether to show tag dropdown (default: true).
 * @var bool   $context->show_date_range Whether to show date range inputs (default: true).
 * @var array  $context->categories      Array of category objects (optional, auto-loaded if not provided).
 * @var array  $context->tags            Array of tag objects (optional, auto-loaded if not provided).
 * @var string $context->date_from       Pre-set start date value (Y-m-d).
 * @var string $context->date_to         Pre-set end date value (Y-m-d).
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$nettertech_events_instance_id = $context->get( 'instance_id', null );

// Ensure required variables are set.
if ( ! $nettertech_events_instance_id ) {
	return;
}

$nettertech_events_target_id = $context->get( 'target_id', $nettertech_events_instance_id . '-grid' );

// Load categories if not provided.
$nettertech_events_categories = $context->get( 'categories', null );
if ( null === $nettertech_events_categories ) {
	$nettertech_events_categories = ( new \NetterTechEvents\Repositories\CategoryRepository() )->get_all(
		array(
			'orderby' => 'name',
			'order'   => 'ASC',
		)
	);
}

// Load tags if not provided.
$nettertech_events_tags = $context->get( 'tags', null );
if ( null === $nettertech_events_tags ) {
	global $wpdb;
	$nettertech_events_tags = ( new \NetterTechEvents\Repositories\TagRepository( $wpdb ) )->get_all(
		array(
			'orderby' => 'name',
			'order'   => 'ASC',
		)
	);
}
?>
<div class="nte-filters" data-target="#<?php echo esc_attr( $nettertech_events_target_id ); ?>">
	<form class="nte-filters__form" role="search" aria-label="<?php esc_attr_e( 'Filter events', 'nettertech-events' ); ?>">
		<?php if ( $context->get( 'show_search', true ) ) : ?>
			<div class="nte-filters__field nte-filters__field--search">
				<label for="<?php echo esc_attr( $nettertech_events_instance_id . '-search' ); ?>" class="screen-reader-text">
					<?php esc_html_e( 'Search events', 'nettertech-events' ); ?>
				</label>
				<input
					type="search"
					id="<?php echo esc_attr( $nettertech_events_instance_id . '-search' ); ?>"
					name="search"
					class="nte-filters__input"
					placeholder="<?php esc_attr_e( 'Search events...', 'nettertech-events' ); ?>"
				/>
				<span class="nte-filters__search-icon" aria-hidden="true">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="11" cy="11" r="8"/>
						<path d="m21 21-4.3-4.3"/>
					</svg>
				</span>
			</div>
		<?php endif; ?>

		<?php if ( $context->get( 'show_category', true ) && ! empty( $nettertech_events_categories ) ) : ?>
			<div class="nte-filters__field nte-filters__field--category">
				<label for="<?php echo esc_attr( $nettertech_events_instance_id . '-category' ); ?>" class="screen-reader-text">
					<?php esc_html_e( 'Filter by category', 'nettertech-events' ); ?>
				</label>
				<select
					id="<?php echo esc_attr( $nettertech_events_instance_id . '-category' ); ?>"
					name="category"
					class="nte-filters__select"
				>
					<option value=""><?php esc_html_e( 'All Categories', 'nettertech-events' ); ?></option>
					<?php foreach ( $nettertech_events_categories as $nettertech_events_category_item ) : ?>
						<option value="<?php echo esc_attr( (string) $nettertech_events_category_item->id ); ?>">
							<?php echo esc_html( $nettertech_events_category_item->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<?php if ( $context->get( 'show_tag', true ) && ! empty( $nettertech_events_tags ) ) : ?>
			<div class="nte-filters__field nte-filters__field--tag">
				<label for="<?php echo esc_attr( $nettertech_events_instance_id . '-tag' ); ?>" class="screen-reader-text">
					<?php esc_html_e( 'Filter by tag', 'nettertech-events' ); ?>
				</label>
				<select
					id="<?php echo esc_attr( $nettertech_events_instance_id . '-tag' ); ?>"
					name="tag"
					class="nte-filters__select"
				>
					<option value=""><?php esc_html_e( 'All Tags', 'nettertech-events' ); ?></option>
					<?php foreach ( $nettertech_events_tags as $nettertech_events_tag_item ) : ?>
						<option value="<?php echo esc_attr( $nettertech_events_tag_item->slug ); ?>">
							<?php echo esc_html( $nettertech_events_tag_item->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<?php if ( $context->get( 'show_date_range', true ) ) : ?>
			<div class="nte-filters__field nte-filters__field--date-from">
				<label for="<?php echo esc_attr( $nettertech_events_instance_id . '-date-from' ); ?>" class="screen-reader-text">
					<?php esc_html_e( 'From date', 'nettertech-events' ); ?>
				</label>
				<input
					type="date"
					id="<?php echo esc_attr( $nettertech_events_instance_id . '-date-from' ); ?>"
					name="date_from"
					class="nte-filters__input nte-filters__input--date"
					value="<?php echo esc_attr( $context->get( 'date_from', '' ) ); ?>"
				/>
			</div>
			<div class="nte-filters__field nte-filters__field--date-to">
				<label for="<?php echo esc_attr( $nettertech_events_instance_id . '-date-to' ); ?>" class="screen-reader-text">
					<?php esc_html_e( 'To date', 'nettertech-events' ); ?>
				</label>
				<input
					type="date"
					id="<?php echo esc_attr( $nettertech_events_instance_id . '-date-to' ); ?>"
					name="date_to"
					class="nte-filters__input nte-filters__input--date"
					value="<?php echo esc_attr( $context->get( 'date_to', '' ) ); ?>"
				/>
			</div>
		<?php endif; ?>

		<button type="button" class="nte-filters__reset" aria-label="<?php esc_attr_e( 'Reset filters', 'nettertech-events' ); ?>">
			<?php esc_html_e( 'Reset', 'nettertech-events' ); ?>
		</button>
	</form>
</div>
