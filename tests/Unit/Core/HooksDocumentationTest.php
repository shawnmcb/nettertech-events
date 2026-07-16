<?php
/**
 * Hook documentation completeness gate.
 *
 * Verifies that every hook the plugin fires appears in docs/HOOKS.md. The
 * fired set is derived from source (do_action / apply_filters call sites,
 * including template call sites and constant-referenced names, plus cron
 * hook names passed to wp_schedule_event / wp_schedule_single_event), not
 * from the Hooks constant registry, because a constant can exist unused and
 * a hook can be fired without a constant.
 *
 * This gate exists because HOOKS.md drifted to documenting 54 of ~145 hooks
 * before 1.1.2, including one AJAX action that did not exist. A hook cannot
 * ship undocumented while this test passes.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Database\MigrationManager;

/**
 * Structural gate: every fired hook must be documented in docs/HOOKS.md.
 *
 * @coversNothing Static analysis of source files, not class behaviour.
 *
 * @group structural
 */
class HooksDocumentationTest extends \NetterTechEventsTestCase {

	/**
	 * WordPress core hooks the plugin fires but does not own.
	 *
	 * These are applied for interoperability (running WordPress's own filter
	 * over plugin output) and are not part of this plugin's extension API.
	 *
	 * @var array<string>
	 */
	private const FOREIGN_HOOKS = array(
		'the_content',
	);

	/**
	 * Plugin root directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Set up paths.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->plugin_dir = dirname( __DIR__, 3 );
	}

	/**
	 * Every fired hook name must appear in docs/HOOKS.md.
	 *
	 * @return void
	 */
	public function test_every_fired_hook_is_documented(): void {
		$doc_path = $this->plugin_dir . '/docs/HOOKS.md';
		$this->assertFileExists( $doc_path, 'docs/HOOKS.md is missing.' );

		$doc = (string) file_get_contents( $doc_path );

		$undocumented = array();

		foreach ( $this->collect_fired_hooks() as $hook => $first_site ) {
			// A documented hook appears as an inline-code token.
			if ( ! str_contains( $doc, '`' . $hook . '`' ) ) {
				$undocumented[] = "{$hook} (fired at {$first_site})";
			}
		}

		$this->assertSame(
			array(),
			$undocumented,
			"Hooks fired in source but missing from docs/HOOKS.md:\n  - "
			. implode( "\n  - ", $undocumented )
			. "\nAdd each to the Complete Hook Index in docs/HOOKS.md (with a Hooks.php constant and docblock if it lacks one)."
		);
	}

	/**
	 * Every dynamic (interpolated) hook call site must be individually documented.
	 *
	 * Dynamic names cannot be matched literally against the doc, so each
	 * dynamic call site found in source must be listed here with the doc
	 * token that covers it. A new dynamic hook fails this test until it is
	 * both documented and registered below.
	 *
	 * @return void
	 */
	public function test_dynamic_hook_call_sites_are_documented(): void {
		$known_dynamic = array(
			// TemplateLoader mirrors WordPress core's template-part action.
			'get_template_part_' => 'get_template_part_{$slug}',
		);

		$doc = (string) file_get_contents( $this->plugin_dir . '/docs/HOOKS.md' );

		$unknown = array();

		foreach ( $this->collect_dynamic_call_sites() as $site ) {
			$matched = false;

			foreach ( $known_dynamic as $prefix => $doc_token ) {
				if ( str_contains( $site['arg'], $prefix ) ) {
					$this->assertStringContainsString(
						'`' . $doc_token . '`',
						$doc,
						"Dynamic hook token {$doc_token} missing from docs/HOOKS.md (fired at {$site['site']})."
					);
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				$unknown[] = "{$site['arg']} (fired at {$site['site']})";
			}
		}

		$this->assertSame(
			array(),
			$unknown,
			"Unrecognized dynamic hook call sites:\n  - " . implode( "\n  - ", $unknown )
			. "\nDocument each in docs/HOOKS.md and register it in this test's known_dynamic map."
		);
	}

	/**
	 * Collect every literal or constant-resolvable hook name fired in source.
	 *
	 * @return array<string, string> Map of hook name to first firing site (relative path:line).
	 */
	private function collect_fired_hooks(): array {
		$constants = $this->constant_map();
		$fired     = array();

		foreach ( $this->source_files() as $path => $contents ) {
			$relative = substr( $path, strlen( $this->plugin_dir ) + 1 );

			$pattern = '/\b(?:do_action|do_action_ref_array|apply_filters|apply_filters_ref_array'
				. '|wp_schedule_event|wp_schedule_single_event)\s*\(\s*([^,)]*?)\s*[,)]/s';

			if ( ! preg_match_all( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[1] as $match ) {
				$arg  = trim( preg_replace( '/\s+/', ' ', $match[0] ) );
				$line = substr_count( $contents, "\n", 0, $match[1] ) + 1;
				$name = $this->resolve_hook_name( $arg, $constants );

				if ( null === $name || in_array( $name, self::FOREIGN_HOOKS, true ) ) {
					continue;
				}

				// Scheduling functions also take timestamps/recurrence as
				// leading args; only hook-name strings matter.
				if ( ! str_starts_with( $name, 'nettertech_events_' ) ) {
					continue;
				}

				if ( ! isset( $fired[ $name ] ) ) {
					$fired[ $name ] = "{$relative}:{$line}";
				}
			}
		}

		return $fired;
	}

	/**
	 * Collect dynamic (unresolvable) first arguments to hook-firing calls.
	 *
	 * @return array<array{arg: string, site: string}>
	 */
	private function collect_dynamic_call_sites(): array {
		$constants = $this->constant_map();
		$dynamic   = array();

		foreach ( $this->source_files() as $path => $contents ) {
			$relative = substr( $path, strlen( $this->plugin_dir ) + 1 );

			$pattern = '/\b(?:do_action|do_action_ref_array|apply_filters|apply_filters_ref_array)\s*\(\s*([^,)]*?)\s*[,)]/s';

			if ( ! preg_match_all( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[1] as $match ) {
				$arg = trim( preg_replace( '/\s+/', ' ', $match[0] ) );

				if ( null !== $this->resolve_hook_name( $arg, $constants ) ) {
					continue;
				}

				$line      = substr_count( $contents, "\n", 0, $match[1] ) + 1;
				$dynamic[] = array(
					'arg'  => $arg,
					'site' => "{$relative}:{$line}",
				);
			}
		}

		return $dynamic;
	}

	/**
	 * Resolve a call-site first argument to a hook name, or null if dynamic.
	 *
	 * @param string                $arg       Normalized first-argument source text.
	 * @param array<string, string> $constants Constant name to hook name map.
	 * @return string|null
	 */
	private function resolve_hook_name( string $arg, array $constants ): ?string {
		if ( preg_match( '/^([\'"])((?:(?!\1).)*)\1$/', $arg, $m ) ) {
			// Interpolated double-quoted names are dynamic.
			if ( '"' === $m[1] && str_contains( $m[2], '$' ) ) {
				return null;
			}
			return $m[2];
		}

		if ( preg_match( '/^(?:self::|Hooks::|\\\\?NetterTechEvents\\\\Core\\\\Hooks::)(\w+)$/', $arg, $m ) ) {
			return $constants[ $m[1] ] ?? null;
		}

		return null;
	}

	/**
	 * Map of constant names to hook name strings from the two registries.
	 *
	 * @return array<string, string>
	 */
	private function constant_map(): array {
		$map = array();

		foreach ( array( Hooks::class, MigrationManager::class ) as $class ) {
			$reflection = new \ReflectionClass( $class );

			foreach ( $reflection->getConstants() as $name => $value ) {
				if ( is_string( $value ) && str_starts_with( $value, 'nettertech_events_' ) ) {
					$map[ $name ] = $value;
				}
			}
		}

		return $map;
	}

	/**
	 * All PHP source files that can fire hooks (excludes vendor and tests).
	 *
	 * @return \Generator<string, string> Path to contents.
	 */
	private function source_files(): \Generator {
		$roots = array(
			$this->plugin_dir . '/includes',
			$this->plugin_dir . '/templates',
			$this->plugin_dir . '/admin',
			$this->plugin_dir . '/src',
		);

		$main = $this->plugin_dir . '/nettertech-events.php';
		if ( is_file( $main ) ) {
			yield $main => (string) file_get_contents( $main );
		}

		$uninstall = $this->plugin_dir . '/uninstall.php';
		if ( is_file( $uninstall ) ) {
			yield $uninstall => (string) file_get_contents( $uninstall );
		}

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				yield $file->getPathname() => (string) file_get_contents( $file->getPathname() );
			}
		}
	}
}
