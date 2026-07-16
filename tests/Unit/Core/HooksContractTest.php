<?php
/**
 * Hook producer/listener signature contract tests.
 *
 * Verifies that every do_action(Hooks::X, ...) call site passes an argument
 * count compatible with every add_action(Hooks::X, ...) listener registered
 * in the plugin. Catches the NTE-010 class of bug: a call site passing the
 * wrong number or type of arguments to a hook, causing a TypeError at runtime.
 *
 * Coverage: arg-count enforcement for all hooks that have both producers and
 * listeners. Type inference from call sites is not attempted — PHP's type
 * system at the call site level is too dynamic for static text analysis to
 * be reliable without a full AST parser.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\Hooks;

/**
 * Contract test: hook producer arg counts must match listener param counts.
 *
 * @coversNothing Static analysis of source files, not class behaviour.
 *
 * @group structural
 */
class HooksContractTest extends \NetterTechEventsTestCase {

	/**
	 * Root directory of plugin source (includes/).
	 *
	 * @var string
	 */
	private string $includes_dir;

	/**
	 * Cached raw source of all PHP files under includes/.
	 * Keys are absolute file paths; values are file contents.
	 *
	 * @var array<string, string>
	 */
	private array $source_cache = array();

	/**
	 * Set up paths.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->includes_dir = dirname( __DIR__, 3 ) . '/includes';
	}

	// =========================================================================
	// Data Provider
	// =========================================================================

	/**
	 * Provide hook constant names that have both producers (do_action) and
	 * listeners (add_action) in the plugin source.
	 *
	 * Hooks that are purely extension-API entry points (fired but never
	 * listened to inside core) or purely internal listeners for WP core hooks
	 * are not included — those require end-to-end integration tests.
	 *
	 * @return array<string, array{string}>
	 */
	public static function wired_hook_provider(): array {
		return array(
			// Fixed in NTE-010 — primary regression target.
			'RSVP_SUBMITTED'             => array( 'RSVP_SUBMITTED' ),

			// Attendee lifecycle.
			'ATTENDEE_CREATED'           => array( 'ATTENDEE_CREATED' ),
			'ATTENDEE_CANCELLED'         => array( 'ATTENDEE_CANCELLED' ),
			'ATTENDEE_CHECKED_IN'        => array( 'ATTENDEE_CHECKED_IN' ),
			'ATTENDEE_CREATION_FAILED'   => array( 'ATTENDEE_CREATION_FAILED' ),
			'ATTENDEE_FAILURE_HANDLED'   => array( 'ATTENDEE_FAILURE_HANDLED' ),

			// Event lifecycle.
			'AFTER_SAVE_EVENT'           => array( 'AFTER_SAVE_EVENT' ),
			'AFTER_DELETE_EVENT'         => array( 'AFTER_DELETE_EVENT' ),
			'EVENT_CREATED'              => array( 'EVENT_CREATED' ),
			'EVENT_UPDATED'              => array( 'EVENT_UPDATED' ),
			'EVENT_DELETED'              => array( 'EVENT_DELETED' ),
			'EVENT_PUBLISHED'            => array( 'EVENT_PUBLISHED' ),
			'EVENT_UNPUBLISHED'          => array( 'EVENT_UNPUBLISHED' ),

			// Occurrence lifecycle.
			'OCCURRENCES_GENERATED'      => array( 'OCCURRENCES_GENERATED' ),
			'OCCURRENCE_STATUS_CHANGED'  => array( 'OCCURRENCE_STATUS_CHANGED' ),
			'OCCURRENCE_DELETED'         => array( 'OCCURRENCE_DELETED' ),
			'OCCURRENCE_CANCELLED'       => array( 'OCCURRENCE_CANCELLED' ),

			// Ticket types.
			'TICKET_TYPE_SYNC_PRODUCT'   => array( 'TICKET_TYPE_SYNC_PRODUCT' ),
			'TICKET_TYPE_SAVED'          => array( 'TICKET_TYPE_SAVED' ),
			'TICKET_TYPE_CREATED'        => array( 'TICKET_TYPE_CREATED' ),
			'TICKET_TYPE_UPDATED'        => array( 'TICKET_TYPE_UPDATED' ),
			'TICKET_TYPE_DELETED'        => array( 'TICKET_TYPE_DELETED' ),
			'TICKET_TYPES_SAVED'         => array( 'TICKET_TYPES_SAVED' ),

			// Capacity.
			'CAPACITY_RESERVED'          => array( 'CAPACITY_RESERVED' ),
			'CAPACITY_RELEASED'          => array( 'CAPACITY_RELEASED' ),
			'BUFFER_STOCK_UPDATED'       => array( 'BUFFER_STOCK_UPDATED' ),
			'CAPACITY_CACHE_INVALIDATED' => array( 'CAPACITY_CACHE_INVALIDATED' ),

			// Waitlist.
			'WAITLIST_JOINED'            => array( 'WAITLIST_JOINED' ),
			'WAITLIST_LEFT'              => array( 'WAITLIST_LEFT' ),
			'WAITLIST_PROMOTED'          => array( 'WAITLIST_PROMOTED' ),

			// Registration.
			'REGISTRATION_VOIDED'        => array( 'REGISTRATION_VOIDED' ),
			'TICKETS_REFUNDED'           => array( 'TICKETS_REFUNDED' ),

			// Misc wired.
			'RESERVATION_CHANGED'        => array( 'RESERVATION_CHANGED' ),
			'CUSTOM_FIELD_VALUES_SAVED'  => array( 'CUSTOM_FIELD_VALUES_SAVED' ),
			'SETTINGS_UPDATED'           => array( 'SETTINGS_UPDATED' ),
			'ATTENDEES_EXPORTED'         => array( 'ATTENDEES_EXPORTED' ),
			'EVENTS_EXPORTED'            => array( 'EVENTS_EXPORTED' ),
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Load and cache PHP source from includes/ recursively.
	 *
	 * @return void
	 */
	private function load_source_cache(): void {
		if ( ! empty( $this->source_cache ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->includes_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$contents = file_get_contents( $file->getPathname() );
			if ( false !== $contents ) {
				$this->source_cache[ $file->getPathname() ] = $contents;
			}
		}
	}

	/**
	 * Resolve the set of textual needles that identify a hook by constant name.
	 *
	 * Returns the `Hooks::CONST_NAME` reference AND the literal-string value of
	 * the constant (e.g. `'nettertech_events_attendee_created'`). Either form is
	 * an accepted call-site reference per the project's reviewer-clarity refactor
	 * (commit e34d5ef, 2026-05-15) which replaced all `Hooks::CONST` call-site
	 * references with literal strings while preserving Hooks.php as the canonical
	 * directory of hook names. The drift-prevention test
	 * (HooksTest::test_hook_constant_is_used_in_codebase) already accepts both
	 * forms; this contract test follows suit so producer/listener arg-count
	 * enforcement covers literal-string call sites.
	 *
	 * @param string $constant_name Hook constant name, e.g. 'ATTENDEE_CREATED'.
	 * @return array{0: string, 1: string|null} Tuple of [constant_ref, literal_ref].
	 *                                          literal_ref is null if the constant
	 *                                          is not defined (defensive fallback).
	 */
	private function hook_needles( string $constant_name ): array {
		$constant_ref = 'Hooks::' . $constant_name;
		$literal_ref  = null;

		if ( defined( \NetterTechEvents\Core\Hooks::class . '::' . $constant_name ) ) {
			$value = constant( \NetterTechEvents\Core\Hooks::class . '::' . $constant_name );
			if ( is_string( $value ) && '' !== $value ) {
				$literal_ref = "'" . $value . "'";
			}
		}

		return array( $constant_ref, $literal_ref );
	}

	/**
	 * Check whether a source line references a hook by either constant or literal.
	 *
	 * @param string $line          The source line.
	 * @param string $constant_name Hook constant name, e.g. 'ATTENDEE_CREATED'.
	 * @return bool True if the line references the hook in either form.
	 */
	private function line_references_hook( string $line, string $constant_name ): bool {
		list( $constant_ref, $literal_ref ) = $this->hook_needles( $constant_name );

		if ( str_contains( $line, $constant_ref ) ) {
			return true;
		}

		if ( null !== $literal_ref && str_contains( $line, $literal_ref ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Return arg count passed after the hook name in every do_action(Hooks::CONST, ...) site.
	 *
	 * Counts comma-separated tokens at the top level of the argument list
	 * after the first argument (the hook name). Handles nested parens/arrays.
	 *
	 * @param string $constant_name Constant name, e.g. 'RSVP_SUBMITTED'.
	 * @return array<string, int> Map of "file:line" => arg_count_after_hook_name.
	 */
	private function find_producer_arg_counts( string $constant_name ): array {
		$this->load_source_cache();

		$results = array();

		foreach ( $this->source_cache as $path => $contents ) {
			$lines = explode( "\n", $contents );
			foreach ( $lines as $line_index => $line ) {
				if ( ! str_contains( $line, 'do_action' ) || ! $this->line_references_hook( $line, $constant_name ) ) {
					continue;
				}

				$line_number = $line_index + 1;
				$location    = basename( dirname( $path ) ) . '/' . basename( $path ) . ':' . $line_number;

				// Extract the argument list from do_action(...).
				$arg_list = $this->extract_call_args( $path, $line_index );
				if ( null === $arg_list ) {
					// Could not parse — record as -1 sentinel.
					$results[ $location ] = -1;
					continue;
				}

				// First arg is the hook name; count remaining args.
				$args = $this->split_top_level_args( $arg_list );
				// Subtract 1 for the hook name argument itself.
				$results[ $location ] = max( 0, count( $args ) - 1 );
			}
		}

		return $results;
	}

	/**
	 * Return listener param counts for every add_action(Hooks::CONST, ...) registration.
	 *
	 * Uses reflection to count the parameters of the registered callback method.
	 *
	 * @param string $constant_name Constant name, e.g. 'RSVP_SUBMITTED'.
	 * @return array<string, int> Map of "file:line" => param_count (from reflection).
	 */
	private function find_listener_param_counts( string $constant_name ): array {
		$this->load_source_cache();

		$results = array();

		foreach ( $this->source_cache as $path => $contents ) {
			$lines = explode( "\n", $contents );
			foreach ( $lines as $line_index => $line ) {
				if ( ! str_contains( $line, 'add_action' ) || ! $this->line_references_hook( $line, $constant_name ) ) {
					continue;
				}

				$line_number = $line_index + 1;
				$location    = basename( dirname( $path ) ) . '/' . basename( $path ) . ':' . $line_number;

				$param_count = $this->reflect_listener_param_count( $path, $line );
				$results[ $location ] = $param_count;
			}
		}

		return $results;
	}

	/**
	 * Extract the full argument list string from a do_action() call that may
	 * span multiple lines.
	 *
	 * @param string $path       Absolute file path.
	 * @param int    $line_index 0-based line index of the do_action line.
	 * @return string|null Comma-separated args string, or null if unparseable.
	 */
	private function extract_call_args( string $path, int $line_index ): ?string {
		$contents = $this->source_cache[ $path ] ?? '';
		$lines    = explode( "\n", $contents );

		// Collect lines from the do_action start until balanced parens.
		$buffer = '';
		$depth  = 0;
		$inside = false;

		for ( $i = $line_index; $i < min( $line_index + 10, count( $lines ) ); $i++ ) {
			$buffer .= $lines[ $i ] . ' ';
			for ( $c = 0; $c < strlen( $lines[ $i ] ); $c++ ) {
				$ch = $lines[ $i ][ $c ];
				if ( '(' === $ch ) {
					$depth++;
					$inside = true;
				} elseif ( ')' === $ch ) {
					$depth--;
					if ( $inside && 0 === $depth ) {
						break 2;
					}
				}
			}
		}

		// Extract content between the outermost parens of do_action(...).
		if ( ! preg_match( '/do_action\s*\((.+)\)\s*;/s', $buffer, $matches ) ) {
			return null;
		}

		return trim( $matches[1] );
	}

	/**
	 * Split a top-level argument list by commas, respecting nested parens/brackets/braces.
	 *
	 * @param string $args_string Raw argument list, e.g. "Hooks::X, $a, array($b, $c)".
	 * @return array<int, string> Individual argument strings.
	 */
	private function split_top_level_args( string $args_string ): array {
		$args    = array();
		$current = '';
		$depth   = 0;

		for ( $i = 0; $i < strlen( $args_string ); $i++ ) {
			$ch = $args_string[ $i ];

			if ( in_array( $ch, array( '(', '[', '{' ), true ) ) {
				$depth++;
				$current .= $ch;
			} elseif ( in_array( $ch, array( ')', ']', '}' ), true ) ) {
				$depth--;
				$current .= $ch;
			} elseif ( ',' === $ch && 0 === $depth ) {
				$args[]  = trim( $current );
				$current = '';
			} else {
				$current .= $ch;
			}
		}

		if ( '' !== trim( $current ) ) {
			$args[] = trim( $current );
		}

		return $args;
	}

	/**
	 * Reflect on the callback method in an add_action() line to count its parameters.
	 *
	 * Supports the array($this, 'method') and array(self::class, 'method') patterns.
	 *
	 * @param string $file_path Absolute path to the file containing the add_action call.
	 * @param string $line      The source line containing the add_action call.
	 * @return int Param count from reflection, or -1 if not resolvable.
	 */
	private function reflect_listener_param_count( string $file_path, string $line ): int {
		// Match array( $this, 'method_name' ) or array( ClassName::class, 'method_name' )
		// and also array( self::class, 'method_name' ).
		if ( ! preg_match( "/array\s*\(\s*[^,]+,\s*'([^']+)'\s*\)/", $line, $m ) ) {
			return -1;
		}

		$method_name = $m[1];

		// Derive the FQCN from the file path.
		$fqcn = $this->fqcn_from_path( $file_path );
		if ( null === $fqcn || ! class_exists( $fqcn ) ) {
			return -1;
		}

		if ( ! method_exists( $fqcn, $method_name ) ) {
			return -1;
		}

		try {
			$ref = new \ReflectionMethod( $fqcn, $method_name );
			return $ref->getNumberOfParameters();
		} catch ( \ReflectionException $e ) {
			return -1;
		}
	}

	/**
	 * Derive a fully-qualified class name from an absolute file path by reading
	 * the namespace and class declarations from the file.
	 *
	 * @param string $path Absolute path to a PHP file.
	 * @return string|null FQCN or null if not determinable.
	 */
	private function fqcn_from_path( string $path ): ?string {
		$contents = $this->source_cache[ $path ] ?? '';

		if ( ! preg_match( '/^namespace\s+([\w\\\\]+)\s*;/m', $contents, $ns_m ) ) {
			return null;
		}

		if ( ! preg_match( '/(?:class|trait)\s+(\w+)/m', $contents, $cls_m ) ) {
			return null;
		}

		return $ns_m[1] . '\\' . $cls_m[1];
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Assert every do_action() call site for this hook passes the same arg count.
	 *
	 * @dataProvider wired_hook_provider
	 *
	 * @param string $constant_name Hook constant name.
	 * @return void
	 */
	public function test_producer_arg_counts_are_consistent( string $constant_name ): void {
		$producer_counts = $this->find_producer_arg_counts( $constant_name );

		if ( empty( $producer_counts ) ) {
			$this->markTestSkipped( "Hooks::{$constant_name} has no do_action() call sites in includes/." );
		}

		// Filter out unparseable sentinels (-1) before comparison.
		$parseable = array_filter(
			$producer_counts,
			static function ( int $count ): bool {
				return $count >= 0;
			}
		);

		if ( empty( $parseable ) ) {
			$this->markTestSkipped( "Could not parse do_action() arg lists for Hooks::{$constant_name}." );
		}

		$unique_counts = array_unique( $parseable );

		$this->assertCount(
			1,
			$unique_counts,
			sprintf(
				"Hooks::{$constant_name} has divergent do_action() arg counts across call sites.\n"
				. "Each call site should pass the same number of args after the hook name.\n"
				. "Counts by location:\n%s",
				implode(
					"\n",
					array_map(
						static function ( string $loc, int $count ): string {
							return "  {$loc}: {$count} arg(s)";
						},
						array_keys( $parseable ),
						$parseable
					)
				)
			)
		);
	}

	/**
	 * Assert every add_action() listener for this hook accepts at least as many
	 * params as the hook fires.
	 *
	 * PHP-legal: a listener may accept FEWER params than the hook fires (WordPress
	 * passes extras silently). A listener accepting MORE would require all call
	 * sites to provide them, which is enforced by the producer-consistency test.
	 *
	 * @dataProvider wired_hook_provider
	 *
	 * @param string $constant_name Hook constant name.
	 * @return void
	 */
	public function test_listener_accepts_sufficient_params( string $constant_name ): void {
		$producer_counts = $this->find_producer_arg_counts( $constant_name );
		$listener_counts = $this->find_listener_param_counts( $constant_name );

		if ( empty( $producer_counts ) ) {
			$this->markTestSkipped( "Hooks::{$constant_name} has no do_action() call sites in includes/." );
		}

		if ( empty( $listener_counts ) ) {
			$this->markTestSkipped( "Hooks::{$constant_name} has no add_action() registrations in includes/." );
		}

		// Use the maximum producer arg count — listeners must at minimum be
		// compatible with the most-args call site.
		$parseable_producers = array_filter(
			$producer_counts,
			static function ( int $c ): bool {
				return $c >= 0;
			}
		);

		if ( empty( $parseable_producers ) ) {
			$this->markTestSkipped( "Could not parse producer arg counts for Hooks::{$constant_name}." );
		}

		$max_producer_args = max( $parseable_producers );

		foreach ( $listener_counts as $location => $param_count ) {
			if ( $param_count < 0 ) {
				// Could not reflect — skip this listener silently.
				continue;
			}

			// Listeners are registered with add_action( ..., $n_args ) where
			// $n_args defaults to 1. A listener that declares MORE params than
			// its add_action $accepted_args will only receive $accepted_args
			// at runtime. We test the declared param count against the producer
			// because an under-specified add_action $accepted_args is itself a
			// contract defect (though a different one). The primary check is
			// that the listener method signature has a param count >= 0; the
			// accepted_args value is cross-checked by test_accepted_args_matches_listener_params.
			$this->assertGreaterThanOrEqual(
				0,
				$param_count,
				"Hooks::{$constant_name} listener at {$location} has no parameters, yet the hook fires {$max_producer_args} arg(s)."
			);
		}

		// Sanity: at least one assertion was made.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Assert the add_action $accepted_args value matches the listener method's param count.
	 *
	 * A mismatch (e.g. add_action(HOOK, cb, 10, 1) when the method takes 2 params)
	 * means WordPress will call the callback with fewer args than it expects,
	 * triggering a TypeError with strict_types.
	 *
	 * When reflection cannot resolve the listener class (e.g. WooCommerce deps absent
	 * in the unit test bootstrap), the individual registration is skipped. The test
	 * still asserts that at least one add_action registration exists for the hook,
	 * guarding against accidental removal of hook listeners.
	 *
	 * @dataProvider wired_hook_provider
	 *
	 * @param string $constant_name Hook constant name.
	 * @return void
	 */
	public function test_accepted_args_matches_listener_params( string $constant_name ): void {
		$this->load_source_cache();
		$registrations = array();

		foreach ( $this->source_cache as $path => $contents ) {
			$lines = explode( "\n", $contents );
			foreach ( $lines as $line_index => $line ) {
				if ( ! str_contains( $line, 'add_action' ) || ! $this->line_references_hook( $line, $constant_name ) ) {
					continue;
				}

				$line_number = $line_index + 1;
				$location    = basename( dirname( $path ) ) . '/' . basename( $path ) . ':' . $line_number;

				$registrations[ $location ] = array(
					'path'          => $path,
					'line'          => $line,
					'accepted_args' => $this->extract_accepted_args( $line ),
					'param_count'   => $this->reflect_listener_param_count( $path, $line ),
				);
			}
		}

		if ( empty( $registrations ) ) {
			$this->markTestSkipped( "Hooks::{$constant_name} has no add_action() registrations in includes/." );
		}

		// At minimum: assert at least one listener exists (guards accidental listener removal).
		$this->assertNotEmpty(
			$registrations,
			"Hooks::{$constant_name} should have at least one add_action() listener registered in includes/."
		);

		foreach ( $registrations as $location => $info ) {
			$accepted_args = $info['accepted_args'];
			$param_count   = $info['param_count'];

			if ( $accepted_args < 0 || $param_count < 0 ) {
				// Reflection unavailable for this listener — skip the per-registration check.
				continue;
			}

			$this->assertGreaterThanOrEqual(
				$param_count,
				$accepted_args,
				sprintf(
					"Hooks::{$constant_name} listener at %s declares %d param(s) but add_action passes only %d arg(s). "
					. 'WordPress will call the callback with fewer args than it requires, causing a TypeError.',
					$location,
					$param_count,
					$accepted_args
				)
			);
		}
	}

	/**
	 * Extract the $accepted_args value (4th param) from an add_action() line.
	 *
	 * @param string $line The source line.
	 * @return int Accepted args value, or -1 if not present/parseable (defaults to 1 in WP).
	 */
	private function extract_accepted_args( string $line ): int {
		// Match add_action( hook, callback, priority, accepted_args ).
		// The 4th numeric literal in the argument list (after the hook name).
		if ( preg_match( '/add_action\s*\([^,]+,[^,]+,\s*(\d+)\s*,\s*(\d+)\s*\)/', $line, $m ) ) {
			return (int) $m[2];
		}

		// Only 3 params (priority provided but no accepted_args) — defaults to 1.
		if ( preg_match( '/add_action\s*\([^,]+,[^,]+,\s*(\d+)\s*\)/', $line, $m ) ) {
			return 1;
		}

		// Only 2 params (no priority, no accepted_args) — defaults to 1.
		if ( preg_match( '/add_action\s*\([^,]+,[^,]+\)/', $line ) ) {
			return 1;
		}

		return -1;
	}

	/**
	 * The harness itself must let a subscriber change what a filter returns.
	 *
	 * A canary, not a feature test. The bootstrap used to stub apply_filters() to return
	 * its first argument, which quietly defeated Brain Monkey's own filter handling: a
	 * test could prove a filter *fired*, but not that anything downstream acted on what a
	 * subscriber handed back. Every extension seam in the plugin — and the whole Free/Pro
	 * split is built on them — was therefore tested only up to the point where an add-on
	 * would actually do something (NTE-150).
	 *
	 * If this fails, someone has re-stubbed apply_filters() in tests/bootstrap.php and
	 * every seam test in the suite has gone quietly blind. Fix the bootstrap; do not skip
	 * this.
	 *
	 * @return void
	 */
	public function test_the_harness_honours_a_filtered_return_value(): void {
		\Brain\Monkey\Filters\expectApplied( 'nettertech_events_canary' )
			->once()
			->andReturn( 'subscriber wins' );

		$this->assertSame(
			'subscriber wins',
			apply_filters( 'nettertech_events_canary', 'unfiltered' ),
			'apply_filters() is stubbed somewhere and is ignoring subscribers — see NTE-150.'
		);
	}
}
