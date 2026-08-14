<?php
/**
 * No-op repository stubs for the nettertech_events_container() fallback in tests.
 *
 * These classes implement the repository interfaces with safe empty/null
 * returns so that templates can call container-resolved services without
 * failing when a specific test has not registered $nettertech_events_test_container.
 *
 * @package NetterTechEvents\Tests\Stubs
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Stubs;

use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\Tag;
use NetterTechEvents\Models\TicketType;

/**
 * No-op TicketTypeRepository stub.
 *
 * @since 1.0.0
 * @internal
 */
class StubTicketTypeRepository implements TicketTypeRepositoryInterface {

	public function find( int $id ): ?TicketType {
		return null;
	}

	public function for_occurrence( int $occurrence_id, array $args = array() ): array {
		return array();
	}

	public function for_multiple_occurrences( array $occurrence_ids, ?string $status = null ): array {
		return array();
	}

	public function get_active_for_occurrence( int $occurrence_id ): array {
		return array();
	}

	public function get_on_sale_for_occurrence( int $occurrence_id ): array {
		return array();
	}

	public function get_on_sale_for_occurrences( array $occurrence_ids ): array {
		return array();
	}

	public function get_on_sale_for_event( int $event_id, ?\DateTimeZone $zone = null ): array {
		return array();
	}

	public function occurrence_is_free( int $occurrence_id ): bool {
		return false;
	}

	public function find_by_product( int $product_id ): ?TicketType {
		return null;
	}

	public function find_by_variation( int $variation_id ): ?TicketType {
		return null;
	}

	public function save( TicketType $ticket_type ): TicketType {
		return $ticket_type;
	}

	public function delete( int $id ): bool {
		return false;
	}

	public function delete_for_occurrence( int $occurrence_id ): int {
		return 0;
	}

	public function get_sold_count( int $ticket_type_id ): int {
		return 0;
	}

	public function get_available_count( int $ticket_type_id ): ?int {
		return null;
	}

	public function has_availability( int $ticket_type_id, int $quantity = 1 ): bool {
		return false;
	}

	public function is_sold_out( int $ticket_type_id ): bool {
		return false;
	}

	public function increment_sold_count( int $ticket_type_id, int $quantity = 1 ): bool {
		return false;
	}

	public function decrement_sold_count( int $ticket_type_id, int $quantity = 1 ): bool {
		return false;
	}

	public function recalculate_sold_count( int $ticket_type_id ): bool {
		return false;
	}

	public function for_event( int $event_id, array $args = array() ): array {
		return array();
	}

	public function event_capacity_for_events( array $event_ids ): array {
		return array();
	}

	public function get_templates( int $event_id, array $args = array() ): array {
		return array();
	}

	public function create_from_template( TicketType $template, int $occurrence_id ): TicketType {
		return $template;
	}

	public function get_fixed_capacity_sum( int $occurrence_id ): int {
		return 0;
	}

	public function get_shared_sold_count( int $occurrence_id ): int {
		return 0;
	}

	public function get_shared_for_occurrence( int $occurrence_id ): array {
		return array();
	}

	public function has_unlimited_fixed_tickets( int $occurrence_id ): bool {
		return false;
	}

	public function get_capacity_type( int $ticket_type_id ): ?string {
		return null;
	}
}

/**
 * No-op TagRepository stub.
 *
 * @since 1.0.0
 * @internal
 */
class StubTagRepository implements TagRepositoryInterface {

	public function find( int $id ): ?Tag {
		return null;
	}

	public function find_by_slug( string $slug ): ?Tag {
		return null;
	}

	public function save( Tag $tag ): Tag {
		return $tag;
	}

	public function delete( int $id ): bool {
		return false;
	}

	public function get_all( array $args = array(), ?string $timeframe = null ): array {
		return array();
	}

	public function find_by_event( int $event_id ): array {
		return array();
	}

	public function find_by_event_ids( array $event_ids ): array {
		return array();
	}

	public function search( string $query, int $limit = 10 ): array {
		return array();
	}

	public function attach_to_event( int $event_id, int $tag_id ): bool {
		return false;
	}

	public function detach_from_event( int $event_id, int $tag_id ): bool {
		return false;
	}

	public function sync_event_tags( int $event_id, array $tag_ids ): bool {
		return false;
	}

	public function find_or_create( string $name ): Tag {
		$tag       = new Tag();
		$tag->name = $name;
		return $tag;
	}
}
