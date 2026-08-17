<?php
/**
 * Occurrence availability presenter.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\TicketType;

/**
 * Answers "should this occurrence display as sold out?" for listing surfaces
 * (cards, grids, carousels, series pages).
 *
 * Sold out is a per-type verdict from CapacityService::get_capacity_summary(),
 * whose effective availability comes from get_available_count() — the same
 * arithmetic the checkout uses, so the badge and the till cannot disagree
 * about pending holds, buffer stock, seating overrides, or the shared house.
 * The denormalized stock_status column must never drive this: it does not
 * know about the house, so a tier with an untouched allotment in a full room
 * would read as available.
 *
 * An occurrence with no on-sale ticket types is never sold out — that covers
 * both unticketed events (NTE-009 bug class: absence of tickets must not read
 * as exhaustion) and events whose sale windows are all closed ("sales closed"
 * is a different state than "sold out").
 *
 * Base computes verdicts only when an extension opts in via
 * Hooks::CARDS_NEED_AVAILABILITY and renders no availability UI of its own;
 * visible labels are an extension concern (e.g. Pro's availability badges).
 *
 * @since 1.4.0
 */
final class OccurrenceAvailabilityPresenter {

	/**
	 * Constructor.
	 *
	 * @param CapacityServiceInterface      $capacity_service Capacity service.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 */
	public function __construct(
		private readonly CapacityServiceInterface $capacity_service,
		private readonly TicketTypeRepositoryInterface $ticket_type_repo
	) {
	}

	/**
	 * Whether a set of on-sale ticket types is collectively sold out.
	 *
	 * @param array<TicketType> $on_sale_ticket_types The occurrence's on-sale ticket types.
	 * @return bool True when every saleable type has zero effective availability.
	 */
	public function is_sold_out( array $on_sale_ticket_types ): bool {
		$has_saleable_type = false;

		foreach ( $on_sale_ticket_types as $ticket_type ) {
			if ( ! $ticket_type instanceof TicketType || null === $ticket_type->id ) {
				continue;
			}

			$has_saleable_type = true;
			$summary           = $this->capacity_service->get_capacity_summary( (int) $ticket_type->id );

			// One buyable type (including any unlimited type) means not sold out.
			if ( empty( $summary['is_sold_out'] ) ) {
				return false;
			}
		}

		return $has_saleable_type;
	}

	/**
	 * Whether an occurrence is sold out, resolving its on-sale ticket types.
	 *
	 * Convenience path for direct template callers without a prefetched map;
	 * listing controllers should prefetch via get_on_sale_for_occurrences()
	 * and call is_sold_out() instead to avoid per-card queries.
	 *
	 * @api Extension entry point (e.g. Pro availability labels); no base
	 *      caller, so static analysis cannot see its consumers.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool True when every saleable type has zero effective availability.
	 */
	public function is_occurrence_sold_out( int $occurrence_id ): bool {
		if ( $occurrence_id <= 0 ) {
			return false;
		}

		return $this->is_sold_out( $this->ticket_type_repo->get_on_sale_for_occurrence( $occurrence_id ) );
	}

	/**
	 * Price range of a set of on-sale ticket types, with the display label.
	 *
	 * The single source of the card price string (NTE-215): the PHP card
	 * template, the single-event page, and the REST occurrence payload (which
	 * the AJAX-paged grid renders from) all call this, so the same occurrence
	 * can never show two different prices. Formatting goes through wc_price()
	 * when WooCommerce is active and falls back to number_format_i18n() so a
	 * ticketless install cannot fatal (NTE-193 class).
	 *
	 * @since 1.4.4
	 *
	 * @param array<object> $on_sale_ticket_types The occurrence's on-sale ticket types (TicketType or any object exposing `price`).
	 * @return array{min: float|null, max: float|null, is_free: bool, label: string}
	 *         Empty label (and null bounds) when nothing is on sale.
	 */
	public static function price_range( array $on_sale_ticket_types ): array {
		$prices = array();
		foreach ( $on_sale_ticket_types as $ticket_type ) {
			// Duck-typed on `price` (not instanceof TicketType): callers may pass
			// lightweight rows, and the label needs no capacity lookup.
			if ( is_object( $ticket_type ) && isset( $ticket_type->price ) && is_numeric( $ticket_type->price ) ) {
				$prices[] = (float) $ticket_type->price;
			}
		}

		if ( empty( $prices ) ) {
			return array(
				'min'     => null,
				'max'     => null,
				'is_free' => false,
				'label'   => '',
			);
		}

		$min = min( $prices );
		$max = max( $prices );

		if ( $max <= 0 ) {
			$label = __( 'Free', 'nettertech-events' );
		} elseif ( $min <= 0 ) {
			/* translators: %s: maximum ticket price. */
			$label = sprintf( __( 'Free – %s', 'nettertech-events' ), self::format_price( $max ) );
		} elseif ( abs( $min - $max ) < 0.01 ) {
			$label = self::format_price( $min );
		} else {
			/* translators: 1: minimum ticket price, 2: maximum ticket price. */
			$label = sprintf( __( '%1$s – %2$s', 'nettertech-events' ), self::format_price( $min ), self::format_price( $max ) );
		}

		return array(
			'min'     => $min,
			'max'     => $max,
			'is_free' => $max <= 0,
			'label'   => $label,
		);
	}

	/**
	 * Price range for an occurrence, resolving its on-sale ticket types.
	 *
	 * Convenience path for direct callers without a prefetched map; listing
	 * controllers should prefetch via get_on_sale_for_occurrences() and call
	 * price_range() to avoid per-card queries.
	 *
	 * @api Extension entry point (theme/plugin card overrides); no base
	 *      caller, so static analysis cannot see its consumers.
	 *
	 * @since 1.4.4
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array{min: float|null, max: float|null, is_free: bool, label: string}
	 */
	public function occurrence_price_range( int $occurrence_id ): array {
		if ( $occurrence_id <= 0 ) {
			return self::price_range( array() );
		}

		return self::price_range( $this->ticket_type_repo->get_on_sale_for_occurrence( $occurrence_id ) );
	}

	/**
	 * Format a price as plain text in the store currency.
	 *
	 * @param float $amount Amount.
	 * @return string Plain-text price (no markup).
	 */
	private static function format_price( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( strip_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- wc_price() markup only; no script/style content, and unit tests run without WP loaded.
		}

		return number_format_i18n( $amount, 2 );
	}
}
