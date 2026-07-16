<?php
/**
 * Contract test: every model property backed by a DB column must appear in LIST_COLUMNS.
 *
 * Catches the class of bug described in NTE-017, where a column was added to a
 * model's from_row() hydration but omitted from the repository's LIST_COLUMNS
 * constant, causing every list-query result to silently return null for that property.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use ReflectionClass;
use ReflectionProperty;

/**
 * Asserts that every DB-backed public property on a model class has a
 * corresponding column name in the repository's LIST_COLUMNS constant.
 *
 * @group structural
 */
class ListColumnsContractTest extends \NetterTechEventsTestCase {

	/**
	 * Properties intentionally excluded from each repository's column list.
	 *
	 * These are properties that exist on the model but are NOT in the LIST_COLUMNS
	 * SELECT list — either because they are LONGTEXT/TEXT columns loaded only via
	 * SELECT *, because they are in-memory-only fields never persisted to the table,
	 * or because the list query deliberately omits them for performance.
	 *
	 * When a new property is added to a model and intentionally excluded from
	 * LIST_COLUMNS, add it here with a brief reason. If you add a property here
	 * but forget to add it to LIST_COLUMNS when it IS needed, the bug class from
	 * NTE-017 recurs — so treat this list as a documented contract, not a workaround.
	 *
	 * @var array<string, array<string>> Key = repository FQCN, value = excluded property names.
	 */
	private const EXCLUDED_PROPERTIES = array(
		// EventQueryRepository excludes TEXT/LONGTEXT and detail-only columns
		// to keep list/pagination queries lean. Full record loaded via SELECT *.
		\NetterTechEvents\Repositories\EventQueryRepository::class    => array(
			'description',                // LONGTEXT — full event body, not needed in lists.
			'excerpt',                    // TEXT — loaded on detail/card views only.
			'venue_address',              // Detail-only field.
			'layout_config',              // LONGTEXT JSON — page layout config, detail only.
			'notification_emails',        // Detail-only field.
			'custom_fields',              // LONGTEXT JSON — integration metadata.
			'is_virtual',                 // Detail-only field.
			'virtual_url',                // Detail-only field.
			'collect_individual_attendees', // Detail-only field.
			'qr_logo_mode',               // Detail-only field.
			'qr_logo_attachment_id',      // Detail-only field.
			'space_id',                   // Detail-only field.
			'image_vertical_anchor',      // Detail-only field; single-event-page crop anchor (NTE-119), not rendered in lists/cards.
			'category_ids',               // Not a DB column; populated during duplication only.
			'tag_ids',                    // Not a DB column; populated during duplication only.
		),
		// OccurrenceQueryRepository excludes description_override (LONGTEXT).
		\NetterTechEvents\Repositories\OccurrenceQueryRepository::class => array(
			'description_override',       // LONGTEXT — loaded on occurrence detail view only.
			'venue_address_override',     // TEXT — loaded on occurrence detail view only.
		),
		// OccurrenceFilterRepository uses JOIN_LIST_COLUMNS; same exclusion applies.
		\NetterTechEvents\Repositories\OccurrenceFilterRepository::class => array(
			'description_override',       // LONGTEXT — loaded on occurrence detail view only.
			'venue_address_override',     // TEXT — loaded on occurrence detail view only.
		),
		// SpaceRepository excludes TEXT/LONGTEXT columns from its list queries.
		\NetterTechEvents\Repositories\SpaceRepository::class          => array(
			'description',                // TEXT — full space description.
			'amenities',                  // LONGTEXT JSON — feature list.
			'gallery_image_ids',          // LONGTEXT JSON — gallery attachment IDs.
			'accessibility_features',     // TEXT JSON — accessibility entries; loaded on detail view only.
		),
	);

	/**
	 * Map of repository class => model class and column constant name.
	 *
	 * Only repositories that define a LIST_COLUMNS-shaped constant are included.
	 * Grep confirmed these four contain the pattern; no others in includes/Repositories/.
	 *
	 * @return array<string, array{repository: class-string, constant: string, model: class-string}>
	 */
	public static function repository_model_map(): array {
		return array(
			'EventQueryRepository / LIST_COLUMNS'            => array(
				'repository' => \NetterTechEvents\Repositories\EventQueryRepository::class,
				'constant'   => 'LIST_COLUMNS',
				'model'      => \NetterTechEvents\Models\Event::class,
			),
			'OccurrenceQueryRepository / LIST_COLUMNS'       => array(
				'repository' => \NetterTechEvents\Repositories\OccurrenceQueryRepository::class,
				'constant'   => 'LIST_COLUMNS',
				'model'      => \NetterTechEvents\Models\Occurrence::class,
			),
			'OccurrenceFilterRepository / JOIN_LIST_COLUMNS' => array(
				'repository' => \NetterTechEvents\Repositories\OccurrenceFilterRepository::class,
				'constant'   => 'JOIN_LIST_COLUMNS',
				'model'      => \NetterTechEvents\Models\Occurrence::class,
			),
			'SpaceRepository / LIST_COLUMNS'                 => array(
				'repository' => \NetterTechEvents\Repositories\SpaceRepository::class,
				'constant'   => 'LIST_COLUMNS',
				'model'      => \NetterTechEvents\Models\Space::class,
			),
		);
	}

	/**
	 * Parse a LIST_COLUMNS string into a set of bare column names.
	 *
	 * Handles:
	 *  - Comma-separated column lists: 'id, event_id, start_datetime'
	 *  - Table-prefixed columns:       'o.id, o.event_id'
	 *  - SQL aliases:                  'o.id AS occ_id' — keeps alias as the column name
	 *
	 * @param string $columns Raw LIST_COLUMNS constant value.
	 * @return array<string> Bare column names (lowercase, no table prefix).
	 */
	private function parse_columns( string $columns ): array {
		$parts = array_map( 'trim', explode( ',', $columns ) );
		$names = array();

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			// Strip SQL alias: keep the bare column name (before AS), not the alias.
			if ( stripos( $part, ' AS ' ) !== false ) {
				$tokens = preg_split( '/\s+AS\s+/i', $part );
				$name   = isset( $tokens[0] ) ? trim( $tokens[0] ) : $part;
			} else {
				$name = $part;
			}

			// Strip table prefix (e.g. 'o.id' → 'id').
			if ( strpos( $name, '.' ) !== false ) {
				$name = (string) substr( $name, (int) strpos( $name, '.' ) + 1 );
			}

			$names[] = strtolower( trim( $name ) );
		}

		return $names;
	}

	/**
	 * Collect public non-static property names from a model class.
	 *
	 * @param class-string $model_class Fully-qualified model class name.
	 * @return array<string> All public non-static property names.
	 */
	private function get_public_properties( string $model_class ): array {
		$reflection = new ReflectionClass( $model_class );
		$properties = $reflection->getProperties( ReflectionProperty::IS_PUBLIC );
		$names      = array();

		foreach ( $properties as $property ) {
			if ( $property->isStatic() ) {
				continue;
			}
			$names[] = $property->getName();
		}

		return $names;
	}

	/**
	 * Read a private/protected class constant via reflection.
	 *
	 * @param class-string $class_name    Fully-qualified class name.
	 * @param string       $constant_name Constant name.
	 * @return string Constant value.
	 */
	private function get_constant_value( string $class_name, string $constant_name ): string {
		$reflection = new ReflectionClass( $class_name );
		$constant   = $reflection->getReflectionConstant( $constant_name );

		$this->assertNotFalse(
			$constant,
			"Constant {$class_name}::{$constant_name} not found. " .
			'If it was renamed, update the map in repository_model_map().'
		);

		return (string) $constant->getValue();
	}

	/**
	 * Assert every DB-backed model property name appears in the repository's column list.
	 *
	 * @dataProvider repository_model_map
	 *
	 * @param class-string $repository Repository class.
	 * @param string       $constant   Name of the columns constant (LIST_COLUMNS or JOIN_LIST_COLUMNS).
	 * @param class-string $model      Model class whose properties must be covered.
	 * @return void
	 */
	public function test_list_columns_covers_all_model_properties(
		string $repository,
		string $constant,
		string $model
	): void {
		$raw_columns      = $this->get_constant_value( $repository, $constant );
		$column_set       = $this->parse_columns( $raw_columns );
		$all_properties   = $this->get_public_properties( $model );
		$excluded         = self::EXCLUDED_PROPERTIES[ $repository ] ?? array();
		$db_properties    = array_diff( $all_properties, $excluded );

		foreach ( $db_properties as $property ) {
			$this->assertContains(
				strtolower( $property ),
				$column_set,
				sprintf(
					"Property '%s::\$%s' is not covered by %s::%s.\n" .
					"Either add the column to the SELECT list, or add the property name to\n" .
					"ListColumnsContractTest::EXCLUDED_PROPERTIES[%s] with a reason.\n" .
					"This test catches the NTE-017 bug class: a column added to from_row() " .
					"without being added to the SELECT list causes every list query to return null.",
					$model,
					$property,
					$repository,
					$constant,
					$repository
				)
			);
		}
	}
}
