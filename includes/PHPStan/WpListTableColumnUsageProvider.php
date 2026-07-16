<?php
/**
 * WP_List_Table column-method usage provider for shipmonk/dead-code-detector.
 *
 * @package NetterTechEvents\PHPStan
 */

declare(strict_types=1);

namespace NetterTechEvents\PHPStan;

// NO ABSPATH guard here — this class is loaded by PHPStan's DI container
// (phpstan.neon services), never by WordPress. A guard makes the file exit(0)
// during PHPStan bootstrap, silently killing the entire analysis (the gate
// was dead 2026-04-05 → 2026-06-11 because of exactly that; see INV-M1 in
// .coherence-invariants.md).

use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks WP_List_Table `column_{key}` methods as used.
 *
 * WordPress core dispatches list-table cells dynamically:
 * `WP_List_Table::single_row_columns()` calls `column_{$column_name}()` by
 * constructed name, so these methods have no static call site anywhere.
 */
final class WpListTableColumnUsageProvider extends ReflectionBasedMemberUsageProvider {

	/**
	 * Mark column_* methods on WP_List_Table descendants as used.
	 *
	 * @param ReflectionMethod $method The method under consideration.
	 * @return VirtualUsageData|null Usage note, or null when not applicable.
	 */
	public function shouldMarkMethodAsUsed( ReflectionMethod $method ): ?VirtualUsageData {
		if ( ! str_starts_with( $method->getName(), 'column_' ) ) {
			return null;
		}

		$class = $method->getDeclaringClass();
		while ( $class ) {
			if ( 'WP_List_Table' === $class->getName() ) {
				return VirtualUsageData::withNote( 'Dispatched dynamically by WP_List_Table::single_row_columns().' );
			}
			$class = $class->getParentClass();
		}

		return null;
	}
}
