<?php
/**
 * Template method usage provider for shipmonk/dead-code-detector.
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
 * Marks methods called from plugin template files as used.
 *
 * PHPStan analyzes only `includes/`, so methods invoked exclusively from
 * `templates/**` (the Presenter pattern: templates receive a typed
 * `$presenter` and call accessor methods) read as dead to
 * shipmonk/dead-code-detector — 149 of the 174 dead-code findings parked in
 * the 2026-06-11 baseline were this false-positive class.
 *
 * Resolution strategy: templates declare their variables with `@var` docblock
 * bindings (house convention, enforced by the template header comments).
 * This provider scans templates once per run, binds `@var \FQCN $var`
 * declarations to `$var->method(...)` calls in the same file, and emits a
 * virtual usage for each (class, method) pair when the class declaration is
 * visited. `possibleDescendant` is true so subclass overrides stay live too.
 */
final class TemplateMethodUsageProvider implements MemberUsageProvider {

	/**
	 * Lazily built map of FQCN => list of method names called from templates.
	 *
	 * @var array<string, array<string, true>>|null
	 */
	private ?array $template_usages = null;

	/**
	 * Emit template-driven usages when visiting a class declaration.
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
		$usages = $this->get_template_usages();

		if ( ! isset( $usages[ $class_name ] ) ) {
			return array();
		}

		$result = array();
		foreach ( array_keys( $usages[ $class_name ] ) as $method_name ) {
			$result[] = new ClassMethodUsage(
				UsageOrigin::createVirtual(
					$this,
					VirtualUsageData::withNote( 'Called from templates/ (not analyzed by PHPStan); see TemplateMethodUsageProvider.' )
				),
				new ClassMethodRef( $class_name, $method_name, true ),
			);
		}

		return $result;
	}

	/**
	 * Build (once) the FQCN => methods map from template files.
	 *
	 * @return array<string, array<string, true>>
	 */
	private function get_template_usages(): array {
		if ( null !== $this->template_usages ) {
			return $this->template_usages;
		}

		$this->template_usages = array();

		$templates_dir = dirname( __DIR__, 2 ) . '/templates';
		if ( ! is_dir( $templates_dir ) ) {
			return $this->template_usages;
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $templates_dir, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local template file read in PHPStan CLI context; WP HTTP API unavailable and irrelevant.

			// Bind `@var \FQCN $var` docblock declarations (house template convention).
			$bindings = array();
			if ( preg_match_all( '/@var\s+\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)(?:<[^>]*>)?(?:\|[^\s]+)?\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $source, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$bindings[ $hit[2] ] = ltrim( $hit[1], '\\' );
				}
			}

			if ( array() === $bindings ) {
				continue;
			}

			// Collect `$var->method(` calls for bound variables.
			if ( preg_match_all( '/\$([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $calls, PREG_SET_ORDER ) ) {
				foreach ( $calls as $call ) {
					if ( isset( $bindings[ $call[1] ] ) ) {
						$this->template_usages[ $bindings[ $call[1] ] ][ $call[2] ] = true;
					}
				}
			}
		}

		return $this->template_usages;
	}
}
