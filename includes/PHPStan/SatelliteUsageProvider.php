<?php
/**
 * Satellite-plugin usage provider for shipmonk/dead-code-detector.
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

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;

/**
 * Marks base-plugin methods consumed by satellite plugins as used.
 *
 * PHPStan analyzes only this plugin's `includes/`, so public API consumed
 * exclusively by the Pro / Seating / Rentals / Migrator satellites (e.g. the
 * AttendeeCheckInInterface check-in methods called by Pro's check-in module)
 * reads as dead to shipmonk/dead-code-detector.
 *
 * Resolution strategy: scan each satellite's `includes/` once per run,
 * collecting (a) the set of base-plugin FQCNs the satellites reference
 * (use-imports and inline `NetterTechEvents\…` references) and (b) the set
 * of method names the satellites call (`->method(` / `::method(`). When a
 * base class declaration is visited, credit each of its declared methods
 * whose name appears in (b) — but only when the class itself appears in (a).
 * Binding is satellite-wide rather than per-file because consumers often
 * call through properties typed in a parent class (e.g. Pro's
 * CheckInController inherits $attendee_checkin from CheckInBaseController).
 * A base class no satellite references is never credited, so this cannot
 * keep alive code that satellites don't import at all.
 * `possibleDescendant` is true so interface usages keep implementations live.
 */
final class SatelliteUsageProvider implements MemberUsageProvider {

	/**
	 * Satellite plugin directory names (siblings of this plugin).
	 *
	 * @var array<string>
	 */
	private const SATELLITE_DIRS = array(
		'nettertech-events-pro',
		'nettertech-events-seating',
		'nettertech-events-rentals',
		'nettertech-events-migrator',
	);

	/**
	 * Lazily built sets: FQCNs referenced and method names called by satellites.
	 *
	 * @var array{classes: array<string, true>, methods: array<string, true>}|null
	 */
	private ?array $satellite_refs = null;

	/**
	 * Emit satellite-driven usages when visiting a class declaration.
	 *
	 * @param Node  $node  The AST node being analyzed.
	 * @param Scope $scope The analysis scope.
	 * @return list<ClassMemberUsage>
	 */
	public function getUsages( Node $node, Scope $scope ): array {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser property name (third-party API).
		if ( ! $node instanceof ClassLike || null === $node->namespacedName ) {
			return array();
		}

		$class_name = $node->namespacedName->toString();
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$refs = $this->get_satellite_refs();

		if ( ! isset( $refs['classes'][ $class_name ] ) ) {
			return array();
		}

		$result = array();
		foreach ( $node->getMethods() as $method ) {
			$method_name = $method->name->toString();
			if ( ! isset( $refs['methods'][ $method_name ] ) ) {
				continue;
			}

			$result[] = new ClassMethodUsage(
				UsageOrigin::createVirtual(
					$this,
					VirtualUsageData::withNote( 'Called from a satellite plugin (not analyzed by PHPStan); see SatelliteUsageProvider.' )
				),
				new ClassMethodRef( $class_name, $method_name, true ),
			);
		}

		return $result;
	}

	/**
	 * Build (once) the referenced-FQCN and called-method sets from satellites.
	 *
	 * @return array{classes: array<string, true>, methods: array<string, true>}
	 */
	private function get_satellite_refs(): array {
		if ( null !== $this->satellite_refs ) {
			return $this->satellite_refs;
		}

		$this->satellite_refs = array(
			'classes' => array(),
			'methods' => array(),
		);

		$plugins_dir = dirname( __DIR__, 3 );

		foreach ( self::SATELLITE_DIRS as $satellite ) {
			$src_dir = $plugins_dir . '/' . $satellite . '/includes';
			if ( ! is_dir( $src_dir ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src_dir, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
					continue;
				}

				$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source read in PHPStan CLI context; WP HTTP API unavailable and irrelevant.

				// Base-plugin FQCNs referenced (imports and inline references).
				if ( preg_match_all( '/\bNetterTechEvents(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $source, $m ) ) {
					foreach ( $m[0] as $fqcn ) {
						$this->satellite_refs['classes'][ $fqcn ] = true;
					}
				}

				// Method-call names.
				if ( preg_match_all( '/(?:->|::)\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $calls ) ) {
					foreach ( $calls[1] as $method_name ) {
						$this->satellite_refs['methods'][ $method_name ] = true;
					}
				}
			}
		}

		return $this->satellite_refs;
	}
}
