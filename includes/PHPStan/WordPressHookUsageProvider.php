<?php
/**
 * WordPress hook usage provider for shipmonk/dead-code-detector.
 *
 * @package NetterTechEvents\PHPStan
 */

declare(strict_types=1);

namespace NetterTechEvents\PHPStan;

// NO ABSPATH guard here — this class is loaded by PHPStan's DI container
// (phpstan.neon services), never by WordPress. A guard makes the file exit(0)
// during PHPStan bootstrap, silently killing the entire analysis (the gate was
// dead 2026-04-05 → 2026-06-11 because of exactly that; see INV-M1 in
// .coherence-invariants.md).

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;

/**
 * Marks WordPress hook/filter callbacks as used so shipmonk/dead-code-detector
 * doesn't report them as dead code.
 *
 * Handles these patterns:
 *   add_action('hook', array( $this, 'method' ))
 *   add_filter('hook', array( $this, 'method' ))
 *   add_shortcode('tag', array( $this, 'method' ))
 *   add_action('hook', array( static::class, 'method' ))
 *   add_action('hook', array( self::class, 'method' ))
 *   add_action('hook', array( __CLASS__, 'method' ))
 *   register_rest_route(..., array( 'callback' => array( $this, 'method' ) ))
 *   $this->loader->add_action('hook', $this, 'method')  (Loader pattern)
 */
final class WordPressHookUsageProvider implements MemberUsageProvider {

	/**
	 * WordPress functions that accept a callable in their second argument.
	 *
	 * @var array<string, int>
	 */
	private const HOOK_FUNCTIONS = array(
		'add_action'    => 1,
		'add_filter'    => 1,
		'add_shortcode' => 1,
	);

	/**
	 * REST route config keys that hold callables.
	 *
	 * @var list<string>
	 */
	private const REST_CALLBACK_KEYS = array(
		'callback',
		'permission_callback',
	);

	/**
	 * Return usages for WordPress hook callbacks found in the given AST node.
	 *
	 * @param Node  $node  The AST node to inspect.
	 * @param Scope $scope The analysis scope.
	 * @return list<ClassMemberUsage>
	 */
	public function getUsages( Node $node, Scope $scope ): array {
		if ( ! $node instanceof FuncCall ) {
			return array();
		}

		$resolved = $this->resolve_hook_callback( $node, $scope );
		if ( array() !== $resolved ) {
			return $resolved;
		}

		$resolved = $this->resolve_rest_route_callbacks( $node, $scope );
		if ( array() !== $resolved ) {
			return $resolved;
		}

		return array();
	}

	/**
	 * Resolve add_action/add_filter/add_shortcode callbacks.
	 *
	 * @param FuncCall $node  The function call node.
	 * @param Scope    $scope The analysis scope.
	 * @return list<ClassMethodUsage>
	 */
	private function resolve_hook_callback( FuncCall $node, Scope $scope ): array {
		$name = $this->get_function_name( $node );
		if ( null === $name || ! isset( self::HOOK_FUNCTIONS[ $name ] ) ) {
			return array();
		}

		$callable_arg_index = self::HOOK_FUNCTIONS[ $name ];
		$args               = $node->getArgs();

		if ( ! isset( $args[ $callable_arg_index ] ) ) {
			return array();
		}

		$callable_arg = $args[ $callable_arg_index ]->value;

		$usage = $this->resolve_array_callable( $callable_arg, $node, $scope );
		if ( null !== $usage ) {
			return array( $usage );
		}

		// Loader pattern: object in arg 1, string method name in arg 2.
		if ( isset( $args[2] ) && $args[2]->value instanceof String_ ) {
			$method_name = $args[2]->value->value;
			$object_type = $scope->getType( $callable_arg );

			foreach ( $object_type->getObjectClassNames() as $class_name ) {
				return array(
					new ClassMethodUsage(
						UsageOrigin::createRegular( $node, $scope ),
						new ClassMethodRef( $class_name, $method_name, true ),
					),
				);
			}
		}

		return array();
	}

	/**
	 * Resolve register_rest_route callbacks and permission_callbacks.
	 *
	 * @param FuncCall $node  The function call node.
	 * @param Scope    $scope The analysis scope.
	 * @return list<ClassMethodUsage>
	 */
	private function resolve_rest_route_callbacks( FuncCall $node, Scope $scope ): array {
		$name = $this->get_function_name( $node );
		if ( 'register_rest_route' !== $name ) {
			return array();
		}

		$args = $node->getArgs();

		if ( ! isset( $args[2] ) ) {
			return array();
		}

		$config_arg = $args[2]->value;
		if ( ! $config_arg instanceof Array_ ) {
			return array();
		}

		$usages = array();

		foreach ( $config_arg->items as $item ) {
			if ( ! $item instanceof ArrayItem || ! $item->key instanceof String_ ) {
				continue;
			}

			if ( ! in_array( $item->key->value, self::REST_CALLBACK_KEYS, true ) ) {
				continue;
			}

			$usage = $this->resolve_array_callable( $item->value, $node, $scope );
			if ( null !== $usage ) {
				$usages[] = $usage;
			}
		}

		return $usages;
	}

	/**
	 * Resolve array callable: array( $this, 'method' ) or array( static::class, 'method' ).
	 *
	 * @param Node\Expr $expr  The expression to resolve.
	 * @param FuncCall  $node  The parent function call node.
	 * @param Scope     $scope The analysis scope.
	 * @return ClassMethodUsage|null
	 */
	private function resolve_array_callable( Node\Expr $expr, FuncCall $node, Scope $scope ): ?ClassMethodUsage {
		if ( ! $expr instanceof Array_ || 2 !== count( $expr->items ) ) {
			return null;
		}

		$first  = $expr->items[0];
		$second = $expr->items[1];

		if ( ! $first instanceof ArrayItem || ! $second instanceof ArrayItem ) {
			return null;
		}

		if ( ! $second->value instanceof String_ ) {
			return null;
		}

		$method_name = $second->value->value;
		$class_name  = $this->resolve_class_name( $first->value, $scope );

		if ( null === $class_name ) {
			return null;
		}

		return new ClassMethodUsage(
			UsageOrigin::createRegular( $node, $scope ),
			new ClassMethodRef( $class_name, $method_name, true ),
		);
	}

	/**
	 * Resolve the class name from $this, static::class, self::class, __CLASS__, or typed variable.
	 *
	 * @param Node\Expr $expr  The expression to resolve.
	 * @param Scope     $scope The analysis scope.
	 * @return string|null
	 */
	private function resolve_class_name( Node\Expr $expr, Scope $scope ): ?string {
		if ( $expr instanceof Variable && 'this' === $expr->name && $scope->isInClass() ) {
			return $scope->getClassReflection()->getName();
		}

		if ( $expr instanceof Node\Expr\ClassConstFetch
			&& $expr->name instanceof Node\Identifier
			&& 'class' === strtolower( $expr->name->name )
			&& $expr->class instanceof Name
		) {
			$class_ref = strtolower( $expr->class->toString() );
			if ( in_array( $class_ref, array( 'static', 'self' ), true ) && $scope->isInClass() ) {
				return $scope->getClassReflection()->getName();
			}
		}

		if ( $expr instanceof Node\Scalar\MagicConst\Class_ && $scope->isInClass() ) {
			return $scope->getClassReflection()->getName();
		}

		$type        = $scope->getType( $expr );
		$class_names = $type->getObjectClassNames();
		if ( 1 === count( $class_names ) ) {
			return $class_names[0];
		}

		return null;
	}

	/**
	 * Get the function name from a FuncCall node.
	 *
	 * @param FuncCall $node The function call node.
	 * @return string|null
	 */
	private function get_function_name( FuncCall $node ): ?string {
		if ( $node->name instanceof Name ) {
			return $node->name->toString();
		}

		return null;
	}
}
